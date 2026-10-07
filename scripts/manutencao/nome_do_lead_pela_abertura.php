<?php
/**
 * scripts/manutencao/nome_do_lead_pela_abertura.php — dá nome ao lead que só tem o
 * telefone no lugar do nome.
 *
 * Lead criado pela automação (a primeira mensagem do robô de prospecção, antes do
 * backfill da planilha) nasce com o telefone em cliente_nome e sem empresa_nome, e
 * a conversa mostra só o número. A abertura que a própria conta enviou começa pelo
 * nome da empresa ("Olá, Sampaio e Dellova Campos Advogados! Tudo bem? Aqui é a
 * Isa..."), então o nome está no sistema, escrito pela automação a partir da
 * planilha. Este script lê essa saudação e grava o nome no lead.
 *
 * SÓ MEXE EM LEAD cujo cliente_nome é um telefone (sem nenhuma letra) e cujo
 * empresa_nome está vazio; nome que alguém digitou ou que veio da planilha nunca é
 * trocado. Só vale saudação no molde "Oi|Olá, NOME!" na PRIMEIRA mensagem enviada;
 * "pessoal da/do" é tirado ("Oi, pessoal da Avance Motors!" => "Avance Motors").
 * Nome genérico (tudo, pessoal, time...) é descartado. Cada troca entra no histórico
 * do lead (acao 'updated', com o valor anterior). Tudo dentro da conta informada.
 *
 * IDEMPOTENTE: depois de nomeado o lead sai do filtro. Só confere por padrão.
 *
 * USO
 *   php scripts/manutencao/nome_do_lead_pela_abertura.php --conta=119            (confere, lista nome a nome)
 *   php scripts/manutencao/nome_do_lead_pela_abertura.php --conta=119 --aplicar  (grava)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

/** Nome na saudação da abertura, ou '' quando não dá para confiar. */
function nomePelaSaudacao(string $msg): string
{
    if (!preg_match('/^\s*(?:Oi|Olá|Ola)\s*,?\s+(.{2,90}?)\s*!/u', $msg, $m)) return '';
    $n = trim((string) preg_replace('/\s+/u', ' ', $m[1]));
    $n = trim((string) preg_replace('/^pessoal\s+d[aeo]s?\s+/iu', '', $n));
    if ($n === '' || !preg_match('/\p{L}/u', $n)) return '';
    if (preg_match('/^(tudo|pessoal|time|equipe|amigo|amiga|tudo bem|tudo certo|prezad[oa]s?)\b/iu', $n)) return '';
    return mb_substr($n, 0, 191);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== realpath(__FILE__)) return; // incluído por teste: só a função

$o = getopt('', ['conta:', 'aplicar']);
$conta = (int) ($o['conta'] ?? 0);
$aplicar = isset($o['aplicar']);
if ($conta <= 0) exit("Informe --conta=ID.\n");

$pdo = \App\Core\Database::getConnection();
$acc = \App\Master\Account::findById($conta);
if (!$acc) exit("Conta $conta não existe.\n");
if (\App\Master\Account::moduloJuridicoDisponivel($acc)) exit("Conta $conta tem módulo jurídico (Yuris): este script é da edição CRM. Nada feito.\n");
echo "conta #{$acc['id']} \"{$acc['nome']}\" " . ($aplicar ? '(GRAVANDO)' : '(conferência, nada é gravado)') . "\n\n";

$cards = $pdo->prepare("SELECT c.id, c.cliente_nome, c.empresa_nome,
                               (SELECT w.instance_id FROM whatsapp_chats w WHERE w.linked_card_id = c.id AND w.account_id = c.account_id ORDER BY w.last_message_at DESC LIMIT 1) AS inst,
                               (SELECT w.remote_jid  FROM whatsapp_chats w WHERE w.linked_card_id = c.id AND w.account_id = c.account_id ORDER BY w.last_message_at DESC LIMIT 1) AS jid
                          FROM cards c
                         WHERE c.account_id = ? AND c.deleted_at IS NULL
                           AND (c.empresa_nome IS NULL OR TRIM(c.empresa_nome) = '')
                           AND c.cliente_nome NOT REGEXP '[[:alpha:]]'");
$cards->execute([$conta]);
$primeira = $pdo->prepare("SELECT message_content FROM whatsapp_messages
                            WHERE instance_id = ? AND remote_jid = ? AND direction = 'outbound' AND message_content IS NOT NULL AND message_content <> ''
                            ORDER BY created_at ASC, id ASC LIMIT 1");
$up = $pdo->prepare('UPDATE cards SET cliente_nome = ?, empresa_nome = ?, updated_at = updated_at WHERE id = ? AND account_id = ? AND (empresa_nome IS NULL OR TRIM(empresa_nome) = \'\') AND cliente_nome NOT REGEXP \'[[:alpha:]]\'');
$hist = $pdo->prepare("INSERT INTO card_history (card_id, usuario_id, acao, campo_alterado, valor_anterior, valor_novo, created_at) VALUES (?, NULL, 'updated', 'cliente_nome', ?, ?, NOW())");

$feitos = 0; $sem = [];
foreach ($cards->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $nome = '';
    if ($c['inst'] && $c['jid']) {
        $primeira->execute([(int) $c['inst'], (string) $c['jid']]);
        $nome = nomePelaSaudacao((string) ($primeira->fetchColumn() ?: ''));
    }
    if ($nome === '') { $sem[] = 'lead #' . $c['id'] . ' (' . $c['cliente_nome'] . ')'; continue; }
    echo sprintf("  lead #%-5d %-22s => %s\n", $c['id'], $c['cliente_nome'], $nome);
    if ($aplicar) {
        $up->execute([$nome, $nome, (int) $c['id'], $conta]);
        if ($up->rowCount() > 0) { $hist->execute([(int) $c['id'], (string) $c['cliente_nome'], $nome]); $feitos++; }
    } else {
        $feitos++;
    }
}
echo "\n" . ($aplicar ? 'nomeados' : 'a nomear') . ": $feitos\n";
echo 'sem saudação que dê o nome (continuam só com o telefone): ' . count($sem) . "\n";
foreach (array_slice($sem, 0, 15) as $l) echo "  - $l\n";
if (!$aplicar) echo "\n(conferência) nada gravado. Rode de novo com --aplicar.\n";
