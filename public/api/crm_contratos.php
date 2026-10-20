<?php
/**
 * Contratos e assinatura eletrônica, lado da equipe (edição CRM). Ver app/Contratos/README.md.
 *
 *   GET  ?central=1               números da conta (quantos, situação, disco)
 *   GET  ?lista=1&status=&busca=&cliente_id=&card_id=
 *   GET  ?id=N                    a ficha (CPF e telefone mascarados)
 *   GET  ?apoio=1                 equipe, configuração, permissões
 *   GET  ?buscar=texto            clientes e leads da conta, para o formulário
 *   GET  ?baixar=<uuid>           um arquivo (original, comprovante, conclusão, manifesto)
 *   GET  ?evidencias=N            o pacote de evidências em JSON
 *   POST multipart acao=anexar    o PDF (id, arquivo)
 *   POST {acao, ...}              criar, atualizar, participante_salvar, participante_remover,
 *                                 enviar, novo_link, liberar, cancelar, config_salvar, reprocessar
 *
 * Cada ação confere a permissão própria no servidor (App\Contratos\Permissao).
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Contratos\Armazenamento;
use App\Contratos\Contrato;
use App\Contratos\Cripto;
use App\Contratos\Eventos;
use App\Contratos\Finalizacao;
use App\Contratos\Permissao;
use App\Contratos\Rede;
use App\Core\AccountContext;
use App\Core\Database;

session_start(['read_and_close' => true]);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function ctrResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

if (empty($_SESSION['user_id'])) ctrResponder(['ok' => false, 'error' => 'Sessão expirada.'], 401);
$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->getProduto() !== 'fleetiflow') ctrResponder(['ok' => false, 'error' => 'Disponível só na edição CRM.'], 403);
if (!Permissao::podeVer()) ctrResponder(['ok' => false, 'error' => 'Sem permissão para contratos.'], 403);

$acc = (int) $ctx->getAccountId();
$uid = (int) $ctx->getUserId();
$quem = ['user_id' => $uid, 'ip' => Rede::ip(), 'user_agent' => Rede::navegador()];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['central'])) ctrResponder(['ok' => true, 'data' => Contrato::central($acc)]);
        if (!empty($_GET['lista'])) {
            ctrResponder(['ok' => true, 'data' => Contrato::listar($acc, [
                'status' => $_GET['status'] ?? '', 'busca' => trim((string) ($_GET['busca'] ?? '')),
                'cliente_id' => (int) ($_GET['cliente_id'] ?? 0), 'card_id' => (int) ($_GET['card_id'] ?? 0),
            ])]);
        }
        if (!empty($_GET['id'])) {
            $f = Contrato::ficha($acc, (int) $_GET['id']);
            $f['pode_gerenciar'] = Permissao::podeGerenciar($ctx, ['responsavel_id' => $f['responsavel_id']]);
            ctrResponder(['ok' => true, 'data' => $f]);
        }
        if (!empty($_GET['apoio'])) {
            $st = Database::getConnection()->prepare("SELECT id, nome FROM users WHERE account_id = ? AND status = 'active' AND deleted_at IS NULL ORDER BY nome");
            $st->execute([$acc]);
            ctrResponder(['ok' => true, 'data' => [
                'equipe' => array_map(fn($u) => ['id' => (int) $u['id'], 'nome' => $u['nome']], $st->fetchAll(\PDO::FETCH_ASSOC)),
                'eu' => $uid, 'config' => Contrato::config($acc), 'pode_gerenciar' => Permissao::podeGerenciar($ctx),
                'papeis' => Contrato::PAPEIS, 'status' => Contrato::STATUS, 'selo_configurado' => Cripto::seloConfigurado(),
            ]]);
        }
        if (isset($_GET['buscar'])) {
            // Vazio: os clientes e leads mais recentes da conta (a lista aparece ao clicar no campo).
            // Com texto: pelo nome, CPF/CNPJ ou telefone. ?tipo=cliente traz só clientes.
            $q = trim((string) $_GET['buscar']);
            $soClientes = ($_GET['tipo'] ?? '') === 'cliente';
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
            $dig = preg_replace('/\D/', '', $q);
            $likeD = strlen($dig) >= 3 ? '%' . $dig . '%' : '__nada__';
            $pdo = Database::getConnection();
            $filtroC = $q === '' ? '' : "AND (nome LIKE ? OR REGEXP_REPLACE(COALESCE(cpf_cnpj,''), '[^0-9]', '') LIKE ?
                                              OR REGEXP_REPLACE(COALESCE(whatsapp,''), '[^0-9]', '') LIKE ? OR REGEXP_REPLACE(COALESCE(telefone,''), '[^0-9]', '') LIKE ?)";
            $c = $pdo->prepare("SELECT id, nome, cpf_cnpj, COALESCE(NULLIF(whatsapp,''), telefone) AS telefone FROM clientes
                                 WHERE account_id = ? AND deleted_at IS NULL $filtroC
                                 ORDER BY " . ($q === '' ? 'updated_at DESC' : 'nome') . ' LIMIT 8');
            $c->execute($q === '' ? [$acc] : [$acc, $like, $likeD, $likeD, $likeD]);
            $out = [];
            $fone = fn($t) => ($d = preg_replace('/\D/', '', (string) $t)) && strlen($d) >= 8 ? $d : null;
            foreach ($c->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $doc = preg_replace('/\D/', '', (string) $r['cpf_cnpj']);
                $out[] = ['tipo' => 'cliente', 'id' => (int) $r['id'], 'nome' => $r['nome'], 'telefone' => $fone($r['telefone']),
                    'cpf' => strlen($doc) === 11 ? $doc : null,
                    'detalhe' => trim((strlen($doc) === 11 ? \App\Contratos\Cpf::mascarar($doc) : (strlen($doc) === 14 ? 'CNPJ' : '')) . ($fone($r['telefone']) ? ' · tel. final ' . substr($fone($r['telefone']), -4) : ''), ' ·')];
            }
            if (!$soClientes) {
                $filtroL = $q === '' ? '' : "AND (cliente_nome LIKE ? OR empresa_nome LIKE ? OR titulo LIKE ?
                                                  OR REGEXP_REPLACE(COALESCE(telefone_whatsapp,''), '[^0-9]', '') LIKE ?)";
                $l = $pdo->prepare("SELECT id, COALESCE(NULLIF(cliente_nome,''), empresa_nome, titulo) AS nome, telefone_whatsapp FROM cards
                                     WHERE account_id = ? AND deleted_at IS NULL $filtroL ORDER BY updated_at DESC LIMIT 8");
                $l->execute($q === '' ? [$acc] : [$acc, $like, $like, $like, $likeD]);
                foreach ($l->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                    $out[] = ['tipo' => 'lead', 'id' => (int) $r['id'], 'nome' => $r['nome'], 'telefone' => $fone($r['telefone_whatsapp']),
                        'detalhe' => $fone($r['telefone_whatsapp']) ? 'tel. final ' . substr($fone($r['telefone_whatsapp']), -4) : ''];
                }
            }
            ctrResponder(['ok' => true, 'data' => $out, 'recentes' => $q === '']);
        }
        if (!empty($_GET['baixar'])) {
            $st = Database::getConnection()->prepare('SELECT a.*, c.titulo FROM crm_contrato_arquivos a JOIN crm_contratos c ON c.id = a.contrato_id WHERE a.uuid = ? AND a.account_id = ?');
            $st->execute([(string) $_GET['baixar'], $acc]);
            $a = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$a) ctrResponder(['ok' => false, 'error' => 'Arquivo não encontrado.'], 404);
            $b = Armazenamento::ler((string) $a['caminho'], (string) $a['sha256']);
            if ($b === null) ctrResponder(['ok' => false, 'error' => 'O arquivo não confere com o hash gravado. Avise o suporte.'], 409);
            Eventos::registrar((int) $a['contrato_id'], 'arquivo_baixado', ['tipo' => $a['tipo'], 'arquivo_uuid' => $a['uuid']], $quem);
            $nome = preg_replace('/[^A-Za-z0-9._ -]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', (string) $a['titulo']) ?: 'contrato');
            $ext = $a['mime'] === 'application/json' ? 'json' : 'pdf';
            header('Content-Type: ' . $a['mime']);
            header('Content-Length: ' . strlen($b));
            header('Content-Disposition: attachment; filename="' . trim(mb_substr($nome, 0, 60)) . ' - ' . $a['tipo'] . '.' . $ext . '"');
            echo $b;
            exit;
        }
        if (!empty($_GET['evidencias'])) {
            $id = (int) $_GET['evidencias'];
            $c = Contrato::doContrato($acc, $id);
            $completo = Permissao::podeGerenciar($ctx, $c);
            $pdo = Database::getConnection();
            $as = $pdo->prepare('SELECT * FROM crm_contrato_assinaturas WHERE contrato_id = ? AND account_id = ? ORDER BY id');
            $as->execute([$id, $acc]);
            $assin = [];
            foreach ($as->fetchAll(\PDO::FETCH_ASSOC) as $a) {
                $ident = json_decode((string) $a['identificacao_json'], true);
                $item = ['evidencia' => json_decode((string) $a['evidencia_json'], true), 'evidencia_sha256' => $a['evidencia_sha256'],
                         'confere' => hash_equals((string) $a['evidencia_sha256'], hash('sha256', (string) $a['evidencia_json']))];
                // CPF completo só para quem gerencia (a evidência guarda só o mascarado e o HMAC).
                if ($completo && !empty($ident['cpf_cifrado'])) $item['cpf_completo'] = Cripto::decifrar($ident['cpf_cifrado']);
                $assin[] = $item;
            }
            Eventos::registrar($id, 'evidencias_baixadas', ['cpf_completo' => $completo], $quem);
            $pacote = [
                'formato' => 'yuris-contratos-evidencias/1', 'gerado_em_utc' => gmdate('Y-m-d\TH:i:s\Z'),
                'contrato' => ['uuid' => $c['uuid'], 'codigo_verificacao' => $c['codigo_verificacao'], 'titulo' => $c['titulo'], 'status' => $c['status']],
                'assinaturas' => $assin, 'eventos' => Eventos::doContrato($id), 'trilha' => Eventos::verificar($id),
                'finalidade' => 'Prova da formação do contrato e defesa em processo. Base legal: execução de contrato e exercício regular de direitos (LGPD, art. 7º, V e VI).',
            ];
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="evidencias-' . $c['codigo_verificacao'] . '.json"');
            echo json_encode($pacote, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            exit;
        }
        ctrResponder(['ok' => false, 'error' => 'Pedido inválido.'], 400);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') ctrResponder(['ok' => false, 'error' => 'Método não permitido.'], 405);
    $multipart = str_starts_with((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');
    $in = $multipart ? $_POST : (json_decode(file_get_contents('php://input'), true) ?? []);
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) ctrResponder(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 419);
    $acao = (string) ($in['acao'] ?? '');
    $id = (int) ($in['id'] ?? 0);
    $gerencia = fn() => Permissao::podeGerenciar($ctx, $id ? Contrato::doContrato($acc, $id) : null)
        ?: ctrResponder(['ok' => false, 'error' => 'Só o responsável pelo contrato ou um administrador faz isso.'], 403);

    switch ($acao) {
        case 'criar':
            ctrResponder(['ok' => true, 'id' => Contrato::criar($acc, $uid, $in)]);
        case 'atualizar':
            Contrato::atualizar($acc, $uid, $id, $in);
            ctrResponder(['ok' => true]);
        case 'anexar':
            $f = $_FILES['arquivo'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                ctrResponder(['ok' => false, 'error' => ($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'O PDF passa do limite de envio.' : 'Envie o PDF do contrato.'], 400);
            }
            $r = Contrato::anexarPdf($acc, $uid, $id, (string) file_get_contents($f['tmp_name']), (string) $f['name']);
            ctrResponder(['ok' => true, 'data' => $r]);
        case 'participante_salvar':
            // "id" na ação é o contrato; o participante vem em participante_id.
            ctrResponder(['ok' => true, 'participante_id' => Contrato::salvarParticipante($acc, $uid, $id, ['id' => (int) ($in['participante_id'] ?? 0)] + $in)]);
        case 'participante_remover':
            Contrato::removerParticipante($acc, $uid, $id, (int) ($in['participante_id'] ?? 0));
            ctrResponder(['ok' => true]);
        case 'enviar':
            ctrResponder(['ok' => true, 'data' => Contrato::fecharEEnviar($acc, $uid, $id, !empty($in['whatsapp']))]);
        case 'novo_link':
            ctrResponder(['ok' => true, 'data' => Contrato::novoLink($acc, $uid, $id, (int) ($in['participante_id'] ?? 0), !empty($in['whatsapp']))]);
        case 'liberar':
            $gerencia();
            ctrResponder(['ok' => true, 'data' => Contrato::novoLink($acc, $uid, $id, (int) ($in['participante_id'] ?? 0), !empty($in['whatsapp']), true, (string) ($in['motivo'] ?? ''))]);
        case 'cancelar':
            $gerencia();
            Contrato::cancelar($acc, $uid, $id, (string) ($in['motivo'] ?? ''));
            ctrResponder(['ok' => true]);
        case 'reprocessar':
            $gerencia();
            Contrato::doContrato($acc, $id);
            Database::getConnection()->prepare("UPDATE crm_contratos SET finalizacao_proxima = ? WHERE id = ? AND account_id = ? AND status = 'concluido' AND finalizacao_status IN ('pendente','erro')")
                ->execute([\App\Contratos\Tempo::agora(), $id, $acc]);
            ctrResponder(['ok' => true, 'resultado' => Finalizacao::processar($id)]);
        case 'config_salvar':
            if (!$ctx->isOwnerOrAdmin() && !Permissao::pode(Permissao::GERENCIAR)) ctrResponder(['ok' => false, 'error' => 'Só administrador ajusta a configuração.'], 403);
            ctrResponder(['ok' => true, 'data' => Contrato::salvarConfig($acc, $uid, $in)]);
    }
    ctrResponder(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (\InvalidArgumentException $e) {
    ctrResponder(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (\Throwable $e) {
    error_log('[contratos] api: ' . get_class($e) . ' ' . $e->getMessage());
    ctrResponder(['ok' => false, 'error' => 'Não foi possível concluir agora. Tente de novo.'], 500);
}
