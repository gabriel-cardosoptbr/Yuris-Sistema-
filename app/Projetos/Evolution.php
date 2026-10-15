<?php
namespace App\Projetos;

use App\Core\Database;

/**
 * Painel do Evolution (aba Evolution da edição CRM, pedido da Inovaize,
 * 15/10/2026): o servidor de WhatsApp da Inovaize visto como infraestrutura.
 * Quantas conexões existem, quais estão de pé, de quais projetos, o volume de
 * cada uma e quanto custam.
 *
 * Regras:
 *
 *  - SÓ LEITURA no Evolution: usa a configuração global (endereço + chave de
 *    administrador cifrada, App\WhatsAppAgente\WhatsAppProvisioningService::globalCfg)
 *    e chama apenas GET / e GET /instance/fetchInstances. Nunca conecta,
 *    desconecta, reinicia ou apaga instância: segunda instância, QR ou
 *    desconexão derrubam o WhatsApp de cliente (risco 2 do CLAUDE.md).
 *  - O `token` de cada instância (a chave dela) vem no fetchInstances e é
 *    descartado em `normalizar()`: nunca sai daqui.
 *  - Status: open = conectada; connecting = tentando reconectar (sessão presa,
 *    consome o servidor e não funciona); close = desconectada.
 *  - Custo mensal do Evolution é informado na tela (`configuracoes.evolution.custo_mensal`,
 *    em centavos de real) e rateado em partes iguais entre as instâncias que
 *    ocupam o servidor (open e connecting). Por projeto = soma das instâncias dele.
 *  - Vínculo instância → projeto em `configuracoes.evolution.vinculos`. Sem
 *    vínculo gravado, há sugestão automática: instância de uma conta deste
 *    sistema que está cadastrada como conta de um projeto ("conta #N").
 *  - O Evolution só dá totais acumulados; a foto diária (evolution_snapshots,
 *    migration 148) permite o volume por dia (diferença entre dias).
 *  - Só a conta com a pasta Projetos ligada (a dona da infraestrutura) vê.
 */
final class Evolution
{
    public const STATUS = ['open' => 'Conectada', 'connecting' => 'Reconectando', 'close' => 'Desconectada'];

    /* ------------------------------------------------------------ leitura do Evolution */

    /**
     * Lê o Evolution. Devolve [instancias normalizadas, versao, erro].
     *
     * @return array{0:list<array>,1:?string,2:?string}
     */
    public static function coletar(\PDO $pdo, int $timeout = 12): array
    {
        [$base, , $key] = \App\WhatsAppAgente\WhatsAppProvisioningService::globalCfg($pdo);
        if ($base === '' || $key === '') return [[], null, 'A configuração global do Evolution (endereço e chave de administrador) não está cadastrada no Painel Master.'];
        $evo = new \App\WhatsAppAgente\EvolutionApiService(['evolution_base_url' => $base, 'evolution_api_key' => $key]);
        $evo->setTimeout($timeout);
        $lista = $evo->fetchInstances();
        if (!empty($lista['_error'])) return [[], null, 'O Evolution não respondeu: ' . $lista['_error']];
        $raiz = $evo->request('GET', '/');
        $versao = is_array($raiz) && isset($raiz['version']) ? (string) $raiz['version'] : null;
        return [self::normalizar($lista), $versao, null];
    }

    /** Endereço do Evolution sem caminho (para mostrar). */
    public static function endereco(\PDO $pdo): ?string
    {
        [$base] = \App\WhatsAppAgente\WhatsAppProvisioningService::globalCfg($pdo);
        $h = parse_url($base, PHP_URL_HOST);
        return $h ?: null;
    }

