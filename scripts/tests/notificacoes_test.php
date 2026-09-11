<?php
/**
 * notificacoes_test.php — a central de notificações.
 *
 * O pedido foi "tem que chegar absolutamente tudo, e quando eu mencionar alguém
 * o responsável tem que receber". Cumprir isso ao pé da letra, sem cuidado, cria
 * um sino com 300 itens por dia, que ninguém abre, e aí o aviso que importava
 * some junto.
 *
 * Então o que está sob teste não é "grava uma linha". É o conjunto de regras que
 * fazem o sino continuar sendo lido:
 *
 *  - ninguém é avisado do próprio ato
 *  - uma edição que muda cinco campos é UM aviso, não cinco
 *  - o contador vermelho conta só o que é dirigido a você
 *  - aviso não atravessa escritório
 *  - quem desliga uma categoria deixa de recebê-la, e continua recebendo o resto
 *  - aviso NUNCA derruba a operação que ele descreve
 *
 * ESCREVE NO BANCO. Não rode em produção.
 *
 * Uso local: C:\xampp\php\php.exe scripts/tests/notificacoes_test.php
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Master\AccountNotification;
use App\Notificacoes\Aviso;
use App\Notificacoes\Movimento;

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

$PREFIXO = 'TESTE-NOTIF';

$users = $pdo->query('SELECT id, account_id, nome FROM users WHERE deleted_at IS NULL ORDER BY id LIMIT 2')->fetchAll(\PDO::FETCH_ASSOC);
if (count($users) < 2) { echo "SKIP: preciso de pelo menos dois usuários.\n"; exit(0); }
[$A, $B] = $users;
$ACC = (int) $A['account_id'];
$UA  = (int) $A['id'];
$UB  = (int) $B['id'];
if ((int) $B['account_id'] !== $ACC) { echo "SKIP: os dois usuários precisam ser da mesma conta.\n"; exit(0); }

$CARD = (int) $pdo->query("SELECT id FROM cards WHERE account_id = $ACC AND deleted_at IS NULL LIMIT 1")->fetchColumn();
echo "Cenário: conta $ACC, usuários #$UA e #$UB, card #$CARD\n";

/*
 * Limpeza por TIPO e por título com prefixo. Não dá para apagar tudo da conta:
 * as notificações antigas (DJEN, vínculos) são dado real do ambiente.
 */
$limpar = function () use ($pdo, $PREFIXO, $ACC) {
    /*
     * `%PREFIXO%`, e nao `PREFIXO%`.
     *
     * Os avisos de responsavel tem titulo "Voce e o responsavel: TESTE-NOTIF
     * Fulano": o prefixo NAO fica no comeco. Com `LIKE 'PREFIXO%'` a limpeza
     * nao pegava essas linhas, elas sobravam entre rodadas e a rodada seguinte
     * contava 3 onde esperava 1. Pior: a assercao final "nada ficou no banco"
     * usava o mesmo LIKE errado e passava MENTINDO.
     */
    $pdo->prepare("DELETE FROM account_notifications WHERE titulo LIKE ? OR tipo LIKE 'TESTE%'")
        ->execute(["%$PREFIXO%"]);
    $pdo->prepare("DELETE FROM account_notifications WHERE account_id = ? AND tipo LIKE 'movimento.%'")
        ->execute([$ACC]);
    $pdo->prepare('DELETE FROM notificacao_preferencias WHERE user_id IN (?, ?)')->execute([$GLOBALS['UA'] ?? 0, $GLOBALS['UB'] ?? 0]);
};
$GLOBALS['UA'] = $UA; $GLOBALS['UB'] = $UB;
$limpar();

/** Conta avisos com este título exato. */
function quantos(\PDO $pdo, string $titulo): int
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM account_notifications WHERE titulo = ?');
    $st->execute([$titulo]);
    return (int) $st->fetchColumn();
}

/* ===================================================================== */
secao('Ninguém é avisado do próprio ato');

