<?php
namespace App\WhatsAppAgente;

/**
 * WhatsAppWebhookParser — parsers PUROS do payload da Evolution API.
 *
 * Strangler do webhook.php (Onda 4 / 4D, Pass 1): estas 3 funções eram globais
 * dentro de public/api/whatsapp/webhook.php. São input→output PURO (sem banco, sem
 * estado global, sem efeito colateral), então movê-las para cá é neutro de
 * comportamento e as torna testáveis isoladamente (ver scripts/tests/wa_webhook_parser_test.php).
 *
 * NADA aqui pode ganhar dependência de banco/rede/sessão — se precisar disso, é
 * outra camada. A pureza é o que garante o teste de caracterização.
 */
class WhatsAppWebhookParser
{
    /**
     * Extrai [type, content, caption, mediaUrl, mimetype, filename] de um objeto
     * `message` da Evolution. 'reaction' é sinalizador (o handler trata à parte).
     */
    public static function extractMessageContent(array $message): array
    {
        // text
        if (!empty($message['conversation'])) {
            return ['text', $message['conversation'], null, null, null, null];
        }
        if (!empty($message['extendedTextMessage']['text'])) {
            return ['text', $message['extendedTextMessage']['text'], null, null, null, null];
        }
        // image
        if (!empty($message['imageMessage'])) {
            $m = $message['imageMessage'];
            return ['image', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? 'image/jpeg', null];
        }
        // video
        if (!empty($message['videoMessage'])) {
            $m = $message['videoMessage'];
            return ['video', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? 'video/mp4', null];
        }
        // document
        if (!empty($message['documentMessage'])) {
            $m = $message['documentMessage'];
            return ['document', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? null, $m['fileName'] ?? null];
        }
        if (!empty($message['documentWithCaptionMessage']['message']['documentMessage'])) {
            $m = $message['documentWithCaptionMessage']['message']['documentMessage'];
            return ['document', null, $m['caption'] ?? null, $m['url'] ?? null, $m['mimetype'] ?? null, $m['fileName'] ?? null];
        }
        // audio
        if (!empty($message['audioMessage'])) {
            $m = $message['audioMessage'];
            return ['audio', null, null, $m['url'] ?? null, $m['mimetype'] ?? 'audio/ogg', null];
        }
        // sticker
        if (!empty($message['stickerMessage'])) {
            return ['sticker', null, null, $message['stickerMessage']['url'] ?? null, 'image/webp', null];
        }
        // reaction — sinalizador; handler trata separado (grava em whatsapp_reactions)
        if (!empty($message['reactionMessage'])) {
            return ['reaction', $message['reactionMessage']['text'] ?? '👍', null, null, null, null];
        }
        // location → mostra como msg de texto com label amigavel (antes virava "nao suportada")
        if (!empty($message['locationMessage'])) {
            $loc = $message['locationMessage'];
            $lat = $loc['degreesLatitude']  ?? '';
            $lng = $loc['degreesLongitude'] ?? '';
            $name = $loc['name'] ?? '';
            $text = '📍 Localização' . ($name ? ': ' . $name : '') . ($lat && $lng ? " ($lat, $lng)" : '');
            return ['text', $text, null, null, null, null];
        }
        // contato compartilhado
        if (!empty($message['contactMessage'])) {
            $name = $message['contactMessage']['displayName'] ?? 'Contato';
            return ['text', '👤 Contato compartilhado: ' . $name, null, null, null, null];
        }
        // enquete (pollCreationMessage)
        if (!empty($message['pollCreationMessage'])) {
            $title = $message['pollCreationMessage']['name'] ?? 'Enquete';
            return ['text', '📊 ' . $title, null, null, null, null];
        }

        // template (newsletter/marketing): o texto NAO fica em conversation, e sim
        // dentro do template hidratado. Sem isto a mensagem era gravada vazia.
        if (!empty($message['templateMessage'])) {
            $h = $message['templateMessage']['hydratedTemplate']
              ?? ($message['templateMessage']['hydratedFourRowTemplate'] ?? []);
            $titulo = trim((string)($h['hydratedTitleText'] ?? ''));
            $corpo  = trim((string)($h['hydratedContentText'] ?? ''));
            $texto  = trim($titulo . ($titulo !== '' && $corpo !== '' ? "\n" : '') . $corpo);
            if ($texto !== '') {
                return ['text', $texto, null, null, null, null];
            }
        }
        // resposta a botao de template
        if (!empty($message['templateButtonReplyMessage']['selectedDisplayText'])) {
            return ['text', (string)$message['templateButtonReplyMessage']['selectedDisplayText'], null, null, null, null];
        }
        // mensagem interativa (lista/botoes)
        if (!empty($message['interactiveMessage'])) {
            $i = $message['interactiveMessage'];
            $texto = trim((string)($i['body']['text'] ?? ($i['header']['title'] ?? '')));
            if ($texto !== '') {
                return ['text', $texto, null, null, null, null];
            }
            return ['text', '[Mensagem interativa]', null, null, null, null];
        }
        // album: as fotos chegam em mensagens proprias; esta e so o agrupador
        if (!empty($message['albumMessage'])) {
            return ['text', '[Álbum]', null, null, null, null];
        }
        // mensagem criptografada que nao conseguimos abrir (enquete/edicao secreta):
        // rotula em vez de virar linha em branco, senao some da conversa sem explicacao
        if (!empty($message['secretEncryptedMessage'])) {
            return ['text', '[Mensagem criptografada]', null, null, null, null];
        }

        // ── PROTOCOLO, nao e conversa ────────────────────────────────────────
        // senderKeyDistributionMessage e a troca de chave de criptografia que o
        // WhatsApp manda em TODO grupo; protocolMessage/pinInChat sao acoes, nao
        // mensagens. Antes caiam no fallback abaixo e viravam linha 'text' VAZIA,
        // que ainda por cima sobrescrevia o preview da conversa (o "..." que o
        // usuario via na lista). No canal de uma advogada real isso era 690 de
        // 1.554 linhas, 57% do total.
        // So ignora quando o payload NAO tem nenhuma outra parte: uma mensagem de
        // verdade pode vir acompanhada de senderKeyDistributionMessage, e nesse
        // caso ela ja foi tratada nos branches acima.
        $protocolo = ['senderKeyDistributionMessage', 'protocolMessage', 'pinInChatMessage', 'messageContextInfo'];
        $partes = array_keys($message);
        if ($partes !== [] && array_diff($partes, $protocolo) === []) {
            return ['ignore', null, null, null, null, null];
        }

        return ['text', null, null, null, null, null];
    }

