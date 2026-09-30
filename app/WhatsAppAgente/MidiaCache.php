<?php
namespace App\WhatsAppAgente;

/**
 * MidiaCache: as regras do que pode morar em `whatsapp_messages.media_base64`.
 *
 * A coluna guarda duas coisas diferentes, e o sistema não distinguia uma da outra:
 *
 *   - o ARQUIVO de verdade (foto, áudio, PDF, vídeo), baixado da Evolution;
 *   - a MINIATURA (`jpegThumbnail`, um JPEG de poucos KB) que o WhatsApp manda
 *     embutida no payload, gravada pelo webhook e pelo sync como quebra-galho
 *     quando o download do arquivo não terminou a tempo.
 *
 * O `media.php` entregava o que estivesse na coluna. Quando era a miniatura de um
 * PDF ou de um vídeo, o usuário baixava um "PDF" que era um JPEG e o arquivo não
 * abria; quando era a de uma foto, via a imagem borrada para sempre, porque o
 * arquivo inteiro nunca mais era buscado. Estas funções dizem qual dos dois está
 * guardado, para o proxy ir buscar o arquivo em vez de servir a miniatura.
 *
 * Tudo aqui é PURO (sem banco, sem rede), exceto `completar()`, que é a busca do
 * arquivo inteiro feita depois que o webhook já respondeu 200.
 */
final class MidiaCache
{
    /**
     * `media_base64` é MEDIUMTEXT: 16.777.215 bytes. Base64 maior que isso faz o
     * INSERT falhar (modo estrito) ou grava truncado, e antes derrubava a mensagem
     * inteira junto. Fica a margem de 1,7 MB para o resto da linha.
     */
    public const LIMITE_BASE64 = 15_000_000;

    /** Miniatura é pequena. Acima disto nem vale decodificar para comparar. */
    private const TETO_MINIATURA = 300_000;

    private const ENVELOPES = [
        'ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2',
        'viewOnceMessageV2Extension', 'documentWithCaptionMessage',
    ];

    private const SUBS_COM_MINIATURA = [
        'imageMessage', 'videoMessage', 'documentMessage', 'stickerMessage', 'ptvMessage',
    ];

    /** Tira o prefixo `data:mime;base64,` se houver. */
    public static function semPrefixo(string $b64): string
    {
        return str_contains($b64, ',') ? explode(',', $b64, 2)[1] : $b64;
    }

    public static function cabeNoBanco(?string $b64, ?\PDO $pdo = null): bool
    {
        if ($b64 === null) return true;
        return strlen($b64) <= ($pdo ? self::limiteDoBanco($pdo) : self::LIMITE_BASE64);
    }

    /**
     * O teto real deste servidor: o da coluna ou o do `max_allowed_packet`, o que
     * for menor. Pacote maior que o permitido não dá só erro: o servidor DERRUBA a
     * conexão, e a tentativa seguinte (gravar sem a mídia) falharia junto. Por isso
     * a conta é feita antes de mandar. Uma consulta por processo.
     */
    public static function limiteDoBanco(\PDO $pdo): int
    {
        static $limite = null;
        if ($limite === null) {
            $limite = self::LIMITE_BASE64;
            try {
                $pacote = (int) $pdo->query('SELECT @@max_allowed_packet')->fetchColumn();
                if ($pacote > 0) {
                    // Folga para o resto do comando (payload cru, demais colunas).
                    $limite = max(0, min($limite, $pacote - 262_144));
                }
            } catch (\Throwable $_) {}
        }
        return $limite;
    }

    /**
     * Desembrulha o objeto `message`: mensagem temporária, de visualização única e
     * documento com legenda chegam dentro de um envelope, e quem procura
     * `imageMessage` na raiz não acha nada.
     */
    public static function desembrulhar(array $message): array
    {
        for ($i = 0; $i < 4; $i++) {
            $achou = false;
            foreach (self::ENVELOPES as $env) {
                if (isset($message[$env]['message']) && is_array($message[$env]['message'])) {
                    $message = $message[$env]['message'];
                    $achou   = true;
                    break;
                }
            }
            if (!$achou) break;
        }
        return $message;
    }

    /**
     * A miniatura embutida no payload cru, em base64 sem prefixo, ou null.
     * Aceita o payload como JSON (como está no banco) ou já decodificado.
     */
    public static function miniaturaDoPayload(string|array|null $rawPayload): ?string
    {
        if (is_string($rawPayload)) {
            $rawPayload = $rawPayload === '' ? null : json_decode($rawPayload, true);
        }
        if (!is_array($rawPayload)) return null;

        $message = $rawPayload['message'] ?? null;
        if (!is_array($message)) return null;
        $message = self::desembrulhar($message);

        foreach (self::SUBS_COM_MINIATURA as $sub) {
            $t = $message[$sub]['jpegThumbnail'] ?? null;
            // Só string: versões antigas da Evolution mandam o Buffer como objeto
            // de bytes, que nunca foi gravado como miniatura.
            if (is_string($t) && $t !== '') {
                return self::semPrefixo($t);
            }
        }
        return null;
    }

