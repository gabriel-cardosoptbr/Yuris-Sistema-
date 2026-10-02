<?php
namespace App\WhatsAppAgente;
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

            $model = new \App\WhatsAppAgente\WhatsAppInstance();

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

            $globalEvo = new \App\WhatsAppAgente\EvolutionApiService(['evolution_base_url' => $base, 'evolution_api_key' => $adminKey]);
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
            $acctEvo = new \App\WhatsAppAgente\EvolutionApiService([
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
                \App\WhatsAppAgente\WhatsAppChannelAccessService::grant($pdo, $channelId, $accountId, 'owner', [], null);
                self::ensureAgentConfig($pdo, $accountId, $channelId, $accountName);
            }

            return ['success' => true, 'instance' => $name, 'channel_id' => $channelId];
        } catch (\Throwable $e) {
            error_log('[WhatsAppProvisioningService] ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Liga à conta, como PRIMEIRO número, uma instância que JÁ EXISTE na Evolution
     * (o QR já foi lido fora do sistema). É o provision() sem o createInstance:
     * grava base, instância e chave em whatsapp_settings, gera o webhook_token,
     * aponta o webhook da instância para o nosso, cria a linha local, dá a posse
     * do canal à conta e o agent_configs desligado.
     *
     * Recusa, sem mexer em nada, quando: a conta já tem número (use
     * adicionarNumero), a instância não existe, a chave ou o nome já são de outra
     * conta, ou a instância já manda eventos para outro endereço (o webhook é um
     * só por instância: sobrescrever cortaria quem recebe hoje, um n8n por exemplo).
     *
     * $simular = true só confere e devolve o que faria.
     *
     * @return array{success:bool, simulado?:bool, instance?:string, channel_id?:int, telefone?:string, error?:string}
     */
    public static function vincularExistente(\PDO $pdo, int $accountId, string $accountName, string $instanceName, bool $simular = true): array
    {
        try {
            if ($accountId <= 0 || $instanceName === '') return ['success' => false, 'error' => 'Conta ou instância inválida.'];
            $model = new \App\WhatsAppAgente\WhatsAppInstance();
            $atual = $model->getSettings($accountId);
            if (!empty($atual['evolution_instance']) && !empty($atual['evolution_api_key'])) {
                return ['success' => false, 'error' => "A conta já tem o número {$atual['evolution_instance']}. Número extra é pelo adicionarNumero()."];
            }

            [$base, $hook, $adminKey] = self::globalCfg($pdo);
            if ($base === '' || $adminKey === '') return ['success' => false, 'error' => 'Config global da Evolution ausente.'];
            $globalEvo = new \App\WhatsAppAgente\EvolutionApiService(['evolution_base_url' => $base, 'evolution_api_key' => $adminKey]);

            $item = null;
            foreach ((array) $globalEvo->fetchInstances() as $it) {
                $nm = $it['name'] ?? $it['instanceName'] ?? ($it['instance']['instanceName'] ?? ($it['instance']['name'] ?? null));
                if ($nm === $instanceName) { $item = $it; break; }
            }
            if ($item === null) return ['success' => false, 'error' => "Instância \"{$instanceName}\" não existe na Evolution."];
            $apikey = (string) ($item['token'] ?? $item['apikey'] ?? ($item['instance']['token'] ?? ''));
            if ($apikey === '') return ['success' => false, 'error' => 'A Evolution não devolveu a chave da instância.'];
            $estado   = (string) ($item['connectionStatus'] ?? ($item['instance']['status'] ?? ''));
            $telefone = (string) preg_replace('/@.*$/', '', (string) ($item['ownerJid'] ?? $item['number'] ?? ''));
            $perfil   = (string) ($item['profileName'] ?? '');

            $conflito = $model->apiKeyConflict($apikey, $accountId);
            if ($conflito) return ['success' => false, 'error' => 'A chave dessa instância já é de outra conta (' . implode(',', $conflito) . ').'];
            $st = $pdo->prepare('SELECT DISTINCT account_id FROM whatsapp_instances WHERE instance_name = ? AND account_id <> ?');
            $st->execute([$instanceName, $accountId]);
            $outras = $st->fetchAll(\PDO::FETCH_COLUMN);
            if ($outras) return ['success' => false, 'error' => 'Essa instância já está registrada em outra conta (' . implode(',', $outras) . ').'];

            $acctEvo = new \App\WhatsAppAgente\EvolutionApiService([
                'evolution_base_url' => $base, 'evolution_api_key' => $apikey, 'evolution_instance' => $instanceName,
            ]);
            $wh = $acctEvo->getWebhook($instanceName);
            if (!empty($wh["_error"]) || (isset($wh["_http"]) && (int) $wh["_http"] >= 400)) {
                return ["success" => false, "error" => "Não consegui ler o webhook atual da instância; nada foi feito."];
            }
            $urlAtual = (string) ($wh['url'] ?? ($wh['webhook']['url'] ?? ''));
            $nosso = strtok($hook, '?');
            if ($urlAtual !== '' && strtok($urlAtual, '?') !== $nosso) {
                return ['success' => false, 'error' => 'A instância já manda eventos para outro endereço (' . parse_url($urlAtual, PHP_URL_HOST) . '). Não sobrescrevo.'];
            }

            if ($simular) {
                return ['success' => true, 'simulado' => true, 'instance' => $instanceName, 'telefone' => $telefone,
                        'estado' => $estado, 'webhook_atual' => $urlAtual === '' ? 'nenhum' : 'o nosso'];
            }

            $model->saveSetting($accountId, 'evolution_base_url', $base);
            $model->saveSetting($accountId, 'evolution_instance', $instanceName);
            $model->saveSetting($accountId, 'evolution_api_key',  $apikey);
            $model->saveSetting($accountId, 'webhook_url',        $hook);
            $webhookToken = bin2hex(random_bytes(32));
            $model->saveSetting($accountId, 'webhook_token', $webhookToken);
            self::logCrachaEvent($pdo, $accountId, 'webhook_token_autogen', ['instance' => $instanceName, 'by' => 'vincular_existente']);

            $acctEvo = new \App\WhatsAppAgente\EvolutionApiService([
                'evolution_base_url' => $base, 'evolution_api_key' => $apikey,
                'evolution_instance' => $instanceName, 'webhook_token' => $webhookToken,
            ]);
            $hookUrl = $hook . (strpos($hook, '?') === false ? '?' : '&') . 'token=' . urlencode($apikey);
            $acctEvo->setWebhook($instanceName, $hookUrl);

            $inst = $model->findOrCreate($instanceName, $perfil !== '' ? $perfil : $instanceName, $accountId);
            $channelId = (int) ($inst['id'] ?? 0);
            if ($channelId > 0) {
                if ($estado === 'open') $model->updateStatus($channelId, 'open', ['phone' => $telefone, 'profile_name' => $perfil ?: null]);
                \App\WhatsAppAgente\WhatsAppChannelAccessService::grant($pdo, $channelId, $accountId, 'owner', [], null);
                self::ensureAgentConfig($pdo, $accountId, $channelId, $accountName);
            }
            return ['success' => true, 'instance' => $instanceName, 'channel_id' => $channelId, 'telefone' => $telefone, 'estado' => $estado];
        } catch (\Throwable $e) {
            error_log('[WhatsAppProvisioningService] vincularExistente: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Teto de números por conta: cada um é uma sessão aberta na Evolution. */
    const MAX_NUMEROS_POR_CONTA = 10;

    /**
     * Cria MAIS UM número (instância) na Evolution para uma conta que já tem o
     * primeiro. Conta sem nenhum ainda passa pelo provision() normal, e o
     * número criado ganha o nome pedido.
     *
     * Diferença para o primeiro número: a chave do número novo NÃO vai para as
     * settings da conta (lá continua a do primeiro, que é a chave de roteamento
     * do webhook). Ela fica em whatsapp_instances.evolution_token, e o webhook
     * do número novo aponta com ?token=<chave da CONTA> — assim os eventos dele
     * chegam no mesmo tenant, e o nome da instância no evento diz de qual número
     * são. Ver WhatsAppInstance::cfgDoCanal.
     *
     * @return array{success:bool, channel_id?:int, instance?:string, error?:string}
     */
    public static function adicionarNumero(\PDO $pdo, int $accountId, string $accountName, string $nomeDoNumero): array
    {
        try {
            if ($accountId <= 0) return ['success' => false, 'error' => 'Conta inválida.'];
            $nomeDoNumero = trim(mb_substr($nomeDoNumero, 0, 150));
            $model = new \App\WhatsAppAgente\WhatsAppInstance();
            $cfg   = $model->getSettings($accountId);

            // Conta ainda sem número nenhum: é o provisionamento de sempre.
            if (empty($cfg['evolution_instance']) || empty($cfg['evolution_api_key'])) {
                $r = self::provision($pdo, $accountId, $accountName);
                if (!empty($r['success']) && !empty($r['channel_id']) && $nomeDoNumero !== '') {
                    $pdo->prepare('UPDATE whatsapp_instances SET display_name = ? WHERE id = ? AND account_id = ?')
                        ->execute([$nomeDoNumero, (int)$r['channel_id'], $accountId]);
                }
                return $r;
            }

            $qtd = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_instances WHERE account_id = ?');
            $qtd->execute([$accountId]);
            if ((int)$qtd->fetchColumn() >= self::MAX_NUMEROS_POR_CONTA) {
                return ['success' => false, 'error' => 'Limite de ' . self::MAX_NUMEROS_POR_CONTA . ' números por conta atingido.'];
            }

            [$base, $hook, $adminKey] = self::globalCfg($pdo);
            if ($base === '' || $adminKey === '') {
                return ['success' => false, 'error' => 'A Evolution não está configurada no Painel Master (URL e chave administrativa).'];
            }

            // Nome único NA EVOLUTION (o servidor é compartilhado entre contas):
            // slug + id da conta + sufixo aleatório.
            $slug = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', $accountName)) ?: 'conta';
            $name = substr($slug, 0, 20) . '-' . $accountId . '-' . bin2hex(random_bytes(3));
            $token = bin2hex(random_bytes(18));

            $globalEvo = new \App\WhatsAppAgente\EvolutionApiService(['evolution_base_url' => $base, 'evolution_api_key' => $adminKey]);
            $res = $globalEvo->createInstance($name, '', $token);
            if (!empty($res['_error'])) {
                return ['success' => false, 'error' => 'A Evolution recusou criar o número: ' . $res['_error']];
            }

            // Chave REAL da instância (mesma leitura do provision()).
            $apikey = null;
            try {
                foreach ((array)$globalEvo->fetchInstances() as $it) {
                    $nm = $it['name'] ?? $it['instanceName'] ?? ($it['instance']['instanceName'] ?? ($it['instance']['name'] ?? null));
                    if ($nm === $name) { $apikey = $it['token'] ?? $it['apikey'] ?? ($it['instance']['token'] ?? null); break; }
                }
            } catch (\Throwable $_) {}
            if (!$apikey) {
                $apikey = $res['hash']['apikey'] ?? (is_string($res['hash'] ?? null) ? $res['hash'] : null)
                       ?? $res['instance']['apikey'] ?? $res['instance']['token'] ?? $token;
            }

            // Webhook: mesma URL da conta, com a chave da CONTA no ?token (roteia o
            // tenant) e o crachá da conta (2º fator), como o primeiro número.
            $hookConta = (string)($cfg['webhook_url'] ?? '') ?: $hook;
            $hookUrl   = $hookConta . (strpos($hookConta, '?') === false ? '?' : '&') . 'token=' . urlencode((string)$cfg['evolution_api_key']);
            $evoNumero = new \App\WhatsAppAgente\EvolutionApiService([
                'evolution_base_url' => (string)($cfg['evolution_base_url'] ?? '') ?: $base,
                'evolution_api_key'  => (string)$apikey,
                'evolution_instance' => $name,
                'webhook_token'      => (string)($cfg['webhook_token'] ?? ''),
            ]);
            $evoNumero->setWebhook($name, $hookUrl);

            $inst = $model->findOrCreate($name, $nomeDoNumero !== '' ? $nomeDoNumero : $name, $accountId);
            $channelId = (int)($inst['id'] ?? 0);
            if ($channelId <= 0) return ['success' => false, 'error' => 'Não foi possível gravar o número.'];
            $pdo->prepare('UPDATE whatsapp_instances SET evolution_token = ? WHERE id = ? AND account_id = ?')
                ->execute([(string)$apikey, $channelId, $accountId]);

            \App\WhatsAppAgente\WhatsAppChannelAccessService::grant($pdo, $channelId, $accountId, 'owner', [], null);
            self::ensureAgentConfig($pdo, $accountId, $channelId, $accountName);
            self::logCrachaEvent($pdo, $accountId, 'numero_adicional_criado', ['instance' => $name]);

            return ['success' => true, 'channel_id' => $channelId, 'instance' => $name];
        } catch (\Throwable $e) {
            error_log('[WhatsAppProvisioningService] adicionarNumero: ' . $e->getMessage());
            return ['success' => false, 'error' => 'Não foi possível criar o número agora. Tente de novo em instantes.'];
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
