<?php
namespace App\Inicio;

use App\Core\Database;
use App\Processos\PublicacaoEstrutura;
use App\Tarefas\CargaDaEquipe;
use App\Tarefas\Central;
use App\WhatsAppAgente\MetricasAtendimento;
use App\WhatsAppAgente\WhatsAppMessage;

/**
 * O QUE A HOME NOVA MOSTRA: o dia do escritório, lido de onde ele já está.
 *
 * Nada aqui é copiado nem gravado. Cada bloco lê a fonte que a tela do módulo
 * já usa, com a MESMA regra de acesso:
 *
 *   agenda e prazos      App\Tarefas\Central (tasks + processo_tarefas +
 *                        processo_prazos, pela visibilidade de quadros e processos)
 *   publicações          push_events pendentes de tratamento, por conta
 *   equipe               users da conta + CargaDaEquipe (tarefas abertas) + os
 *                        prazos de processo cujo responsável é o nome da pessoa
 *   processos            processos por última movimentação, com a última publicação
 *   aguardando retorno   whatsapp_chats em que a última mensagem é do contato,
 *                        a mesma regra de MetricasAtendimento::relatorio
 *   números              contagens simples por conta; a receita vem do dashboard
 *
 * As funções puras (saudação, tipo do item, rótulo de vencimento, resumo) ficam
 * separadas das consultas para o teste cobrir a regra sem banco.
 */
final class Painel
{
    public const FUSO = 'America/Sao_Paulo';

    private const DIAS  = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
    private const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    private const DIAS_CURTOS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

    /** Tipos de item da agenda e o rótulo que a tela mostra. */
    public const TIPOS = ['audiencia' => 'Audiência', 'reuniao' => 'Reunião', 'prazo' => 'Prazo', 'tarefa' => 'Tarefa'];

    // ── Regras puras ─────────────────────────────────────────────────────────

