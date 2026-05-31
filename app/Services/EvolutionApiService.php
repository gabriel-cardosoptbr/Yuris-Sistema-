<?php
/**
 * EvolutionApiService — camada de integração com a Evolution API.
 *
 * Todas as chamadas HTTP são feitas via cURL.
 * As configurações são lidas da tabela whatsapp_settings.
 */
class EvolutionApiService
{
    private string $baseUrl;
    private string $apiKey;
    private string $instanceName;
    private int    $timeout = 20;

    public function __construct(array $settings = [])
    {
        $this->baseUrl      = rtrim($settings['evolution_base_url'] ?? 'http://localhost:8080', '/');
        $this->apiKey       = $settings['evolution_api_key']   ?? '';
        $this->instanceName = $settings['evolution_instance']  ?? 'yuris-crm';
    }

    /**
     * Ajusta o timeout (segundos) das chamadas HTTP desta instância do serviço.
     * Usado por contextos web que NÃO podem travar a tela (ex.: sync.php): com a
     * Evolution lenta/instável, garante que cada chamada falhe rápido em vez de
     * pendurar 20s. Afeta só ESTA instância — envio de mensagem etc. seguem com o
     * timeout padrão.
     */
    public function setTimeout(int $seconds): void
    {
        $this->timeout = max(1, $seconds);
    }

    // ────────────────────────────────────────────
    // Instance management
    // ────────────────────────────────────────────

    /** Listar instâncias existentes. */
    public function fetchInstances(): array
    {
        return $this->request('GET', '/instance/fetchInstances');
    }

    /** Criar nova instância. */
    public function createInstance(string $name, string $webhookUrl = ''): array
    {
        $body = [
            'instanceName'  => $name,
            'integration'   => 'WHATSAPP-BAILEYS',
            'qrcode'        => true,
        ];
        if ($webhookUrl) {
            $body['webhook'] = [
                'url'      => $webhookUrl,
                'byEvents' => false,
                'base64'   => true,
                'events'   => [
                    'MESSAGES_UPSERT',
                    'MESSAGES_UPDATE',
                    'CONNECTION_UPDATE',
                    'QRCODE_UPDATED',
                    'CONTACTS_UPDATE',
                    'CONTACTS_UPSERT',
                    'CHATS_UPSERT',
                    'GROUPS_UPSERT',
                    'GROUPS_UPDATE',
                    'GROUP_PARTICIPANTS_UPDATE',
                    'SEND_MESSAGE',
                ],
            ];
            // Header apikey pro webhook.php identificar o tenant (ver setWebhook).
            if ($this->apiKey !== '') {
                $body['webhook']['headers'] = ['apikey' => $this->apiKey, 'Content-Type' => 'application/json'];
            }
        }
        return $this->request('POST', '/instance/create', $body);
    }

    /** Conectar instância (gera QR Code). */
    public function connectInstance(string $name): array
    {
        return $this->request('GET', "/instance/connect/{$this->enc($name)}");
    }

    /** Estado da conexão. */
    public function getConnectionState(string $name): array
    {
        return $this->request('GET', "/instance/connectionState/{$this->enc($name)}");
    }

    /** Desconectar (logout) sem excluir instância. */
    public function logoutInstance(string $name): array
    {
        return $this->request('DELETE', "/instance/logout/{$this->enc($name)}");
    }

    /** Excluir instância completamente. */
    public function deleteInstance(string $name): array
    {
        return $this->request('DELETE', "/instance/delete/{$this->enc($name)}");
    }

    /** Reiniciar instância. */
    public function restartInstance(string $name): array
    {
        return $this->request('PUT', "/instance/restart/{$this->enc($name)}");
    }

    // ────────────────────────────────────────────
    // Webhook
    // ────────────────────────────────────────────

