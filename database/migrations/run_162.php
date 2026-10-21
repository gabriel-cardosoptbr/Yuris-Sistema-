<?php
/**
 * Migration 162: a ficha do cliente de cada ambiente (Inovaize, 21/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono do produto em 21/10/2026: cada ambiente abre o cliente como
 * um sistema à parte. No Digital a ficha é de projeto (entregas, postagem,
 * Instagram, Drive), no Software é do sistema do cliente, no Comercial é da
 * venda e na Empresa é tudo. Para isso:
 *
 *   crm_cliente_perfil                o perfil do cliente por área: redes
 *                                     (Instagram, TikTok, YouTube...), site e
 *                                     briefing da marca (Digital); endereço do
 *                                     sistema, repositório, stack, hospedagem e
 *                                     notas técnicas (Software)
 *   task_entregas_digital.link_publicacao   o link do post publicado
 *   projetos.cliente_id               o projeto (pasta Projetos) ligado ao
 *                                     cadastro do cliente; o campo `cliente`
 *                                     continua sendo o texto livre de sempre
 *
 * Só tabela e colunas novas, nada existente muda. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_162.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_162.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 162' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
$st->execute(['crm_cliente_perfil']);
if ((int) $st->fetchColumn() > 0) {
    echo "  = crm_cliente_perfil já existe\n";
} else {
    echo "  + crm_cliente_perfil\n";
    if (!$dryRun) {
        $pdo->exec("CREATE TABLE crm_cliente_perfil (
            account_id      INT NOT NULL,
            cliente_id      INT NOT NULL,
            instagram       VARCHAR(100) NULL COMMENT 'só o usuário, sem @',
            tiktok          VARCHAR(100) NULL,
            youtube         VARCHAR(255) NULL,
            facebook        VARCHAR(255) NULL,
            linkedin        VARCHAR(255) NULL,
            site            VARCHAR(255) NULL,
            briefing        TEXT NULL COMMENT 'tom, público, cores, o que não fazer',
            sistema_url     VARCHAR(255) NULL,
            repositorio     VARCHAR(255) NULL,
            stack           VARCHAR(255) NULL,
            hospedagem      VARCHAR(255) NULL,
            notas_tecnicas  TEXT NULL,
            atualizado_por  INT NULL,
            atualizado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (account_id, cliente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }
}

$colunas = [
    ['task_entregas_digital', 'link_publicacao', "ALTER TABLE task_entregas_digital ADD COLUMN link_publicacao VARCHAR(500) NULL COMMENT 'link do post publicado'"],
    ['projetos', 'cliente_id', "ALTER TABLE projetos ADD COLUMN cliente_id INT NULL COMMENT 'cadastro do cliente (clientes.id)', ADD KEY idx_projetos_cliente (account_id, cliente_id)"],
];
$col = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
foreach ($colunas as [$tabela, $coluna, $sql]) {
    $st->execute([$tabela]);
    if ((int) $st->fetchColumn() === 0) { echo "  ! $tabela não existe (rode a migration dela antes)\n"; continue; }
    $col->execute([$tabela, $coluna]);
    if ((int) $col->fetchColumn() > 0) { echo "  = $tabela.$coluna já existe\n"; continue; }
    echo "  + $tabela.$coluna\n";
    if (!$dryRun) $pdo->exec($sql);
}

echo $dryRun ? "Nada gravado (dry-run).\n" : "Pronto.\n";
