<?php
/**
 * FASE 2 — Reaponta o webhook da instancia da Silvana (#1 "Homologacao") pro Yuris.
 *
 * Reversivel: imprime o webhook ATUAL (backup) ANTES de trocar. Usa o MESMO metodo
 * canonico do app (EvolutionApiService::setWebhook + ?token=<evolution_api_key do tenant>).
 * NAO toca em mais nada (Bruno/n8n intactos).
 *
 * Uso prod: docker exec yuris_app php /var/www/html/scripts/_phase2_repoint_silvana.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$ACCOUNT_ID = 1;                                              // Silvana
$YURIS_HOOK = 'https://yuris.com.br/api/whatsapp/webhook.php';

$pdo = Database::getConnection();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$wi  = new \WhatsAppInstance();
$cfg = $wi->getSettings($ACCOUNT_ID);
$base = $cfg['evolution_base_url'] ?? '';
$key  = $cfg['evolution_api_key'] ?? '';
$inst = $cfg['evolution_instance'] ?? '';
if ($base === '' || $key === '' || $inst === '') {
    fwrite(STDERR, "ABORT: conta {$ACCOUNT_ID} sem config Evolution completa (base/key/instance).\n");
    exit(1);
}

$svc = new \EvolutionApiService($cfg);
$svc->setTimeout(15);

echo "== FASE 2 — repoint webhook (conta #{$ACCOUNT_ID}, instancia '{$inst}') ==\n\n";

// 1) BACKUP do webhook atual (pra rollback exato)
$before    = $svc->getWebhook($inst);
$beforeUrl = $before['url'] ?? $before['webhook']['url'] ?? null;
echo "== BACKUP (webhook ATUAL, antes da troca) ==\n";
echo "url=" . ($beforeUrl ?? '(null)') . "\n";
echo "raw=" . substr(json_encode($before), 0, 300) . "\n\n";

// 2) Monta URL canonica com ?token (igual instances.php / WhatsAppProvisioningService)
$urlFinal = $YURIS_HOOK . (strpos($YURIS_HOOK, '?') === false ? '?' : '&') . 'token=' . urlencode($key);
echo "== APLICANDO webhook -> Yuris ==\n";
echo "url(limpa)=" . $YURIS_HOOK . "\n";
echo "url(final)=" . $YURIS_HOOK . "?token=" . substr($key, 0, 4) . "..." . substr($key, -2) . " (token mascarado)\n";

// 3) Seta com eventos CONFIRMADOS validos nesta versao da Evolution (o default do
//    setWebhook inclui 'GROUPS_UPDATE', que esta versao rejeita -> 400). Estes 4
//    cobrem o essencial: recebidas (dispara o agente + persiste), enviadas/echo,
//    status e contatos. Outros podem ser adicionados depois com seguranca.
$EVENTS = ['MESSAGES_UPSERT', 'MESSAGES_UPDATE', 'SEND_MESSAGE', 'CONTACTS_UPSERT'];
$res = $svc->setWebhook($inst, $urlFinal, $EVENTS);
echo "setWebhook http=" . ($res['_http'] ?? '?') . " raw=" . substr(json_encode($res), 0, 300) . "\n\n";

// 4) Verifica
$after    = $svc->getWebhook($inst);
$urlAfter = $after['url'] ?? $after['webhook']['url'] ?? null;
$evAfter  = $after['events'] ?? $after['webhook']['events'] ?? null;
echo "== VERIFICACAO (apos troca) ==\n";
echo "url agora=" . ($urlAfter ?? '(null)') . "\n";
echo "events=" . (is_array($evAfter) ? implode(',', $evAfter) : ($evAfter ?? '-')) . "\n";
$ok = $urlAfter && stripos($urlAfter, 'yuris') !== false && stripos($urlAfter, 'webhook') !== false;
echo ($ok ? "[OK] webhook agora aponta pro YURIS\n" : "[ATENCAO] nao confirmou Yuris — conferir!\n") . "\n";

// 5) Persiste webhook_url (limpo) no whatsapp_settings, p/ a UI exibir certo
if ($ok) {
    $wi->saveSetting($ACCOUNT_ID, 'webhook_url', $YURIS_HOOK);
    echo "webhook_url salvo em whatsapp_settings (limpo, sem token).\n";
}

// 6) Instrucao de rollback (caso precise)
echo "\n== ROLLBACK (se precisar reverter pro estado anterior) ==\n";
echo "setWebhook('{$inst}', '" . ($beforeUrl ?? '???') . "') com events=[MESSAGES_UPSERT]\n";
echo "== FIM ==\n";
