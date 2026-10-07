<?php
/**
 * scripts/manutencao/salvar_contatos_sem_card.php — garante que todo contato do
 * WhatsApp de uma conta da edição CRM tenha card.
 *
 * Pedido (07/10/2026): "o lead pingou no número, tem que criar um card novo; se o
 * WhatsApp cair, os cards são a base". O card já nasce sozinho quando a mensagem chega
 * (SdrFleetiflow::aoMensagem); esta é a rede de segurança que pega o que o webhook
 * perdeu, a mesma que roda na lista do Chat (SdrFleetiflow::ligarConversasSoltas).
 * Aqui serve para conferir e para varrer uma conta de uma vez.
 *
 * Para cada número (instância) da conta lista o que faria:
 *   ligar  a conversa já tem card de mesmo telefone, só falta ligar
 *   criar  a conversa não tem card: nasce em "Novos leads", com o nome da conversa
 *   pular  com o motivo: sem telefone conhecido (WhatsApp mostrou só o identificador
 *          interno @lid), sem nenhuma mensagem, número da própria conta, número de
 *          treino da equipe
 * Quem já é cliente não vira card. Só confere por padrão. Cada card novo avisa a
 * equipe como qualquer lead novo, então rode com --aplicar sabendo disso.
 *
 * USO
 *   php scripts/manutencao/salvar_contatos_sem_card.php --conta=119            (confere)
 *   php scripts/manutencao/salvar_contatos_sem_card.php --conta=119 --aplicar  (grava)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\WhatsAppAgente\SdrFleetiflow;

$o = getopt('', ['conta:', 'aplicar']);
$conta = (int) ($o['conta'] ?? 0);
$aplicar = isset($o['aplicar']);
if ($conta <= 0) exit("Informe --conta=ID.\n");

$pdo = \App\Core\Database::getConnection();
$acc = \App\Master\Account::findById($conta);
if (!$acc) exit("Conta $conta não existe.\n");
if (\App\Master\Account::moduloJuridicoDisponivel($acc)) exit("Conta $conta tem módulo jurídico (Yuris): este script é da edição CRM. Nada feito.\n");
echo "conta #{$acc['id']} \"{$acc['nome']}\" " . ($aplicar ? '(GRAVANDO)' : '(conferência, nada é gravado)') . "\n";

$ins = $pdo->prepare('SELECT id, instance_name, phone, status FROM whatsapp_instances WHERE account_id = ? ORDER BY id');
$ins->execute([$conta]);
$totalCriar = 0;
foreach ($ins->fetchAll(PDO::FETCH_ASSOC) as $i) {
    $plano = SdrFleetiflow::conversasSemCard($pdo, $conta, (int) $i['id']);
    echo "\nnúmero #{$i['id']} \"{$i['instance_name']}\" ({$i['phone']}, {$i['status']}): "
       . count($plano['ligar']) . ' a ligar, ' . count($plano['criar']) . ' a criar, ' . count($plano['pular']) . " a pular\n";
    foreach ($plano['criar'] as $c) echo "  criar  {$c['fone']}  " . ($c['nome'] !== '' ? $c['nome'] : '(sem nome)') . "\n";
    foreach ($plano['pular'] as $p) echo '  pular  ' . substr($p['jid'], 0, 24) . '  ' . $p['motivo'] . "\n";
    $totalCriar += count($plano['criar']);
    if ($aplicar) echo '  => ' . SdrFleetiflow::ligarConversasSoltas($pdo, $conta, (int) $i['id']) . " conversa(s) ligada(s) ou com card novo\n";
}
if (!$aplicar) echo "\n(conferência) nada gravado. Rode de novo com --aplicar.\n";
