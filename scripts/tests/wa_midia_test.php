<?php
/**
 * wa_midia_test.php: o arquivo de mídia do WhatsApp (foto, áudio, PDF, vídeo).
 *
 * A coluna `whatsapp_messages.media_base64` guarda o arquivo de verdade OU a
 * miniatura que o WhatsApp manda embutida, e o sistema não sabia qual dos dois
 * estava ali. Este teste tranca o que a auditoria de 30/09/2026 consertou:
 *
 *   1. miniatura não é servida como se fosse o arquivo (o "PDF" que era um JPEG);
 *   2. PDF, planilha, Word e vídeo MP4 passam a ser guardados (antes só imagem,
 *      OGG e MP3, e o resto sumia quando o payload cru era apagado aos 30 dias);
 *   3. mídia grande demais para a coluna não derruba a gravação da mensagem;
 *   4. mensagem temporária e de visualização única (que chegam num envelope)
 *      são lidas, em vez de virar linha vazia;
 *   5. a segunda tentativa de download, depois do 200 do webhook, grava o
 *      arquivo por cima da miniatura, e nunca grava lixo;
 *   6. a ficha do cliente acha a conversa pelo número do próprio cliente, sem
 *      atravessar para outra conta.
 *
 * Não faz chamada de rede. O que toca o banco roda em transação desfeita no fim.
 * Uso: php scripts/tests/wa_midia_test.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Clientes\VinculosCliente;
use App\WhatsAppAgente\MidiaCache as MC;
use App\WhatsAppAgente\WhatsAppMessage;
use App\WhatsAppAgente\WhatsAppWebhookParser as Parser;

$OK = 0;
$FALHAS = [];

function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}

/* Amostras: só os primeiros bytes importam para as regras. */
$JPEG  = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00" . str_repeat('m', 300);      // a miniatura
$JPEG2 = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00" . str_repeat('F', 5000);     // uma foto de verdade
$PDF   = "%PDF-1.4\n" . str_repeat('p', 400);
$OGG   = 'OggS' . str_repeat("\x00", 200);
$MP4   = "\x00\x00\x00\x18ftypmp42" . str_repeat("\x00", 200);
$thumb = base64_encode($JPEG);

$payload = static fn(string $sub, array $extra = []): array => [
    'key'         => ['id' => 'X', 'remoteJid' => '5511900000000@s.whatsapp.net', 'fromMe' => false],
    'message'     => [$sub => $extra + ['jpegThumbnail' => $thumb]],
    'messageType' => $sub,
];

echo "\n== 1. Miniatura no payload ==\n";
ok('acha a miniatura de uma foto', MC::miniaturaDoPayload($payload('imageMessage')) === $thumb);
ok('aceita o payload como JSON (como está no banco)', MC::miniaturaDoPayload(json_encode($payload('documentMessage'))) === $thumb);
ok('tira o prefixo data:...;base64,', MC::miniaturaDoPayload(['message' => ['imageMessage' => ['jpegThumbnail' => 'data:image/jpeg;base64,' . $thumb]]]) === $thumb);
ok('abre o envelope de mensagem temporária', MC::miniaturaDoPayload(['message' => ['ephemeralMessage' => ['message' => ['imageMessage' => ['jpegThumbnail' => $thumb]]]]]) === $thumb);
ok('abre o envelope de documento com legenda', MC::miniaturaDoPayload(['message' => ['documentWithCaptionMessage' => ['message' => ['documentMessage' => ['jpegThumbnail' => $thumb]]]]]) === $thumb);
ok('miniatura como objeto de bytes (Buffer) é ignorada, sem erro', MC::miniaturaDoPayload(['message' => ['imageMessage' => ['jpegThumbnail' => [255, 216, 255]]]]) === null);
ok('áudio não tem miniatura', MC::miniaturaDoPayload(['message' => ['audioMessage' => ['mimetype' => 'audio/ogg']]]) === null);
ok('payload vazio, nulo ou quebrado -> null', MC::miniaturaDoPayload(null) === null && MC::miniaturaDoPayload('') === null && MC::miniaturaDoPayload('{nao é json') === null);

