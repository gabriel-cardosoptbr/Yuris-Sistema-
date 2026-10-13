<?php
namespace App\Plataforma;

use App\Core\Database;
use App\Crm\Interacao;
use App\Tarefas\TaskEntrega;

/**
 * Vinculo: qual conta da plataforma é qual lead (card) deste CRM.
 *
 * A ligação é pelo CÓDIGO da conta na plataforma (FLT-XXXX-XXXX), o
 * identificador que o cliente vê na tela "Conta" e dita no telefone. O CRM
 * avisa a plataforma do vínculo (`PUT /contas/{codigo}/vinculo`) com o
 * vendedor responsável, que vira o "seu consultor" na tela do cliente.
 *
 * O resumo da conta (plano, módulos, uso, dinheiro) é lido da API na hora de
 * abrir a ficha e guardado em `resumo_json`: é o que alimenta a carteira sem
 * uma chamada por linha, e o que aparece, marcado como antigo, se a
 * plataforma estiver fora do ar. Evento recebido zera `resumo_em` para a
 * próxima abertura buscar de novo.
 *
 * Toda leitura e escrita filtra account_id.
 */
final class Vinculo
{
    /** Resumo com menos de 2 minutos não é buscado de novo ao reabrir a ficha. */
    private const FRESCO_SEG = 120;

    public static function normalizarCodigo(string $codigo): string
    {
        return strtoupper(preg_replace('/\s+/', '', $codigo));
    }

    public static function codigoValido(string $codigo): bool
    {
        return (bool) preg_match('/^FLT-[A-Z0-9]{4}-[A-Z0-9]{4}$/', $codigo);
    }

    public static function doCard(int $accountId, int $cardId): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM plataforma_vinculos WHERE account_id = ? AND card_id = ? LIMIT 1');
        $st->execute([$accountId, $cardId]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function doCodigo(int $accountId, string $codigo): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM plataforma_vinculos WHERE account_id = ? AND codigo_conta = ? LIMIT 1');
        $st->execute([$accountId, self::normalizarCodigo($codigo)]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** O card da conta, ou erro. */
    public static function card(int $accountId, int $cardId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT c.id, c.account_id, c.cliente_id, c.cliente_nome, c.empresa_nome, c.cpf_cnpj, c.responsavel_user_id
               FROM cards c WHERE c.id = ? AND c.account_id = ? AND c.deleted_at IS NULL LIMIT 1'
        );
        $st->execute([$cardId, $accountId]);
        $card = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$card) throw new \InvalidArgumentException('Lead não encontrado.');
        return $card;
    }

    /**
     * Liga o lead à conta da plataforma.
     *
     * @throws \InvalidArgumentException mensagem para a tela
     * @throws \RuntimeException plataforma fora do ar
     */
    public static function vincular(int $accountId, int $cardId, string $codigo, int $userId): array
    {
        $codigo = self::normalizarCodigo($codigo);
        if (!self::codigoValido($codigo)) {
            throw new \InvalidArgumentException('O código da conta tem o formato FLT-XXXX-XXXX. Ele aparece na tela "Conta" da plataforma.');
        }
        $card = self::card($accountId, $cardId);

        $outro = self::doCodigo($accountId, $codigo);
        if ($outro && (int) $outro['card_id'] !== $cardId && $outro['card_id'] !== null) {
            throw new \InvalidArgumentException('Esta conta já está ligada a outro lead (#' . (int) $outro['card_id'] . '). Desligue lá primeiro.');
        }

        $resumo = Conexao::api($accountId, 'GET', '/contas/' . rawurlencode($codigo));
        if ($resumo['status'] === 404) throw new \InvalidArgumentException('Nenhuma conta na plataforma com o código ' . $codigo . '.');
        if ($resumo['status'] !== 200) throw new \InvalidArgumentException(Conexao::mensagem($resumo, 'A plataforma recusou a consulta.'));

        self::avisarPlataforma($accountId, $card, $codigo);

        // Um lead, uma conta: ligar a outra desliga a anterior.
        $anterior = self::doCard($accountId, $cardId);
        if ($anterior && $anterior['codigo_conta'] !== $codigo) {
            self::desligar($accountId, $anterior);
        }

        $pdo = Database::getConnection();
        if ($outro) {
            $pdo->prepare('UPDATE plataforma_vinculos SET card_id = ?, cliente_id = ? WHERE id = ? AND account_id = ?')
                ->execute([$cardId, $card['cliente_id'] ?: null, (int) $outro['id'], $accountId]);
            $id = (int) $outro['id'];
        } else {
            $pdo->prepare(
                'INSERT INTO plataforma_vinculos (account_id, codigo_conta, card_id, cliente_id, created_by) VALUES (?, ?, ?, ?, ?)'
            )->execute([$accountId, $codigo, $cardId, $card['cliente_id'] ?: null, $userId ?: null]);
            $id = (int) $pdo->lastInsertId();
        }
        self::guardarResumo($accountId, $id, $resumo['corpo']);

        $nome = (string) ($resumo['corpo']['conta']['nome'] ?? $codigo);
        self::nota($accountId, $card, 'Lead ligado à plataforma', "Conta \"$nome\" ($codigo) na plataforma Fleetiflow.", $userId);

        return self::doCard($accountId, $cardId);
    }

