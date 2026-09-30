<?php
/**
 * Migration 132: whatsapp_identidades.perfil_comercial_em.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Conta comercial do WhatsApp manda pushName vazio, e a conversa aparecia na
 * lista só com o telefone. App\WhatsAppAgente\PerfilComercial passa a deduzir o
 * nome a partir do perfil comercial que a Evolution entrega (descrição, site).
 *
 * Essa consulta custa uma chamada à Evolution por contato. Sem registrar que
 * ela já foi feita, TODA abertura do Chat consultaria de novo cada conversa sem
 * nome (numa conta com 60 conversas sem nome, 60 chamadas por abertura). Esta
 * coluna guarda quando o perfil foi consultado, tenha dado nome ou não.
 *
 * NULL = nunca consultado. Só acrescenta coluna: nenhuma linha existente muda.
 *
 * IDEMPOTENTE. Não altera dado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_132.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_132.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function temColuna(\PDO $pdo, string $t, string $c): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$t, $c]);
    return (bool) $st->fetchColumn();
}

linha('== Migration 132: whatsapp_identidades.perfil_comercial_em ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

if (!temColuna($pdo, 'whatsapp_identidades', 'id')) {
    linha('  [ERRO] tabela whatsapp_identidades nao existe (migration 129 nao aplicada).');
    exit(1);
}

if (temColuna($pdo, 'whatsapp_identidades', 'perfil_comercial_em')) {
    linha('  [ja existe] whatsapp_identidades.perfil_comercial_em');
} elseif ($dryRun) {
    linha('  [criaria]   whatsapp_identidades.perfil_comercial_em');
} else {
    $pdo->exec("ALTER TABLE `whatsapp_identidades`
        ADD COLUMN `perfil_comercial_em` DATETIME NULL
        COMMENT 'quando o perfil comercial foi consultado na Evolution (com ou sem nome); NULL = nunca'
        AFTER `ultima_mensagem_em`");
    linha('  [criada]    whatsapp_identidades.perfil_comercial_em');
}

linha();
linha($dryRun ? 'Dry-run concluido. Nada foi gravado.' : 'Concluido.');