    /**
     * O que está no cache é a miniatura, e não o arquivo?
     *
     * Duas provas, da mais forte para a mais fraca:
     *  1. o conteúdo é idêntico ao `jpegThumbnail` do payload cru;
     *  2. sem payload (ele é apagado aos 30 dias pela retenção): é um JPEG guardado
     *     numa mensagem cujo tipo não pode ser JPEG (vídeo, áudio, figurinha, ou
     *     documento que não se declara imagem).
     *
     * Foto sem payload não tem como ser julgada, e fica valendo o que está gravado.
     */
    public static function ehMiniatura(string $cacheB64, string|array|null $rawPayload, string $tipo, ?string $mime): bool
    {
        $cacheB64 = self::semPrefixo($cacheB64);
        if ($cacheB64 === '' || strlen($cacheB64) > self::TETO_MINIATURA) return false;

        $thumb = self::miniaturaDoPayload($rawPayload);
        if ($thumb !== null) {
            if ($thumb === $cacheB64) return true;
            // Mesmos bytes escritos de outro jeito (quebra de linha, padding).
            $a = base64_decode($thumb, false);
            $b = base64_decode($cacheB64, false);
            if ($a !== false && $a !== '' && $a === $b) return true;
        }

        $inicio = base64_decode(substr($cacheB64, 0, 16), false);
        $ehJpeg = is_string($inicio) && str_starts_with($inicio, "\xFF\xD8");
        if (!$ehJpeg) return false;

        $mime = strtolower(trim(explode(';', (string) $mime)[0]));
        return match ($tipo) {
            'video', 'audio', 'sticker' => true,
            'document'                  => !str_starts_with($mime, 'image/'),
            default                     => false,
        };
    }

    /**
     * Os primeiros bytes são de um arquivo de verdade?
     *
     * Serve para decidir se o que voltou da Evolution pode ser guardado. Antes só
     * imagem, OGG e MP3 passavam: PDF, planilha, Word e vídeo MP4 nunca eram
     * guardados, e sumiam quando o payload cru era apagado aos 30 dias.
     */
    public static function conteudoReconhecido(string $inicio, ?string $mime = null): bool
    {
        if (strlen($inicio) < 4) return false;

        $assinaturas = [
            "\xFF\xD8",             // JPEG
            "\x89PNG",              // PNG
            'GIF8',                 // GIF
            'RIFF',                 // WebP, WAV, AVI
            'OggS',                 // OGG / Opus (áudio de voz)
            'ID3',                  // MP3 com etiqueta
            "\xFF\xFB", "\xFF\xF3", "\xFF\xF2", // MP3 sem etiqueta
            "\xFF\xF1", "\xFF\xF9", // AAC
            '%PDF',                 // PDF
            "PK\x03\x04",           // DOCX, XLSX, PPTX, ZIP
            "\xD0\xCF\x11\xE0",     // DOC, XLS, PPT antigos
            "\x1A\x45\xDF\xA3",     // WebM, MKV
            '#!AMR',                // AMR
            'fLaC',                 // FLAC
            'Rar!',                 // RAR
            "7z\xBC\xAF",           // 7-Zip
            '{\rtf',                // RTF
        ];
        foreach ($assinaturas as $a) {
            if (str_starts_with($inicio, $a)) return true;
        }
        // MP4, M4A, MOV, 3GP: "ftyp" a partir do 5º byte.
        if (strlen($inicio) >= 8 && substr($inicio, 4, 4) === 'ftyp') return true;

        // Texto puro (CSV, TXT, XML, JSON, vCard) não tem assinatura: aceita quando
        // o tipo declarado é de texto e o começo é texto legível de fato.
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));
        $declaradoTexto = str_starts_with($mime, 'text/')
            || in_array($mime, ['application/json', 'application/xml', 'application/csv'], true);
        if ($declaradoTexto) {
            $amostra = preg_replace('/^\xEF\xBB\xBF/', '', $inicio);
            // Corta um possível caractere multibyte partido no fim da amostra.
            $amostra = (string) preg_replace('/[\x80-\xFF]+$/', '', $amostra);
            return $amostra !== ''
                && preg_match('//u', $amostra) === 1
                && preg_match('/[\x00-\x08\x0E-\x1F]/', $amostra) === 0;
        }
        return false;
    }

    /** O binário (já sem prefixo) pode ser guardado na coluna? */
    public static function podeGuardar(string $b64, ?string $mime = null, ?\PDO $pdo = null): bool
    {
        if ($b64 === '' || !self::cabeNoBanco($b64, $pdo)) return false;
        $inicio = base64_decode(substr($b64, 0, 24), false);
        return is_string($inicio) && self::conteudoReconhecido($inicio, $mime);
    }

    /**
     * Busca o arquivo inteiro de uma mensagem cujo download não terminou dentro do
     * tempo curto do webhook, e grava por cima da miniatura.
     *
     * Roda DEPOIS do 200 (mesma fila do agente), então pode esperar mais. Nunca
     * lança: mídia que não veio continua disponível sob demanda pelo `media.php`.
     *
     * @return bool true se o arquivo foi gravado
     */
    public static function completar(\PDO $pdo, EvolutionApiService $evo, string $instancia, int $mensagemId, array $payload, ?string $mime = null): bool
    {
        try {
            if ($mensagemId <= 0) return false;
            $b64 = $evo->getMediaBase64($instancia, $payload);
            if (!is_string($b64) || $b64 === '') return false;
            $b64 = self::semPrefixo($b64);
            if (!self::podeGuardar($b64, $mime, $pdo)) return false;

            $pdo->prepare('UPDATE whatsapp_messages SET media_base64 = ? WHERE id = ?')
                ->execute([$b64, $mensagemId]);
            return true;
        } catch (\Throwable $e) {
            error_log('[whatsapp/midia] completar falhou msg=' . $mensagemId . ': ' . $e->getMessage());
            return false;
        }
    }
}
