<?php
/**
 * perfil_comercial_test.php: o nome de conta comercial do WhatsApp.
 *
 * Conta Business manda pushName vazio, e a conversa aparecia só com o telefone.
 * App\WhatsAppAgente\PerfilComercial deduz o nome do perfil comercial. Como é
 * DEDUÇÃO, este teste tranca duas coisas:
 *
 *   1. Os casos reais que motivaram (30/09/2026, canal do Fleetiflow) dão o
 *      nome certo, e os casos duvidosos dão NULL. Nome errado é pior que nome
 *      nenhum: na dúvida a tela segue mostrando o telefone.
 *   2. O peso é o mais baixo entre os nomes de verdade: pushName, agenda, CRM e
 *      rename manual sempre substituem o nome deduzido, e nunca o contrário.
 *
 * Não faz chamada de rede. Uso: php scripts/tests/perfil_comercial_test.php
 */

require_once __DIR__ . '/../../app/bootstrap.php';

use App\WhatsAppAgente\Identidade;
use App\WhatsAppAgente\PerfilComercial as PC;

$OK = 0;
$FALHAS = [];

function ok(string $msg, bool $cond): void
{
    global $OK, $FALHAS;
    if ($cond) { $OK++; echo "  [ok]   $msg\n"; }
    else { $FALHAS[] = $msg; echo "  [FALHA] $msg\n"; }
}

echo "\n== 1. Casos reais ==\n";
ok('"Fiat Sinal é uma empresa do Grupo Sinal..." -> Fiat Sinal',
    PC::nomeDoPerfil(['description' => 'Fiat Sinal é uma empresa do Grupo Sinal. Tradicional rede de concessionárias, que atua no segmento desde 1980.', 'website' => ['https://www.gruposinal.com.br/']]) === 'Fiat Sinal');
ok('"Jeep Sinal é uma empresa..." -> Jeep Sinal (descrição vence o site)',
    PC::nomeDoPerfil(['description' => 'Jeep Sinal é uma empresa do Grupo Sinal.', 'website' => ['https://www.gruposinal.com.br/']]) === 'Jeep Sinal');
ok('descrição sem nome + site carrera.com.br -> Carrera',
    PC::nomeDoPerfil(['description' => 'O carro dos seus sonhos está aqui! 🚗 Concessionária especializada em seminovos.', 'website' => ['https://www.carrera.com.br/comprar/carros/usados?refine=x']]) === 'Carrera');
ok('descrição "." e sem site -> null (segue mostrando o telefone)',
    PC::nomeDoPerfil(['description' => '.', 'website' => []]) === null);

echo "\n== 2. Descrição ==\n";
ok('artigo inicial é descartado: "A Clínica Vida é referência..." -> Clínica Vida', PC::nomeDaDescricao('A Clínica Vida é referência em cardiologia.') === 'Clínica Vida');
ok('"Nossa missão é a excelência" NÃO vira nome', PC::nomeDaDescricao('Nossa missão é a excelência no atendimento.') === null);
ok('"Aqui é o lugar certo" NÃO vira nome', PC::nomeDaDescricao('Aqui é o lugar certo para comprar.') === null);
ok('frase sem o padrão "X é uma" -> null', PC::nomeDaDescricao('Atendemos de segunda a sexta, das 8h às 18h.') === null);
ok('começo em minúscula -> null', PC::nomeDaDescricao('somos uma empresa de tecnologia') === null);
ok('nome longo demais -> null', PC::nomeDaDescricao('Esta Empresa De Nome Absurdamente Comprido Que Não Acaba Nunca Mesmo é uma loja.') === null);
ok('vazio -> null', PC::nomeDaDescricao('   ') === null);

