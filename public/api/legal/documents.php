<?php
/**
 * /api/legal/documents.php
 *
 * GET  ?tipo=privacy_policy        → retorna versão vigente do tipo (público)
 * GET  ?tipo=privacy_policy&hash=1 → retorna só id + versão + hash (lightweight)
 * GET  ?list=1&tipo=privacy_policy → lista versões (auth necessária)
 *
 * Público: leitura do documento vigente NÃO exige sessão — qualquer titular
 * (logado ou não) pode ver Política de Privacidade/Termos sem precisar
 * estar autenticado.
 *
 * STATUS DE WIRING (auditoria #35): endpoint mantido PROPOSITALMENTE.
 *   As páginas legais server-side (termos.php, privacidade.php, etc. via
 *   includes/legal_page.php) renderizam o texto diretamente. Esta API é a via
 *   DINÂMICA (modal de termos, detecção de nova versão via ?hash=1, app/cliente
 *   externo) e é explicitamente pública. Não remover: é a fonte JSON canônica do
 *   LegalDocument. O consumo dinâmico (modal de re-aceite que casa com
 *   /api/legal/accept.php) deve ser plugado nas páginas/banner — arquivos de
 *   outro dono (includes/legal_page.php, login.php) — ver risks.
 */
require_once __DIR__ . '/../../../app/bootstrap.php';

use App\Lgpd\LegalDocument;
use App\Core\ApiResponse;

session_start();
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') ApiResponse::methodNotAllowed();

$tipo = trim($_GET['tipo'] ?? '');
$validos = ['privacy_policy','terms_of_use','cookies_policy','dpa','security_policy','privacy_notice'];

// list=1 → exige auth (admin)
if (!empty($_GET['list'])) {
    if (empty($_SESSION['user_id'])) ApiResponse::unauthorized();
    if (!$tipo || !in_array($tipo, $validos, true)) ApiResponse::badRequest('tipo inválido');
    ApiResponse::ok(LegalDocument::listVersions($tipo, 50));
}

if (!$tipo || !in_array($tipo, $validos, true)) {
    ApiResponse::badRequest('tipo inválido. Use: ' . implode(', ', $validos));
}

$doc = LegalDocument::findActive($tipo);
if (!$doc) {
    ApiResponse::ok(null);
}

// Hash-only (UI usa pra detectar mudança de versão sem trafegar texto inteiro)
if (!empty($_GET['hash'])) {
    ApiResponse::ok([
        'id'            => (int)$doc['id'],
        'tipo'          => $doc['tipo'],
        'versao'        => $doc['versao'],
        'hash_conteudo' => $doc['hash_conteudo'],
        'publicado_em'  => $doc['publicado_em'],
    ]);
}

// Documento completo
ApiResponse::ok([
    'id'            => (int)$doc['id'],
    'tipo'          => $doc['tipo'],
    'versao'        => $doc['versao'],
    'titulo'        => $doc['titulo'],
    'conteudo_md'   => $doc['conteudo_md'],
    'hash_conteudo' => $doc['hash_conteudo'],
    'publicado_em'  => $doc['publicado_em'],
]);
