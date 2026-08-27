<?php
/**
 * wa_webhook_parser_test.php — teste de CARACTERIZAÇÃO dos parsers puros do webhook.
 *
 * Rede de segurança do strangler (Onda 4 / 4D, Pass 1). As 3 funções
 * (extractMessageContent / extractQuotedWamid / extractQuotedSnapshot) foram movidas
 * verbatim do public/api/whatsapp/webhook.php para App\WhatsAppAgente\WhatsAppWebhookParser.
 * Os valores esperados abaixo foram CAPTURADOS da versão LEGADA (harness legado-vs-novo,
 * 42 comparações, 0 divergência) — então este teste crava o comportamento observado, não
 * uma expectativa reinventada. Cobre TODOS os branches. Self-contained (só requer a classe),
 * roda igual local e em prod.
 *
 * Uso: php scripts/tests/wa_webhook_parser_test.php   (exit 0 = tudo passou)
 */
require_once __DIR__ . '/../../app/WhatsAppAgente/WhatsAppWebhookParser.php';

use App\WhatsAppAgente\WhatsAppWebhookParser as P;

$pass = 0; $fail = 0;
function check(string $label, $actual, $expected): void {
    global $pass, $fail;
    if ($actual === $expected) { $pass++; return; }
    $fail++;
    fwrite(STDERR, "  [FAIL] $label\n    esperado: " . json_encode($expected, JSON_UNESCAPED_UNICODE) . "\n    obtido:   " . json_encode($actual, JSON_UNESCAPED_UNICODE) . "\n");
}

// ── extractMessageContent (todos os branches) ────────────────────────────────
// [type, content, caption, mediaUrl, mimetype, filename]
$mc = [
    'conversation'        => [['conversation' => 'Oi tudo bem'],                                                         ['text', 'Oi tudo bem', null, null, null, null]],
    'conversation_vazio'  => [['conversation' => ''],                                                                    ['text', null, null, null, null, null]],
    'extendedText'        => [['extendedTextMessage' => ['text' => 'texto longo']],                                      ['text', 'texto longo', null, null, null, null]],
    'extendedText_vazio'  => [['extendedTextMessage' => ['text' => '']],                                                 ['text', null, null, null, null, null]],
    'image_caption'       => [['imageMessage' => ['caption' => 'foto', 'url' => 'http://x/i', 'mimetype' => 'image/png']], ['image', null, 'foto', 'http://x/i', 'image/png', null]],
    'image_sem_mime'      => [['imageMessage' => ['url' => 'http://x/i']],                                               ['image', null, null, 'http://x/i', 'image/jpeg', null]],
    'video'               => [['videoMessage' => ['caption' => 'v', 'url' => 'http://x/v']],                             ['video', null, 'v', 'http://x/v', 'video/mp4', null]],
    'document'            => [['documentMessage' => ['caption' => 'd', 'url' => 'http://x/d', 'mimetype' => 'application/pdf', 'fileName' => 'a.pdf']], ['document', null, 'd', 'http://x/d', 'application/pdf', 'a.pdf']],
    'documentWithCaption' => [['documentWithCaptionMessage' => ['message' => ['documentMessage' => ['url' => 'http://x/d2', 'fileName' => 'b.pdf']]]], ['document', null, null, 'http://x/d2', null, 'b.pdf']],
    'audio'               => [['audioMessage' => ['url' => 'http://x/a']],                                               ['audio', null, null, 'http://x/a', 'audio/ogg', null]],
    'sticker'             => [['stickerMessage' => ['url' => 'http://x/s']],                                             ['sticker', null, null, 'http://x/s', 'image/webp', null]],
    'reaction_com_texto'  => [['reactionMessage' => ['text' => '❤️']],                                                   ['reaction', '❤️', null, null, null, null]],
    'reaction_sem_texto'  => [['reactionMessage' => []],                                                                 ['text', null, null, null, null, null]], // [] é empty → cai adiante
    'location_completo'   => [['locationMessage' => ['degreesLatitude' => -23.5, 'degreesLongitude' => -46.6, 'name' => 'SP']], ['text', '📍 Localização: SP (-23.5, -46.6)', null, null, null, null]],
    'location_sem_nome'   => [['locationMessage' => ['degreesLatitude' => -23.5, 'degreesLongitude' => -46.6]],          ['text', '📍 Localização (-23.5, -46.6)', null, null, null, null]],
    'location_vazio'      => [['locationMessage' => []],                                                                 ['text', null, null, null, null, null]],
    'contato_com_nome'    => [['contactMessage' => ['displayName' => 'Fulano']],                                         ['text', '👤 Contato compartilhado: Fulano', null, null, null, null]],
    'contato_sem_nome'    => [['contactMessage' => []],                                                                  ['text', null, null, null, null, null]],
    'poll_com_nome'       => [['pollCreationMessage' => ['name' => 'Qual?']],                                            ['text', '📊 Qual?', null, null, null, null]],
    'poll_sem_nome'       => [['pollCreationMessage' => []],                                                             ['text', null, null, null, null, null]],
    'desconhecido'        => [['algoNovo' => ['x' => 1]],                                                                ['text', null, null, null, null, null]],
    'vazio'               => [[],                                                                                        ['text', null, null, null, null, null]],
];
foreach ($mc as $label => [$in, $exp]) check("mc/$label", P::extractMessageContent($in), $exp);

