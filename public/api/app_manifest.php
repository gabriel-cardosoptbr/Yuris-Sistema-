<?php
/**
 * /api/app_manifest.php: o manifesto do app instalável (PWA, 22/10/2026).
 *
 * É o que faz o Android (Chrome, Samsung Internet) oferecer "Instalar app" e
 * dá nome, cor e ícone ao atalho da tela inicial. O iPhone usa as metas
 * apple-* que o sidebar.php põe na página, mas também lê daqui.
 *
 * Sai com a marca da conta logada: Inovaize instala "Inovaize" com o ícone da
 * Inovaize. Sem sessão (o navegador pode buscar sem cookie), vale a marca do
 * domínio (ProductHost), e no domínio do Yuris, o Yuris.
 *
 * Nada aqui é dado de cliente: nome, cor e o endereço público do ícone.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\IconeApp;
use App\Core\ProductHost;
use App\Master\Marca;

session_start(['read_and_close' => true]);

$marca = null;
$produto = 'yuris';
try {
    if (!empty($_SESSION['user_id']) && !empty($_SESSION['account_id'])) {
        $ctx = AccountContext::fromSession();
        $produto = $ctx->getProduto();
        if ($produto === 'fleetiflow') $marca = $ctx->getMarca();
    } elseif (ProductHost::isFleetiflow()) {
        $produto = 'fleetiflow';
        $marca = ProductHost::marcaDoHost();
    }
} catch (\Throwable $e) {
    error_log('[app_manifest] ' . $e->getMessage());
}

if ($produto === 'fleetiflow') {
    $marca  = $marca ?? Marca::padraoFleetiflow();
    $nome   = (string) $marca['nome'];
    $cor    = (string) ($marca['paleta']['marca'] ?? $marca['cor']);
    $fundo  = '#FFFFFF';
    $descricao = 'CRM, WhatsApp e atendimento da ' . $nome . '.';
} else {
    $nome  = 'Yuris';
    $cor   = '#081526';
    $fundo = '#081526';
    $descricao = 'Gestão do escritório de advocacia.';
}

$icones = [];
foreach (IconeApp::LADOS as $l) {
    if ($l === 180) continue; // 180 é o tamanho do iPhone, que vem pela meta apple-touch-icon
    $icones[] = ['src' => IconeApp::url($produto, $marca, $l), 'sizes' => $l . 'x' . $l, 'type' => 'image/png', 'purpose' => 'any'];
}

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: private, no-cache');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'id'               => '/dashboard',
    'name'             => $nome,
    'short_name'       => $nome,
    'description'      => $descricao,
    'lang'             => 'pt-BR',
    'dir'              => 'ltr',
    'start_url'        => '/dashboard',
    'scope'            => '/',
    'display'          => 'standalone',
    'background_color' => $fundo,
    'theme_color'      => $cor,
    'icons'            => $icones,
    'shortcuts'        => [
        ['name' => 'Conversas', 'short_name' => 'Conversas', 'url' => '/chat', 'icons' => [$icones[0]]],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