    /** Desliga o lead da conta, aqui e na plataforma. */
    public static function desvincular(int $accountId, int $cardId, int $userId): void
    {
        $card = self::card($accountId, $cardId);
        $v = self::doCard($accountId, $cardId);
        if (!$v) return;
        self::desligar($accountId, $v);
        self::nota($accountId, $card, 'Lead desligado da plataforma', 'Conta ' . $v['codigo_conta'] . ' desligada deste lead.', $userId);
    }

    private static function desligar(int $accountId, array $v): void
    {
        try {
            Conexao::api($accountId, 'DELETE', '/contas/' . rawurlencode($v['codigo_conta']) . '/vinculo');
        } catch (\Throwable $e) {
            // Sem rede, desliga só aqui; o próximo vínculo da conta sobrescreve lá.
            error_log('[plataforma] desvincular ' . $v['codigo_conta'] . ': ' . $e->getMessage());
        }
        Database::getConnection()->prepare('DELETE FROM plataforma_vinculos WHERE id = ? AND account_id = ?')
            ->execute([(int) $v['id'], $accountId]);
    }

    /**
     * Conta à plataforma quem atende a conta: o responsável do card, com o
     * link do card. É o que a conta vê como "seu consultor".
     */
    public static function avisarPlataforma(int $accountId, array $card, string $codigo): void
    {
        $responsavel = null;
        if (!empty($card['responsavel_user_id'])) {
            // O login do usuário é o e-mail; só vai como e-mail se for um.
            $st = Database::getConnection()->prepare('SELECT nome, login, telefone FROM users WHERE id = ? AND account_id = ? LIMIT 1');
            $st->execute([(int) $card['responsavel_user_id'], $accountId]);
            $u = $st->fetch(\PDO::FETCH_ASSOC);
            if ($u) {
                $responsavel = [
                    'nome'     => $u['nome'],
                    'email'    => filter_var((string) $u['login'], FILTER_VALIDATE_EMAIL) ? $u['login'] : null,
                    'whatsapp' => $u['telefone'] ?: null,
                ];
            }
        }
        $situacao = Conexao::situacao($accountId);
        $crm = rtrim((string) ($situacao['crm_url'] ?? ''), '/');
        $r = Conexao::api($accountId, 'PUT', '/contas/' . rawurlencode($codigo) . '/vinculo', [
            'referenciaExterna' => (string) $card['id'],
            'urlExterna'        => $crm !== '' ? $crm . '/prospeccao.php?open=' . (int) $card['id'] : null,
            'responsavel'       => $responsavel,
        ]);
        if ($r['status'] !== 200) throw new \InvalidArgumentException(Conexao::mensagem($r, 'A plataforma não aceitou o vínculo.'));
    }