echo "\n== 2. O que está no cache é a miniatura? ==\n";
ok('cache igual à miniatura do payload -> é miniatura (foto)', MC::ehMiniatura($thumb, $payload('imageMessage'), 'image', 'image/jpeg') === true);
ok('cache igual à miniatura do payload -> é miniatura (PDF)', MC::ehMiniatura($thumb, json_encode($payload('documentMessage')), 'document', 'application/pdf') === true);
ok('mesmos bytes com prefixo data: também conta', MC::ehMiniatura('data:image/jpeg;base64,' . $thumb, $payload('imageMessage'), 'image', 'image/jpeg') === true);
ok('foto de verdade, diferente da miniatura -> NÃO é miniatura', MC::ehMiniatura(base64_encode($JPEG2), $payload('imageMessage'), 'image', 'image/jpeg') === false);
ok('PDF de verdade -> NÃO é miniatura', MC::ehMiniatura(base64_encode($PDF), $payload('documentMessage'), 'document', 'application/pdf') === false);
ok('sem payload: JPEG guardado num PDF -> é miniatura', MC::ehMiniatura($thumb, null, 'document', 'application/pdf') === true);
ok('sem payload: JPEG guardado num vídeo -> é miniatura', MC::ehMiniatura($thumb, null, 'video', 'video/mp4') === true);
ok('sem payload: JPEG enviado COMO documento (image/jpeg) -> fica valendo', MC::ehMiniatura($thumb, null, 'document', 'image/jpeg') === false);
ok('sem payload: foto não tem como ser julgada -> fica valendo', MC::ehMiniatura($thumb, null, 'image', 'image/jpeg') === false);
ok('áudio OGG de verdade -> NÃO é miniatura', MC::ehMiniatura(base64_encode($OGG), null, 'audio', 'audio/ogg') === false);
ok('cache grande nunca é miniatura (nem decodifica)', MC::ehMiniatura(str_repeat('A', 400_000), $payload('imageMessage'), 'video', 'video/mp4') === false);
ok('cache vazio -> false', MC::ehMiniatura('', $payload('imageMessage'), 'image', 'image/jpeg') === false);

echo "\n== 3. O que pode ser guardado ==\n";
foreach ([
    'JPEG' => "\xFF\xD8\xFF\xE0", 'PNG' => "\x89PNG\r\n", 'GIF' => 'GIF89a', 'WebP/WAV' => 'RIFF....WEBP',
    'OGG (áudio de voz)' => 'OggS....', 'MP3' => 'ID3.....', 'PDF' => '%PDF-1.7', 'DOCX/XLSX' => "PK\x03\x04....",
    'DOC/XLS antigo' => "\xD0\xCF\x11\xE0....", 'WebM' => "\x1A\x45\xDF\xA3....", 'MP4' => "\x00\x00\x00\x18ftypmp42",
    'M4A (áudio do iPhone)' => "\x00\x00\x00\x20ftypM4A ",
] as $nome => $inicio) {
    ok("$nome é reconhecido", MC::conteudoReconhecido($inicio) === true);
}
ok('CSV declarado como texto é reconhecido', MC::conteudoReconhecido("placa;modelo\nABC1D23;Onix", 'text/csv') === true);
ok('texto com acento cortado no fim da amostra é reconhecido', MC::conteudoReconhecido(substr('relatório de março', 0, 5), 'text/plain; charset=utf-8') === true);
ok('texto SEM tipo de texto declarado não passa', MC::conteudoReconhecido("placa;modelo\nABC1D23", 'application/octet-stream') === false);
ok('bytes sem assinatura (conteúdo ainda cifrado) não passam', MC::conteudoReconhecido("\x8A\x11\x02\xF0\x9C\x33\x71\x00\x05\xEE", 'application/pdf') === false);
ok('binário disfarçado de texto não passa', MC::conteudoReconhecido("\x00\x01\x02\x03\x04\x05", 'text/plain') === false);
ok('menos de 4 bytes não passa', MC::conteudoReconhecido('%PD') === false);
ok('PDF em base64 pode ser guardado', MC::podeGuardar(base64_encode($PDF), 'application/pdf') === true);
ok('vídeo MP4 em base64 pode ser guardado', MC::podeGuardar(base64_encode($MP4), 'video/mp4') === true);
ok('acima do limite da coluna não pode', MC::podeGuardar(base64_encode("%PDF-1.4\n") . str_repeat('A', MC::LIMITE_BASE64), 'application/pdf') === false);
ok('vazio não pode', MC::podeGuardar('', 'application/pdf') === false);
ok('nulo cabe (mensagem sem mídia)', MC::cabeNoBanco(null) === true);

