<?php
/**
 * repara_mensagens_vazias.php — conserta o historico que o parser antigo estragou.
 *
 * O PROBLEMA (corrigido no codigo pelo commit 85ce7ea)
 *
 * `extractMessageContent()` terminava em `return ['text', null, ...]`, entao
 * QUALQUER tipo que o parser nao conhecesse virava uma mensagem de texto VAZIA.
 * Duas consequencias no historico ja gravado:
 *
 *   1. `senderKeyDistributionMessage` — a troca de chave de criptografia que o
 *      WhatsApp manda em TODO grupo — virou linha no historico e sobrescreveu o
 *      `last_message_content` da conversa. E o "..." que aparece na lista.
 *   2. Texto que EXISTIA no payload foi descartado, porque estava num campo que
 *      o parser nao lia (templateMessage, templateButtonReply, interactive).
 *      Sao mensagens reais que nunca apareceram na tela.
 *
 * O QUE ESTE SCRIPT FAZ
 *
 * Reprocessa o `raw_payload` das linhas vazias com o parser NOVO e, para cada uma:
 *   - protocolo puro          -> APAGA a linha (nao era mensagem de ninguem)
 *   - texto recuperado        -> preenche `message_content`
 *   - rotulo ([Album] etc)    -> preenche `message_content`
 *   - continua sem texto      -> deixa como esta, nao inventa conteudo
 *
 * Depois RECALCULA `last_message_content` / `last_message_type` /
 * `last_message_at` / `last_message_from_me` de cada conversa afetada, a partir
 * da mensagem mais recente que sobrou.
 *
 * SEGURANCA
 *   - `--dry-run` mostra o que faria sem escrever nada. RODE ISSO PRIMEIRO.
 *   - Antes de apagar, grava um backup em JSON com a linha inteira, para dar
 *     para restaurar. O caminho e impresso no final.
 *   - So toca em linhas com `message_type='text'` E conteudo vazio E que tenham
 *     `raw_payload`. Mensagem com texto nunca e tocada.
 *   - IDEMPOTENTE: rodar de novo nao faz nada, porque as linhas ja foram tratadas.
 *
 * Uso:
 *   php scripts/manutencao/repara_mensagens_vazias.php --dry-run
 *   php scripts/manutencao/repara_mensagens_vazias.php --apply
 *   php scripts/manutencao/repara_mensagens_vazias.php --apply --instance=14
 *
 * `--database=NOME` aponta para outro banco (mesmo host/usuario do .env). E o que
 * permite ensaiar num banco DESCARTAVEL com os casos ruins injetados de proposito,
 * antes de encostar em producao — a licao que a migration 111 deixou.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\WhatsAppAgente\WhatsAppWebhookParser as P;
use App\Core\Database;

$args    = $argv ?? [];
$dryRun  = in_array('--dry-run', $args, true);
$apply   = in_array('--apply', $args, true);
$instance = null;
$database = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--instance=')) { $instance = (int)substr($a, 11); }
    if (str_starts_with($a, '--database=')) { $database = substr($a, 11); }
}

if (!$dryRun && !$apply) {
    fwrite(STDERR, "Escolha --dry-run (mostra o que faria) ou --apply (executa).\n");
    exit(2);
}
if ($dryRun && $apply) {
    fwrite(STDERR, "--dry-run e --apply sao mutuamente exclusivos.\n");
    exit(2);
}

if ($database !== null) {
    // banco alternativo (ensaio): mesmo host/credencial do .env, outro dbname
    $cfg = require __DIR__ . '/../../config/database.php';
    $pdo = new \PDO(
        "mysql:host={$cfg['host']};dbname={$database};charset={$cfg['charset']}",
        $cfg['user'], $cfg['pass'],
        [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
    );
    echo "*** banco alternativo: {$database} ***

";
} else {
    $pdo = Database::getConnection();
}
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$where  = "message_type = 'text' AND (message_content IS NULL OR message_content = '') AND raw_payload IS NOT NULL";
$params = [];
if ($instance) { $where .= " AND instance_id = ?"; $params[] = $instance; }

$st = $pdo->prepare("SELECT id, instance_id, remote_jid, raw_payload FROM whatsapp_messages WHERE $where ORDER BY id");
$st->execute($params);
$linhas = $st->fetchAll(\PDO::FETCH_ASSOC);

echo "linhas vazias encontradas: " . count($linhas) . ($instance ? " (canal $instance)" : " (todos os canais)") . "\n\n";
if (!$linhas) { echo "nada a fazer.\n"; exit(0); }

$apagar = [];   // ids de protocolo
$textar = [];   // id => texto recuperado
$manter = 0;

foreach ($linhas as $l) {
    $j = json_decode((string)$l['raw_payload'], true);
    $message = $j['message'] ?? null;
    if (!is_array($message)) { $manter++; continue; }
    [$tipo, $conteudo] = P::extractMessageContent($message);
    if ($tipo === 'ignore') { $apagar[] = (int)$l['id']; continue; }
    if (is_string($conteudo) && $conteudo !== '') { $textar[(int)$l['id']] = $conteudo; continue; }
    $manter++;
}

printf("  apagar (protocolo, nao era mensagem): %s\n", count($apagar));
printf("  preencher texto recuperado/rotulo:    %s\n", count($textar));
printf("  deixar como esta:                     %s\n\n", $manter);

// conversas afetadas, para recalcular o preview depois
$jids = [];
foreach ($linhas as $l) { $jids[$l['instance_id'] . '|' . $l['remote_jid']] = [(int)$l['instance_id'], (string)$l['remote_jid']]; }
printf("  conversas com preview a recalcular:   %s\n\n", count($jids));

if ($dryRun) {
    echo "DRY-RUN: nada foi escrito.\n";
    $amostra = array_slice($textar, 0, 5, true);
    if ($amostra) {
        echo "\namostra do texto que seria recuperado:\n";
        foreach ($amostra as $id => $txt) {
            echo "  id=$id  " . str_replace("\n", " / ", mb_substr($txt, 0, 55)) . "\n";
        }
    }
    exit(0);
}

// ── BACKUP das linhas que serao apagadas ────────────────────────────────────
$dir = __DIR__ . '/../../storage/backups';
if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
$arqBackup = $dir . '/mensagens_protocolo_' . date('Ymd_His') . '.json';
if ($apagar) {
    $ph = implode(',', array_fill(0, count($apagar), '?'));
    $stB = $pdo->prepare("SELECT * FROM whatsapp_messages WHERE id IN ($ph)");
    $stB->execute($apagar);
    $dump = $stB->fetchAll(\PDO::FETCH_ASSOC);
    file_put_contents($arqBackup, json_encode($dump, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "backup das linhas a apagar: $arqBackup (" . count($dump) . " linhas)\n";
}

$pdo->beginTransaction();
try {
    // 1) apaga o protocolo, em lotes
    $apagadas = 0;
    foreach (array_chunk($apagar, 500) as $lote) {
        $ph = implode(',', array_fill(0, count($lote), '?'));
        $stD = $pdo->prepare("DELETE FROM whatsapp_messages WHERE id IN ($ph)");
        $stD->execute($lote);
        $apagadas += $stD->rowCount();
    }

    // 2) preenche o texto recuperado
    $stU = $pdo->prepare("UPDATE whatsapp_messages SET message_content = ? WHERE id = ?");
    $preenchidas = 0;
    foreach ($textar as $id => $txt) { $stU->execute([$txt, $id]); $preenchidas += $stU->rowCount(); }

    // 3) recalcula o preview de cada conversa a partir do que sobrou
    $stUlt = $pdo->prepare(
        "SELECT message_content, message_type, created_at, direction
           FROM whatsapp_messages
          WHERE instance_id = ? AND remote_jid = ?
          ORDER BY created_at DESC, id DESC LIMIT 1"
    );
    $stChat = $pdo->prepare(
        "UPDATE whatsapp_chats
            SET last_message_content = ?, last_message_type = ?, last_message_at = ?, last_message_from_me = ?
          WHERE instance_id = ? AND remote_jid = ?"
    );
    $chats = 0;
    foreach ($jids as [$iid, $jid]) {
        $stUlt->execute([$iid, $jid]);
        $u = $stUlt->fetch(\PDO::FETCH_ASSOC);
        if (!$u) { continue; }
        $stChat->execute([
            $u['message_content'], $u['message_type'], $u['created_at'],
            ($u['direction'] === 'outbound' ? 1 : 0), $iid, $jid,
        ]);
        $chats += $stChat->rowCount();
    }

    $pdo->commit();
    echo "\nAPLICADO:\n";
    printf("  linhas de protocolo apagadas: %s\n", $apagadas);
    printf("  mensagens com texto restaurado: %s\n", $preenchidas);
    printf("  conversas com preview recalculado: %s\n", $chats);
    if ($apagar) { echo "\n  para desfazer o DELETE, o backup esta em:\n  $arqBackup\n"; }
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERRO, nada foi alterado: " . $e->getMessage() . "\n");
    exit(1);
}
