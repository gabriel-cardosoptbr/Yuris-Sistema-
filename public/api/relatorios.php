<?php

/**
 * /api/relatorios.php — a porta dos relatórios.
 *
 *   GET ?acao=dossie&entidade=cliente|card|processo&id=N
 *   GET ?acao=listagem&fonte=clientes|prospeccoes|processos[&filtros...]
 *   GET ?acao=opcoes&fonte=...
 *
 * Acrescente `&formato=csv` a `dossie` ou a `listagem` para baixar a planilha
 * em vez de receber JSON.
 *
 * ---------------------------------------------------------------------------
 * O GATE É POR FONTE, NÃO PELA TELA
 * ---------------------------------------------------------------------------
 * Cada entidade tem o seu módulo de permissão, e o escopo de contas é pedido
 * COM esse módulo:
 *
 *   cliente   -> clientes
 *   card      -> prospeccao
 *   processo  -> processos
 *
 * Quem pode abrir a tela de Relatórios mas não enxerga Processos recebe lista
 * vazia de processos e 404 no dossiê de processo. Não existe caminho em que
 * "ter acesso a relatórios" amplie o que a pessoa já podia ver.
 *
 * Somente GET: tudo aqui é leitura. Um POST não teria o que fazer, e aceitar
 * um só aumentaria a superfície.
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Relatorios\Dossie;
use App\Relatorios\Listagem;
use App\Relatorios\Planilha;

// read_and_close: só leitura. Sem isso o download de um relatório grande
// seguraria o lock de sessão e travaria o resto da tela do usuário.
session_start(['read_and_close' => true]);

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

$acao    = strtolower(trim((string) ($_GET['acao'] ?? '')));
$formato = strtolower(trim((string) ($_GET['formato'] ?? 'json')));

/** Resposta JSON padrão. */
function rel_json(int $code, array $body): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    /* ===================================================================== */
    if ($acao === 'dossie') {
        $entidade = strtolower(trim((string) ($_GET['entidade'] ?? '')));
        $id       = (int) ($_GET['id'] ?? 0);

        if ($id <= 0 || !isset(Dossie::MODULOS[$entidade])) {
            rel_json(400, ['error' => 'Informe entidade=cliente|card|processo e id']);
        }

        $tenantIds = $ctx->getAccessibleAccountIds(Dossie::MODULOS[$entidade]);
        $doc       = Dossie::montar($entidade, $id, $tenantIds);

        /*
         * 404 para os três casos: não existe, está apagado, não é seu.
         * Distinguir "não é seu" de "não existe" já entregaria a informação de
         * que aquele id existe em algum lugar do sistema.
         */
        if ($doc === null) {
            rel_json(404, ['error' => 'Registro não encontrado']);
        }

        if ($formato === 'csv') {
            Planilha::baixar(
                Planilha::doDossie($doc),
                'dossie-' . $entidade . '-' . $id . '-' . date('Y-m-d') . '.csv'
            );
            exit;
        }

        rel_json(200, ['success' => true, 'dossie' => $doc]);
    }

    /* ===================================================================== */
    if ($acao === 'listagem' || $acao === 'opcoes') {
        $fonte = strtolower(trim((string) ($_GET['fonte'] ?? '')));
        if (!isset(Listagem::FONTES[$fonte])) {
            rel_json(400, ['error' => 'Informe fonte=clientes|prospeccoes|processos']);
        }

        $tenantIds = $ctx->getAccessibleAccountIds(Listagem::FONTES[$fonte]['modulo']);

        if ($acao === 'opcoes') {
            rel_json(200, ['success' => true, 'fonte' => $fonte, 'opcoes' => Listagem::opcoes($fonte, $tenantIds)]);
        }

        $filtros = [
            'status'         => (string) ($_GET['status'] ?? ''),
            'setor_id'       => (int) ($_GET['setor_id'] ?? 0),
            'responsavel_id' => (int) ($_GET['responsavel_id'] ?? 0),
            'etapa_id'       => (int) ($_GET['etapa_id'] ?? 0),
            'de'             => (string) ($_GET['de'] ?? ''),
            'ate'            => (string) ($_GET['ate'] ?? ''),
            'busca'          => (string) ($_GET['busca'] ?? ''),
            'ordem'          => (string) ($_GET['ordem'] ?? ''),
        ];

        $res = Listagem::montar($fonte, $filtros, $tenantIds);

        if ($formato === 'csv') {
            $cabecalho = ['Relatório de ' . $res['titulo'], 'Gerado em ' . $res['gerado_em'], 'Registros: ' . $res['total']];
            if (!empty($res['truncado'])) {
                $cabecalho[] = 'ATENÇÃO: a lista foi cortada em ' . $res['limite'] . ' linhas. Use os filtros para reduzir.';
            }
            Planilha::baixar(
                Planilha::conteudo($res['colunas'], $res['linhas'], $cabecalho),
                'relatorio-' . $fonte . '-' . date('Y-m-d') . '.csv'
            );
            exit;
        }

        rel_json(200, ['success' => true] + $res);
    }

    rel_json(400, ['error' => 'Ação desconhecida']);
} catch (\Throwable $e) {
    error_log('[api/relatorios] ' . $e->getMessage());
    rel_json(500, ['error' => 'Erro ao montar o relatório']);
}
