<?php
/**
 * notificacao_prazos_tick.php — avisa quem tem prazo ou tarefa vencendo.
 *
 * Roda por cron, uma vez por dia de manhã. Sem sessão, sem tenant logado:
 * percorre todas as contas e avisa cada responsável.
 *
 * ---------------------------------------------------------------------------
 * POR QUE ISTO EXISTE
 * ---------------------------------------------------------------------------
 * Todo o resto da central reage a alguém FAZER alguma coisa. Prazo é o
 * contrário: o evento é o tempo passar, e ninguém clica em nada. Sem um tick, o
 * único aviso que realmente não pode faltar num escritório de advocacia seria
 * justamente o único que o sistema nunca daria.
 *
 * ---------------------------------------------------------------------------
 * O QUE ELE AVISA, E PARA QUEM
 * ---------------------------------------------------------------------------
 *   processo_prazos    o responsável está gravado como TEXTO, não como id, então
 *                      o aviso vai para o responsável DO PROCESSO, que é quem
 *                      tem usuário de verdade
 *   tasks              responsavel_id é id, vai direto para ele
 *
 * Janela: vence hoje, vence amanhã, ou venceu há pouco e continua em aberto. O
 * vencido é o mais importante dos três e é o que o sistema mais escondia.
 *
 * ---------------------------------------------------------------------------
 * DOIS LIMITES, MEDIDOS CONTRA A BASE REAL ANTES DE LIGAR
 * ---------------------------------------------------------------------------
 * O dry-run em produção devolveu 344 itens na janela, e UMA pessoa sozinha
 * levaria 102. O contador vermelho marcaria 102 no primeiro dia, e a pessoa
 * mutaria o sino antes de ler o primeiro aviso. Cumprir o pedido assim seria
 * o mesmo que não cumprir.
 *
 *   LIMITE_ATRASO_DIAS  prazo vencido há mais de 30 dias não é prazo, é dado
 *                       abandonado. Avisar todo dia sobre maio, para sempre, é
 *                       barulho que nunca para e ensina a ignorar o sino.
 *
 *   MAX_POR_PESSOA      ninguém recebe mais que 10 por rodada. O que passar
 *                       disso vira UMA linha de resumo, "e mais 92 vencendo",
 *                       que leva para a tela de tarefas. Dez avisos a pessoa lê;
 *                       cem ela apaga sem olhar.
 *
 * Os mais urgentes vêm primeiro, então o corte nunca engole o que vence hoje.
 *
 * ---------------------------------------------------------------------------
 * RODAR DUAS VEZES NO MESMO DIA NÃO DUPLICA
 * ---------------------------------------------------------------------------
 * A chave de dedupe inclui a DATA. Então o mesmo prazo avisa uma vez por dia,
 * todo dia, enquanto continuar vencendo. É o comportamento certo: um prazo
 * vencido há três dias precisa aparecer de novo, não sumir porque já avisou.
 *
 * A janela curta de `Aviso` (2 minutos) NÃO bastaria aqui: com ela, rodar o tick
 * duas vezes no mesmo dia criaria dois avisos do mesmo prazo. Por isso cada
 * chamada passa `janela_min => Aviso::JANELA_DIARIA_MIN`, e aí o dedupe olha o
 * dia inteiro em vez dos últimos dois minutos.
 *
 * Uso: docker exec -i yuris_app php /var/www/html/public/api/notificacao_prazos_tick.php
 * Cron sugerido: 5 8 * * *
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Notificacoes\Aviso;
use App\Notificacoes\Movimento;

$cli    = PHP_SAPI === 'cli';
$dryRun = $cli && in_array('--dry-run', $argv ?? [], true);

if (!$cli) {
    // Pela web só com o mesmo segredo dos outros ticks, e sem listar nada.
    header('Content-Type: application/json; charset=utf-8');
    $esperado = \App\Core\EnvLoader::get('CRON_TOKEN', '');
    $enviado  = (string) ($_GET['token'] ?? '');
    if ($esperado === '' || !hash_equals($esperado, $enviado)) {
        http_response_code(401);
        echo json_encode(['error' => 'não autorizado']);
        exit;
    }
}

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

/** Prazo vencido há mais que isto é dado abandonado, não compromisso. */
const LIMITE_ATRASO_DIAS = 30;

/** Teto por pessoa por rodada. O excedente vira uma linha de resumo. */
const MAX_POR_PESSOA = 10;

$hoje    = date('Y-m-d');
$criados = 0;
$olhados = 0;

/*
 * Quantos avisos cada pessoa já levou nesta rodada, e quantos sobraram.
 * O resumo do excedente é emitido no fim, uma linha por pessoa.
 */
$porPessoa  = [];
$excedente  = [];
$contaDe    = [];

/** Cabe mais um aviso para esta pessoa? Se não, contabiliza o excedente. */
function cabe(int $userId, int $accountId): bool
{
    global $porPessoa, $excedente, $contaDe;
    $contaDe[$userId] = $accountId;
    $atual = $porPessoa[$userId] ?? 0;
    if ($atual >= MAX_POR_PESSOA) {
        $excedente[$userId] = ($excedente[$userId] ?? 0) + 1;
        return false;
    }
    $porPessoa[$userId] = $atual + 1;
    return true;
}

function quando(string $data, string $hoje): string
{
    if ($data < $hoje)  {
        $dias = (int) ((strtotime($hoje) - strtotime($data)) / 86400);
        return 'venceu há ' . $dias . ' dia' . ($dias === 1 ? '' : 's');
    }
    if ($data === $hoje) return 'vence hoje';
    return 'vence amanhã';
}

/* ===========================================================================
 * 1. Prazos de processo
 *
 * O JOIN em `processos` não é conveniência: `processo_prazos` não tem
 * account_id, e é de lá que sai tanto a conta quanto o responsável com usuário
 * de verdade.
 * ========================================================================= */
