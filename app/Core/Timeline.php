<?php

namespace App\Core;

/**
 * Timeline — a linha do tempo unica de uma pessoa dentro do sistema.
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA QUE ELA RESOLVE
 * ---------------------------------------------------------------------------
 * O historico do Yuris mora em tabelas separadas, com formatos diferentes:
 *
 *   card_history      campo_alterado / valor_anterior / valor_novo  (colunas)
 *   clientes_history  antes_json / depois_json                      (JSON)
 *
 * Quando uma prospeccao vira cliente, a pessoa e a mesma, mas o rastro dela
 * fica dos dois lados. Se a tela do cliente lesse so `clientes_history`, a
 * timeline comecaria do zero no dia da conversao, e tudo que aconteceu enquanto
 * ela era prospeccao sumiria da vista.
 *
 * ---------------------------------------------------------------------------
 * A ESCOLHA: LER JUNTO, NAO COPIAR
 * ---------------------------------------------------------------------------
 * Na conversao NAO se copia uma linha de historico sequer. O vinculo
 * `cards.cliente_id` e o que costura os dois lados, e a timeline do cliente
 * consulta:
 *
 *     clientes_history do cliente
 *   + card_history de TODAS as prospeccoes que apontam para ele
 *
 * Copiar centenas de linhas na conversao criaria duas verdades para o mesmo
 * evento, e a segunda envelheceria sozinha. Ler junto tem outra vantagem: uma
 * pessoa que volta como prospeccao nova, vinculada ao MESMO cliente, entra na
 * timeline dele automaticamente, sem migracao de dado nenhuma.
 *
 * ---------------------------------------------------------------------------
 * MULTI-TENANT
 * ---------------------------------------------------------------------------
 * `clientes_history` tem account_id proprio. `card_history` NAO tem: o dono e o
 * card. Por isso toda leitura de card_history aqui passa por JOIN em `cards`
 * filtrando account_id. Nunca consulte card_history sem esse JOIN.
 */
final class Timeline
{
    /**
     * Categorias usadas pelos filtros da UI.
     *
     * 'documentos' e 'interacoes' ficaram vazias da Fase 1 ate a Fase 2: a
     * primeira ja estava declarada esperando os anexos, a segunda entrou junto
     * com App\Crm\Interacao.
     */
    public const CATEGORIAS = ['cadastro', 'comercial', 'interacoes', 'processos', 'whatsapp', 'documentos', 'tarefas', 'sistema'];

