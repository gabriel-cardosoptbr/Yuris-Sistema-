<?php
/**
 * Migration 127 — FASE 2 do CRM: o que a prospeccao e o cliente ainda nao tinham.
 *
 * ---------------------------------------------------------------------------
 * O QUE FALTAVA
 * ---------------------------------------------------------------------------
 * A Fase 1 ligou prospeccao e cliente (migration 126) e fez o historico, os
 * processos, a conversa e as tarefas acompanharem a conversao. Mas cinco coisas
 * que todo CRM juridico usa NAO EXISTIAM em tabela nenhuma das 112 do banco:
 *
 *   A. anexo/documento de cliente ou de prospeccao
 *      (havia `task_attachments` e `lgpd_request_attachments`, nenhum dos dois
 *       serve: um pertence a tarefa, o outro a peticao de titular)
 *   B. tag / etiqueta / classificacao
 *   C. campo personalizado
 *   D. interacao registrada e nota interna como entidade
 *      (existia so a coluna `observacoes text`, texto solto, sem autor e sem data)
 *   E. canal de aquisicao na prospeccao
 *      (o filtro "Origem" da tela de prospeccao e matriz/filial, NAO canal;
 *       entao "por onde este lead chegou" se perdia na conversao)
 *
 * ---------------------------------------------------------------------------
 * POR QUE O PREFIXO `crm_`
 * ---------------------------------------------------------------------------
 * As seis tabelas novas servem AS DUAS pontas: prospeccao e cliente. Chamar de
 * `clientes_anexos` mentiria (o card tambem anexa) e `card_anexos` mentiria ao
 * contrario. O prefixo `crm_` diz a verdade: e do CRM, os dois lados usam.
 *
 * Cada tabela carrega `entidade ENUM('cliente','card')` + `entidade_id`. E
 * deliberadamente um vinculo polimorfico, e nao duas tabelas espelhadas, porque
 * a conversao precisa LER OS DOIS LADOS numa consulta so. Com tabelas separadas
 * a ficha do cliente faria dois SELECTs e um merge em PHP para cada bloco.
 *
 * ---------------------------------------------------------------------------
 * A REGRA QUE DECIDE O QUE COPIA E O QUE NAO COPIA NA CONVERSAO
 * ---------------------------------------------------------------------------
 * FATO nao se copia, se le junto:   anexo, interacao, historico.
 *   Um documento anexado dia 3 na prospeccao E o documento daquele dia. Copiar
 *   criaria duas verdades e a segunda envelheceria sozinha (mesmo argumento do
 *   App\Core\Timeline e do App\Clientes\VinculosCliente).
 *
 * OPINIAO EDITAVEL copia uma vez e fica independente:   tag, campo personalizado.
 *   "Lead frio" e um juizo sobre a prospeccao. Se fosse lido por heranca, o
 *   usuario nao conseguiria tirar a tag da ficha do cliente sem mexer no card,
 *   e isso e errado: depois de virar cliente, a classificacao e outra.
 *
 * Essa assimetria e a decisao central da Fase 2. Ver App\Prospeccao\ConversaoCliente.
 *
 * ---------------------------------------------------------------------------
 * MULTI-TENANT
 * ---------------------------------------------------------------------------
 * TODAS as seis tabelas tem `account_id` proprio, NOT NULL. Isso e escolha, nao
 * descuido de normalizacao: `card_history` nao tem, e por isso toda leitura dela
 * exige JOIN em `cards`, e esquecer o JOIN atravessa contas em silencio. Aqui o
 * filtro e direto. A coerencia entre o account_id da linha e o da entidade dona
 * e garantida na escrita por App\Crm\Entidade::resolver(), que e o unico portao.
 *
 * ---------------------------------------------------------------------------
 * SEM CHAVE ESTRANGEIRA
 * ---------------------------------------------------------------------------
 * Nao ha UMA unica FK apontando para `clientes` ou `cards` em todo o schema
 * (conferido em information_schema.KEY_COLUMN_USAGE). Manter o padrao: indice
 * sim, FK nao. Introduzir FK aqui mudaria o comportamento de delete das duas
 * tabelas mais antigas do sistema, o que esta migration nao tem por que fazer.
 *
 * ---------------------------------------------------------------------------
 * O QUE MAIS ELA FAZ
 * ---------------------------------------------------------------------------
 *   · cards.origem_id            → canal de aquisicao, aponta para clientes_origens
 *   · task_links.link_type += 'cliente'  → compromisso ligado direto ao cliente
 *   · trigger de imutabilidade em clientes_history
 *
 * Sobre o ultimo: a migration 053 protegeu 15 tabelas de auditoria contra UPDATE
 * e DELETE (LGPD Art. 37). `clientes_history` ficou de fora, e era a UNICA das
 * seis tabelas de historico sem a trava. Conferido antes de ligar: nenhum ponto
 * do codigo faz UPDATE ou DELETE nela (so INSERT e SELECT), e nenhuma FK com
 * ON DELETE CASCADE aponta para `clientes`, entao apagar um cliente nao tenta
 * apagar o historico dele. A trava e segura.
 *
 * Sobre o ENUM de task_links: 'cliente' e ACRESCENTADO, os quatro valores atuais
 * ('contato','processo','card','dre_account') continuam intactos. Sem isso, um
 * cliente que nunca foi prospeccao nao teria como ter compromisso proprio, ja
 * que hoje a tarefa so chega ao cliente por dentro do card de origem.
 *
 * ---------------------------------------------------------------------------
 * NAO APAGA E NAO ALTERA NENHUMA LINHA EXISTENTE.
 * IDEMPOTENTE: confere information_schema antes de cada passo.
 * ---------------------------------------------------------------------------
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_127.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_127.php --dry-run
 *
 * Flags:
 *   --dry-run   diz o que faria, sem gravar nada. RODE ISSO PRIMEIRO EM PRODUCAO.
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void
{
    echo $s . PHP_EOL;
}

function temTabela(\PDO $pdo, string $tabela): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $st->execute([$tabela]);
    return (bool) $st->fetchColumn();
}

function temColuna(\PDO $pdo, string $tabela, string $coluna): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$tabela, $coluna]);
    return (bool) $st->fetchColumn();
}

function temIndice(\PDO $pdo, string $tabela, string $indice): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $st->execute([$tabela, $indice]);
    return (bool) $st->fetchColumn();
}

function temTrigger(\PDO $pdo, string $nome): bool
{
    $st = $pdo->prepare(
        'SELECT 1 FROM information_schema.TRIGGERS
          WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?'
    );
    $st->execute([$nome]);
    return (bool) $st->fetchColumn();
}

function tipoColuna(\PDO $pdo, string $tabela, string $coluna): ?string
{
    $st = $pdo->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $st->execute([$tabela, $coluna]);
    $t = $st->fetchColumn();
    return $t === false ? null : (string) $t;
}

linha('== Migration 127: Fase 2 do CRM (anexos, tags, campos, interacoes, canal) ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$feitas  = 0;
$puladas = 0;

/* =========================================================================
 * 1. TABELAS NOVAS
 * ===================================================================== */

