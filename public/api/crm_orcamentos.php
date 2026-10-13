<?php
/**
 * Orçamento do CRM (edição Fleetiflow). Ver app/Orcamentos/README.md.
 *
 *   GET  ?status=RASCUNHO|APROVADO|REJEITADO|CONVERTIDO  lista (sem status: os ativos, sem rejeitados)
 *   GET  ?card_id=N        os orçamentos de um lead
 *   GET  ?id=N             a ficha, com "para o cliente" e "controle do escritório"
 *   GET  ?apoio=1          catálogo ativo, débitos e equipe, para o formulário
 *   GET  ?servicos=1       o catálogo inteiro, com as taxas (tela Serviços e taxas)
 *   POST {acao, ...}       criar, atualizar, aprovar, rejeitar, reabrir, converter,
 *                          servico_salvar, taxa_adicionar, taxa_ajustar, taxa_remover
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Orcamentos\Catalogo;
use App\Orcamentos\Orcamento;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function orcResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->getProduto() !== 'fleetiflow') orcResponder(['ok' => false, 'error' => 'Disponível só na edição CRM.'], 403);

$acc     = (int) $ctx->getAccountId();
$userId  = (int) $ctx->getUserId();
$isAdmin = $ctx->isOwnerOrAdmin();

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['id'])) {
            $f = Orcamento::ficha($acc, (int) $_GET['id']);
            if (!$f) orcResponder(['ok' => false, 'error' => 'Orçamento não encontrado.'], 404);
            $f['texto_whatsapp'] = Orcamento::textoParaOCliente($f, (string) ($ctx->getMarca()['nome'] ?? ''));
            orcResponder(['ok' => true, 'data' => $f]);
        }
        if (!empty($_GET['apoio'])) {
            $st = \App\Core\Database::getConnection()->prepare(
                "SELECT id, nome FROM users WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL ORDER BY nome"
            );
            $st->execute([$acc]);
            orcResponder(['ok' => true, 'data' => [
                'servicos' => Catalogo::listar($acc, true),
                'debitos'  => Orcamento::DEBITOS,
                'equipe'   => array_map(fn($u) => ['id' => (int) $u['id'], 'nome' => $u['nome']], $st->fetchAll(\PDO::FETCH_ASSOC)),
                'eu'       => $userId,
                'pode_editar_catalogo' => $isAdmin,
            ]]);
        }
        if (!empty($_GET['servicos'])) orcResponder(['ok' => true, 'data' => Catalogo::listar($acc)]);
        if (isset($_GET['buscar'])) {
            // Leads e clientes da conta pelo nome, documento ou telefone, para o formulário.
            $q = trim((string) $_GET['buscar']);
            if (mb_strlen($q) < 2) orcResponder(['ok' => true, 'data' => []]);
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
            $digitos = preg_replace('/\D/', '', $q);
            $likeD = strlen($digitos) >= 4 ? '%' . $digitos . '%' : '__nada__';
            $pdo = \App\Core\Database::getConnection();
            $l = $pdo->prepare(
                "SELECT id, COALESCE(NULLIF(cliente_nome,''), empresa_nome, titulo) AS nome, cpf_cnpj, telefone_whatsapp FROM cards
                  WHERE account_id = ? AND deleted_at IS NULL
                    AND (cliente_nome LIKE ? OR empresa_nome LIKE ? OR titulo LIKE ?
                         OR REGEXP_REPLACE(COALESCE(telefone_whatsapp,''), '[^0-9]', '') LIKE ?
                         OR REGEXP_REPLACE(COALESCE(cpf_cnpj,''), '[^0-9]', '') LIKE ?)
                  ORDER BY updated_at DESC LIMIT 8"
            );
            $l->execute([$acc, $like, $like, $like, $likeD, $likeD]);
            $c = $pdo->prepare(
                "SELECT id, nome, cpf_cnpj, COALESCE(NULLIF(whatsapp,''), telefone) AS telefone FROM clientes
                  WHERE account_id = ? AND deleted_at IS NULL
                    AND (nome LIKE ? OR REGEXP_REPLACE(COALESCE(cpf_cnpj,''), '[^0-9]', '') LIKE ?
                         OR REGEXP_REPLACE(COALESCE(whatsapp,''), '[^0-9]', '') LIKE ?)
                  ORDER BY nome LIMIT 8"
            );
            $c->execute([$acc, $like, $likeD, $likeD]);
            $out = [];
            foreach ($l->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[] = ['tipo' => 'lead', 'id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'documento' => $r['cpf_cnpj'], 'telefone' => $r['telefone_whatsapp']];
            foreach ($c->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[] = ['tipo' => 'cliente', 'id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'documento' => $r['cpf_cnpj'], 'telefone' => $r['telefone']];
            orcResponder(['ok' => true, 'data' => $out]);
        }
        if (!empty($_GET['lead'])) {
            // O lead para começar um orçamento a partir da ficha dele.
            $st = \App\Core\Database::getConnection()->prepare(
                "SELECT id, COALESCE(NULLIF(cliente_nome,''), empresa_nome, titulo) AS nome, cpf_cnpj, telefone_whatsapp FROM cards
                  WHERE id = ? AND account_id = ? AND deleted_at IS NULL"
            );
            $st->execute([(int) $_GET['lead'], $acc]);
            $r = $st->fetch(\PDO::FETCH_ASSOC);
            orcResponder(['ok' => true, 'data' => $r ? ['tipo' => 'lead', 'id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'documento' => $r['cpf_cnpj'], 'telefone' => $r['telefone_whatsapp']] : null]);
        }
        if (!empty($_GET['card_id'])) orcResponder(['ok' => true, 'data' => Orcamento::listar($acc, null, (int) $_GET['card_id'])]);
        orcResponder(['ok' => true, 'data' => Orcamento::listar($acc, isset($_GET['status']) ? (string) $_GET['status'] : null)]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') orcResponder(['ok' => false, 'error' => 'Método não suportado.'], 405);

    $in   = json_decode(file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) {
        orcResponder(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
    }

    $acao = (string) ($in['acao'] ?? '');
    $id   = (int) ($in['id'] ?? 0);
    try {
        switch ($acao) {
            case 'criar':
                orcResponder(['ok' => true, 'data' => ['id' => Orcamento::salvar($acc, $in, $userId)]]);
            case 'atualizar':
                orcResponder(['ok' => true, 'data' => ['id' => Orcamento::salvar($acc, $in, $userId, $id)]]);
            case 'aprovar':   Orcamento::aprovar($acc, $id, $userId); break;
            case 'converter': Orcamento::converter($acc, $id, $userId); break;
            case 'rejeitar':  Orcamento::rejeitar($acc, $id); break;
            case 'reabrir':   Orcamento::reabrir($acc, $id); break;
            case 'servico_salvar':
            case 'taxa_adicionar':
            case 'taxa_ajustar':
            case 'taxa_remover':
                // O catálogo (preço e taxas) é decisão do dono: quem vende só usa.
                if (!$isAdmin) orcResponder(['ok' => false, 'error' => 'Só o dono ou um administrador altera os serviços e as taxas.'], 403);
                if ($acao === 'servico_salvar') Catalogo::salvar($acc, $in);
                if ($acao === 'taxa_adicionar') Catalogo::adicionarComponente($acc, (int) ($in['servico_id'] ?? 0), $in);
                if ($acao === 'taxa_ajustar') Catalogo::ajustarComponente($acc, (int) ($in['taxa_id'] ?? 0), $in);
                if ($acao === 'taxa_remover') Catalogo::removerComponente($acc, (int) ($in['taxa_id'] ?? 0));
                orcResponder(['ok' => true, 'data' => Catalogo::listar($acc)]);
            default:
                orcResponder(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
        }
    } catch (\InvalidArgumentException $e) {
        orcResponder(['ok' => false, 'error' => $e->getMessage()], 422);
    }
    orcResponder(['ok' => true, 'data' => Orcamento::ficha($acc, $id)]);
} catch (\Throwable $e) {
    error_log('[crm_orcamentos] ' . $e->getMessage());
    orcResponder(['ok' => false, 'error' => 'Não foi possível concluir. Tente de novo.'], 500);
}
