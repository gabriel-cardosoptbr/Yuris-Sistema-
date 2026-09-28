<?php
/**
 * tarefas_acesso_test.php — quem enxerga e quem edita quadros e tarefas.
 *
 * O RELATO (28/09/2026): "uma conta de usuário administrador não consegue
 * interagir com Tarefas. O admin principal funciona, uma conta criada não."
 *
 * O que a investigação mediu em produção, e é o que este arquivo trava:
 *
 *  1. Quadro só era visível para o DONO ou MEMBRO, e não existia tela para
 *     adicionar membro. Zero membros no sistema inteiro; "Compartilhado" era só
 *     rótulo; 281 tarefas atribuídas a quem não abria o quadro.
 *  2. A conta criada tinha Perfil = Administrador e Nível = Usuário. O selo
 *     dizia ADMIN, o sistema tratava como usuário comum.
 *  3. De passagem, dois buracos de segurança:
 *     - canEditTask liberava QUALQUER admin antes de checar a conta: admin de um
 *       escritório editava tarefa de outro trocando o id.
 *     - a edição de usuário não checava se quem pedia era admin: usuário comum
 *       se promovia a dono, ou trocava a senha do dono.
 *
 * ESCREVE NO BANCO (quadros com marca, apagados no fim). Não rode em produção.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/tarefas_acesso_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Tarefas\TaskBoard;
use App\Usuarios\User;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

$FAILS = 0; $PASSES = 0;
function pass(string $m): void { global $PASSES; $PASSES++; echo "  [PASS] $m\n"; }
function fail(string $m): void { global $FAILS;  $FAILS++;  echo "  [FAIL] $m\n"; }
function secao(string $t): void { echo "\n== $t ==\n"; }
function ok(bool $c, string $m): void { $c ? pass($m) : fail($m); }
function eq($esp, $obt, string $m): void {
    if ($esp === $obt) { pass($m); return; }
    fail($m . ' (esperado ' . var_export($esp, true) . ', obtido ' . var_export($obt, true) . ')');
}

/* ===================================================================== */
secao('1. Perfil e Nível de acesso dizem a mesma coisa');
/* ===================================================================== */

eq(['admin', 'admin'], User::alinharPerfilENivel('admin', 'user'),
   'o caso do relato: Perfil Administrador + Nível Usuário vira admin nos dois');
eq(['admin', 'admin'], User::alinharPerfilENivel('user', 'admin'),  'Nível Administrador puxa o Perfil');
eq(['admin', 'owner'], User::alinharPerfilENivel('user', 'owner'),  'Proprietário continua proprietário, e o Perfil vira admin');
eq(['admin', 'owner'], User::alinharPerfilENivel('admin', 'owner'), 'combinação já coerente não muda');
eq(['user', 'manager'], User::alinharPerfilENivel('user', 'manager'), 'gerente comum continua gerente');
eq(['user', 'user'], User::alinharPerfilENivel(null, null),         'sem nada, usuário comum');
eq(['user', 'member'], User::alinharPerfilENivel('user', 'member'), 'nível legado "member" NÃO é reescrito em silêncio');
eq(['admin', 'admin'], User::alinharPerfilENivel('admin', 'member'), 'mas Perfil Administrador ainda promove um "member" a admin');
ok(User::alinharPerfilENivel('admin', 'viewer')[1] !== 'owner',     'nunca promove a proprietário por conta própria');

/* ===================================================================== */
/* cenário no banco                                                      */
/* ===================================================================== */