eq(0, Aviso::paraUsuario($ACC, $UA, [
    'tipo' => 'TESTE', 'titulo' => "$PREFIXO proprio", 'origem_user_id' => $UA,
]), 'o autor do evento NÃO recebe aviso dele mesmo');

ok(Aviso::paraUsuario($ACC, $UB, [
    'tipo' => 'TESTE', 'titulo' => "$PREFIXO para outro", 'origem_user_id' => $UA,
]) > 0, 'mas a outra pessoa recebe');

ok(Aviso::paraUsuario($ACC, $UA, [
    'tipo' => 'TESTE', 'titulo' => "$PREFIXO sem origem",
]) > 0, 'aviso sem origem (do sistema) chega normalmente');

/* ===================================================================== */
secao('Aviso não atravessa escritório');

eq(0, Aviso::paraUsuario(999999, $UB, ['tipo' => 'TESTE', 'titulo' => "$PREFIXO conta inexistente"]),
   'conta que não existe não grava');

$outroUser = (int) $pdo->query("SELECT id FROM users WHERE account_id <> $ACC AND deleted_at IS NULL LIMIT 1")->fetchColumn();
if ($outroUser) {
    eq(0, Aviso::paraUsuario($ACC, $outroUser, ['tipo' => 'TESTE', 'titulo' => "$PREFIXO cross tenant"]),
       'usuário de OUTRO escritório não recebe aviso desta conta (o título traz nome de cliente)');
} else {
    echo "  [SKIP] só existe uma conta com usuários: cross-tenant não pôde ser testado\n";
}

eq(0, Aviso::paraUsuario($ACC, $UB, ['tipo' => 'TESTE', 'titulo' => '']), 'aviso sem título não grava');

/* ===================================================================== */
secao('Dedupe: a mesma coisa em janela curta é UM aviso');

$t = "$PREFIXO dedupe";
Aviso::paraUsuario($ACC, $UB, ['tipo' => 'TESTE', 'titulo' => $t, 'chave_dedupe' => 'K1']);
Aviso::paraUsuario($ACC, $UB, ['tipo' => 'TESTE', 'titulo' => $t, 'chave_dedupe' => 'K1']);
Aviso::paraUsuario($ACC, $UB, ['tipo' => 'TESTE', 'titulo' => $t, 'chave_dedupe' => 'K1']);
eq(1, quantos($pdo, $t), 'três vezes a mesma chave viram um aviso só');

Aviso::paraUsuario($ACC, $UB, ['tipo' => 'TESTE', 'titulo' => "$PREFIXO chave diferente", 'chave_dedupe' => 'K2']);
eq(1, quantos($pdo, "$PREFIXO chave diferente"), 'chave diferente gera aviso próprio');

// Sem chave não há dedupe: quem não pediu, não ganha.
$t2 = "$PREFIXO sem chave";
Aviso::paraUsuario($ACC, $UB, ['tipo' => 'TESTE', 'titulo' => $t2]);
Aviso::paraUsuario($ACC, $UB, ['tipo' => 'TESTE', 'titulo' => $t2]);
eq(2, quantos($pdo, $t2), 'sem chave de dedupe, cada chamada é um aviso');

// A janela é parametrizável, e é disso que o aviso diário de prazo depende.
ok(Aviso::JANELA_DIARIA_MIN > Aviso::JANELA_DEDUPE_MIN, 'existe uma janela diária, maior que a padrão');
ok(Aviso::deduplicado($ACC, $UB, 'K1', Aviso::JANELA_DIARIA_MIN), 'na janela diária a chave K1 continua deduplicada');

/* ===================================================================== */
secao('O contador vermelho conta SÓ o que é dirigido');

$limpar();
$antes = AccountNotification::countNaoLidas($UB, $ACC);

Aviso::paraUsuario($ACC, $UB, ['tipo' => 'TESTE', 'titulo' => "$PREFIXO dirigido 1"]);
Aviso::paraUsuario($ACC, $UB, ['tipo' => 'TESTE', 'titulo' => "$PREFIXO dirigido 2"]);
eq($antes + 2, AccountNotification::countNaoLidas($UB, $ACC), 'dois avisos dirigidos somam dois no contador');

