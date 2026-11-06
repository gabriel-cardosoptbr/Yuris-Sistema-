<?php
/**
 * scripts/manutencao/restaura_updated_at_setores.php: desfaz o efeito colateral da primeira
 * rodada de setor_dos_disparos.php (06/11/2026 02:07 UTC, conta 116).
 *
 * Aquela rodada fez `UPDATE cards SET team_id = ?` sem prender o updated_at, e a coluna tem
 * ON UPDATE CURRENT_TIMESTAMP: 619 leads e 42 conversas ganharam "alterado agora". Na
 * Prospecção isso vira "último contato: hoje" e joga os cards para o topo da coluna
 * (Card::list ordena por updated_at DESC); na tela Desempenho, tira leads de "parados".
 *
 * O QUE RESTAURA (só quem está na janela --de/--ate E num setor, da conta informada)
 *   lead      GREATEST(created_at, última linha do card_history): é o que o updated_at
 *             valia, porque toda escrita relevante do card grava histórico;
 *   conversa  last_message_at (senão created_at).
 * card_history só é lido, nunca alterado.
 *
 * IDEMPOTENTE: depois de rodar, ninguém mais está na janela. Só confere por padrão.
 *
 * USO
 *   php scripts/manutencao/restaura_updated_at_setores.php --conta=116 --de="2026-11-06 02:07:00" --ate="2026-11-06 02:08:00"
 *   ...mesmos argumentos mais --aplicar
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$o = getopt('', ['conta:', 'de:', 'ate:', 'aplicar']);
$conta = (int) ($o['conta'] ?? 0);
$de = (string) ($o['de'] ?? '');
$ate = (string) ($o['ate'] ?? '');
$aplicar = isset($o['aplicar']);
$data = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
if ($conta <= 0 || !preg_match($data, $de) || !preg_match($data, $ate) || $de >= $ate) {
    exit("Informe --conta=ID --de=\"AAAA-MM-DD HH:MM:SS\" --ate=\"AAAA-MM-DD HH:MM:SS\" (horário do banco, UTC em produção).\n");
}
$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$acc = \App\Master\Account::findById($conta);
if (!$acc) exit("Conta $conta não existe.\n");
echo "conta #{$acc['id']} \"{$acc['nome']}\", janela $de a $ate " . ($aplicar ? '(GRAVANDO)' : '(conferência, nada é gravado)') . "\n";

$noSetor = 'c.team_id IS NOT NULL';
$cards = "SELECT COUNT(*) FROM cards c WHERE c.account_id = ? AND c.updated_at >= ? AND c.updated_at < ? AND $noSetor";
$chats = 'SELECT COUNT(*) FROM whatsapp_chats w WHERE w.account_id = ? AND w.updated_at >= ? AND w.updated_at < ? AND w.team_id IS NOT NULL';
$conta3 = fn(string $sql) => (function () use ($pdo, $sql, $conta, $de, $ate) { $s = $pdo->prepare($sql); $s->execute([$conta, $de, $ate]); return (int) $s->fetchColumn(); })();
echo "leads na janela:     " . $conta3($cards) . "\nconversas na janela: " . $conta3($chats) . "\n";
if (!$aplicar) { echo "\nNada gravado. Use --aplicar.\n"; exit; }

$pdo->beginTransaction();
try {
    $a = $pdo->prepare("UPDATE cards c SET c.updated_at = GREATEST(c.created_at, COALESCE((SELECT MAX(h.created_at) FROM card_history h WHERE h.card_id = c.id), c.created_at))
                         WHERE c.account_id = ? AND c.updated_at >= ? AND c.updated_at < ? AND $noSetor");
    $a->execute([$conta, $de, $ate]);
    $b = $pdo->prepare('UPDATE whatsapp_chats w SET w.updated_at = COALESCE(w.last_message_at, w.created_at)
                         WHERE w.account_id = ? AND w.updated_at >= ? AND w.updated_at < ? AND w.team_id IS NOT NULL');
    $b->execute([$conta, $de, $ate]);
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    exit('Erro, nada gravado: ' . $e->getMessage() . "\n");
}
echo "\nRestaurados: " . $a->rowCount() . ' lead(s), ' . $b->rowCount() . " conversa(s). Ainda na janela: " . $conta3($cards) . " lead(s).\n";
