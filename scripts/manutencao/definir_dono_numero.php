<?php
/**
 * scripts/manutencao/definir_dono_numero.php — define o vendedor dono de um
 * número de WhatsApp (whatsapp_instances.responsavel_user_id, migration 137).
 *
 * O lead que a automação cria por esse número nasce com esse vendedor como
 * responsável (SdrFleetiflow::donoDoNumero), e é o que o painel por vendedor
 * conta para ele.
 *
 * O usuário tem de ser da MESMA conta do número, ativo. Só confere por padrão.
 *
 * USO
 *   php scripts/manutencao/definir_dono_numero.php --instancia=18 --usuario=126            (confere)
 *   php scripts/manutencao/definir_dono_numero.php --instancia=18 --usuario=126 --aplicar  (grava)
 *   php scripts/manutencao/definir_dono_numero.php --instancia=18 --usuario=0 --aplicar    (tira o dono)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

$o = getopt('', ['instancia:', 'usuario:', 'aplicar']);
$inst = (int) ($o['instancia'] ?? 0);
$uid  = (int) ($o['usuario'] ?? -1);
if ($inst <= 0 || $uid < 0) exit("Informe --instancia=ID e --usuario=ID (0 tira o dono).\n");

$pdo = \App\Core\Database::getConnection();
$st = $pdo->prepare('SELECT id, account_id, instance_name, phone, responsavel_user_id FROM whatsapp_instances WHERE id = ?');
$st->execute([$inst]);
$n = $st->fetch(PDO::FETCH_ASSOC);
if (!$n) exit("Número $inst não existe.\n");
echo "número #{$n['id']} \"{$n['instance_name']}\" ({$n['phone']}), conta {$n['account_id']}, dono atual: " . var_export($n['responsavel_user_id'], true) . "\n";

if ($uid > 0) {
    $st = $pdo->prepare("SELECT id, nome, login FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL AND status = 'active'");
    $st->execute([$uid, (int) $n['account_id']]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) exit("Usuário $uid não é um usuário ativo da conta {$n['account_id']}. Nada feito.\n");
    echo "novo dono: #{$u['id']} {$u['nome']} <{$u['login']}>\n";
} else {
    echo "novo dono: nenhum\n";
}
if (!isset($o['aplicar'])) { echo "(só conferi; --aplicar grava)\n"; exit(0); }

$pdo->prepare('UPDATE whatsapp_instances SET responsavel_user_id = ? WHERE id = ?')->execute([$uid > 0 ? $uid : null, $inst]);
\App\Master\Account::audit((int) $n['account_id'], 'whatsapp.dono_numero', ['entidade' => 'whatsapp_instance', 'entidade_id' => $inst,
    'detalhes' => ['de' => $n['responsavel_user_id'], 'para' => $uid > 0 ? $uid : null, 'origem' => 'scripts/manutencao/definir_dono_numero.php']]);
echo "gravado.\n";
