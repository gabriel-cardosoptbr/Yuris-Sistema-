<?php

namespace App\Notificacoes;

use App\Core\Database;

/**
 * Aviso — o ÚNICO caminho por onde uma notificação entra no sistema.
 *
 * ---------------------------------------------------------------------------
 * POR QUE UM PONTO SÓ
 * ---------------------------------------------------------------------------
 * Antes disto, sete lugares diferentes montavam o INSERT na mão, cada um do seu
 * jeito. Funcionava porque eram sete. Agora que TODO movimento gera aviso, sem
 * um ponto único cada regra nova (não avisar a si mesmo, deduplicar, respeitar
 * preferência) teria de ser lembrada em dezenas de lugares, e bastaria esquecer
 * num para o sino virar lixo.
 *
 * ---------------------------------------------------------------------------
 * AS DUAS NATUREZAS
 * ---------------------------------------------------------------------------
 *   dirigido   É PRA VOCÊ: virou responsável, foi mencionado, seu prazo chegou.
 *              Conta no contador vermelho.
 *   movimento  Aconteceu no escritório. Fica no sino, mas NÃO estoura o
 *              contador. É acompanhamento, não cobrança.
 *
 * O pedido foi "tem que chegar absolutamente tudo". Num escritório ativo isso dá
 * entre 100 e 300 avisos por dia, e sino com 300 itens é sino ignorado. As duas
 * naturezas são o que permite cumprir o pedido sem destruir a utilidade dele.
 *
 * ---------------------------------------------------------------------------
 * AS QUATRO REGRAS QUE SEGURAM O RUÍDO
 * ---------------------------------------------------------------------------
 *  1. NINGUÉM É AVISADO DO PRÓPRIO ATO. É a regra que mais importa: sem ela,
 *     cada pessoa receberia um aviso a cada clique que ela mesma deu.
 *  2. DEDUPE POR JANELA. Mover um card três vezes em dois minutos é UM
 *     movimento, não três avisos.
 *  3. PREFERÊNCIA. Quem achar barulhento desliga a natureza `movimento`, e
 *     continua recebendo o que é dirigido a ele.
 *  4. FALHA EM SILÊNCIO. Notificação NUNCA derruba a operação que ela descreve.
 *     Mesmo princípio de Card::logEvento e Cliente::_logHistory: um card salvo
 *     com o aviso falhando é melhor que um card perdido porque o aviso falhou.
 *
 * ---------------------------------------------------------------------------
 * MULTI-TENANT
 * ---------------------------------------------------------------------------
 * Todo aviso nasce com `account_id`. O destinatário é conferido contra a conta
 * antes de gravar: mandar aviso para um usuário de outro escritório não é só
 * errado, é vazamento, porque o título costuma trazer o nome do cliente.
 */
final class Aviso
{
    public const DIRIGIDO  = 'dirigido';
    public const MOVIMENTO = 'movimento';

    /** As chaves de preferência. Ausência na tabela significa LIGADO. */
    public const PREFERENCIAS = [
        'movimento'   => 'Movimentações do escritório',
        'responsavel' => 'Quando eu viro responsável',
        'mencao'      => 'Quando me mencionam',
        'prazo'       => 'Prazos e tarefas vencendo',
    ];

    /**
     * Janela de dedupe PADRÃO, em minutos.
     *
     * Dois minutos porque é o tempo de alguém arrastar um card, perceber que
     * errou de coluna e arrastar de novo. Mais que isso começaria a engolir
     * movimento de verdade.
     *
     * Quem precisa de outra janela passa `janela_min`. O aviso diário de prazo
     * usa 1440: o mesmo prazo deve avisar uma vez POR DIA enquanto continuar
     * vencendo, e dois minutos deixariam o tick duplicar se rodasse de novo.
     */
    public const JANELA_DEDUPE_MIN = 2;

    /** Um dia, para quem avisa uma vez por dia. */
    public const JANELA_DIARIA_MIN = 1440;

    /* ===================================================================== */
    /* escrita                                                                */
    /* ===================================================================== */

