<?php
/**
 * scripts/manutencao/setor_dos_disparos.php: coloca os leads que vieram de disparo nos
 * setores dos SEGMENTOS que a Fleet atende (Concessionária, Locação, Transportadora,
 * Despachante, Telecom), pelo nome da empresa.
 *
 * Pedido do dono da Fleet (05/11/2026): os setores são os segmentos atendidos, e os
 * leads dos disparos já feitos precisam estar neles. Uma primeira versão deste script
 * criou setores de ORIGEM ("Grupo Veículos SP", "Prospecção ativa"); a decisão foi
 * trocá-los pelos segmentos de verdade, então ele agora desfaz isso:
 *
 *   1. candidatos: lead com descrição de disparo e SEM setor, e lead que está num setor
 *      de origem (descrição começa com Team::PREFIXO_ORIGEM);
 *   2. o setor sai do NOME da empresa (SegmentoLead::setorPeloNome), só entre os
 *      setores da conta, e só com prova: ambíguo ou sem palavra do segmento, não marca;
 *   3. a conversa ligada ao lead, se está sem setor ou no de origem, recebe o mesmo;
 *   4. quem não foi classificado fica SEM setor (e o robô ainda o classifica quando o
 *      lead responder), e o setor de origem vazio é excluído.
 *
 * A data de última alteração (updated_at) NÃO muda: a Prospecção a usa como "último contato"
 * e para ordenar os cards, e marcar setor não é contato.
 *
 * Setor escolhido por uma pessoa nunca é trocado. Só edição CRM, tudo dentro da conta.
 * IDEMPOTENTE. Só confere por padrão.
 *
 * USO
 *   php scripts/manutencao/setor_dos_disparos.php --conta=116            (confere)
 *   php scripts/manutencao/setor_dos_disparos.php --conta=116 --aplicar  (grava)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Usuarios\Team;
use App\WhatsAppAgente\SegmentoLead;

$o = getopt('', ['conta:', 'aplicar']);
$conta = (int) ($o['conta'] ?? 0);
$aplicar = isset($o['aplicar']);
if ($conta <= 0) exit("Informe --conta=ID.\n");

// Setores que o dono pediu para existir; Telecom só entra se a conta já tem.
$OBRIGATORIOS = ['Concessionária' => '#3B82F6', 'Locação' => '#10B981', 'Transportadora' => '#8B5CF6', 'Despachante' => '#F59E0B'];
$ORIGENS = ['Lista Grupo Veículos SP%', 'Lead da prospecção ativa pelo WhatsApp%'];

$pdo = Database::getConnection();
$acc = \App\Master\Account::findById($conta);
if (!$acc) exit("Conta $conta não existe.\n");
if (\App\Master\Account::moduloJuridicoDisponivel($acc)) exit("Conta $conta tem módulo jurídico (Yuris): este script é da edição CRM. Nada feito.\n");
if (!Team::temColuna('cards', 'team_id')) exit("Falta a migration 140 (cards.team_id).\n");
echo "conta #{$acc['id']} \"{$acc['nome']}\" " . ($aplicar ? '(GRAVANDO)' : '(conferência, nada é gravado)') . "\n\n";

// Setores finais (id => nome) e os de origem.
$setores = [];
$st = $pdo->prepare('SELECT id, nome, descricao FROM teams WHERE account_id = ? AND deleted_at IS NULL');
$st->execute([$conta]);
$origemIds = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
    if (str_starts_with((string) $t['descricao'], Team::PREFIXO_ORIGEM)) { $origemIds[] = (int) $t['id']; continue; }
    $setores[(int) $t['id']] = (string) $t['nome'];
}
foreach ($OBRIGATORIOS as $nome => $cor) {
    if (in_array(SegmentoLead::chave($nome), array_map([SegmentoLead::class, 'chave'], $setores), true)) continue;
    if (!$aplicar) { echo "setor $nome: a criar\n"; $setores[-count($setores) - 1] = $nome; continue; }
    $id = Team::create(['account_id' => $conta, 'nome' => $nome, 'cor' => $cor, 'descricao' => null]);
    \App\Master\Account::audit($conta, 'team.created', ['user_id' => null, 'entidade' => 'team', 'entidade_id' => $id,
        'detalhes' => ['nome' => $nome, 'cor' => $cor, 'origem' => 'script setor_dos_disparos']]);
    $setores[$id] = $nome;
    echo "setor $nome: criado #$id\n";
}

// Candidatos.
$like = implode(' OR ', array_fill(0, count($ORIGENS), 'c.descricao LIKE ?'));
$args = [$conta];
$cond = "(c.team_id IS NULL AND ($like))";
foreach ($ORIGENS as $l) $args[] = $l;
if ($origemIds) { $cond .= ' OR c.team_id IN (' . implode(',', $origemIds) . ')'; }
$st = $pdo->prepare("SELECT c.id, c.titulo, c.empresa_nome, c.team_id FROM cards c WHERE c.account_id = ? AND c.deleted_at IS NULL AND ($cond)");
$st->execute($args);
$cards = $st->fetchAll(PDO::FETCH_ASSOC);

$porSetor = []; $sem = []; $destino = [];
foreach ($cards as $c) {
    $id = SegmentoLead::setorPeloNome(trim(($c['empresa_nome'] ?: '') . ' ' . $c['titulo']), $setores);
    if ($id === null) { $sem[] = $c; continue; }
    $porSetor[$setores[$id]] = ($porSetor[$setores[$id]] ?? 0) + 1;
    $destino[(int) $c['id']] = $id;
}
echo sprintf("\n%d lead(s) de disparo para classificar\n", count($cards));
arsort($porSetor);
foreach ($porSetor as $nome => $n) echo sprintf("  %-16s %4d\n", $nome, $n);
echo sprintf("  %-16s %4d (ficam sem setor)\n", 'sem prova', count($sem));
if ($sem) {
    echo "\nAmostra dos que ficam sem setor:\n";
    shuffle($sem);
    foreach (array_slice($sem, 0, 30) as $c) echo '  - ' . mb_substr((string) $c['titulo'], 0, 70) . "\n";
}
if (!$aplicar) { echo "\nNada gravado. Use --aplicar.\n"; exit; }

$origemLista = $origemIds ? implode(',', $origemIds) : '0';
$pdo->beginTransaction();
try {
    $card = $pdo->prepare("UPDATE cards SET team_id = :t, updated_at = updated_at WHERE id = :id AND account_id = :a AND (team_id IS NULL OR team_id IN ($origemLista))");
    $chat = $pdo->prepare("UPDATE whatsapp_chats SET team_id = :t, updated_at = updated_at WHERE linked_card_id = :id AND account_id = :a AND (team_id IS NULL OR team_id IN ($origemLista))");
    $nCards = 0; $nChats = 0;
    foreach ($cards as $c) {
        $t = $destino[(int) $c['id']] ?? null;
        $card->execute([':t' => $t, ':id' => $c['id'], ':a' => $conta]); $nCards += $card->rowCount();
        $chat->execute([':t' => $t, ':id' => $c['id'], ':a' => $conta]); $nChats += $chat->rowCount();
    }
    // Setor de origem que ficou vazio some.
    $apagados = 0;
    foreach ($origemIds as $oid) {
        $resta = $pdo->prepare('SELECT (SELECT COUNT(*) FROM cards WHERE team_id = ? AND deleted_at IS NULL) + (SELECT COUNT(*) FROM whatsapp_chats WHERE team_id = ?)');
        $resta->execute([$oid, $oid]);
        if ((int) $resta->fetchColumn() === 0) { Team::delete($oid, $conta); $apagados++; }
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    exit('Erro, nada gravado: ' . $e->getMessage() . "\n");
}
echo "\nGravado: $nCards lead(s), $nChats conversa(s); $apagados setor(es) de origem excluído(s).\n";
