<?php
namespace App\Contratos;

use App\Core\EnvLoader;

/**
 * Os arquivos dos contratos no disco: PDF original de cada versão, comprovante,
 * PDF de conclusão e manifesto.
 *
 * - Pasta privada, fora de public/ (CONTRATOS_ARMAZENAMENTO; padrão storage/contratos,
 *   que está no .gitignore). Nada aqui é servido pelo Apache: sai só pela API, com
 *   sessão e permissão.
 * - Nome pelo conteúdo: <conta>/<2 primeiros do hash>/<sha256>.<ext>. O mesmo
 *   conteúdo cai sempre no mesmo arquivo, e um arquivo existente NUNCA é
 *   sobrescrito: se já existe, confere que o hash bate e reaproveita.
 * - Gravação atômica (arquivo temporário + rename) e permissão só de leitura
 *   depois de gravado. Na leitura o hash é conferido de novo (ler()): arquivo
 *   mexido no disco é detectado, não servido como se fosse o original.
 *
 * O que isto NÃO garante: quem tem root no servidor apaga ou troca o arquivo. O
 * hash no banco e no manifesto assinado é o que permite perceber. Ver o README.
 */
final class Armazenamento
{
    public const MAX_PDF_BYTES = 20 * 1024 * 1024;

    public static function base(): string
    {
        $b = EnvLoader::get('CONTRATOS_ARMAZENAMENTO');
        return rtrim($b !== '' ? $b : dirname(__DIR__, 2) . '/storage/contratos', '/\\');
    }

    /** @return array{caminho:string, sha256:string, bytes:int} */
    public static function gravar(int $accountId, string $conteudo, string $ext): array
    {
        if ($accountId <= 0) throw new \InvalidArgumentException('Conta inválida.');
        if (!preg_match('/^[a-z]{2,8}$/', $ext)) throw new \InvalidArgumentException('Extensão inválida.');
        $sha = hash('sha256', $conteudo);
        $rel = $accountId . '/' . substr($sha, 0, 2) . '/' . $sha . '.' . $ext;
        $abs = self::base() . '/' . $rel;

        if (is_file($abs)) {
            if (hash_file('sha256', $abs) !== $sha) {
                throw new \RuntimeException('Arquivo já gravado com conteúdo diferente: integridade comprometida.');
            }
            return ['caminho' => $rel, 'sha256' => $sha, 'bytes' => strlen($conteudo)];
        }
        $dir = dirname($abs);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Não foi possível criar a pasta dos contratos.');
        }
        $tmp = $dir . '/.tmp-' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $conteudo, LOCK_EX) !== strlen($conteudo)) {
            @unlink($tmp);
            throw new \RuntimeException('Falha ao gravar o arquivo do contrato.');
        }
        @chmod($tmp, 0440);
        if (is_file($abs)) { @unlink($tmp); return self::gravar($accountId, $conteudo, $ext); }
        if (!@rename($tmp, $abs)) { @unlink($tmp); throw new \RuntimeException('Falha ao gravar o arquivo do contrato.'); }
        return ['caminho' => $rel, 'sha256' => $sha, 'bytes' => strlen($conteudo)];
    }

    /** Lê e confere o hash; null se sumiu ou não bate. */
    public static function ler(string $rel, string $shaEsperado): ?string
    {
        $abs = self::caminhoSeguro($rel);
        if ($abs === null || !is_file($abs)) return null;
        $b = file_get_contents($abs);
        if ($b === false || !hash_equals($shaEsperado, hash('sha256', $b))) return null;
        return $b;
    }

    public static function existeIntegro(string $rel, string $shaEsperado): bool
    {
        $abs = self::caminhoSeguro($rel);
        return $abs !== null && is_file($abs) && hash_equals($shaEsperado, (string) hash_file('sha256', $abs));
    }

    private static function caminhoSeguro(string $rel): ?string
    {
        if (!preg_match('#^\d+/[0-9a-f]{2}/[0-9a-f]{64}\.[a-z]{2,8}$#', $rel)) return null;
        return self::base() . '/' . $rel;
    }

    public static function ehPdf(string $b): bool
    {
        return strlen($b) > 64 && str_starts_with($b, '%PDF-') && str_contains(substr($b, -2048), '%%EOF');
    }
}