$tabelas = [];

/* ── A. anexos ─────────────────────────────────────────────────────────────
 * Espelha task_attachments de proposito: mesmo formato de file_path relativo a
 * public/, mesmo par mime_type/file_size. Assim o endpoint de download novo
 * pode ser lido lado a lado com o de tarefa sem traduzir nada.
 *
 * deleted_at existe porque o nome do arquivo e prova: a linha fica marcada como
 * removida e o ARQUIVO sai do disco. Quem apagou e quando ficam no historico.
 */
$tabelas['crm_anexos'] = "
CREATE TABLE `crm_anexos` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `account_id`  INT NOT NULL COMMENT 'Conta dona. Sempre igual ao account_id da entidade.',
  `entidade`    ENUM('cliente','card') NOT NULL,
  `entidade_id` INT NOT NULL,
  `file_path`   VARCHAR(500) NOT NULL COMMENT 'Relativo a public/, igual task_attachments. NUNCA exposto ao front.',
  `file_name`   VARCHAR(255) NOT NULL COMMENT 'Nome original, para exibir e para o download',
  `mime_type`   VARCHAR(100) NULL,
  `file_size`   INT NULL,
  `descricao`   VARCHAR(255) NULL COMMENT 'Rotulo livre: \"RG frente\", \"contrato assinado\"',
  `uploaded_by` INT NULL,
  `created_at`  DATETIME NULL,
  `deleted_at`  DATETIME NULL,
  `deleted_by`  INT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_crm_anexos_ent` (`entidade`,`entidade_id`,`deleted_at`),
  KEY `idx_crm_anexos_acc` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fase 2: documentos de cliente e de prospeccao. Fato: nao copia na conversao.'";

/* ── B. tags ───────────────────────────────────────────────────────────────
 * Uma estrutura para os tres nomes que o pedido usou (tag, etiqueta,
 * classificacao): sao a mesma coisa. Catalogo por conta + vinculo.
 */
$tabelas['crm_tags'] = "
CREATE TABLE `crm_tags` (
  `id`         INT NOT NULL AUTO_INCREMENT,
  `account_id` INT NOT NULL,
  `nome`       VARCHAR(60) NOT NULL,
  `slug`       VARCHAR(60) NOT NULL COMMENT 'Identidade estavel: renomear a tag nao quebra o historico',
  `cor`        VARCHAR(20) NULL COMMENT 'Hex #rrggbb. NULL = a UI escolhe pelo slug.',
  `ordem`      INT NOT NULL DEFAULT 0,
  `ativo`      TINYINT(1) NOT NULL DEFAULT 1,
  `created_by` INT NULL,
  `created_at` DATETIME NULL,
  `updated_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_crm_tags_slug` (`account_id`,`slug`),
  KEY `idx_crm_tags_acc` (`account_id`,`ativo`,`ordem`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fase 2: catalogo de tags/etiquetas/classificacoes por conta'";

/* O account_id aqui e denormalizado de proposito. Ver o cabecalho: sem ele,
 * toda leitura precisaria de JOIN em crm_tags, e o JOIN esquecido atravessa
 * contas. App\Crm\Tag confere que tag.account_id == entidade.account_id antes
 * de gravar, entao os dois nunca divergem. */
$tabelas['crm_tag_vinculos'] = "
CREATE TABLE `crm_tag_vinculos` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `tag_id`      INT NOT NULL,
  `account_id`  INT NOT NULL COMMENT 'Denormalizado da tag E conferido contra a entidade na escrita',
  `entidade`    ENUM('cliente','card') NOT NULL,
  `entidade_id` INT NOT NULL,
  `created_by`  INT NULL,
  `created_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_crm_tag_vinc` (`tag_id`,`entidade`,`entidade_id`),
  KEY `idx_crm_tag_vinc_ent` (`entidade`,`entidade_id`),
  KEY `idx_crm_tag_vinc_acc` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fase 2: tag aplicada a um cliente ou prospeccao. Opiniao: COPIA na conversao.'";

/* ── C. campos personalizados ──────────────────────────────────────────────
 * `chave` e o que a auditoria grava. Trocar o `rotulo` na tela nao reescreve o
 * historico: o evento antigo continua dizendo qual chave mudou.
 */
$tabelas['crm_campos'] = "
CREATE TABLE `crm_campos` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `account_id`  INT NOT NULL,
  `aplica_em`   ENUM('cliente','card','ambos') NOT NULL DEFAULT 'ambos',
  `chave`       VARCHAR(60) NOT NULL COMMENT 'Slug estavel. E o que vai para o historico.',
  `rotulo`      VARCHAR(120) NOT NULL COMMENT 'O que aparece na tela. Pode mudar sem quebrar auditoria.',
  `tipo`        ENUM('texto','texto_longo','numero','moeda','data','selecao','multi_selecao','sim_nao') NOT NULL DEFAULT 'texto',
  `opcoes_json` TEXT NULL COMMENT 'Array JSON de opcoes. So para selecao e multi_selecao.',
  `obrigatorio` TINYINT(1) NOT NULL DEFAULT 0,
  `ordem`       INT NOT NULL DEFAULT 0,
  `ativo`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`  INT NULL,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_crm_campos_chave` (`account_id`,`chave`),
  KEY `idx_crm_campos_acc` (`account_id`,`ativo`,`ordem`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fase 2: definicao de campo personalizado, por conta'";

$tabelas['crm_campo_valores'] = "
CREATE TABLE `crm_campo_valores` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `campo_id`    INT NOT NULL,
  `account_id`  INT NOT NULL COMMENT 'Denormalizado do campo E conferido contra a entidade na escrita',
  `entidade`    ENUM('cliente','card') NOT NULL,
  `entidade_id` INT NOT NULL,
  `valor`       TEXT NULL COMMENT 'Sempre texto. multi_selecao guarda JSON. NULL = nao preenchido.',
  `updated_by`  INT NULL,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_crm_campo_valor` (`campo_id`,`entidade`,`entidade_id`),
  KEY `idx_crm_campo_val_ent` (`entidade`,`entidade_id`),
  KEY `idx_crm_campo_val_acc` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fase 2: valor de campo personalizado. Opiniao: COPIA na conversao.'";

