<?php
/**
 * scripts/tests/roleta_test.php — App\WhatsAppAgente\Roleta, a divisão dos leads
 * da prospecção entre os atendentes (Operação › Roleta).
 *
 * Parte 1, sem banco: o sorteio (escolher) com milhares de leads simulados fica no
 * percentual (70/30, 50/50, três pessoas), nunca se afasta mais de um lead da
 * meta em nenhum momento, e não é uma sequência fixa.
 *
 * Parte 2, com banco e a migration 170: validar recusa soma diferente de 100,
 * repetição e usuário de fora; salvar grava e só recomeça a contagem quando a
 * divisão muda; sortear grava card e conversa, não sorteia duas vezes o mesmo
 * lead, não mexe em card fora de "Novos leads" nem em responsável escolhido por
 * pessoa. Usa a conta do robô (AutomacaoSdr::contaDoRobo) com um card de teste
 * e devolve tudo como encontrou.
 *
 * Uso: php scripts/tests/roleta_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\WhatsAppAgente\Roleta as R;

$ok = 0; $falhas = 0;
function ok(string $nome, bool $cond): void {
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  [ok]   $nome\n"; } else { $falhas++; echo "  [FALHA] $nome\n"; }
}

/** Simula $n leads e devolve [contagem, maior desvio da meta em leads, sequência]. */
function simular(array $pesos, int $n): array {
    $rec = array_fill_keys(array_keys($pesos), 0);
    $soma = array_sum($pesos);
    $desvio = 0.0;
    $seq = '';
    for ($i = 1; $i <= $n; $i++) {
        $uid = R::escolher($pesos, $rec);
        $rec[$uid]++;
        $seq .= $uid . ',';
        foreach ($pesos as $u => $p) $desvio = max($desvio, abs($rec[$u] - $p / $soma * $i));
    }
    return [$rec, $desvio, $seq];
}

echo "Parte 1: o sorteio\n";
[$r, $d] = simular([129 => 70, 115 => 30], 1000);
echo "         1000 leads 70/30: Kaue {$r[129]}, Vitória {$r[115]} (maior desvio no caminho: " . round($d, 2) . " lead)\n";
ok('1000 leads 70/30 dão 700/300 (±1)', abs($r[129] - 700) <= 1 && abs($r[115] - 300) <= 1);
ok('em nenhum momento passa de 1 lead da meta', $d <= 1.0 + 1e-9);

[$r, $d] = simular([129 => 70, 115 => 30], 10);
ok('10 leads 70/30 dão 7/3', $r[129] === 7 && $r[115] === 3);
[$r, $d] = simular([129 => 70, 115 => 30], 7);
ok('7 leads 70/30 ficam em 5/2 ou 4/3', in_array([$r[129], $r[115]], [[5, 2], [4, 3]], true));

[$r, $d] = simular([1 => 50, 2 => 50], 1000);
ok('1000 leads 50/50 dão 500/500 (±1)', abs($r[1] - 500) <= 1 && $d <= 1.0 + 1e-9);
[$r, $d] = simular([1 => 50, 2 => 30, 3 => 20], 1000);
// Com três ou mais, no meio do caminho alguém pode ficar até 1,5 lead da meta.
ok('três pessoas 50/30/20 em 1000 leads (±1, nunca 2 leads fora no caminho)', abs($r[1] - 500) <= 1 && abs($r[2] - 300) <= 1 && abs($r[3] - 200) <= 1 && $d < 2);
[$r] = simular([9 => 100], 50);
ok('uma pessoa só recebe tudo', $r[9] === 50);

$seqs = [];
for ($i = 0; $i < 20; $i++) $seqs[simular([129 => 70, 115 => 30], 30)[2]] = true;
ok('a ordem não é fixa (20 rodadas, sequências diferentes)', count($seqs) > 1);

// Mil sorteios independentes do PRIMEIRO lead: ainda é sorteio pelo peso.
$primeiro = [129 => 0, 115 => 0];
for ($i = 0; $i < 1000; $i++) $primeiro[R::escolher([129 => 70, 115 => 30], [])]++;
echo "         1000 primeiros leads: Kaue {$primeiro[129]}, Vitória {$primeiro[115]}\n";
ok('o primeiro lead sai pelo peso (Kaue entre 63% e 77%)', $primeiro[129] >= 630 && $primeiro[129] <= 770);