echo "\n== 3. Site ==\n";
ok('www. e caminho são ignorados', PC::nomeDoSite(['https://www.carrera.com.br/x?y=1']) === 'Carrera');
ok('hífen vira espaço: auto-center-sp.com -> Auto Center Sp', PC::nomeDoSite(['auto-center-sp.com']) === 'Auto Center Sp');
ok('Instagram: nome vem do caminho', PC::nomeDoSite(['https://instagram.com/lojadocarro']) === 'Lojadocarro');
ok('wa.me e encurtador não dizem nada -> null', PC::nomeDoSite(['https://wa.me/5511999999999', 'https://bit.ly/abc']) === null);
ok('pula o inútil e usa o próximo', PC::nomeDoSite(['https://linktr.ee/x', 'https://www.oficinadoze.com.br']) === 'Oficinadoze');
ok('string simples (não array) também serve', PC::nomeDoSite('https://www.carrera.com.br') === 'Carrera');
ok('sem site -> null', PC::nomeDoSite([]) === null && PC::nomeDoSite(null) === null);

echo "\n== 4. Peso: deduzido perde para qualquer nome de verdade ==\n";
$P = Identidade::PESOS;
ok('perfil_comercial existe nos pesos', isset($P['perfil_comercial']));
ok('pesa MENOS que o pushName', $P['perfil_comercial'] < $P['messages_upsert']);
ok('pesa MAIS que o fallback (telefone)', $P['perfil_comercial'] > $P['fallback']);
ok('pushName substitui o nome deduzido', Identidade::melhorNome('Carrera', $P['perfil_comercial'], 'Carrera Seminovos VW', $P['messages_upsert']));
ok('nome deduzido NÃO substitui pushName', !Identidade::melhorNome('Marcos', $P['messages_upsert'], 'Carrera', $P['perfil_comercial']));
ok('nome deduzido NÃO substitui rename manual', !Identidade::melhorNome('Concessionária do João', $P['manual'], 'Carrera', $P['perfil_comercial']));
ok('sem nome nenhum, o deduzido entra', Identidade::melhorNome('', 0, 'Carrera', $P['perfil_comercial']));

/* ── 5. resolver(): grava, não repete consulta, não atropela nome real ────── */
echo "\n== 5. resolver() com Evolution simulada (banco, desfeito no fim) ==\n";

/** Evolution de mentira: devolve o perfil combinado e CONTA as consultas. */
final class EvoFalsa extends \App\WhatsAppAgente\EvolutionApiService
{
    public int $consultas = 0;
    public array $resposta = [];
    public function __construct() { parent::__construct([]); }
    public function fetchBusinessProfile(string $name, string $number): array
    {
        $this->consultas++;
        return $this->resposta;
    }
}

