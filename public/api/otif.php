<?php

/**
 * /api/otif.php: o OTIF (On Time In Full) das tarefas, por colaborador.
 *
 *   GET ?de=AAAA-MM-DD&ate=AAAA-MM-DD[&colaborador=ID]
 *   GET ...&formato=csv   baixa o ranking como planilha
 *
 * A conta e as regras estão em App\Tarefas\Otif; a tela é /desempenho.php.
 *
 * QUEM VÊ O QUÊ
 *   - precisa enxergar Tarefas (admin, curinga ou permissão `tarefas`)
 *   - dono/admin da conta vê a equipe inteira das contas acessíveis
 *   - os demais veem SÓ o próprio desempenho, e `colaborador` é ignorado:
 *     a nota de um colega não é informação que todo mundo deva ter
 *
 * Somente GET: é leitura.
 */

require_once __DIR__ . '/_json_guard.php';
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\AccountContext;
use App\Core\ApiResponse;
use App\Relatorios\Planilha;
use App\Tarefas\Otif;

session_start(['read_and_close' => true]);

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ApiResponse::error('Método não permitido', 405);
}

$perfilAdmin = strtolower((string) ($_SESSION['user_perfil'] ?? '')) === 'admin';
$perms       = (array) ($_SESSION['user_permissions'] ?? []);
if (!$perfilAdmin && !in_array('*', $perms, true) && !in_array('tarefas', $perms, true)) {
    ApiResponse::forbidden('Sem acesso ao módulo de Tarefas');
}

$veEquipe = $ctx->isOwnerOrAdmin() || $perfilAdmin;
[$de, $ate] = Otif::periodo($_GET['de'] ?? null, $_GET['ate'] ?? null);
$foco = isset($_GET['colaborador']) && $_GET['colaborador'] !== '' ? max(0, (int) $_GET['colaborador']) : null;

$rel = Otif::relatorio(
    $ctx->getAccessibleAccountIds('tarefas'),
    $de, $ate,
    $veEquipe ? null : $ctx->getUserId(),
    $veEquipe ? $foco : null
);

if (strtolower((string) ($_GET['formato'] ?? 'json')) === 'csv') {
    $pct = static fn($v) => $v === null ? '' : str_replace('.', ',', (string) $v) . '%';
    $linhas = [];
    foreach ($rel['colaboradores'] as $c) {
        $linhas[] = [
            $c['nome'], $c['compromissos'], $pct($c['otif_pct']), $pct($c['ot_pct']), $pct($c['if_pct']),
            $c['otif'], $c['atrasadas'], $c['incompletas'], $c['nao_entregues'], $c['sem_prazo'],
        ];
    }
    Planilha::baixar(
        Planilha::conteudo(
            ['Colaborador', 'Compromissos', 'OTIF', 'No prazo', 'Completas', 'Entregas OTIF',
             'Atrasadas', 'Incompletas', 'Não entregues', 'Entregues sem prazo'],
            $linhas,
            [
                'Desempenho (OTIF) das tarefas',
                'Período: ' . date('d/m/Y', strtotime($de)) . ' a ' . date('d/m/Y', strtotime($ate)),
                'Gerado em ' . date('d/m/Y H:i', strtotime(\App\Tarefas\TaskEntrega::agoraLocal())),
            ]
        ),
        'otif-' . $de . '-a-' . $ate . '.csv'
    );
    exit;
}

ApiResponse::ok($rel);