    /**
     * Extrai o stanzaId da mensagem citada (reply).
     * Localizado em vários paths dependendo do tipo da msg que carrega o reply.
     */
    public static function extractQuotedWamid(array $message): ?string
    {
        // Reply em texto simples
        if (!empty($message['extendedTextMessage']['contextInfo']['stanzaId'])) {
            return $message['extendedTextMessage']['contextInfo']['stanzaId'];
        }
        // Reply em imagem/video/audio/sticker (todos têm contextInfo dentro do sub)
        foreach (['imageMessage', 'videoMessage', 'audioMessage', 'stickerMessage', 'documentMessage'] as $sub) {
            if (!empty($message[$sub]['contextInfo']['stanzaId'])) {
                return $message[$sub]['contextInfo']['stanzaId'];
            }
        }
        return null;
    }

    /**
     * Extrai o SNAPSHOT da mensagem citada (autor + trecho de texto) embutido no
     * contextInfo do payload. O WhatsApp sempre manda esse trecho junto da resposta,
     * então conseguimos exibir a citação mesmo sem ter a mensagem original no banco.
     * Procura o contextInfo em: topo do payload ($msg) → extendedTextMessage → subs de mídia.
     * Retorna [senderName|null, text|null].
     */
    public static function extractQuotedSnapshot(array $message, array $msg): array
    {
        // Acha o primeiro contextInfo disponível (topo > texto > mídia)
        $ci = $msg['contextInfo']
            ?? ($message['extendedTextMessage']['contextInfo'] ?? null);
        if (!$ci) {
            foreach (['imageMessage', 'videoMessage', 'audioMessage', 'stickerMessage', 'documentMessage'] as $sub) {
                if (!empty($message[$sub]['contextInfo'])) { $ci = $message[$sub]['contextInfo']; break; }
            }
        }
        if (!is_array($ci)) return [null, null];

        // Autor da mensagem citada (JID). Só guardamos se for telefone real — o nome
        // de verdade é resolvido depois no Model. Aqui guardamos o número como dica.
        $participant = $ci['participant'] ?? null;
        $senderName = null;
        if ($participant && !str_contains((string)$participant, '@lid')) {
            $digits = preg_replace('/\D/', '', explode('@', (string)$participant)[0]);
            if ($digits !== '') $senderName = $digits; // Model tenta trocar por nome real
        }

        // Texto da mensagem citada (vários formatos possíveis no quotedMessage)
        $qm = $ci['quotedMessage'] ?? [];
        $text = null;
        if (is_array($qm)) {
            $text = $qm['conversation']
                ?? ($qm['extendedTextMessage']['text'] ?? null)
                ?? ($qm['imageMessage']['caption'] ?? null)
                ?? ($qm['videoMessage']['caption'] ?? null)
                ?? ($qm['documentMessage']['caption'] ?? null)
                ?? null;
            if ($text === null) {
                if (isset($qm['imageMessage']))      $text = '📷 Imagem';
                elseif (isset($qm['videoMessage']))  $text = '🎥 Vídeo';
                elseif (isset($qm['audioMessage']))  $text = '🎵 Áudio';
                elseif (isset($qm['documentMessage'])) $text = '📄 Documento';
                elseif (isset($qm['stickerMessage'])) $text = '✨ Sticker';
            }
        }
        if (is_string($text) && mb_strlen($text) > 480) $text = mb_substr($text, 0, 477) . '…';

        return [$senderName, $text];
    }
}