try {
    $pdo = \App\Core\Database::getConnection();
    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $inst = $pdo->query('SELECT id, account_id FROM whatsapp_instances ORDER BY id LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
    $temColuna = (bool) $pdo->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_identidades' AND COLUMN_NAME = 'perfil_comercial_em'")->fetchColumn();
} catch (\Throwable $e) {
    $inst = null; $temColuna = false;
    echo '  [pulado] sem banco: ' . $e->getMessage() . "\n";
}

if (!$inst || !$temColuna) {
    echo "  [pulado] precisa de uma instância de WhatsApp e da migration 132\n";
} else {
    $ACC = (int) $inst['account_id']; $INST = (int) $inst['id'];
    $pdo->beginTransaction();
    try {
        $evo = new EvoFalsa();
        $linha = static function (string $jid) use ($pdo, $INST) {
            $st = $pdo->prepare('SELECT nome, nome_origem, perfil_comercial_em FROM whatsapp_identidades WHERE instance_id = ? AND jid = ?');
            $st->execute([$INST, $jid]);
            return $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        };

        // a) empresa com perfil: grava o nome, com a origem certa, e marca a consulta
        $jA = '5511900000901@s.whatsapp.net';
        $evo->resposta = ['isBusiness' => true, 'description' => 'Fiat Sinal é uma empresa do Grupo Sinal.', 'website' => [], '_http' => 200];
        $r = PC::resolver($ACC, $INST, $jA, $evo, 'x');
        $l = $linha($jA);
        ok('grava o nome deduzido', $r['nome'] === 'Fiat Sinal' && ($l['nome'] ?? '') === 'Fiat Sinal');
        ok('origem perfil_comercial e consulta marcada', ($l['nome_origem'] ?? '') === 'perfil_comercial' && !empty($l['perfil_comercial_em']));

        // b) segunda chamada: devolve o nome SEM consultar a Evolution de novo
        $antes = $evo->consultas;
        $r2 = PC::resolver($ACC, $INST, $jA, $evo, 'x');
        ok('não consulta a Evolution duas vezes', $r2['nome'] === 'Fiat Sinal' && $evo->consultas === $antes);

        // c) perfil sem nome aproveitável: marca a consulta e não insiste
        $jB = '5511900000902@s.whatsapp.net';
        $evo->resposta = ['isBusiness' => true, 'description' => '.', 'website' => [], '_http' => 200];
        $r = PC::resolver($ACC, $INST, $jB, $evo, 'x');
        $antes = $evo->consultas;
        PC::resolver($ACC, $INST, $jB, $evo, 'x');
        ok('sem nome: devolve null, marca, e não consulta de novo', $r['nome'] === null && !empty($linha($jB)['perfil_comercial_em']) && $evo->consultas === $antes);

        // d) conta pessoal (isBusiness false): nunca inventa nome pelo site
        $jC = '5511900000903@s.whatsapp.net';
        $evo->resposta = ['isBusiness' => false, 'website' => ['https://www.carrera.com.br'], '_http' => 200];
        ok('conta não comercial não ganha nome deduzido', PC::resolver($ACC, $INST, $jC, $evo, 'x')['nome'] === null);

        // e) já existe pushName: não consulta e não troca
        $jD = '5511900000904@s.whatsapp.net';
        Identidade::registrar($ACC, $INST, $jD, null, '5511900000904', 'Marcos Andrade', 'messages_upsert');
        $evo->resposta = ['isBusiness' => true, 'description' => 'Carrera é uma concessionária.', '_http' => 200];
        $antes = $evo->consultas;
        $r = PC::resolver($ACC, $INST, $jD, $evo, 'x');
        ok('quem já tem nome real não é consultado nem renomeado', $r['nome'] === 'Marcos Andrade' && $evo->consultas === $antes && ($linha($jD)['nome'] ?? '') === 'Marcos Andrade');

        // f) pushName que chega DEPOIS substitui o nome deduzido
        Identidade::registrar($ACC, $INST, $jA, null, '5511900000901', 'Fiat Sinal Moema', 'messages_upsert');
        ok('pushName posterior substitui o deduzido', ($linha($jA)['nome'] ?? '') === 'Fiat Sinal Moema');

        // g) Evolution fora do ar: não marca, para tentar de novo depois
        $jE = '5511900000905@s.whatsapp.net';
        $evo->resposta = ['_error' => 'timeout', '_http' => 0];
        PC::resolver($ACC, $INST, $jE, $evo, 'x');
        ok('erro da Evolution não marca como consultado', empty($linha($jE)['perfil_comercial_em']));

        // h) grupo e @lid: fora do escopo, sem consulta
        $antes = $evo->consultas;
        PC::resolver($ACC, $INST, '120363000000000001@g.us', $evo, 'x');
        PC::resolver($ACC, $INST, '232366454870257@lid', $evo, 'x');
        ok('grupo e @lid não são consultados', $evo->consultas === $antes);
    } catch (\Throwable $e) {
        ok('integração sem exceção: ' . $e->getMessage(), false);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

echo "\n----\n";
if (!$FALHAS) {
    echo "Resultado: {$OK} ok · 0 falha(s)\n";
    exit(0);
}
echo 'Resultado: ' . $OK . ' ok · ' . count($FALHAS) . " falha(s)\n\n";
foreach ($FALHAS as $f) echo "  - $f\n";
exit(1);