    public function setWebhook(string $name, string $url, array $events = []): array
    {
        if (!$events) {
            $events = [
                'MESSAGES_UPSERT', 'MESSAGES_UPDATE',
                'CONNECTION_UPDATE', 'QRCODE_UPDATED',
                'CONTACTS_UPDATE',  'CONTACTS_UPSERT', 'CHATS_UPSERT',
                'GROUPS_UPSERT', 'GROUPS_UPDATE', 'GROUP_PARTICIPANTS_UPDATE',
                'SEND_MESSAGE',
            ];
        }
        // Evolution API v2 exige o envelope { "webhook": { ... } }
        $webhook = [
            'enabled'  => true,
            'url'      => $url,
            'byEvents' => false,
            'base64'   => true,
            'events'   => $events,
        ];
        // Header apikey (defense-in-depth): quando a Evolution honra headers
        // customizados, o webhook.php identifica o tenant por aqui. Quando NÃO
        // honra (varia por versão), o ?token na própria URL cobre. Os dois juntos
        // garantem a entrega independente do comportamento da versão.
        if ($this->apiKey !== '') {
            $webhook['headers'] = ['apikey' => $this->apiKey, 'Content-Type' => 'application/json'];
        }
        return $this->request('POST', "/webhook/set/{$this->enc($name)}", ['webhook' => $webhook]);
    }

    public function getWebhook(string $name): array
    {
        return $this->request('GET', "/webhook/find/{$this->enc($name)}");
    }

    // ────────────────────────────────────────────
    // Sending messages
    // ────────────────────────────────────────────

    /**
     * Enviar texto simples.
     *
     * $quoted = ['id' => wamid, 'fromMe' => bool, 'remoteJid' => '...', 'participant' => '...', 'content' => 'preview']
     * Apenas string (wamid) também é aceito por compat — mas reply só aparece no WhatsApp
     * se a Evolution receber o `message` completo, não só `key.id`.
     */
    public function sendText(string $name, string $to, string $text, string|array|null $quoted = null): array
    {
        $body = [
            'number'  => $this->normalizeNumber($to),
            'text'    => $text,
            'options' => ['delay' => 0],
        ];
        if ($quoted) {
            // Compat: aceita string (wamid solto) — mas reply no WhatsApp pode não funcionar
            if (is_string($quoted)) {
                $quoted = ['id' => $quoted];
            }
            // Evolution API v2 espera estrutura "Message" completa:
            //   options.quoted = { key: {id, fromMe, remoteJid, participant?}, message: {conversation|...} }
            // Sem `message`, a Evolution v2 às vezes envia o quoted_id mas o WhatsApp
            // não consegue resolver e a citação não aparece no celular.
            $key = ['id' => $quoted['id']];
            if (isset($quoted['fromMe']))      $key['fromMe']      = (bool)$quoted['fromMe'];
            if (!empty($quoted['remoteJid']))  $key['remoteJid']   = $quoted['remoteJid'];
            if (!empty($quoted['participant']))$key['participant'] = $quoted['participant'];

            $body['options']['quoted'] = [
                'key'     => $key,
                'message' => [
                    'conversation' => (string)($quoted['content'] ?? ''),
                ],
            ];
        }
        return $this->request('POST', "/message/sendText/{$this->enc($name)}", $body);
    }

    /**
     * Enviar mídia (imagem, vídeo, documento, áudio).
     * $mediaData pode ser base64 ou URL pública.
     */
    public function sendMedia(string $name, string $to, string $type, string $mediaData, string $caption = '', string $filename = '', string $mimetype = ''): array
    {
        $body = [
            'number'    => $this->normalizeNumber($to),
            'mediatype' => $type,
            'caption'   => $caption,
            'media'     => $mediaData,
        ];
        if ($filename)  $body['fileName'] = $filename;
        if ($mimetype)  $body['mimetype'] = $mimetype;

        return $this->request('POST', "/message/sendMedia/{$this->enc($name)}", $body);
    }

    /**
     * Enviar áudio como mensagem de voz (PTT).
     * $audioBase64 deve ser base64 do arquivo .ogg/.mp3.
     */
    public function sendAudio(string $name, string $to, string $audioBase64): array
    {
        return $this->request('POST', "/message/sendWhatsAppAudio/{$this->enc($name)}", [
            'number'   => $this->normalizeNumber($to),
            'audio'    => $audioBase64,
            'encoding' => true,
        ]);
    }

