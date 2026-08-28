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
            try { $key = (string)\App\Core\Crypto::decrypt($enc); }
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

            // B3 auto-provisionamento do 2o fator (cracha): gera o webhook_token AGORA,
            // ANTES do setWebhook, e o injeta no servico. Como o $hook aponta pro nosso
            // webhook.php, a blindagem do EvolutionApiService anexa o header X-Webhook-Token
            // ao envelope -> a Evolution ja nasce mandando o cracha (Fase B automatica).
            // O modo estrito (Fase C) e ligado depois, sozinho, pelo reconciliador do
            // whatsapp_health_tick, quando confirmar que o cracha esta chegando limpo.
            $webhookToken = bin2hex(random_bytes(32)); // 256 bits, mesma convencao da apikey
            $model->saveSetting($accountId, 'webhook_token', $webhookToken);
            self::logCrachaEvent($pdo, $accountId, 'webhook_token_autogen', ['instance' => $name, 'by' => 'provisioning']);

            // Webhook canonico com ?token (auth da propria instancia) + cracha via blindagem.
            $acctEvo = new \EvolutionApiService([
                'evolution_base_url' => $base,
                'evolution_api_key'  => (string)$apikey,
                'evolution_instance' => $name,
                'webhook_token'      => $webhookToken, // blindagem le daqui e anexa X-Webhook-Token
            ]);
            $hookUrl = $hook . (strpos($hook, '?') === false ? '?' : '&') . 'token=' . urlencode((string)$apikey);
            $acctEvo->setWebhook($name, $hookUrl);

            // Linha local da instancia.
            $inst = $model->findOrCreate($name, $accountName !== '' ? $accountName : $name, $accountId);

            // Autorização de canal: a conta vira DONA do canal (full perms). É o que
            // a camada WhatsAppChannelAccessService usa como base (deny-by-default).
            $channelId = (int)($inst['id'] ?? 0);
            if ($channelId > 0) {
                \WhatsAppChannelAccessService::grant($pdo, $channelId, $accountId, 'owner', [], null);
                self::ensureAgentConfig($pdo, $accountId, $channelId, $accountName);
            }

            return ['success' => true, 'instance' => $name, 'channel_id' => $channelId];
        } catch (\Throwable $e) {
            error_log('[WhatsAppProvisioningService] ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Onboarding ZERO-TOQUE do agente de IA: cria o agent_config da conta JA configurado
     * mas DESLIGADO (enabled=0). A conta nasce pronta (webhook no Yuris + agente no banco,
     * herdando prompt+chave GLOBAIS do Master) e a unica acao do cliente e clicar "Ativar".
     * O bot so responde com enabled=1 E instancia conectada (gate do webhook). Best-effort,
     * idempotente (UNIQUE em whatsapp_instance_id) — nunca derruba o provisionamento.
     */
    private static function ensureAgentConfig(\PDO $pdo, int $accountId, int $channelId, string $accountName): void
    {
        try {
            $has = $pdo->prepare("SELECT id FROM agent_configs WHERE whatsapp_instance_id = ? LIMIT 1");
            $has->execute([$channelId]);
            if ($has->fetchColumn()) return; // ja existe — nao recria

            $tipo = '';
            try {
                $ts = $pdo->prepare("SELECT tipo FROM accounts WHERE id = ? LIMIT 1");
                $ts->execute([$accountId]);
                $tipo = (string)$ts->fetchColumn();
            } catch (\Throwable $_) {}
            $branchId = ($tipo === 'filial') ? $accountId : null;

            // Defaults seguros: provedor/modelo padrao, escritorio = nome da conta, agente OFF.
            // prompt/chave ficam vazios de proposito -> o motor usa o prompt mestre + a
            // Security Key GLOBAIS do Master. Areas: vazio -> motor cai no catalogo completo.
            $pdo->prepare(
                "INSERT INTO agent_configs
                   (account_id, branch_id, whatsapp_instance_id, name, enabled, status, provider, model,
                    api_key_enc, prompt, max_questions, office_name, office_description,
                    office_information_json, behavior_json, handoff_config_json, usage_limits_json,
                    initial_message, closing_message, urgency_message, handoff_message, updated_by)
                 VALUES (?,?,?,?,0,'inactive','openai','gpt-4o-mini',
                         NULL,NULL,6,?,NULL,
                         NULL,NULL,NULL,NULL,
                         NULL,NULL,NULL,NULL,NULL)"
            )->execute([$accountId, $branchId, $channelId, '', $accountName]);
        } catch (\Throwable $e) {
            error_log('[WhatsAppProvisioningService] ensureAgentConfig: ' . $e->getMessage());
        }
    }

    /**
     * Registra um evento do ciclo de vida do cracha em ai_agent_events (best-effort),
     * pra a aba "Provisionamento (Cracha)" do Master mostrar o que foi feito/executado.
     * NUNCA lanca (o provisionamento nao pode falhar por causa de telemetria).
     */
    private static function logCrachaEvent(\PDO $pdo, int $accountId, string $code, array $detail = []): void
    {
        try {
            $f = __DIR__ . '/AiIntake/AgentEvent.php';
            // is_file antes do require: require de arquivo ausente e fatal NAO-capturavel por
            // try/catch, e o provisionamento NAO pode quebrar por causa de telemetria.
            if (!class_exists('App\\WhatsAppAgente\\AiIntake\\AgentEvent', false) && is_file($f)) {
                require_once $f;
            }
            if (class_exists('App\\WhatsAppAgente\\AiIntake\\AgentEvent', false)) {
                \App\WhatsAppAgente\AiIntake\AgentEvent::log($pdo, $code, $detail, 'info', $accountId, null, null);
            }
        } catch (\Throwable $_) { /* telemetria e best-effort */ }
    }
}