// ── extractQuotedWamid ───────────────────────────────────────────────────────
$qw = [
    'text_reply'     => [['extendedTextMessage' => ['contextInfo' => ['stanzaId' => 'WAMID_TXT']]], 'WAMID_TXT'],
    'image_reply'    => [['imageMessage'        => ['contextInfo' => ['stanzaId' => 'WAMID_IMG']]], 'WAMID_IMG'],
    'video_reply'    => [['videoMessage'        => ['contextInfo' => ['stanzaId' => 'WAMID_VID']]], 'WAMID_VID'],
    'audio_reply'    => [['audioMessage'        => ['contextInfo' => ['stanzaId' => 'WAMID_AUD']]], 'WAMID_AUD'],
    'sticker_reply'  => [['stickerMessage'      => ['contextInfo' => ['stanzaId' => 'WAMID_STK']]], 'WAMID_STK'],
    'doc_reply'      => [['documentMessage'     => ['contextInfo' => ['stanzaId' => 'WAMID_DOC']]], 'WAMID_DOC'],
    'sem_reply'      => [['conversation' => 'oi'], null],
    'ctx_sem_stanza' => [['extendedTextMessage' => ['contextInfo' => ['participant' => 'x@s.whatsapp.net']]], null],
];
foreach ($qw as $label => [$in, $exp]) check("qw/$label", P::extractQuotedWamid($in), $exp);

// ── extractQuotedSnapshot ────────────────────────────────────────────────────
// [senderName|null, text|null]
$qs = [
    'topo_phone_conv'     => [[], ['contextInfo' => ['participant' => '5511999998888@s.whatsapp.net', 'quotedMessage' => ['conversation' => 'texto citado']]], ['5511999998888', 'texto citado']],
    'lid_participant'     => [[], ['contextInfo' => ['participant' => '12345@lid', 'quotedMessage' => ['conversation' => 'x']]], [null, 'x']],
    'em_extendedText'     => [['extendedTextMessage' => ['contextInfo' => ['participant' => '5511888887777@s.whatsapp.net', 'quotedMessage' => ['extendedTextMessage' => ['text' => 'reply em texto']]]]], [], ['5511888887777', 'reply em texto']],
    'em_midia_image_cap'  => [['imageMessage' => ['contextInfo' => ['quotedMessage' => ['imageMessage' => ['caption' => 'legenda']]]]], [], [null, 'legenda']],
    'image_sem_caption'   => [['imageMessage' => ['contextInfo' => ['quotedMessage' => ['imageMessage' => []]]]], [], [null, '📷 Imagem']],
    'video_sem_caption'   => [['videoMessage' => ['contextInfo' => ['quotedMessage' => ['videoMessage' => []]]]], [], [null, '🎥 Vídeo']],
    'audio_placeholder'   => [['audioMessage' => ['contextInfo' => ['quotedMessage' => ['audioMessage' => []]]]], [], [null, '🎵 Áudio']],
    'doc_placeholder'     => [['documentMessage' => ['contextInfo' => ['quotedMessage' => ['documentMessage' => []]]]], [], [null, '📄 Documento']],
    'sticker_placeholder' => [['stickerMessage' => ['contextInfo' => ['quotedMessage' => ['stickerMessage' => []]]]], [], [null, '✨ Sticker']],
    'texto_longo_trunca'  => [[], ['contextInfo' => ['quotedMessage' => ['conversation' => str_repeat('a', 600)]]], [null, str_repeat('a', 477) . '…']],
    'sem_contextinfo'     => [['conversation' => 'oi'], [], [null, null]],
    'ci_nao_array'        => [[], ['contextInfo' => 'stringinvalida'], [null, null]],
];
foreach ($qs as $label => [$message, $msg, $exp]) check("qs/$label", P::extractQuotedSnapshot($message, $msg), $exp);

echo "== wa_webhook_parser: $pass PASS · $fail FAIL ==\n";
exit($fail === 0 ? 0 : 1);
