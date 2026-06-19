<?php
/**
 * WhatsAppProvisioningService — provisionamento (idempotente, best-effort) de
 * instância WhatsApp na Evolution para uma conta (matriz / advogado / filial).
 *
 * Centraliza o que antes vivia inline no endpoint whatsapp_config.php (action=provision),
 * pra ser reutilizado também na criação de conta/filial (auto-instância).
 *
 * Regras:
 *  - NUNCA lança: retorna sempre um array ['success'=>bool, ...]. O caller decide o que fazer.
 *  - Idempotente: se a conta já tem evolution_instance + evolution_api_key, não recria.
 *  - Sem dependência de sessão/HTTP. Recebe o PDO do caller.
 *  - Lê a apikey REAL da instância via fetchInstances (campo `token`) — Evolution v2.3.6
 *    não devolve a chave num formato fixo no create.
 */

require_once __DIR__ . '/EvolutionApiService.php';
require_once __DIR__ . '/../Models/WhatsAppInstance.php';
require_once __DIR__ . '/../Helpers/Crypto.php';

class WhatsAppProvisioningService
{
    const GK_BASE      = 'evolution_global_base_url';
    const GK_HOOK      = 'evolution_global_webhook_url';
    const GK_KEY       = 'evolution_global_admin_key_enc';
    const HOOK_DEFAULT = 'https://yuris.com.br/api/whatsapp/webhook.php';

    private static function appGet(\PDO $pdo, string $k): ?string
    {
        $s = $pdo->prepare('SELECT config_value FROM app_settings WHERE config_key = ?');
        $s->execute([$k]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string)$v;
    }

    /** Config global decifrada: [base_url, webhook_url, admin_key]. */
    public static function globalCfg(\PDO $pdo): array
    {
        $base = (string)(self::appGet($pdo, self::GK_BASE) ?? '');
        $hook = (string)(self::appGet($pdo, self::GK_HOOK) ?? self::HOOK_DEFAULT);
        $enc  = self::appGet($pdo, self::GK_KEY);
        $key  = '';
        if ($enc) {
            try { $key = (string)\App\Helpers\Crypto::decrypt($enc); }
            catch (\Throwable $_) { $key = ''; }
        }
        return [$base, $hook, $key];
    }

    /** Há config global suficiente (base + admin key) pra criar instâncias? */
    public static function isGloballyConfigured(\PDO $pdo): bool
    {
        [$base, , $key] = self::globalCfg($pdo);
        return $base !== '' && $key !== '';
    }

    /**
     * Provisiona a instância da conta. Idempotente e best-effort.
     *
     * @return array{success:bool, instance?:string, skipped?:bool, error?:string}
     */
    public static function provision(\PDO $pdo, int $accountId, string $accountName): array
    {
        try {
            if ($accountId <= 0) {
                return ['success' => false, 'error' => 'accountId invalido'];
            }

            $model = new \WhatsAppInstance();

            // Idempotencia: ja configurada? nao recria.
            $existing = $model->getSettings($accountId);
            if (!empty($existing['evolution_instance']) && !empty($existing['evolution_api_key'])) {
                return ['success' => true, 'instance' => (string)$existing['evolution_instance'], 'skipped' => true];
            }

            [$base, $hook, $adminKey] = self::globalCfg($pdo);
            if ($base === '' || $adminKey === '') {
                return ['success' => false, 'error' => 'config global Evolution ausente (base_url/admin key)'];
            }

            // Nome unico: slug do nome + id.
            $slug = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', $accountName));
            if ($slug === '') $slug = 'conta';
            $name  = substr($slug, 0, 24) . '-' . $accountId;
            $token = bin2hex(random_bytes(18));

            $globalEvo = new \EvolutionApiService(['evolution_base_url' => $base, 'evolution_api_key' => $adminKey]);
            $res = $globalEvo->createInstance($name, '', $token);
            if (!empty($res['_error'])) {
                return ['success' => false, 'error' => 'Evolution recusou criar a instancia: ' . $res['_error']];
            }

            // A apikey REAL e o campo `token` do item em fetchInstances; fallback no create-response.
            $apikey = null;
            try {
                foreach ((array)$globalEvo->fetchInstances() as $it) {
                    $nm = $it['name'] ?? $it['instanceName'] ?? ($it['instance']['instanceName'] ?? ($it['instance']['name'] ?? null));
                    if ($nm === $name) {
                        $apikey = $it['token'] ?? $it['apikey'] ?? ($it['instance']['token'] ?? null);
                        break;
                    }
                }
            } catch (\Throwable $_) { /* fallback abaixo */ }
            if (!$apikey) {
                $apikey = $res['hash']['apikey'] ?? (is_string($res['hash'] ?? null) ? $res['hash'] : null)
                       ?? $res['instance']['apikey'] ?? $res['instance']['token'] ?? $token;
            }

            // Salva config da conta (per-tenant).
            $model->saveSetting($accountId, 'evolution_base_url', $base);
            $model->saveSetting($accountId, 'evolution_instance', $name);
            $model->saveSetting($accountId, 'evolution_api_key',  (string)$apikey);
            $model->saveSetting($accountId, 'webhook_url',        $hook);

            // Webhook canonico com ?token (auth da propria instancia).
            $acctEvo = new \EvolutionApiService(['evolution_base_url' => $base, 'evolution_api_key' => (string)$apikey, 'evolution_instance' => $name]);
            $hookUrl = $hook . (strpos($hook, '?') === false ? '?' : '&') . 'token=' . urlencode((string)$apikey);
            $acctEvo->setWebhook($name, $hookUrl);

            // Linha local da instancia.
            $model->findOrCreate($name, $accountName !== '' ? $accountName : $name, $accountId);

            return ['success' => true, 'instance' => $name];
        } catch (\Throwable $e) {
            error_log('[WhatsAppProvisioningService] ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