/* ── D. interacoes e notas internas ────────────────────────────────────────
 * Uma tabela, nao duas. Nota interna E uma interacao sem contraparte: tipo
 * 'nota'. Duas tabelas para isso obrigariam a ficha a fazer dois SELECTs e um
 * merge por data em PHP, com dois formatos de linha para a mesma timeline.
 *
 * `ocorrido_em` e separado de `created_at` porque a ligacao de ontem pode ser
 * registrada hoje. A timeline ordena por ocorrido_em, que e quando o fato foi.
 */
$tabelas['crm_interacoes'] = "
CREATE TABLE `crm_interacoes` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `account_id`  INT NOT NULL,
  `entidade`    ENUM('cliente','card') NOT NULL,
  `entidade_id` INT NOT NULL,
  `tipo`        ENUM('ligacao','reuniao','email','whatsapp','presencial','nota','outro') NOT NULL DEFAULT 'nota',
  `direcao`     ENUM('entrada','saida','interna') NULL COMMENT 'NULL para tipo nota',
  `assunto`     VARCHAR(180) NULL,
  `conteudo`    TEXT NULL,
  `ocorrido_em` DATETIME NOT NULL COMMENT 'Quando o fato aconteceu, nao quando foi digitado',
  `duracao_min` INT NULL,
  `created_by`  INT NULL,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,
  `deleted_at`  DATETIME NULL,
  `deleted_by`  INT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_crm_inter_ent` (`entidade`,`entidade_id`,`deleted_at`),
  KEY `idx_crm_inter_acc` (`account_id`),
  KEY `idx_crm_inter_data` (`ocorrido_em`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fase 2: interacao registrada e nota interna. Fato: nao copia na conversao.'";

foreach ($tabelas as $nome => $ddl) {
    if (temTabela($pdo, $nome)) {
        linha("  [ja existe] tabela $nome");
        $puladas++;
        continue;
    }
    if ($dryRun) {
        linha("  [criaria]   tabela $nome");
    } else {
        $pdo->exec($ddl);
        linha("  [criada]    tabela $nome");
    }
    $feitas++;
}

linha();

/* =========================================================================
 * 2. cards.origem_id — canal de aquisicao (bloco E)
 *
 * Aponta para `clientes_origens`, o catalogo que JA existe por conta com dez
 * canais semeados (indicacao, anuncio, site, redes sociais, evento, captacao
 * ativa...). Reusar em vez de criar `cards_origens` e o que faz o canal
 * SOBREVIVER a conversao: os dois lados leem do mesmo catalogo, entao
 * ConversaoCliente copia o slug para `clientes.origem` sem tradutor no meio.
 *
 * O nome da tabela diz "clientes" e agora ela serve os dois. Renomear custaria
 * um ALTER em producao e uma varredura de codigo, para ganhar so estetica.
 * ===================================================================== */

$colunas = [
    ['cards', 'origem_id', "INT NULL COMMENT 'Canal de aquisicao: clientes_origens.id. Acompanha na conversao.'"],
];
$indices = [
    ['cards', 'idx_cards_origem', 'origem_id'],
];

foreach ($colunas as [$tabela, $coluna, $def]) {
    if (temColuna($pdo, $tabela, $coluna)) {
        linha("  [ja existe] $tabela.$coluna");
        $puladas++;
        continue;
    }
    $sql = "ALTER TABLE `$tabela` ADD COLUMN `$coluna` $def";
    if ($dryRun) {
        linha("  [faria]     $sql");
    } else {
        $pdo->exec($sql);
        linha("  [criada]    $tabela.$coluna");
    }
    $feitas++;
}

foreach ($indices as [$tabela, $indice, $coluna]) {
    if (temIndice($pdo, $tabela, $indice)) {
        linha("  [ja existe] indice $tabela.$indice");
        $puladas++;
        continue;
    }
    if ($dryRun && !temColuna($pdo, $tabela, $coluna)) {
        linha("  [faria]     ALTER TABLE `$tabela` ADD INDEX `$indice` (`$coluna`)  (depois da coluna)");
        $feitas++;
        continue;
    }
    $sql = "ALTER TABLE `$tabela` ADD INDEX `$indice` (`$coluna`)";
    if ($dryRun) {
        linha("  [faria]     $sql");
    } else {
        $pdo->exec($sql);
        linha("  [criado]    indice $tabela.$indice");
    }
    $feitas++;
}

linha();

/* =========================================================================
 * 3. task_links.link_type += 'cliente'
 *
 * Hoje a tarefa chega ao cliente SO por dentro do card de origem
 * (App\Clientes\VinculosCliente::tarefas). Um cliente cadastrado direto, que
 * nunca foi prospeccao, nao tem como ter compromisso proprio.
 *
 * ACRESCENTA um valor ao ENUM. Os quatro atuais continuam iguais e nenhuma
 * linha existente muda: MySQL guarda o indice do valor, e os indices 1 a 4
 * seguem apontando para os mesmos rotulos.
 * ===================================================================== */

$tipoAtual = tipoColuna($pdo, 'task_links', 'link_type');
if ($tipoAtual === null) {
    linha('  [pulado]    task_links.link_type nao existe neste banco');
    $puladas++;
} elseif (str_contains($tipoAtual, "'cliente'")) {
    linha("  [ja existe] task_links.link_type ja aceita 'cliente'");
    $puladas++;
} else {
    $sql = "ALTER TABLE `task_links`
             MODIFY COLUMN `link_type`
             ENUM('contato','processo','card','dre_account','cliente') NOT NULL";
    if ($dryRun) {
        linha("  [faria]     ALTER task_links.link_type: $tipoAtual  ->  + 'cliente'");
    } else {
        $pdo->exec($sql);
        linha("  [alterado]  task_links.link_type aceita 'cliente'");
    }
    $feitas++;
}

linha();

/* =========================================================================
 * 4. Imutabilidade de clientes_history (LGPD Art. 37)
 *
 * A migration 053 travou 15 tabelas de auditoria contra UPDATE e DELETE.
 * `clientes_history` foi a unica das seis tabelas de historico que ficou de
 * fora, e nada justificava a excecao.
 *
 * Conferido antes de ligar:
 *   · nenhum ponto do codigo faz UPDATE ou DELETE nela (grep: so INSERT/SELECT)
 *   · nenhuma FK aponta para `clientes`, entao apagar cliente nao cascateia
 *     para o historico e nao bate na trava
 *
 * Se um dia for preciso redigir uma linha por decisao de titular, o caminho e
 * o mesmo dos anexos LGPD: trigger de update PARCIAL (ver trg_lra_partial_update
 * na 053), nao remover a trava.
 * ===================================================================== */

$triggers = [
    'trg_clih_no_update' => "CREATE TRIGGER `trg_clih_no_update` BEFORE UPDATE ON `clientes_history`
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'clientes_history e imutavel (LGPD Art. 37)';
END",
    'trg_clih_no_delete' => "CREATE TRIGGER `trg_clih_no_delete` BEFORE DELETE ON `clientes_history`
FOR EACH ROW BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'clientes_history e imutavel (LGPD Art. 37)';
END",
];

foreach ($triggers as $nome => $ddl) {
    if (temTrigger($pdo, $nome)) {
        linha("  [ja existe] trigger $nome");
        $puladas++;
        continue;
    }
    if ($dryRun) {
        linha("  [criaria]   trigger $nome");
    } else {
        $pdo->exec($ddl);
        linha("  [criado]    trigger $nome");
    }
    $feitas++;
}

linha();

/* =========================================================================
 * 5. Diagnostico: o tamanho do que a Fase 2 passa a atender.
 * ===================================================================== */

$cards    = (int) $pdo->query('SELECT COUNT(*) FROM cards WHERE deleted_at IS NULL')->fetchColumn();
$clientes = (int) $pdo->query('SELECT COUNT(*) FROM clientes WHERE deleted_at IS NULL')->fetchColumn();
$canais   = (int) $pdo->query('SELECT COUNT(*) FROM clientes_origens WHERE ativo = 1')->fetchColumn();
$contas   = (int) $pdo->query('SELECT COUNT(DISTINCT account_id) FROM clientes_origens')->fetchColumn();

linha("  prospeccoes ativas:            $cards");
linha("  clientes ativos:               $clientes");
linha("  canais de aquisicao no catalogo: $canais  (em $contas conta(s))");

// Conta sem catalogo de canal nao consegue classificar o lead. Vale avisar no
// dry-run, do mesmo jeito que a conta sem setor de cliente aparece na Fase 1.
$semCanal = $pdo->query(
    'SELECT a.id, a.nome
       FROM accounts a
      WHERE NOT EXISTS (SELECT 1 FROM clientes_origens o WHERE o.account_id = a.id AND o.ativo = 1)
        AND EXISTS (SELECT 1 FROM cards c WHERE c.account_id = a.id AND c.deleted_at IS NULL)'
)->fetchAll(\PDO::FETCH_ASSOC);

if ($semCanal !== []) {
    linha();
    linha('  ATENCAO: contas com prospeccao e SEM catalogo de canal ativo.');
    linha('  Nessas o seletor de canal aparece vazio (nao quebra, so fica vazio):');
    foreach ($semCanal as $a) {
        linha("    · conta #{$a['id']}  {$a['nome']}");
    }
}

linha();
linha("  alteracoes: $feitas   ja existentes: $puladas");
linha($dryRun ? '  DRY-RUN concluido. Rode sem --dry-run para aplicar.' : '  Migration 127 aplicada.');
