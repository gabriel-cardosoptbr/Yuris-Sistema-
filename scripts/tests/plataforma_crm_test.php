<?php
/**
 * plataforma_crm_test.php: a integração do CRM com a plataforma Fleetiflow
 * (app/Plataforma, migration 144).
 *
 *   - o código de conexão (FFC1.) abre e recusa o que não é dele;
 *   - o evento só vale com a assinatura certa e o timestamp recente;
 *   - cada evento vira texto legível para a nota do card;
 *   - o mesmo evento entregue duas vezes não grava duas vezes;
 *   - com vínculo, o evento vai para o lead: nota no histórico e aviso ao
 *     responsável quando é pedido da conta; sem vínculo, fica guardado.
 *
 * Não chama a plataforma de verdade: a conexão de teste é gravada à mão. Linhas
 * marcadas com ZZTESTE_PLA_<pid>, apagadas no fim (o lead de teste entra por
 * INSERT direto, sem histórico, e sai de vez).
 * Uso: php scripts/tests/plataforma_crm_test.php
 */
if (PHP_SAPI !== 'cli') exit;

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Crypto;
use App\Core\Database;
use App\Plataforma\Conexao;
use App\Plataforma\Eventos;
use App\Plataforma\Vinculo;

$OK = 0;
$FALHAS = [];
function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}
function uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

echo "\n== 1. Código de conexão e textos (sem banco) ==\n";
$conteudo = rtrim(strtr(base64_encode(json_encode(['u' => 'https://fleetiflow.com.br/api/integracao/v1/', 'k' => 'ffc_abc123'])), '+/', '-_'), '=');
$aberto = Conexao::abrirCodigo('  FFC1.' . $conteudo . "\n");
ok('abre o código e tira a barra do fim do endereço', $aberto === ['api_url' => 'https://fleetiflow.com.br/api/integracao/v1', 'chave' => 'ffc_abc123']);
ok('recusa sem o prefixo FFC1.', Conexao::abrirCodigo($conteudo) === null);
ok('recusa chave que não é ffc_', Conexao::abrirCodigo('FFC1.' . rtrim(strtr(base64_encode(json_encode(['u' => 'https://x', 'k' => 'ffk_x'])), '+/', '-_'), '=')) === null);
ok('recusa lixo', Conexao::abrirCodigo('FFC1.@@@') === null);
ok('código da conta: normaliza e confere o formato', Vinculo::normalizarCodigo(' flt-8hhk-mkrn ') === 'FLT-8HHK-MKRN' && Vinculo::codigoValido('FLT-8HHK-MKRN') && !Vinculo::codigoValido('FLT-123'));

[$a, $t] = Eventos::descrever(['tipo' => 'solicitacao.criada', 'origem' => ['tipo' => 'conta'], 'dados' => ['solicitacao' => [
    'tipo' => 'MODULO', 'moduloNome' => 'Gestão de Frota', 'mensagem' => 'A partir de novembro', 'solicitadoPorNome' => 'Marina',
]]]);
ok('pedido de módulo vira nota legível', $a === 'Pedido na plataforma' && str_contains($t, 'módulo Gestão de Frota') && str_contains($t, 'A partir de novembro') && str_contains($t, 'Marina'));
[$a, $t] = Eventos::descrever(['tipo' => 'recarga.aprovada', 'dados' => ['recarga' => ['valor' => 50, 'metodo' => 'PIX'], 'saldo' => 120.5]]);
ok('recarga aprovada com valor e saldo', $a === 'Recarga aprovada' && str_contains($t, 'R$ 50,00 por Pix') && str_contains($t, 'R$ 120,50'));
[$a, $t] = Eventos::descrever(['tipo' => 'plano.alterado', 'origem' => ['tipo' => 'master', 'nome' => 'Gabriel'], 'dados' => ['plano' => 'Plano Pro', 'valorMensal' => 499, 'anterior' => 'Essencial']]);
ok('plano alterado diz o novo, o antigo e quem mudou', str_contains($t, 'Plano Pro (R$ 499,00/mês), antes Essencial (pelo Master, Gabriel)'));
[$a] = Eventos::descrever(['tipo' => 'conta.status', 'dados' => ['ativa' => false]]);
ok('conta suspensa', $a === 'Conta suspensa');
ok('situação: suspensa, teste e ativa', Vinculo::situacaoDe(['conta' => ['ativa' => false]]) === 'suspensa'
    && Vinculo::situacaoDe(['conta' => ['ativa' => true], 'assinatura' => ['status' => 'TRIAL']]) === 'teste'
    && Vinculo::situacaoDe(['conta' => ['ativa' => true], 'assinatura' => null]) === 'ativa');

