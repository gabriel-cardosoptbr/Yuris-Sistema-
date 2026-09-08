<?php
/**
 * preenche_nome_foto_contatos.php — preenche nome e foto das conversas a partir
 * da agenda da Evolution.
 *
 * POR QUE ISSO EXISTE
 *
 * Ate 08/09/2026 `EvolutionApiService::findContacts()` chamava
 * `/contact/findContacts`, que a Evolution v2 responde 404. A busca de contatos
 * NUNCA funcionou, em conta nenhuma, e falhava calado (o array de erro do 404
 * chegava ao chamador como se fosse a lista). Resultado: conversa 1:1 ficava so
 * com o numero, sem nome e sem foto.
 *
 * O commit 12449a2 corrigiu o path (e mais dois bugs no sync: o JID vem em
 * `remoteJid`, nao em `id`; e contato sem nome era descartado junto com a foto).
 * Mas o sync so roda pela tela, com sessao e CSRF. Este script aplica a mesma
 * correcao ao que ja esta gravado, sem depender de alguem clicar em Sincronizar.
 *
 * O QUE FAZ
 *   - le a agenda com findContacts (ja corrigido)
 *   - indexa por remoteJid E por digitos do telefone (cobre @lid ja resolvido
 *     para telefone e variacao de sufixo @c.us / @s.whatsapp.net)
 *   - preenche `profile_pic_url` das conversas que estao sem foto
 *   - preenche `contact_name` das que estao sem nome, quando a agenda tem nome
 *   - espelha em `whatsapp_contacts`
 *
 * O QUE NAO FAZ
 *   - nao sobrescreve nome que o usuario renomeou a mao (`is_manual_name`)
 *   - nao sobrescreve nome ou foto que ja existem
 *   - nao inventa nome: no formato @lid o WhatsApp NAO expoe o nome (confirmado
 *     em fetchProfile, whatsappNumbers e findContacts, todos com name ""), entao
 *     essas conversas seguem sem nome ate alguem renomear
 *   - nao toca em mensagem nenhuma
 *
 * Uso:
 *   php scripts/manutencao/preenche_nome_foto_contatos.php --dry-run
 *   php scripts/manutencao/preenche_nome_foto_contatos.php --apply --account=83
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\WhatsAppInstance;
use App\WhatsAppAgente\EvolutionApiService;

$args   = $argv ?? [];
$dryRun = in_array('--dry-run', $args, true);
$apply  = in_array('--apply', $args, true);
$soConta = null;
foreach ($args as $a) { if (str_starts_with($a, '--account=')) $soConta = (int)substr($a, 10); }

if (!$dryRun && !$apply) { fwrite(STDERR, "Escolha --dry-run ou --apply.\n"); exit(2); }
if ($dryRun && $apply)   { fwrite(STDERR, "--dry-run e --apply sao exclusivos.\n"); exit(2); }

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$model = new WhatsAppInstance();

$sqlCanais = "SELECT id, account_id, instance_name FROM whatsapp_instances WHERE status = 'open'"
           . ($soConta ? " AND account_id = " . (int)$soConta : "");
$canais = $pdo->query($sqlCanais)->fetchAll(\PDO::FETCH_ASSOC);
echo "canais conectados: " . count($canais) . "\n\n";

$totFoto = 0; $totNome = 0;
foreach ($canais as $ch) {
    $iid = (int)$ch['id'];
    $acc = (int)$ch['account_id'];
    echo "== canal {$iid} ({$ch['instance_name']}, conta {$acc}) ==\n";

    $cfg = $model->getSettings($acc);
    if (empty($cfg['evolution_base_url']) || empty($cfg['evolution_api_key'])) {
        echo "  sem credencial, pulando\n\n"; continue;
    }
    $evo = new EvolutionApiService($cfg);
    $lista = $evo->findContacts((string)($cfg['evolution_instance'] ?? $ch['instance_name']));
    $lista = is_array($lista) && isset($lista[0]) ? $lista : [];
    echo "  agenda: " . count($lista) . " contatos\n";
    if (!$lista) { echo "\n"; continue; }

    // indexa por JID exato e por digitos
    $porJid = []; $porDigito = [];
    foreach ($lista as $c) {
        $jid = $c['remoteJid'] ?? null;
        if (!$jid || !str_contains((string)$jid, '@')) continue;
        $nome = $c['pushName'] ?? $c['name'] ?? null;
        if ($nome !== null && preg_match('/^\d{12,}$/', (string)$nome)) $nome = null;
        $info = ['nome' => ($nome !== null && trim((string)$nome) !== '') ? $nome : null,
                 'foto' => $c['profilePicUrl'] ?? null];
        if ($info['nome'] === null && $info['foto'] === null) continue;
        $porJid[$jid] = $info;
        $d = preg_replace('/[^0-9]/', '', explode('@', (string)$jid)[0]);
        if ($d && strlen($d) >= 10 && !str_ends_with((string)$jid, '@lid')) { $porDigito[$d] = $info; }
    }

    $chats = $pdo->prepare("SELECT id, remote_jid, contact_name, is_manual_name, profile_pic_url
                              FROM whatsapp_chats WHERE instance_id = ?");
    $chats->execute([$iid]);
    $upFoto = $pdo->prepare("UPDATE whatsapp_chats SET profile_pic_url = ? WHERE id = ?");
    $upNome = $pdo->prepare("UPDATE whatsapp_chats SET contact_name = ? WHERE id = ?");
    $nFoto = 0; $nNome = 0;

    foreach ($chats->fetchAll(\PDO::FETCH_ASSOC) as $c) {
        $jid = (string)$c['remote_jid'];
        $d   = preg_replace('/[^0-9]/', '', explode('@', $jid)[0]);
        $info = $porJid[$jid] ?? ($porDigito[$d] ?? null);
        if (!$info) continue;

        if (empty($c['profile_pic_url']) && !empty($info['foto'])) {
            if ($apply) { $upFoto->execute([$info['foto'], (int)$c['id']]); }
            $nFoto++;
        }
        $semNome = ($c['contact_name'] === null || $c['contact_name'] === '');
        if ($semNome && (int)($c['is_manual_name'] ?? 0) === 0 && !empty($info['nome'])) {
            if ($apply) { $upNome->execute([$info['nome'], (int)$c['id']]); }
            $nNome++;
        }
    }
    printf("  fotos a preencher: %-4s nomes a preencher: %s\n\n", $nFoto, $nNome);
    $totFoto += $nFoto; $totNome += $nNome;
}

echo ($dryRun ? "DRY-RUN (nada escrito): " : "APLICADO: ")
   . "$totFoto fotos, $totNome nomes\n";
