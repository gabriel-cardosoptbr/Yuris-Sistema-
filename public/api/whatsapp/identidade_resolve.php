<?php
/**
 * identidade_resolve.php — quem é essa pessoa, respondido pelo Yuris.
 *
 * ---------------------------------------------------------------------------
 * PARA QUE ISSO EXISTE
 * ---------------------------------------------------------------------------
 * A automação (n8n) recebe do WhatsApp um endereço `@lid`, que NÃO é o telefone,
 * e quer três coisas antes de decidir qualquer passo: o telefone real, o nome, e
 * se o nome é bom o bastante para não precisar perguntar.
 *
 * Ela não pode buscar isso na Evolution: a tabela `Contact` de lá guarda o `@lid`
 * e o telefone como dois contatos separados, sem nenhuma coluna ligando um ao
 * outro. Quem tem o vínculo é o Yuris, porque é ele que lê `remoteJidAlt` e
 * `participantAlt` de cada mensagem e consolida em `whatsapp_identidades`.
 *
 * Então o CRM é a fonte de verdade, e este endpoint é a porta dela.
 *
 * ---------------------------------------------------------------------------
 * COMO CHAMAR
 * ---------------------------------------------------------------------------
 * POST (ou GET) com:
 *
 *   apikey            header `apikey`, ou `?token=`   (a evolution_api_key do tenant)
 *   X-Webhook-Token   header, ou `?wtoken=`           (2o fator, igual ao webhook)
 *   instance          nome do canal, ex.: mariafernanda-83
 *
 * e UM destes dois, nesta ordem de preferência:
 *
 *   key       o objeto `key` CRU da mensagem. Preferido: traz o Alt, então além
 *             de responder a pergunta ele APRENDE o vínculo (registra a
 *             identidade). É o mesmo caminho do webhook.
 *   endereco  um jid, lid ou telefone solto. Só consulta, não aprende.
 *
 * Resposta (o mesmo contrato de App\WhatsAppAgente\Identidade::resolvido):
 *
 *   {"ok":true,"identidade":{
 *      "phone":"5511997529604","jid":"...@s.whatsapp.net","lid":"...@lid",
 *      "name":"Fe VIVO","push_name":"Fe","name_source":"contacts_upsert",
 *      "contact_resolved":true}}
 *
 * `contact_resolved` é o campo que decide o fluxo: falso significa que não
 * sabemos o nome dessa pessoa e a automação deve perguntar, em vez de tratar o
 * telefone como se fosse nome.
 *
 * ---------------------------------------------------------------------------
 * O QUE ELE NÃO FAZ
 * ---------------------------------------------------------------------------
 * Não cria instância, não toca em credencial, não devolve nada de outro tenant.
 * A instância é resolvida DENTRO da conta identificada pela apikey: mandar o
 * nome do canal de outro escritório não encontra nada.
 */

require_once __DIR__ . '/../../../app/bootstrap.php';

use App\WhatsAppAgente\Identidade;
use App\WhatsAppAgente\WhatsAppInstance;
use App\WhatsAppAgente\WhatsAppWebhookAuth;

header('Content-Type: application/json; charset=utf-8');

function resp(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$raw  = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) { $body = []; }

$instanceName = trim((string) ($body['instance'] ?? $_REQUEST['instance'] ?? ''));
$endereco     = trim((string) ($body['endereco'] ?? $_REQUEST['endereco'] ?? ''));
$key          = is_array($body['key'] ?? null) ? $body['key'] : [];

if ($instanceName === '') {
    resp(400, ['ok' => false, 'error' => 'instance obrigatório']);
}
if ($endereco === '' && $key === []) {
    resp(400, ['ok' => false, 'error' => 'informe key ou endereco']);
}

try {
    $instModel = new WhatsAppInstance();

    /* ── mesma identificação de tenant do webhook ──────────────────────────
     * A Evolution manda no header `apikey` a chave DELA, que não é a do
     * tenant. Por isso testamos todas as candidatas e aceitamos a primeira que
     * identificar, exatamente como o webhook faz. Divergir aqui criaria um
     * segundo comportamento de autenticação para manter.
     */
    $candidatos = [];
    foreach ([
        $_SERVER['HTTP_APIKEY']  ?? '',
        $_SERVER['HTTP_API_KEY'] ?? '',
        $_GET['token']           ?? '',
        $_GET['apikey']          ?? '',
        $body['apikey']          ?? '',
    ] as $cand) {
        $cand = trim((string) $cand);
        if ($cand !== '' && !in_array($cand, $candidatos, true)) { $candidatos[] = $cand; }
    }
    if (empty($candidatos)) {
        resp(401, ['ok' => false, 'error' => 'apikey obrigatória']);
    }

    $accountId = null;
    foreach ($candidatos as $cand) {
        $accountId = $instModel->findAccountByApiKey($cand);
        if ($accountId !== null) { break; }
    }
    if ($accountId === null) {
        resp(401, ['ok' => false, 'error' => 'apikey não bate com nenhum tenant configurado']);
    }

    $cfg = $instModel->getSettings($accountId);

    // 2o fator, com a MESMA regra do webhook: só token presente e errado (ou
    // ausente em modo estrito) derruba. Assim o canal que já está estrito não
    // ganha uma porta mais fraca por causa deste endpoint.
    $wtProvided = (string) ($_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? ($_GET['wtoken'] ?? ($body['wtoken'] ?? '')));
    $wtStrict   = !empty($cfg['webhook_token_strict']);
    if (WhatsAppWebhookAuth::verify($cfg['webhook_token'] ?? null, $wtProvided, $wtStrict) === WhatsAppWebhookAuth::REJECT) {
        error_log('[whatsapp/identidade_resolve] webhook_token rejeitado (account_id=' . $accountId . ')');
        resp(401, ['ok' => false, 'error' => 'webhook token inválido']);
    }

    /* A instância é procurada DENTRO da conta. Um nome de canal de outro
     * escritório simplesmente não é encontrado, e não há vazamento possível. */
    $row = $instModel->findByName($instanceName, [$accountId]);
    if (!$row) {
        resp(404, ['ok' => false, 'error' => 'canal não encontrado nesta conta']);
    }
    $instanceId = (int) $row['id'];

    $aprendeu = false;

    if ($key !== []) {
        $end = Identidade::enderecosDaKey($key);
        // Grupo sem participante não tem pessoa a resolver: responder o número do
        // grupo como se fosse telefone de alguém seria pior que responder nada.
        if ($end['lid'] === null && $end['phone'] === null) {
            resp(200, ['ok' => true, 'identidade' => Identidade::resolvido($instanceId, ''), 'aprendeu' => false]);
        }
        $pushName = trim((string) ($body['pushName'] ?? ''));
        $fromMe   = !empty($key['fromMe']);
        Identidade::registrar(
            $accountId,
            $instanceId,
            $end['jid'],
            $end['lid'],
            $end['phone'],
            // pushName é de quem ESCREVEU: em mensagem própria é o dono da conta.
            $fromMe ? null : ($pushName !== '' ? $pushName : null),
            'messages_upsert'
        );
        $aprendeu = true;
        $endereco = $end['lid'] ?? $end['jid'] ?? (string) $end['phone'];
    }

    resp(200, [
        'ok'         => true,
        'identidade' => Identidade::resolvido($instanceId, $endereco),
        'aprendeu'   => $aprendeu,
    ]);
} catch (\Throwable $e) {
    error_log('[whatsapp/identidade_resolve] ' . $e->getMessage());
    resp(500, ['ok' => false, 'error' => 'erro interno']);
}
