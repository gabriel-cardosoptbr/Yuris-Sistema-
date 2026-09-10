<?php

namespace App\Relatorios;

use App\Clientes\VinculosCliente;
use App\Core\Database;
use App\Core\Timeline;
use App\Crm\Anexo;
use App\Crm\CampoPersonalizado;
use App\Crm\Interacao;
use App\Crm\Tag;

/**
 * Dossie — tudo o que o sistema sabe sobre UMA entidade, num documento so.
 *
 * ---------------------------------------------------------------------------
 * O PEDIDO
 * ---------------------------------------------------------------------------
 * "Dentro do card do cliente, dentro do card da prospeccao e dentro do processo
 * tem que ter uma opcao de baixar o relatorio por completo, de todo o historico
 * de tudo."
 *
 * Ate aqui o sistema nao gerava documento nenhum: nem PDF, nem planilha, nem
 * impressao. O que existia era grafico na tela e um botao que tirava foto PNG
 * do dashboard.
 *
 * ---------------------------------------------------------------------------
 * A ESCOLHA: UM FORMATO SO, TRES ENTIDADES
 * ---------------------------------------------------------------------------
 * Cliente, prospeccao e processo tem naturezas diferentes, mas o documento que
 * a advogada leva para uma reuniao e o mesmo tipo de coisa: identificacao,
 * blocos de conteudo, e a linha do tempo inteira no fim.
 *
 * Por isso `montar()` sempre devolve a MESMA estrutura, e quem renderiza (a
 * tela e o CSV) nao sabe qual entidade e:
 *
 *   ['entidade', 'id', 'titulo', 'subtitulo', 'gerado_em', 'identificacao',
 *    'blocos' => [ ['chave','titulo','tipo','...'] ], 'resumo']
 *
 * Os tipos de bloco sao quatro: `pares` (rotulo/valor), `tabela`
 * (colunas/linhas), `chips` (etiquetas) e `timeline`. Acrescentar um bloco novo
 * a qualquer entidade nao exige tocar na tela.
 *
 * ---------------------------------------------------------------------------
 * NAO INVENTA CONSULTA
 * ---------------------------------------------------------------------------
 * O dado consolidado ja existia e estava maduro. Este arquivo REUSA:
 *
 *   App\Core\Timeline            historico unificado (o cliente ja lia junto o
 *                                rastro das prospeccoes que viraram ele)
 *   App\Clientes\VinculosCliente conversas de WhatsApp e tarefas do cliente
 *   App\Crm\Anexo/Tag/CampoPersonalizado/Interacao   os quatro blocos da Fase 2
 *
 * Consulta nova aqui existe so onde nao havia nada: processo (prazos, tarefas,
 * dados do cabecalho), checklist do card e os vinculos do card.
 *
 * ---------------------------------------------------------------------------
 * MULTI-TENANT
 * ---------------------------------------------------------------------------
 * `montar()` NAO confia no id que recebe. Ela resolve a entidade dentro das
 * contas acessiveis e devolve `null` quando o registro nao existe, esta
 * apagado, ou nao e daquela conta. Os tres casos dao a MESMA resposta de
 * proposito: distinguir "nao existe" de "nao e seu" ja seria vazamento.
 *
 * Toda consulta daqui filtra por `account_id`, e as tabelas sem coluna propria
 * (processo_history, processo_prazos, processo_tarefas, card_checklist_items)
 * passam por JOIN na tabela dona.
 */
final class Dossie
{
    public const ENTIDADES = ['cliente', 'card', 'processo'];

    /** Modulo de permissao de cada entidade, para o chamador pedir o escopo certo. */
    public const MODULOS = [
        'cliente'  => 'clientes',
        'card'     => 'prospeccao',
        'processo' => 'processos',
    ];

    /**
     * Monta o dossie completo.
     *
     * @param  string $entidade   cliente | card | processo
     * @param  int[]  $accountIds contas que a sessao alcanca, JA no modulo certo
     * @return array|null null quando nao existe, esta apagado, ou nao e acessivel
     */
    public static function montar(string $entidade, int $id, array $accountIds): ?array
    {
        $entidade   = strtolower(trim($entidade));
        $accountIds = self::inteiros($accountIds);

        if ($id <= 0 || $accountIds === [] || !in_array($entidade, self::ENTIDADES, true)) {
            return null;
        }

        return match ($entidade) {
            'cliente'  => self::doCliente($id, $accountIds),
            'card'     => self::doCard($id, $accountIds),
            'processo' => self::doProcesso($id, $accountIds),
        };
    }

    /* ===================================================================== */
    /* cliente                                                                */
    /* ===================================================================== */