    /**
     * Timeline de uma prospeccao. So os eventos dela.
     *
     * @param int[] $accountIds contas que a sessao alcanca
     */
    public static function paraCard(int $cardId, array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || $cardId <= 0) {
            return [];
        }
        $eventos = self::eventosDeCards([$cardId], $accountIds);
        $eventos = array_merge($eventos, self::eventosDeInteracoes(['card'], [$cardId], $accountIds));
        return self::ordena($eventos);
    }

    /**
     * Timeline de um cliente: os eventos dele MAIS os de toda prospeccao que
     * aponta para ele. E isto que faz o historico nao recomecar na conversao.
     *
     * @param int[] $accountIds contas que a sessao alcanca
     */
    public static function paraCliente(int $clienteId, array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || $clienteId <= 0) {
            return [];
        }

        $eventos = self::eventosDeCliente($clienteId, $accountIds);

        $cardIds = self::cardsDoCliente($clienteId, $accountIds);
        if ($cardIds !== []) {
            $eventos = array_merge($eventos, self::eventosDeCards($cardIds, $accountIds));
        }

        /*
         * Interacoes do cliente MAIS as das prospeccoes de origem, no mesmo
         * escopo que o historico. E o que faz a ligacao feita quando a pessoa
         * ainda era lead continuar na timeline depois da conversao.
         */
        $entidades = ['cliente'];
        $ids       = [$clienteId];
        foreach ($cardIds as $cardId) {
            $entidades[] = 'card';
            $ids[]       = (int) $cardId;
        }
        $eventos = array_merge($eventos, self::eventosDeInteracoes($entidades, $ids, $accountIds));

        return self::ordena($eventos);
    }

    /**
     * Timeline de um PROCESSO.
     *
     * Entrou depois das outras duas, junto com o modulo de relatorios, porque o
     * processo era a unica entidade grande do sistema sem linha do tempo
     * unificada: a tela dele lia `processo_history` direto, com LIMIT 50, e
     * prazo e tarefa nao apareciam em lugar nenhum do rastro.
     *
     * Tres fontes viram um so rastro:
     *
     *   processo_history   o que alguem fez no processo (JOIN em `processos`
     *                      porque a tabela NAO tem account_id, mesmo problema
     *                      de card_history)
     *   processo_prazos    cada prazo cadastrado e um fato datado
     *   processo_tarefas   idem para tarefa do processo
     *
     * Prazo e tarefa entram pelo `created_at`, que e quando o fato foi
     * registrado. A data-limite do prazo vai no texto do evento, e nao no
     * `quando`: colocar o vencimento futuro na linha do tempo jogaria o evento
     * para o topo antes de ele existir.
     *
     * @param int[] $accountIds contas que a sessao alcanca
     */
    public static function paraProcesso(int $processoId, array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || $processoId <= 0) {
            return [];
        }
        $eventos = self::eventosDeProcesso($processoId, $accountIds);
        return self::ordena($eventos);
    }

    /**
     * Ids das prospeccoes que apontam para este cliente, dentro das contas
     * acessiveis. E a consulta que costura a timeline.
     *
     * @return int[]
     */
    public static function cardsDoCliente(int $clienteId, array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || $clienteId <= 0) {
            return [];
        }
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $st  = $pdo->prepare(
            "SELECT id FROM cards
              WHERE cliente_id = ?
                AND account_id IN ($in)"
        );
        $st->execute(array_merge([$clienteId], $accountIds));
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    /* ===================================================================== */
    /* leitores por tabela                                                    */
    /* ===================================================================== */

    /**
     * card_history das prospeccoes dadas. O JOIN em `cards` NAO e conveniencia:
     * card_history nao tem account_id, e sem ele a consulta atravessaria contas.
     */
    private static function eventosDeCards(array $cardIds, array $accountIds): array
    {
        $cardIds = self::inteiros($cardIds);
        if ($cardIds === []) {
            return [];
        }
        $pdo    = Database::getConnection();
        $inCard = implode(',', array_fill(0, count($cardIds), '?'));
        $inAcc  = implode(',', array_fill(0, count($accountIds), '?'));

        $st = $pdo->prepare(
            "SELECT ch.id, ch.card_id, ch.created_at, ch.acao, ch.campo_alterado,
                    ch.valor_anterior, ch.valor_novo, ch.de_coluna_id, ch.para_coluna_id,
                    u.nome AS user_nome, u.login AS user_login,
                    cde.nome AS coluna_de, cpara.nome AS coluna_para
               FROM card_history ch
               JOIN cards c            ON c.id  = ch.card_id
          LEFT JOIN users u            ON u.id  = ch.usuario_id
          LEFT JOIN pipeline_columns cde   ON cde.id   = ch.de_coluna_id
          LEFT JOIN pipeline_columns cpara ON cpara.id = ch.para_coluna_id
              WHERE ch.card_id IN ($inCard)
                AND c.account_id IN ($inAcc)"
        );
        $st->execute(array_merge($cardIds, $accountIds));

        $saida = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $de   = $r['valor_anterior'];
            $para = $r['valor_novo'];
            // 'moved' guarda a coluna nos campos proprios, nao em valor_*.
            if ($r['acao'] === 'moved') {
                $de   = $r['coluna_de']   ?? $de;
                $para = $r['coluna_para'] ?? $para;
            }
            $saida[] = self::evento(
                (string) $r['created_at'],
                $r['user_nome'] ?: ($r['user_login'] ?: null),
                (string) ($r['acao'] ?? ''),
                $r['campo_alterado'] ?: null,
                $de,
                $para,
                'card',
                (int) $r['card_id'],
                'prospeccao'
            );
        }
        return $saida;
    }

    /**
     * O rastro do processo: historico, prazos e tarefas.
     *
     * O JOIN em `processos` NAO e conveniencia. Nenhuma das tres tabelas tem
     * account_id proprio: quem tem a conta e o processo. Consultar
     * processo_history sem esse JOIN atravessaria contas, exatamente como em
     * card_history.
     *
     * SEM LIMIT, de proposito. A tela lia com LIMIT 50 porque so precisava
     * mostrar o comeco; um relatorio que promete "historico completo" nao pode
     * cortar em 50 e nao dizer nada.
     */
    private static function eventosDeProcesso(int $processoId, array $accountIds): array
    {
        $pdo   = Database::getConnection();
        $inAcc = implode(',', array_fill(0, count($accountIds), '?'));
        $saida = [];

        /*
         * `processo_history` nao guarda usuario_id, guarda `user_email` como
         * texto, e `author_account_nome` quando o autor era de outra conta
         * (matriz agindo na filial). Preferimos o nome quando existe: um
         * relatorio impresso com e-mail cru fica pior de ler.
         */
        $st = $pdo->prepare(
            "SELECT h.id, h.created_at, h.acao, h.descricao, h.user_email,
                    h.author_account_nome
               FROM processo_history h
               JOIN processos p ON p.id = h.processo_id
              WHERE h.processo_id = ?
                AND p.account_id IN ($inAcc)"
        );
        $st->execute(array_merge([$processoId], $accountIds));
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $autor = trim((string) ($r['user_email'] ?? ''));
            if ($autor === '' || $autor === 'sistema') {
                $autor = trim((string) ($r['author_account_nome'] ?? ''));
            }
            $saida[] = self::evento(
                (string) $r['created_at'],
                $autor !== '' ? $autor : null,
                (string) ($r['acao'] ?? ''),
                null,
                null,
                $r['descricao'] ?: null,
                'processo',
                $processoId,
                'processo'
            );
        }

        // Prazos. `data_limite` vai no TEXTO, nao no `quando`: ver o cabecalho
        // de paraProcesso.
        $st = $pdo->prepare(
            "SELECT z.id, z.created_at, z.descricao, z.data_limite, z.status, z.responsavel
               FROM processo_prazos z
               JOIN processos p ON p.id = z.processo_id
              WHERE z.processo_id = ?
                AND p.account_id IN ($inAcc)"
        );
        $st->execute(array_merge([$processoId], $accountIds));
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $texto = trim((string) ($r['descricao'] ?? ''));
            if (!empty($r['data_limite'])) {
                $texto .= ' (limite ' . self::dataBr((string) $r['data_limite']) . ')';
            }
            $saida[] = self::evento(
                (string) $r['created_at'],
                $r['responsavel'] ?: null,
                'prazo_processo',
                'Prazo',
                null,
                trim($texto) !== '' ? trim($texto) : null,
                'processo',
                $processoId,
                'processo'
            );
        }

        // Tarefas do processo. Sao as da aba do processo, tabela propria, NAO as
        // de `tasks`: aquelas ligam por task_links e pertencem ao quadro.
        $st = $pdo->prepare(
            "SELECT t.id, t.created_at, t.titulo, t.concluido, t.responsavel, t.data_tarefa
               FROM processo_tarefas t
               JOIN processos p ON p.id = t.processo_id
              WHERE t.processo_id = ?
                AND p.account_id IN ($inAcc)"
        );
        $st->execute(array_merge([$processoId], $accountIds));
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $texto = trim((string) ($r['titulo'] ?? ''));
            if (!empty($r['concluido'])) {
                $texto .= ' (concluída)';
            }
            $saida[] = self::evento(
                (string) $r['created_at'],
                $r['responsavel'] ?: null,
                'tarefa_registrada',
                'Tarefa',
                null,
                $texto !== '' ? $texto : null,
                'processo',
                $processoId,
                'processo'
            );
        }

        return $saida;
    }

    /** dd/mm/aaaa a partir de um DATE/DATETIME do banco. Devolve o cru se nao souber ler. */
    private static function dataBr(string $iso): string
    {
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', substr($iso, 0, 10));
        return $d ? $d->format('d/m/Y') : $iso;
    }

    /** clientes_history do cliente. Tem account_id proprio, entao filtra direto. */
    private static function eventosDeCliente(int $clienteId, array $accountIds): array
    {
        $pdo   = Database::getConnection();
        $inAcc = implode(',', array_fill(0, count($accountIds), '?'));

        $st = $pdo->prepare(
            "SELECT ch.id, ch.cliente_id, ch.created_at, ch.acao,
                    ch.antes_json, ch.depois_json,
                    u.nome AS user_nome, u.login AS user_login
               FROM clientes_history ch
          LEFT JOIN users u ON u.id = ch.user_id
              WHERE ch.cliente_id = ?
                AND ch.account_id IN ($inAcc)"
        );
        $st->execute(array_merge([$clienteId], $accountIds));

        $saida = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $antes  = self::json($r['antes_json']);
            $depois = self::json($r['depois_json']);

            // 'updated' guarda SO os campos que mudaram nos dois lados. Vira um
            // evento por campo, para a timeline dizer o que mudou, e nao apenas
            // "registro atualizado".
            if (($r['acao'] ?? '') === 'updated' && is_array($depois) && $depois !== []) {
                foreach ($depois as $campo => $novo) {
                    if (!is_scalar($novo) && $novo !== null) {
                        continue;
                    }
                    $saida[] = self::evento(
                        (string) $r['created_at'],
                        $r['user_nome'] ?: ($r['user_login'] ?: null),
                        'updated',
                        (string) $campo,
                        is_array($antes) ? ($antes[$campo] ?? null) : null,
                        $novo,
                        'cliente',
                        (int) $r['cliente_id'],
                        'cliente'
                    );
                }
                continue;
            }

            /*
             * Evento que NAO e 'updated' pode carregar campo/de/para no JSON.
             * E o formato que App\Crm\Auditoria grava para os eventos da Fase 2
             * (anexo, tag, campo personalizado, interacao editada). Sem ler estas
             * tres chaves, a timeline do cliente mostraria "tag aplicada" sem
             * dizer qual tag, enquanto o mesmo evento no lado da prospeccao
             * mostraria o nome, porque card_history tem colunas proprias.
             */
            $saida[] = self::evento(
                (string) $r['created_at'],
                $r['user_nome'] ?: ($r['user_login'] ?: null),
                (string) ($r['acao'] ?? ''),
                is_array($depois) && isset($depois['campo']) ? (string) $depois['campo'] : null,
                is_array($depois) ? ($depois['de']   ?? null) : null,
                is_array($depois) ? ($depois['para'] ?? null) : null,
                'cliente',
                (int) $r['cliente_id'],
                'cliente'
            );
        }
        return $saida;
    }

    /**
     * Interacoes e notas internas como eventos da timeline.
     *
     * Le `crm_interacoes` DIRETO, e nao o historico, por dois motivos:
     *
     *  1. A interacao tem `ocorrido_em` proprio, que pode ser bem antes de
     *     `created_at`. A ligacao de ontem registrada hoje tem de aparecer no
     *     lugar de ontem, e nenhuma tabela de auditoria sabe representar isso:
     *     ela so tem a hora em que a linha foi escrita.
     *  2. Registrar interacao NAO grava evento de auditoria (ver o cabecalho de
     *     App\Crm\Interacao). Se gravasse, o mesmo fato apareceria duas vezes na
     *     mesma tela, uma vindo daqui e outra do historico.
     *
     * O tenant sai direto de `crm_interacoes.account_id`, que existe e e NOT NULL,
     * diferente de card_history.
     *
     * @param array<int,string> $entidades alinhado com $ids
     * @param array<int,int>    $ids
     */
    private static function eventosDeInteracoes(array $entidades, array $ids, array $accountIds): array
    {
        if ($entidades === [] || $accountIds === []) {
            return [];
        }

        $partes = [];
        $params = [];
        foreach ($entidades as $i => $ent) {
            $partes[] = '(i.entidade = ? AND i.entidade_id = ?)';
            $params[] = $ent;
            $params[] = (int) $ids[$i];
        }
        $inAcc = implode(',', array_fill(0, count($accountIds), '?'));

        $st = Database::getConnection()->prepare(
            'SELECT i.id, i.entidade, i.entidade_id, i.tipo, i.direcao, i.assunto,
                    i.conteudo, i.ocorrido_em, i.duracao_min,
                    u.nome AS user_nome, u.login AS user_login
               FROM crm_interacoes i
          LEFT JOIN users u ON u.id = i.created_by
              WHERE (' . implode(' OR ', $partes) . ')
                AND i.deleted_at IS NULL
                AND i.account_id IN (' . $inAcc . ')'
        );
        $st->execute(array_merge($params, $accountIds));

        $saida = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $tipo   = (string) $r['tipo'];
            $rotulo = \App\Crm\Interacao::ROTULOS[$tipo] ?? $tipo;

            // 'para' carrega o texto legivel do evento: assunto, ou o comeco do
            // conteudo quando nao ha assunto. A UI ja sabe renderizar 'para'.
            $texto = trim((string) ($r['assunto'] ?? ''));
            if ($texto === '') {
                $texto = mb_substr(trim((string) ($r['conteudo'] ?? '')), 0, 140);
            }
            if ($r['duracao_min'] !== null && (int) $r['duracao_min'] > 0) {
                $texto .= ' (' . (int) $r['duracao_min'] . ' min)';
            }

            $saida[] = self::evento(
                (string) $r['ocorrido_em'],
                $r['user_nome'] ?: ($r['user_login'] ?: null),
                $tipo === 'nota' ? 'nota_interna' : 'interacao_registrada',
                $rotulo,
                null,
                $texto !== '' ? $texto : null,
                (string) $r['entidade'],
                (int) $r['entidade_id'],
                $r['entidade'] === 'card' ? 'prospeccao' : 'cliente'
            );
        }
        return $saida;
    }

    /* ===================================================================== */
    /* normalizacao                                                           */
    /* ===================================================================== */

    private static function evento(
        string $quando,
        ?string $usuario,
        string $acao,
        ?string $campo,
        $de,
        $para,
        string $origemTipo,
        int $origemId,
        string $fase
    ): array {
        return [
            'quando'    => $quando,
            'usuario'   => $usuario,          // null = acao do sistema
            'acao'      => $acao,             // crua: o front traduz com Yuris.translateAuditAcao
            'categoria' => self::categoria($acao, $campo),
            'campo'     => $campo,
            'de'        => self::texto($de),
            'para'      => self::texto($para),
            'origem'    => ['tipo' => $origemTipo, 'id' => $origemId],
            'fase'      => $fase,             // 'prospeccao' ou 'cliente': a UI marca de onde veio
        ];
    }

    /** Categoria do evento, usada pelos filtros da timeline. */
    private static function categoria(string $acao, ?string $campo): string
    {
        $a = strtolower($acao);
        // Antes de 'whatsapp': uma interacao do tipo WhatsApp e interacao
        // registrada a mao, nao mensagem trocada no chat. Sao filtros diferentes.
        if (str_contains($a, 'interacao') || str_contains($a, 'nota_interna')) {
            return 'interacoes';
        }
        if (str_contains($a, 'whatsapp') || str_contains($a, 'chat') || str_contains($a, 'conversa')) {
            return 'whatsapp';
        }
        if (str_contains($a, 'processo')) {
            return 'processos';
        }
        if (str_contains($a, 'document') || str_contains($a, 'anexo') || str_contains($a, 'arquivo')) {
            return 'documentos';
        }
        if (str_contains($a, 'task') || str_contains($a, 'tarefa') || str_contains($a, 'checklist')) {
            return 'tarefas';
        }
        if (in_array($a, ['moved', 'reorder', 'reordered', 'stage_changed', 'status_changed', 'convertido_cliente', 'vinculado_cliente'], true)) {
            return 'comercial';
        }
        if (in_array($a, ['created', 'updated', 'archived', 'restored', 'deleted', 'merged'], true) || $campo !== null) {
            return 'cadastro';
        }
        return 'sistema';
    }

    /** Valor legivel. JSON de reorder vira texto curto em vez de chaves cruas. */
    private static function texto($v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_scalar($v)) {
            $s = (string) $v;
            $decodificado = json_decode($s, true);
            if (is_array($decodificado)) {
                $partes = [];
                foreach ($decodificado as $k => $x) {
                    if (is_scalar($x) || $x === null) {
                        $partes[] = $k . ': ' . ($x ?? '');
                    }
                }
                return $partes ? implode(', ', $partes) : null;
            }
            return $s;
        }
        return null;
    }

    private static function json($v): ?array
    {
        if (!is_string($v) || $v === '') {
            return null;
        }
        $d = json_decode($v, true);
        return is_array($d) ? $d : null;
    }

    /** Mais recente primeiro, com desempate estavel. */
    private static function ordena(array $eventos): array
    {
        usort($eventos, static function (array $a, array $b): int {
            $cmp = strcmp((string) $b['quando'], (string) $a['quando']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp((string) ($a['campo'] ?? ''), (string) ($b['campo'] ?? ''));
        });
        return $eventos;
    }

    /** @return int[] */
    private static function inteiros(array $v): array
    {
        $out = [];
        foreach ($v as $x) {
            $i = (int) $x;
            if ($i > 0) {
                $out[$i] = $i;
            }
        }
        return array_values($out);
    }
}
