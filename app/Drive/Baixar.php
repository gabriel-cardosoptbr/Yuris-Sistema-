<?php
namespace App\Drive;

/**
 * Baixar do Drive do cliente pelo sistema (21/10/2026): um arquivo ou vários num
 * .zip, em fluxo (o servidor repassa os pedaços que chegam do Google, sem guardar
 * nada). Só arquivo visto dentro da árvore do cliente. Documento do Google
 * (Docs, Planilhas, Apresentações, Desenhos) vai convertido para o formato do
 * Office; formulário e atalho não têm como baixar e ficam de fora.
 */
final class Baixar
{
    /** Limite do .zip (sem ZIP64): o que passar disso se baixa um a um ou pelo Drive. */
    public const LIMITE_ZIP = 2147483648;

    private const EXPORTAR = [
        'application/vnd.google-apps.document' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'docx'],
        'application/vnd.google-apps.spreadsheet' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'],
        'application/vnd.google-apps.presentation' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'pptx'],
        'application/vnd.google-apps.drawing' => ['image/png', 'png'],
    ];

    /** Endereço e nome do download de um item; null quando não há como baixar. */
    public static function como(array $item): ?array
    {
        if ($item['pasta']) return null;
        $mime = (string) $item['mime'];
        if (str_starts_with($mime, 'application/vnd.google-apps.')) {
            $x = self::EXPORTAR[$mime] ?? null;
            if (!$x) return null;
            $nome = preg_replace('/\.' . $x[1] . '$/i', '', (string) $item['nome']) . '.' . $x[1];
            return ['url' => Api::DRIVE . '/files/' . rawurlencode($item['id']) . '/export?' . http_build_query(['mimeType' => $x[0]]), 'nome' => $nome, 'mime' => $x[0]];
        }
        return ['url' => Api::DRIVE . '/files/' . rawurlencode($item['id']) . '?' . http_build_query(['alt' => 'media', 'supportsAllDrives' => 'true']), 'nome' => (string) $item['nome'], 'mime' => $mime];
    }

    public static function cabecalhoNome(string $nome): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9._ ()-]/', '_', $nome) ?: 'arquivo';
        return 'attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($nome);
    }

    /**
     * Um arquivo. $cabecalhos recebe os cabeçalhos HTTP (só chamado se o Google
     * respondeu), $saida cada pedaço.
     */
    public static function arquivo(int $accountId, int $clienteId, string $fileId, callable $cabecalhos, callable $saida): void
    {
        $item = Pasta::itemDoCliente($accountId, $clienteId, $fileId);
        if (!$item) throw new \InvalidArgumentException('Arquivo fora da pasta do cliente.');
        $c = self::como($item);
        if (!$c) throw new \InvalidArgumentException('Este item do Google não tem como ser baixado (formulário, atalho ou pasta). Abra no Drive.');
        Api::transmitir(Conexao::acesso($accountId), $c['url'], $saida, function () use ($cabecalhos, $c, $item) {
            $cabecalhos(['Content-Type' => $c['mime'] ?: 'application/octet-stream', 'Content-Disposition' => self::cabecalhoNome($c['nome'])]
                + ($item['tamanho'] && !str_starts_with((string) $item['mime'], 'application/vnd.google-apps.') ? ['Content-Length' => (string) $item['tamanho']] : []));
        });
    }

    /**
     * Vários arquivos de uma pasta do cliente num .zip. Confere tudo antes de
     * começar (depois do primeiro byte não dá mais para responder com erro).
     * @return array{itens:array, fora:array} o que vai e o que fica de fora
     */
    public static function planoZip(int $accountId, int $clienteId, ?string $folderId, ?array $ids): array
    {
        $vai = []; $fora = []; $total = 0;
        foreach (Pasta::arquivosDaPasta($accountId, $clienteId, $folderId, $ids) as $i) {
            $c = self::como($i);
            if (!$c) { $fora[] = $i['nome']; continue; }
            $total += (int) ($i['tamanho'] ?? 0);
            $vai[] = $i + ['download' => $c];
        }
        if (!$vai) throw new \InvalidArgumentException('Nenhum arquivo para baixar aqui.');
        if ($total > self::LIMITE_ZIP) throw new \InvalidArgumentException('Passa de 2 GB juntos. Baixe por partes (selecione alguns) ou pela tela do Drive.');
        return ['itens' => $vai, 'fora' => $fora, 'total' => $total];
    }

    public static function zip(int $accountId, array $plano, callable $saida): void
    {
        $acesso = Conexao::acesso($accountId);
        $zip = new ZipEmFluxo($saida);
        $falhas = [];
        foreach ($plano['itens'] as $i) {
            $zip->adicionar($i['download']['nome'], function (callable $escreve) use ($acesso, $i, &$falhas) {
                try {
                    Api::transmitir($acesso, $i['download']['url'], $escreve);
                } catch (DriveErro $e) {
                    // No meio do zip não há como avisar na tela: o arquivo vai vazio e o motivo vai num .txt.
                    $falhas[] = $i['nome'] . ': ' . $e->getMessage();
                    error_log('[drive zip] ' . $i['nome'] . ': ' . $e->getMessage());
                }
            }, $i['modificado_em'] ? strtotime($i['modificado_em']) : null);
        }
        if ($plano['fora']) {
            $zip->adicionar('LEIA - itens que ficaram de fora.txt', function (callable $escreve) use ($plano) {
                $escreve("Estes itens do Google não têm como ser baixados (formulário, atalho). Abra no Drive:\r\n\r\n" . implode("\r\n", $plano['fora']) . "\r\n");
            });
        }
        if ($falhas) {
            $zip->adicionar('LEIA - arquivos que falharam.txt', function (callable $escreve) use ($falhas) {
                $escreve("Estes arquivos não vieram do Google (estão vazios no zip). Baixe de novo ou pelo Drive:

" . implode("
", $falhas) . "
");
            });
        }
        $zip->fechar();
    }
}
