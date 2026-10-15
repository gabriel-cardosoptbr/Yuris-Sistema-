<?php
/**
 * scripts/tests/evolution_painel_test.php — App\Projetos\Evolution (aba
 * Evolution da edição CRM, 15/10/2026).
 *
 * Confere, sem banco nem Evolution: a limpeza do fetchInstances (token
 * descartado, item sem nome ignorado, situação desconhecida vira close, número
 * do JID, datas em Brasília), o rateio do custo só entre as conexões que ocupam
 * o servidor (soma exata), a montagem (vínculo gravado vence a sugestão, projeto
 * apagado é ignorado, totais por projeto e por situação, custo por conexão), a
 * série diária (volume = diferença entre fotos, primeiro dia desconhecido,
 * instância recriada não dá negativo) e a leitura da configuração da conta.
 * Com banco e a migration 148: a foto do dia não duplica e o histórico sai dela.
 *
 * Uso: php scripts/tests/evolution_painel_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Projetos\Evolution as E;

$ok = 0; $falhas = 0;
function ok(string $nome, bool $cond): void {
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  [ok]   $nome\n"; } else { $falhas++; echo "  [FALHA] $nome\n"; }
}

echo "== 1. Limpeza do fetchInstances ==\n";
$cru = [
    ['name' => 'ss2', 'connectionStatus' => 'open', 'ownerJid' => '5511944445555@s.whatsapp.net', 'profileName' => 'Schumaher', 'token' => 'SEGREDO-1',
     '_count' => ['Message' => 78035, 'Contact' => 3009, 'Chat' => 4486], 'createdAt' => '2026-09-17T17:07:02.887Z', 'updatedAt' => '2026-10-15T08:09:57.137Z'],
    ['name' => 'presa', 'connectionStatus' => 'connecting', 'token' => 'SEGREDO-2', '_count' => ['Message' => 10], 'disconnectionReasonCode' => 401, 'disconnectionAt' => '2026-10-01T10:00:00Z'],
    ['name' => 'fechada', 'connectionStatus' => 'close', '_count' => null],
    ['name' => 'estranha', 'connectionStatus' => 'qualquer'],
    ['connectionStatus' => 'open'],
    'lixo',
];
$n = E::normalizar($cru);
ok('4 instâncias (sem nome e lixo ignorados)', count($n) === 4);
ok('o token nunca sai', !str_contains(json_encode($n), 'SEGREDO'));
ok('ordem: conectadas, reconectando, desconectadas', array_column($n, 'status') === ['open', 'connecting', 'close', 'close']);
ok('número tirado do JID', $n[0]['numero'] === '5511944445555' && $n[1]['numero'] === null);
ok('contagens lidas; ausentes viram 0', $n[0]['mensagens'] === 78035 && $n[0]['conversas'] === 4486 && $n[2]['mensagens'] === 0);
ok('situação desconhecida vira close', $n[3]['nome'] === 'estranha' || $n[2]['nome'] === 'estranha');
ok('data em Brasília (UTC-3)', $n[0]['criada_em'] === '2026-09-17 14:07:02');
ok('motivo e hora da desconexão', $n[1]['motivo_desconexao'] === 401 && $n[1]['desconectada_em'] === '2026-10-01 07:00:00');

echo "\n== 2. Rateio do custo ==\n";
$r = E::rateio(10000, $n);
ok('R$ 100 entre as 2 que ocupam o servidor (open e connecting)', $r['ss2'] === 5000 && $r['presa'] === 5000 && $r['fechada'] === 0);
$r3 = E::rateio(10000, [['nome' => 'a', 'status' => 'open'], ['nome' => 'b', 'status' => 'open'], ['nome' => 'c', 'status' => 'connecting']]);
ok('soma exata em 3 (33,34 + 33,33 + 33,33)', array_sum($r3) === 10000 && $r3['a'] === 3334);
ok('sem custo: tudo zero', array_sum(E::rateio(null, $n)) === 0);

echo "\n== 3. Montagem ==\n";
$m = E::montar($n, ['ss2' => 7, 'fechada' => 99], ['ss2' => 8, 'presa' => 8], [7 => 'Schumaher', 8 => 'Yuris'], 10000, ['ss2' => 78000]);
$porNome = array_column($m['instancias'], null, 'nome');
ok('vínculo gravado vence a sugestão', $porNome['ss2']['projeto_id'] === 7 && $porNome['ss2']['projeto_auto'] === false);
ok('sem vínculo, usa a sugestão (marcada como sugerida)', $porNome['presa']['projeto_id'] === 8 && $porNome['presa']['projeto_auto'] === true);
ok('vínculo com projeto apagado é ignorado', $porNome['fechada']['projeto_id'] === null);
ok('volume desde a última foto', $porNome['ss2']['mensagens_desde_ultima_foto'] === 35 && $porNome['presa']['mensagens_desde_ultima_foto'] === null);
$res = $m['resumo'];
ok('resumo por situação', $res['total'] === 4 && $res['conectadas'] === 1 && $res['reconectando'] === 1 && $res['desconectadas'] === 2 && $res['ocupam'] === 2);
ok('custo por conexão no servidor e por conectada', $res['custo_por_conexao'] === 50.0 && $res['custo_por_conectada'] === 100.0);
$pp = array_column($m['por_projeto'], null, 'nome');
ok('por projeto: Schumaher, Yuris e Sem projeto', isset($pp['Schumaher'], $pp['Yuris'], $pp['Sem projeto']) && $pp['Sem projeto']['instancias'] === 2);
ok('custo por projeto soma o das instâncias', $pp['Schumaher']['custo'] === 50.0 && $pp['Sem projeto']['custo'] === 0.0);

echo "\n== 4. Série diária ==\n";
$s = E::serie([
    ['dia' => '2026-10-13', 'instancia' => 'a', 'status' => 'open', 'mensagens' => 100],
    ['dia' => '2026-10-13', 'instancia' => 'b', 'status' => 'open', 'mensagens' => 50],
    ['dia' => '2026-10-14', 'instancia' => 'a', 'status' => 'open', 'mensagens' => 130],
    ['dia' => '2026-10-14', 'instancia' => 'b', 'status' => 'connecting', 'mensagens' => 10],
    ['dia' => '2026-10-15', 'instancia' => 'a', 'status' => 'open', 'mensagens' => 150],
], '2026-10-15');
ok('primeiro dia sem comparação: volume desconhecido', $s['serie'][0]['mensagens'] === null && $s['desde'] === '2026-10-13');
ok('volume = diferença (30 de a; b recriada não dá negativo)', $s['serie'][1]['mensagens'] === 30);
ok('hoje: +20', $s['serie'][2]['mensagens'] === 20 && $s['serie'][2]['instancias'] === 1);
ok('conectadas por dia', $s['serie'][1]['conectadas'] === 1);
ok('última foto antes de hoje', $s['anterior'] === ['a' => 130, 'b' => 10]);

echo "\n== 5. Configuração da conta ==\n";
$cfg = E::config(['configuracoes' => json_encode(['evolution' => ['custo_mensal_centavos' => 12000, 'vinculos' => ['ss2' => 7, 'x' => 0]]])]);
ok('custo e vínculos lidos (vínculo zero descartado)', $cfg['custo'] === 12000 && $cfg['vinculos'] === ['ss2' => 7]);
ok('conta sem configuração', E::config([]) === ['custo' => null, 'vinculos' => []]);

echo "\n== 6. Banco: foto do dia ==\n";
// Database::getConnection() encerra o script quando o banco está fora: confere antes.
$tem = 0; $pdo = null;
// No servidor (Docker, produção) a parte com banco só roda com TESTE_NO_SERVIDOR=1:
// ela grava fotos de teste (apagadas no fim), e produção não é lugar de rastro.
if (file_exists('/.dockerenv') && getenv('TESTE_NO_SERVIDOR') !== '1') {
    echo "  [pulado] no servidor (produção) a parte com banco não roda\n";
    echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
    exit($falhas > 0 ? 1 : 0);
}
$sock = @fsockopen(\App\Core\EnvLoader::get('DB_HOST', '127.0.0.1'), (int) \App\Core\EnvLoader::get('DB_PORT', '3306'), $en, $es, 2);
if (!$sock) {
    echo "  (pulado: banco fora do ar)\n";
} else {
    fclose($sock);
    $pdo = \App\Core\Database::getConnection();
    $tem = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'evolution_snapshots'")->fetchColumn();
    if (!$tem) echo "  (pulado: rode a migration 148)\n";
}
if ($tem) {
    $acc = -987654; // conta que não existe: não esbarra em dado real
    try {
        E::gravarFoto($pdo, $acc, [['nome' => 'teste', 'status' => 'open', 'mensagens' => 10, 'contatos' => 1, 'conversas' => 1]], '2026-10-14');
        E::gravarFoto($pdo, $acc, [['nome' => 'teste', 'status' => 'open', 'mensagens' => 15, 'contatos' => 1, 'conversas' => 1]], '2026-10-14');
        E::gravarFoto($pdo, $acc, [['nome' => 'teste', 'status' => 'open', 'mensagens' => 40, 'contatos' => 2, 'conversas' => 2]], '2026-10-15');
        $qtd = (int) $pdo->query("SELECT COUNT(*) FROM evolution_snapshots WHERE account_id = $acc")->fetchColumn();
        ok('mesma instância no mesmo dia atualiza, não duplica', $qtd === 2);
        $h = E::historico($pdo, $acc, '2026-10-15');
        ok('histórico do banco: +25 no dia 15', count($h['serie']) === 2 && $h['serie'][1]['mensagens'] === 25 && $h['anterior'] === ['teste' => 15]);
    } finally {
        $pdo->exec("DELETE FROM evolution_snapshots WHERE account_id = $acc");
    }
}

echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
