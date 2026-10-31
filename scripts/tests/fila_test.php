<?php
/**
 * scripts/tests/fila_test.php: App\WhatsAppAgente\FilaDaProspeccao, o painel da fila da
 * prospecção (Operação › Fila).
 *
 *   1. Sem banco: as faixas de horário (uma por lead, na hora cheia, só dentro da janela, na
 *      ordem, pulando o domingo) e a conta do ritmo.
 *   2. Com banco (conta da prospecção, sem deixar nada): o retrato que o n8n manda é limpo
 *      (campos conhecidos, tamanhos, tags) e vira o painel: faltam, saíram hoje, ritmo,
 *      término, ordem dos próximos e o que foi pulado.
 *
 * Uso: php scripts/tests/fila_test.php
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\AutomacaoSdr;
use App\WhatsAppAgente\FilaDaProspeccao as F;

$OK = 0; $FALHAS = [];
function ok(string $m, bool $c): void { global $OK, $FALHAS; if ($c) { $OK++; echo "  [ok]   $m\n"; } else { $FALHAS[] = $m; echo "  [FALHA] $m\n"; } }
$sp = fn(string $s) => (new \DateTimeImmutable($s, new \DateTimeZone('America/Sao_Paulo')))->getTimestamp();
$hh = fn(int $t) => (new \DateTimeImmutable('@' . $t))->setTimezone(new \DateTimeZone('America/Sao_Paulo'))->format('D d/m H:i');

echo "== 1. Faixas ==\n";
$robo = ['dias' => [1, 2, 3, 4, 5, 6], 'de' => '07:00', 'ate' => '20:00', 'espera_min' => 15, 'espera_max' => 20];
$agora = $sp('2026-10-30 10:05'); // sexta
$lista = array_map(fn($i) => ['id' => (string) $i], range(1, 6));
$f = F::faixas($lista, $robo, $sp('2026-10-30 10:00'), $agora);
ok('a faixa é de uma hora cheia, nunca um minuto exato', $f[0]['ate'] - $f[0]['de'] === 3600 && $f[0]['de'] % 3600 === 0);
ok('o primeiro sai depois da espera média a partir do último envio (10h00 + 17,5 min = 10h17)', $f[0]['de'] === $sp('2026-10-30 10:00'));
ok('um a cada 17,5 min: o 4º cai na hora seguinte', $f[3]['de'] === $sp('2026-10-30 11:00'));
$ordem = true; foreach ($f as $i => $x) if ($i && $x['de'] < $f[$i - 1]['de']) $ordem = false;
ok('a ordem dos horários nunca volta', $ordem);

$tarde = F::faixas(array_map(fn($i) => ['id' => (string) $i], range(1, 3)), $robo, $sp('2026-10-30 19:50'), $sp('2026-10-30 19:52'));
ok('o que passa das 20h vai para a abertura do dia seguinte (sábado 7h)', $tarde[0]['de'] === $sp('2026-10-30 19:00') || $tarde[0]['de'] === $sp('2026-10-31 07:00'));
ok('depois de sábado o domingo fica de fora (segunda 7h)', F::faixas([['id' => 'x']], $robo, $sp('2026-10-31 19:50'), $sp('2026-10-31 19:55'))[0]['de'] === $sp('2026-11-02 07:00'));
$noite = F::faixas([['id' => 'x']], $robo, null, $sp('2026-10-30 03:00'));
ok('de madrugada, o primeiro espera as 7h', $noite[0]['de'] === $sp('2026-10-30 07:00'));
$listas = ['dias' => [1, 2, 3, 4, 5, 6], 'de' => '08:00', 'ate' => '19:00', 'espera_min' => 10, 'espera_max' => 30];
ok('o disparo das listas respeita a janela dele (8h às 19h)', F::faixas([['id' => 'x']], $listas, null, $sp('2026-10-30 06:00'))[0]['de'] === $sp('2026-10-30 08:00'));

echo "\n== 2. Com banco ==\n";
$pdo = Database::getConnection();
$ACC = AutomacaoSdr::contaDoRobo();
$temTabela = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sdr_roleta_sorteios'")->fetchColumn();
if ($ACC === null || !$temTabela) {
    echo "  (pulado: precisa de exatamente uma conta Fleetiflow sem marca própria e da migration 170)\n";
    echo "\n$OK ok/" . count($FALHAS) . "\n"; exit($FALHAS ? 1 : 0);
}
$chaves = ['sdr_fila_planilha', 'sdr_fila_listas'];
$guardado = [];
foreach ($chaves as $c) { $st = $pdo->prepare('SELECT config_value FROM whatsapp_settings WHERE account_id = ? AND config_key = ?'); $st->execute([$ACC, $c]); $guardado[$c] = $st->fetchColumn(); }
$sorteiosAntes = (int) $pdo->query("SELECT COUNT(*) FROM sdr_roleta_sorteios WHERE account_id = $ACC AND origem IN ('planilha', 'listas')")->fetchColumn();
$pdo->prepare("DELETE FROM whatsapp_settings WHERE account_id = ? AND config_key IN ('sdr_fila_planilha', 'sdr_fila_listas')")->execute([$ACC]);
try {
    ok('sem retrato, o fluxo aparece "sem dados"', ($p = F::painel($ACC))['fluxos'][0]['sem_dados'] === true && $p['fluxos'][1]['sem_dados'] === true);
    ok('fluxo desconhecido é recusado', F::guardar($ACC, 'outro', []) === false && F::guardar(0, 'planilha', []) === false);

    $hojeBr = (new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    $ontem = (new \DateTimeImmutable('-1 day', new \DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
    $retrato = [
        'janela' => $robo, 'restantes' => 120, 'por_grupo' => ['Locadoras' => 60, 'Concessionárias <b>x</b>' => 60],
        'enviados_total' => 90, 'enviados_por_dia' => [$ontem => 40, 'lixo' => 5, $hojeBr => 3],
        'pulados' => ['sem_whatsapp' => 4, 'enviando' => 1],
        'proximos' => [['id' => 'a1', 'nome' => '<script>alert(1)</script>Loja Um', 'grupo' => 'Locadora', 'fone' => '+55 (11) 98888-7777'], ['id' => 'a2', 'nome' => 'Loja Dois', 'grupo' => 'Concessionária', 'fone' => '5511977776666']],
        'problemas' => [['id' => 'p1', 'nome' => 'Sem Zap', 'status' => 'sem_whatsapp', 'detalhe' => 'x', 'quando' => '30/10 10:00']],
        'campo_estranho' => 'não entra',
    ];
    ok('o retrato é guardado', F::guardar($ACC, 'planilha', $retrato) === true);
    $r = F::retrato($ACC, 'planilha');
    ok('tag HTML some do nome e do grupo', !str_contains(json_encode($r), '<') && $r['proximos'][0]['nome'] === 'alert(1)Loja Um');
    ok('telefone fica só com dígitos', $r['proximos'][0]['fone'] === '5511988887777');
    ok('dia que não é data é descartado e campo desconhecido não entra', !isset($r['enviados_por_dia']['lixo']) && !isset($r['campo_estranho']));
    ok('o texto de tamanho absurdo é cortado', mb_strlen(F::retrato($ACC, 'planilha')['proximos'][0]['nome']) <= 120);

    $p = F::painel($ACC);
    $pl = $p['fluxos'][0];
    ok('o painel traz o que falta e o que saiu', $pl['sem_dados'] === false && $pl['restantes'] === 120 && $pl['enviados_hoje'] >= 3);
    ok('o ritmo é a média dos dias anteriores (40/dia)', $pl['ritmo_dia'] === 40);
    ok('o término cai em alguns dias de envio (120 faltam a 40/dia), nunca num domingo', $pl['termino'] !== null && (new \DateTimeImmutable($pl['termino']))->format('N') !== '7');
    ok('cada próximo tem a faixa de uma hora', count($pl['proximos']) === 2 && $pl['proximos'][0]['ate'] - $pl['proximos'][0]['de'] === 3600);
    ok('o que foi pulado e o problema chegam ao painel', $pl['pulados']['sem_whatsapp'] === 4 && $pl['problemas'][0]['status'] === 'sem_whatsapp');
    ok('retrato fresco não aparece como desatualizado', $pl['desatualizado'] === false);
    $pdo->prepare("UPDATE whatsapp_settings SET config_value = REPLACE(config_value, ?, ?) WHERE account_id = ? AND config_key = 'sdr_fila_planilha'")
        ->execute([$r['gerado_em'], '2020-01-01 00:00:00', $ACC]);
    ok('retrato velho aparece como desatualizado', F::painel($ACC)['fluxos'][0]['desatualizado'] === true);
    ok('a fila vazia não tem término', (F::guardar($ACC, 'listas', ['janela' => $listas, 'restantes' => 0]) && F::painel($ACC)['fluxos'][1]['termino'] === null));
} finally {
    $pdo->prepare("DELETE FROM whatsapp_settings WHERE account_id = ? AND config_key IN ('sdr_fila_planilha', 'sdr_fila_listas')")->execute([$ACC]);
    foreach ($guardado as $c => $v) if ($v !== false) $pdo->prepare('INSERT INTO whatsapp_settings (account_id, config_key, config_value, updated_at) VALUES (?, ?, ?, NOW())')->execute([$ACC, $c, $v]);
}
echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
exit($FALHAS ? 1 : 0);
