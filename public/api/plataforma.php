<?php
/**
 * A integração com a plataforma Fleetiflow, vista do CRM (edição CRM).
 * Ver app/Plataforma/README.md.
 *
 *   GET  ?conexao=1            a ligação desta conta (sem segredo nenhum)
 *   GET  ?card_id=N            o vínculo do lead, o resumo da conta e os eventos;
 *                              sem vínculo, as contas que podem ser dele
 *   GET  ?card_id=N&financeiro=1  mensalidades, recargas e extrato da conta
 *   GET  ?buscar=termo         contas da plataforma (código, CNPJ, nome)
 *   GET  ?planos=1             o catálogo de planos e módulos da plataforma
 *   GET  ?carteira=1           todas as contas ligadas, com o último resumo
 *   POST {acao, ...}           vincular, desvincular, modulo, plano, responder;
 *                              conectar e desconectar (só dono/admin)
 *
 * Vendedor (não dono, não admin) vê e mexe só nos leads de que é responsável,
 * como no resto da edição CRM. Toda mudança na plataforma leva o nome de quem
 * fez, e a plataforma grava na auditoria da conta.
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\Database;
use App\Core\EnvLoader;
use App\Plataforma\Conexao;
use App\Plataforma\Eventos;
use App\Plataforma\Vinculo;

session_start(['read_and_close' => true]);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function platResponder(array $corpo, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->getProduto() !== 'fleetiflow') platResponder(['ok' => false, 'error' => 'Disponível só na edição CRM.'], 403);

$acc      = (int) $ctx->getAccountId();
$userId   = (int) $ctx->getUserId();
$isAdmin  = $ctx->isOwnerOrAdmin();
$vendedor = $ctx->vendedorCrm();

/** O card, conferido: da conta e, para o vendedor, dele. */
function platCard(int $acc, int $cardId, bool $vendedor, int $userId): array
{
    $card = Vinculo::card($acc, $cardId);
    if ($vendedor && (int) $card['responsavel_user_id'] !== $userId) {
        platResponder(['ok' => false, 'error' => 'Este lead é de outro vendedor.'], 403);
    }
    return $card;
}