    /**
     * Resposta crua do fetchInstances => lista limpa. Descarta o token e tudo que
     * não é usado; ignora item sem nome.
     */
    public static function normalizar(array $cru): array
    {
        $out = [];
        foreach ($cru as $i) {
            if (!is_array($i)) continue;
            $nome = trim((string) ($i['name'] ?? $i['instanceName'] ?? ''));
            if ($nome === '') continue;
            $st = (string) ($i['connectionStatus'] ?? 'close');
            $cnt = is_array($i['_count'] ?? null) ? $i['_count'] : [];
            $out[] = [
                'nome' => $nome,
                'status' => isset(self::STATUS[$st]) ? $st : 'close',
                'numero' => self::numero((string) ($i['ownerJid'] ?? $i['number'] ?? '')),
                'perfil' => trim((string) ($i['profileName'] ?? '')) ?: null,
                'integracao' => $i['integration'] ?? null,
                'mensagens' => (int) ($cnt['Message'] ?? 0),
                'contatos' => (int) ($cnt['Contact'] ?? 0),
                'conversas' => (int) ($cnt['Chat'] ?? 0),
                'criada_em' => self::data($i['createdAt'] ?? null),
                'atualizada_em' => self::data($i['updatedAt'] ?? null),
                'desconectada_em' => self::data($i['disconnectionAt'] ?? null),
                'motivo_desconexao' => isset($i['disconnectionReasonCode']) && $i['disconnectionReasonCode'] !== null ? (int) $i['disconnectionReasonCode'] : null,
            ];
        }
        usort($out, fn($a, $b) => [array_search($a['status'], array_keys(self::STATUS)), -$a['mensagens']] <=> [array_search($b['status'], array_keys(self::STATUS)), -$b['mensagens']]);
        return $out;
    }

    /** "5511999998888@s.whatsapp.net" => "5511999998888"; vazio => null. */
    public static function numero(string $jid): ?string
    {
        $d = preg_replace('/\D/', '', explode('@', $jid)[0]);
        return $d === '' ? null : $d;
    }

    /** ISO do Evolution (UTC) => "Y-m-d H:i:s" no horário de Brasília; inválido => null. */
    public static function data($iso): ?string
    {
        if (!$iso) return null;
        try {
            return (new \DateTimeImmutable((string) $iso))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) { return null; }
    }

    /* ------------------------------------------------------------ contas (puras) */

    /** Ocupa o servidor? open e connecting (sessão presa também consome). */
    public static function ocupa(array $i): bool
    {
        return $i['status'] !== 'close';
    }

    /**
     * Custo mensal (centavos) dividido igual entre as instâncias que ocupam o
     * servidor; os centavos que sobram vão para as primeiras.
     *
     * @return array<string,int> nome => centavos
     */
    public static function rateio(?int $custoCentavos, array $instancias): array
    {
        $out = [];
        foreach ($instancias as $i) $out[$i['nome']] = 0;
        $ocupam = array_values(array_filter($instancias, [self::class, 'ocupa']));
        if (!$custoCentavos || !$ocupam) return $out;
        $base = intdiv($custoCentavos, count($ocupam));
        $resto = $custoCentavos - $base * count($ocupam);
        foreach ($ocupam as $k => $i) $out[$i['nome']] = $base + ($k < $resto ? 1 : 0);
        return $out;
    }

