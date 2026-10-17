<?php
/**
 * Migration 150: índices da listagem de cards do funil (Prospecção).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Em 16/10/2026 a Central de Prospecção da Fleetiflow levava de 3 a 4 segundos
 * para abrir. Medido no navegador: a coluna "Novos leads" (496 cards) demorava
 * 1,5 s só no servidor, cerca de 3 ms por card. A consulta de listagem
 * (App\Prospeccao\Card::list) faz quatro subconsultas por card em
 * whatsapp_chats.linked_card_id, e essa coluna não tinha índice: cada card
 * varria a tabela de conversas inteira quatro vezes.
 *
 * Três índices, todos lidos a cada abertura do funil:
 *
 *   whatsapp_chats.linked_card_id   as quatro subconsultas de Card::list
 *                                   (JID, última mensagem, de quem, chip)
 *   cards.coluna_id                 o filtro por coluna da mesma listagem
 *   crm_agendamentos.card_id        AgendaDoLead::proximas, que busca por
 *                                   card_id sem account_id (o índice que
 *                                   existia começa por account_id)
 *
 * Só cria índice. Não altera dado, não muda coluna. IDEMPOTENTE: índice que
 * já existe é pulado.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_150.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_150.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function indiceExiste(\PDO $pdo, string $tabela, string $indice): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $st->execute([$tabela, $indice]);
    return (int) $st->fetchColumn() > 0;
}

function tabelaExiste(\PDO $pdo, string $tabela): bool
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$tabela]);
    return (int) $st->fetchColumn() > 0;
}

linha('== Migration 150: índices da listagem de cards do funil ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$indices = [
    ['whatsapp_chats',   'idx_wachat_linked_card', 'ALTER TABLE whatsapp_chats ADD KEY idx_wachat_linked_card (linked_card_id)'],
    ['cards',            'idx_cards_coluna',       'ALTER TABLE cards ADD KEY idx_cards_coluna (coluna_id)'],
    ['crm_agendamentos', 'idx_agenda_card_id',     'ALTER TABLE crm_agendamentos ADD KEY idx_agenda_card_id (card_id)'],
];

$criados = 0;
foreach ($indices as [$tabela, $indice, $sql]) {
    if (!tabelaExiste($pdo, $tabela)) {
        linha("   - $tabela.$indice: tabela nao existe neste banco, pulando.");
        continue;
    }
    if (indiceExiste($pdo, $tabela, $indice)) {
        linha("   - $tabela.$indice: ja existe, pulando.");
        continue;
    }
    if ($dryRun) {
        linha("   - $tabela.$indice: seria criado -> $sql");
        continue;
    }
    $pdo->exec($sql);
    $criados++;
    linha("   + $tabela.$indice: criado.");
}

linha();
linha($dryRun ? '   DRY-RUN concluido.' : "   Concluido: $criados indice(s) criado(s).");