    /**
     * Envia uma reaction (emoji) a uma mensagem existente.
     * $key = ['remoteJid' => '...', 'id' => '<wamid>', 'fromMe' => bool]
     * $emoji = '' remove a reaction.
     *
     * Evolution API v2 (formato real, sem envelope reactionMessage):
     *   POST /message/sendReaction/{instance}
     *   body: { key: {remoteJid, fromMe, id}, reaction: '👍' }
     *
     * Ref: https://doc.evolution-api.com/v2/api-reference/message-controller/send-reaction
     */
    public function sendReaction(string $name, array $key, string $emoji): array
    {
        return $this->request('POST', "/message/sendReaction/{$this->enc($name)}", [
            'key'      => $key,
            'reaction' => $emoji,
        ]);
    }

    /**
     * Apaga (revoke) uma mensagem para todos os participantes.
     * $key = ['remoteJid' => '...', 'id' => '<wamid>', 'fromMe' => true]
     *
     * Evolution API v2: DELETE /chat/deleteMessageForEveryone/{instance}
     *   body: { id, remoteJid, fromMe, participant? }
     */
    public function deleteMessage(string $name, array $key): array
    {
        return $this->request('DELETE', "/chat/deleteMessageForEveryone/{$this->enc($name)}", $key);
    }

    // ────────────────────────────────────────────
    /**
     * Baixa mídia de uma mensagem e retorna como base64.
     * Usa o endpoint getBase64FromMediaMessage da Evolution API.
     */
    /**
     * Baixa mídia de uma mensagem usando o payload completo.
     * O raw_payload contém messageType, mediaKey etc — necessários para descriptografia.
     */
    public function getMediaBase64(string $name, array $rawPayload): ?string
    {
        // Formato compacto: apenas os campos necessários para decriptação
        $msg = [
            'key'              => $rawPayload['key']              ?? [],
            'message'          => $rawPayload['message']          ?? new \stdClass(),
            'messageType'      => $rawPayload['messageType']      ?? '',
            'messageTimestamp' => $rawPayload['messageTimestamp'] ?? 0,
        ];

        // Tentativa 1: formato compacto
        $resp = $this->request('POST', "/chat/getBase64FromMediaMessage/{$this->enc($name)}", [
            'message'      => $msg,
            'convertToMp4' => false,
        ]);
        $result = $resp['base64'] ?? $resp['data']['base64'] ?? $resp['mediaBase64'] ?? (is_string($resp['data'] ?? null) ? $resp['data'] : null) ?? null;
        if ($result) return $result;

        // Tentativa 2: payload completo (fallback)
        $resp2 = $this->request('POST', "/chat/getBase64FromMediaMessage/{$this->enc($name)}", [
            'message'      => $rawPayload,
            'convertToMp4' => false,
        ]);
        return $resp2['base64'] ?? $resp2['data']['base64'] ?? $resp2['mediaBase64'] ?? (is_string($resp2['data'] ?? null) ? $resp2['data'] : null) ?? null;
    }

    /**
     * Retorna a resposta completa do getBase64 para diagnóstico.
     */
    public function getMediaBase64Raw(string $name, array $rawPayload): array
    {
        return $this->request('POST', "/chat/getBase64FromMediaMessage/{$this->enc($name)}", [
            'message'      => $rawPayload,
            'convertToMp4' => false,
        ]);
    }

    // Chats & Contacts
    // ────────────────────────────────────────────

    public function findChats(string $name): array
    {
        return $this->request('POST', "/chat/findChats/{$this->enc($name)}", new \stdClass());
    }

