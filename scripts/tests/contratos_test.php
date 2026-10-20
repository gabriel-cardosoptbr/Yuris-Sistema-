<?php
/**
 * contratos_test.php: contratos e assinatura eletrônica (app/Contratos, migration 151).
 *
 *   - identificadores: UUID v4, código de verificação, token de 256 bits, CPF;
 *   - o fluxo inteiro com três participantes (contratante com CPF esperado,
 *     representante de empresa, testemunha), WhatsApp falso;
 *   - token inválido, expirado e revogado; código errado, esgotado e reutilizado;
 *   - pular a confirmação; CPF divergente bloqueia, a equipe revisa e libera;
 *   - clique repetido e chamada com outra chave de idempotência: uma assinatura só;
 *   - nova versão: a anterior fica substituída, com a assinatura preservada, e a
 *     sessão antiga cai; todos assinam de novo e o contrato conclui;
 *   - comprovante gerado; PDF de conclusão e manifesto quando há qpdf;
 *   - gatilhos barram UPDATE/DELETE em assinatura e evento; versão fechada não troca
 *     de hash; a corrente de eventos confere; outra conta não enxerga nada;
 *   - cancelamento barra novas assinaturas sem apagar as feitas.
 *
 * Usa a conta CRM local 304 (e a 476 como "outra empresa"). Linhas marcadas
 * ZZTESTE_CTR_<pid>. Como assinatura e evento não se apagam (gatilho), a limpeza
 * tira os gatilhos, apaga e recria: SÓ roda fora de produção.
 * Uso: php -d extension=sodium scripts/tests/contratos_test.php
 */
if (PHP_SAPI !== 'cli') exit;

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Contratos\Armazenamento;
use App\Contratos\Assinatura;
use App\Contratos\Contrato;
use App\Contratos\Cpf;
use App\Contratos\Cripto;
use App\Contratos\Entrega;
use App\Contratos\Eventos;
use App\Contratos\Finalizacao;
use App\Contratos\Manifesto;
use App\Contratos\PdfSimples;
use App\Core\Database;
use App\Core\EnvLoader;

$OK = 0;
$FALHAS = [];
function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}
function recusa(callable $f): ?string
{
    try { $f(); return null; } catch (\InvalidArgumentException $e) { return $e->getMessage(); }
}

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
if (strtolower(EnvLoader::get('APP_ENV')) === 'production') { echo "Não roda em produção.\n"; exit(1); }

