<?php
/**
 * API da página externa de assinatura (/assinar). Sem login do CRM: vale só a
 * sessão curta "ctr_s", presa a UM convite (App\Contratos\Assinatura).
 *
 *   POST {acao:'abrir', token}           troca o token do link por uma sessão (não assina nada)
 *   GET  ?estado=1                       o que a página mostra (o mínimo antes do código)
 *   POST {acao:'enviar_codigo'}          código de 6 dígitos para o WhatsApp do convite
 *   POST {acao:'confirmar', codigo}
 *   POST {acao:'identificar', nome, cpf, confirmar_cadastro}
 *   POST {acao:'assinar', aceite, idempotencia}
 *   POST {acao:'recusar', motivo}
 *   GET  ?documento=1[&baixar=1]         o PDF da versão (só depois do código)
 *
 * GET nunca muda nada (prévia de link, robô de busca). POST exige o cabeçalho
 * X-CSRF-Token igual ao da sessão. Token e código nunca vão para log.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Contratos\Assinatura;
use App\Contratos\Rede;

header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');

function assResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

$https = (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (($_SERVER['HTTPS'] ?? '') === 'on');
$ip = Rede::ip();
$ua = Rede::navegador();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode(file_get_contents('php://input'), true) ?? [];
        $acao = (string) ($in['acao'] ?? '');

        if ($acao === 'abrir') {
            $r = Assinatura::abrir((string) ($in['token'] ?? ''), $ip, $ua);
            setcookie('ctr_s', $r['cookie'], [
                'expires' => time() + Assinatura::SESSAO_MIN * 60, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict',
            ]);
            $s = Assinatura::sessao($r['cookie']);
            assResponder(['ok' => true, 'data' => Assinatura::estado($s)]);
        }

        $s = Assinatura::sessao($_COOKIE['ctr_s'] ?? null);
        if (!$s) assResponder(['ok' => false, 'error' => 'Sua sessão terminou. Abra de novo o link que você recebeu.', 'sessao' => false], 401);
        if (!hash_equals((string) $s['csrf'], (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) assResponder(['ok' => false, 'error' => 'Recarregue a página.'], 419);

        switch ($acao) {
            case 'enviar_codigo':
                $r = Assinatura::enviarCodigo($s, $ip);
                if (isset($r['aguardar'])) assResponder(['ok' => false, 'aguardar' => $r['aguardar'], 'error' => 'Aguarde ' . $r['aguardar'] . ' segundos para pedir outro código.'], 429);
                assResponder(['ok' => true, 'contato' => $r['contato']]);
            case 'confirmar':
                $r = Assinatura::confirmarCodigo($s, (string) ($in['codigo'] ?? ''), $ip);
                if (!$r['ok']) assResponder(['ok' => false, 'restantes' => $r['restantes'], 'error' => $r['restantes'] > 0
                    ? 'Código incorreto. Restam ' . $r['restantes'] . ' tentativa' . ($r['restantes'] === 1 ? '' : 's') . '.' : 'Tentativas esgotadas. Peça um novo código.'], 400);
                assResponder(['ok' => true, 'data' => Assinatura::estado(Assinatura::sessao($_COOKIE['ctr_s']))]);
            case 'identificar':
                $r = Assinatura::identificar($s, (string) ($in['nome'] ?? ''), (string) ($in['cpf'] ?? ''), !empty($in['confirmar_cadastro']), $ip);
                if (!$r['ok']) assResponder(['ok' => false, 'bloqueado' => true, 'error' => $r['mensagem']], 409);
                assResponder(['ok' => true, 'data' => Assinatura::estado(Assinatura::sessao($_COOKIE['ctr_s']))]);
            case 'assinar':
                $r = Assinatura::assinar($s, ($in['aceite'] ?? false) === true, (string) ($in['idempotencia'] ?? ''), $ip, $ua);
                assResponder(['ok' => true, 'data' => $r]);
            case 'recusar':
                Assinatura::recusar($s, (string) ($in['motivo'] ?? ''), $ip, $ua);
                assResponder(['ok' => true]);
        }
        assResponder(['ok' => false, 'error' => 'Pedido inválido.'], 400);
    }

    $s = Assinatura::sessao($_COOKIE['ctr_s'] ?? null);
    if (!$s) assResponder(['ok' => false, 'error' => 'Abra o link que você recebeu para assinar.', 'sessao' => false], 401);

    if (!empty($_GET['documento'])) {
        if (!$s['canal_confirmado_em']) assResponder(['ok' => false, 'error' => 'Confirme o código antes.'], 403);
        $erro = Assinatura::validarSessao($s, false);
        if ($erro) assResponder(['ok' => false, 'error' => $erro], 410);
        $doc = Assinatura::pdf($s);
        if (!$doc) assResponder(['ok' => false, 'error' => 'O documento não confere com o registrado. Fale com quem enviou o contrato.'], 409);
        Assinatura::marcarVisualizacao($s, $ip);
        $nome = preg_replace('/[^A-Za-z0-9._ -]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', (string) $doc['nome']) ?: 'contrato.pdf');
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($doc['bytes']));
        header('Content-Disposition: ' . (!empty($_GET['baixar']) ? 'attachment' : 'inline') . '; filename="' . $nome . '"');
        echo $doc['bytes'];
        exit;
    }
    assResponder(['ok' => true, 'data' => Assinatura::estado($s)]);
} catch (\InvalidArgumentException $e) {
    assResponder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log('[contratos] assinatura: ' . get_class($e) . ' ' . $e->getMessage());
    assResponder(['ok' => false, 'error' => $e instanceof \RuntimeException && str_starts_with($e->getMessage(), 'Não conseguimos') ? $e->getMessage() : 'Não foi possível concluir agora. Tente de novo em instantes.'], 500);
}
