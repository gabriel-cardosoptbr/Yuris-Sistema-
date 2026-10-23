<?php
/**
 * Migration 168: follow-up automático do WhatsApp configurado no CRM (23/10/2026).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Até aqui o follow-up da Fleetiflow morava num fluxo do n8n, com textos e
 * prazos escritos no código do fluxo, e só para quem nunca respondeu. O cliente
 * pediu para controlar isso no sistema (Operação › Follow-up) e para ver no card
 * quando sai o próximo. Ver App\FollowUp.
 *
 * Duas tabelas novas, nenhuma alteração nas existentes:
 *
 *   followup_cadencias  uma cadência da conta: quando começa (gatilho), em quais
 *                       etapas do funil, com quem, horário de envio e os passos
 *                       (espera + texto), guardados em JSON.
 *   followup_envios     cada follow-up que o sistema mandou (ou tentou). A chave
 *                       única (conversa, cadência, início do silêncio, passo)
 *                       impede mandar o mesmo passo duas vezes, até com dois
 *                       workers juntos: a linha nasce `enviando` ANTES do envio.
 *                       Envio que falhou volta a ser tentado (até 3 vezes), só
 *                       se aquele passo ainda for o da vez.
 *
 * IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_168.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_168.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

echo 'Migration 168' . ($dryRun ? ' (dry-run)' : '') . PHP_EOL;

$tabelas = [
    'followup_cadencias' => "CREATE TABLE followup_cadencias (
        id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id      INT NOT NULL,
        nome            VARCHAR(120) NOT NULL,
        ativo           TINYINT(1) NOT NULL DEFAULT 0,
        gatilho         VARCHAR(20) NOT NULL DEFAULT 'sem_resposta',
        com_quem        VARCHAR(20) NOT NULL DEFAULT 'todas',
        etapas          TEXT NULL,
        mover_para      INT NULL,
        inicio_max_dias INT NOT NULL DEFAULT 3,
        horario         TEXT NULL,
        passos          TEXT NOT NULL,
        ordem           INT NOT NULL DEFAULT 0,
        criado_por      INT NULL,
        atualizado_por  INT NULL,
        created_at      DATETIME NOT NULL,
        updated_at      DATETIME NOT NULL,
        KEY idx_followup_cad_conta (account_id, ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    'followup_envios' => "CREATE TABLE followup_envios (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        account_id   INT NOT NULL,
        cadencia_id  INT UNSIGNED NOT NULL,
        passo        INT NOT NULL,
        instance_id  INT NOT NULL,
        remote_jid   VARCHAR(120) NOT NULL,
        card_id      INT NULL,
        ciclo_desde  DATETIME NOT NULL,
        status       VARCHAR(12) NOT NULL DEFAULT 'enviando',
        texto        TEXT NULL,
        wamid        VARCHAR(120) NULL,
        erro         VARCHAR(255) NULL,
        tentativas   INT NOT NULL DEFAULT 1,
        created_at   DATETIME NOT NULL,
        atualizado_em DATETIME NOT NULL,
        enviado_em   DATETIME NULL,
        UNIQUE KEY uk_followup_envio (instance_id, remote_jid, cadencia_id, ciclo_desde, passo),
        KEY idx_followup_envio_conta (account_id, created_at),
        KEY idx_followup_envio_card (card_id),
        KEY idx_followup_envio_inst (instance_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

$existe = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
foreach ($tabelas as $nome => $sql) {
    $existe->execute([$nome]);
    if ((int) $existe->fetchColumn() > 0) { echo "  {$nome}: já existe" . PHP_EOL; continue; }
    if ($dryRun) { echo "  {$nome}: seria criada" . PHP_EOL; continue; }
    $pdo->exec($sql);
    echo "  {$nome}: criada" . PHP_EOL;
}

echo 'Fim.' . PHP_EOL;