    private static function doCliente(int $id, array $accountIds): ?array
    {
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));

        $st = $pdo->prepare(
            "SELECT c.*,
                    s.nome AS setor_nome,
                    o.nome AS origem_nome,
                    r.nome AS responsavel_nome,
                    a.nome AS conta_nome
               FROM clientes c
          LEFT JOIN clientes_setores  s ON s.id = c.setor_id
          LEFT JOIN clientes_origens  o ON o.id = c.origem
          LEFT JOIN users             r ON r.id = c.responsavel_id
          LEFT JOIN accounts          a ON a.id = c.account_id
              WHERE c.id = ? AND c.deleted_at IS NULL AND c.account_id IN ($in)
              LIMIT 1"
        );
        $st->execute(array_merge([$id], $accountIds));
        $c = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$c) {
            return null;
        }

        /*
         * `clientes.origem` guarda ora o id do catalogo, ora o texto livre de
         * antes do catalogo existir. O LEFT JOIN resolve o primeiro caso; o
         * segundo cai no proprio valor. Sem esse fallback, cliente antigo
         * apareceria com origem em branco no relatorio.
         */
        $origem = $c['origem_nome'] ?: (is_numeric($c['origem'] ?? null) ? null : ($c['origem'] ?: null));

        $blocos = [];

        $blocos[] = self::blocoPares('endereco', 'Endereço', self::endereco($c));
        $blocos[] = self::blocoChips('etiquetas', 'Etiquetas', Tag::daEntidade('cliente', $id, $accountIds));
        $blocos[] = self::blocoCampos(CampoPersonalizado::valores('cliente', $id, $accountIds));

        /*
         * Documentos e interacoes usam o ESCOPO DE LEITURA: o cliente mais toda
         * prospeccao que aponta para ele. E o que faz o contrato anexado
         * enquanto a pessoa era lead continuar no dossie depois da conversao,
         * sem que a conversao tenha copiado arquivo nenhum.
         */
        $blocos[] = self::blocoDocumentos(Anexo::listar('cliente', $id, $accountIds));
        $blocos[] = self::blocoInteracoes(Interacao::listar('cliente', $id, $accountIds, 5000));

        $cards = Timeline::cardsDoCliente($id, $accountIds);
        $blocos[] = self::blocoProspeccoesDeOrigem($cards, $accountIds);
        $blocos[] = self::blocoProcessos(self::processosDe('cliente_id', $id, $accountIds));
        $blocos[] = self::blocoTarefas(VinculosCliente::tarefas($id, $accountIds));
        $blocos[] = self::blocoConversas(VinculosCliente::conversas($id, $accountIds));
        $blocos[] = self::blocoTimeline(Timeline::paraCliente($id, $accountIds));

        return self::documento(
            'cliente',
            $id,
            (string) $c['nome'],
            'Ficha completa do cliente',
            (string) ($c['conta_nome'] ?? ''),
            [
                ['rotulo' => 'Nome',            'valor' => $c['nome']],
                ['rotulo' => 'Tipo',            'valor' => self::rotuloTipoCliente($c['tipo_cliente'] ?? null)],
                ['rotulo' => 'CPF / CNPJ',      'valor' => $c['cpf_cnpj']],
                ['rotulo' => 'RG',              'valor' => $c['rg']],
                ['rotulo' => 'Nome da mãe',     'valor' => $c['nome_mae']],
                ['rotulo' => 'Nascimento',      'valor' => self::data($c['data_nascimento'] ?? null)],
                ['rotulo' => 'Telefone',        'valor' => $c['telefone']],
                ['rotulo' => 'WhatsApp',        'valor' => $c['whatsapp']],
                ['rotulo' => 'E-mail',          'valor' => $c['email']],
                ['rotulo' => 'Situação',        'valor' => $c['status']],
                ['rotulo' => 'Setor',           'valor' => $c['setor_nome']],
                ['rotulo' => 'Responsável',     'valor' => $c['responsavel_nome']],
                ['rotulo' => 'Canal de aquisição', 'valor' => $origem],
                ['rotulo' => 'Cadastrado em',   'valor' => self::dataHora($c['created_at'] ?? null)],
                ['rotulo' => 'Virou cliente em', 'valor' => self::dataHora($c['convertido_em'] ?? null)],
                ['rotulo' => 'Observações',     'valor' => $c['observacoes']],
            ],
            $blocos
        );
    }

    /* ===================================================================== */
    /* prospeccao (card)                                                      */
    /* ===================================================================== */

    private static function doCard(int $id, array $accountIds): ?array
    {
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));

        $st = $pdo->prepare(
            "SELECT k.*,
                    col.nome AS coluna_nome,
                    o.nome   AS origem_nome,
                    r.nome   AS responsavel_nome,
                    cli.nome AS cliente_convertido_nome,
                    a.nome   AS conta_nome
               FROM cards k
          LEFT JOIN pipeline_columns  col ON col.id = k.coluna_id
          LEFT JOIN clientes_origens  o   ON o.id   = k.origem_id
          LEFT JOIN users             r   ON r.id   = k.responsavel_user_id
          LEFT JOIN clientes          cli ON cli.id = k.cliente_id
          LEFT JOIN accounts          a   ON a.id   = k.account_id
              WHERE k.id = ? AND k.deleted_at IS NULL AND k.account_id IN ($in)
              LIMIT 1"
        );
        $st->execute(array_merge([$id], $accountIds));
        $k = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$k) {
            return null;
        }

        $blocos = [];
        $blocos[] = self::blocoPares('valores', 'Valores e prazos', [
            ['rotulo' => 'Valor estimado',        'valor' => self::dinheiro($k['valor_estimado'] ?? null)],
            ['rotulo' => 'Valor da proposta',     'valor' => self::dinheiro($k['valor_proposta'] ?? null)],
            ['rotulo' => 'Valor fechado',         'valor' => self::dinheiro($k['valor_fechado_final'] ?? null)],
            ['rotulo' => 'Previsão de fechamento','valor' => self::data($k['data_prevista_fechamento'] ?? null)],
            ['rotulo' => 'Fechado em',            'valor' => self::data($k['data_fechamento'] ?? null)],
        ]);
        $blocos[] = self::blocoPares('endereco', 'Endereço', self::endereco($k));
        $blocos[] = self::blocoChips('etiquetas', 'Etiquetas', Tag::daEntidade('card', $id, $accountIds));
        $blocos[] = self::blocoCampos(CampoPersonalizado::valores('card', $id, $accountIds));
        $blocos[] = self::blocoChecklist(self::checklistDoCard($id, $accountIds));
        $blocos[] = self::blocoDocumentos(Anexo::listar('card', $id, $accountIds));
        $blocos[] = self::blocoInteracoes(Interacao::listar('card', $id, $accountIds, 5000));
        $blocos[] = self::blocoProcessos(self::processosDe('card_id', $id, $accountIds));
        $blocos[] = self::blocoTarefas(self::tarefasDoCard($id, $accountIds));
        $blocos[] = self::blocoConversas(self::conversasDoCard($id, $accountIds));
        $blocos[] = self::blocoTimeline(Timeline::paraCard($id, $accountIds));

        $titulo = trim((string) ($k['cliente_nome'] ?? '')) ?: trim((string) ($k['titulo'] ?? '')) ?: ('Prospecção ' . $id);

        return self::documento(
            'card',
            $id,
            $titulo,
            'Ficha completa da prospecção',
            (string) ($k['conta_nome'] ?? ''),
            [
                ['rotulo' => 'Nome',           'valor' => $k['cliente_nome']],
                ['rotulo' => 'Empresa',        'valor' => $k['empresa_nome']],
                ['rotulo' => 'CPF / CNPJ',     'valor' => $k['cpf_cnpj']],
                ['rotulo' => 'RG',             'valor' => $k['rg']],
                ['rotulo' => 'Nome da mãe',    'valor' => $k['nome_mae']],
                ['rotulo' => 'Nascimento',     'valor' => self::data($k['data_nascimento'] ?? null)],
                ['rotulo' => 'Telefone / WhatsApp', 'valor' => $k['telefone_whatsapp']],
                ['rotulo' => 'E-mail',         'valor' => $k['email']],
                ['rotulo' => 'Etapa do funil', 'valor' => $k['coluna_nome']],
                ['rotulo' => 'Situação',       'valor' => $k['status']],
                ['rotulo' => 'Responsável',    'valor' => $k['responsavel_nome']],
                ['rotulo' => 'Canal de aquisição', 'valor' => $k['origem_nome']],
                ['rotulo' => 'Criada em',      'valor' => self::dataHora($k['created_at'] ?? null)],
                ['rotulo' => 'Virou cliente',  'valor' => $k['cliente_convertido_nome']
                    ? $k['cliente_convertido_nome'] . ' em ' . self::dataHora($k['convertido_em'] ?? null)
                    : null],
                ['rotulo' => 'Descrição',      'valor' => $k['descricao']],
            ],
            $blocos
        );
    }

    /* ===================================================================== */
    /* processo                                                               */
    /* ===================================================================== */

    private static function doProcesso(int $id, array $accountIds): ?array
    {
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));

        $st = $pdo->prepare(
            "SELECT p.*,
                    s.nome   AS setor_nome,
                    r.nome   AS responsavel_nome,
                    cli.nome AS cliente_vinculado_nome,
                    a.nome   AS conta_nome
               FROM processos p
          LEFT JOIN clientes_setores s   ON s.id   = p.setor_id
          LEFT JOIN users            r   ON r.id   = p.responsavel_user_id
          LEFT JOIN clientes         cli ON cli.id = p.cliente_id
          LEFT JOIN accounts         a   ON a.id   = p.account_id
              WHERE p.id = ? AND p.deleted_at IS NULL AND p.account_id IN ($in)
              LIMIT 1"
        );
        $st->execute(array_merge([$id], $accountIds));
        $p = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$p) {
            return null;
        }

        $blocos = [];
        $blocos[] = self::blocoPrazos(self::prazosDoProcesso($id, $accountIds));
        $blocos[] = self::blocoTarefasProcesso(self::tarefasDoProcesso($id, $accountIds));
        $blocos[] = self::blocoTimeline(Timeline::paraProcesso($id, $accountIds));

        $numero = trim((string) ($p['numero_cnj'] ?? '')) ?: trim((string) ($p['numero'] ?? ''));

        return self::documento(
            'processo',
            $id,
            $numero !== '' ? $numero : ('Processo ' . $id),
            'Histórico completo do processo',
            (string) ($p['conta_nome'] ?? ''),
            [
                ['rotulo' => 'Número',              'valor' => $p['numero']],
                ['rotulo' => 'Número CNJ',          'valor' => $p['numero_cnj']],
                ['rotulo' => 'Cliente',             'valor' => $p['cliente_vinculado_nome'] ?: $p['cliente_nome']],
                ['rotulo' => 'Parte contrária',     'valor' => $p['parte_contraria']],
                ['rotulo' => 'CPF/CNPJ da parte contrária', 'valor' => $p['cpf_cnpj_parte_contraria']],
                ['rotulo' => 'Tipo de ação',        'valor' => $p['tipo_acao']],
                ['rotulo' => 'Vara / comarca',      'valor' => $p['vara_comarca']],
                ['rotulo' => 'Situação',            'valor' => $p['status']],
                ['rotulo' => 'Setor',               'valor' => $p['setor_nome']],
                ['rotulo' => 'Responsável',         'valor' => $p['responsavel_nome']],
                ['rotulo' => 'Início',              'valor' => self::data($p['data_inicio'] ?? null)],
                ['rotulo' => 'Próximo prazo',       'valor' => self::data($p['proximo_prazo'] ?? null)],
                ['rotulo' => 'Última movimentação', 'valor' => self::data($p['ultima_movimentacao'] ?? null)],
                ['rotulo' => 'Cadastrado em',       'valor' => self::dataHora($p['created_at'] ?? null)],
                ['rotulo' => 'Observações',         'valor' => $p['observacoes']],
            ],
            $blocos
        );
    }

    /* ===================================================================== */
    /* consultas que so existem aqui                                          */
    /* ===================================================================== */

    /** Processos ligados por `cliente_id` ou por `card_id`, conforme a coluna. */
    private static function processosDe(string $coluna, int $id, array $accountIds): array
    {
        // A coluna vem de constante do proprio arquivo, nunca do usuario, mas a
        // allowlist fica aqui para que continue verdade se alguem chamar de fora.
        if (!in_array($coluna, ['cliente_id', 'card_id'], true)) {
            return [];
        }
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $st  = $pdo->prepare(
            "SELECT p.id, p.numero, p.numero_cnj, p.tipo_acao, p.status,
                    p.vara_comarca, p.proximo_prazo, p.ultima_movimentacao,
                    r.nome AS responsavel_nome
               FROM processos p
          LEFT JOIN users r ON r.id = p.responsavel_user_id
              WHERE p.`$coluna` = ? AND p.deleted_at IS NULL AND p.account_id IN ($in)
           ORDER BY p.created_at DESC, p.id DESC"
        );
        $st->execute(array_merge([$id], $accountIds));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** JOIN em `processos` porque `processo_prazos` nao tem account_id. */
    private static function prazosDoProcesso(int $id, array $accountIds): array
    {
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $st  = $pdo->prepare(
            "SELECT z.descricao, z.data_limite, z.status, z.prioridade, z.responsavel, z.observacao
               FROM processo_prazos z
               JOIN processos p ON p.id = z.processo_id
              WHERE z.processo_id = ? AND p.account_id IN ($in)
           ORDER BY z.data_limite IS NULL, z.data_limite ASC, z.id ASC"
        );
        $st->execute(array_merge([$id], $accountIds));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** JOIN em `processos` porque `processo_tarefas` nao tem account_id. */
    private static function tarefasDoProcesso(int $id, array $accountIds): array
    {
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $st  = $pdo->prepare(
            "SELECT t.titulo, t.concluido, t.prioridade, t.responsavel, t.data_tarefa
               FROM processo_tarefas t
               JOIN processos p ON p.id = t.processo_id
              WHERE t.processo_id = ? AND p.account_id IN ($in)
           ORDER BY t.ordem ASC, t.id ASC"
        );
        $st->execute(array_merge([$id], $accountIds));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /** JOIN em `cards` porque `card_checklist_items` nao tem account_id. */
    private static function checklistDoCard(int $id, array $accountIds): array
    {
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $st  = $pdo->prepare(
            "SELECT i.titulo, i.concluido
               FROM card_checklist_items i
               JOIN cards k ON k.id = i.card_id
              WHERE i.card_id = ? AND k.account_id IN ($in)
           ORDER BY i.ordem ASC, i.id ASC"
        );
        $st->execute(array_merge([$id], $accountIds));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Tarefas da prospeccao. O JOIN em `task_boards` NAO e enfeite: `tasks` nao
     * tem account_id, quem tem e o quadro. Mesmo cuidado de VinculosCliente.
     */
    private static function tarefasDoCard(int $id, array $accountIds): array
    {
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $st  = $pdo->prepare(
            "SELECT t.id, t.titulo, t.status, t.prazo, t.prioridade,
                    u.nome AS responsavel_nome
               FROM task_links tl
               JOIN tasks       t ON t.id = tl.task_id
               JOIN task_boards b ON b.id = t.board_id
          LEFT JOIN users       u ON u.id = t.responsavel_id
              WHERE tl.link_type = 'card' AND tl.link_id = ?
                AND b.account_id IN ($in)
           GROUP BY t.id, t.titulo, t.status, t.prazo, t.prioridade, u.nome
           ORDER BY t.prazo IS NULL, t.prazo ASC, t.id ASC"
        );
        $st->execute(array_merge([$id], $accountIds));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function conversasDoCard(int $id, array $accountIds): array
    {
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $st  = $pdo->prepare(
            "SELECT wc.id, wc.remote_jid, wc.contact_name, wc.phone,
                    wc.last_message_at
               FROM whatsapp_chats wc
              WHERE wc.linked_card_id = ? AND wc.account_id IN ($in)
           ORDER BY wc.id DESC"
        );
        $st->execute(array_merge([$id], $accountIds));
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    private static function blocoProspeccoesDeOrigem(array $cardIds, array $accountIds): array
    {
        $cardIds = self::inteiros($cardIds);
        if ($cardIds === []) {
            return self::blocoTabela('prospeccoes', 'Prospecções de origem', [], [], 'Este cliente foi cadastrado direto, sem passar pelo funil.');
        }
        $pdo   = Database::getConnection();
        $inC   = implode(',', array_fill(0, count($cardIds), '?'));
        $inA   = implode(',', array_fill(0, count($accountIds), '?'));
        $st    = $pdo->prepare(
            "SELECT k.id, k.cliente_nome, k.created_at, k.convertido_em,
                    col.nome AS coluna_nome, k.status
               FROM cards k
          LEFT JOIN pipeline_columns col ON col.id = k.coluna_id
              WHERE k.id IN ($inC) AND k.account_id IN ($inA)
           ORDER BY k.created_at ASC"
        );
        $st->execute(array_merge($cardIds, $accountIds));

        $linhas = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $linhas[] = [
                (string) $r['id'],
                (string) $r['cliente_nome'],
                (string) ($r['coluna_nome'] ?? ''),
                (string) ($r['status'] ?? ''),
                self::dataHora($r['created_at'] ?? null),
                self::dataHora($r['convertido_em'] ?? null),
            ];
        }
        return self::blocoTabela(
            'prospeccoes',
            'Prospecções de origem',
            ['Nº', 'Nome', 'Etapa', 'Situação', 'Criada em', 'Convertida em'],
            $linhas,
            'Este cliente foi cadastrado direto, sem passar pelo funil.'
        );
    }

    /* ===================================================================== */
    /* montadores de bloco                                                    */
    /* ===================================================================== */

    private static function blocoDocumentos(array $anexos): array
    {
        $linhas = [];
        foreach ($anexos as $a) {
            $linhas[] = [
                (string) ($a['file_name'] ?? ''),
                (string) ($a['descricao'] ?? ''),
                self::tamanho($a['file_size'] ?? null),
                (string) ($a['enviado_por_nome'] ?? ''),
                self::dataHora($a['created_at'] ?? null),
                // Diz de ONDE veio: o documento anexado enquanto a pessoa era
                // lead aparece aqui, e o dossie tem de deixar isso claro.
                (string) ($a['origem_titulo'] ?? ''),
            ];
        }
        return self::blocoTabela(
            'documentos',
            'Documentos anexados',
            ['Arquivo', 'Descrição', 'Tamanho', 'Enviado por', 'Enviado em', 'Origem'],
            $linhas,
            'Nenhum documento anexado.'
        );
    }

    private static function blocoInteracoes(array $itens): array
    {
        $linhas = [];
        foreach ($itens as $i) {
            $tipo = (string) ($i['tipo'] ?? '');
            $linhas[] = [
                self::dataHora($i['ocorrido_em'] ?? null),
                Interacao::ROTULOS[$tipo] ?? $tipo,
                self::rotuloDirecao($i['direcao'] ?? null),
                (string) ($i['assunto'] ?? ''),
                trim((string) ($i['conteudo'] ?? '')),
                (string) ($i['registrado_por_nome'] ?? ''),
            ];
        }
        return self::blocoTabela(
            'interacoes',
            'Contatos e anotações',
            ['Quando', 'Tipo', 'Direção', 'Assunto', 'Conteúdo', 'Registrado por'],
            $linhas,
            'Nenhum contato ou anotação registrada.'
        );
    }

    private static function blocoProcessos(array $itens): array
    {
        $linhas = [];
        foreach ($itens as $p) {
            $linhas[] = [
                (string) (($p['numero_cnj'] ?? '') ?: ($p['numero'] ?? '')),
                (string) ($p['tipo_acao'] ?? ''),
                (string) ($p['vara_comarca'] ?? ''),
                (string) ($p['status'] ?? ''),
                (string) ($p['responsavel_nome'] ?? ''),
                self::data($p['proximo_prazo'] ?? null),
                self::data($p['ultima_movimentacao'] ?? null),
            ];
        }
        return self::blocoTabela(
            'processos',
            'Processos',
            ['Número', 'Tipo de ação', 'Vara / comarca', 'Situação', 'Responsável', 'Próximo prazo', 'Última movimentação'],
            $linhas,
            'Nenhum processo vinculado.'
        );
    }

    private static function blocoTarefas(array $itens): array
    {
        $linhas = [];
        foreach ($itens as $t) {
            $linhas[] = [
                (string) ($t['titulo'] ?? ''),
                (string) ($t['status'] ?? ''),
                (string) ($t['prioridade'] ?? ''),
                self::data($t['prazo'] ?? null),
                (string) ($t['responsavel_nome'] ?? ''),
            ];
        }
        return self::blocoTabela(
            'tarefas',
            'Tarefas',
            ['Tarefa', 'Situação', 'Prioridade', 'Prazo', 'Responsável'],
            $linhas,
            'Nenhuma tarefa vinculada.'
        );
    }

    private static function blocoTarefasProcesso(array $itens): array
    {
        $linhas = [];
        foreach ($itens as $t) {
            $linhas[] = [
                (string) ($t['titulo'] ?? ''),
                empty($t['concluido']) ? 'Pendente' : 'Concluída',
                (string) ($t['prioridade'] ?? ''),
                self::data($t['data_tarefa'] ?? null),
                (string) ($t['responsavel'] ?? ''),
            ];
        }
        return self::blocoTabela(
            'tarefas',
            'Tarefas do processo',
            ['Tarefa', 'Situação', 'Prioridade', 'Data', 'Responsável'],
            $linhas,
            'Nenhuma tarefa cadastrada no processo.'
        );
    }

    private static function blocoPrazos(array $itens): array
    {
        $linhas = [];
        foreach ($itens as $z) {
            $linhas[] = [
                self::data($z['data_limite'] ?? null),
                (string) ($z['descricao'] ?? ''),
                (string) ($z['status'] ?? ''),
                (string) ($z['prioridade'] ?? ''),
                (string) ($z['responsavel'] ?? ''),
                (string) ($z['observacao'] ?? ''),
            ];
        }
        return self::blocoTabela(
            'prazos',
            'Prazos',
            ['Limite', 'Descrição', 'Situação', 'Prioridade', 'Responsável', 'Observação'],
            $linhas,
            'Nenhum prazo cadastrado.'
        );
    }

    private static function blocoChecklist(array $itens): array
    {
        $linhas = [];
        foreach ($itens as $i) {
            $linhas[] = [
                (string) ($i['titulo'] ?? ''),
                empty($i['concluido']) ? 'Pendente' : 'Concluído',
            ];
        }
        return self::blocoTabela('checklist', 'Checklist', ['Item', 'Situação'], $linhas, 'Nenhum item de checklist.');
    }

    private static function blocoConversas(array $itens): array
    {
        $linhas = [];
        foreach ($itens as $c) {
            $linhas[] = [
                (string) (($c['contact_name'] ?? '') ?: ($c['remote_jid'] ?? '')),
                (string) ($c['phone'] ?? ''),
                self::dataHora($c['last_message_at'] ?? null),
                (string) ($c['origem_titulo'] ?? ''),
            ];
        }
        return self::blocoTabela(
            'conversas',
            'Conversas de WhatsApp',
            ['Contato', 'Telefone', 'Última mensagem', 'Origem'],
            $linhas,
            'Nenhuma conversa vinculada.'
        );
    }

    private static function blocoCampos(array $valores): array
    {
        $pares = [];
        foreach ($valores as $v) {
            $pares[] = [
                'rotulo' => (string) ($v['rotulo'] ?? $v['chave'] ?? ''),
                'valor'  => self::valorDeCampo($v),
            ];
        }
        return self::blocoPares('campos', 'Campos personalizados', $pares, 'Nenhum campo personalizado configurado.');
    }

    /**
     * Campo de multipla escolha guarda o valor como JSON. Um relatorio impresso
     * com `["a","b"]` na folha seria pior que nao imprimir.
     */
    private static function valorDeCampo(array $v): ?string
    {
        $bruto = $v['valor'] ?? null;
        if ($bruto === null || $bruto === '') {
            return null;
        }
        if (($v['tipo'] ?? '') === 'multi_selecao') {
            $d = json_decode((string) $bruto, true);
            if (is_array($d)) {
                return implode(', ', array_map('strval', $d));
            }
        }
        if (($v['tipo'] ?? '') === 'data') {
            return self::data($bruto);
        }
        if (($v['tipo'] ?? '') === 'booleano') {
            return ((string) $bruto === '1' || $bruto === true) ? 'Sim' : 'Não';
        }
        return (string) $bruto;
    }

    private static function blocoTimeline(array $eventos): array
    {
        /*
         * A etiqueta de FASE só ajuda quando há mais de uma.
         *
         * Na timeline de um cliente ela é o que diz "isto aconteceu quando ele
         * ainda era prospecção", e vale ouro. Na de um processo, ou na de uma
         * prospecção que nunca converteu, TODO evento tem a mesma fase, e aí a
         * etiqueta vira uma coluna de ruído repetida linha a linha, encompridando
         * o documento sem informar nada.
         */
        $fases = [];
        foreach ($eventos as $e) {
            $f = trim((string) ($e['fase'] ?? ''));
            if ($f !== '') {
                $fases[$f] = true;
            }
        }
        $mostrarFase = count($fases) > 1;

        $itens = [];
        foreach ($eventos as $e) {
            $itens[] = [
                'quando'    => self::dataHora($e['quando'] ?? null),
                'usuario'   => $e['usuario'] ?: 'Sistema',
                'acao'      => self::rotuloAcao((string) ($e['acao'] ?? '')),
                'categoria' => (string) ($e['categoria'] ?? ''),
                'campo'     => $e['campo'] ?? null,
                'de'        => $e['de'] ?? null,
                'para'      => $e['para'] ?? null,
                'fase'      => $mostrarFase ? (string) ($e['fase'] ?? '') : '',
            ];
        }
        return [
            'chave'  => 'timeline',
            'titulo' => 'Linha do tempo completa',
            'tipo'   => 'timeline',
            'itens'  => $itens,
            'total'  => count($itens),
            'vazio'  => 'Nenhum movimento registrado.',
        ];
    }

    private static function blocoPares(string $chave, string $titulo, array $pares, string $vazio = 'Não informado.'): array
    {
        // Par sem valor nao vai para o papel: uma folha cheia de "vazio" esconde
        // o que interessa.
        $limpos = [];
        foreach ($pares as $p) {
            $valor = trim((string) ($p['valor'] ?? ''));
            if ($valor !== '') {
                $limpos[] = ['rotulo' => (string) $p['rotulo'], 'valor' => $valor];
            }
        }
        return [
            'chave'  => $chave,
            'titulo' => $titulo,
            'tipo'   => 'pares',
            'itens'  => $limpos,
            'total'  => count($limpos),
            'vazio'  => $vazio,
        ];
    }

    private static function blocoChips(string $chave, string $titulo, array $tags): array
    {
        $itens = [];
        foreach ($tags as $t) {
            $itens[] = ['nome' => (string) ($t['nome'] ?? ''), 'cor' => (string) ($t['cor'] ?? '')];
        }
        return [
            'chave'  => $chave,
            'titulo' => $titulo,
            'tipo'   => 'chips',
            'itens'  => $itens,
            'total'  => count($itens),
            'vazio'  => 'Nenhuma etiqueta aplicada.',
        ];
    }

    private static function blocoTabela(string $chave, string $titulo, array $colunas, array $linhas, string $vazio): array
    {
        return [
            'chave'   => $chave,
            'titulo'  => $titulo,
            'tipo'    => 'tabela',
            'colunas' => $colunas,
            'linhas'  => $linhas,
            'total'   => count($linhas),
            'vazio'   => $vazio,
        ];
    }

    private static function documento(
        string $entidade,
        int $id,
        string $titulo,
        string $subtitulo,
        string $conta,
        array $identificacao,
        array $blocos
    ): array {
        $ident = [];
        foreach ($identificacao as $p) {
            $valor = trim((string) ($p['valor'] ?? ''));
            if ($valor !== '') {
                $ident[] = ['rotulo' => (string) $p['rotulo'], 'valor' => $valor];
            }
        }

        // O resumo e o que a pessoa le antes de virar a pagina.
        $resumo = [];
        foreach ($blocos as $b) {
            if (in_array($b['chave'], ['documentos', 'interacoes', 'processos', 'tarefas', 'prazos', 'conversas', 'timeline'], true)) {
                $resumo[$b['chave']] = (int) ($b['total'] ?? 0);
            }
        }

        return [
            'entidade'      => $entidade,
            'id'            => $id,
            'titulo'        => $titulo,
            'subtitulo'     => $subtitulo,
            'conta'         => $conta,
            'gerado_em'     => date('d/m/Y H:i'),
            'identificacao' => $ident,
            'blocos'        => $blocos,
            'resumo'        => $resumo,
        ];
    }

    /* ===================================================================== */
    /* formatacao                                                             */
    /* ===================================================================== */

    private static function endereco(array $r): array
    {
        $rua = trim((string) ($r['logradouro'] ?? ''));
        if ($rua !== '' && trim((string) ($r['numero'] ?? '')) !== '') {
            $rua .= ', ' . trim((string) $r['numero']);
        }
        if (trim((string) ($r['complemento'] ?? '')) !== '') {
            $rua .= ' ' . trim((string) $r['complemento']);
        }
        $cidade = trim((string) ($r['cidade'] ?? ''));
        if ($cidade !== '' && trim((string) ($r['uf'] ?? '')) !== '') {
            $cidade .= ' / ' . trim((string) $r['uf']);
        }
        return [
            ['rotulo' => 'CEP',        'valor' => $r['cep'] ?? null],
            ['rotulo' => 'Logradouro', 'valor' => $rua],
            ['rotulo' => 'Bairro',     'valor' => $r['bairro'] ?? null],
            ['rotulo' => 'Cidade',     'valor' => $cidade],
        ];
    }

    private static function data($v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '' || str_starts_with($s, '0000')) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', substr($s, 0, 10));
        return $d ? $d->format('d/m/Y') : $s;
    }

    private static function dataHora($v): ?string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '' || str_starts_with($s, '0000')) {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', substr($s, 0, 19));
        return $d ? $d->format('d/m/Y H:i') : (self::data($s) ?? $s);
    }

    private static function dinheiro($v): ?string
    {
        if ($v === null || $v === '' || (float) $v == 0.0) {
            return null;
        }
        return 'R$ ' . number_format((float) $v, 2, ',', '.');
    }

    private static function tamanho($bytes): string
    {
        $b = (int) $bytes;
        if ($b <= 0) {
            return '';
        }
        if ($b < 1024) {
            return $b . ' B';
        }
        if ($b < 1024 * 1024) {
            return number_format($b / 1024, 0, ',', '.') . ' KB';
        }
        return number_format($b / (1024 * 1024), 1, ',', '.') . ' MB';
    }

    private static function rotuloTipoCliente($v): ?string
    {
        // O ENUM grava em MAIUSCULA ('PF'/'PJ'). Normaliza antes de comparar,
        // senao o rotulo cai no default e o relatorio imprime "PF" cru.
        return match (strtolower(trim((string) $v))) {
            'pf' => 'Pessoa física',
            'pj' => 'Pessoa jurídica',
            ''   => null,
            default => (string) $v,
        };
    }

    private static function rotuloDirecao($v): string
    {
        return match ((string) $v) {
            'entrada' => 'Recebido',
            'saida'   => 'Enviado',
            'interna' => 'Interna',
            default   => '',
        };
    }

    /**
     * A acao chega crua do historico, do jeito que foi gravada. A tela traduz
     * com `Yuris.translateAuditAcao`; o relatorio nao tem JavaScript no
     * caminho do papel, entao traduz aqui.
     *
     * O que nao estiver no mapa vira texto legivel em vez de sumir: um verbo
     * novo gravado amanha aparece como "Anexo removido" e nao como vazio.
     */
    public const ACOES = [
        'created'             => 'Cadastro criado',
        'updated'             => 'Cadastro alterado',
        'deleted'             => 'Excluído',
        'archived'            => 'Arquivado',
        'restored'            => 'Restaurado',
        'moved'               => 'Movido de etapa',
        'reorder'             => 'Reordenado',
        'reordered'           => 'Reordenado',
        'stage_changed'       => 'Mudança de etapa',
        'status_changed'      => 'Mudança de situação',
        'convertido_cliente'  => 'Convertido em cliente',
        'vinculado_cliente'   => 'Vinculado a cliente',
        'interacao_registrada'=> 'Contato registrado',
        'nota_interna'        => 'Anotação interna',
        'prazo_processo'      => 'Prazo cadastrado',
        'tarefa_registrada'   => 'Tarefa registrada',
    ];

    private static function rotuloAcao(string $acao): string
    {
        if (isset(self::ACOES[$acao])) {
            return self::ACOES[$acao];
        }
        $texto = trim(str_replace('_', ' ', $acao));
        return $texto === '' ? 'Movimento' : mb_strtoupper(mb_substr($texto, 0, 1)) . mb_substr($texto, 1);
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
