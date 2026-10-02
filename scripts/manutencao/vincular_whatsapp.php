<?php
/**
 * scripts/manutencao/vincular_whatsapp.php — liga a uma conta, como primeiro
 * número, uma instância que JÁ EXISTE na Evolution (QR lido fora do sistema).
 *
 * Usa WhatsAppProvisioningService::vincularExistente(), que é o provision() sem
 * criar instância. Recusa sem mexer em nada se a conta já tem número, se a
 * instância é de outra conta ou se ela já manda eventos para outro endereço.
 *
 * USO
 *   php scripts/manutencao/vincular_whatsapp.php --conta=119 --instancia="S1  principal"            (só confere)
 *   php scripts/manutencao/vincular_whatsapp.php --conta=119 --instancia="S1  principal" --aplicar  (liga)
 *
 * O nome da instância é exato, com os espaços que tiver na Evolution.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\WhatsAppAgente\WhatsAppProvisioningService;

$o = getopt('', ['conta:', 'instancia:', 'aplicar']);
$conta = (int) ($o['conta'] ?? 0);
$inst  = (string) ($o['instancia'] ?? '');
if ($conta <= 0 || $inst === '') exit("Informe --conta=ID e --instancia=\"nome exato\".\n");

$pdo = \App\Core\Database::getConnection();
$st = $pdo->prepare('SELECT id, nome FROM accounts WHERE id = ? AND deleted_at IS NULL');
$st->execute([$conta]);
$acc = $st->fetch(PDO::FETCH_ASSOC);
if (!$acc) exit("Conta $conta não encontrada.\n");

$aplicar = isset($o['aplicar']);
$r = WhatsAppProvisioningService::vincularExistente($pdo, (int) $acc['id'], (string) $acc['nome'], $inst, !$aplicar);
echo ($aplicar ? 'APLICAR' : 'SIMULAR') . " conta {$acc['id']} ({$acc['nome']}) instância \"$inst\"\n";
foreach ($r as $k => $v) echo "  $k: " . (is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)) . "\n";
if ($aplicar && !empty($r['success'])) {
    \App\Master\Account::audit((int) $acc['id'], 'whatsapp.vincular_existente', ['entidade' => 'whatsapp_instance',
        'entidade_id' => (int) ($r['channel_id'] ?? 0), 'detalhes' => ['instancia' => $inst, 'telefone' => $r['telefone'] ?? null,
        'origem' => 'scripts/manutencao/vincular_whatsapp.php']]);
}
exit(!empty($r['success']) ? 0 : 1);
