<?php
/**
 * scripts/manutencao/avisos_destinatarios.php: quem recebe um aviso de tempo real
 * da conta (App\Notificacoes\Destinatarios, 22/10/2026). É o mesmo que a tela
 * Configurações › Notificações › "Quem recebe os avisos" grava; serve para deixar
 * a conta configurada no dia do deploy. Pedido da Inovaize: lead novo para o
 * Kauê e para os administradores, para a Isa não.
 *
 * Mostra a escolha atual e como fica; só grava com --aplicar. Os usuários têm de
 * ser da conta (os de fora são descartados por Destinatarios::salvar).
 *
 * USO
 *   php scripts/manutencao/avisos_destinatarios.php --conta=119 --evento=lead_novo --admins=1 --usuarios=128
 *   php scripts/manutencao/avisos_destinatarios.php --conta=119 --evento=lead_novo --admins=1 --usuarios=128 --aplicar
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Notificacoes\Destinatarios;

$o = getopt('', ['conta:', 'evento:', 'admins:', 'usuarios:', 'aplicar']);
$conta  = (int) ($o['conta'] ?? 0);
$evento = (string) ($o['evento'] ?? 'lead_novo');
if ($conta <= 0 || !isset(Destinatarios::EVENTOS[$evento])) {
    exit("Informe --conta=ID e um --evento conhecido (" . implode(', ', array_keys(Destinatarios::EVENTOS)) . ").\n");
}
$admins   = filter_var($o['admins'] ?? '1', FILTER_VALIDATE_BOOLEAN);
$usuarios = array_values(array_filter(array_map('intval', explode(',', (string) ($o['usuarios'] ?? '')))));

$pdo = Database::getConnection();
$nomes = static function (array $ids) use ($pdo, $conta): string {
    if (!$ids) return '(ninguém)';
    $st = $pdo->prepare('SELECT id, nome FROM users WHERE account_id = ? AND id IN (' . implode(',', array_map('intval', $ids)) . ')');
    $st->execute([$conta]);
    return implode(', ', array_map(fn($r) => $r['nome'] . ' (' . $r['id'] . ')', $st->fetchAll(\PDO::FETCH_ASSOC)));
};

echo "Conta $conta, evento $evento\n";
echo '  hoje recebem: ' . $nomes(Destinatarios::usuarios($conta, $evento)) . "\n";
if (!isset($o['aplicar'])) {
    echo '  ficaria: administradores ' . ($admins ? 'sim' : 'não') . ' + ' . $nomes($usuarios) . "\n";
    exit("Nada gravado. Rode de novo com --aplicar.\n");
}
Destinatarios::salvar($conta, $evento, $admins, $usuarios);
echo '  agora recebem: ' . $nomes(Destinatarios::usuarios($conta, $evento)) . "\n";