$ACC = 304;
$OUTRA = 476;
$UID = (int) $pdo->query("SELECT id FROM users WHERE account_id = $ACC AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$MARCA = 'ZZTESTE_CTR_' . getmypid();
// Todo contrato mora num card do CRM (20/10/2026): um lead e um cliente da conta de teste.
$CARD = $pdo->query("SELECT id, responsavel_user_id FROM cards WHERE account_id = $ACC AND deleted_at IS NULL AND responsavel_user_id IS NOT NULL ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$CLI = (int) $pdo->query("SELECT id FROM clientes WHERE account_id = $ACC AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
$UID_OUTRA = (int) $pdo->query("SELECT id FROM users WHERE account_id = $OUTRA AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
if (!$CARD || !$CLI) { echo "A conta $ACC precisa de um lead com responsável e de um cliente.\n"; exit(1); }

// WhatsApp falso: guarda as mensagens para o teste ler o link e o código.
$CAIXA = [];
Entrega::$enviador = function (int $acc, string $numero, string $texto) use (&$CAIXA) {
    $CAIXA[] = ['acc' => $acc, 'numero' => $numero, 'texto' => $texto];
    return ['ok' => true];
};
function ultimoCodigo(array $caixa, string $numero): ?string
{
    for ($i = count($caixa) - 1; $i >= 0; $i--) {
        if ($caixa[$i]['numero'] === $numero && preg_match('/é (\d{6})\./', $caixa[$i]['texto'], $m)) return $m[1];
    }
    return null;
}
function tokenDo(string $link): string { return substr($link, strpos($link, '#t=') + 3); }

$ip = '203.0.113.7';
$ua = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) teste';
$criados = [];

try {
    echo "\n== 1. Identificadores e CPF ==\n";
    $u = Cripto::uuid();
    ok('UUID v4', (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $u));
    ok('UUIDs não se repetem', Cripto::uuid() !== Cripto::uuid());
    $t = Cripto::token();
    ok('token de 256 bits em base64url (43)', strlen($t) === 43 && strlen(base64_decode(strtr($t, '-_', '+/') . '=')) === 32);
    $cod = Cripto::codigoVerificacao();
    ok('código de verificação XXXX-XXXX-XXXX', (bool) preg_match('/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/', $cod));
    ok('código normaliza minúsculas, O e sem traço', Cripto::normalizarCodigoVerificacao(strtolower(str_replace('-', '', $cod))) === $cod);
    ok('CPF válido aceito', Cpf::valido('529.982.247-25'));
    ok('CPF com dígito errado recusado', !Cpf::valido('529.982.247-26'));
    ok('CPF repetido recusado', !Cpf::valido('111.111.111-11'));
    ok('CPF mascarado', Cpf::mascarar('52998224725') === '***.982.247-**');
    ok('código de uso único tem 6 dígitos', (bool) preg_match('/^\d{6}$/', Cripto::codigoUnico()));

    echo "\n== 2. Criar, anexar e convidar ==\n";
    $pdf = new PdfSimples('Contrato de teste');
    $pdf->texto('Contrato de prestação de serviços (teste)', 14, true);
    $pdf->texto('Cláusula 1. O contratado presta o serviço; o contratante paga. ' . $MARCA);
    $v1 = $pdf->render();

    ok('sem título é recusado', recusa(fn() => Contrato::criar($ACC, $UID, ['titulo' => '', 'card_id' => $CARD['id']])) !== null);
    ok('sem lead nem cliente é recusado', recusa(fn() => Contrato::criar($ACC, $UID, ['titulo' => "$MARCA Solto"])) !== null);
    ok('vendedor de outra conta é recusado', $UID_OUTRA === 0
        || recusa(fn() => Contrato::criar($ACC, $UID, ['titulo' => "$MARCA Vend", 'card_id' => $CARD['id'], 'vendedor_id' => $UID_OUTRA])) !== null);
    $id = Contrato::criar($ACC, $UID, ['titulo' => "$MARCA Prestação de serviços", 'card_id' => $CARD['id'], 'prazo' => date('Y-m-d', strtotime('+10 days'))]);
    $criados[] = $id;
    $fx = Contrato::ficha($ACC, $id);
    ok('sem escolha, o vendedor é o responsável do lead', $fx['vendedor_id'] === (int) $CARD['responsavel_user_id'] && $fx['vendedor'] !== null);
    ok('lista traz o vendedor', (function () use ($ACC, $id, $CARD) {
        foreach (Contrato::listar($ACC, ['card_id' => $CARD['id']]) as $l) if ($l['id'] === $id) return $l['vendedor_id'] === (int) $CARD['responsavel_user_id'];
        return false;
    })());
    ok('tirar o cliente de contrato sem lead é recusado', (function () use ($ACC, $UID, $CLI, $MARCA, &$criados) {
        $x = Contrato::criar($ACC, $UID, ['titulo' => "$MARCA Só cliente", 'cliente_id' => $CLI]);
        $criados[] = $x;
        return recusa(fn() => Contrato::vincularCliente($ACC, $UID, $x, 0)) !== null;
    })());
    ok('contrato criado em rascunho', Contrato::doContrato($ACC, $id)['status'] === 'rascunho');
    ok('arquivo que não é PDF é recusado', recusa(fn() => Contrato::anexarPdf($ACC, $UID, $id, 'não sou pdf', 'x.pdf')) !== null);
    ok('enviar sem PDF é recusado', recusa(fn() => Contrato::fecharEEnviar($ACC, $UID, $id, true)) !== null);
    $an = Contrato::anexarPdf($ACC, $UID, $id, $v1, 'contrato.pdf');
    ok('SHA-256 calculado dos bytes', $an['sha256'] === hash('sha256', $v1));

    ok('representante sem CNPJ é recusado', recusa(fn() => Contrato::salvarParticipante($ACC, $UID, $id, ['papel' => 'representante', 'nome' => 'Rita Sem Empresa', 'telefone' => '11 98888-0002'])) !== null);
    $p1 = Contrato::salvarParticipante($ACC, $UID, $id, ['papel' => 'contratante', 'nome' => 'Carla Contratante da Silva', 'telefone' => '11 98888-0001', 'cpf' => '529.982.247-25']);
    $p2 = Contrato::salvarParticipante($ACC, $UID, $id, ['papel' => 'representante', 'nome' => 'Rui Representante Souza', 'telefone' => '11 98888-0002',
        'empresa' => 'Empresa Teste Ltda', 'cnpj' => '11.222.333/0001-81', 'funcao' => 'Sócio administrador']);
    $p3 = Contrato::salvarParticipante($ACC, $UID, $id, ['papel' => 'testemunha', 'nome' => 'Tina Testemunha Lima', 'telefone' => '11 98888-0003']);
    $tel = fn($pid) => Cripto::decifrar($pdo->query("SELECT telefone_cifrado FROM crm_contrato_participantes WHERE id = $pid")->fetchColumn());
    ok('telefone guardado cifrado', !str_contains((string) $pdo->query("SELECT telefone_cifrado FROM crm_contrato_participantes WHERE id = $p1")->fetchColumn(), '98888'));
    ok('CPF esperado guardado cifrado e mascarado', $pdo->query("SELECT cpf_mascara FROM crm_contrato_participantes WHERE id = $p1")->fetchColumn() === '***.982.247-**');

    $env = Contrato::fecharEEnviar($ACC, $UID, $id, true);
    ok('três links, um por participante', count($env['links']) === 3);
    ok('os três convites mandados por WhatsApp', count(array_filter($env['links'], fn($l) => $l['enviado'] === true)) === 3);
    ok('o link leva o token no fragmento (#t=)', str_contains($env['links'][0]['link'], '/assinar#t='));
    $links = [];
    foreach ($env['links'] as $l) $links[$l['participante_id']] = tokenDo($l['link']);
    $c = Contrato::doContrato($ACC, $id);
    ok('contrato aguardando assinaturas', $c['status'] === 'aguardando');
    ok('o token não fica no banco (só o hash)', (int) $pdo->query("SELECT COUNT(*) FROM crm_contrato_convites WHERE token_hash = " . $pdo->quote($links[$p1]))->fetchColumn() === 0);
    $versao1 = (int) $c['versao_atual_id'];
    $erroTroca = null;
    try { $pdo->exec("UPDATE crm_contrato_versoes SET sha256 = REPEAT('0', 64) WHERE id = $versao1"); } catch (\PDOException $e) { $erroTroca = $e->getMessage(); }
    ok('versão aberta não troca de hash (gatilho)', $erroTroca !== null);

    echo "\n== 3. Link: inválido, sem pular a confirmação, código errado ==\n";
    ok('token inventado é recusado', recusa(fn() => Assinatura::abrir(Cripto::token(), $ip, $ua)) !== null);
    ok('token mal formado é recusado', recusa(fn() => Assinatura::abrir('abc', $ip, $ua)) !== null);
    $ab = Assinatura::abrir($links[$p1], $ip, $ua);
    $s = Assinatura::sessao($ab['cookie']);
    $e = Assinatura::estado($s);
    ok('antes do código: só o mínimo (sem CPF, sem documento)', $e['etapa'] === 'codigo' && !isset($e['documento']) && !isset($e['participante_completo']));
    ok('pular a confirmação: identificar recusado', recusa(fn() => Assinatura::identificar($s, 'Carla Contratante da Silva', '52998224725', false, $ip)) !== null);
    ok('pular a confirmação: assinar recusado', recusa(fn() => Assinatura::assinar($s, true, Cripto::uuid(), $ip, $ua)) !== null);
    ok('documento só depois do código', Assinatura::estado($s)['etapa'] === 'codigo');
    $r = Assinatura::enviarCodigo($s, $ip);
    ok('código enviado ao WhatsApp do convite', $r['enviado'] && ultimoCodigo($CAIXA, '5511988880001') !== null);
    ok('reenvio antes do intervalo pede para aguardar', isset(Assinatura::enviarCodigo($s, $ip)['aguardar']));
    $codigo1 = ultimoCodigo($CAIXA, '5511988880001');
    ok('o código não fica no banco (só o HMAC)', (int) $pdo->query("SELECT COUNT(*) FROM crm_contrato_desafios WHERE codigo_hmac = " . $pdo->quote($codigo1))->fetchColumn() === 0);
    $errado = $codigo1 === '000000' ? '111111' : '000000';
    $r = Assinatura::confirmarCodigo($s, $errado, $ip);
    ok('código errado: recusado e conta a tentativa', !$r['ok'] && $r['restantes'] === 4);
    $r = Assinatura::confirmarCodigo(Assinatura::sessao($ab['cookie']), $codigo1, $ip);
    ok('código certo confirma', $r['ok']);
    $s = Assinatura::sessao($ab['cookie']);
    ok('depois do código: identificação', Assinatura::estado($s)['etapa'] === 'identificacao');

    echo "\n== 4. Código reutilizado e tentativas esgotadas ==\n";
    $ab2 = Assinatura::abrir($links[$p1], $ip, $ua);
    $s2 = Assinatura::sessao($ab2['cookie']);
    $pdo->exec("UPDATE crm_contrato_desafios SET criado_em = DATE_SUB(criado_em, INTERVAL 5 MINUTE) WHERE convite_id = (SELECT convite_id FROM crm_contrato_sessoes WHERE id = {$s2['id']})");
    Assinatura::enviarCodigo($s2, $ip);
    $novo = ultimoCodigo($CAIXA, '5511988880001');
    $r = Assinatura::confirmarCodigo($s2, $codigo1 === $novo ? '999999' : $codigo1, $ip);
    ok('código antigo, já usado, não vale na sessão nova', !$r['ok']);
    for ($i = 0; $i < 4; $i++) Assinatura::confirmarCodigo(Assinatura::sessao($ab2['cookie']), $novo === '123456' ? '654321' : '123456', $ip);
    ok('tentativas esgotadas: nem o código certo passa', recusa(fn() => Assinatura::confirmarCodigo(Assinatura::sessao($ab2['cookie']), $novo, $ip)) !== null);

    echo "\n== 5. CPF divergente bloqueia; a equipe revisa e libera ==\n";
    $r = Assinatura::identificar($s, 'Carla Contratante da Silva', '111.444.777-35', false, $ip);
    ok('CPF diferente do esperado bloqueia', !$r['ok'] && !empty($r['bloqueado']));
    ok('participante fica bloqueado para revisão', Contrato::doParticipante($ACC, $id, $p1)['status'] === 'bloqueado');
    ok('o link bloqueado não abre mais', recusa(fn() => Assinatura::abrir($links[$p1], $ip, $ua)) !== null);
    ok('sem revisão, não sai novo link', recusa(fn() => Contrato::novoLink($ACC, $UID, $id, $p1, false)) !== null);
    ok('liberar exige explicar a revisão', recusa(fn() => Contrato::novoLink($ACC, $UID, $id, $p1, false, true, '')) !== null);
    $nl = Contrato::novoLink($ACC, $UID, $id, $p1, true, true, 'Conferido por telefone: digitou errado.');
    $links[$p1] = tokenDo($nl['link']);
    ok('liberado com novo link', Contrato::doParticipante($ACC, $id, $p1)['status'] === 'convidado');

    echo "\n== 6. Assinar (e clicar duas vezes) ==\n";
    $assinar = function (int $pid, string $numero, string $cpf, string $nome) use (&$links, &$CAIXA, $ip, $ua, $pdo) {
        $ab = Assinatura::abrir($links[$pid], $ip, $ua);
        $s = Assinatura::sessao($ab['cookie']);
        $pdo->exec("UPDATE crm_contrato_desafios SET criado_em = DATE_SUB(criado_em, INTERVAL 5 MINUTE) WHERE participante_id = $pid");
        Assinatura::enviarCodigo($s, $ip);
        Assinatura::confirmarCodigo(Assinatura::sessao($ab['cookie']), ultimoCodigo($CAIXA, $numero), $ip);
        Assinatura::identificar(Assinatura::sessao($ab['cookie']), $nome, $cpf, false, $ip);
        return $ab['cookie'];
    };
    $ck1 = $assinar($p1, '5511988880001', '52998224725', 'Carla Contratante da Silva');
    $s1 = Assinatura::sessao($ck1);
    ok('sem o aceite marcado, não assina', recusa(fn() => Assinatura::assinar($s1, false, Cripto::uuid(), $ip, $ua)) !== null);
    $idem = Cripto::uuid();
    $a1 = Assinatura::assinar($s1, true, $idem, $ip, $ua);
    ok('assinatura nova', $a1['nova'] && $a1['contrato'] === 'parcial');
    $a1b = Assinatura::assinar(Assinatura::sessao($ck1), true, $idem, $ip, $ua);
    ok('clique repetido devolve a mesma assinatura', !$a1b['nova'] && $a1b['uuid'] === $a1['uuid']);
    $a1c = Assinatura::assinar(Assinatura::sessao($ck1), true, Cripto::uuid(), $ip, $ua);
    ok('outra chave de idempotência também não duplica', !$a1c['nova'] && $a1c['uuid'] === $a1['uuid']);
    ok('uma assinatura só no banco', (int) $pdo->query("SELECT COUNT(*) FROM crm_contrato_assinaturas WHERE participante_id = $p1")->fetchColumn() === 1);
    ok('o link usado não abre de novo', recusa(fn() => Assinatura::abrir($links[$p1], $ip, $ua)) !== null);
    $ev = json_decode((string) $pdo->query("SELECT evidencia_json FROM crm_contrato_assinaturas WHERE participante_id = $p1")->fetchColumn(), true);
    ok('evidência: versão, hash, IP, navegador, método, contato e aceite', $ev['versao']['sha256'] === hash('sha256', $v1) && $ev['rede']['ip'] === $ip
        && $ev['confirmacao']['metodo'] === Assinatura::METODO && $ev['confirmacao']['contato'] === 'WhatsApp terminado em 0001'
        && $ev['aceite']['texto'] === Assinatura::ACEITE_TEXTO);
    ok('evidência sem o código e sem CPF completo', !str_contains(json_encode($ev), '52998224725') && !preg_match('/"codigo"/', json_encode($ev)));
    ok('CPF conferido com o convite e dado como declarado', $ev['signatario']['cpf_conferido_com_convite'] === true && $ev['signatario']['origem'] === 'autodeclarado');
    $erroUp = $erroDel = null;
    try { $pdo->exec("UPDATE crm_contrato_assinaturas SET ip = '1.1.1.1' WHERE participante_id = $p1"); } catch (\PDOException $e) { $erroUp = $e->getMessage(); }
    try { $pdo->exec("DELETE FROM crm_contrato_eventos WHERE contrato_id = $id"); } catch (\PDOException $e) { $erroDel = $e->getMessage(); }
    ok('assinatura não se altera (gatilho)', $erroUp !== null);
    ok('evento não se apaga (gatilho)', $erroDel !== null);

    echo "\n== 7. Troca de documento: nova versão ==\n";
    $abT = Assinatura::abrir($links[$p3], $ip, $ua);          // a testemunha abriu a versão 1 e não terminou
    $pdf2 = new PdfSimples('Contrato de teste v2');
    $pdf2->texto('Contrato de prestação de serviços (teste) - versão corrigida', 14, true);
    $pdf2->texto('Cláusula 1 corrigida. ' . $MARCA);
    $v2 = $pdf2->render();
    Contrato::anexarPdf($ACC, $UID, $id, $v2, 'contrato-v2.pdf');
    ok('com a v2 em rascunho, a v1 continua valendo', Contrato::doContrato($ACC, $id)['versao_atual_id'] === $versao1);
    $env2 = Contrato::fecharEEnviar($ACC, $UID, $id, false);
    foreach ($env2['links'] as $l) $links[$l['participante_id']] = tokenDo($l['link']);
    $c = Contrato::doContrato($ACC, $id);
    ok('contrato volta a aguardar, na versão 2', $c['status'] === 'aguardando' && (int) $c['versao_atual_id'] !== $versao1);
    ok('versão 1 marcada como substituída', $pdo->query("SELECT status FROM crm_contrato_versoes WHERE id = $versao1")->fetchColumn() === 'substituida');
    ok('assinatura da versão 1 preservada', (int) $pdo->query("SELECT COUNT(*) FROM crm_contrato_assinaturas WHERE versao_id = $versao1")->fetchColumn() === 1);
    ok('a sessão aberta na versão 1 caiu', Assinatura::validarSessao(Assinatura::sessao($abT['cookie'])) !== null);
    ok('quem assinou a v1 precisa assinar de novo', Contrato::doParticipante($ACC, $id, $p1)['status'] === 'convidado');

    echo "\n== 8. Todos assinam a versão 2; concluir ==\n";
    $c1 = $assinar($p1, '5511988880001', '52998224725', 'Carla Contratante da Silva');
    Assinatura::assinar(Assinatura::sessao($c1), true, Cripto::uuid(), $ip, $ua);
    $c2 = $assinar($p2, '5511988880002', '11144477735', 'Rui Representante Souza');
    // Duas "abas" da mesma pessoa assinando quase juntas.
    $sA = Assinatura::sessao($c2);
    $rA = Assinatura::assinar($sA, true, Cripto::uuid(), $ip, $ua);
    $rB = Assinatura::assinar($sA, true, Cripto::uuid(), $ip, $ua);
    ok('assinatura simultânea: uma só', $rA['uuid'] === $rB['uuid'] && (int) $pdo->query("SELECT COUNT(*) FROM crm_contrato_assinaturas WHERE participante_id = $p2")->fetchColumn() === 1);
    $evR = json_decode((string) $pdo->query("SELECT evidencia_json FROM crm_contrato_assinaturas WHERE participante_id = $p2")->fetchColumn(), true);
    ok('representante: empresa, CNPJ, função e o aviso sobre poderes', $evR['representacao']['cnpj'] === '11222333000181' && str_contains($evR['representacao']['observacao'], 'poderes'));
    $c3 = $assinar($p3, '5511988880003', '86288366757', 'Tina Testemunha Lima');
    $fim = Assinatura::assinar(Assinatura::sessao($c3), true, Cripto::uuid(), $ip, $ua);
    ok('a última assinatura obrigatória conclui o contrato', $fim['contrato'] === 'concluido');
    $c = Contrato::doContrato($ACC, $id);
    ok('contrato concluído', $c['status'] === 'concluido' && $c['concluido_em'] !== null);
    $temQpdf = (bool) @proc_open([EnvLoader::get('CONTRATOS_QPDF', 'qpdf'), '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp);
    $comp = $pdo->query("SELECT * FROM crm_contrato_arquivos WHERE contrato_id = $id AND tipo = 'comprovante'")->fetch(\PDO::FETCH_ASSOC);
    ok('comprovante gerado e guardado com hash', $comp && Armazenamento::existeIntegro($comp['caminho'], $comp['sha256']));
    if ($c['finalizacao_status'] === 'ok') {
        $conc = $pdo->query("SELECT * FROM crm_contrato_arquivos WHERE contrato_id = $id AND tipo = 'conclusao'")->fetch(\PDO::FETCH_ASSOC);
        $man = $pdo->query("SELECT * FROM crm_contrato_arquivos WHERE contrato_id = $id AND tipo = 'manifesto'")->fetch(\PDO::FETCH_ASSOC);
        $mj = json_decode((string) Armazenamento::ler($man['caminho'], $man['sha256']), true);
        ok('PDF de conclusão e manifesto gerados', $conc && $man);
        ok('manifesto registra o hash do PDF de conclusão', $mj['conteudo']['arquivos']['conclusao']['sha256'] === $conc['sha256']);
        ok('selo do manifesto confere', Manifesto::conferir($mj)['valido']);
        $mj['conteudo']['assinaturas'][0]['nome'] = 'Outra Pessoa';
        ok('manifesto alterado não confere', !Manifesto::conferir($mj)['valido']);
    } else {
        ok('sem qpdf a finalização fica pendente, com o motivo, sem perder assinaturas',
            !$temQpdf && $c['finalizacao_status'] === 'erro' && str_contains((string) $c['finalizacao_erro'], 'qpdf')
            && (int) $pdo->query("SELECT COUNT(*) FROM crm_contrato_assinaturas WHERE contrato_id = $id")->fetchColumn() === 4);
    }
    $cad = Eventos::verificar($id);
    ok('corrente de eventos íntegra', $cad['integra'] && $cad['quantidade'] > 20);
    // Vendedor e card mudam mesmo depois de assinado: é cadastro do CRM, não o documento.
    Contrato::vincularCrm($ACC, $UID, $id, ['cliente_id' => $CLI, 'vendedor_id' => $UID]);
    $fx = Contrato::ficha($ACC, $id);
    ok('assinado: troca vendedor e card no CRM', $fx['vendedor_id'] === $UID && $fx['cliente_id'] === $CLI && $fx['card_id'] === null && $fx['status'] === 'concluido');
    ok('vínculo no CRM sem card é recusado', recusa(fn() => Contrato::vincularCrm($ACC, $UID, $id, ['vendedor_id' => $UID])) !== null);
    ok('trilha continua íntegra depois do vínculo', Eventos::verificar($id)['integra']);

    echo "\n== 9. Expirado, revogado, cancelado e outra empresa ==\n";
    $id2 = Contrato::criar($ACC, $UID, ['titulo' => "$MARCA Segundo", 'cliente_id' => $CLI]);
    $criados[] = $id2;
    Contrato::anexarPdf($ACC, $UID, $id2, $v1, 'contrato.pdf');
    $q1 = Contrato::salvarParticipante($ACC, $UID, $id2, ['papel' => 'contratante', 'nome' => 'Pedro Prazo Vencido', 'telefone' => '11 98888-0004']);
    $q2 = Contrato::salvarParticipante($ACC, $UID, $id2, ['papel' => 'contratado', 'nome' => 'Paula Contratada Reis', 'telefone' => '11 98888-0005']);
    $e2 = Contrato::fecharEEnviar($ACC, $UID, $id2, false);
    $tk = [];
    foreach ($e2['links'] as $l) $tk[$l['participante_id']] = tokenDo($l['link']);
    $pdo->exec("UPDATE crm_contrato_convites SET expira_em = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE participante_id = $q1");
    ok('token expirado é recusado', str_contains((string) recusa(fn() => Assinatura::abrir($tk[$q1], $ip, $ua)), 'venceu'));
    $antigo = $tk[$q2];
    Contrato::novoLink($ACC, $UID, $id2, $q2, false);
    ok('token revogado por reenvio é recusado', recusa(fn() => Assinatura::abrir($antigo, $ip, $ua)) !== null);
    ok('outra empresa não vê o contrato', recusa(fn() => Contrato::ficha($OUTRA, $id2)) !== null);
    ok('outra empresa não cancela', recusa(fn() => Contrato::cancelar($OUTRA, $UID, $id2, 'x')) !== null);
    ok('outra empresa não mexe em participante', recusa(fn() => Contrato::salvarParticipante($OUTRA, $UID, $id2, ['id' => $q2, 'papel' => 'contratado', 'nome' => 'Invasor Nome', 'telefone' => '11999999999'])) !== null);
    ok('lista da outra empresa não traz este contrato', !in_array($id2, array_column(Contrato::listar($OUTRA), 'id'), true));
    Contrato::cancelar($ACC, $UID, $id2, 'Teste de cancelamento');
    ok('cancelado barra novas assinaturas', recusa(fn() => Contrato::novoLink($ACC, $UID, $id2, $q2, false)) !== null);
    ok('cancelar não apaga assinatura do outro contrato', (int) $pdo->query("SELECT COUNT(*) FROM crm_contrato_assinaturas WHERE contrato_id = $id")->fetchColumn() === 4);
    $cen = Contrato::central($ACC);
    ok('central conta contratos e bytes', $cen['total'] >= 2 && $cen['bytes_total'] > 0 && $cen['por_status']['concluido'] >= 1);

    echo "
== 10. Ficha do cliente: vincular, documentos e PDF anexado ==
";
    $cliT = (int) $pdo->query("SELECT id FROM clientes WHERE account_id = $ACC AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $cliOutra = (int) $pdo->query("SELECT id FROM clientes WHERE account_id <> $ACC AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    if ($cliT) {
        Contrato::vincularCliente($ACC, $UID, $id, $cliT);
        $docs = array_column(Contrato::documentosDaFicha($ACC, $cliT, []), null, 'id');
        $tipos = array_column($docs[$id]['arquivos'] ?? [], 'tipo');
        ok('contrato vinculado aparece nos documentos da ficha', isset($docs[$id]));
        ok('documentos trazem o comprovante e o enviado (e o assinado, com qpdf)', in_array('comprovante', $tipos, true) && in_array('original', $tipos, true)
            && (in_array('conclusao', $tipos, true) || !$temQpdf));
        ok('outra empresa não vê os documentos do cliente', !in_array($id, array_column(Contrato::documentosDaFicha($OUTRA, $cliT, []), 'id'), true));
        ok('outra empresa não vincula', recusa(fn() => Contrato::vincularCliente($OUTRA, $UID, $id, $cliT)) !== null);
        if ($cliOutra) ok('cliente de outra empresa não é vinculado', recusa(fn() => Contrato::vincularCliente($ACC, $UID, $id, $cliOutra)) !== null);
        $id3 = Contrato::criarDeAnexo($ACC, $UID, ['entidade' => 'cliente', 'entidade_id' => $cliT, 'file_name' => 'Proposta do site.pdf', 'descricao' => ''], $v1);
        $criados[] = $id3;
        $c3 = Contrato::doContrato($ACC, $id3);
        $d3 = array_column(Contrato::documentosDaFicha($ACC, $cliT, []), null, 'id');
        ok('PDF anexado vira contrato ligado ao cliente, com o documento', (int) $c3['cliente_id'] === $cliT && $c3['titulo'] === 'Proposta do site'
            && in_array('original', array_column($d3[$id3]['arquivos'] ?? [], 'tipo'), true));
        // Com o lead ligado, tirar o cliente pode: o contrato continua num card do CRM.
        Contrato::vincularCrm($ACC, $UID, $id, ['card_id' => $CARD['id'], 'cliente_id' => $cliT]);
        Contrato::vincularCliente($ACC, $UID, $id, 0);
        ok('desvincular tira da ficha', !in_array($id, array_column(Contrato::documentosDaFicha($ACC, $cliT, []), 'id'), true));
    }
} catch (\Throwable $e) {
    $FALHAS[] = 'exceção: ' . $e->getMessage();
    echo "  [FALHA] exceção: " . get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
} finally {
    // Limpeza: assinatura e evento são imutáveis por gatilho; fora de produção, tira, apaga e recria.
    if ($criados) {
        $lista = implode(',', array_map('intval', $criados));
        foreach (['trg_ctr_assinatura_sem_update', 'trg_ctr_assinatura_sem_delete', 'trg_ctr_evento_sem_update', 'trg_ctr_evento_sem_delete',
                  'trg_ctr_arquivo_sem_update', 'trg_ctr_arquivo_sem_delete', 'trg_ctr_versao_fechada', 'trg_ctr_versao_sem_delete'] as $g) {
            $pdo->exec("DROP TRIGGER IF EXISTS $g");
        }
        $pdo->exec("DELETE FROM crm_contrato_desafios WHERE convite_id IN (SELECT id FROM crm_contrato_convites WHERE contrato_id IN ($lista))");
        foreach (['crm_contrato_assinaturas', 'crm_contrato_eventos', 'crm_contrato_sessoes', 'crm_contrato_convites',
                  'crm_contrato_participantes', 'crm_contrato_versoes', 'crm_contrato_arquivos'] as $t) {
            $pdo->exec("DELETE FROM $t WHERE contrato_id IN ($lista)");
        }
        $pdo->exec("DELETE FROM crm_contratos WHERE id IN ($lista)");
        ob_start();
        $argv = ['run_151.php'];
        require __DIR__ . '/../../database/migrations/run_151.php';
        ob_end_clean();
        $n = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME LIKE 'trg_ctr_%'")->fetchColumn();
        echo "\n  (limpeza: contratos de teste apagados, $n gatilhos recriados)\n";
    }
}

echo "\n" . ($FALHAS ? count($FALHAS) . ' FALHA(S): ' . implode(' | ', $FALHAS) : "$OK ok/0") . "\n";
exit($FALHAS ? 1 : 0);
