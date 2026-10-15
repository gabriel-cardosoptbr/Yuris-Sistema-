<?php
namespace App\Plataforma;

use App\Core\Database;
use App\Notificacoes\Aviso;

/**
 * Eventos: o que a plataforma conta ao CRM, recebido por
 * POST /api/plataforma_eventos.php?c=<conta>.
 *
 * ---------------------------------------------------------------------------
 * AS QUATRO GARANTIAS
 * ---------------------------------------------------------------------------
 * 1. AUTENTICIDADE. Só vale evento assinado com o segredo desta conta:
 *    `X-Fleetiflow-Assinatura: sha256=HMAC(segredo, "<timestamp>.<corpo>")`,
 *    comparado em tempo constante, com timestamp de no máximo 10 minutos.
 * 2. IDEMPOTÊNCIA. O id do evento é único por conta (plataforma_eventos): a
 *    plataforma reentrega quando não recebe 2xx, e a segunda entrega responde
 *    200 sem gravar nada.
 * 3. LIGAÇÃO. O evento traz o código e o CNPJ da conta. Vale o vínculo pelo
 *    código; sem vínculo, um lead não excluído com o mesmo CNPJ (se for um só)
 *    é ligado automaticamente. Sem lead, o evento fica guardado e aparece
 *    quando alguém ligar a conta.
 * 4. NADA TRAVA. A nota no card e o aviso ao vendedor falham em silêncio: o
 *    evento já está guardado.
 */
final class Eventos
{
    private const TOLERANCIA_SEG = 600;

    public const MODULOS = ['FINANCEIRO' => 'Financeiro', 'IA' => 'Gestão com IA', 'FROTA' => 'Gestão de Frota', 'MANUTENCAO' => 'Manutenção'];

