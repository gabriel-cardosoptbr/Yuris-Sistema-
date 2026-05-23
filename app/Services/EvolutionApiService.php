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
                    'CHATS_UPSERT',
                    'SEND_MESSAGE',
                ],
            ];
        }
        return $this->request('POST', '/instance/create', $body);
    }

    /** Conectar instância (gera QR Code). */
    public function connectInstance(string $name): array
    {
        return $this->request('GET', "/instance/connect/{$name}");
    }

    /** Estado da conexão. */
    public function getConnectionState(string $name): array
    {
        return $this->request('GET', "/instance/connectionState/{$name}");
    }

    /** Desconectar (logout) sem excluir instância. */
    public function logoutInstance(string $name): array
    {
        return $this->request('DELETE', "/instance/logout/{$name}");
    }

    /** Excluir instância completamente. */
    public function deleteInstance(string $name): array
    {
        return $this->request('DELETE', "/instance/delete/{$name}");
    }

    /** Reiniciar instância. */
    public function restartInstance(string $name): array
    {
        return $this->request('PUT', "/instance/restart/{$name}");
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
                'CONTACTS_UPDATE',  'CHATS_UPSERT',
                'SEND_MESSAGE',
            ];
        }
        // Evolution API v2 exige o envelope { "webhook": { ... } }
        return $this->request('POST', "/webhook/set/{$name}", [
            'webhook' => [
                'enabled'  => true,
                'url'      => $url,
                'byEvents' => false,
                'base64'   => true,
                'events'   => $events,
            ],
        ]);
    }

    public function getWebhook(string $name): array
    {
        return $this->request('GET', "/webhook/find/{$name}");
    }

    // ────────────────────────────────────────────
    // Sending messages
    // ────────────────────────────────────────────

    /** Enviar texto simples. */
    public function sendText(string $name, string $to, string $text, ?string $quotedId = null): array
    {
        $body = [
            'number'  => $this->normalizeNumber($to),
            'text'    => $text,
            'options' => ['delay' => 0],
        ];
        if ($quotedId) {
            $body['options']['quoted'] = ['key' => ['id' => $quotedId]];
        }
        return $this->request('POST', "/message/sendText/{$name}", $body);
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

        return $this->request('POST', "/message/sendMedia/{$name}", $body);
    }

    /**
     * Enviar áudio como mensagem de voz (PTT).
     * $audioBase64 deve ser base64 do arquivo .ogg/.mp3.
     */
    public function sendAudio(string $name, string $to, string $audioBase64): array
    {
        return $this->request('POST', "/message/sendWhatsAppAudio/{$name}", [
            'number'   => $this->normalizeNumber($to),
            'audio'    => $audioBase64,
            'encoding' => true,
        ]);
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
        $resp = $this->request('POST', "/chat/getBase64FromMediaMessage/{$name}", [
            'message'      => $msg,
            'convertToMp4' => false,
        ]);
        $result = $resp['base64'] ?? $resp['data']['base64'] ?? $resp['mediaBase64'] ?? (is_string($resp['data'] ?? null) ? $resp['data'] : null) ?? null;
        if ($result) return $result;

        // Tentativa 2: payload completo (fallback)
        $resp2 = $this->request('POST', "/chat/getBase64FromMediaMessage/{$name}", [
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
        return $this->request('POST', "/chat/getBase64FromMediaMessage/{$name}", [
            'message'      => $rawPayload,
            'convertToMp4' => false,
        ]);
    }

    // Chats & Contacts
    // ────────────────────────────────────────────

    public function findChats(string $name): array
    {
        return $this->request('POST', "/chat/findChats/{$name}", new \stdClass());
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
            $resp = $this->request('POST', "/chat/findMessages/{$name}", [
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
        return $this->request('GET', "/group/fetchAllGroups/{$name}?getParticipants=false");
    }

    /** Metadados de um grupo específico. */
    public function fetchGroupInfo(string $name, string $groupJid): array
    {
        return $this->request('GET', "/group/findGroupInfos/{$name}?groupJid=" . urlencode($groupJid));
    }

    public function findContacts(string $name, string $query = ''): array
    {
        $body = $query ? ['where' => ['pushName' => $query]] : new \stdClass();
        return $this->request('POST', "/contact/findContacts/{$name}", $body);
    }

    public function getProfilePicture(string $name, string $jid): array
    {
        return $this->request('GET', "/contact/getProfilePicture/{$name}?number=" . urlencode($jid));
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
