<?php
/**
 * scripts/tests/segmento_lead_test.php — App\WhatsAppAgente\SegmentoLead e o encaixe em
 * SdrFleetiflow::completarLead (nome e setor do lead que acaba de nascer).
 *
 * 1) Regra pura: a saudação "Oi|Olá, NOME!" vira o nome; "pessoal da" sai; genérico e
 *    fora do molde é descartado; abertura precisa ter tamanho de abertura; o setor sai
 *    da família de palavras (ou do tipo do lead) e só entre os setores da conta; duas
 *    famílias no mesmo texto é ambíguo.
 * 2) Fluxo no banco (conta CRM de teste): a mensagem enviada chega, o card nasce, e
 *    ganha nome e setor (e a conversa herda o setor); resposta curta de quem atende
 *    não dá nome; nome e setor já escolhidos nunca são trocados. Apaga o que criou.
 *
 * Uso: php scripts/tests/segmento_lead_test.php   (fluxo precisa da migration 140)
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\Usuarios\Team;
use App\WhatsAppAgente\SdrFleetiflow;
use App\WhatsAppAgente\SegmentoLead as S;

$ok = 0; $falhas = 0;
function ok(string $nome, bool $cond): void {
    global $ok, $falhas;
    if ($cond) { $ok++; echo "  [ok]   $nome\n"; } else { $falhas++; echo "  [FALHA] $nome\n"; }
}

echo "== 1. Nome pela saudação ==\n";
ok('Olá, NOME! pega o nome inteiro', S::nomePelaSaudacao('Olá, Sampaio e Dellova Campos Advogados! Tudo bem? Aqui é a Isa, da Inovaize.') === 'Sampaio e Dellova Campos Advogados');
ok('Oi, NOME! também', S::nomePelaSaudacao("Oi, Advocacia Carolina Rockenbach! Tudo certo? Aqui é a Isa.\n\nPosso te fazer uma pergunta?") === 'Advocacia Carolina Rockenbach');
ok('"pessoal da" é tirado', S::nomePelaSaudacao('Oi, pessoal da Avance Motors! Aqui é a Vitória, da Fleetiflow') === 'Avance Motors');
ok('título e ponto no nome ficam (Dr.)', S::nomePelaSaudacao('Olá, Dr. Rafael Nascimento advocacia! Tudo bem?') === 'Dr. Rafael Nascimento advocacia');
ok('sem vírgula depois do Oi', S::nomePelaSaudacao('Oi Clínica Bela! Tudo bem?') === 'Clínica Bela');
ok('espaços repetidos se juntam', S::nomePelaSaudacao('Oi,   Studio   Lima! Tudo bem?') === 'Studio Lima');
ok('"Olá! Tudo bem?" não tem nome', S::nomePelaSaudacao('Olá! Tudo bem? Aqui é a Isa') === '');
ok('saudação genérica é descartada', S::nomePelaSaudacao('Oi, tudo bem? Aqui é a Isa, da Inovaize!') === '' && S::nomePelaSaudacao('Olá, pessoal! Tudo bem?') === '');
ok('fora do molde não vale', S::nomePelaSaudacao('Bom dia, Fulano! Tudo bem?') === '' && S::nomePelaSaudacao('') === '');
ok('só número não é nome', S::nomePelaSaudacao('Oi, 1234! Tudo bem?') === '');
ok('resposta curta de quem atende não é abertura', S::nomeDaAbertura('Olá, Sampaio! Obrigado pelo retorno') === '');
ok('abertura de verdade é', S::nomeDaAbertura('Olá, Clínica Bela! Tudo bem? Aqui é a Isa, da Inovaize. Estou entrando em contato porque ajudamos clínicas.') === 'Clínica Bela');

echo "\n== 2. Setor pelo segmento ==\n";
$setores = [13 => 'Advocacia', 14 => 'Estética', 99 => 'Outro'];
ok('chave tira acento e caixa', S::chave('Estética') === 'estetica' && S::chave(' ADVOCACIA ') === 'advocacia');
ok('"escritório" e Yuris: Advocacia', S::setorPelaMensagem('Hoje, de onde vem os novos clientes do escritório? Criamos o Yuris.', $setores) === 13);
ok('"clínica": Estética', S::setorPelaMensagem('Encontrei a clínica de vocês e resolvi entrar em contato', $setores) === 14);
ok('duas famílias é ambíguo', S::setorPelaMensagem('Ajudamos clínicas e escritórios', $setores) === null);
ok('duas famílias é ambíguo mesmo com um setor só', S::setorPelaMensagem('Ajudamos clínicas e escritórios', [13 => 'Advocacia']) === null);
ok('nenhuma palavra: sem setor', S::setorPelaMensagem('Olá, tudo bem?', $setores) === null);
ok('conta sem o setor do segmento: sem setor', S::setorPelaMensagem('Encontrei a clínica de vocês', [13 => 'Advocacia']) === null);
ok('setor sem regra (Fleet) nunca é sugerido', S::setorPelaMensagem('Oi, Jeep Dahruj! Aqui é a Vitória, da Fleetiflow', [11 => 'Concessionária', 12 => 'Locação']) === null);
ok('tipo do lead: Clínica de Estética', S::setorPeloTipo('Clínica de Estética', $setores) === 14 && S::setorPeloTipo('Advocacia', $setores) === 13);
ok('tipo vazio: sem setor', S::setorPeloTipo('', $setores) === null && S::setorPeloTipo(null, $setores) === null);

echo "\n== 3. Fluxo: a mensagem chega, o card nasce com nome e setor ==\n";
$pdo = Database::getConnection();
$ACC = 476; $INST = 15; $P = '551998887';
$FONES = array_map(fn($i) => $P . '000' . $i, [1, 2, 3, 4, 5]); // 13 dígitos, só estes são apagados
$conta = \App\Master\Account::findById($ACC);
$inst = $pdo->prepare('SELECT account_id FROM whatsapp_instances WHERE id = ?');
$inst->execute([$INST]);
if (!$conta || \App\Master\Account::moduloJuridicoDisponivel($conta) || (int)$inst->fetchColumn() !== $ACC || !Team::temColuna('cards', 'team_id')) {
    echo "  (pulado: precisa da conta CRM de teste $ACC, da instância $INST e da migration 140)\n";
} else {
    $limpa = function () use ($pdo, $ACC, $INST, $FONES) {
        foreach ($FONES as $f) {
            $pdo->prepare('DELETE FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ?')->execute([$INST, $f . '@s.whatsapp.net']);
            $pdo->prepare('DELETE FROM cards WHERE account_id = ? AND telefone_whatsapp = ?')->execute([$ACC, $f]);
        }
    };
    $limpa();
    $criados = [];
    $setor = function (string $nome) use ($pdo, $ACC, &$criados): int {
        $st = $pdo->prepare('SELECT id FROM teams WHERE account_id = ? AND nome = ? AND deleted_at IS NULL LIMIT 1');
        $st->execute([$ACC, $nome]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id) return $id;
        $id = Team::create(['account_id' => $ACC, 'nome' => $nome, 'cor' => '#2563EB', 'descricao' => 'teste segmento_lead']);
        $criados[] = $id;
        return $id;
    };
    try {
        $adv = $setor('Advocacia');
        $est = $setor('Estética');
        $chat = function (string $jid) use ($pdo, $ACC, $INST) {
            $pdo->prepare('INSERT INTO whatsapp_chats (instance_id, account_id, remote_jid, contact_name, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())')
                ->execute([$INST, $ACC, $jid, '']);
        };
        $card = function (string $fone) use ($pdo, $ACC) {
            $st = $pdo->prepare('SELECT * FROM cards WHERE account_id = ? AND telefone_whatsapp = ? AND deleted_at IS NULL LIMIT 1');
            $st->execute([$ACC, $fone]);
            return $st->fetch(PDO::FETCH_ASSOC) ?: null;
        };
        $chatTeam = function (string $jid) use ($pdo, $INST) {
            $st = $pdo->prepare('SELECT team_id FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ?');
            $st->execute([$INST, $jid]);
            $v = $st->fetchColumn();
            return ($v === null || $v === false) ? null : (int)$v;
        };
        $abertura = fn(string $nome, string $miolo) => "Olá, $nome! Tudo bem? Aqui é a Isa, da Inovaize.\n\nEstou entrando em contato porque $miolo e gostaria de entender como vocês trabalham hoje.";

        // A) abertura de advocacia
        $f1 = $P . '0001'; $j1 = $f1 . '@s.whatsapp.net'; $chat($j1);
        SdrFleetiflow::aoMensagem($ACC, $INST, $j1, [], true, null, null, time(), $abertura('Studio Teste Advocacia SC', 'trabalhamos com escritórios de advocacia'));
        $c = $card($f1);
        ok('A) o card nasce', $c !== null);
        ok('A) ganha o nome da saudação (cliente e empresa)', $c && $c['cliente_nome'] === 'Studio Teste Advocacia SC' && $c['empresa_nome'] === 'Studio Teste Advocacia SC');
        ok('A) ganha o setor Advocacia', $c && (int)$c['team_id'] === $adv);
        ok('A) a conversa herda o setor', $chatTeam($j1) === $adv);

        // B) abertura de estética
        $f2 = $P . '0002'; $j2 = $f2 . '@s.whatsapp.net'; $chat($j2);
        SdrFleetiflow::aoMensagem($ACC, $INST, $j2, [], true, null, null, time(), $abertura('Clínica Teste Bela', 'ajudamos clínicas a estruturar o comercial'));
        $c = $card($f2);
        ok('B) Estética pela palavra "clínica", com o nome', $c && (int)$c['team_id'] === $est && $c['cliente_nome'] === 'Clínica Teste Bela');

        // C) resposta curta de quem atende: não é abertura
        $f3 = $P . '0003'; $j3 = $f3 . '@s.whatsapp.net'; $chat($j3);
        SdrFleetiflow::aoMensagem($ACC, $INST, $j3, [], true, null, null, time(), 'Olá, Maria! Obrigado pelo retorno');
        $c = $card($f3);
        ok('C) resposta curta não dá nome (segue o telefone)', $c && !preg_match('/\p{L}/u', (string)$c['cliente_nome']) && trim((string)$c['empresa_nome']) === '');
        ok('C) sem palavra de segmento, sem setor', $c && $c['team_id'] === null && $chatTeam($j3) === null);

        // D) o que alguém escolheu não é trocado
        $f4 = $P . '0004'; $j4 = $f4 . '@s.whatsapp.net'; $chat($j4);
        $cid = SdrFleetiflow::garantirCard($ACC, $INST, $j4, $f4, 'Nome Manual Ltda');
        Team::definirSetorDoCard($ACC, (int)$cid, $adv);
        SdrFleetiflow::aoMensagem($ACC, $INST, $j4, [], true, null, null, time(), $abertura('Clínica Outra', 'ajudamos clínicas a estruturar o comercial'));
        $c = $card($f4);
        ok('D) nome já escrito não é trocado', $c && $c['cliente_nome'] === 'Nome Manual Ltda');
        ok('D) setor já escolhido não é trocado', $c && (int)$c['team_id'] === $adv);

        // E) conta sem setor do segmento: nada é inventado
        $pdo->prepare('UPDATE teams SET deleted_at = NOW() WHERE id IN (?, ?)')->execute([$adv, $est]);
        $f5 = $P . '0005'; $j5 = $f5 . '@s.whatsapp.net'; $chat($j5);
        SdrFleetiflow::aoMensagem($ACC, $INST, $j5, [], true, null, null, time(), $abertura('Studio Sem Setor', 'trabalhamos com escritórios de advocacia'));
        $c = $card($f5);
        ok('E) sem o setor cadastrado, o nome entra e o setor não é inventado', $c && $c['cliente_nome'] === 'Studio Sem Setor' && $c['team_id'] === null);
        $pdo->prepare('UPDATE teams SET deleted_at = NULL WHERE id IN (?, ?)')->execute([$adv, $est]);
    } finally {
        $limpa();
        foreach ($criados as $id) $pdo->prepare('DELETE FROM teams WHERE id = ?')->execute([$id]);
    }
    echo "  histórico de auditoria permanece (imutável por trigger), órfão e invisível\n";
}

echo "\n----\nResultado: $ok ok · $falhas falha(s)\n";
exit($falhas > 0 ? 1 : 0);