/** Quem está agindo, para a auditoria da plataforma. */
function platAutor(int $userId): array
{
    $st = Database::getConnection()->prepare('SELECT nome, login FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $u = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
    $login = (string) ($u['login'] ?? '');
    return ['nome' => (string) ($u['nome'] ?? 'Usuário do CRM'), 'email' => filter_var($login, FILTER_VALIDATE_EMAIL) ? $login : null];
}

/** Origem pública deste CRM, de onde sai o endereço que recebe os eventos. */
function platOrigem(): string
{
    $fixa = trim(EnvLoader::get('PLATAFORMA_CRM_URL', ''));
    if ($fixa !== '') return rtrim($fixa, '/');
    $host = preg_replace('/[^A-Za-z0-9.:\-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    // Fora da máquina local o CRM está sempre atrás de TLS (nginx do host), mesmo
    // quando o proxy não repassa X-Forwarded-Proto; a plataforma só aceita https.
    $local = (bool) preg_match('/^(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$/', $host);
    $https = !$local || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    return ($https ? 'https' : 'http') . '://' . $host;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!empty($_GET['conexao'])) {
            $s = Conexao::situacao($acc);
            platResponder(['ok' => true, 'data' => [
                'ligada'     => $s !== null,
                'conexao'    => $s ? [
                    'api_url'          => $s['api_url'],
                    'nome'             => $s['nome_conexao'],
                    'pode_mudar'       => $s['pode_mudar'],
                    'conectado_em'     => $s['conectado_em'],
                    'ultimo_evento_em' => $s['ultimo_evento_em'],
                ] : null,
                'pode_configurar' => $isAdmin,
            ]]);
        }

        if (!Conexao::ligada($acc)) {
            platResponder(['ok' => true, 'data' => ['ligada' => false, 'pode_configurar' => $isAdmin]]);
        }

        if (!empty($_GET['card_id'])) {
            $card = platCard($acc, (int) $_GET['card_id'], $vendedor, $userId);
            $v = Vinculo::doCard($acc, (int) $card['id']);
            if (!empty($_GET['financeiro'])) {
                if (!$v) platResponder(['ok' => false, 'error' => 'Lead sem conta ligada.'], 404);
                $r = Conexao::api($acc, 'GET', '/contas/' . rawurlencode($v['codigo_conta']) . '/financeiro');
                if ($r['status'] !== 200) platResponder(['ok' => false, 'error' => Conexao::mensagem($r, 'A plataforma recusou.')], 502);
                platResponder(['ok' => true, 'data' => $r['corpo']]);
            }
            $situacao = Conexao::situacao($acc);
            $podeMudar = ($situacao['pode_mudar'] ?? false) && ($isAdmin || (int) $card['responsavel_user_id'] === $userId);
            if (!$v) {
                $sugestoes = [];
                try { $sugestoes = Vinculo::sugestoes($acc, $card); } catch (\RuntimeException $e) { /* sem rede: sem sugestão */ }
                platResponder(['ok' => true, 'data' => ['ligada' => true, 'vinculo' => null, 'sugestoes' => $sugestoes, 'pode_mudar' => $podeMudar]]);
            }
            $r = Vinculo::resumo($acc, $v, !empty($_GET['atualizar']));
            // Trocou o responsável do lead: a plataforma passa a mostrar o consultor novo.
            if (!$r['de_cache'] && $r['resumo']) Vinculo::sincronizarResponsavel($acc, $card, $r['resumo']);
            platResponder(['ok' => true, 'data' => [
                'ligada'     => true,
                'vinculo'    => ['codigo' => $v['codigo_conta'], 'nome' => $v['nome_conta']],
                'resumo'     => $r['resumo'],
                'atualizado_em' => $r['atualizado_em'],
                'de_cache'   => $r['de_cache'],
                'erro'       => $r['erro'] ?? null,
                'eventos'    => Eventos::doCard($acc, (int) $card['id'], 15),
                'pode_mudar' => $podeMudar,
            ]]);
        }

        if (isset($_GET['buscar'])) {
            $r = Conexao::api($acc, 'GET', '/contas?busca=' . rawurlencode(trim((string) $_GET['buscar'])));
            platResponder(['ok' => true, 'data' => $r['corpo']['itens'] ?? []]);
        }

        if (!empty($_GET['planos'])) {
            $r = Conexao::api($acc, 'GET', '/planos');
            platResponder(['ok' => true, 'data' => $r['corpo']]);
        }

        if (!empty($_GET['carteira'])) {
            $linhas = Vinculo::carteira($acc);
            if ($vendedor) {
                $st = Database::getConnection()->prepare('SELECT id FROM cards WHERE account_id = ? AND responsavel_user_id = ? AND deleted_at IS NULL');
                $st->execute([$acc, $userId]);
                $meus = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
                $linhas = array_values(array_filter($linhas, fn($l) => $l['card_id'] && in_array($l['card_id'], $meus, true)));
            }
            platResponder(['ok' => true, 'data' => $linhas]);
        }

        platResponder(['ok' => false, 'error' => 'Parâmetro ausente.'], 400);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') platResponder(['ok' => false, 'error' => 'Método não permitido.'], 405);

    $in = json_decode((string) file_get_contents('php://input'), true) ?? [];
    $csrf = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($in['csrf_token'] ?? '');
    if (!$csrf || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), (string) $csrf)) {
        platResponder(['ok' => false, 'error' => 'Sessão expirada. Recarregue a página.'], 403);
    }
    $acao = (string) ($in['acao'] ?? '');

    if ($acao === 'conectar' || $acao === 'desconectar') {
        if (!$isAdmin) platResponder(['ok' => false, 'error' => 'Só o dono ou um administrador liga o CRM à plataforma.'], 403);
        if ($acao === 'desconectar') {
            Conexao::desconectar($acc);
            platResponder(['ok' => true]);
        }
        $s = Conexao::conectar($acc, $userId, (string) ($in['codigo'] ?? ''), platOrigem());
        platResponder(['ok' => true, 'data' => ['nome' => $s['nome_conexao'], 'pode_mudar' => $s['pode_mudar']]]);
    }

    if (!Conexao::ligada($acc)) platResponder(['ok' => false, 'error' => 'O CRM ainda não está ligado à plataforma.'], 409);
    $card = platCard($acc, (int) ($in['card_id'] ?? 0), $vendedor, $userId);
    $cardId = (int) $card['id'];

    if ($acao === 'vincular') {
        $v = Vinculo::vincular($acc, $cardId, (string) ($in['codigo'] ?? ''), $userId);
        platResponder(['ok' => true, 'data' => ['codigo' => $v['codigo_conta'], 'nome' => $v['nome_conta']]]);
    }
    if ($acao === 'desvincular') {
        Vinculo::desvincular($acc, $cardId, $userId);
        platResponder(['ok' => true]);
    }

    // Daqui para baixo, mudança na conta do cliente: precisa do escopo
    // comercial na conexão e ser admin ou o responsável pelo lead.
    $situacao = Conexao::situacao($acc);
    if (!($situacao['pode_mudar'] ?? false)) platResponder(['ok' => false, 'error' => 'A conexão com a plataforma é só de leitura.'], 403);
    if (!$isAdmin && (int) $card['responsavel_user_id'] !== $userId) {
        platResponder(['ok' => false, 'error' => 'Só o responsável pelo lead ou um administrador muda a conta.'], 403);
    }
    $v = Vinculo::doCard($acc, $cardId);
    if (!$v) platResponder(['ok' => false, 'error' => 'Ligue o lead a uma conta da plataforma primeiro.'], 409);
    $codigo = rawurlencode($v['codigo_conta']);
    $autor = platAutor($userId);

    if ($acao === 'modulo') {
        $modulo = strtoupper((string) ($in['modulo'] ?? ''));
        if (!preg_match('/^[A-Z_]{2,30}$/', $modulo)) throw new \InvalidArgumentException('Módulo inválido.');
        $ativo = !empty($in['ativo']);
        $r = Conexao::api($acc, 'PATCH', "/contas/$codigo/modulos/$modulo", ['ativo' => $ativo, 'autor' => $autor]);
        if ($r['status'] !== 200) platResponder(['ok' => false, 'error' => Conexao::mensagem($r, 'A plataforma recusou.')], 422);
        Vinculo::envelhecer($acc, $v['codigo_conta']);
        if (!empty($r['corpo']['alterado'])) {
            $nomeModulo = Eventos::MODULOS[$modulo] ?? $modulo;
            Vinculo::nota($acc, $card, 'Módulo ' . ($ativo ? 'liberado' : 'desligado'), 'Módulo ' . $nomeModulo . ' ' . ($ativo ? 'liberado' : 'desligado') . ' pelo CRM, por ' . $autor['nome'] . '.', $userId);
        }
        platResponder(['ok' => true, 'data' => $r['corpo']]);
    }

    if ($acao === 'plano') {
        $plano = (string) ($in['plano_id'] ?? '');
        $corpo = ['planoComercialId' => $plano, 'autor' => $autor];
        if (!empty($in['dia_vencimento'])) $corpo['diaVencimento'] = (int) $in['dia_vencimento'];
        $r = Conexao::api($acc, 'PUT', "/contas/$codigo/plano", $corpo);
        if ($r['status'] !== 200) platResponder(['ok' => false, 'error' => Conexao::mensagem($r, 'A plataforma recusou.')], 422);
        Vinculo::envelhecer($acc, $v['codigo_conta']);
        $p = $r['corpo']['plano'] ?? [];
        Vinculo::nota($acc, $card, 'Plano alterado pelo CRM', 'Plano ' . ($p['nome'] ?? '') . ' (' . Eventos::real((float) ($p['valorMensal'] ?? 0)) . '/mês), por ' . $autor['nome'] . '.', $userId);
        platResponder(['ok' => true, 'data' => $r['corpo']]);
    }

    if ($acao === 'responder') {
        $id = (string) ($in['solicitacao_id'] ?? '');
        $status = (string) ($in['status'] ?? '');
        $r = Conexao::api($acc, 'PATCH', '/solicitacoes/' . rawurlencode($id), [
            'status'   => $status,
            'resposta' => trim((string) ($in['resposta'] ?? '')) ?: null,
            'autor'    => $autor,
        ]);
        if ($r['status'] !== 200) platResponder(['ok' => false, 'error' => Conexao::mensagem($r, 'A plataforma recusou.')], 422);
        Vinculo::envelhecer($acc, $v['codigo_conta']);
        Vinculo::nota($acc, $card, 'Pedido ' . ($status === 'ATENDIDA' ? 'atendido' : 'recusado'), 'Resposta ao pedido da conta' . (trim((string) ($in['resposta'] ?? '')) !== '' ? ': ' . trim((string) $in['resposta']) : '.') . ' Por ' . $autor['nome'] . '.', $userId);
        platResponder(['ok' => true, 'data' => $r['corpo']]);
    }

    platResponder(['ok' => false, 'error' => 'Ação desconhecida.'], 400);
} catch (\InvalidArgumentException $e) {
    platResponder(['ok' => false, 'error' => $e->getMessage()], 422);
} catch (\PDOException $e) {
    // PDOException é RuntimeException: sem esta linha, o SQL ia para a tela.
    error_log('[plataforma] ' . $e->getMessage());
    platResponder(['ok' => false, 'error' => 'Erro interno.'], 500);
} catch (\RuntimeException $e) {
    platResponder(['ok' => false, 'error' => $e->getMessage()], 502);
} catch (\Throwable $e) {
    error_log('[plataforma] ' . get_class($e) . ': ' . $e->getMessage());
    platResponder(['ok' => false, 'error' => 'Erro interno.'], 500);
}