$st = $pdo->prepare(
    "SELECT z.id, z.descricao, z.data_limite, z.status,
            p.id AS processo_id, p.account_id, p.responsavel_user_id,
            COALESCE(NULLIF(p.numero_cnj,''), NULLIF(p.numero,''), CONCAT('#', p.id)) AS processo_titulo
       FROM processo_prazos z
       JOIN processos p ON p.id = z.processo_id
      WHERE p.deleted_at IS NULL
        AND p.responsavel_user_id IS NOT NULL
        AND z.data_limite IS NOT NULL
        AND z.data_limite <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        AND z.data_limite >= DATE_SUB(CURDATE(), INTERVAL " . LIMITE_ATRASO_DIAS . " DAY)
        AND (z.status IS NULL OR LOWER(z.status) NOT IN ('concluido','concluído','cancelado','feito'))
   ORDER BY z.data_limite ASC"
);
$st->execute();

foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $z) {
    $olhados++;
    $data = substr((string) $z['data_limite'], 0, 10);
    $texto = trim((string) ($z['descricao'] ?? '')) ?: 'Prazo';

    if ($dryRun) { continue; }
    if (!cabe((int) $z['responsavel_user_id'], (int) $z['account_id'])) { continue; }

    $id = Aviso::paraUsuario((int) $z['account_id'], (int) $z['responsavel_user_id'], [
        'tipo'        => 'prazo.vencendo',
        'titulo'      => 'Prazo ' . quando($data, $hoje) . ': ' . $z['processo_titulo'],
        'mensagem'    => $texto . ' (limite ' . date('d/m/Y', strtotime($data)) . ')',
        'entidade'    => 'processo',
        'entidade_id' => (int) $z['processo_id'],
        'url'         => Movimento::urlDe('processo', (int) $z['processo_id']),
        'preferencia' => 'prazo',
        // A data entra na chave: avisa uma vez POR DIA enquanto estiver vencendo.
        'chave_dedupe'=> 'prazo:' . $z['id'] . ':' . $hoje,
        'janela_min'  => Aviso::JANELA_DIARIA_MIN,
    ]);
    if ($id > 0) { $criados++; }
}

/* ===========================================================================
 * 2. Tarefas
 *
 * A conta vem do QUADRO: `tasks` não tem account_id.
 * ========================================================================= */
$st = $pdo->prepare(
    "SELECT t.id, t.titulo, t.prazo, t.responsavel_id, b.account_id
       FROM tasks t
       JOIN task_boards b ON b.id = t.board_id
      WHERE t.responsavel_id IS NOT NULL
        AND t.prazo IS NOT NULL
        AND DATE(t.prazo) <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)
        AND DATE(t.prazo) >= DATE_SUB(CURDATE(), INTERVAL " . LIMITE_ATRASO_DIAS . " DAY)
        AND t.status = 'ativa'
   ORDER BY t.prazo ASC"
);
$st->execute();

foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $t) {
    $olhados++;
    $data = substr((string) $t['prazo'], 0, 10);

    if ($dryRun) { continue; }
    if (!cabe((int) $t['responsavel_id'], (int) $t['account_id'])) { continue; }

    $id = Aviso::paraUsuario((int) $t['account_id'], (int) $t['responsavel_id'], [
        'tipo'        => 'tarefa.vencendo',
        'titulo'      => 'Tarefa ' . quando($data, $hoje) . ': ' . $t['titulo'],
        'mensagem'    => 'Prazo ' . date('d/m/Y', strtotime($data)) . '.',
        'entidade'    => 'tarefa',
        'entidade_id' => (int) $t['id'],
        'url'         => Movimento::urlDe('tarefa', (int) $t['id']),
        'preferencia' => 'prazo',
        'chave_dedupe'=> 'tarefa-prazo:' . $t['id'] . ':' . $hoje,
        'janela_min'  => Aviso::JANELA_DIARIA_MIN,
    ]);
    if ($id > 0) { $criados++; }
}

/* ===========================================================================
 * 3. O excedente, numa linha por pessoa
 *
 * Quem passou do teto recebe "e mais N vencendo" em vez de N avisos. A linha
 * leva para a tela de tarefas, que e onde da para resolver em lote.
 * ========================================================================= */
$resumos = 0;
foreach ($excedente as $userId => $quantos) {
    if ($dryRun || $quantos <= 0) { continue; }
    $id = Aviso::paraUsuario((int) $contaDe[$userId], (int) $userId, [
        'tipo'        => 'prazo.resumo',
        // "itens", nao "items": o plural de item em portugues e irregular, e
        // appendar 's' produzia "E mais 2 items vencendo".
        'titulo'      => 'E mais ' . $quantos . ' ' . ($quantos === 1 ? 'item' : 'itens') . ' vencendo',
        'mensagem'    => 'Além dos ' . MAX_POR_PESSOA . ' mais urgentes acima. Abra Tarefas para ver a lista completa.',
        'url'         => '/tarefas.php',
        'preferencia' => 'prazo',
        'chave_dedupe'=> 'prazo-resumo:' . $userId . ':' . $hoje,
        'janela_min'  => Aviso::JANELA_DIARIA_MIN,
    ]);
    if ($id > 0) { $resumos++; $criados++; }
}

$msg = "prazos e tarefas na janela: $olhados   avisos criados: $criados   (resumos de excedente: $resumos)"
     . ($dryRun ? '   (DRY-RUN: nada foi gravado)' : '');

if ($cli) {
    echo '  ' . $msg . PHP_EOL;
} else {
    echo json_encode(['ok' => true, 'olhados' => $olhados, 'criados' => $criados]);
}