for ($i = 0; $i < 5; $i++) {
    Aviso::paraConta($ACC, ['tipo' => 'TESTE', 'titulo' => "$PREFIXO movimento $i", 'entidade' => 'card', 'entidade_id' => $CARD]);
}
eq($antes + 2, AccountNotification::countNaoLidas($UB, $ACC),
   'cinco movimentações NÃO mexem no contador (é o que impede o badge de marcar 300 todo dia)');

$lista = AccountNotification::listForUser($UB, $ACC, true);
$titulos = array_column($lista, 'titulo');
ok(in_array("$PREFIXO movimento 0", $titulos, true), 'mas as movimentações APARECEM na lista');
ok(in_array("$PREFIXO dirigido 1", $titulos, true), 'e os dirigidos também');

// O dirigido vem primeiro: com "tudo chega", o movimento das últimas horas
// empurraria para baixo o prazo que veio de manhã.
$primeiraMov = null; $ultimoDir = null;
foreach ($lista as $i => $n) {
    if (($n['natureza'] ?? '') === 'movimento' && $primeiraMov === null) { $primeiraMov = $i; }
    if (($n['natureza'] ?? '') === 'dirigido') { $ultimoDir = $i; }
}
ok($primeiraMov === null || $ultimoDir === null || $ultimoDir < $primeiraMov,
   'todo aviso dirigido vem ANTES de qualquer movimentação na lista');

/* ===================================================================== */
secao('Preferência: quem desliga uma categoria para de receber SÓ ela');

$limpar();
ok(Aviso::querReceber($UB, 'mencao'), 'chave ausente significa LIGADO (a entrega não depende de marcar caixinha)');

ok(Aviso::salvarPreferencia($ACC, $UB, 'mencao', false), 'desliga menção');
ok(!Aviso::querReceber($UB, 'mencao'), 'e passa a valer');

eq(0, Aviso::mencao($ACC, $UB, 'Fulano', 'No chat', 'oi', $UA), 'com menção desligada, a menção não chega');
ok(Aviso::paraUsuario($ACC, $UB, ['tipo' => 'TESTE', 'titulo' => "$PREFIXO outro tipo", 'preferencia' => 'responsavel']) > 0,
   'mas o aviso de responsável continua chegando: desligar um não desliga tudo');

ok(Aviso::salvarPreferencia($ACC, $UB, 'mencao', true), 'religa menção');
ok(Aviso::mencao($ACC, $UB, 'Fulano', 'No chat', 'oi de novo', $UA) > 0, 'e a menção volta a chegar');

ok(!Aviso::salvarPreferencia($ACC, $UB, 'chave_inventada', false), 'chave desconhecida é recusada');

$prefs = Aviso::preferenciasDe($UB);
eq(count(Aviso::PREFERENCIAS), count($prefs), 'a foto de preferências traz todas as chaves');
foreach (array_keys(Aviso::PREFERENCIAS) as $k) {
    ok(array_key_exists($k, $prefs), "  a chave $k está na foto");
}

// Movimento desligado some da LISTA, porque ele não tem destinatário.
$limpar();
Aviso::paraConta($ACC, ['tipo' => 'TESTE', 'titulo' => "$PREFIXO mov pref"]);
Aviso::paraUsuario($ACC, $UB, ['tipo' => 'TESTE', 'titulo' => "$PREFIXO dir pref"]);

Aviso::salvarPreferencia($ACC, $UB, 'movimento', false);
$titulos = array_column(AccountNotification::listForUser($UB, $ACC, true), 'titulo');
ok(!in_array("$PREFIXO mov pref", $titulos, true), 'quem desligou movimento não vê movimentação na lista');
ok(in_array("$PREFIXO dir pref", $titulos, true), 'e continua vendo o que é dirigido a ele');

// A mesma movimentação continua visível para quem NÃO desligou.
$titulosA = array_column(AccountNotification::listForUser($UA, $ACC, true), 'titulo');
ok(in_array("$PREFIXO mov pref", $titulosA, true), 'e a outra pessoa, que não desligou, continua vendo');
Aviso::salvarPreferencia($ACC, $UB, 'movimento', true);