    /** Se o consultor que a plataforma mostra não é o responsável atual do lead, corrige lá. */
    public static function sincronizarResponsavel(int $accountId, array $card, array $resumo): void
    {
        $la = (string) ($resumo['vinculo']['responsavel']['nome'] ?? '');
        $aqui = '';
        if (!empty($card['responsavel_user_id'])) {
            $st = Database::getConnection()->prepare('SELECT nome FROM users WHERE id = ? AND account_id = ? LIMIT 1');
            $st->execute([(int) $card['responsavel_user_id'], $accountId]);
            $aqui = (string) ($st->fetchColumn() ?: '');
        }
        if ($la === $aqui || empty($resumo['conta']['codigo'])) return;
        try {
            self::avisarPlataforma($accountId, $card, (string) $resumo['conta']['codigo']);
        } catch (\Throwable $e) {
            error_log('[plataforma] consultor de ' . $resumo['conta']['codigo'] . ': ' . $e->getMessage());
        }
    }

    /**
     * O resumo da conta, da API quando possível. Sem rede, o último guardado,
     * com `de_cache` verdadeiro para a tela dizer de quando é.
     */
    public static function resumo(int $accountId, array $v, bool $forcar = false): array
    {
        $fresco = $v['resumo_em'] && (time() - strtotime($v['resumo_em'] . ' UTC')) < self::FRESCO_SEG;
        if (!$forcar && $fresco && $v['resumo_json']) {
            return ['resumo' => json_decode($v['resumo_json'], true), 'atualizado_em' => $v['resumo_em'], 'de_cache' => false];
        }
        try {
            $r = Conexao::api($accountId, 'GET', '/contas/' . rawurlencode($v['codigo_conta']));
            if ($r['status'] === 200) {
                self::guardarResumo($accountId, (int) $v['id'], $r['corpo']);
                return ['resumo' => $r['corpo'], 'atualizado_em' => gmdate('Y-m-d H:i:s'), 'de_cache' => false];
            }
            $erro = Conexao::mensagem($r, 'HTTP ' . $r['status']);
        } catch (\RuntimeException $e) {
            if ($e instanceof \PDOException) throw $e;
            $erro = $e->getMessage();
        }
        return [
            'resumo'        => $v['resumo_json'] ? json_decode($v['resumo_json'], true) : null,
            'atualizado_em' => $v['resumo_em'],
            'de_cache'      => true,
            'erro'          => $erro,
        ];
    }

    private static function guardarResumo(int $accountId, int $vinculoId, array $resumo): void
    {
        $conta = $resumo['conta'] ?? [];
        Database::getConnection()->prepare(
            'UPDATE plataforma_vinculos
                SET nome_conta = ?, cnpj = ?, plano = ?, situacao = ?, resumo_json = ?, resumo_em = UTC_TIMESTAMP()
              WHERE id = ? AND account_id = ?'
        )->execute([
            mb_substr((string) ($conta['nome'] ?? ''), 0, 191) ?: null,
            preg_replace('/\D/', '', (string) ($conta['cnpj'] ?? '')) ?: null,
            mb_substr((string) ($resumo['plano']['comercial']['nome'] ?? $resumo['plano']['cobranca']['nome'] ?? ''), 0, 120) ?: null,
            self::situacaoDe($resumo),
            json_encode($resumo, JSON_UNESCAPED_UNICODE),
            $vinculoId,
            $accountId,
        ]);
    }

    /** Uma palavra para a carteira: ativa, teste, inadimplente, suspensa, cancelada. */
    public static function situacaoDe(array $resumo): string
    {
        if (empty($resumo['conta']['ativa'])) return 'suspensa';
        $status = $resumo['assinatura']['status'] ?? null;
        return match ($status) {
            'TRIAL' => 'teste',
            'INADIMPLENTE' => 'inadimplente',
            'SUSPENSA' => 'suspensa',
            'CANCELADA' => 'cancelada',
            default => 'ativa',
        };
    }

    /** Força a próxima abertura a buscar de novo (um evento mudou algo). */
    public static function envelhecer(int $accountId, string $codigo): void
    {
        Database::getConnection()->prepare('UPDATE plataforma_vinculos SET resumo_em = NULL WHERE account_id = ? AND codigo_conta = ?')
            ->execute([$accountId, self::normalizarCodigo($codigo)]);
    }

