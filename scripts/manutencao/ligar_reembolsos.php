<?php
/**
 * scripts/manutencao/ligar_reembolsos.php — liga ou desliga o painel de
 * Reembolsos da tela Finanças numa conta (configuracoes.modulos.reembolsos,
 * App\Financas\Reembolso::habilitado).
 *
 * Só vale para conta da edição CRM: conta com módulo jurídico (Yuris) é
 * recusada, e mesmo com a chave o painel não apareceria nela. Pedido de
 * 03/10/2026: ligado só na Inovaize. Só confere por padrão.
 *
 * USO
 *   php scripts/manutencao/ligar_reembolsos.php --conta=119                       (confere)
 *   php scripts/manutencao/ligar_reembolsos.php --conta=119 --aplicar             (liga)
 *   php scripts/manutencao/ligar_reembolsos.php --conta=119 --desligar --aplicar  (desliga)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Financas\Reembolso;
use App\Master\Account;

$o = getopt('', ['conta:', 'desligar', 'aplicar']);
$conta = (int) ($o['conta'] ?? 0);
if ($conta <= 0) exit("Informe --conta=ID.\n");
$ligar = !isset($o['desligar']);

$pdo = \App\Core\Database::getConnection();
$acc = Account::findById($conta);
if (!$acc) exit("Conta $conta não existe.\n");
echo "conta #{$acc['id']} \"{$acc['nome']}\" ({$acc['status']}), reembolsos hoje: " . (Reembolso::habilitado($acc) ? 'ligado' : 'desligado') . "\n";
if ($ligar && Account::moduloJuridicoDisponivel($acc)) exit("Conta com módulo jurídico (Yuris): o painel de reembolsos é só da edição CRM. Nada feito.\n");
echo 'vai ficar: ' . ($ligar ? 'ligado' : 'desligado') . "\n";
if (!isset($o['aplicar'])) exit("(conferência) nada gravado. Rode de novo com --aplicar.\n");

Reembolso::ligar($pdo, $conta, $ligar);
echo 'gravado. Agora: ' . (Reembolso::habilitado(Account::findById($conta) ?? []) ? 'ligado' : 'desligado') . "\n";