    /**
     * Junta tudo: cada instância com projeto, custo e volume de hoje; o resumo
     * por situação e por projeto.
     *
     * @param array<string,int> $vinculos nome => projeto_id (gravados)
     * @param array<string,int> $sugestoes nome => projeto_id (automáticos)
     * @param array<int,string> $projetos projeto_id => nome
     * @param array<string,int> $ontem nome => mensagens na foto anterior
     */
    public static function montar(array $instancias, array $vinculos, array $sugestoes, array $projetos, ?int $custoCentavos, array $ontem = []): array
    {
        $rateio = self::rateio($custoCentavos, $instancias);
        $porStatus = array_fill_keys(array_keys(self::STATUS), 0);
        $porProjeto = [];
        $tot = ['mensagens' => 0, 'contatos' => 0, 'conversas' => 0, 'ocupam' => 0];
        foreach ($instancias as &$i) {
            $pid = $vinculos[$i['nome']] ?? null;
            $auto = false;
            if ($pid !== null && !isset($projetos[$pid])) $pid = null; // projeto apagado
            if ($pid === null && isset($sugestoes[$i['nome']], $projetos[$sugestoes[$i['nome']]])) { $pid = $sugestoes[$i['nome']]; $auto = true; }
            $i['projeto_id'] = $pid;
            $i['projeto_nome'] = $pid !== null ? $projetos[$pid] : null;
            $i['projeto_auto'] = $auto;
            $i['custo'] = $rateio[$i['nome']] / 100.0;
            $i['mensagens_desde_ultima_foto'] = isset($ontem[$i['nome']]) ? max(0, $i['mensagens'] - $ontem[$i['nome']]) : null;
            $porStatus[$i['status']]++;
            foreach (['mensagens', 'contatos', 'conversas'] as $k) $tot[$k] += $i[$k];
            if (self::ocupa($i)) $tot['ocupam']++;
            $chave = $pid ?? 0;
            $porProjeto[$chave] ??= ['projeto_id' => $pid, 'nome' => $pid !== null ? $projetos[$pid] : 'Sem projeto', 'instancias' => 0,
                                     'conectadas' => 0, 'mensagens' => 0, 'custo' => 0];
            $porProjeto[$chave]['instancias']++;
            if ($i['status'] === 'open') $porProjeto[$chave]['conectadas']++;
            $porProjeto[$chave]['mensagens'] += $i['mensagens'];
            $porProjeto[$chave]['custo'] += $rateio[$i['nome']];
        }
        unset($i);
        $porProjeto = array_values(array_map(fn($p) => ['custo' => $p['custo'] / 100.0] + $p, $porProjeto));
        usort($porProjeto, fn($a, $b) => $b['instancias'] <=> $a['instancias'] ?: $b['mensagens'] <=> $a['mensagens']);
        return [
            'instancias' => $instancias,
            'por_projeto' => $porProjeto,
            'resumo' => [
                'total' => count($instancias), 'conectadas' => $porStatus['open'], 'reconectando' => $porStatus['connecting'],
                'desconectadas' => $porStatus['close'], 'ocupam' => $tot['ocupam'],
                'mensagens' => $tot['mensagens'], 'contatos' => $tot['contatos'], 'conversas' => $tot['conversas'],
                'custo_mensal' => $custoCentavos === null ? null : $custoCentavos / 100.0,
                'custo_por_conexao' => $custoCentavos && $tot['ocupam'] ? round($custoCentavos / $tot['ocupam'] / 100.0, 2) : null,
                'custo_por_conectada' => $custoCentavos && $porStatus['open'] ? round($custoCentavos / $porStatus['open'] / 100.0, 2) : null,
            ],
        ];
    }

    /* ------------------------------------------------------------ configuração da conta */

    /** @return array{custo:?int,vinculos:array<string,int>} */
    public static function config(array $conta): array
    {
        $c = json_decode((string) ($conta['configuracoes'] ?? ''), true);
        $e = is_array($c) && is_array($c['evolution'] ?? null) ? $c['evolution'] : [];
        $custo = isset($e['custo_mensal_centavos']) ? (int) $e['custo_mensal_centavos'] : null;
        $vinc = [];
        foreach ((array) ($e['vinculos'] ?? []) as $nome => $pid) if ((int) $pid > 0) $vinc[(string) $nome] = (int) $pid;
        return ['custo' => $custo, 'vinculos' => $vinc];
    }

    /** Grava o custo mensal (centavos; null apaga). */
    public static function salvarCusto(\PDO $pdo, int $accountId, ?int $centavos): void
    {
        Projeto::mexerConfig($pdo, $accountId, function (array $c) use ($centavos): array {
            if (!is_array($c['evolution'] ?? null)) $c['evolution'] = [];
            if ($centavos === null) unset($c['evolution']['custo_mensal_centavos']); else $c['evolution']['custo_mensal_centavos'] = $centavos;
            return $c;
        });
    }

    /** Liga a instância a um projeto da conta (ou desliga com projetoId 0). Devolve false se o projeto não é da conta. */
    public static function vincular(\PDO $pdo, int $accountId, string $instancia, int $projetoId): bool
    {
        $instancia = trim($instancia);
        if ($instancia === '' || mb_strlen($instancia) > 120) return false;
        if ($projetoId > 0 && Projeto::donoDa('projetos', $projetoId, [$accountId]) !== $accountId) return false;
        Projeto::mexerConfig($pdo, $accountId, function (array $c) use ($instancia, $projetoId): array {
            if (!is_array($c['evolution'] ?? null)) $c['evolution'] = [];
            if (!is_array($c['evolution']['vinculos'] ?? null)) $c['evolution']['vinculos'] = [];
            if ($projetoId > 0) $c['evolution']['vinculos'][$instancia] = $projetoId; else unset($c['evolution']['vinculos'][$instancia]);
            return $c;
        });
        return true;
    }