$st = $pdo->query("SELECT account_id, GROUP_CONCAT(id ORDER BY id) ids FROM users
                   WHERE deleted_at IS NULL AND status = 'active' GROUP BY account_id
                   HAVING COUNT(*) >= 3 ORDER BY account_id LIMIT 1");
$a = $st->fetch(\PDO::FETCH_ASSOC);
$st = $pdo->prepare("SELECT account_id, MIN(id) id FROM users WHERE deleted_at IS NULL AND account_id <> ? GROUP BY account_id ORDER BY account_id LIMIT 1");
$st->execute([(int)($a['account_id'] ?? 0)]);
$b = $st->fetch(\PDO::FETCH_ASSOC);
if (!$a || !$b) { echo "\nSKIP do cenário de banco: precisa de uma conta com 3 usuários e outra conta.\n"; goto fim; }

$CONTA_A = (int)$a['account_id'];
$CONTA_B = (int)$b['account_id'];
[$DONO, $COLEGA, $LEITOR] = array_map('intval', array_slice(explode(',', $a['ids']), 0, 3));
$DE_FORA = (int)$b['id'];
echo "\nCenário: conta A=$CONTA_A (dono $DONO, colega $COLEGA, leitor $LEITOR), conta B=$CONTA_B (usuário $DE_FORA)\n";

$MARCA = 'ZZTESTE_ACESSO_' . getmypid();
$pdo->prepare("DELETE FROM task_boards WHERE nome LIKE 'ZZTESTE_ACESSO_%'")->execute();

$PESSOAL = TaskBoard::create(['nome' => "$MARCA pessoal", 'tipo' => 'pessoal', 'owner_id' => $DONO, 'account_id' => $CONTA_A]);
$COMPART = TaskBoard::create(['nome' => "$MARCA compartilhado", 'tipo' => 'compartilhado', 'owner_id' => $DONO, 'account_id' => $CONTA_A]);
TaskBoard::addMember($PESSOAL, $LEITOR, 'viewer');

/* ===================================================================== */
secao('2. quadro PESSOAL');
/* ===================================================================== */

ok(TaskBoard::canEdit($PESSOAL, $DONO, [$CONTA_A]),                  'o dono vê e edita');
ok(!TaskBoard::canView($PESSOAL, $COLEGA, [$CONTA_A], false),        'colega comum NÃO vê o quadro pessoal de outro');
ok(TaskBoard::canView($PESSOAL, $COLEGA, [$CONTA_A], true),          'O CASO DO RELATO: admin da conta vê o quadro');
ok(TaskBoard::canEdit($PESSOAL, $COLEGA, [$CONTA_A], true),          'admin da conta edita o conteúdo');
ok(TaskBoard::canManage($PESSOAL, $COLEGA, [$CONTA_A], true),        'admin da conta administra o quadro');
ok(TaskBoard::canView($PESSOAL, $LEITOR, [$CONTA_A], false),         'membro leitor vê');
ok(!TaskBoard::canEdit($PESSOAL, $LEITOR, [$CONTA_A], false),        'membro leitor NÃO edita');
ok(!TaskBoard::canView($PESSOAL, $DE_FORA, [$CONTA_B], true),        'admin de OUTRA conta não vê');
ok(!TaskBoard::canView($PESSOAL, $COLEGA, null, true),               'sem escopo de conta, ser admin não abre nada');

/* ===================================================================== */
secao('3. quadro COMPARTILHADO');
/* ===================================================================== */

ok(TaskBoard::canView($COMPART, $COLEGA, [$CONTA_A], false),         'toda a equipe da conta vê');
ok(TaskBoard::canEdit($COMPART, $COLEGA, [$CONTA_A], false),         'toda a equipe edita as tarefas');
ok(!TaskBoard::canManage($COMPART, $COLEGA, [$CONTA_A], false),      'mas não renomeia nem troca o tipo');
ok(TaskBoard::canManage($COMPART, $DONO, [$CONTA_A], false),         'o dono administra');
ok(!TaskBoard::canView($COMPART, $DE_FORA, [$CONTA_B], false),       'outra conta não vê o compartilhado');
ok(!TaskBoard::canView($COMPART, $COLEGA, null, false),
   'sem escopo de conta, "compartilhado" não vale (senão vazaria entre escritórios)');

/* ===================================================================== */
secao('4. a lista de quadros (o seletor "Nenhum quadro")');
/* ===================================================================== */

$ids = fn(array $bs) => array_map(fn($x) => (int)$x['id'], $bs);
$colega = $ids(TaskBoard::findForUser($COLEGA, [$CONTA_A], false));
$admin  = $ids(TaskBoard::findForUser($COLEGA, [$CONTA_A], true));
$fora   = $ids(TaskBoard::findForUser($DE_FORA, [$CONTA_B], true));

ok(in_array($COMPART, $colega, true) && !in_array($PESSOAL, $colega, true),
   'colega comum lista o compartilhado e não o pessoal de outro');
ok(in_array($COMPART, $admin, true) && in_array($PESSOAL, $admin, true),
   'admin lista os dois (deixa de ver "Nenhum quadro")');
ok(!in_array($COMPART, $fora, true) && !in_array($PESSOAL, $fora, true),
   'admin de outra conta não lista nenhum dos dois');

/* ===================================================================== */
secao('5. canEditTask: o buraco entre escritórios');
/* ===================================================================== */

/*
 * canEditTask mora dentro do endpoint (public/api/tasks.php), que executa ao
 * ser incluído. Extrai só a função do arquivo real e a declara aqui, para o
 * teste exercitar o código que vai para produção e não uma cópia.
 */
$fonteTasks = (string) file_get_contents(__DIR__ . '/../../public/api/tasks.php');
$ini = strpos($fonteTasks, 'function canEditTask(');
$i = strpos($fonteTasks, '{', $ini); $prof = 0;
for (; $i < strlen($fonteTasks); $i++) {
    if ($fonteTasks[$i] === '{') $prof++;
    elseif ($fonteTasks[$i] === '}' && --$prof === 0) break;
}
$corpoCanEditTask = substr($fonteTasks, $ini, $i - $ini + 1);
eval('use App\Tarefas\TaskBoard; ' . $corpoCanEditTask);

$tarefaA = ['board_id' => $PESSOAL, 'origin_account_id' => $CONTA_A, 'criado_por_id' => $DONO, 'responsavel_id' => $COLEGA];

ok(!canEditTask($tarefaA, $DE_FORA, true, [$CONTA_B]),
   'ADMIN DE OUTRO ESCRITÓRIO NÃO EDITA a tarefa (era o buraco)');
ok(canEditTask($tarefaA, $COLEGA, false, [$CONTA_A]),
   'o responsável edita a própria tarefa, mesmo num quadro pessoal de outro');
ok(canEditTask($tarefaA, $DONO, false, [$CONTA_A]), 'o criador edita');
ok(!canEditTask($tarefaA, $LEITOR, false, [$CONTA_A]),
   'membro só leitor, que não é criador nem responsável, não edita');
ok(!canEditTask($tarefaA, $COLEGA, false, [$CONTA_B]),
   'ser o responsável não vale fora da conta da tarefa');
ok(!canEditTask($tarefaA, $COLEGA, true, []), 'sem contas acessíveis, nega');

/* ===================================================================== */
secao('6. as travas estão no código de verdade');
/* ===================================================================== */

ok(!preg_match('/\{\s*if \(\$isAdmin\) return true;/', $corpoCanEditTask),
   'canEditTask não começa mais liberando qualquer admin');
ok(str_contains($corpoCanEditTask, 'origin_account_id'),
   'canEditTask confere a conta da tarefa');

$fonteUsers = (string) file_get_contents(__DIR__ . '/../../public/api/users.php');
ok(str_contains($fonteUsers, "if (\$editandoOutro) \$negar('Apenas owner/admin pode editar outros usuários')"),
   'usuário comum não edita outro usuário');
ok(str_contains($fonteUsers, "\$input['role']   !== \$alvo['role'])   \$negar("),
   'usuário comum não muda o próprio nível');
ok(str_contains($fonteUsers, "\$editandoOutro && \$alvo['role'] === 'owner') \$negar("),
   'admin não edita o proprietário');
ok(substr_count($fonteUsers, 'User::alinharPerfilENivel(') >= 2,
   'criação e edição de usuário passam pelo alinhamento de Perfil e Nível');

foreach (['public/api/task_boards.php', 'public/api/task_columns.php', 'public/api/tasks.php'] as $arq) {
    $f = (string) file_get_contents(__DIR__ . '/../../' . $arq);
    preg_match_all('/TaskBoard::(canView|canEdit|canManage|findForUser)\(([^;]*)\)/', $f, $m);
    $semAdmin = array_values(array_filter($m[0], fn($c) => !str_contains($c, '$isAdmin')));
    eq([], $semAdmin, "$arq passa \$isAdmin em toda checagem de quadro");
}

/* ===================================================================== */
secao('7. coluna tem que ser do quadro da tarefa, e o último admin é da CONTA');
/* ===================================================================== */

$putTasks = substr($fonteTasks, strpos($fonteTasks, "if (\$method === 'PUT')"));
ok(str_contains($putTasks, "(int)\$colDestino['board_id'] !== (int)\$task['board_id']"),
   'edição: coluna de outro quadro (ou vazia) é ignorada');
$posUnset  = strpos($putTasks, 'unset($input[\'column_id\'])');
$posUpdate = strpos($putTasks, 'Task::update($id, $input');
// strpos devolve false quando não acha, e false < número é VERDADEIRO no PHP:
// sem checar os dois, este teste passava no código antigo, que nem tinha a trava.
ok($posUnset !== false && $posUpdate !== false && $posUnset < $posUpdate,
   'edição: a checagem da coluna vem ANTES de gravar');
ok(str_contains($fonteTasks, "(int)\$colMove['board_id'] !== (int)\$task['board_id']) fail("),
   'arrastar: recusa coluna de outro quadro');

ok(!str_contains($fonteUsers, "WHERE perfil = 'admin' AND deleted_at IS NULL AND id != :id"),
   'a contagem de admins não é mais do sistema inteiro');
ok(str_contains($fonteUsers, 'WHERE account_id = (SELECT account_id FROM users WHERE id = :id1)'),
   'a contagem de admins é da conta do usuário');
ok(str_contains($fonteUsers, "(perfil = 'admin' OR role IN ('owner','admin'))"),
   'admin é reconhecido por qualquer um dos dois campos (contas antigas desalinhadas)');

/* ===================================================================== */
secao('limpeza');
/* ===================================================================== */

$pdo->prepare("DELETE FROM task_boards WHERE nome LIKE 'ZZTESTE_ACESSO_%'")->execute();
$sobrou = (int)$pdo->query("SELECT COUNT(*) FROM task_boards WHERE nome LIKE 'ZZTESTE_ACESSO_%'")->fetchColumn();
eq(0, $sobrou, 'nenhum quadro de teste sobrou');

fim:
echo "\n== RESULTADO ==\n  passou: $PASSES\n  falhou: $FAILS\n";
exit($FAILS > 0 ? 1 : 0);
