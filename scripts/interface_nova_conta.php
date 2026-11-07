<?php
/**
 * interface_nova_conta.php: liga ou desliga a interface nova (prévia) numa CONTA.
 *
 * A conta ligada vê a interface nova em qualquer navegador, sem ?novo=1. É como o
 * dono do produto pediu em 07/11/2026: "sobe o layout completo do sistema inteiro
 * dentro da minha conta". Ver App\Inicio\InterfaceNova.
 *
 * Uso:
 *   php scripts/interface_nova_conta.php --procurar=Gabriel      quem é, em que conta
 *   php scripts/interface_nova_conta.php --conta=1 --ligar
 *   php scripts/interface_nova_conta.php --conta=1 --desligar
 *   php scripts/interface_nova_conta.php --listar                 contas ligadas
 *
 * Em produção: docker exec yuris_app php /var/www/html/scripts/interface_nova_conta.php ...
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Database;
use App\Inicio\InterfaceNova;

$opt = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $a, $m)) $opt[$m[1]] = $m[2] ?? '1';
}
$pdo = Database::getConnection();

if (isset($opt['procurar'])) {
    $st = $pdo->prepare('SELECT u.id, u.nome, u.login, u.role, u.account_id, a.nome AS conta, a.status, a.tipo, a.configuracoes
                           FROM users u JOIN accounts a ON a.id = u.account_id
                          WHERE u.deleted_at IS NULL AND (u.nome LIKE ? OR u.login LIKE ?) ORDER BY u.account_id, u.id');
    $st->execute(['%' . $opt['procurar'] . '%', '%' . $opt['procurar'] . '%']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        printf("usuário %d  %-32s %-36s %-7s conta %d (%s, %s, %s)%s\n", $r['id'], $r['nome'], $r['login'], $r['role'], $r['account_id'],
            $r['conta'], $r['tipo'], $r['status'], InterfaceNova::contaLigadaPelaLinha($r) ? '  [interface nova LIGADA]' : '');
    }
    exit(0);
}

if (isset($opt['listar'])) {
    $n = 0;
    foreach ($pdo->query('SELECT id, nome, configuracoes FROM accounts WHERE deleted_at IS NULL ORDER BY id') as $a) {
        if (InterfaceNova::contaLigadaPelaLinha($a)) { printf("conta %d  %s\n", $a['id'], $a['nome']); $n++; }
    }
    echo $n ? "$n conta(s) com a interface nova.\n" : "Nenhuma conta com a interface nova ligada.\n";
    exit(0);
}

$conta = (int) ($opt['conta'] ?? 0);
if ($conta <= 0 || (!isset($opt['ligar']) && !isset($opt['desligar']))) {
    fwrite(STDERR, "Uso: --procurar=NOME | --listar | --conta=ID --ligar | --conta=ID --desligar\n");
    exit(1);
}
$st = $pdo->prepare('SELECT id, nome, configuracoes FROM accounts WHERE id = ? AND deleted_at IS NULL');
$st->execute([$conta]);
$linha = $st->fetch(PDO::FETCH_ASSOC);
if (!$linha) { fwrite(STDERR, "conta $conta não existe\n"); exit(1); }
if (\App\Master\Account::getProduto($linha) !== 'yuris') { fwrite(STDERR, "conta $conta é de marca (edição CRM): a interface nova não se aplica\n"); exit(1); }

InterfaceNova::ligarNaConta($pdo, $conta, isset($opt['ligar']));
printf("conta %d (%s): interface nova %s\n", $conta, $linha['nome'], isset($opt['ligar']) ? 'LIGADA' : 'desligada');