    /** @return int 0 = válido; 401 = assinatura ruim; 410 = conta sem conexão */
    public static function autenticar(int $accountId, string $corpo, string $timestamp, string $assinatura, ?int $agora = null): int
    {
        $segredo = Conexao::segredoDosEventos($accountId);
        if ($segredo === null) return 410;
        $agora = $agora ?? time();
        if (!ctype_digit($timestamp) || abs($agora - (int) $timestamp) > self::TOLERANCIA_SEG) return 401;
        $esperada = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $corpo, $segredo);
        return hash_equals($esperada, $assinatura) ? 0 : 401;
    }

    /**
     * Grava e aplica um evento já autenticado.
     *
     * @return array{novo:bool, card_id:?int}
     */
    public static function receber(int $accountId, array $evento): array
    {
        $id = (string) ($evento['id'] ?? '');
        $tipo = (string) ($evento['tipo'] ?? '');
        if (!preg_match('/^[0-9a-f-]{36}$/i', $id) || !preg_match('/^[a-z]+\.[a-z]+$/', $tipo)) {
            throw new \InvalidArgumentException('Evento fora do contrato.');
        }
        $codigo = Vinculo::normalizarCodigo((string) ($evento['conta']['codigo'] ?? ''));
        $pdo = Database::getConnection();

        try {
            $pdo->prepare(
                'INSERT INTO plataforma_eventos (account_id, evento_id, tipo, codigo_conta, payload, recebido_em)
                 VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())'
            )->execute([$accountId, strtolower($id), $tipo, $codigo ?: null, json_encode($evento, JSON_UNESCAPED_UNICODE)]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') return ['novo' => false, 'card_id' => null];
            throw $e;
        }
        $eventoRow = (int) $pdo->lastInsertId();
        Conexao::marcarEvento($accountId);

        // Assinatura pelo site (página de preços): quem assinou ainda não tem conta.
        if (str_starts_with($tipo, 'site.')) {
            $cardId = self::leadDoSite($accountId, $evento);
            if ($cardId) $pdo->prepare('UPDATE plataforma_eventos SET card_id = ? WHERE id = ?')->execute([$cardId, $eventoRow]);
            return ['novo' => true, 'card_id' => $cardId];
        }

        $vinculo = $codigo !== '' ? Vinculo::doCodigo($accountId, $codigo) : null;
        if (!$vinculo && $codigo !== '') $vinculo = self::ligarPeloCnpj($accountId, $codigo, $evento);
        if (!$vinculo || !$vinculo['card_id']) return ['novo' => true, 'card_id' => null];

        $cardId = (int) $vinculo['card_id'];
        $pdo->prepare('UPDATE plataforma_eventos SET card_id = ? WHERE id = ?')->execute([$cardId, $eventoRow]);
        Vinculo::envelhecer($accountId, $codigo);

        try {
            $card = Vinculo::card($accountId, $cardId);
        } catch (\InvalidArgumentException $e) {
            return ['novo' => true, 'card_id' => null];
        }

        [$assunto, $texto] = self::descrever($evento);
        Vinculo::nota($accountId, $card, $assunto, $texto, null);

        // O pedido da conta é o que pede ação: avisa quem atende o lead.
        if ($tipo === 'solicitacao.criada') {
            $dados = [
                'tipo'         => 'plataforma',
                'titulo'       => $assunto . ': ' . mb_substr((string) ($evento['conta']['nome'] ?? ''), 0, 80),
                'mensagem'     => mb_substr($texto, 0, 400),
                'entidade'     => 'card',
                'entidade_id'  => $cardId,
                'url'          => '/prospeccao.php?open=' . $cardId,
                'preferencia'  => 'responsavel',
                'chave_dedupe' => 'plataforma:' . strtolower($id),
            ];
            try {
                if (!empty($card['responsavel_user_id'])) Aviso::paraUsuario($accountId, (int) $card['responsavel_user_id'], $dados);
                else Aviso::paraConta($accountId, $dados);
            } catch (\Throwable $e) {
                error_log('[plataforma] aviso do pedido ' . $id . ': ' . $e->getMessage());
            }
        }

        return ['novo' => true, 'card_id' => $cardId];
    }

    /**
     * O lead pago do site (15/10/2026): quem assinou um plano pela página de
     * preços vira card, ou o card que já existe para ele (mesma assinatura,
     * mesmo CNPJ, e-mail ou WhatsApp). Assinatura autorizada vai para "Venda
     * concluída" com o valor fechado; a conta é aberta pelo time no Master.
     *
     * @return int|null o card
     */
    private static function leadDoSite(int $accountId, array $evento): ?int
    {
        $a = is_array($evento['dados']['assinatura'] ?? null) ? $evento['dados']['assinatura'] : [];
        $assinaturaId = (string) ($a['id'] ?? '');
        if ($assinaturaId === '') return null;
        $pdo = Database::getConnection();

        // 1. Um evento anterior da mesma assinatura já tem card.
        $st = $pdo->prepare(
            "SELECT card_id FROM plataforma_eventos
              WHERE account_id = ? AND card_id IS NOT NULL AND tipo LIKE 'site.%'
                AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.dados.assinatura.id')) = ?
              ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$accountId, $assinaturaId]);
        $cardId = (int) ($st->fetchColumn() ?: 0);

        // 2. Um lead que já existe: mesmo CNPJ, e-mail ou WhatsApp.
        if (!$cardId) {
            $doc = preg_replace('/\D/', '', (string) ($a['documento'] ?? ''));
            $email = strtolower(trim((string) ($a['email'] ?? '')));
            $fone8 = substr(preg_replace('/\D/', '', (string) ($a['telefone'] ?? '')), -8);
            $st = $pdo->prepare(
                "SELECT id FROM cards
                  WHERE account_id = ? AND deleted_at IS NULL
                    AND ((? <> '' AND REPLACE(REPLACE(REPLACE(COALESCE(cpf_cnpj,''),'.',''),'-',''),'/','') = ?)
                      OR (? <> '' AND LOWER(COALESCE(email,'')) = ?)
                      OR (LENGTH(?) = 8 AND RIGHT(REGEXP_REPLACE(COALESCE(telefone_whatsapp,''), '[^0-9]', ''), 8) = ?))
                  ORDER BY updated_at DESC LIMIT 1"
            );
            $st->execute([$accountId, $doc, $doc, $email, $email, $fone8, $fone8]);
            $cardId = (int) ($st->fetchColumn() ?: 0);
        }

        $colunas = \App\WhatsAppAgente\SdrFleetiflow::colunasDaConta($pdo, $accountId);
        $autorizada = ($a['status'] ?? '') === 'AUTORIZADA';
        $valor = (float) ($a['valorMensal'] ?? 0);

        // 3. Ninguém: lead novo.
        if (!$cardId) {
            $fone = preg_replace('/\D/', '', (string) ($a['telefone'] ?? ''));
            if ($fone !== '' && strlen($fone) <= 11) $fone = '55' . $fone;
            $cardId = (int) \App\Prospeccao\Card::create([
                'account_id'          => $accountId,
                'titulo'              => (string) ($a['empresa'] ?? $a['nome'] ?? 'Assinatura pelo site'),
                'cliente_nome'        => (string) ($a['nome'] ?? ''),
                'empresa_nome'        => (string) ($a['empresa'] ?? ''),
                'telefone_whatsapp'   => $fone ?: null,
                'email'               => (string) ($a['email'] ?? '') ?: null,
                'cpf_cnpj'            => (string) ($a['documento'] ?? '') ?: null,
                'responsavel_user_id' => \App\WhatsAppAgente\SdrFleetiflow::especialistaPadrao($accountId),
                'coluna_id'           => $colunas['novo'] ?? null,
                'valor_proposta'      => $valor,
                'descricao'           => 'Assinou pela página de preços do site: ' . (string) ($a['descricao'] ?? ''),
                '_usuario_id'         => null,
            ]);
        }
        if (!$cardId) return null;

        if ($autorizada) {
            \App\WhatsAppAgente\SdrFleetiflow::moverEtapa($accountId, $cardId, 'venda', null, true);
            try {
                \App\Prospeccao\Card::update($cardId, ['valor_proposta' => $valor, 'valor_fechado_final' => $valor, '_usuario_id' => null]);
            } catch (\Throwable $e) {
                error_log('[plataforma] valor do lead do site ' . $cardId . ': ' . $e->getMessage());
            }
        }

        try {
            $card = Vinculo::card($accountId, $cardId);
        } catch (\InvalidArgumentException $e) {
            return $cardId;
        }
        [$assunto, $texto] = self::descrever($evento);
        Vinculo::nota($accountId, $card, $assunto, $texto, null);

        // Assinatura ativa ou cancelada pede gente: abrir a conta, ou entender o porquê.
        if (in_array($a['status'] ?? '', ['AUTORIZADA', 'CANCELADA'], true) && ($evento['tipo'] ?? '') === 'site.assinatura') {
            $dados = [
                'tipo'         => 'plataforma',
                'titulo'       => $assunto . ': ' . mb_substr((string) ($a['empresa'] ?? ''), 0, 80),
                'mensagem'     => mb_substr($texto, 0, 400),
                'entidade'     => 'card',
                'entidade_id'  => $cardId,
                'url'          => '/prospeccao.php?open=' . $cardId,
                'preferencia'  => 'responsavel',
                'chave_dedupe' => 'plataforma:' . strtolower((string) ($evento['id'] ?? '')),
            ];
            try {
                if (!empty($card['responsavel_user_id'])) Aviso::paraUsuario($accountId, (int) $card['responsavel_user_id'], $dados);
                else Aviso::paraConta($accountId, $dados);
            } catch (\Throwable $e) {
                error_log('[plataforma] aviso do lead do site: ' . $e->getMessage());
            }
        }
        return $cardId;
    }

    /** Sem vínculo: um lead só com o mesmo CNPJ vira o vínculo (e a plataforma fica sabendo). */
    private static function ligarPeloCnpj(int $accountId, string $codigo, array $evento): ?array
    {
        $cnpj = preg_replace('/\D/', '', (string) ($evento['conta']['cnpj'] ?? ''));
        if (strlen($cnpj) < 11 || preg_match('/^0+$/', $cnpj)) return null;
        $st = Database::getConnection()->prepare(
            "SELECT id FROM cards
              WHERE account_id = ? AND deleted_at IS NULL
                AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cpf_cnpj,''),'.',''),'-',''),'/',''),' ','') = ?
              LIMIT 2"
        );
        $st->execute([$accountId, $cnpj]);
        $ids = $st->fetchAll(\PDO::FETCH_COLUMN);
        if (count($ids) !== 1) return null;
        try {
            return Vinculo::vincular($accountId, (int) $ids[0], $codigo, 0);
        } catch (\Throwable $e) {
            error_log('[plataforma] vínculo automático de ' . $codigo . ': ' . $e->getMessage());
            return null;
        }
    }

    /** O evento em português, para a nota do card. @return array{0:string,1:string} */
    public static function descrever(array $e): array
    {
        $d = is_array($e['dados'] ?? null) ? $e['dados'] : [];
        $origem = $e['origem'] ?? [];
        $quem = trim((string) ($origem['nome'] ?? ''));
        $por = match ($origem['tipo'] ?? '') {
            'conta'  => $quem !== '' ? " (pela conta, $quem)" : ' (pela conta)',
            'master' => $quem !== '' ? " (pelo Master, $quem)" : ' (pelo Master)',
            default  => '',
        };

        switch ($e['tipo'] ?? '') {
            case 'solicitacao.criada':
            case 'solicitacao.cancelada':
                $s = $d['solicitacao'] ?? [];
                $assunto = match ($s['tipo'] ?? '') {
                    'MODULO' => 'módulo ' . ($s['moduloNome'] ?? $s['modulo'] ?? ''),
                    'PLANO'  => 'mudar para ' . ($s['planoNome'] ?? 'outro plano'),
                    default  => 'falar com o comercial',
                };
                $cancelado = ($e['tipo'] ?? '') === 'solicitacao.cancelada';
                $texto = ($cancelado ? 'A conta cancelou o pedido: ' : 'A conta pediu na plataforma: ') . $assunto . '.';
                if (!empty($s['mensagem'])) $texto .= "\nRecado: " . $s['mensagem'];
                if (!empty($s['solicitadoPorNome'])) $texto .= "\nPedido por " . $s['solicitadoPorNome'] . '.';
                return [$cancelado ? 'Pedido cancelado na plataforma' : 'Pedido na plataforma', $texto];
            case 'modulo.alterado':
                $nome = $d['nome'] ?? (self::MODULOS[$d['modulo'] ?? ''] ?? ($d['modulo'] ?? 'módulo'));
                return ['Módulo ' . (!empty($d['ativo']) ? 'liberado' : 'desligado'), "Módulo $nome " . (!empty($d['ativo']) ? 'liberado' : 'desligado') . " na plataforma$por."];
            case 'plano.alterado':
                $valor = isset($d['valorMensal']) ? ' (' . self::real((float) $d['valorMensal']) . '/mês)' : '';
                $antes = !empty($d['anterior']) && $d['anterior'] !== ($d['plano'] ?? '') ? ', antes ' . $d['anterior'] : '';
                return ['Plano alterado', 'Plano agora: ' . ($d['plano'] ?? '—') . $valor . $antes . $por . '.'];
            case 'conta.status':
                return [!empty($d['ativa']) ? 'Conta reativada' : 'Conta suspensa', 'A conta foi ' . (!empty($d['ativa']) ? 'reativada' : 'suspensa') . " na plataforma$por."];
            case 'assinatura.alterada':
                return ['Assinatura alterada', 'Assinatura: ' . ($d['anterior'] ?? '—') . ' → ' . ($d['status'] ?? '—') . $por . '.'];
            case 'recarga.aprovada':
            case 'recarga.estornada':
                $r = $d['recarga'] ?? [];
                $meio = ($r['metodo'] ?? '') === 'PIX' ? 'Pix' : 'cartão';
                $estorno = ($e['tipo'] ?? '') === 'recarga.estornada';
                $saldo = isset($d['saldo']) ? ' Saldo agora: ' . self::real((float) $d['saldo']) . '.' : '';
                return [$estorno ? 'Recarga estornada' : 'Recarga aprovada', 'Recarga de ' . self::real((float) ($r['valor'] ?? 0)) . " por $meio " . ($estorno ? 'estornada' : 'aprovada') . '.' . $saldo];
            case 'site.assinatura':
                $a = $d['assinatura'] ?? [];
                $plano = (string) ($a['descricao'] ?? 'plano');
                $valor = self::real((float) ($a['valorMensal'] ?? 0));
                $contato = trim(($a['nome'] ?? '') . ' · ' . ($a['email'] ?? '') . ' · ' . ($a['telefone'] ?? ''), ' ·');
                return match ($d['status'] ?? '') {
                    'AUTORIZADA' => ['Assinatura pelo site ativa', "Assinou $plano por $valor/mês no cartão (recorrente). Abra a conta no Master com esse plano.\nContato: $contato"],
                    'CANCELADA'  => ['Assinatura pelo site cancelada', "A assinatura de $plano ($valor/mês) foi cancelada.\nContato: $contato"],
                    'PAUSADA'    => ['Assinatura pelo site pausada', "A assinatura de $plano ($valor/mês) foi pausada.\nContato: $contato"],
                    default      => ['Assinatura pelo site', "Começou a assinar $plano ($valor/mês).\nContato: $contato"],
                };
            case 'site.pagamento':
                $a = $d['assinatura'] ?? [];
                return ['Mensalidade paga pelo site', 'Cobrança de ' . self::real((float) ($d['valor'] ?? 0)) . ' aprovada (' . (int) ($a['pagamentosAprovados'] ?? 0) . 'ª) de ' . (string) ($a['descricao'] ?? 'plano') . '.'];
            case 'mensalidade.paga':
                return ['Mensalidade paga', 'Mensalidade ' . ($d['competencia'] ?? '') . ' paga: ' . self::real((float) ($d['valor'] ?? 0)) . $por . '.'];
            default:
                return ['Evento da plataforma', 'Evento ' . ($e['tipo'] ?? '?') . ' recebido.'];
        }
    }

    public static function real(float $v): string
    {
        return 'R$ ' . number_format($v, 2, ',', '.');
    }

    /** Eventos do lead, para a ficha. */
    public static function doCard(int $accountId, int $cardId, int $limite = 20): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT tipo, payload, recebido_em FROM plataforma_eventos WHERE account_id = ? AND card_id = ? ORDER BY id DESC LIMIT ' . max(1, min(100, $limite))
        );
        $st->execute([$accountId, $cardId]);
        return array_map(function ($r) {
            [$assunto, $texto] = self::descrever(json_decode($r['payload'], true) ?: []);
            return ['tipo' => $r['tipo'], 'assunto' => $assunto, 'texto' => $texto, 'recebido_em' => $r['recebido_em']];
        }, $st->fetchAll(\PDO::FETCH_ASSOC));
    }
}