    /**
     * Aviso DIRIGIDO a uma pessoa.
     *
     * @param array $dados titulo, mensagem, tipo, entidade, entidade_id, url,
     *                     origem_user_id, preferencia, chave_dedupe
     * @return int id criado, ou 0 quando não havia o que gravar
     */
    public static function paraUsuario(int $accountId, int $paraUserId, array $dados): int
    {
        return self::gravar($accountId, $paraUserId, self::DIRIGIDO, $dados);
    }

    /**
     * Aviso de MOVIMENTO, para a conta inteira (`user_id` nulo).
     *
     * `user_id` nulo é como a caixa já representa "aviso da conta" desde antes
     * desta classe. Reusar isso, em vez de criar uma linha por pessoa, evita
     * multiplicar o volume pelo número de usuários: um escritório de 10 pessoas
     * geraria 3.000 linhas por dia em vez de 300.
     */
    public static function paraConta(int $accountId, array $dados): int
    {
        return self::gravar($accountId, null, self::MOVIMENTO, $dados);
    }

    /**
     * "Você virou responsável por X."
     *
     * Avisa o NOVO responsável, e avisa o anterior que saiu. O segundo importa:
     * quem era responsável por um prazo precisa saber que não é mais, senão
     * continua contando com uma tarefa que já não é dele.
     */
    public static function responsavel(
        int $accountId,
        string $entidade,
        int $entidadeId,
        string $titulo,
        ?int $novoUserId,
        ?int $antigoUserId,
        ?int $origemUserId,
        ?string $url = null
    ): void {
        $rotulo = self::ROTULO_ENTIDADE[$entidade] ?? $entidade;

        if ($novoUserId && $novoUserId !== $antigoUserId) {
            self::paraUsuario($accountId, $novoUserId, [
                'tipo'           => 'responsavel.atribuido',
                'titulo'         => 'Você é o responsável: ' . $titulo,
                'mensagem'       => 'Passou a ser seu ' . mb_strtolower($rotulo) . '.',
                'entidade'       => $entidade,
                'entidade_id'    => $entidadeId,
                'origem_user_id' => $origemUserId,
                'url'            => $url,
                'preferencia'    => 'responsavel',
                // Reatribuir para a mesma pessoa duas vezes seguidas é um aviso.
                'chave_dedupe'   => "resp:$entidade:$entidadeId:$novoUserId",
            ]);
        }

        if ($antigoUserId && $antigoUserId !== $novoUserId) {
            self::paraUsuario($accountId, $antigoUserId, [
                'tipo'           => 'responsavel.removido',
                'titulo'         => 'Não é mais seu: ' . $titulo,
                'mensagem'       => 'A responsabilidade por este ' . mb_strtolower($rotulo) . ' passou para outra pessoa.',
                'entidade'       => $entidade,
                'entidade_id'    => $entidadeId,
                'origem_user_id' => $origemUserId,
                'url'            => $url,
                'preferencia'    => 'responsavel',
                'chave_dedupe'   => "resp-out:$entidade:$entidadeId:$antigoUserId",
            ]);
        }
    }

    /** "Fulano mencionou você." */
    public static function mencao(
        int $accountId,
        int $paraUserId,
        string $quem,
        string $onde,
        string $trecho,
        ?int $origemUserId,
        ?string $url = null
    ): int {
        return self::paraUsuario($accountId, $paraUserId, [
            'tipo'           => 'mencao',
            'titulo'         => $quem . ' mencionou você',
            'mensagem'       => $onde . ': ' . mb_substr(trim($trecho), 0, 160),
            'entidade'       => 'chat',
            'origem_user_id' => $origemUserId,
            'url'            => $url,
            'preferencia'    => 'mencao',
        ]);
    }

    /* ===================================================================== */
    /* leitura                                                                */
    /* ===================================================================== */

