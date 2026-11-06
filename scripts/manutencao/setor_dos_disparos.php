<?php
/**
 * scripts/manutencao/setor_dos_disparos.php: dá setor aos leads que vieram de disparo
 * e ainda estão "Sem setor", na conta da edição CRM informada.
 *
 * Pedido do dono da Fleet (05/11/2026): os setores apareciam vazios, e os disparos já
 * tinham sido feitos. Cada origem vira um setor de ORIGEM (descrição começa com
 * Team::PREFIXO_ORIGEM): quando o robô qualificar o lead e informar o setor de verdade
 * (Concessionária, Locação...), o lead sai do setor de origem (sdr_etapa.php).
 *
 * AS ORIGENS (a prova é a descrição do card, gravada na criação)
 *   Grupo Veículos SP   "Lista Grupo Veículos SP ..."           planilha do grupo de WhatsApp
 *   Prospecção ativa    "Lead da prospecção ativa pelo WhatsApp" aberto pelo robô do disparo
 * O que não tem uma dessas descrições fica como está e é contado no fim.
 *
 * REGRAS
 *   - só lead SEM setor: setor já escolhido nunca é trocado;
 *   - a conversa ligada ao lead, se não tem setor, recebe o mesmo (como
 *     WhatsAppMessage::sincronizarSetorComCard);
 *   - tudo dentro da conta informada, e só edição CRM;
 *   - IDEMPOTENTE: rodar de novo só marca o que ainda está sem setor.
 * Só confere por padrão.
 *
 * USO
 *   php scripts/manutencao/setor_dos_disparos.php --conta=116            (confere)
 *   php scripts/manutencao/setor_dos_disparos.php --conta=116 --aplicar  (grava)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Usuarios\Team;

$o = getopt('', ['conta:', 'aplicar']);
$conta = (int) ($o['conta'] ?? 0);
$aplicar = isset($o['aplicar']);
if ($conta <= 0) exit("Informe --conta=ID.\n");

$ORIGENS = [
    'Grupo Veículos SP' => ['cor' => '#0EA5E9', 'like' => 'Lista Grupo Veículos SP%',               'nota' => 'contatos do grupo de veículos de São Paulo'],
    'Prospecção ativa'  => ['cor' => '#F59E0B', 'like' => 'Lead da prospecção ativa pelo WhatsApp%', 'nota' => 'leads abertos pelo robô do disparo'],
];

$pdo = Database::getConnection();
$acc = \App\Master\Account::findById($conta);
if (!$acc) exit("Conta $conta não existe.\n");
if (\App\Master\Account::moduloJuridicoDisponivel($acc)) exit("Conta $conta tem módulo jurídico (Yuris): este script é da edição CRM. Nada feito.\n");
if (!Team::temColuna('cards', 'team_id')) exit("Falta a migration 140 (cards.team_id).\n");
echo "conta #{$acc['id']} \"{$acc['nome']}\" " . ($aplicar ? '(GRAVANDO)' : '(conferência, nada é gravado)') . "\n\n";

$totalCards = 0; $totalChats = 0;
foreach ($ORIGENS as $nome => $cfg) {
    $st = $pdo->prepare('SELECT id FROM cards WHERE account_id = ? AND deleted_at IS NULL AND team_id IS NULL AND descricao LIKE ?');
    $st->execute([$conta, $cfg['like']]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));

    $st = $pdo->prepare('SELECT id FROM teams WHERE account_id = ? AND nome = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
    $st->execute([$conta, $nome]);
    $setorId = (int) ($st->fetchColumn() ?: 0);
    echo sprintf("%-20s %4d lead(s) sem setor; setor %s\n", $nome, count($ids), $setorId ? "#$setorId (já existe)" : 'a criar');
    if (!$ids) continue;
    $totalCards += count($ids);
    if (!$aplicar) continue;

    if ($setorId === 0) {
        $setorId = Team::create(['account_id' => $conta, 'nome' => $nome, 'cor' => $cfg['cor'], 'descricao' => Team::PREFIXO_ORIGEM . ': ' . $cfg['nota']]);
        \App\Master\Account::audit($conta, 'team.created', ['user_id' => null, 'entidade' => 'team', 'entidade_id' => $setorId,
            'detalhes' => ['nome' => $nome, 'cor' => $cfg['cor'], 'origem' => 'script setor_dos_disparos']]);
    }
    $pdo->beginTransaction();
    try {
        $marca = $pdo->prepare('UPDATE cards SET team_id = ? WHERE id = ? AND account_id = ? AND team_id IS NULL');
        $chat  = $pdo->prepare('UPDATE whatsapp_chats SET team_id = ? WHERE linked_card_id = ? AND account_id = ? AND team_id IS NULL');
        foreach ($ids as $id) {
            $marca->execute([$setorId, $id, $conta]);
            $chat->execute([$setorId, $id, $conta]);
            $totalChats += $chat->rowCount();
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        exit('Erro, nada gravado neste setor: ' . $e->getMessage() . "\n");
    }
}

$restam = (int) $pdo->query("SELECT COUNT(*) FROM cards WHERE account_id = $conta AND deleted_at IS NULL AND team_id IS NULL")->fetchColumn();
echo "\n" . ($aplicar ? 'Marcados' : 'Marcaria') . ": $totalCards lead(s)" . ($aplicar ? ", $totalChats conversa(s)" : '') . ". Seguem sem setor: $restam" . ($aplicar ? '' : " (antes de marcar: " . ($restam) . ')') . ".\n";