echo "\n== 4. Envelopes no parser do webhook ==\n";
[$t, $c] = Parser::extractMessageContent(['ephemeralMessage' => ['message' => ['extendedTextMessage' => ['text' => 'oi temporário']]]]);
ok('texto de mensagem temporária é lido', $t === 'text' && $c === 'oi temporário');
[$t, , $cap] = Parser::extractMessageContent(['ephemeralMessage' => ['message' => ['imageMessage' => ['caption' => 'foto', 'mimetype' => 'image/jpeg']]]]);
ok('foto de mensagem temporária vira imagem com legenda', $t === 'image' && $cap === 'foto');
[$t] = Parser::extractMessageContent(['viewOnceMessageV2' => ['message' => ['imageMessage' => ['mimetype' => 'image/jpeg']]]]);
ok('foto de visualização única vira imagem', $t === 'image');
[$t, , , , $mime] = Parser::extractMessageContent(['ptvMessage' => ['mimetype' => 'video/mp4']]);
ok('recado em vídeo (redondo) vira vídeo', $t === 'video' && $mime === 'video/mp4');
[$t, $c] = Parser::extractMessageContent(['conversation' => 'texto simples']);
ok('mensagem sem envelope continua igual', $t === 'text' && $c === 'texto simples');
[$t] = Parser::extractMessageContent(['senderKeyDistributionMessage' => ['x' => 1]]);
ok('protocolo continua sendo ignorado', $t === 'ignore');

echo "\n== 5. Telefone do cliente x número da conversa ==\n";
ok('"(11) 98888-7777" casa com e sem o nono dígito', VinculosCliente::variantesDeTelefone('(11) 98888-7777') === ['5511988887777', '551188887777']);
ok('já com 55 dá o mesmo resultado', VinculosCliente::variantesDeTelefone('5511988887777') === ['5511988887777', '551188887777']);
ok('número antigo de 8 dígitos ganha a forma com o 9', VinculosCliente::variantesDeTelefone('+55 11 8888-7777') === ['551188887777', '5511988887777']);
ok('zero de operadora na frente é descartado', VinculosCliente::variantesDeTelefone('011 98888-7777') === ['5511988887777', '551188887777']);
ok('número estrangeiro só casa exato', VinculosCliente::variantesDeTelefone('351912345678') === ['351912345678']);
ok('curto demais ou vazio não casa com nada', VinculosCliente::variantesDeTelefone('123') === [] && VinculosCliente::variantesDeTelefone('') === []);

/* ── 6 e 7: com banco, dentro de transação desfeita ───────────────────────── */
echo "\n== 6. Gravação e segunda tentativa (banco, desfeito no fim) ==\n";

/** Evolution de mentira: devolve o base64 combinado e conta as chamadas. */
final class EvoMidiaFalsa extends \App\WhatsAppAgente\EvolutionApiService
{
    public int $chamadas = 0;
    public ?string $devolve = null;
    public bool $explode = false;
    public function __construct() { parent::__construct([]); }
    public function getMediaBase64(string $name, array $rawPayload): ?string
    {
        $this->chamadas++;
        if ($this->explode) throw new \RuntimeException('Evolution fora do ar');
        return $this->devolve;
    }
}