/* ===================================================================== */
secao('Movimento: uma edição de cinco campos é UM aviso');

$limpar();
if ($CARD > 0) {
    foreach (['telefone_whatsapp', 'email', 'cep', 'cidade', 'uf'] as $campo) {
        App\Prospeccao\Card::logEvento($CARD, $UA, 'updated', $campo, 'antes', 'depois');
    }
    $n = (int) $pdo->query("SELECT COUNT(*) FROM account_notifications WHERE account_id = $ACC AND tipo LIKE 'movimento.%'")->fetchColumn();
    eq(1, $n, 'cinco campos alterados na mesma edição viram UM aviso');

    $r = $pdo->query("SELECT titulo, natureza, url, entidade FROM account_notifications WHERE account_id = $ACC AND tipo LIKE 'movimento.%' LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
    eq('movimento', $r['natureza'], '  e nasce como movimento, não como dirigido');
    eq('card', $r['entidade'], '  sabendo de que entidade fala');
    ok(str_contains((string) $r['url'], 'prospeccao.php'), '  e com link para a tela certa');
    // Concordância: "Prospecção foi alterado" é o erro que faz o sistema parecer
    // mal feito antes de a pessoa ler o que o aviso diz.
    ok(str_contains((string) $r['titulo'], 'alterada'), '  com o verbo no feminino, porque Prospecção é feminina');

    // Arrastar dentro da coluna não é fato.
    App\Prospeccao\Card::logEvento($CARD, $UA, 'reorder', null, null, '3');
    $n2 = (int) $pdo->query("SELECT COUNT(*) FROM account_notifications WHERE account_id = $ACC AND tipo LIKE 'movimento.%'")->fetchColumn();
    eq(1, $n2, 'reordenar card NÃO gera aviso');

    // Ação diferente é movimento diferente.
    App\Prospeccao\Card::logEvento($CARD, $UA, 'moved', null, 'A', 'B');
    $n3 = (int) $pdo->query("SELECT COUNT(*) FROM account_notifications WHERE account_id = $ACC AND tipo LIKE 'movimento.%'")->fetchColumn();
    eq(2, $n3, 'mudar de etapa é um aviso próprio');

    // Verbo que não está no mapa não some.
    App\Prospeccao\Card::logEvento($CARD, $UA, 'verbo_novo_qualquer', null, null, null);
    $ultimo = (string) $pdo->query("SELECT titulo FROM account_notifications WHERE account_id = $ACC AND tipo LIKE 'movimento.%' ORDER BY id DESC LIMIT 1")->fetchColumn();
    ok(str_contains($ultimo, 'verbo novo qualquer'),
       'ação desconhecida vira texto legível em vez de sumir: ' . $ultimo);
} else {
    echo "  [SKIP] a conta não tem card para exercitar o movimento\n";
}

eq(null, Movimento::urlDe('inexistente', 1), 'entidade sem tela não inventa link');
ok(Movimento::urlDe('processo', 5) !== null, 'processo tem link');

/* ===================================================================== */
secao('Responsável: avisa quem entrou E quem saiu');

$limpar();
Aviso::responsavel($ACC, 'card', $CARD ?: 1, "$PREFIXO Fulano", $UB, $UA, $UA, '/x');

$st = $pdo->prepare("SELECT user_id, tipo FROM account_notifications WHERE titulo LIKE ? ORDER BY id");
$st->execute(["%$PREFIXO Fulano%"]);
$linhas = $st->fetchAll(\PDO::FETCH_ASSOC);

// Quem SAIU é o próprio autor da mudança, então ele não recebe: ele sabe.
eq(1, count($linhas), 'quem entrou é avisado; quem saiu era o próprio autor, e ninguém é avisado do próprio ato');
eq($UB, (int) $linhas[0]['user_id'], '  e o aviso foi para quem entrou');
eq('responsavel.atribuido', $linhas[0]['tipo'], '  com o tipo certo');

// Agora com um terceiro fazendo a troca: os dois lados são avisados.
$limpar();
Aviso::responsavel($ACC, 'processo', 1, "$PREFIXO Beltrano", $UB, $UA, null, '/y');
$st->execute(["%$PREFIXO Beltrano%"]);
$linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
eq(2, count($linhas), 'troca feita por um terceiro avisa os DOIS lados');
$tipos = array_column($linhas, 'tipo');
ok(in_array('responsavel.atribuido', $tipos, true), '  quem entrou soube');
ok(in_array('responsavel.removido', $tipos, true), '  e quem saiu também, senão segue contando com um compromisso que não é mais dele');

// Atribuir para a mesma pessoa duas vezes não repete.
$limpar();
Aviso::responsavel($ACC, 'card', 7, "$PREFIXO Ciclano", $UB, null, $UA, null);
Aviso::responsavel($ACC, 'card', 7, "$PREFIXO Ciclano", $UB, null, $UA, null);
$st->execute(["%$PREFIXO Ciclano%"]);
eq(1, count($st->fetchAll()), 'atribuir duas vezes para a mesma pessoa é um aviso só');

// Trocar para a MESMA pessoa não é troca.
$limpar();
Aviso::responsavel($ACC, 'card', 8, "$PREFIXO Mesmo", $UB, $UB, $UA, null);
$st->execute(["%$PREFIXO Mesmo%"]);
eq(0, count($st->fetchAll()), 'reatribuir para quem já era responsável não avisa nada');

/* ===================================================================== */
secao('Menção');

$limpar();
eq(0, Aviso::mencao($ACC, $UA, 'Fulano', 'No chat', 'oi', $UA), 'mencionar a si mesmo não gera aviso');
$id = Aviso::mencao($ACC, $UB, 'Fulano', 'No chat interno', 'preciso de você neste processo', $UA);
ok($id > 0, 'mencionar outra pessoa gera aviso');
$r = $pdo->query("SELECT titulo, mensagem, natureza FROM account_notifications WHERE id = $id")->fetch(\PDO::FETCH_ASSOC);
ok(str_contains((string) $r['titulo'], 'mencionou você'), '  com título que diz o que é');
ok(str_contains((string) $r['mensagem'], 'preciso de você'), '  e trazendo o trecho da mensagem');
eq('dirigido', $r['natureza'], '  menção é dirigida, então conta no contador');

/* ===================================================================== */
secao('Aviso NUNCA derruba a operação que descreve');

// Entidade que não existe, id absurdo, ação vazia: nada disso pode lançar.
$explodiu = false;
try {
    Movimento::registrar('nao_existe', 99999999, 'updated', $UA);
    Movimento::registrar('card', 0, 'updated', $UA);
    Movimento::registrar('card', 99999999, '', $UA);
    Aviso::paraUsuario($ACC, 0, ['titulo' => 'x']);
    Aviso::paraConta(0, ['titulo' => 'x']);
    Aviso::responsavel($ACC, 'card', 0, '', null, null, null);
    Aviso::querReceber(0, 'chave_que_nao_existe');
} catch (\Throwable $e) {
    $explodiu = true;
    fail('lançou exceção: ' . $e->getMessage());
}
ok(!$explodiu, 'entidade inexistente, id zero e ação vazia não lançam exceção');

/* ===================================================================== */
echo "\n== limpeza ==\n";
$limpar();
$sobra = (int) $pdo->query("SELECT COUNT(*) FROM account_notifications WHERE titulo LIKE '%$PREFIXO%' OR tipo LIKE 'TESTE%'")->fetchColumn();
eq(0, $sobra, 'nenhuma notificação de teste ficou no banco');
$sobraPref = (int) $pdo->query("SELECT COUNT(*) FROM notificacao_preferencias WHERE user_id IN ($UA, $UB)")->fetchColumn();
eq(0, $sobraPref, 'nenhuma preferência de teste ficou no banco');

printf("\n%s  %d PASS, %d FAIL\n", $FAILS === 0 ? 'OK' : 'FALHOU', $PASSES, $FAILS);
exit($FAILS === 0 ? 0 : 1);