ok('ninguém com peso: null', R::escolher([], []) === null && R::escolher([5 => 0], []) === null);
ok('o sorteio injetado manda (rand=1 escolhe o primeiro)', R::escolher([7 => 70, 8 => 30], [], fn($m) => 1) === 7);
ok('quem já passou da parte não concorre', R::escolher([7 => 70, 8 => 30], [7 => 7, 8 => 2], fn($m) => 1) === 8);

// ---------------------------------------------------------------------------
echo "\nParte 2: banco\n";
try {
    $pdo = \App\Core\Database::getConnection();
    $pdo->query('SELECT 1 FROM sdr_roleta_sorteios LIMIT 1');
} catch (\Throwable $e) {
    echo "  (pulado: sem banco ou sem a migration 170)\n";
    echo "\n$ok ok, $falhas falha(s)\n";
    exit($falhas ? 1 : 0);
}
$ACC = \App\WhatsAppAgente\AutomacaoSdr::contaDoRobo();
if ($ACC === null) {
    echo "  (pulado: precisa de exatamente uma conta Fleetiflow sem marca própria)\n";
    echo "\n$ok ok, $falhas falha(s)\n";
    exit($falhas ? 1 : 0);
}
$st = $pdo->prepare("SELECT id FROM users WHERE account_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY id LIMIT 3");
$st->execute([$ACC]);
$us = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
if (count($us) < 2) { echo "  (pulado: a conta #$ACC precisa de dois usuários ativos)\n"; echo "\n$ok ok, $falhas falha(s)\n"; exit($falhas ? 1 : 0); }
[$A, $B] = $us;
echo "conta do robô: #$ACC, usuários #$A e #$B\n";

$st = $pdo->prepare('SELECT config_value FROM whatsapp_settings WHERE account_id = ? AND config_key = ?');
$st->execute([$ACC, R::CHAVE]);
$antes = $st->fetchColumn();
$colunas = \App\WhatsAppAgente\SdrFleetiflow::colunasDaConta($pdo, $ACC);
$cards = [];
$jids = [];
$fones = [];
$novoCard = function (?int $resp, ?int $coluna = null) use ($ACC, $colunas, &$cards): int {
    $id = (int) \App\Prospeccao\Card::create([
        'account_id' => $ACC, 'responsavel_user_id' => $resp, 'titulo' => 'Teste roleta', 'cliente_nome' => 'Teste roleta',
        'telefone_whatsapp' => '5511900' . random_int(100000, 999999), 'coluna_id' => $coluna ?? $colunas['novo'],
        'ordem_na_coluna' => 0, 'status' => 'aberto', 'descricao' => 'roleta_test', '_usuario_id' => null,
    ]);
    $cards[] = $id;
    return $id;
};
$resp = function (int $id) use ($pdo): ?int { $s = $pdo->prepare('SELECT responsavel_user_id FROM cards WHERE id = ?'); $s->execute([$id]); $v = $s->fetchColumn(); return $v === null ? null : (int) $v; };