    /**
     * Busca as mensagens mais recentes de um JID.
     * Para consultas por JID, página 1 = mais recentes.
     * Busca as primeiras $pages páginas para cobrir mensagens novas.
     */
    public function findMessages(string $name, string $remoteJid, int $count = 50, int $pages = 2): array
    {
        $allRecords = [];
        for ($p = 1; $p <= $pages; $p++) {
            $resp = $this->request('POST', "/chat/findMessages/{$this->enc($name)}", [
                'where' => ['key' => ['remoteJid' => $remoteJid]],
                'limit' => $count,
                'page'  => $p,
            ]);
            $records = $resp['messages']['records'] ?? [];
            if (empty($records)) break;
            $allRecords = array_merge($allRecords, $records);
        }
        return ['messages' => ['records' => $allRecords]];
    }

    /** Listar todos os grupos da instância. */
    public function fetchAllGroups(string $name): array
    {
        return $this->request('GET', "/group/fetchAllGroups/{$this->enc($name)}?getParticipants=false");
    }

    /** Metadados de um grupo específico. */
    public function fetchGroupInfo(string $name, string $groupJid): array
    {
        return $this->request('GET', "/group/findGroupInfos/{$this->enc($name)}?groupJid=" . urlencode($groupJid));
    }

    public function findContacts(string $name, string $query = ''): array
    {
        $body = $query ? ['where' => ['pushName' => $query]] : new \stdClass();
        return $this->request('POST', "/contact/findContacts/{$this->enc($name)}", $body);
    }

    public function getProfilePicture(string $name, string $jid): array
    {
        return $this->request('GET', "/contact/getProfilePicture/{$this->enc($name)}?number=" . urlencode($jid));
    }

    // ────────────────────────────────────────────
    // Internal HTTP client
    // ────────────────────────────────────────────

    public function request(string $method, string $path, array|object $body = []): array
    {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        if ($this->apiKey) {
            $headers[] = 'apikey: ' . $this->apiKey;
        }

        // ─── LGPD P1 (2C.4) — TLS verify configurável via .env ────────────────
        // Antes desta correção, CURLOPT_SSL_VERIFYPEER era SEMPRE false —
        // chamadas à Evolution API ficavam vulneráveis a MITM (interceptação
        // de tokens, mídia, mensagens). Agora padrão é TRUE; só desliga via
        // EVOLUTION_TLS_VERIFY=false no .env (cenários dev com cert self-signed).
        // Em prod sempre TRUE.
        if (!class_exists('App\\Helpers\\EnvLoader')) {
            require_once __DIR__ . '/../Helpers/EnvLoader.php';
        }
        \App\Helpers\EnvLoader::load();
        $tlsVerifySetting = strtolower(\App\Helpers\EnvLoader::get('EVOLUTION_TLS_VERIFY', 'true'));
        $tlsVerify        = !in_array($tlsVerifySetting, ['false','0','no','off'], true);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => $tlsVerify,
            CURLOPT_SSL_VERIFYHOST => $tlsVerify ? 2 : 0,
        ]);
        // ──────────────────────────────────────────────────────────────────────

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                break;
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
                if (!empty((array)$body)) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
                break;
        }

        $raw    = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['_error' => $error, '_http' => 0];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return ['_raw' => $raw, '_http' => $httpCode];
        }

        $decoded['_http'] = $httpCode;
        return $decoded;
    }

    // ────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────

    /**
     * Escapa o nome da instância para uso seguro no PATH da URL.
     * Ex.: "Gabriel Yuris" -> "Gabriel%20Yuris"; "Homologação" -> "Homologa%C3%A7%C3%A3o".
     * O nome continua com espaço/acento no banco e na tela — a codificação acontece
     * só na hora de montar a URL HTTP pra Evolution, que decodifica de volta.
     * rawurlencode (RFC 3986) é o correto p/ segmento de path (espaço -> %20, não '+').
     * NÃO usar no corpo JSON (ex.: createInstance) — lá o nome vai cru.
     */
    private function enc(string $name): string
    {
        return rawurlencode($name);
    }

    /** Normaliza número: remove tudo exceto dígitos e @. */
    private function normalizeNumber(string $to): string
    {
        if (str_contains($to, '@')) return $to;
        return preg_replace('/\D/', '', $to);
    }

    public function getInstanceName(): string
    {
        return $this->instanceName;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }
}