    /**
     * Sugestão automática de projeto: a instância é de uma conta deste sistema
     * (whatsapp_instances) que a conta dona da aba cadastrou como conta de um
     * projeto ("conta #N" em projeto_contas). Lê só o nome da instância e o id da
     * conta, e só das contas que já estão cadastradas nos projetos dela.
     *
     * @return array<string,int> nome da instância => projeto_id
     */
    public static function sugestoes(\PDO $pdo, int $accountId): array
    {
        try {
            $st = $pdo->prepare("SELECT projeto_id, identificador FROM projeto_contas
                                  WHERE account_id = ? AND deleted_at IS NULL AND identificador LIKE 'conta #%'");
            $st->execute([$accountId]);
            $porConta = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $n = (int) substr($r['identificador'], 7);
                if ($n > 0) $porConta[$n] ??= (int) $r['projeto_id'];
            }
            if (!$porConta) return [];
            $ph = implode(',', array_fill(0, count($porConta), '?'));
            $st = $pdo->prepare("SELECT account_id, instance_name FROM whatsapp_instances WHERE account_id IN ($ph) AND instance_name IS NOT NULL");
            $st->execute(array_keys($porConta));
            $out = [];
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[(string) $r['instance_name']] = $porConta[(int) $r['account_id']];
            return $out;
        } catch (\Throwable $e) {
            error_log('[evolution_painel] sugestoes: ' . $e->getMessage());
            return [];
        }
    }

    /* ------------------------------------------------------------ fotos diárias */

    /** Grava (ou atualiza) a foto de hoje de cada instância. Falha silenciosa sem a tabela. */
    public static function gravarFoto(\PDO $pdo, int $accountId, array $instancias, string $dia): void
    {
        try {
            $st = $pdo->prepare("INSERT INTO evolution_snapshots (account_id, dia, instancia, status, mensagens, contatos, conversas)
                                 VALUES (?, ?, ?, ?, ?, ?, ?)
                                 ON DUPLICATE KEY UPDATE status = VALUES(status), mensagens = VALUES(mensagens),
                                                         contatos = VALUES(contatos), conversas = VALUES(conversas)");
            foreach ($instancias as $i) $st->execute([$accountId, $dia, $i['nome'], $i['status'], $i['mensagens'], $i['contatos'], $i['conversas']]);
        } catch (\Throwable $e) {
            error_log('[evolution_painel] foto: ' . $e->getMessage());
        }
    }

    /**
     * Fotos dos últimos $dias dias, já como volume por dia: para cada instância,
     * mensagens do dia = total do dia - total do dia anterior com foto (negativo
     * vira 0: instância recriada). Também devolve a última foto antes de hoje.
     *
     * @return array{serie:list<array{dia:string,mensagens:int,conectadas:int,instancias:int}>,anterior:array<string,int>,desde:?string}
     */
    public static function historico(\PDO $pdo, int $accountId, string $hoje, int $dias = 30): array
    {
        try {
            $ini = (new \DateTimeImmutable($hoje))->modify('-' . $dias . ' days')->format('Y-m-d');
            $st = $pdo->prepare("SELECT dia, instancia, status, mensagens FROM evolution_snapshots WHERE account_id = ? AND dia >= ? ORDER BY dia, instancia");
            $st->execute([$accountId, $ini]);
            $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            return ['serie' => [], 'anterior' => [], 'desde' => null];
        }
        return self::serie($linhas, $hoje);
    }

    /** Parte pura do histórico (testada sem banco). */
    public static function serie(array $linhas, string $hoje): array
    {
        $porDia = []; $ultimo = []; $anterior = [];
        foreach ($linhas as $l) $porDia[$l['dia']][] = $l;
        ksort($porDia);
        $serie = [];
        foreach ($porDia as $dia => $fotos) {
            $msgs = 0; $con = 0;
            foreach ($fotos as $f) {
                $n = $f['instancia']; $m = (int) $f['mensagens'];
                if (isset($ultimo[$n])) $msgs += max(0, $m - $ultimo[$n]);
                if ($dia < $hoje) $anterior[$n] = $m;
                $ultimo[$n] = $m;
                if ($f['status'] === 'open') $con++;
            }
            $serie[] = ['dia' => $dia, 'mensagens' => $msgs, 'conectadas' => $con, 'instancias' => count($fotos)];
        }
        // O primeiro dia não tem com o que comparar: o volume dele é desconhecido.
        if ($serie) $serie[0]['mensagens'] = null;
        return ['serie' => $serie, 'anterior' => $anterior, 'desde' => $serie[0]['dia'] ?? null];
    }
}
