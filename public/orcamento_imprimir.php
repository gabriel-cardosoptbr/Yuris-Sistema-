<?php
/**
 * O orçamento do CRM como o CLIENTE lê: taxas do órgão e débitos do veículo em
 * linha própria, e o honorário. O controle interno (SERPRO, custos, margem)
 * nunca aparece aqui. "Salvar como PDF" do navegador gera o arquivo.
 */
require_once __DIR__ . '/../app/bootstrap.php';
session_start(['read_and_close' => true]);
if (empty($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

use App\Core\AccountContext;
use App\Core\Database;
use App\Orcamentos\Orcamento;

$ctx = AccountContext::fromSession();
$ctx->assertAccountActive();
if ($ctx->getProduto() !== 'fleetiflow') { http_response_code(404); exit; }
$acc = (int) $ctx->getAccountId();
$f = Orcamento::ficha($acc, (int) ($_GET['id'] ?? 0));
if (!$f) { http_response_code(404); echo 'Orçamento não encontrado.'; exit; }

$marca = $ctx->getMarca();
$st = Database::getConnection()->prepare('SELECT nome, razao_social, cnpj, telefone, email, cidade, estado FROM accounts WHERE id = ?');
$st->execute([$acc]);
$conta = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
$nomeConta = trim((string) ($conta['razao_social'] ?? '')) ?: trim((string) ($conta['nome'] ?? '')) ?: (string) ($marca['nome'] ?? '');
$cnpj = preg_replace('/\D/', '', (string) ($conta['cnpj'] ?? ''));
if (strlen($cnpj) === 14) $cnpj = substr($cnpj, 0, 2) . '.' . substr($cnpj, 2, 3) . '.' . substr($cnpj, 5, 3) . '/' . substr($cnpj, 8, 4) . '-' . substr($cnpj, 12);
$cor = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($marca['cor'] ?? '')) ? $marca['cor'] : '#015DFC';

$e = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$real = fn($v) => 'R$ ' . number_format((float) $v, 2, ',', '.');
$data = fn($s) => $s ? date('d/m/Y', strtotime((string) $s)) : '';
$situacao = ['RASCUNHO' => 'Proposta', 'APROVADO' => 'Aprovado', 'CONVERTIDO' => 'Aprovado', 'REJEITADO' => 'Não aprovado'][$f['status']] ?? '';
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Orçamento nº <?= (int) $f['numero'] ?> · <?= $e($f['cliente_nome']) ?></title>
  <style>
    :root { --cor: <?= $e($cor) ?>; --texto: #1F2937; --muted: #6B7280; --borda: #E5E7EB; }
    * { box-sizing: border-box; }
    body { margin: 0; background: #F4F6F9; color: var(--texto); font: 14px/1.5 'Helvetica Neue', Arial, sans-serif; }
    .folha { max-width: 760px; margin: 24px auto; background: #fff; padding: 40px 48px; border-top: 6px solid var(--cor); box-shadow: 0 10px 30px rgba(17,29,45,.08); }
    .topo { display: flex; justify-content: space-between; gap: 20px; align-items: flex-start; }
    .topo h1 { margin: 0; font-size: 22px; }
    .topo .sub { color: var(--muted); font-size: 12.5px; }
    .numero { text-align: right; }
    .numero b { display: block; font-size: 18px; color: var(--cor); }
    h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin: 26px 0 8px; }
    .dados { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 24px; font-size: 13.5px; }
    .dados span { color: var(--muted); display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; }
    ul.servicos { margin: 0; padding-left: 18px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 9px 0; border-bottom: 1px solid var(--borda); }
    td.v { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    tr.total td { border-bottom: 0; border-top: 2px solid var(--texto); font-weight: 800; font-size: 16px; padding-top: 12px; }
    .obs { margin-top: 18px; padding: 12px 14px; background: #F9FAFB; border-radius: 8px; font-size: 13px; white-space: pre-wrap; }
    .rodape { margin-top: 30px; color: var(--muted); font-size: 11.5px; display: flex; justify-content: space-between; }
    .acoes { max-width: 760px; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; }
    .acoes button { padding: 9px 16px; border-radius: 10px; border: 1px solid var(--cor); background: var(--cor); color: #fff; font-weight: 700; cursor: pointer; }
    @media print { body { background: #fff; } .folha { box-shadow: none; margin: 0; max-width: none; padding: 0; border-top: 0; } .acoes { display: none; } }
  </style>
</head>
<body>
  <div class="acoes"><button type="button" onclick="window.print()">Imprimir / salvar PDF</button></div>
  <div class="folha">
    <div class="topo">
      <div>
        <h1><?= $e($nomeConta) ?></h1>
        <div class="sub"><?= $e(implode(' · ', array_filter([$cnpj ? 'CNPJ ' . $cnpj : '', (string) ($conta['telefone'] ?? ''), (string) ($conta['email'] ?? '')]))) ?></div>
      </div>
      <div class="numero"><b>Orçamento nº <?= (int) $f['numero'] ?></b><span class="sub"><?= $e($situacao) ?> · <?= $e($data($f['created_at'])) ?></span></div>
    </div>

    <h2>Cliente</h2>
    <div class="dados">
      <div><span>Nome</span><?= $e($f['cliente_nome']) ?></div>
      <div><span>CPF/CNPJ</span><?= $e($f['cliente_documento'] ?: '—') ?></div>
      <div><span>Veículo</span><?= $e($f['placa'] ?: 'A definir') ?><?= $f['veiculo'] ? ', ' . $e($f['veiculo']) : '' ?></div>
      <div><span>Atendimento</span><?= $e($f['vendedor_nome'] ?: '—') ?></div>
    </div>

    <h2>Serviços</h2>
    <ul class="servicos">
      <?php foreach ($f['itens'] as $i): ?>
        <li><?= $i['quantidade'] != 1 ? $e(str_replace('.', ',', (string) $i['quantidade'])) . ' × ' : '' ?><?= $e($i['descricao']) ?><?= $i['natureza'] === 'DEBITO' ? ' (débito do veículo)' : '' ?></li>
      <?php endforeach; ?>
    </ul>

    <h2>Valores</h2>
    <table>
      <?php foreach ($f['para_o_cliente'] as $l): ?>
        <tr><td><?= $e($l['descricao']) ?></td><td class="v"><?= $real($l['valor']) ?></td></tr>
      <?php endforeach; ?>
      <tr class="total"><td>Orçamento</td><td class="v"><?= $real($f['total']) ?></td></tr>
    </table>

    <?php if ($f['observacoes']): ?><div class="obs"><?= $e($f['observacoes']) ?></div><?php endif; ?>

    <div class="rodape"><span>Gerado em <?= $e($data(\App\Tarefas\TaskEntrega::agoraLocal())) ?></span><span><?= $e($nomeConta) ?></span></div>
  </div>
</body>
</html>
