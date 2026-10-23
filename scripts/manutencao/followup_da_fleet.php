<?php
/**
 * scripts/manutencao/followup_da_fleet.php: traz o follow-up da Fleetiflow do
 * n8n para Operação › Follow-up (App\FollowUp, 23/10/2026).
 *
 * Cria duas cadências na conta (pelo nome; rodar de novo não duplica):
 *
 *   "Não respondeu à abertura"   a mesma cadência do fluxo do n8n
 *                                (pY0tfwPbHlfUt5al, nó "Escolhe quem recebe"):
 *                                5 h, 18 h e 44 h, de segunda a sábado das 8h
 *                                às 18h, só para abertura com até 3 dias, nas
 *                                etapas Novos leads e Follow-up automático; o
 *                                card vai para Follow-up automático no primeiro
 *                                envio (o fluxo fazia isso pelo sdr_etapa.php).
 *   "Respondeu e parou"          nova (pedido de 23/10/2026, Ouro Verde): lead
 *                                que respondeu e sumiu, com qualquer pessoa ou
 *                                com a Vitória. Nasce DESLIGADA, com textos
 *                                sugeridos para o cliente conferir na tela.
 *
 * --ligar liga a primeira. Ligada, o n8n deixa de mandar o follow-up dele
 * sozinho (sdr_automacao.php responde `followup: false`, ver
 * AutomacaoSdr::paraOn8n): o lead nunca recebe os dois. Para voltar ao n8n,
 * basta desligar a cadência na tela.
 *
 * USO
 *   php scripts/manutencao/followup_da_fleet.php --conta=116              (só confere)
 *   php scripts/manutencao/followup_da_fleet.php --conta=116 --aplicar
 *   php scripts/manutencao/followup_da_fleet.php --conta=116 --aplicar --ligar
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Só via CLI.\n"); }
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\FollowUp\Cadencia;
use App\WhatsAppAgente\SdrFleetiflow;

$o = getopt('', ['conta:', 'aplicar', 'ligar']);
$conta = (int) ($o['conta'] ?? 0);
$aplicar = isset($o['aplicar']);
if ($conta <= 0) exit("Informe --conta=ID.\n");
$pdo = Database::getConnection();
$col = SdrFleetiflow::colunasDaConta($pdo, $conta);
foreach (['novo', 'followup', 'qualificacao', 'qualificado', 'especialista'] as $k) {
    if (!isset($col[$k])) exit("A conta {$conta} não tem a etapa '{$k}' do funil da Fleetiflow.\n");
}
$horario = ['dias' => [1, 2, 3, 4, 5, 6], 'de' => '08:00', 'ate' => '18:00'];

$cadencias = [
    [
        'nome' => 'Não respondeu à abertura', 'gatilho' => 'sem_resposta', 'com_quem' => 'todas',
        'etapas' => [$col['novo'], $col['followup']], 'mover_para' => $col['followup'], 'inicio_max_dias' => 3, 'horario' => $horario,
        'passos' => [
            ['espera_min' => 300, 'texto' => "Só para não deixar minha mensagem perdida por aqui 🙂\n\nUma coisa que vemos bastante em operações com veículos é informação espalhada entre sistema, planilha, WhatsApp e fornecedor.\n\nAcontece algo parecido por aí?"],
            ['espera_min' => 1080, 'texto' => "Passando porque tem uma situação que aparece bastante nas empresas que atendemos: a multa chega, existe prazo para tratar e alguém precisa descobrir quem estava com o veículo.\n\nVocês já têm esse fluxo bem organizado?"],
            ['espera_min' => 2640, 'texto' => "Prometo que é minha última mensagem por aqui 😅\n\nA Fleetiflow centraliza multas, documentação, manutenção e gestão dos veículos para reduzir justamente esse tipo de controle manual.\n\nSe fizer sentido conhecer, consigo te mostrar em uma demonstração rápida de uns 15 minutos.\n\nSe não fizer sentido agora, sem problema."],
        ],
    ],
    [
        'nome' => 'Respondeu e parou', 'gatilho' => 'parou', 'com_quem' => 'todas',
        'etapas' => [$col['qualificacao'], $col['followup'], $col['qualificado'], $col['especialista']], 'mover_para' => null,
        'inicio_max_dias' => 3, 'horario' => $horario,
        'passos' => [
            ['espera_min' => 1440, 'texto' => "Oi {nome}, tudo bem? Não quis deixar nossa conversa esfriar 🙂\n\nConseguiu pensar no que falamos? Se ficou alguma dúvida, me conta que eu te ajudo."],
            ['espera_min' => 2880, 'texto' => "Passando para saber se ainda faz sentido conversarmos sobre a gestão dos veículos de vocês.\n\nSe preferir, consigo te mostrar a Fleetiflow funcionando numa demonstração rápida de uns 15 minutos, no horário que for melhor para você."],
            ['espera_min' => 5760, 'texto' => "Vou parar por aqui para não ficar te incomodando 😊\n\nSe em algum momento quiser retomar, é só me chamar nesta conversa que eu continuo de onde paramos."],
        ],
    ],
];

$existentes = array_column(Cadencia::daConta($conta), null, 'nome');
foreach ($cadencias as $i => $c) {
    $ja = $existentes[$c['nome']] ?? null;
    if ($ja) {
        echo "  \"{$c['nome']}\": já existe (id {$ja['id']}, " . ($ja['ativo'] ? 'ligada' : 'desligada') . ")\n";
        $id = $ja['id'];
    } elseif (!$aplicar) {
        echo "  \"{$c['nome']}\": seria criada, desligada\n";
        continue;
    } else {
        $r = Cadencia::salvar($conta, $c, 0);
        if (!$r['ok']) exit("  \"{$c['nome']}\": {$r['error']}\n");
        $id = $r['id'];
        echo "  \"{$c['nome']}\": criada (id {$id}), desligada\n";
    }
    if ($i === 0 && isset($o['ligar'])) {
        if ($aplicar) { Cadencia::ligar((int) $id, $conta, true, 0); echo "    ligada: o n8n deixa de mandar o follow-up dele\n"; }
        else echo "    seria ligada\n";
    }
}
echo $aplicar ? "Fim.\n" : "Só conferido. Rode com --aplicar para gravar.\n";
