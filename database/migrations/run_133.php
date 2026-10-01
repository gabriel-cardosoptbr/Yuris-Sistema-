<?php
/**
 * Migration 133: account_marca_arquivos (logo e ícone da marca de cada conta).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * A edição CRM comercial (a mesma da conta Fleetiflow) passa a ser criada pelo
 * Painel Master para qualquer marca: Inovaize, Autodoc, a próxima. Nome, cor e
 * domínio da marca moram em `accounts.configuracoes.marca` (JSON que já existe,
 * sem coluna nova). As IMAGENS moram aqui, e não no disco, por três razões:
 *
 *  1. `public/uploads/` é bloqueada pelo Apache (LGPD P0), e o logo precisa
 *     aparecer na tela de login, sem sessão.
 *  2. Disco dentro do container depende de permissão de escrita e some numa
 *     reconstrução; o banco entra no backup que já existe.
 *  3. Cada imagem tem um `hash` do conteúdo, que vira o endereço público: não
 *     dá para adivinhar o logo de uma conta pelo id, e o navegador pode guardar
 *     a imagem para sempre (trocou o logo, trocou o hash, trocou o endereço).
 *
 * Uma linha por (conta, tipo). Só cria tabela: nenhuma linha existente muda.
 *
 * IDEMPOTENTE. Não altera dado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_133.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_133.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function temTabela(\PDO $pdo, string $t): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (bool) $st->fetchColumn();
}

linha('== Migration 133: account_marca_arquivos ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

if (!temTabela($pdo, 'accounts')) {
    linha('  [ERRO] tabela accounts nao existe.');
    exit(1);
}

if (temTabela($pdo, 'account_marca_arquivos')) {
    linha('  [ja existe] account_marca_arquivos');
} elseif ($dryRun) {
    linha('  [criaria]   account_marca_arquivos');
} else {
    $pdo->exec("CREATE TABLE `account_marca_arquivos` (
        `id`         INT NOT NULL AUTO_INCREMENT,
        `account_id` INT NOT NULL,
        `tipo`       VARCHAR(10) NOT NULL COMMENT 'logo | icone',
        `mime`       VARCHAR(40) NOT NULL,
        `conteudo`   MEDIUMBLOB NOT NULL,
        `hash`       CHAR(40) NOT NULL COMMENT 'sha1 do conteudo: e o endereco publico da imagem',
        `largura`    INT NULL,
        `altura`     INT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uk_conta_tipo` (`account_id`, `tipo`),
        KEY `ix_hash` (`hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
      COMMENT 'logo e icone da marca de cada conta (edicao CRM comercial)'");
    linha('  [criada]    account_marca_arquivos');
}

linha();
linha($dryRun ? 'Dry-run concluido. Nada foi gravado.' : 'Concluido.');
