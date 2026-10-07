<?php
/**
 * scripts/manutencao/setores_por_segmento.php — cadastra os setores de uma conta
 * da edição CRM por SEGMENTO (Advocacia, Estética) e marca neles os leads e as
 * conversas que ainda estão "Sem setor".
 *
 * Pedido da Inovaize (07/10/2026): a conta não tinha nenhum setor cadastrado, então
 * toda conversa aparecia "Sem setor" e o botão Setor do Chat não tinha o que
 * oferecer. Os dois segmentos com que ela trabalha viram setores.
 *
 * COMO CLASSIFICA (só o que tem prova; o resto fica "Sem setor" e é listado)
 *   1. Lead com tipo_lead ("Advocacia", "Clínica de Estética"): vale o tipo.
 *   2. Sem tipo: a PRIMEIRA mensagem que a conta enviou na conversa. A abertura de
 *      advocacia fala de "escritório" e do Yuris; a de estética fala de "clínica".
 *      Se as duas famílias de palavras aparecem, é ambíguo e não se marca.
 *   O setor do lead e o da conversa ficam iguais (mesma regra de
 *   WhatsAppMessage::sincronizarSetorComCard). Setor já escolhido por uma pessoa
 *   nunca é trocado. Tudo dentro da conta informada.
 *
 * IDEMPOTENTE: rodar de novo só marca o que apareceu de novo e ainda está sem setor.
 * Só confere por padrão.
 *
 * USO
 *   php scripts/manutencao/setores_por_segmento.php --conta=119            (confere)
 *   php scripts/manutencao/setores_por_segmento.php --conta=119 --aplicar  (grava)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Usuarios\Team;

$o = getopt('', ['conta:', 'aplicar']);
$conta = (int) ($o['conta'] ?? 0);
$aplicar = isset($o['aplicar']);
if ($conta <= 0) exit("Informe --conta=ID.\n");

$SETORES = [
    'Advocacia' => ['cor' => '#2563EB', 'tipo' => '/advoc/iu',           'msg' => '/escrit[oó]rio|advoc|jur[ií]dic|yuris/iu'],
    'Estética'  => ['cor' => '#DB2777', 'tipo' => '/est[eé]tic|cl[ií]nic/iu', 'msg' => '/cl[ií]nica|est[eé]tic|biom[eé]dic|harmoniza/iu'],
];

$pdo = Database::getConnection();
$acc = \App\Master\Account::findById($conta);
if (!$acc) exit("Conta $conta não existe.\n");
if (\App\Master\Account::moduloJuridicoDisponivel($acc)) exit("Conta $conta tem módulo jurídico (Yuris): este script é da edição CRM. Nada feito.\n");
if (!Team::temColuna('cards', 'team_id')) exit("Falta a migration 140 (cards.team_id).\n");
echo "conta #{$acc['id']} \"{$acc['nome']}\" " . ($aplicar ? '(GRAVANDO)' : '(conferência, nada é gravado)') . "\n\n";

// 1) Setores: existentes pelo nome, ou a criar.
$ids = [];
foreach ($SETORES as $nome => $cfg) {
    $st = $pdo->prepare('SELECT id FROM teams WHERE account_id = ? AND nome = ? AND deleted_at IS NULL ORDER BY id LIMIT 1');
    $st->execute([$conta, $nome]);
    $id = (int) ($st->fetchColumn() ?: 0);
    if ($id === 0 && $aplicar) {
        $id = Team::create(['account_id' => $conta, 'nome' => $nome, 'cor' => $cfg['cor'], 'descricao' => 'Segmento ' . $nome]);
        \App\Master\Account::audit($conta, 'team.created', ['user_id' => null, 'entidade' => 'team', 'entidade_id' => $id,
            'detalhes' => ['nome' => $nome, 'cor' => $cfg['cor'], 'origem' => 'script setores_por_segmento']]);
    }
    $ids[$nome] = $id;
    echo "setor $nome: " . ($id ? "#$id" . ($id && !$aplicar ? ' (já existe)' : '') : 'a criar') . "\n";
}

// 2) Primeira mensagem enviada pela conta em cada conversa (por telefone, com e sem o 9 de celular não importa:
//    a conversa já está ligada ao card, e a das sem card entra pelo próprio chat).
$primeira = function (int $instanceId, string $jid) use ($pdo): string {
    $st = $pdo->prepare("SELECT message_content FROM whatsapp_messages
                          WHERE instance_id = ? AND remote_jid = ? AND direction = 'outbound' AND message_content IS NOT NULL AND message_content <> ''
                          ORDER BY created_at ASC, id ASC LIMIT 1");
    $st->execute([$instanceId, $jid]);
    return (string) ($st->fetchColumn() ?: '');
};
$classifica = function (?string $tipo, string $msg) use ($SETORES): ?string {
    if ($tipo !== null && trim($tipo) !== '') {
        foreach ($SETORES as $nome => $c) if (preg_match($c['tipo'], $tipo)) return $nome;
    }
    $achou = [];
    foreach ($SETORES as $nome => $c) if ($msg !== '' && preg_match($c['msg'], $msg)) $achou[] = $nome;
    return count($achou) === 1 ? $achou[0] : null;
};

$cont = ['cards' => array_fill_keys(array_keys($SETORES), 0), 'chats' => array_fill_keys(array_keys($SETORES), 0)];
$semClasse = [];

// 3) Cards sem setor.
$cards = $pdo->prepare('SELECT c.id, c.cliente_nome, c.empresa_nome, c.tipo_lead,
                               (SELECT w.instance_id FROM whatsapp_chats w WHERE w.linked_card_id = c.id ORDER BY w.last_message_at DESC LIMIT 1) AS inst,
                               (SELECT w.remote_jid  FROM whatsapp_chats w WHERE w.linked_card_id = c.id ORDER BY w.last_message_at DESC LIMIT 1) AS jid
                          FROM cards c WHERE c.account_id = ? AND c.deleted_at IS NULL AND c.team_id IS NULL');
$cards->execute([$conta]);
foreach ($cards->fetchAll(PDO::FETCH_ASSOC) as $c) {
    $msg = ($c['inst'] && $c['jid']) ? $primeira((int) $c['inst'], (string) $c['jid']) : '';
    $setor = $classifica($c['tipo_lead'], $msg);
    if ($setor === null) { $semClasse[] = 'lead #' . $c['id'] . ' ' . ($c['empresa_nome'] ?: $c['cliente_nome']); continue; }
    $cont['cards'][$setor]++;
    if ($aplicar && $ids[$setor]) Team::definirSetorDoCard($conta, (int) $c['id'], $ids[$setor], true);
}

// 4) Conversas sem setor: herdam o do card; as sem card, pela primeira mensagem.
$chats = $pdo->prepare('SELECT w.id, w.instance_id, w.remote_jid, w.contact_name, w.linked_card_id, cd.team_id AS card_team, cd.tipo_lead
                          FROM whatsapp_chats w
                          LEFT JOIN cards cd ON cd.id = w.linked_card_id AND cd.account_id = w.account_id AND cd.deleted_at IS NULL
                         WHERE w.account_id = ? AND w.team_id IS NULL AND w.is_group = 0 AND w.is_archived = 0
                           AND w.remote_jid NOT LIKE \'%@lid\'');
$chats->execute([$conta]);
$nomePorId = array_flip(array_filter($ids));
foreach ($chats->fetchAll(PDO::FETCH_ASSOC) as $w) {
    $setor = null;
    if ($w['card_team'] && isset($nomePorId[(int) $w['card_team']])) {
        $setor = $nomePorId[(int) $w['card_team']];
    } else {
        $setor = $classifica($w['tipo_lead'], $primeira((int) $w['instance_id'], (string) $w['remote_jid']));
    }
    if ($setor === null) { $semClasse[] = 'conversa #' . $w['id'] . ' ' . ($w['contact_name'] ?: $w['remote_jid']); continue; }
    $cont['chats'][$setor]++;
    if ($aplicar && $ids[$setor]) {
        $pdo->prepare('UPDATE whatsapp_chats SET team_id = ? WHERE id = ? AND account_id = ? AND team_id IS NULL')->execute([$ids[$setor], (int) $w['id'], $conta]);
        if ($w['linked_card_id']) Team::definirSetorDoCard($conta, (int) $w['linked_card_id'], $ids[$setor], true);
    }
}

echo "\n" . ($aplicar ? 'marcados' : 'a marcar') . ":\n";
foreach ($SETORES as $nome => $_) printf("  %-10s %3d leads, %3d conversas\n", $nome, $cont['cards'][$nome], $cont['chats'][$nome]);
echo "\nsem prova de segmento (continuam \"Sem setor\"): " . count($semClasse) . "\n";
foreach (array_slice($semClasse, 0, 15) as $l) echo "  - $l\n";
if (count($semClasse) > 15) echo '  ... e mais ' . (count($semClasse) - 15) . "\n";
if (!$aplicar) echo "\n(conferência) nada gravado. Rode de novo com --aplicar.\n";