try {
    $pdo = \App\Core\Database::getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $insts = $pdo->query('SELECT id, account_id FROM whatsapp_instances ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $insts = [];
    echo '  [pulado] sem banco: ' . $e->getMessage() . "\n";
}

if (!$insts) {
    echo "  [pulado] precisa de uma instância de WhatsApp\n";
} else {
    $INST = (int) $insts[0]['id']; $ACC = (int) $insts[0]['account_id'];
    $pdo->beginTransaction();
    try {
        $model = new WhatsAppMessage();
        $jid   = '5511900000911@s.whatsapp.net';
        $linha = static function (int $id) use ($pdo) {
            $st = $pdo->prepare('SELECT message_type, media_base64 FROM whatsapp_messages WHERE id = ?');
            $st->execute([$id]);
            return $st->fetch(\PDO::FETCH_ASSOC);
        };
        $nova = static fn(string $wamid, array $extra) => $model->save($extra + [
            'account_id' => $ACC, 'instance_id' => $INST, 'wamid' => $wamid, 'remote_jid' => $jid,
            'contact_name' => 'Teste Midia', 'phone' => '5511900000911', 'direction' => 'inbound', 'status' => 'delivered',
        ]);

        // a) mídia maior que o banco aguenta: a mensagem entra, sem o binário
        $limite = MC::limiteDoBanco($pdo);
        ok('o teto real do banco nunca passa do limite da coluna', $limite > 0 && $limite <= MC::LIMITE_BASE64);
        $grande = str_repeat('A', $limite + 10);
        $idG = $nova('TESTE_MIDIA_GRANDE', ['message_type' => 'video', 'media_mimetype' => 'video/mp4', 'media_base64' => $grande, 'media_is_full' => 1]);
        $g = $linha($idG);
        ok('mídia grande demais NÃO derruba a mensagem', $idG > 0 && ($g['message_type'] ?? '') === 'video');
        ok('... e a mensagem fica sem o binário (sai sob demanda)', $g && $g['media_base64'] === null);
        unset($grande);

        // b) reenvio da mesma mensagem com mídia grande: continua inteira
        $idG2 = $nova('TESTE_MIDIA_GRANDE', ['message_type' => 'video', 'media_base64' => str_repeat('B', $limite + 10), 'media_is_full' => 1, 'status' => 'read']);
        ok('reenvio com mídia grande atualiza a mesma linha', $idG2 === $idG && $linha($idG)['media_base64'] === null);

        // c) miniatura gravada pelo webhook, depois a segunda tentativa traz o arquivo
        $idP = $nova('TESTE_MIDIA_PDF', ['message_type' => 'document', 'media_mimetype' => 'application/pdf', 'media_filename' => 'contrato.pdf',
            'media_base64' => $thumb, 'media_is_full' => 0, 'raw_payload' => json_encode($payload('documentMessage'))]);
        ok('antes: o cache do PDF é a miniatura', MC::ehMiniatura((string) $linha($idP)['media_base64'], json_encode($payload('documentMessage')), 'document', 'application/pdf'));

        $evo = new EvoMidiaFalsa();
        $evo->devolve = 'data:application/pdf;base64,' . base64_encode($PDF);
        ok('completar() grava o arquivo', MC::completar($pdo, $evo, 'x', $idP, $payload('documentMessage'), 'application/pdf') === true);
        ok('... por cima da miniatura, sem o prefixo data:', $linha($idP)['media_base64'] === base64_encode($PDF));
        ok('... com uma chamada à Evolution', $evo->chamadas === 1);

        // d) Evolution devolve vazio: nada muda
        $idV = $nova('TESTE_MIDIA_VAZIA', ['message_type' => 'image', 'media_mimetype' => 'image/jpeg', 'media_base64' => $thumb]);
        $evo->devolve = null;
        ok('completar() com resposta vazia devolve false', MC::completar($pdo, $evo, 'x', $idV, $payload('imageMessage'), 'image/jpeg') === false);
        ok('... e a miniatura continua lá', $linha($idV)['media_base64'] === $thumb);

        // e) Evolution devolve algo que não é arquivo: não grava lixo
        $evo->devolve = base64_encode("\x8A\x11\x02\xF0\x9C\x33\x71\x00\x05\xEE" . str_repeat("\x07", 50));
        ok('completar() recusa conteúdo não reconhecido', MC::completar($pdo, $evo, 'x', $idV, $payload('imageMessage'), 'image/jpeg') === false);
        ok('... e não troca a miniatura por lixo', $linha($idV)['media_base64'] === $thumb);

        // f) Evolution fora do ar: nunca lança
        $evo->explode = true;
        ok('completar() engole a exceção da Evolution', MC::completar($pdo, $evo, 'x', $idV, $payload('imageMessage'), 'image/jpeg') === false);
        ok('completar() com id inválido nem chama a Evolution', (function () use ($pdo, $evo, $payload) {
            $antes = $evo->chamadas;
            return MC::completar($pdo, $evo, 'x', 0, $payload('imageMessage')) === false && $evo->chamadas === $antes;
        })());

        echo "\n== 7. Conversa na ficha do cliente pelo telefone (banco, desfeito no fim) ==\n";
        $outra = null;
        foreach ($insts as $i) { if ((int) $i['account_id'] !== $ACC) { $outra = $i; break; } }

        $pdo->prepare('INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, phone, is_group) VALUES (?,?,?,?,?,0)')
            ->execute([$ACC, $INST, '5511955550001@s.whatsapp.net', 'Cliente Pelo Telefone', '5511955550001']);
        $chatA = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, phone, is_group) VALUES (?,?,?,?,?,1)')
            ->execute([$ACC, $INST, '5511955550001-1600000000@g.us', 'Grupo com o mesmo número', '5511955550001']);

        $setor = $pdo->prepare('SELECT id FROM clientes_setores WHERE account_id = ? ORDER BY id LIMIT 1');
        $setor->execute([$ACC]);
        $setorId = (int) ($setor->fetchColumn() ?: 0);   // setor_id é NOT NULL; 0 serve ao teste
        $pdo->prepare('INSERT INTO clientes (account_id, setor_id, nome, whatsapp, status, created_at, updated_at) VALUES (?,?,?,?,?,NOW(),NOW())')
            ->execute([$ACC, $setorId, 'Cliente Teste Conversa', '(11) 95555-0001', 'ativo']);
        $cli = (int) $pdo->lastInsertId();

        $conv = VinculosCliente::conversas($cli, [$ACC]);
        ok('a conversa com o número do cliente aparece na ficha', count($conv) === 1 && (int) $conv[0]['id'] === $chatA);
        ok('... com telefone e sem origem de prospecção', ($conv[0]['phone'] ?? '') === '5511955550001' && $conv[0]['origem_titulo'] === null);
        ok('grupo não entra, mesmo com o número igual', !in_array('5511955550001-1600000000@g.us', array_column($conv, 'remote_jid'), true));

        $pdo->prepare('UPDATE clientes SET whatsapp = NULL, telefone = ? WHERE id = ?')->execute(['11 5555-0001', $cli]);
        ok('telefone sem o nono dígito também acha a conversa', count(VinculosCliente::conversas($cli, [$ACC])) === 1);

        $pdo->prepare('UPDATE clientes SET telefone = ? WHERE id = ?')->execute(['(11) 94444-0000', $cli]);
        ok('telefone de outra pessoa não acha nada', VinculosCliente::conversas($cli, [$ACC]) === []);

        $pdo->prepare('UPDATE clientes SET whatsapp = ? WHERE id = ?')->execute(['5511955550001', $cli]);
        ok('outra conta não enxerga o cliente nem a conversa', VinculosCliente::conversas($cli, [$ACC + 987654]) === []);
        if ($outra) {
            $pdo->prepare('INSERT INTO whatsapp_chats (account_id, instance_id, remote_jid, contact_name, phone, is_group) VALUES (?,?,?,?,?,0)')
                ->execute([(int) $outra['account_id'], (int) $outra['id'], '5511955550001@s.whatsapp.net', 'Mesmo número, outro escritório', '5511955550001']);
            $conv = VinculosCliente::conversas($cli, [$ACC]);
            ok('conversa do MESMO número em outra conta não vaza para esta', count($conv) === 1 && (int) $conv[0]['id'] === $chatA);
        } else {
            echo "  [pulado] só há uma conta com canal: sem o caso de vazamento entre contas\n";
        }

        $pdo->prepare('UPDATE clientes SET deleted_at = NOW() WHERE id = ?')->execute([$cli]);
        ok('cliente excluído não resolve conversa', VinculosCliente::conversas($cli, [$ACC]) === []);
    } catch (\Throwable $e) {
        ok('integração sem exceção: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

echo "\n----\n";
if (!$FALHAS) {
    echo "Resultado: {$OK} ok · 0 falha(s)\n";
    exit(0);
}
echo 'Resultado: ' . $OK . ' ok · ' . count($FALHAS) . " falha(s)\n\n";
foreach ($FALHAS as $f) echo "  - $f\n";
exit(1);