    public static function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone(self::FUSO));
    }

    public static function saudacao(int $hora): string
    {
        if ($hora >= 5 && $hora < 12) return 'Bom dia';
        if ($hora >= 12 && $hora < 18) return 'Boa tarde';
        return 'Boa noite';
    }

    public static function primeiroNome(string $nome): string
    {
        $nome = trim($nome);
        if ($nome === '') return '';
        $partes = preg_split('/\s+/', $nome) ?: [$nome];
        $p = $partes[0];
        // "Dr." e "Dra." não são nome.
        if (count($partes) > 1 && preg_match('/^dr[a]?\.?$/iu', $p)) $p = $partes[1];
        return $p;
    }

    /** Iniciais para o avatar: "Mariana Cardoso Pereira" vira "MP". */
    public static function iniciais(string $nome): string
    {
        $partes = array_values(array_filter(preg_split('/\s+/', trim($nome)) ?: [], static fn($p) => $p !== '' && !preg_match('/^(d[aeo]s?|e)$/iu', $p)));
        if (!$partes) return '?';
        $a = mb_substr($partes[0], 0, 1, 'UTF-8');
        $b = count($partes) > 1 ? mb_substr($partes[count($partes) - 1], 0, 1, 'UTF-8') : '';
        return mb_strtoupper($a . $b, 'UTF-8');
    }

    /** "sexta-feira, 7 de novembro". */
    public static function dataPorExtenso(\DateTimeImmutable $d): string
    {
        return self::DIAS[(int) $d->format('w')] . ', ' . (int) $d->format('j') . ' de ' . self::MESES[(int) $d->format('n') - 1];
    }

    private static function semAcento(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        return strtr($s, ['á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    }

    /**
     * O que o item é, pelo título e pela origem: audiência e reunião se anunciam
     * no nome; prazo de processo é prazo; o resto é tarefa.
     */
    public static function tipoDoItem(array $item): string
    {
        $t = self::semAcento((string) ($item['titulo'] ?? ''));
        if (str_contains($t, 'audienc')) return 'audiencia';
        if (str_contains($t, 'reuniao') || str_contains($t, 'reunir')) return 'reuniao';
        if (($item['origem'] ?? '') === 'prazo') return 'prazo';
        return 'tarefa';
    }

    /** A agenda do dia: o que cai em "hoje" e está aberto, com hora primeiro. */
    public static function agendaDoDia(array $itens): array
    {
        $hoje = array_values(array_filter($itens, static fn($i) => empty($i['concluida']) && in_array('hoje', $i['periodos'] ?? [], true)));
        usort($hoje, static function (array $a, array $b): int {
            $ha = $a['hora'] ?? null; $hb = $b['hora'] ?? null;
            if (($ha === null) !== ($hb === null)) return $ha === null ? 1 : -1;
            return [$ha ?? '', $a['titulo']] <=> [$hb ?? '', $b['titulo']];
        });
        foreach ($hoje as &$i) $i['tipo'] = self::tipoDoItem($i);
        unset($i);
        return $hoje;
    }

    /**
     * Os prazos que pedem atenção: atrasados primeiro, depois os próximos, só o
     * que é prazo ou tarefa de processo (quadro é rotina, não prazo processual).
     */
    public static function proximosPrazos(array $itens, int $limite = 5): array
    {
        $lista = array_values(array_filter($itens, static fn($i) =>
            empty($i['concluida']) && !empty($i['data']) && in_array($i['origem'] ?? '', ['prazo', 'tarefa_processo'], true)));
        usort($lista, static fn($a, $b) => [$a['data'], $a['titulo']] <=> [$b['data'], $b['titulo']]);
        foreach ($lista as &$i) $i['tipo'] = self::tipoDoItem($i);
        unset($i);
        return array_slice($lista, 0, $limite);
    }

    /**
     * Como dizer quando vence: ['texto' => 'hoje', 'tom' => 'r'|'w'|'n'].
     * r é vermelho (hoje ou atrasado), w é âmbar (amanhã), n é neutro.
     */
    public static function rotuloDeVencimento(string $data, string $hoje): array
    {
        $d = new \DateTimeImmutable($data); $h = new \DateTimeImmutable($hoje);
        $dias = (int) $h->diff($d)->format('%r%a');
        if ($dias < 0)  return ['texto' => 'atrasado ' . (-$dias === 1 ? 'há 1 dia' : 'há ' . (-$dias) . ' dias'), 'tom' => 'r'];
        if ($dias === 0) return ['texto' => 'hoje', 'tom' => 'r'];
        if ($dias === 1) return ['texto' => 'amanhã', 'tom' => 'w'];
        if ($dias < 7)  return ['texto' => self::DIAS_CURTOS[(int) $d->format('w')] . ', ' . (int) $d->format('j'), 'tom' => 'n'];
        return ['texto' => $d->format('d/m'), 'tom' => 'n'];
    }

    /** "há 40 min", "há 3 h", "há 2 dias". */
    public static function tempoDesde(int $minutos): string
    {
        if ($minutos < 1)    return 'agora';
        if ($minutos < 60)   return 'há ' . $minutos . ' min';
        if ($minutos < 1440) return 'há ' . intdiv($minutos, 60) . ' h';
        $d = intdiv($minutos, 1440);
        return 'há ' . $d . ($d === 1 ? ' dia' : ' dias');
    }

    /** "hoje", "ontem" ou dd/mm. */
    public static function diaRelativo(string $data, string $hoje): string
    {
        $data = substr($data, 0, 10);
        if ($data === $hoje) return 'hoje';
        if ($data === (new \DateTimeImmutable($hoje))->modify('-1 day')->format('Y-m-d')) return 'ontem';
        return (new \DateTimeImmutable($data))->format('d/m');
    }

    /**
     * A frase da saudação, montada só com o que existe. Vazio de tudo: um convite.
     *
     * @param array{audiencias:int, prazos:int, publicacoes:int, retorno:int, atrasados:int} $n
     */
    public static function resumo(array $n): string
    {
        $partes = [];
        if ($n['audiencias'] > 0) $partes[] = '<b>' . $n['audiencias'] . ($n['audiencias'] === 1 ? ' audiência' : ' audiências') . '</b>';
        if ($n['prazos'] > 0)     $partes[] = '<b>' . $n['prazos'] . ($n['prazos'] === 1 ? ' prazo vencendo hoje' : ' prazos vencendo hoje') . '</b>';
        if ($n['publicacoes'] > 0) $partes[] = '<b>' . $n['publicacoes'] . ($n['publicacoes'] === 1 ? ' publicação sem tratamento' : ' publicações sem tratamento') . '</b>';
        if ($n['retorno'] > 0)    $partes[] = '<b>' . $n['retorno'] . ($n['retorno'] === 1 ? ' cliente aguardando retorno' : ' clientes aguardando retorno') . '</b>';

        if (!$partes) {
            return $n['atrasados'] > 0
                ? 'Nada marcado para hoje, mas há <b>' . $n['atrasados'] . ($n['atrasados'] === 1 ? ' prazo atrasado' : ' prazos atrasados') . '</b> pedindo atenção.'
                : 'Nenhum compromisso marcado para hoje. Boa hora para adiantar o que vem na semana.';
        }
        $frase = 'Hoje o escritório tem ';
        if (count($partes) === 1) $frase .= $partes[0];
        else $frase .= implode(', ', array_slice($partes, 0, -1)) . ' e ' . end($partes);
        $frase .= '.';
        if ($n['atrasados'] > 0) $frase .= ' E <b>' . $n['atrasados'] . ($n['atrasados'] === 1 ? ' prazo atrasado' : ' prazos atrasados') . '</b>.';
        return $frase;
    }

    /**
     * A equipe de hoje: para cada pessoa, o que tem na mão.
     *
     * @param array $usuarios      [{id, nome, perfil, role}]
     * @param array $cargaPessoas  CargaDaEquipe::agora()['pessoas'], por user_id
     * @param array $itensCentral  Central::listar()['itens']
     */
    public static function equipe(array $usuarios, array $cargaPessoas, array $itensCentral): array
    {
        $porNome = [];
        foreach ($usuarios as $u) $porNome[mb_strtolower(trim((string) $u['nome']), 'UTF-8')] = (int) $u['id'];

        $hojePorPessoa = []; $semanaPorPessoa = []; $atrasoProc = [];
        foreach ($itensCentral as $i) {
            if (!empty($i['concluida'])) continue;
            $uid = $porNome[mb_strtolower(trim((string) ($i['responsavel_nome'] ?? '')), 'UTF-8')] ?? null;
            if ($uid === null) continue;
            $per = $i['periodos'] ?? [];
            if (in_array('hoje', $per, true))   $hojePorPessoa[$uid] = ($hojePorPessoa[$uid] ?? 0) + 1;
            if (in_array('semana', $per, true)) $semanaPorPessoa[$uid] = ($semanaPorPessoa[$uid] ?? 0) + 1;
            if (in_array('atrasadas', $per, true) && ($i['origem'] ?? '') !== 'tarefa') $atrasoProc[$uid] = ($atrasoProc[$uid] ?? 0) + 1;
        }

        $saida = [];
        foreach ($usuarios as $u) {
            $uid = (int) $u['id'];
            $c = $cargaPessoas[$uid] ?? ['abertas' => 0, 'vencidas' => 0, 'vencendo' => 0];
            $hoje = $hojePorPessoa[$uid] ?? 0;
            $vencidas = (int) $c['vencidas'] + ($atrasoProc[$uid] ?? 0);
            $abertas = (int) $c['abertas'];
            $semana = $semanaPorPessoa[$uid] ?? 0;
            if ($vencidas > 0)   { $estado = 'atrasado'; }
            elseif ($hoje > 0)   { $estado = 'ocupado'; }
            else                 { $estado = 'livre'; }

            $partes = [];
            $partes[] = $hoje > 0 ? $hoje . ' hoje' : 'nada hoje';
            if ($semana > 0)   $partes[] = $semana . ' na semana';
            if ($vencidas > 0) $partes[] = $vencidas . ($vencidas === 1 ? ' vencido' : ' vencidos');
            if ($abertas > 0)  $partes[] = $abertas . ($abertas === 1 ? ' tarefa aberta' : ' tarefas abertas');

            $saida[] = [
                'id' => $uid, 'nome' => (string) $u['nome'], 'iniciais' => self::iniciais((string) $u['nome']),
                'papel' => self::papel($u), 'estado' => $estado, 'hoje' => $hoje, 'semana' => $semana,
                'vencidas' => $vencidas, 'abertas' => $abertas, 'carga' => $abertas + $semana + $vencidas,
                'texto' => implode(' · ', $partes),
            ];
        }
        $max = max(1, ...array_map(static fn($p) => $p['carga'], $saida ?: [['carga' => 1]]));
        foreach ($saida as &$p) $p['pct'] = (int) round(100 * $p['carga'] / $max);
        unset($p);
        usort($saida, static fn($a, $b) => [$b['carga'], $a['nome']] <=> [$a['carga'], $b['nome']]);
        return $saida;
    }

    private static function papel(array $u): string
    {
        $role = (string) ($u['role'] ?? '');
        if ($role === 'owner') return 'Responsável pela conta';
        if ($role === 'admin' || ($u['perfil'] ?? '') === 'admin') return 'Administração';
        if (!empty($u['is_advogado']) || !empty($u['oab'])) return 'Advocacia';
        return 'Equipe';
    }

    /** Situação do processo para a pílula: [rótulo, tom]. */
    public static function situacaoDoProcesso(string $status, ?string $proximoPrazo, string $hoje): array
    {
        $s = mb_strtolower($status, 'UTF-8');
        if (in_array($s, ['concluido', 'concluído', 'encerrado', 'arquivado'], true)) return ['Encerrado', 'ok'];
        if ($s === 'suspenso') return ['Suspenso', 'ag'];
        if ($proximoPrazo) {
            $dias = (int) (new \DateTimeImmutable($hoje))->diff(new \DateTimeImmutable($proximoPrazo))->format('%r%a');
            if ($dias < 0)  return ['Prazo vencido', 'at'];
            if ($dias <= 3) return ['Prazo em ' . ($dias === 0 ? 'hoje' : $dias . ($dias === 1 ? ' dia' : ' dias')), 'at'];
        }
        return ['Em andamento', 'and'];
    }

    /**
     * Os itens abertos com data, por dia, para o calendário:
     * ['2026-11-07' => [['titulo', 'hora', 'tipo', 'processo_id', 'processo_numero', 'cliente_nome', 'responsavel_nome', 'origem', 'id'], ...]].
     * Cada dia em ordem de hora (sem hora por último) e nome.
     */
    public static function porDia(array $itens): array
    {
        $dias = [];
        foreach ($itens as $i) {
            if (!empty($i['concluida']) || empty($i['data'])) continue;
            $dias[substr((string) $i['data'], 0, 10)][] = [
                'id' => (int) ($i['id'] ?? 0), 'origem' => (string) ($i['origem'] ?? ''), 'titulo' => (string) $i['titulo'],
                'hora' => $i['hora'] ?? null, 'tipo' => self::tipoDoItem($i),
                'processo_id' => !empty($i['processo_id']) ? (int) $i['processo_id'] : null,
                'processo_numero' => $i['processo_numero'] ?? null, 'cliente_nome' => $i['cliente_nome'] ?? null,
                'responsavel_nome' => $i['responsavel_nome'] ?? null,
                'data' => substr((string) $i['data'], 0, 10),
            ];
        }
        foreach ($dias as &$lista) {
            usort($lista, static function (array $a, array $b): int {
                if (($a['hora'] === null) !== ($b['hora'] === null)) return $a['hora'] === null ? 1 : -1;
                return [$a['hora'] ?? '', $a['titulo']] <=> [$b['hora'] ?? '', $b['titulo']];
            });
        }
        unset($lista);
        ksort($dias);
        return $dias;
    }

    /** Prazos de processo que vencem de hoje até daqui a $dias dias (os "urgentes" do cartão). */
    public static function urgentes(array $itens, string $hoje, int $dias = 3): int
    {
        $ate = (new \DateTimeImmutable($hoje))->modify('+' . $dias . ' days')->format('Y-m-d');
        $n = 0;
        foreach ($itens as $i) {
            if (!empty($i['concluida']) || empty($i['data'])) continue;
            if (!in_array($i['origem'] ?? '', ['prazo', 'tarefa_processo'], true)) continue;
            $d = substr((string) $i['data'], 0, 10);
            if ($d >= $hoje && $d <= $ate) $n++;
        }
        return $n;
    }

    /**
     * A grade de um mês para o calendário: semanas de domingo a sábado, cada célula
     * ['data' => 'Y-m-d', 'dia' => 7, 'fora' => true quando é do mês vizinho].
     */
    public static function gradeDoMes(string $mes): array
    {
        $primeiro = new \DateTimeImmutable($mes . '-01');
        $inicio   = $primeiro->modify('-' . (int) $primeiro->format('w') . ' days');
        $semanas  = [];
        $d = $inicio;
        do {
            $semana = [];
            for ($k = 0; $k < 7; $k++) {
                $semana[] = ['data' => $d->format('Y-m-d'), 'dia' => (int) $d->format('j'), 'fora' => $d->format('Y-m') !== $mes];
                $d = $d->modify('+1 day');
            }
            $semanas[] = $semana;
        } while ($d->format('Y-m') === $mes);
        return $semanas;
    }

    /** "Novembro de 2026". */
    public static function nomeDoMes(string $mes): string
    {
        return ucfirst(self::MESES[(int) substr($mes, 5, 2) - 1]) . ' de ' . substr($mes, 0, 4);
    }

    /** "Hoje, 7 de nov" ou "Ter, 11 de nov". */
    public static function tituloDoDia(string $data, string $hoje): string
    {
        $d = new \DateTimeImmutable($data);
        $mes = substr(self::MESES[(int) $d->format('n') - 1], 0, 3);
        $pre = $data === $hoje ? 'Hoje' : ucfirst(self::DIAS_CURTOS[(int) $d->format('w')]);
        return $pre . ', ' . (int) $d->format('j') . ' de ' . $mes;
    }

    // ── Consultas ────────────────────────────────────────────────────────────

    private static function lista(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
        if (!$ids) $ids = [0];
        return [implode(',', array_fill(0, count($ids), '?')), $ids];
    }

    /** As pessoas da conta, ativas, em ordem de nome. */
    public static function usuarios(array $tenantIds): array
    {
        [$in, $p] = self::lista($tenantIds);
        $st = Database::getConnection()->prepare(
            "SELECT id, nome, perfil, role, is_advogado, oab FROM users
              WHERE account_id IN ($in) AND deleted_at IS NULL AND (status IS NULL OR status = 'active')
           ORDER BY nome LIMIT 12"
        );
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** Publicações do Diário sem tratamento, as mais novas primeiro, e o total. */
    public static function publicacoes(array $tenantIds, int $limite = 4): array
    {
        [$in, $p] = self::lista($tenantIds);
        $pdo = Database::getConnection();
        $stT = $pdo->prepare("SELECT COUNT(*) FROM push_events WHERE account_id IN ($in) AND status = 'ativo' AND tratamento_status = 'pendente'");
        $stT->execute($p);
        $total = (int) $stT->fetchColumn();

        $st = $pdo->prepare(
            "SELECT e.id, e.tribunal, e.tipo_comunicacao, e.titulo, e.resumo, e.data_disponibilizacao, e.orgao, LEFT(e.conteudo, 12000) AS conteudo, e.payload_original,
                    COALESCE(NULLIF(e.numero_processo_mascara,''), e.numero_processo) AS numero, e.processo_id, pr.cliente_nome, pr.cliente_id
               FROM push_events e
          LEFT JOIN processos pr ON pr.id = e.processo_id
              WHERE e.account_id IN ($in) AND e.status = 'ativo' AND e.tratamento_status = 'pendente'
           ORDER BY e.data_disponibilizacao DESC, e.id DESC LIMIT " . (int) $limite
        );
        $st->execute($p);
        $itens = $st->fetchAll(\PDO::FETCH_ASSOC);
        // O pop-up da home mostra a publicação em tópicos: cabeçalho, partes, advogados
        // com a OAB, cada seção do teor em parágrafos e a assinatura à parte
        // (App\Processos\PublicacaoEstrutura). O texto cru só vai quando não deu para separar.
        foreach ($itens as &$it) {
            $texto = (string) ($it['conteudo'] ?: $it['resumo']);
            $it['estrutura'] = PublicacaoEstrutura::estruturar($texto, $it['payload_original'] ?? null);
            $it['texto'] = $it['estrutura']['blocos'] ? '' : $texto;
            unset($it['conteudo'], $it['payload_original']);
        }
        unset($it);
        return ['total' => $total, 'itens' => $itens];
    }

    /** Processos pela última movimentação, com a última publicação de cada um. */
    public static function processosMovimentados(array $tenantIds, int $limite = 5): array
    {
        [$in, $p] = self::lista($tenantIds);
        $st = Database::getConnection()->prepare(
            "SELECT p.id, p.cliente_nome, p.status, p.proximo_prazo, p.tipo_acao, p.vara_comarca,
                    COALESCE(NULLIF(p.numero_cnj,''), NULLIF(p.numero,''), CONCAT('#', p.id)) AS numero,
                    GREATEST(COALESCE(p.ultima_movimentacao, '1970-01-01'), COALESCE(p.updated_at, '1970-01-01')) AS movimentado_em,
                    (SELECT CONCAT(e.tribunal, ': ', COALESCE(NULLIF(e.tipo_comunicacao,''), NULLIF(e.titulo,''), 'publicação'))
                       FROM push_events e WHERE e.processo_id = p.id AND e.status = 'ativo'
                   ORDER BY e.data_disponibilizacao DESC, e.id DESC LIMIT 1) AS ultima_publicacao,
                    (SELECT MAX(e.data_disponibilizacao) FROM push_events e WHERE e.processo_id = p.id AND e.status = 'ativo') AS publicada_em
               FROM processos p
              WHERE p.account_id IN ($in) AND p.deleted_at IS NULL
           ORDER BY movimentado_em DESC, p.id DESC LIMIT " . (int) $limite
        );
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Quem está esperando resposta no WhatsApp: a última mensagem é do contato.
     * null quando a conta não tem canal, e a tela nem mostra o bloco.
     */
    public static function aguardandoRetorno(array $tenantIds, int $limite = 4): ?array
    {
        [$in, $p] = self::lista($tenantIds);
        $pdo = Database::getConnection();
        $st = $pdo->prepare("SELECT id FROM whatsapp_instances WHERE account_id IN ($in)");
        $st->execute($p);
        $inst = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        if (!$inst) return null;

        [$inI, $pI] = self::lista($inst);
        $where = "c.instance_id IN ($inI) AND c.is_group = 0 AND c.is_archived = 0 AND c.last_message_from_me = 0 AND c.last_message_at IS NOT NULL
                  AND c.remote_jid NOT LIKE '%@broadcast' AND c.remote_jid NOT LIKE '%@newsletter'"
               . (WhatsAppMessage::temColunaExcluida($pdo) ? ' AND c.excluida_em IS NULL' : '');
        $stT = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_chats c WHERE $where");
        $stT->execute($pI);
        $total = (int) $stT->fetchColumn();

        $st = $pdo->prepare(
            "SELECT c.remote_jid, c.contact_name, c.phone, c.last_message_content, c.last_message_type, c.last_message_at, c.linked_user_id
               FROM whatsapp_chats c WHERE $where ORDER BY c.last_message_at ASC LIMIT " . (int) $limite
        );
        $st->execute($pI);
        $agora = MetricasAtendimento::agora();
        $itens = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $desde = MetricasAtendimento::paraLocal((string) $c['last_message_at']);
            $min   = (int) floor(max(0, strtotime($agora) - strtotime($desde)) / 60);
            $nome  = trim((string) ($c['contact_name'] ?? '')) ?: MetricasAtendimento::telefoneLegivel((string) ($c['phone'] ?: explode('@', (string) $c['remote_jid'])[0]));
            $texto = trim((string) ($c['last_message_content'] ?? ''));
            if ($texto === '') $texto = self::nomeDoTipo((string) ($c['last_message_type'] ?? ''));
            $itens[] = ['jid' => (string) $c['remote_jid'], 'nome' => $nome, 'iniciais' => self::iniciais($nome),
                        'texto' => mb_strimwidth($texto, 0, 90, '…', 'UTF-8'), 'minutos' => $min, 'desde' => self::tempoDesde($min)];
        }
        return ['total' => $total, 'itens' => $itens];
    }

    private static function nomeDoTipo(string $tipo): string
    {
        return match (true) {
            str_contains($tipo, 'image') => 'Enviou uma imagem',
            str_contains($tipo, 'audio') || str_contains($tipo, 'ptt') => 'Enviou um áudio',
            str_contains($tipo, 'document') => 'Enviou um documento',
            str_contains($tipo, 'video') => 'Enviou um vídeo',
            default => 'Enviou uma mensagem',
        };
    }

    /** Os números do rodapé, a porta para Gestão. */
    public static function numeros(array $tenantIds, string $hoje): array
    {
        [$in, $p] = self::lista($tenantIds);
        $pdo = Database::getConnection();
        $mes = substr($hoje, 0, 7) . '-01';
        $q = static function (string $sql, array $extra = []) use ($pdo, $p): int {
            $st = $pdo->prepare($sql);
            $st->execute(array_merge($p, $extra));
            return (int) $st->fetchColumn();
        };
        return [
            'ativos'      => $q("SELECT COUNT(*) FROM processos WHERE account_id IN ($in) AND deleted_at IS NULL AND status = 'ativo'"),
            'novos_mes'   => $q("SELECT COUNT(*) FROM processos WHERE account_id IN ($in) AND deleted_at IS NULL AND created_at >= ?", [$mes]),
            'publicacoes_mes' => $q("SELECT COUNT(*) FROM push_events WHERE account_id IN ($in) AND status = 'ativo' AND data_disponibilizacao >= ?", [$mes]),
            'clientes'    => $q("SELECT COUNT(*) FROM clientes WHERE account_id IN ($in) AND deleted_at IS NULL"),
        ];
    }

    /**
     * Tudo junto, para a página. $escopo é o mesmo da Central
     * (user_id, is_admin, contas_tarefas, contas_processos).
     */
    public static function montar(array $escopo, array $tenantIds): array
    {
        $agora = self::agora();
        $hoje  = $agora->format('Y-m-d');

        $central = Central::listar($escopo, ['situacao' => 'abertas']);
        $itens   = $central['itens'];
        $cont    = $central['contagem'];
        $agenda  = self::agendaDoDia($itens);

        $audienciasHoje = count(array_filter($agenda, static fn($i) => $i['tipo'] === 'audiencia'));
        $audienciasSemana = count(array_filter($itens, static fn($i) => empty($i['concluida']) && in_array('semana', $i['periodos'] ?? [], true) && self::tipoDoItem($i) === 'audiencia'));
        $prazosHoje = count(array_filter($agenda, static fn($i) => $i['tipo'] === 'prazo'));

        $publicacoes = self::publicacoes($tenantIds);
        $whats       = self::aguardandoRetorno($tenantIds);
        $usuarios    = self::usuarios($tenantIds);
        $carga       = CargaDaEquipe::agora($escopo['contas_tarefas'] ?? $tenantIds);
        $numeros     = self::numeros($tenantIds, $hoje) + ['audiencias_semana' => $audienciasSemana];
        $porDia      = self::porDia($itens);

        return [
            'agora'     => $agora,
            'hoje'      => $hoje,
            'saudacao'  => self::saudacao((int) $agora->format('G')),
            'data'      => self::dataPorExtenso($agora),
            'resumo'    => self::resumo([
                'audiencias' => $audienciasHoje, 'prazos' => $prazosHoje, 'publicacoes' => $publicacoes['total'],
                'retorno' => $whats['total'] ?? 0, 'atrasados' => (int) ($cont['atrasadas'] ?? 0),
            ]),
            'agenda'    => $agenda,
            'contagem'  => $cont,
            'prazos'    => self::proximosPrazos($itens),
            'publicacoes' => $publicacoes,
            'equipe'    => self::equipe($usuarios, $carga['pessoas'], $itens),
            'processos' => self::processosMovimentados($tenantIds),
            'whatsapp'  => $whats,
            'numeros'   => $numeros,
            'kpis'      => [
                'urgentes'    => self::urgentes($itens, $hoje),
                'ativos'      => $numeros['ativos'], 'novos_mes' => $numeros['novos_mes'],
                'publicacoes' => $publicacoes['total'], 'audiencias_semana' => $audienciasSemana,
            ],
            'calendario' => ['mes' => substr($hoje, 0, 7), 'por_dia' => $porDia],
        ];
    }
}
