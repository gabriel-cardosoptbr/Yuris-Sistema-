<?php
/**
 * Migration 144: integração do CRM com a plataforma Fleetiflow (edição CRM).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono do produto em 13/10/2026: o cliente da plataforma conversa
 * com o card do CRM, SÓ por API, ligado por um código único. O vendedor vê
 * plano, módulos, uso e dinheiro da conta e faz o upsell; a conta pede pela
 * plataforma e o pedido cai no card. Ver app/Plataforma/README.md.
 *
 *   plataforma_conexoes  a ligação desta conta do CRM com a plataforma: o
 *                        endereço da API, a chave e o segredo dos eventos
 *                        (os dois cifrados com App\Core\Crypto)
 *   plataforma_vinculos  qual conta da plataforma (código FLT-XXXX-XXXX) é
 *                        qual lead/cliente daqui, com o último resumo lido
 *                        da API guardado para a carteira e para quando a
 *                        plataforma estiver fora do ar
 *   plataforma_eventos   os eventos recebidos, únicos por id: o mesmo evento
 *                        entregue duas vezes não vira duas notas no card
 *
 * IDEMPOTENTE. Não altera dado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_144.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_144.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function tabelaExiste(\PDO $pdo, string $t): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (int) $st->fetchColumn() > 0;
}

linha('Migration 144' . ($dryRun ? ' (dry-run)' : ''));

$tabelas = [
    'plataforma_conexoes' => "
        CREATE TABLE plataforma_conexoes (
          id                      INT NOT NULL AUTO_INCREMENT,
          account_id              INT NOT NULL,
          api_url                 VARCHAR(300) NOT NULL,
          chave_cifrada           TEXT NOT NULL,
          segredo_eventos_cifrado TEXT NOT NULL,
          nome_conexao            VARCHAR(120) NULL,
          escopos                 VARCHAR(100) NULL,
          crm_url                 VARCHAR(300) NULL,
          conectado_por           INT NULL,
          conectado_em            DATETIME NOT NULL,
          ultimo_evento_em        DATETIME NULL,
          created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_plataforma_conexao_conta (account_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'plataforma_vinculos' => "
        CREATE TABLE plataforma_vinculos (
          id            INT NOT NULL AUTO_INCREMENT,
          account_id    INT NOT NULL,
          codigo_conta  VARCHAR(20) NOT NULL,
          card_id       INT NULL,
          cliente_id    INT NULL,
          nome_conta    VARCHAR(191) NULL,
          cnpj          VARCHAR(20) NULL,
          plano         VARCHAR(120) NULL,
          situacao      VARCHAR(30) NULL,
          resumo_json   MEDIUMTEXT NULL,
          resumo_em     DATETIME NULL,
          created_by    INT NULL,
          created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_plataforma_vinculo_codigo (account_id, codigo_conta),
          KEY idx_plataforma_vinculo_card (account_id, card_id),
          KEY idx_plataforma_vinculo_cliente (account_id, cliente_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    'plataforma_eventos' => "
        CREATE TABLE plataforma_eventos (
          id            INT NOT NULL AUTO_INCREMENT,
          account_id    INT NOT NULL,
          evento_id     VARCHAR(64) NOT NULL,
          tipo          VARCHAR(60) NOT NULL,
          codigo_conta  VARCHAR(20) NULL,
          card_id       INT NULL,
          payload       MEDIUMTEXT NOT NULL,
          recebido_em   DATETIME NOT NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_plataforma_evento (account_id, evento_id),
          KEY idx_plataforma_evento_conta (account_id, codigo_conta, recebido_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
];

foreach ($tabelas as $nome => $sql) {
    if (tabelaExiste($pdo, $nome)) { linha("$nome: já existe"); continue; }
    linha("$nome: criar");
    if (!$dryRun) { $pdo->exec($sql); linha("$nome: criada"); }
}

linha('ok');
