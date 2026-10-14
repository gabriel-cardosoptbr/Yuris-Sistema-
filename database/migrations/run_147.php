<?php
/**
 * Migration 147: Projetos ganha subprojetos, contas e acessos.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido da Inovaize (14/10/2026), sobre a aba Projetos (migration 146):
 *
 *   - projeto é uma pasta própria no menu, e dentro dela cada projeto; um
 *     projeto (Arte Piedosa) junta os subprojetos dele (streaming, site, B2B):
 *     `projetos.parent_id`;
 *   - quanto cada conta cadastrada no projeto paga e consome, e o custo por
 *     usuário dela: `projeto_contas`;
 *   - os links, o login e o acesso master de cada projeto: `projeto_acessos`,
 *     com a senha cifrada (App\Core\Crypto), revelada só sob pedido e auditada.
 *
 *   projeto_contas    uma linha por conta/cliente dentro do projeto (os
 *                     escritórios do Yuris, as contas da plataforma Fleetiflow):
 *                     plano, situação, receita mensal (NULL = a preencher),
 *                     usuários, peso no rateio do custo do projeto, custo direto
 *                     (o que só ela consome, ex. IA) e métricas da última
 *                     sincronização (JSON). `origem` diz se veio da tela ou do
 *                     script de sincronização.
 *   projeto_acessos   rótulo, endereço, usuário e senha cifrada (NULL = sem senha
 *                     guardada).
 *
 * Exclusão lógica nas duas. account_id nas duas: toda leitura e escrita filtra
 * por conta. IDEMPOTENTE. Não altera dado existente.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_147.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_147.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function tabelaExiste(\PDO $pdo, string $tabela): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$tabela]);
    return (int) $st->fetchColumn() > 0;
}

function colunaExiste(\PDO $pdo, string $tabela, string $coluna): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$tabela, $coluna]);
    return (int) $st->fetchColumn() > 0;
}

linha('== Migration 147: projetos.parent_id, projeto_contas e projeto_acessos ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

if (!tabelaExiste($pdo, 'projetos')) {
    linha('   ERRO: a tabela projetos nao existe. Rode a migration 146 antes.');
    exit(1);
}

if (colunaExiste($pdo, 'projetos', 'parent_id')) {
    linha('   projetos.parent_id: ja existe, nada a fazer.');
} else {
    linha('   projetos.parent_id: sera criada.');
    if (!$dryRun) {
        $pdo->exec("ALTER TABLE projetos ADD COLUMN parent_id INT NULL DEFAULT NULL AFTER account_id, ADD KEY idx_proj_pai (account_id, parent_id)");
        linha('   projetos.parent_id: criada.');
    }
}

$tabelas = [
    'projeto_contas' => "CREATE TABLE projeto_contas (
        id INT NOT NULL AUTO_INCREMENT,
        account_id INT NOT NULL,
        projeto_id INT NOT NULL,
        nome VARCHAR(150) NOT NULL,
        identificador VARCHAR(80) NULL,
        plano VARCHAR(80) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ativa',
        receita_mensal DECIMAL(12,2) NULL DEFAULT NULL,
        moeda CHAR(3) NOT NULL DEFAULT 'BRL',
        usuarios INT NULL DEFAULT NULL,
        peso_consumo DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        custo_direto DECIMAL(12,2) NULL DEFAULT NULL,
        inicio DATE NULL DEFAULT NULL,
        metricas TEXT NULL,
        origem VARCHAR(10) NOT NULL DEFAULT 'manual',
        sincronizado_em DATETIME NULL DEFAULT NULL,
        observacao TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_pconta_projeto (account_id, projeto_id, deleted_at),
        KEY idx_pconta_ident (account_id, projeto_id, identificador)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'projeto_acessos' => "CREATE TABLE projeto_acessos (
        id INT NOT NULL AUTO_INCREMENT,
        account_id INT NOT NULL,
        projeto_id INT NOT NULL,
        rotulo VARCHAR(120) NOT NULL,
        url VARCHAR(255) NULL,
        usuario VARCHAR(150) NULL,
        senha_enc TEXT NULL,
        observacao TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_pacesso_projeto (account_id, projeto_id, deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($tabelas as $nome => $sql) {
    if (tabelaExiste($pdo, $nome)) {
        linha("   $nome: ja existe, nada a fazer.");
        continue;
    }
    linha("   $nome: sera criada.");
    if ($dryRun) continue;
    $pdo->exec($sql);
    linha("   $nome: criada.");
}
linha();
linha($dryRun ? '   (dry-run) nada aplicado.' : '   pronto.');
