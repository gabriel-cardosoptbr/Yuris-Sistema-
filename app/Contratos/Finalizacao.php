<?php
namespace App\Contratos;

use App\Core\Database;
use App\Core\EnvLoader;

/**
 * Depois da última assinatura obrigatória: o comprovante, o PDF de conclusão
 * (original + comprovante) e o manifesto selado.
 *
 * Roda fora da transação da assinatura: se falhar, a assinatura já está gravada e
 * nada se perde. O contrato fica com finalizacao_status 'pendente' ou 'erro' e o
 * worker (bin/contratos_worker.php) tenta de novo, com espera crescente.
 * Cada passo reaproveita o que já foi gerado (arquivo pelo hash), então repetir é
 * seguro. Uma trava por contrato (GET_LOCK) impede dois processos juntos.
 *
 * O PDF de conclusão junta as páginas com o qpdf (instalado na imagem Docker). O
 * PDF original continua preservado, intacto, como arquivo próprio.
 */
final class Finalizacao
{
    public const MAX_TENTATIVAS = 12;

    /** @return string 'ok' | 'pendente' | 'erro' | 'ocupado' | 'nao_se_aplica' */
    public static function processar(int $contratoId): string
    {
        $pdo = Database::getConnection();
        $lk = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lk->execute(['contrato_final_' . $contratoId]);
        if ((int) $lk->fetchColumn() !== 1) return 'ocupado';
        try {
            $st = $pdo->prepare('SELECT * FROM crm_contratos WHERE id = ?');
            $st->execute([$contratoId]);
            $c = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$c || $c['status'] !== 'concluido' || $c['finalizacao_status'] === 'ok') return $c['finalizacao_status'] ?? 'nao_se_aplica';
            $pdo->prepare('UPDATE crm_contratos SET finalizacao_tentativas = finalizacao_tentativas + 1 WHERE id = ?')->execute([$contratoId]);
            try {
                self::gerar($c);
                $pdo->prepare("UPDATE crm_contratos SET finalizacao_status = 'ok', finalizacao_erro = NULL, finalizacao_proxima = NULL WHERE id = ?")->execute([$contratoId]);
                Eventos::registrar($contratoId, 'finalizado', ['observacao' => 'Comprovante, PDF de conclusão e manifesto gerados.']);
                return 'ok';
            } catch (\Throwable $e) {
                $t = (int) $c['finalizacao_tentativas'] + 1;
                $espera = min(3600, 60 * (2 ** min($t, 6)));
                $pdo->prepare("UPDATE crm_contratos SET finalizacao_status = 'erro', finalizacao_erro = ?, finalizacao_proxima = ? WHERE id = ?")
                    ->execute([mb_substr($e->getMessage(), 0, 500), $t >= self::MAX_TENTATIVAS ? null : Tempo::daquiA($espera), $contratoId]);
                error_log('[contratos] finalização do contrato ' . $contratoId . ' falhou (tentativa ' . $t . '): ' . $e->getMessage());
                return 'erro';
            }
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute(['contrato_final_' . $contratoId]);
        }
    }

    /** Os contratos que esperam finalização (worker). */
    public static function pendentes(int $limite = 20): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT id FROM crm_contratos WHERE status = 'concluido' AND finalizacao_status IN ('pendente','erro')
                AND finalizacao_proxima IS NOT NULL AND finalizacao_proxima <= ? ORDER BY finalizacao_proxima LIMIT " . (int) $limite
        );
        $st->execute([Tempo::agora()]);
        return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function gerar(array $c): void
    {
        $pdo = Database::getConnection();
        $acc = (int) $c['account_id'];
        $v = $pdo->prepare('SELECT v.*, a.caminho, a.bytes FROM crm_contrato_versoes v JOIN crm_contrato_arquivos a ON a.id = v.arquivo_id WHERE v.id = ?');
        $v->execute([(int) $c['versao_atual_id']]);
        $versao = $v->fetch(\PDO::FETCH_ASSOC);
        $original = Armazenamento::ler((string) $versao['caminho'], (string) $versao['sha256']);
        if ($original === null) throw new \RuntimeException('O PDF original não confere com o hash gravado.');

        $as = $pdo->prepare('SELECT * FROM crm_contrato_assinaturas WHERE contrato_id = ? AND versao_id = ? ORDER BY assinado_em, id');
        $as->execute([(int) $c['id'], (int) $versao['id']]);
        $assinaturas = $as->fetchAll(\PDO::FETCH_ASSOC);
        if (!$assinaturas) throw new \RuntimeException('Contrato concluído sem assinaturas na versão atual.');
        $empresa = Contrato::nomeDaMarca($acc);
        $verificacao = Convite::enderecoBase($acc) . '/verificar?c=' . $c['codigo_verificacao'];

        $comp = self::arquivoExistente((int) $c['id'], 'comprovante');
        if (!$comp) {
            $bytes = self::comprovante($c, $versao, $assinaturas, $empresa, $verificacao);
            $comp = self::guardar($c, (int) $versao['id'], 'comprovante', $bytes, 'application/pdf', 'pdf');
        }

        $conc = self::arquivoExistente((int) $c['id'], 'conclusao');
        if (!$conc) {
            $compBytes = Armazenamento::ler($comp['caminho'], $comp['sha256']);
            if ($compBytes === null) throw new \RuntimeException('O comprovante não confere com o hash gravado.');
            $bytes = self::juntar($original, $compBytes);
            $conc = self::guardar($c, (int) $versao['id'], 'conclusao', $bytes, 'application/pdf', 'pdf');
        }

        if (!self::arquivoExistente((int) $c['id'], 'manifesto')) {
            $c2 = $pdo->prepare('SELECT * FROM crm_contratos WHERE id = ?');
            $c2->execute([(int) $c['id']]);
            $man = Manifesto::montar($c2->fetch(\PDO::FETCH_ASSOC), $versao, $assinaturas, Eventos::verificar((int) $c['id']), [
                'original'    => ['sha256' => $versao['sha256'], 'bytes' => (int) $versao['bytes']],
                'comprovante' => ['sha256' => $comp['sha256'], 'bytes' => (int) $comp['bytes']],
                'conclusao'   => ['sha256' => $conc['sha256'], 'bytes' => (int) $conc['bytes']],
            ], $empresa);
            $json = json_encode($man, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            self::guardar($c, (int) $versao['id'], 'manifesto', $json, 'application/json', 'json');
        }
    }

    private static function arquivoExistente(int $contratoId, string $tipo): ?array
    {
        $st = Database::getConnection()->prepare('SELECT * FROM crm_contrato_arquivos WHERE contrato_id = ? AND tipo = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$contratoId, $tipo]);
        $a = $st->fetch(\PDO::FETCH_ASSOC);
        return $a && Armazenamento::existeIntegro((string) $a['caminho'], (string) $a['sha256']) ? $a : null;
    }

    private static function guardar(array $c, int $versaoId, string $tipo, string $bytes, string $mime, string $ext): array
    {
        $g = Armazenamento::gravar((int) $c['account_id'], $bytes, $ext);
        $pdo = Database::getConnection();
        $pdo->prepare('INSERT INTO crm_contrato_arquivos (uuid, account_id, contrato_id, versao_id, tipo, sha256, bytes, mime, caminho, criado_em)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([Cripto::uuid(), (int) $c['account_id'], (int) $c['id'], $versaoId, $tipo, $g['sha256'], $g['bytes'], $mime, $g['caminho'], Tempo::agora()]);
        Eventos::registrar((int) $c['id'], 'arquivo_gerado', ['tipo' => $tipo, 'sha256' => $g['sha256'], 'bytes' => $g['bytes']], ['versao_id' => $versaoId]);
        return ['caminho' => $g['caminho'], 'sha256' => $g['sha256'], 'bytes' => $g['bytes']];
    }

    public static function comprovante(array $c, array $versao, array $assinaturas, string $empresa, string $verificacao): string
    {
        $pdf = new PdfSimples('Comprovante de assinaturas - ' . $c['titulo']);
        $roxo = [0.20, 0.20, 0.30];
        $pdf->texto('Comprovante de assinaturas eletrônicas', 16, true, $roxo);
        $pdf->texto($empresa . ' · ' . $c['titulo'], 10.5, false, [0.35, 0.35, 0.4]);
        $pdf->linha();
        $pdf->campo('Código de verificação', $c['codigo_verificacao'] . '    (consulte em ' . $verificacao . ')');
        $pdf->campo('Identificador do contrato', (string) $c['uuid']);
        $pdf->campo('Versão assinada', 'Versão ' . (int) $versao['numero'] . ' · ' . $versao['uuid']);
        $pdf->campo('SHA-256 do PDF original assinado', (string) $versao['sha256']);
        $pdf->campo('Concluído em', Tempo::local($c['concluido_em'] ?: Tempo::agora(), true) . ' (horário de Brasília, registrado pelo servidor)');
        $pdf->campo('Tipo de assinatura', 'Assinatura eletrônica. Cada participante confirmou o acesso por um código de uso único enviado ao WhatsApp do convite, '
            . 'marcou o aceite e acionou "Assinar contrato". Não é assinatura eletrônica qualificada (ICP-Brasil).');
        $pdf->linha();
        $pdf->texto('Participantes', 12.5, true, $roxo);
        $pdf->espaco(4);
        foreach ($assinaturas as $i => $a) {
            $ev = json_decode((string) $a['evidencia_json'], true);
            $sig = $ev['signatario'];
            $papel = Contrato::PAPEIS[$ev['participante']['papel']] ?? $ev['participante']['papel'];
            $pdf->texto(($i + 1) . '. ' . $sig['nome'] . ' · ' . $papel, 10.5, true);
            $pdf->campo('CPF', $sig['cpf_mascara'] . ($sig['origem'] === 'autodeclarado' ? ' (declarado pelo signatário; só os dígitos foram conferidos)' : ' (dados do cadastro, confirmados pelo signatário)'));
            if (!empty($ev['representacao'])) {
                $r = $ev['representacao'];
                $pdf->campo('Representa', $r['empresa'] . ' · CNPJ ' . $r['cnpj'] . ' · ' . $r['funcao'] . '. A identificação do representante não comprova, sozinha, os poderes de representação.');
            }
            $pdf->campo('Assinado em', Tempo::local($a['assinado_em'], true) . ' (Brasília) · ' . $ev['instante_utc'] . ' UTC, relógio do servidor');
            $pdf->campo('Confirmação', 'Código de uso único enviado ao ' . $ev['confirmacao']['contato'] . '. O código comprova acesso ao WhatsApp, não a titularidade do CPF.');
            $pdf->campo('Rede', 'IP ' . ($ev['rede']['ip'] ?? 'não registrado') . ' · ' . mb_substr((string) ($ev['rede']['navegador'] ?? ''), 0, 160));
            $pdf->campo('Aceite apresentado', '"' . $ev['aceite']['texto'] . '" (' . $ev['aceite']['versao'] . ')');
            $pdf->campo('Identificadores', 'Assinatura ' . $a['uuid'] . ' · Evidência SHA-256 ' . $a['evidencia_sha256']);
            $pdf->espaco(6);
        }
        $pdf->linha();
        $pdf->texto('Como conferir', 11, true, $roxo);
        $pdf->texto('1. Na página de verificação, informe o código acima: ela mostra a situação e os identificadores, sem dados pessoais. '
            . '2. O SHA-256 do PDF original deve ser igual ao informado aqui. 3. O hash deste PDF de conclusão está no manifesto de evidências, '
            . 'selado pela plataforma; este arquivo não traz o próprio hash, porque um arquivo não pode conter o hash de si mesmo.', 9);
        return $pdf->render();
    }

    /** Original + comprovante num PDF só, pelo qpdf. */
    public static function juntar(string $original, string $comprovante): string
    {
        $qpdf = EnvLoader::get('CONTRATOS_QPDF', 'qpdf');
        $dir = sys_get_temp_dir() . '/contrato-' . bin2hex(random_bytes(6));
        if (!@mkdir($dir, 0700)) throw new \RuntimeException('Sem pasta temporária para gerar o PDF de conclusão.');
        try {
            file_put_contents("$dir/a.pdf", $original);
            file_put_contents("$dir/b.pdf", $comprovante);
            $proc = @proc_open([$qpdf, '--empty', '--pages', "$dir/a.pdf", "$dir/b.pdf", '--', "$dir/c.pdf"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($proc)) throw new \RuntimeException('qpdf não está instalado neste servidor.');
            $saida = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $cod = proc_close($proc);
            // 3 = concluído com avisos (PDF original com pequenos defeitos); o arquivo sai.
            if (($cod !== 0 && $cod !== 3) || !is_file("$dir/c.pdf")) {
                throw new \RuntimeException($cod === 127 || $cod === 1 && str_contains((string) $saida, 'not found')
                    ? 'qpdf não está instalado neste servidor.' : 'qpdf falhou ao montar o PDF de conclusão (código ' . $cod . ').');
            }
            $b = (string) file_get_contents("$dir/c.pdf");
            if (!Armazenamento::ehPdf($b)) throw new \RuntimeException('O PDF de conclusão saiu inválido.');
            return $b;
        } finally {
            foreach (['a', 'b', 'c'] as $f) @unlink("$dir/$f.pdf");
            @rmdir($dir);
        }
    }
}
