<?php
/**
 * Migration 159: ambientes da Inovaize (Empresa, Comercial, Software, Digital) e a
 * operação de Digital no modelo do Trello.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido da Inovaize em 20/10/2026 (ver app/Ambientes/README.md). Só acrescenta:
 * tabela nova e coluna nova que nasce vazia. Nenhum dado existente muda.
 *
 *   user_ambientes            o último ambiente usado por pessoa
 *   task_boards.area          área responsável pelo quadro (software, digital,
 *                             empresa, comercial); NULL = quadro de sempre
 *   task_boards.cliente_id    o cliente do quadro (o mesmo cadastro em todas as áreas)
 *   task_boards.modelo        como as colunas se organizam (etapas, episódios,
 *                             campanhas, demandas, suporte)
 *   task_entregas_digital     a entrega de um cartão: tipo (post, vídeo...) e os
 *                             estados próprios de produção, aprovação, entrega e
 *                             publicação, cada um com a data em que mudou
 *   task_participantes        responsáveis além do principal (tasks.responsavel_id)
 *   task_arquivos_esperados   o material que a entrega precisa; recebido quando
 *                             chega um arquivo (do Drive ou enviado pela tela)
 *   crm_cliente_valores.area  de que área é a receita (software, digital, outro):
 *                             o total da Empresa soma cada linha uma vez só
 *   crm_cliente_frequencias   frequência contratada (6 vídeos por mês, 3 posts por
 *                             semana) para o previsto x realizado
 *   crm_campanhas             campanhas de tráfego pago por cliente
 *   cards.area_servico        o que o lead quer comprar (software, digital, ambos),
 *                             para o Comercial acompanhar as duas frentes
 *   projetos.area             área do projeto da carteira (NULL = software)
 *
 * IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_159.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_159.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 159' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$tabelas = [
    'user_ambientes' => "CREATE TABLE user_ambientes (
        user_id        INT NOT NULL PRIMARY KEY,
        account_id     INT NOT NULL,
        ambiente       VARCHAR(20) NOT NULL,
        atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_useramb_conta (account_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'task_entregas_digital' => "CREATE TABLE task_entregas_digital (
        task_id          INT NOT NULL PRIMARY KEY,
        account_id       INT NOT NULL,
        cliente_id       INT NULL,
        tipo             VARCHAR(30) NOT NULL COMMENT 'post, video, reels, carrossel, story, arte, artigo, campanha, outro',
        producao         VARCHAR(20) NOT NULL DEFAULT 'a_fazer' COMMENT 'a_fazer, em_producao, pronto',
        aprovacao        VARCHAR(20) NOT NULL DEFAULT 'nao_enviado' COMMENT 'nao_enviado, aguardando, aprovado, ajustes',
        entrega          VARCHAR(20) NOT NULL DEFAULT 'pendente' COMMENT 'pendente, entregue',
        publicacao       VARCHAR(20) NOT NULL DEFAULT 'nao_se_aplica' COMMENT 'nao_se_aplica, pendente, agendado, publicado',
        competencia      DATE NULL COMMENT 'primeiro dia do mês a que a entrega pertence',
        producao_em      DATETIME NULL,
        aprovacao_em     DATETIME NULL,
        entregue_em      DATETIME NULL,
        publicado_em     DATETIME NULL,
        criado_em        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_tentdig_conta (account_id, competencia),
        KEY idx_tentdig_cliente (account_id, cliente_id, competencia)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'task_participantes' => "CREATE TABLE task_participantes (
        task_id     INT NOT NULL,
        user_id     INT NOT NULL,
        account_id  INT NOT NULL,
        criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (task_id, user_id),
        KEY idx_tpart_user (account_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'task_arquivos_esperados' => "CREATE TABLE task_arquivos_esperados (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        account_id     INT NOT NULL,
        task_id        INT NOT NULL,
        nome           VARCHAR(160) NOT NULL,
        drive_file_id  VARCHAR(128) NULL,
        arquivo_nome   VARCHAR(255) NULL,
        arquivo_link   VARCHAR(500) NULL,
        recebido_em    DATETIME NULL,
        recebido_por   INT NULL,
        criado_por     INT NULL,
        criado_em      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_tarqesp_task (task_id),
        KEY idx_tarqesp_conta (account_id, recebido_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'crm_cliente_frequencias' => "CREATE TABLE crm_cliente_frequencias (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        account_id  INT NOT NULL,
        cliente_id  INT NOT NULL,
        tipo        VARCHAR(30) NOT NULL COMMENT 'mesmos tipos de task_entregas_digital',
        quantidade  SMALLINT NOT NULL,
        periodo     VARCHAR(10) NOT NULL DEFAULT 'mensal' COMMENT 'mensal, semanal',
        inicio      DATE NOT NULL,
        fim         DATE NULL,
        criado_por  INT NULL,
        criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_clifreq (account_id, cliente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    'crm_campanhas' => "CREATE TABLE crm_campanhas (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        account_id      INT NOT NULL,
        cliente_id      INT NOT NULL,
        nome            VARCHAR(160) NOT NULL,
        plataforma      VARCHAR(20) NOT NULL COMMENT 'meta, google, tiktok, linkedin, outra',
        objetivo        VARCHAR(160) NULL,
        verba_mensal    DECIMAL(12,2) NULL,
        inicio          DATE NULL,
        fim             DATE NULL,
        status          VARCHAR(20) NOT NULL DEFAULT 'planejada' COMMENT 'planejada, ativa, pausada, encerrada',
        link            VARCHAR(500) NULL,
        responsavel_id  INT NULL,
        board_id        INT NULL COMMENT 'quadro de produção da campanha, se houver',
        observacao      VARCHAR(500) NULL,
        criado_por      INT NULL,
        criado_em       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_camp_conta (account_id, status),
        KEY idx_camp_cliente (account_id, cliente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$colunas = [
    ['task_boards', 'area', "ALTER TABLE task_boards ADD COLUMN area VARCHAR(20) NULL COMMENT 'software, digital, empresa, comercial; NULL = quadro de sempre', ADD KEY idx_taskboards_area (account_id, area)"],
    ['task_boards', 'cliente_id', "ALTER TABLE task_boards ADD COLUMN cliente_id INT NULL, ADD KEY idx_taskboards_cliente (account_id, cliente_id)"],
    ['task_boards', 'modelo', "ALTER TABLE task_boards ADD COLUMN modelo VARCHAR(20) NULL COMMENT 'etapas, episodios, campanhas, demandas, suporte'"],
    ['crm_cliente_valores', 'area', "ALTER TABLE crm_cliente_valores ADD COLUMN area VARCHAR(20) NULL COMMENT 'software, digital, outro'"],
    ['cards', 'area_servico', "ALTER TABLE cards ADD COLUMN area_servico VARCHAR(20) NULL COMMENT 'software, digital, ambos'"],
    ['projetos', 'area', "ALTER TABLE projetos ADD COLUMN area VARCHAR(20) NULL COMMENT 'software, digital, empresa; NULL = software'"],
];

$stT = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
foreach ($tabelas as $nome => $sql) {
    $stT->execute([$nome]);
    if ((int) $stT->fetchColumn() > 0) { echo "  = $nome já existe\n"; continue; }
    echo "  + $nome\n";
    if (!$dryRun) $pdo->exec($sql);
}

$stC = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
foreach ($colunas as [$tabela, $coluna, $sql]) {
    $stT->execute([$tabela]);
    if ((int) $stT->fetchColumn() === 0) { echo "  ! $tabela não existe (pulado)\n"; continue; }
    $stC->execute([$tabela, $coluna]);
    if ((int) $stC->fetchColumn() > 0) { echo "  = $tabela.$coluna já existe\n"; continue; }
    echo "  + $tabela.$coluna\n";
    if (!$dryRun) $pdo->exec($sql);
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