    /**
     * Quantos avisos DIRIGIDOS e não lidos esta pessoa tem.
     *
     * O contador conta só o dirigido, de propósito. Contar movimento faria o
     * badge marcar 200 todo dia, e um badge que sempre marca 200 não informa
     * nada: a pessoa para de olhar.
     */
    public static function contarDirigidos(int $userId, int $accountId): int
    {
        try {
            $st = Database::getConnection()->prepare(
                'SELECT COUNT(*) FROM account_notifications
                  WHERE account_id = :acc AND user_id = :uid
                    AND natureza = :nat AND lida = 0'
            );
            $st->execute(['acc' => $accountId, 'uid' => $userId, 'nat' => self::DIRIGIDO]);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Quantas movimentações da conta hoje, para a linha agrupada do sino. */
    public static function contarMovimentoHoje(int $accountId): int
    {
        try {
            $st = Database::getConnection()->prepare(
                'SELECT COUNT(*) FROM account_notifications
                  WHERE account_id = :acc AND natureza = :nat
                    AND created_at >= CURDATE()'
            );
            $st->execute(['acc' => $accountId, 'nat' => self::MOVIMENTO]);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /* ===================================================================== */
    /* preferências                                                           */
    /* ===================================================================== */

    /**
     * Esta pessoa quer receber esta categoria?
     *
     * Chave ausente = SIM. A tabela guarda o desvio do padrão, não o padrão:
     * assim a entrega não depende de ninguém marcar caixinha.
     */
    public static function querReceber(int $userId, string $chave): bool
    {
        if ($chave === '' || !isset(self::PREFERENCIAS[$chave])) {
            return true;
        }
        try {
            $st = Database::getConnection()->prepare(
                'SELECT ativo FROM notificacao_preferencias WHERE user_id = ? AND chave = ? LIMIT 1'
            );
            $st->execute([$userId, $chave]);
            $v = $st->fetchColumn();
            return $v === false ? true : ((int) $v === 1);
        } catch (\Throwable $e) {
            // Sem preferência legível, entrega. Perder um aviso é pior que um
            // aviso a mais.
            return true;
        }
    }

    /** @return array<string,bool> a foto completa, já com os padrões aplicados */
    public static function preferenciasDe(int $userId): array
    {
        $out = [];
        foreach (array_keys(self::PREFERENCIAS) as $c) {
            $out[$c] = true;
        }
        try {
            $st = Database::getConnection()->prepare(
                'SELECT chave, ativo FROM notificacao_preferencias WHERE user_id = ?'
            );
            $st->execute([$userId]);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                if (isset($out[$r['chave']])) {
                    $out[$r['chave']] = ((int) $r['ativo'] === 1);
                }
            }
        } catch (\Throwable $e) { /* devolve os padrões */ }
        return $out;
    }

    public static function salvarPreferencia(int $accountId, int $userId, string $chave, bool $ativo): bool
    {
        if (!isset(self::PREFERENCIAS[$chave]) || $userId <= 0 || $accountId <= 0) {
            return false;
        }
        try {
            Database::getConnection()->prepare(
                'INSERT INTO notificacao_preferencias (account_id, user_id, chave, ativo, created_at, updated_at)
                      VALUES (?, ?, ?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE ativo = VALUES(ativo), updated_at = NOW()'
            )->execute([$accountId, $userId, $chave, $ativo ? 1 : 0]);
            return true;
        } catch (\Throwable $e) {
            error_log('[Aviso] salvarPreferencia: ' . $e->getMessage());
            return false;
        }
    }

    /* ===================================================================== */
    /* internos                                                               */
    /* ===================================================================== */

    public const ROTULO_ENTIDADE = [
        'cliente'  => 'Cliente',
        'card'     => 'Prospecção',
        'processo' => 'Processo',
        'tarefa'   => 'Tarefa',
        'chat'     => 'Mensagem',
    ];

    /**
     * O INSERT, com as quatro regras aplicadas antes.
     *
     * Envolvido em try/catch inteiro: ver "FALHA EM SILÊNCIO" no cabeçalho.
     */
    private static function gravar(int $accountId, ?int $paraUserId, string $natureza, array $dados): int
    {
        try {
            $titulo = trim((string) ($dados['titulo'] ?? ''));
            if ($accountId <= 0 || $titulo === '') {
                return 0;
            }

            $origem = isset($dados['origem_user_id']) ? (int) $dados['origem_user_id'] : 0;

            // REGRA 1: ninguém é avisado do próprio ato.
            if ($paraUserId !== null && $origem > 0 && $origem === $paraUserId) {
                return 0;
            }

            // REGRA 3: preferência da pessoa. Movimento vai para a conta toda
            // (sem destinatário), então a preferência dele é filtrada na LEITURA,
            // em AccountNotification::listForUser.
            $pref = (string) ($dados['preferencia'] ?? '');
            if ($paraUserId !== null && $pref !== '' && !self::querReceber($paraUserId, $pref)) {
                return 0;
            }

            $pdo = Database::getConnection();

            // O destinatário precisa ser desta conta. Título de aviso costuma
            // trazer nome de cliente: entregar ao escritório errado é vazamento.
            if ($paraUserId !== null && !self::usuarioDaConta($pdo, $paraUserId, $accountId)) {
                return 0;
            }

            // REGRA 2: dedupe por janela.
            $chave  = trim((string) ($dados['chave_dedupe'] ?? ''));
            $janela = (int) ($dados['janela_min'] ?? self::JANELA_DEDUPE_MIN);
            if ($chave !== '' && self::jaAvisado($pdo, $accountId, $paraUserId, $chave, $janela)) {
                return 0;
            }

            $st = $pdo->prepare(
                'INSERT INTO account_notifications
                   (account_id, user_id, tipo, natureza, entidade, entidade_id, origem_user_id,
                    titulo, mensagem, url, payload, chave_dedupe, created_at)
                 VALUES
                   (:acc, :uid, :tipo, :nat, :ent, :entid, :orig,
                    :titulo, :msg, :url, :payload, :chave, NOW())'
            );
            $st->execute([
                'acc'     => $accountId,
                'uid'     => $paraUserId,
                'tipo'    => mb_substr((string) ($dados['tipo'] ?? 'aviso'), 0, 50),
                'nat'     => $natureza,
                'ent'     => $dados['entidade'] ?? null,
                'entid'   => isset($dados['entidade_id']) ? (int) $dados['entidade_id'] : null,
                'orig'    => $origem > 0 ? $origem : null,
                'titulo'  => mb_substr($titulo, 0, 255),
                'msg'     => isset($dados['mensagem']) ? mb_substr((string) $dados['mensagem'], 0, 2000) : null,
                'url'     => isset($dados['url']) ? mb_substr((string) $dados['url'], 0, 255) : null,
                'payload' => isset($dados['payload']) ? json_encode($dados['payload'], JSON_UNESCAPED_UNICODE) : null,
                'chave'   => $chave !== '' ? mb_substr($chave, 0, 120) : null,
            ]);

            return (int) $pdo->lastInsertId();
        } catch (\Throwable $e) {
            error_log('[Aviso] ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Este aviso já foi dado na janela?
     *
     * Público porque quem monta o aviso precisa perguntar ANTES de montar. O
     * caminho caro de um aviso de movimento é descobrir o título da entidade
     * (uma consulta por evento); perguntar aqui primeiro, com um índice, evita
     * essa consulta nos casos em que o aviso seria descartado de qualquer jeito.
     * Numa edição que muda cinco campos de um card, isso é a diferença entre
     * cinco consultas e uma.
     */
    public static function deduplicado(int $accountId, ?int $userId, string $chave, int $janelaMin = self::JANELA_DEDUPE_MIN): bool
    {
        if ($chave === '' || $accountId <= 0) {
            return false;
        }
        try {
            return self::jaAvisado(Database::getConnection(), $accountId, $userId, $chave, $janelaMin);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private static function usuarioDaConta(\PDO $pdo, int $userId, int $accountId): bool
    {
        $st = $pdo->prepare('SELECT 1 FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$userId, $accountId]);
        return (bool) $st->fetchColumn();
    }

    private static function jaAvisado(\PDO $pdo, int $accountId, ?int $userId, string $chave, int $janelaMin = self::JANELA_DEDUPE_MIN): bool
    {
        // Teto de 7 dias: janela maior que isso indica chave mal montada, e
        // engoliria aviso de verdade em silêncio.
        $janelaMin = max(1, min($janelaMin, 7 * 1440));
        $sql = 'SELECT 1 FROM account_notifications
                 WHERE account_id = ? AND chave_dedupe = ?
                   AND created_at >= DATE_SUB(NOW(), INTERVAL ' . $janelaMin . ' MINUTE)
                   AND ' . ($userId === null ? 'user_id IS NULL' : 'user_id = ?') . '
                 LIMIT 1';
        $params = [$accountId, $chave];
        if ($userId !== null) {
            $params[] = $userId;
        }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (bool) $st->fetchColumn();
    }
}