// A parte com banco GRAVA (linhas marcadas, apagadas no fim). No servidor, que
// roda em Docker e é produção, só com TESTE_NO_SERVIDOR=1.
if (file_exists('/.dockerenv') && getenv('TESTE_NO_SERVIDOR') !== '1') {
    echo "\n  [pulado] no servidor (produção) a parte com banco não roda\n";
    echo "\nResultado: $OK ok · " . count($FALHAS) . " falha(s)\n";
    exit($FALHAS ? 1 : 0);
}

$pdo = null;
try {
    $pdo = Database::getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $temTabela = (bool) $pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'plataforma_eventos'")->fetchColumn();
    // Uma conta com usuário e funil e SEM conexão de verdade: a do teste é gravada e apagada aqui.
    $conta = $temTabela ? $pdo->query("SELECT a.id FROM accounts a WHERE a.deleted_at IS NULL
        AND EXISTS (SELECT 1 FROM users u WHERE u.account_id = a.id AND u.status = 'active' AND u.deleted_at IS NULL)
        AND EXISTS (SELECT 1 FROM pipeline_columns c WHERE c.account_id = a.id)
        AND NOT EXISTS (SELECT 1 FROM plataforma_conexoes p WHERE p.account_id = a.id) ORDER BY a.id LIMIT 1")->fetchColumn() : null;
    Crypto::encrypt('teste');
} catch (\Throwable $e) {
    echo '  [pulado] sem banco ou sem APP_ENCRYPTION_KEY: ' . $e->getMessage() . "\n";
    $temTabela = false; $conta = null;
}

if (!$pdo || !$temTabela || !$conta) {
    echo "\n  [pulado] precisa do banco, da migration 144 e de uma conta com usuário e funil\n";
} else {
    $acc = (int) $conta;
    $eu = (int) $pdo->query("SELECT id FROM users WHERE account_id = $acc AND status = 'active' AND deleted_at IS NULL ORDER BY id LIMIT 1")->fetchColumn();
    $marca = 'ZZTESTE_PLA_' . getmypid();
    $col = (int) $pdo->query("SELECT id FROM pipeline_columns WHERE account_id = $acc ORDER BY ordem, id LIMIT 1")->fetchColumn();
    $segredo = bin2hex(random_bytes(32));
    $card = 0;
    $siteCards = [];
    try {
        $pdo->prepare('INSERT INTO plataforma_conexoes (account_id, api_url, chave_cifrada, segredo_eventos_cifrado, nome_conexao, escopos, conectado_em)
                       VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$acc, 'http://127.0.0.1:9/api/integracao/v1', Crypto::encrypt('ffc_teste'), Crypto::encrypt($segredo), $marca, 'leitura,comercial']);
        $pdo->prepare('INSERT INTO cards (account_id, coluna_id, titulo, cliente_nome, telefone_whatsapp, responsavel_user_id, created_at) VALUES (?,?,?,?,?,?, NOW())')
            ->execute([$acc, $col, $marca, $marca . ' Cliente', '11999990000', $eu]);
        $card = (int) $pdo->lastInsertId();

        echo "\n== 2. Assinatura ==\n";
        $corpo = '{"x":1}';
        $ts = (string) time();
        $assinatura = 'sha256=' . hash_hmac('sha256', $ts . '.' . $corpo, $segredo);
        ok('assinatura certa passa', Eventos::autenticar($acc, $corpo, $ts, $assinatura) === 0);
        ok('corpo mexido não passa', Eventos::autenticar($acc, '{"x":2}', $ts, $assinatura) === 401);
        ok('timestamp de 20 minutos atrás não passa', Eventos::autenticar($acc, $corpo, (string) (time() - 1200), 'sha256=' . hash_hmac('sha256', (time() - 1200) . '.' . $corpo, $segredo)) === 401);
        ok('conta sem conexão responde 410', Eventos::autenticar(999999999, $corpo, $ts, $assinatura) === 410);

        echo "\n== 3. Recebimento ==\n";
        $codigo = 'FLT-ZZTE-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        $evento = ['id' => uuid(), 'tipo' => 'recarga.aprovada', 'conta' => ['codigo' => $codigo, 'nome' => $marca, 'cnpj' => '00000000000000'],
            'origem' => ['tipo' => 'conta'], 'dados' => ['recarga' => ['valor' => 50, 'metodo' => 'PIX'], 'saldo' => 50]];
        $r = Eventos::receber($acc, $evento);
        ok('sem vínculo: guarda o evento, sem lead', $r === ['novo' => true, 'card_id' => null]);
        ok('a mesma entrega de novo não grava', Eventos::receber($acc, $evento)['novo'] === false);
        ok('evento fora do contrato é recusado', (function () use ($acc) {
            try { Eventos::receber($acc, ['id' => 'x', 'tipo' => 'nada']); return false; } catch (\InvalidArgumentException $e) { return true; }
        })());

        $pdo->prepare('INSERT INTO plataforma_vinculos (account_id, codigo_conta, card_id, nome_conta, resumo_em) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())')
            ->execute([$acc, $codigo, $card, $marca]);
        $pedido = ['id' => uuid(), 'tipo' => 'solicitacao.criada', 'conta' => ['codigo' => $codigo, 'nome' => $marca],
            'origem' => ['tipo' => 'conta', 'nome' => 'Marina'], 'dados' => ['solicitacao' => ['tipo' => 'PLANO', 'planoNome' => 'Plano Pro', 'solicitadoPorNome' => 'Marina']]];
        $r = Eventos::receber($acc, $pedido);
        ok('com vínculo: vai para o lead', $r === ['novo' => true, 'card_id' => $card]);
        $nota = $pdo->query("SELECT assunto, conteudo FROM crm_interacoes WHERE entidade = 'card' AND entidade_id = $card ORDER BY id DESC LIMIT 1")->fetch(\PDO::FETCH_ASSOC);
        ok('nota no histórico do lead', $nota && $nota['assunto'] === 'Pedido na plataforma' && str_contains($nota['conteudo'], 'mudar para Plano Pro'));
        $aviso = (int) $pdo->query("SELECT COUNT(*) FROM account_notifications WHERE account_id = $acc AND tipo = 'plataforma' AND entidade = 'card' AND entidade_id = $card AND user_id = $eu")->fetchColumn();
        ok('aviso ao responsável pelo lead', $aviso === 1);
        $velho = $pdo->query('SELECT resumo_em FROM plataforma_vinculos WHERE account_id = ' . $acc . ' AND codigo_conta = ' . $pdo->quote($codigo))->fetchColumn();
        ok('o resumo guardado fica marcado como antigo', $velho === null);
        $lista = Eventos::doCard($acc, $card);
        ok('a ficha lista os eventos do lead', count($lista) === 1 && $lista[0]['tipo'] === 'solicitacao.criada');
        ok('outra conta não vê os eventos deste lead', Eventos::doCard($acc + 1, $card) === []);

        echo "\n== 4. Assinatura pelo site ==\n";
        // Telefone que não existe na base: o lead tem de ser novo, não um que já estava lá.
        $foneSite = '119' . str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $assinatura = ['id' => uuid(), 'descricao' => 'Fleetiflow Despachante — Essencial', 'valorMensal' => 389.9, 'taxaDeAtivacao' => 1000, 'status' => 'AUTORIZADA',
            'nome' => 'Ana Teste', 'email' => strtolower($marca) . '@exemplo.com', 'telefone' => $foneSite, 'empresa' => $marca . ' Site', 'documento' => null];
        $ev1 = ['id' => uuid(), 'tipo' => 'site.assinatura', 'conta' => null, 'origem' => ['tipo' => 'sistema'], 'dados' => ['status' => 'AUTORIZADA', 'assinatura' => $assinatura]];
        $r1 = Eventos::receber($acc, $ev1);
        $cardSite = (int) ($r1['card_id'] ?? 0);
        $siteCards[] = $cardSite;
        ok('assinatura autorizada vira lead', $cardSite > 0);
        $c = $pdo->query("SELECT empresa_nome, email, telefone_whatsapp, valor_proposta FROM cards WHERE id = $cardSite")->fetch(\PDO::FETCH_ASSOC);
        ok('o lead tem empresa, e-mail, WhatsApp com 55 e o valor', $c && $c['empresa_nome'] === $marca . ' Site' && $c['telefone_whatsapp'] === '55' . $foneSite && (float) $c['valor_proposta'] === 389.9);
        $notaSite = $pdo->query("SELECT assunto FROM crm_interacoes WHERE entidade = 'card' AND entidade_id = $cardSite ORDER BY id DESC LIMIT 1")->fetchColumn();
        ok('nota "assinatura pelo site ativa"', $notaSite === 'Assinatura pelo site ativa');
        $textoSite = (string) $pdo->query("SELECT conteudo FROM crm_interacoes WHERE entidade = 'card' AND entidade_id = $cardSite ORDER BY id DESC LIMIT 1")->fetchColumn();
        ok('a nota manda cobrar a taxa de ativação à parte', str_contains($textoSite, 'Cobrar à parte a taxa de ativação de R$ 1.000,00 (uma vez)'));
        $ev2 = ['id' => uuid(), 'tipo' => 'site.pagamento', 'conta' => null, 'origem' => ['tipo' => 'sistema'], 'dados' => ['valor' => 389.9, 'assinatura' => ['pagamentosAprovados' => 1] + $assinatura]];
        ok('a cobrança do mês vai para o mesmo lead', (Eventos::receber($acc, $ev2)['card_id'] ?? 0) === $cardSite);
    } catch (\Throwable $e) {
        ok('sem exceção: ' . $e->getMessage() . ' @' . basename($e->getFile()) . ':' . $e->getLine(), false);
    } finally {
        foreach (array_filter($siteCards) as $sc) {
            // Só o que o teste criou: os eventos do site, as notas e os avisos deles.
            $pdo->exec("DELETE FROM plataforma_eventos WHERE account_id = $acc AND card_id = $sc AND tipo LIKE 'site.%'");
            $pdo->exec("DELETE FROM crm_interacoes WHERE entidade = 'card' AND entidade_id = $sc AND (assunto LIKE 'Assinatura pelo site%' OR assunto = 'Mensalidade paga pelo site')");
            $pdo->exec("DELETE FROM account_notifications WHERE entidade = 'card' AND entidade_id = $sc AND tipo = 'plataforma'");
            // Criado por Card::create, tem histórico imutável: sai por exclusão lógica.
            $pdo->exec("UPDATE cards SET deleted_at = NOW() WHERE id = $sc AND empresa_nome = " . $pdo->quote($marca . ' Site'));
        }
        $pdo->exec("DELETE FROM plataforma_eventos WHERE account_id = $acc AND (codigo_conta LIKE 'FLT-ZZTE-%')");
        $pdo->exec("DELETE FROM plataforma_vinculos WHERE account_id = $acc AND codigo_conta LIKE 'FLT-ZZTE-%'");
        $pdo->exec("DELETE FROM plataforma_conexoes WHERE account_id = $acc AND nome_conexao = " . $pdo->quote($marca));
        if ($card) {
            $pdo->exec("DELETE FROM crm_interacoes WHERE entidade = 'card' AND entidade_id = $card");
            $pdo->exec("DELETE FROM account_notifications WHERE entidade = 'card' AND entidade_id = $card");
            $pdo->exec("DELETE FROM cards WHERE id = $card AND titulo = " . $pdo->quote($marca));
        }
        $resto = (int) $pdo->query("SELECT COUNT(*) FROM plataforma_conexoes WHERE nome_conexao = " . $pdo->quote($marca))->fetchColumn()
            + (int) $pdo->query("SELECT COUNT(*) FROM cards WHERE titulo = " . $pdo->quote($marca))->fetchColumn()
            + (int) $pdo->query("SELECT COUNT(*) FROM plataforma_eventos WHERE codigo_conta LIKE 'FLT-ZZTE-%'")->fetchColumn();
        ok('limpeza: nada do teste ficou no banco', $resto === 0);
    }
}

echo "\nResultado: $OK ok · " . count($FALHAS) . " falha(s)\n";
if ($FALHAS) exit(1);
