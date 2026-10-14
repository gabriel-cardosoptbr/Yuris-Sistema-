<?php
/**
 * Migration 146: Projetos (carteira de projetos da conta, com receita e custo).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido da Inovaize (14/10/2026): uma aba Projetos na edição CRM com todos os
 * projetos que a empresa roda, o que cada um consome (servidor, IA, WhatsApp,
 * domínio, licença por usuário...), quanto cada um arrecada e o que ainda falta
 * preencher. Um dossiê vivo por projeto.
 *
 *   projeto_servidores  máquina ou hospedagem (EC2, VPS, Hostinger...) com o
 *                       custo mensal dela. Vários projetos dividem o mesmo
 *                       servidor; o custo é rateado pelo peso de cada um.
 *   projetos            o projeto em si: cliente, tipo, situação, endereço,
 *                       onde roda (servidor_id + servidor_peso), stack,
 *                       repositório, usuários ativos, responsável.
 *   projeto_itens       uma linha por receita ou custo do projeto: categoria,
 *                       fornecedor, valor unitário x quantidade, moeda,
 *                       recorrência (mensal, anual, único) e vigência.
 *                       valor_unitario NULL = "a preencher": o item existe (o
 *                       projeto consome aquilo), só falta o número.
 *
 * Totais, margem, arrecadado e pendências NÃO são gravados: saem dos itens em
 * App\Projetos\Projeto, para nunca desencontrar.
 *
 * Valores em DECIMAL(12,2), nunca float. account_id nas três tabelas: toda
 * leitura e escrita filtra por conta.
 *
 * IDEMPOTENTE. Não altera dado existente.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_146.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_146.php --dry-run
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

linha('== Migration 146: projeto_servidores, projetos e projeto_itens ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

// A ordem importa: projetos referencia projeto_servidores, e itens referencia projetos.
$tabelas = [
    'projeto_servidores' => "CREATE TABLE projeto_servidores (
        id INT NOT NULL AUTO_INCREMENT,
        account_id INT NOT NULL,
        nome VARCHAR(150) NOT NULL,
        provedor VARCHAR(100) NULL,
        endereco VARCHAR(255) NULL,
        valor_mensal DECIMAL(12,2) NULL DEFAULT NULL,
        moeda CHAR(3) NOT NULL DEFAULT 'BRL',
        observacao TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_psrv_conta (account_id, deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'projetos' => "CREATE TABLE projetos (
        id INT NOT NULL AUTO_INCREMENT,
        account_id INT NOT NULL,
        slug VARCHAR(80) NOT NULL,
        nome VARCHAR(150) NOT NULL,
        cliente VARCHAR(150) NULL,
        tipo VARCHAR(20) NOT NULL DEFAULT 'cliente',
        status VARCHAR(20) NOT NULL DEFAULT 'producao',
        descricao TEXT NULL,
        url VARCHAR(255) NULL,
        repositorio VARCHAR(255) NULL,
        stack VARCHAR(255) NULL,
        servidor_id INT NULL DEFAULT NULL,
        servidor_peso SMALLINT UNSIGNED NOT NULL DEFAULT 1,
        usuarios_ativos INT NULL DEFAULT NULL,
        responsavel VARCHAR(150) NULL,
        inicio DATE NULL DEFAULT NULL,
        observacao TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_proj_conta (account_id, deleted_at, status),
        KEY idx_proj_slug (account_id, slug),
        KEY idx_proj_servidor (servidor_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'projeto_itens' => "CREATE TABLE projeto_itens (
        id INT NOT NULL AUTO_INCREMENT,
        account_id INT NOT NULL,
        projeto_id INT NOT NULL,
        natureza VARCHAR(10) NOT NULL,
        categoria VARCHAR(30) NOT NULL,
        descricao VARCHAR(255) NOT NULL,
        fornecedor VARCHAR(150) NULL,
        valor_unitario DECIMAL(12,2) NULL DEFAULT NULL,
        quantidade DECIMAL(10,2) NOT NULL DEFAULT 1.00,
        moeda CHAR(3) NOT NULL DEFAULT 'BRL',
        recorrencia VARCHAR(10) NOT NULL DEFAULT 'mensal',
        inicio DATE NULL DEFAULT NULL,
        fim DATE NULL DEFAULT NULL,
        observacao TEXT NULL,
        created_by INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_pitem_conta (account_id, deleted_at),
        KEY idx_pitem_projeto (projeto_id, deleted_at)
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