try {
    ok('soma diferente de 100 é recusada', str_contains((string) R::validar($ACC, [['user_id' => $A, 'percentual' => 70], ['user_id' => $B, 'percentual' => 20]]), 'somam 90%'));
    ok('a mesma pessoa duas vezes é recusada', R::validar($ACC, [['user_id' => $A, 'percentual' => 50], ['user_id' => $A, 'percentual' => 50]]) !== null);
    ok('usuário que não é da conta é recusado', R::validar($ACC, [['user_id' => 999999999, 'percentual' => 100]]) !== null);
    ok('percentual quebrado é recusado', R::validar($ACC, [['user_id' => $A, 'percentual' => 70.5], ['user_id' => $B, 'percentual' => 29.5]]) !== null);
    ok('ninguém é recusado', R::validar($ACC, []) !== null);

    $div = [['user_id' => $A, 'percentual' => 70], ['user_id' => $B, 'percentual' => 30]];
    $soDivisao = fn(array $ps) => array_map(fn($p) => ['user_id' => $p['user_id'], 'percentual' => $p['percentual']], $ps);
    ok('salva 70/30 desligada', R::salvar($ACC, $div, false) === null && R::config($ACC)['ligada'] === false && $soDivisao(R::config($ACC)['participantes']) === $div);
    $c1 = $novoCard(null);
    ok('desligada não sorteia', isset(R::sortear($ACC, $c1)['pulou']) && $resp($c1) === null);

    $desde = R::config($ACC)['desde'];
    sleep(1);
    ok('ligar sem mudar a divisão não recomeça a contagem', R::salvar($ACC, $div, true) === null && R::config($ACC)['desde'] === $desde);
    $s = R::sortear($ACC, $c1, null, null, 'teste');
    ok('ligada sorteia e grava no card', !empty($s['novo']) && in_array($s['user_id'], [$A, $B], true) && $resp($c1) === $s['user_id']);
    $s2 = R::sortear($ACC, $c1);
    ok('o mesmo lead não é sorteado de novo', empty($s2['novo']) && $s2['user_id'] === $s['user_id']);

    // Responsável que não é o automático (dono do número) foi escolhido por pessoa.
    $auto = \App\WhatsAppAgente\SdrFleetiflow::donoDoNumero($pdo, $ACC, null);
    $humano = $auto === $A ? $B : $A;
    $c2 = $novoCard($humano);
    ok('card com responsável escolhido por pessoa não muda', isset(R::sortear($ACC, $c2)['pulou']) && $resp($c2) === $humano);
    $foraNovo = array_values(array_filter($colunas, fn($c) => $c !== $colunas['novo']))[0] ?? null;
    if ($foraNovo) ok('card fora de Novos leads não muda', isset(R::sortear($ACC, $novoCard(null, (int) $foraNovo))['pulou']));

    // A conversa: existe na hora do sorteio, ou nasce depois e é ligada ao card.
    $st = $pdo->prepare('SELECT id FROM whatsapp_instances WHERE account_id = ? ORDER BY id LIMIT 1');
    $st->execute([$ACC]);
    $inst = (int) ($st->fetchColumn() ?: 0);
    if ($inst) {
        $dono = function (string $jid) use ($pdo, $inst): ?int { $s = $pdo->prepare('SELECT linked_user_id FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ?'); $s->execute([$inst, $jid]); $v = $s->fetchColumn(); return $v === null || $v === false ? null : (int) $v; };
        $jids[] = $j1 = '5511900' . random_int(100000, 999999) . '@s.whatsapp.net';
        $pdo->prepare('INSERT INTO whatsapp_chats (instance_id, remote_jid, linked_user_id) VALUES (?, ?, NULL)')->execute([$inst, $j1]);
        $c3 = $novoCard(null);
        $s3 = R::sortear($ACC, $c3, $inst, $j1);
        ok('a conversa existente passa a ser do sorteado', !empty($s3['novo']) && $dono($j1) === $s3['user_id']);

        $jids[] = $j2 = '5511900' . random_int(100000, 999999) . '@s.whatsapp.net';
        $pdo->prepare('INSERT INTO whatsapp_chats (instance_id, remote_jid, linked_user_id) VALUES (?, ?, ?)')->execute([$inst, $j2, $humano]);
        $c4 = $novoCard(null);
        $s4 = R::sortear($ACC, $c4, $inst, $j2);
        ok('conversa que uma pessoa já pegou não muda de dono', !empty($s4['novo']) && $dono($j2) === $humano);

        $jids[] = $j3 = '5511900' . random_int(100000, 999999) . '@s.whatsapp.net';
        $c5 = $novoCard(null);
        $s5 = R::sortear($ACC, $c5, $inst, $j3); // conversa ainda não existe
        $pdo->prepare('INSERT INTO whatsapp_chats (instance_id, remote_jid, linked_user_id) VALUES (?, ?, NULL)')->execute([$inst, $j3]);
        R::aoLigarConversa($ACC, $c5, $inst, $j3);
        ok('conversa que nasce depois do sorteio vai para o sorteado', $dono($j3) === $s5['user_id']);
    } else {
        echo "  (conversa pulada: a conta #$ACC não tem número)\n";
    }

    // Sorteio ANTES do envio (reservar, migration 171): a abertura sai com o nome do sorteado.
    $temTelefone = (bool) $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sdr_roleta_sorteios' AND column_name = 'telefone'")->fetchColumn();
    if ($temTelefone) {
        $primeiro = function (int $uid) use ($pdo): string { $s = $pdo->prepare('SELECT nome FROM users WHERE id = ?'); $s->execute([$uid]); return preg_split('/\s+/', trim((string) $s->fetchColumn()))[0]; };
        $fone = function () use (&$fones): string { return $fones[] = '5511977' . random_int(100000, 999999); };
        $cardFone = function (string $f, ?int $resp) use ($ACC, $colunas, &$cards): int {
            $id = (int) \App\Prospeccao\Card::create(['account_id' => $ACC, 'responsavel_user_id' => $resp, 'titulo' => 'Teste roleta', 'cliente_nome' => 'Teste roleta',
                'telefone_whatsapp' => $f, 'coluna_id' => $colunas['novo'], 'ordem_na_coluna' => 0, 'status' => 'aberto', 'descricao' => 'roleta_test', '_usuario_id' => null]);
            $cards[] = $id;
            return $id;
        };
        sleep(1);
        R::salvar($ACC, [['user_id' => $A, 'percentual' => 70], ['user_id' => $B, 'percentual' => 30]], true);
        $desde7030 = R::config($ACC)['desde'];
        sleep(1);
        R::salvar($ACC, [['user_id' => $B, 'percentual' => 30], ['user_id' => $A, 'percentual' => 70]], true);
        ok('mudar só a ordem das pessoas não recomeça a contagem', R::config($ACC)['desde'] === $desde7030);
        ok('o nome é o primeiro nome do cadastro', R::assinatura($ACC, $A)['vendedor'] === $primeiro($A) && R::assinatura($ACC, $B)['vendedor'] === $primeiro($B));

        // Vendedor da vez: sorteado antes da mensagem, guardado, consumido pela abertura.
        $vez = R::daVez($ACC);
        ok('o vendedor da vez já está sorteado e não muda ao olhar de novo', $vez && in_array($vez['user_id'], [$A, $B], true) && R::daVez($ACC)['user_id'] === $vez['user_id'] && R::daVez($ACC)['user_id'] === $vez['user_id']);
        $f1 = $fone();
        $r1 = R::reservar($ACC, $f1, null, 'planilha');
        ok('a abertura nova vai para o vendedor da vez, com o nome do cadastro', !empty($r1['novo']) && $r1['user_id'] === $vez['user_id'] && $r1['vendedor'] === $primeiro($vez['user_id']));
        $proximo = R::daVez($ACC);
        ok('depois da abertura, o seguinte já está sorteado', $proximo !== null && in_array($proximo['user_id'], [$A, $B], true));
        $r1b = R::reservar($ACC, $f1, null, 'planilha');
        ok('perguntar de novo pelo mesmo telefone devolve o mesmo atendente (e não gasta a vez)', empty($r1b['novo']) && $r1b['user_id'] === $r1['user_id'] && R::daVez($ACC)['user_id'] === $proximo['user_id']);
        $c7 = $cardFone($f1, null); // o card nasce depois do envio
        $s7 = R::sortear($ACC, $c7, null, null, 'planilha');
        ok('o card que nasce depois fica com quem assinou a abertura', $s7['user_id'] === $r1['user_id'] && empty($s7['novo']) && $resp($c7) === $r1['user_id']);
        $n = $pdo->prepare('SELECT COUNT(*), MAX(card_id) FROM sdr_roleta_sorteios WHERE account_id = ? AND telefone = ?');
        $n->execute([$ACC, $f1]);
        [$qtd, $ligado] = $n->fetch(\PDO::FETCH_NUM);
        ok('um sorteio só, ligado ao card', (int) $qtd === 1 && (int) $ligado === $c7);

        $f2 = $fone();
        $c8 = $cardFone($f2, null); // listas: o card já existe
        $r8 = R::reservar($ACC, $f2, $c8, 'listas');
        ok('com o card já existente, o sorteado já vai para o card', !empty($r8['novo']) && $resp($c8) === $r8['user_id']);
        $f3 = $fone();
        $c9 = $cardFone($f3, $humano);
        $r9 = R::reservar($ACC, $f3, $c9, 'listas');
        ok('card que uma pessoa já pegou: assina quem atende, sem sortear', $r9['user_id'] === $humano && empty($r9['novo']) && $resp($c9) === $humano);

        if (count($us) >= 3) {
            $C = $us[2];
            sleep(1); // a divisão nova começa a contar no segundo seguinte
            R::salvar($ACC, [['user_id' => $A, 'percentual' => 50], ['user_id' => $B, 'percentual' => 30], ['user_id' => $C, 'percentual' => 20]], true);
            $conta3 = [$A => 0, $B => 0, $C => 0];
            $assin = [];
            $bateu = true;
            for ($i = 0; $i < 30; $i++) {
                $vez = R::daVez($ACC);
                $r = R::reservar($ACC, $fone(), null, 'planilha');
                $bateu = $bateu && $vez['user_id'] === $r['user_id'];
                $conta3[$r['user_id']]++; $assin[$r['user_id']] = $r['vendedor'];
            }
            ok('em 30 aberturas, cada uma foi para quem a tela mostrava como vendedor da vez', $bateu);
            echo "         30 aberturas 50/30/20: " . json_encode($conta3) . ' assinaturas: ' . json_encode($assin, JSON_UNESCAPED_UNICODE) . "\n";
            ok('terceiro atendente entra na roleta e a divisão fica no percentual (15/9/6, menos de 2 de diferença)', abs($conta3[$A] - 15) < 2 && abs($conta3[$B] - 9) < 2 && abs($conta3[$C] - 6) < 2);
            ok('cada um assina com o primeiro nome do cadastro', ($assin[$A] ?? '') === $primeiro($A) && ($assin[$B] ?? '') === $primeiro($B) && ($assin[$C] ?? '') === $primeiro($C));
        } else {
            echo "  (terceiro atendente pulado: a conta #$ACC tem só dois usuários ativos)\n";
        }
    } else {
        echo "  (sorteio antes do envio pulado: falta a migration 171)\n";
    }

    sleep(1);
    ok('mudar a divisão recomeça a contagem', R::salvar($ACC, [['user_id' => $A, 'percentual' => 50], ['user_id' => $B, 'percentual' => 50]], true) === null && R::config($ACC)['desde'] !== $desde);
    $cont = R::contagem($ACC);
    ok('a contagem traz os dois com o total', count($cont) >= 2 && array_sum(array_column($cont, 'total')) >= 1);
} finally {
    if ($cards) {
        $in = implode(',', array_map('intval', $cards));
        $pdo->exec("DELETE FROM sdr_roleta_sorteios WHERE card_id IN ($in)");
        // card_history é imutável (gatilho LGPD): o card de teste sai por exclusão lógica.
        $pdo->exec("UPDATE cards SET deleted_at = NOW() WHERE id IN ($in)");
    }
    foreach ($fones as $f) $pdo->prepare('DELETE FROM sdr_roleta_sorteios WHERE account_id = ? AND telefone = ?')->execute([$ACC, $f]);
    foreach ($jids as $j) $pdo->prepare('DELETE FROM whatsapp_chats WHERE remote_jid = ?')->execute([$j]);
    $pdo->prepare('DELETE FROM whatsapp_settings WHERE account_id = ? AND config_key = ?')->execute([$ACC, R::CHAVE]);
    if ($antes !== false) $pdo->prepare('INSERT INTO whatsapp_settings (account_id, config_key, config_value, updated_at) VALUES (?, ?, ?, NOW())')->execute([$ACC, R::CHAVE, $antes]);
}

echo "\n$ok ok, $falhas falha(s)\n";
exit($falhas ? 1 : 0);