    /**
     * Contas da plataforma que podem ser deste lead: pelo CNPJ do card e pelo
     * nome da empresa. Só sugestão; quem liga é o vendedor.
     */
    public static function sugestoes(int $accountId, array $card): array
    {
        $termos = [];
        $doc = preg_replace('/\D/', '', (string) ($card['cpf_cnpj'] ?? ''));
        if (strlen($doc) >= 11) $termos[] = $doc;
        $empresa = trim((string) ($card['empresa_nome'] ?? ''));
        if (mb_strlen($empresa) >= 3) $termos[] = $empresa;
        $achadas = [];
        foreach ($termos as $t) {
            $r = Conexao::api($accountId, 'GET', '/contas?busca=' . rawurlencode($t));
            foreach (($r['corpo']['itens'] ?? []) as $c) $achadas[$c['codigo']] = $c;
        }
        return array_values(array_slice($achadas, 0, 5));
    }

    /** Todas as contas ligadas, com o último resumo guardado. */
    public static function carteira(int $accountId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT v.*, c.cliente_nome, c.empresa_nome, c.responsavel_user_id, u.nome AS responsavel_nome
               FROM plataforma_vinculos v
               LEFT JOIN cards c ON c.id = v.card_id AND c.account_id = v.account_id AND c.deleted_at IS NULL
               LEFT JOIN users u ON u.id = c.responsavel_user_id
              WHERE v.account_id = ?
              ORDER BY v.nome_conta'
        );
        $st->execute([$accountId]);
        $linhas = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $v) {
            $r = $v['resumo_json'] ? json_decode($v['resumo_json'], true) : [];
            $abertos = 0;
            foreach (($r['solicitacoes'] ?? []) as $s) if (($s['status'] ?? '') === 'ABERTA') $abertos++;
            $linhas[] = [
                'codigo'           => $v['codigo_conta'],
                'nome'             => $v['nome_conta'] ?: ($v['empresa_nome'] ?: $v['cliente_nome']),
                'card_id'          => $v['card_id'] ? (int) $v['card_id'] : null,
                'lead'             => $v['cliente_nome'],
                'responsavel'      => $v['responsavel_nome'],
                'plano'            => $v['plano'],
                'situacao'         => $v['situacao'],
                'mensalidade'      => $r['plano']['cobranca']['valorMensal'] ?? null,
                'modulos_ativos'   => count(array_filter($r['modulos'] ?? [], fn($m) => !empty($m['ativo']))),
                'modulos_total'    => count($r['modulos'] ?? []),
                'saldo'            => $r['financeiro']['saldo'] ?? null,
                'gerado_mes'       => $r['financeiro']['geradoNoMes'] ?? null,
                'gerado_total'     => $r['financeiro']['geradoTotal'] ?? null,
                'consultas_total'  => $r['consultas']['total'] ?? null,
                'consultas_mes'    => $r['consultas']['noMes'] ?? null,
                'gasto_consultas'  => $r['consultas']['cobrado']['valor'] ?? null,
                'oportunidades'    => count($r['oportunidades'] ?? []),
                'pedidos_abertos'  => $abertos,
                'resumo_em'        => $v['resumo_em'],
            ];
        }
        return $linhas;
    }

    /** Nota no histórico do lead. Falha em silêncio: a ação principal já aconteceu. */
    public static function nota(int $accountId, array $card, string $assunto, string $conteudo, ?int $userId): void
    {
        try {
            Interacao::registrar(
                ['entidade' => 'card', 'id' => (int) $card['id'], 'account_id' => $accountId, 'titulo' => (string) ($card['cliente_nome'] ?? '')],
                ['tipo' => 'nota', 'assunto' => $assunto, 'conteudo' => $conteudo, 'ocorrido_em' => TaskEntrega::agoraLocal()],
                $userId ?: null
            );
        } catch (\Throwable $e) {
            error_log('[plataforma] nota no card ' . (int) $card['id'] . ': ' . $e->getMessage());
        }
    }
}
