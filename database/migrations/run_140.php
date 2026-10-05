<?php
/**
 * Migration 140: setor do lead e gestor do setor.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do cliente Fleetiflow em 05/10/2026: cada lead fica ligado a um setor
 * (Concessionária, Locação, e os que vierem com novas prospecções), e cada setor
 * tem um gestor. O setor já existia na CONVERSA (whatsapp_chats.team_id, o botão
 * "Setor" do Chat), mas não no card, e o robô de prospecção avisa o CRM às vezes
 * antes de a conversa existir: sem coluna no card, o setor se perdia.
 *
 *   - cards.team_id: o setor do lead. A conversa e o card andam juntos: escolher
 *     o setor na conversa atualiza o card, e conversa que se liga a um card herda
 *     o setor dele (App\WhatsAppAgente\WhatsAppMessage::linkChat).
 *   - teams.gestor_user_id: o usuário responsável pelo setor. NULL = sem gestor.
 *
 * Só cria as colunas (e os índices). Quem preenche é a tela de Setores, o Chat e
 * /api/whatsapp/sdr_etapa.php (campo `setor`).
 *
 * IDEMPOTENTE. Não altera dado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_140.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_140.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function temColuna(\PDO $pdo, string $tabela, string $coluna): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$tabela, $coluna]);
    return (int) $st->fetchColumn() > 0;
}

linha('== Migration 140: cards.team_id e teams.gestor_user_id ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$passos = [
    ['cards', 'team_id',
     "ALTER TABLE cards
        ADD COLUMN team_id INT NULL DEFAULT NULL,
        ADD KEY idx_cards_account_team (account_id, team_id)"],
    ['teams', 'gestor_user_id',
     "ALTER TABLE teams
        ADD COLUMN gestor_user_id INT NULL DEFAULT NULL AFTER descricao,
        ADD KEY idx_teams_gestor (gestor_user_id)"],
];

foreach ($passos as [$tabela, $coluna, $sql]) {
    if (temColuna($pdo, $tabela, $coluna)) {
        linha("   $tabela.$coluna ja existe: nada a fazer.");
        continue;
    }
    linha('   ' . preg_replace('/\s+/', ' ', $sql));
    if ($dryRun) {
        linha('   (dry-run) nao aplicado.');
        continue;
    }
    $pdo->exec($sql);
    linha('   aplicado.');
}
