<?php
/**
 * scripts/manutencao/vincular_pessoa.php: põe a mesma pessoa em outra conta
 * (App\Usuarios\PessoaVinculada, migration 169). Pedido de 29/10/2026: o Kauê,
 * vendedor da Inovaize, passa a vender também para a Fleetiflow, com o mesmo
 * login.
 *
 * Cria o usuário da pessoa na conta de destino (se ela ainda não tem um lá),
 * com o mesmo nome e a mesma senha, no nível pedido (vendedor por padrão), com as
 * permissões por página copiadas de --permissoes-de (um usuário da conta de
 * destino) ou da origem, e liga os dois como a mesma pessoa. O login de verdade
 * continua o da origem: o do usuário novo é só um apelido interno (o e-mail com
 * "+conta"), porque o login é único no sistema.
 *
 * Só confere por padrão; --aplicar grava.
 *
 * USO
 *   php scripts/manutencao/vincular_pessoa.php --de=128 --conta=116 --permissoes-de=115
 *   php scripts/manutencao/vincular_pessoa.php --de=128 --conta=116 --permissoes-de=115 --aplicar
 *   php scripts/manutencao/vincular_pessoa.php --de=128 --usuario=140 --aplicar   (liga a um usuário que já existe)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Usuarios\PessoaVinculada;

$o = getopt('', ['de:', 'conta:', 'usuario:', 'nivel:', 'permissoes-de:', 'aplicar']);
$de = (int) ($o['de'] ?? 0);
$aplicar = isset($o['aplicar']);
$pdo = \App\Core\Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$st = $pdo->prepare('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL');
$st->execute([$de]);
$origem = $st->fetch(PDO::FETCH_ASSOC);
if (!$origem) exit("Usuário de origem --de=$de não existe.\n");
echo "origem: #{$origem['id']} {$origem['nome']} <{$origem['login']}>, conta {$origem['account_id']}, nível {$origem['role']}\n";

// Ligar a um usuário que já existe.
if (!empty($o['usuario'])) {
    $st->execute([(int) $o['usuario']]);
    $outro = $st->fetch(PDO::FETCH_ASSOC);
    if (!$outro) exit("Usuário --usuario={$o['usuario']} não existe.\n");
    if ((int) $outro['account_id'] === (int) $origem['account_id']) exit("Os dois são da mesma conta.\n");
    echo "vai: ligar a #{$outro['id']} {$outro['nome']} (conta {$outro['account_id']}) como a mesma pessoa\n";
    if ($aplicar) { PessoaVinculada::vincular($de, (int) $outro['id']); echo "Ligados.\n"; } else echo "Só conferido. Rode com --aplicar para gravar.\n";
    exit;
}

$conta = (int) ($o['conta'] ?? 0);
$sc = $pdo->prepare('SELECT id, nome, status FROM accounts WHERE id = ? AND deleted_at IS NULL');
$sc->execute([$conta]);
$acc = $sc->fetch(PDO::FETCH_ASSOC);
if (!$acc) exit("Conta --conta=$conta não existe.\n");
if ($conta === (int) $origem['account_id']) exit("A origem já é dessa conta.\n");
$ja = PessoaVinculada::usuarioNaConta($de, $conta);
if ($ja) exit("A pessoa já tem o usuário #$ja na conta $conta.\n");

$nivel = (string) ($o['nivel'] ?? 'user');
if (!in_array($nivel, ['user', 'admin'], true)) exit("--nivel é user (vendedor) ou admin.\n");
$perfil = $nivel === 'admin' ? 'admin' : 'user';

$permDe = (int) ($o['permissoes-de'] ?? $de);
$sp = $pdo->prepare('SELECT page FROM user_permissions WHERE user_id = ?');
$sp->execute([$permDe]);
$paginas = $sp->fetchAll(PDO::FETCH_COLUMN);

[$local, $dominio] = array_pad(explode('@', (string) $origem['login'], 2), 2, 'local');
$slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', (string) $acc['nome'])) ?: 'conta' . $conta;
$login = $local . '+' . $slug . '@' . $dominio;
$sl = $pdo->prepare('SELECT COUNT(*) FROM users WHERE login = ?');
for ($i = 2; ; $i++) { $sl->execute([$login]); if ((int) $sl->fetchColumn() === 0) break; $login = $local . '+' . $slug . $i . '@' . $dominio; }

echo "vai: criar \"{$origem['nome']}\" na conta $conta ({$acc['nome']}), nível $nivel, login interno $login, mesma senha\n";
echo "     permissões copiadas de #$permDe: " . ($paginas ? implode(', ', $paginas) : '(nenhuma)') . "\n";
echo "     e ligar os dois como a mesma pessoa\n";
if (!$aplicar) exit("Só conferido. Rode com --aplicar para gravar.\n");

$pdo->beginTransaction();
$pdo->prepare('INSERT INTO users (account_id, nome, login, telefone, senha_hash, perfil, role, status, created_at, updated_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, "active", NOW(), NOW())')
    ->execute([$conta, $origem['nome'], $login, $origem['telefone'] ?? null, $origem['senha_hash'], $perfil, $nivel]);
$novo = (int) $pdo->lastInsertId();
$ip = $pdo->prepare('INSERT INTO user_permissions (user_id, page, account_id) VALUES (?, ?, ?)');
foreach ($paginas as $p) $ip->execute([$novo, $p, $conta]);
$pdo->commit();
PessoaVinculada::vincular($de, $novo);
\App\Master\Account::audit($conta, 'usuario.vinculado', [
    'user_id' => null, 'entidade' => 'users', 'entidade_id' => $novo, 'detalhes' => ['mesma_pessoa_que' => $de],
]);
echo "Criado o usuário #$novo e ligado a #$de.\n";
