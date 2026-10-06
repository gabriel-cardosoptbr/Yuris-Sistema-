<?php
namespace App\WhatsAppAgente;

use App\Core\EnvLoader;
use App\Master\Account;

/**
 * SdrFleetiflow: o Agente IA de uma conta Fleetiflow é a Vitória, não a triagem.
 *
 * ---------------------------------------------------------------------------
 * POR QUÊ
 * ---------------------------------------------------------------------------
 * O motor do Agente IA (AiIntake) é de triagem jurídica: perguntas de advogado
 * e "este canal é destinado ao atendimento jurídico" para o resto. A conta
 * Fleetiflow não tem jurídico. A pré-venda dela é a Vitória, uma IA que roda no
 * n8n (workflow "SDR Fleetiflow | Vitória (pré-qualificação)").
 *
 * A Evolution só aceita UM webhook por instância, e ele aponta para o Yuris.
 * Então quem entrega a mensagem para a Vitória é o Yuris, e as chaves que já
 * existem no Chat passam a valer para ela:
 *   - "Agente" do canal (agent_configs.enabled) liga e desliga a Vitória;
 *   - "Assumir conversa" (whatsapp_chats.agent_paused) tira ela daquela conversa.
 *
 * ---------------------------------------------------------------------------
 * ESCOPO
 * ---------------------------------------------------------------------------
 * Tudo aqui só age quando a conta DONA do canal tem produto 'fleetiflow'.
 * Conta Yuris continua exatamente como era.
 *
 * Configuração no .env:
 *   FLEETIFLOW_SDR_WEBHOOK_URL    endereço do webhook da Vitória no n8n
 *   FLEETIFLOW_SDR_WEBHOOK_TOKEN  opcional; vai no cabeçalho X-Fleetiflow-Token
 *   FLEETIFLOW_SDR_NUMEROS_TREINO opcional; telefones de treino além dos de NUMEROS_TREINO,
 *                                 separados por vírgula
 * Sem a URL nada é encaminhado, e a chave do canal não liga.
 */
final class SdrFleetiflow
{
    /** Nome que aparece na chave do Chat quando o canal é Fleetiflow. */
    public const NOME_AGENTE = 'Vitória (pré-qualificação)';

    /**
     * Origens de mensagem própria que são uma pessoa digitando, não a API.
     * 'aparelho' não vem da Evolution: é o que origemEfetiva() devolve quando o
     * evento prova que foi digitado (ver lá).
     */
    private const APARELHO = ['android', 'ios', 'desktop', 'aparelho'];

    /**
     * Números de TREINO da Vitória (mesmo esquema do SDR Schumaher): quem fala por
     * eles é do time, testando. A conversa nunca fica pausada nem vai para o
     * especialista, para a Vitória sempre receber a mensagem; quem decide se é
     * conversa normal, correção, aprovação ou "reiniciar" é o fluxo no n8n, que
     * tem a mesma lista no nó "Configuração (editar aqui)". Mudou aqui, muda lá.
     */
    private const NUMEROS_TREINO = ['5511925592706'];

    /** DDD + 8 últimos dígitos, igual ao chaveFone do n8n (aceita com ou sem 55 e 9). */
    private static function chaveFone(string $fone): string
    {
        $d = preg_replace('/\D/', '', $fone);
        if (strlen($d) >= 12 && str_starts_with($d, '55')) $d = substr($d, 2);
        if (strlen($d) < 10) return '';
        return substr($d, 0, 2) . substr($d, -8);
    }

    /** @return list<string> chaves dos números de treino */
    private static function chavesTreino(): array
    {
        $extra = (string)EnvLoader::get('FLEETIFLOW_SDR_NUMEROS_TREINO', '');
        $todos = array_merge(self::NUMEROS_TREINO, preg_split('/[,;\s]+/', $extra) ?: []);
        return array_values(array_filter(array_unique(array_map([self::class, 'chaveFone'], $todos))));
    }

    /**
     * Telefone de uma conversa: do próprio JID, do remoteJidAlt de um @lid, ou da
     * identidade já gravada para o @lid naquele número.
     */
    private static function foneDaConversa(int $instanceId, string $remoteJid, array $key = []): string
    {
        if (str_ends_with($remoteJid, '@s.whatsapp.net')) return explode('@', $remoteJid)[0];
        $alt = (string)($key['remoteJidAlt'] ?? '');
        if (str_ends_with($alt, '@s.whatsapp.net')) return explode('@', $alt)[0];
        if ($instanceId > 0 && str_ends_with($remoteJid, '@lid')) {
            try {
                $st = \App\Core\Database::getConnection()->prepare(
                    "SELECT phone FROM whatsapp_identidades WHERE instance_id = ? AND (lid = ? OR jid = ?)
                        AND phone REGEXP '^[0-9]{10,13}$' LIMIT 1"
                );
                $st->execute([$instanceId, $remoteJid, $remoteJid]);
                return (string)($st->fetchColumn() ?: '');
            } catch (\Throwable $e) { return ''; }
        }
        return '';
    }

    /** A conversa é de um número de treino? */
    public static function ehTreino(int $instanceId, string $remoteJid, array $key = []): bool
    {
        $chave = self::chaveFone(self::foneDaConversa($instanceId, $remoteJid, $key));
        return $chave !== '' && in_array($chave, self::chavesTreino(), true);
    }

    /**
     * De onde veio uma mensagem PRÓPRIA (fromMe), para saber se foi gente.
     *
     * O campo `source` da Evolution não basta: mensagem enviada pela API (o robô,
     * a Vitória, o Chat do CRM) e mensagem digitada no WhatsApp Web chegam as duas
     * com source "web". Assim, a especialista que respondia pelo WhatsApp Web no
     * computador não movia o card para "Em atendimento pelo especialista" nem
     * pausava a Vitória (visto em 01/10/2026: só 6 envios pelo Chat em dois dias,
     * o resto pelo WhatsApp Web, e a coluna ficava vazia).
     *
     * O que separa é o EVENTO. Na Evolution 2.3.7 o socket roda com
     * `emitOwnEvents: false`: o envio pela API dispara só `send.message`, e o
     * `messages.upsert` com fromMe só existe quando a mensagem foi digitada num
     * aparelho do número (celular, WhatsApp Web, app de desktop). Conferido no
     * código-fonte da 2.3.7 (whatsapp.baileys.service.ts). PURA.
     */
    public static function origemEfetiva(string $evento, ?string $source): ?string
    {
        if (strtolower($evento) === 'messages.upsert') return 'aparelho';
        return $source;
    }

    /** @var array<int,bool> produto por conta, dentro da mesma requisição */
    private static array $cache = [];

    /** A conta dona do canal é Fleetiflow? Fail-soft: na dúvida, não é. */
    public static function contaUsa(int $accountId): bool
    {
        if ($accountId <= 0) return false;
        if (array_key_exists($accountId, self::$cache)) return self::$cache[$accountId];
        try {
            $conta = Account::findById($accountId);
            $usa = $conta !== null && Account::getProduto($conta) === 'fleetiflow';
        } catch (\Throwable $e) {
            error_log('[sdr_fleetiflow] produto da conta indisponível: ' . $e->getMessage());
            $usa = false;
        }
        return self::$cache[$accountId] = $usa;
    }

    /**
     * A conta é a dona do token do `.env` (FLEETIFLOW_SDR_WEBHOOK_TOKEN), ou seja
     * a Fleetiflow original, sem marca própria? Os endpoints chamados pelo n8n
     * da Vitória (sdr_etapa, sdr_transferencia) só podem agir nela.
     *
     * Antes eles aceitavam qualquer conta da edição CRM. Com uma conta só, dava
     * no mesmo; em 02/10/2026 a Inovaize (marca própria) ganhou número aberto e
     * duas coisas quebraram: o "card antes da conversa" exigia exatamente UMA
     * conta CRM com número aberto (passou a responder 404 para a Fleetiflow), e
     * um telefone presente nas duas contas podia levar o robô da Fleetiflow a
     * mexer no card da outra. Na dúvida (marca ilegível), não é.
     */
    public static function contaDoTokenGlobal(int $accountId): bool
    {
        if (!self::contaUsa($accountId)) return false;
        $m = self::marcaDaConta($accountId);
        return $m !== null && !$m['personalizada'];
    }

    public static function url(): string
    {
        return trim((string)EnvLoader::get('FLEETIFLOW_SDR_WEBHOOK_URL', ''));
    }

    /**
     * Para onde vai a mensagem do agente de pré-venda DESTA conta.
     *
     * A edição CRM agora serve várias marcas, e o endereço do `.env` é o da
     * Vitória, do Fleetiflow. Ele só vale para a conta sem marca própria (a
     * Fleetiflow original). Conta com marca própria usa o endereço gravado na
     * marca pelo Painel Master, ou nenhum: sem isso, ligar o agente numa conta
     * nova mandaria os leads dela para o robô do Fleetiflow.
     */
    public static function urlDaConta(int $accountId): string
    {
        $m = self::marcaDaConta($accountId);
        if ($m === null || !$m['personalizada']) return self::url();
        return (string)($m['agente_webhook'] ?? '');
    }

    /** Token do cabeçalho: só o do `.env`, e só para o endereço do `.env`. */
    private static function tokenDaConta(int $accountId): string
    {
        $m = self::marcaDaConta($accountId);
        if ($m !== null && $m['personalizada']) return '';
        return trim((string)EnvLoader::get('FLEETIFLOW_SDR_WEBHOOK_TOKEN', ''));
    }

    /** Nome do agente na chave do Chat: a Vitória no Fleetiflow, o da marca nas outras. */
    public static function nomeAgenteDaConta(int $accountId): string
    {
        $m = self::marcaDaConta($accountId);
        if ($m === null || !$m['personalizada']) return self::NOME_AGENTE;
        return $m['agente']['nome'] !== 'IA' ? $m['agente']['nome'] : 'Agente de pré-venda';
    }

    /** @var array<int,?array> */
    private static array $marcas = [];

    private static function marcaDaConta(int $accountId): ?array
    {
        if (array_key_exists($accountId, self::$marcas)) return self::$marcas[$accountId];
        try {
            $conta = Account::findById($accountId);
            $m = $conta ? \App\Master\Marca::daConta($conta) : null;
        } catch (\Throwable $e) {
            $m = null;
        }
        return self::$marcas[$accountId] = $m;
    }

    /**
     * Entrega a mensagem crua da Evolution (com key.remoteJidAlt, que traz o
     * telefone real de um @lid) para a Vitória. Roda DEPOIS do 200 à Evolution,
     * como o resto do agente. Nunca propaga exceção.
     *
     * @param array{account_id:int,instance_id:int,remote_jid:string,payload:array} $task
     */
    public static function encaminhar(array $task): void
    {
        $conta = (int)($task['account_id'] ?? 0);
        $url   = self::urlDaConta($conta);
        if ($url === '') {
            error_log('[sdr_fleetiflow] conta ' . $conta . ' sem endereço de agente: mensagem não encaminhada');
            return;
        }
        try {
            // Por qual número a mensagem chegou (nome da instância na Evolution), igual
            // ao que a Evolution manda no webhook direto. Com dois números na conta, a
            // Vitória responde pelo mesmo número em que o lead escreveu (05/10/2026).
            $instancia = '';
            if (!empty($task['instance_id'])) {
                $st = \App\Core\Database::getConnection()->prepare(
                    'SELECT instance_name FROM whatsapp_instances WHERE id = ? AND account_id = ? LIMIT 1'
                );
                $st->execute([(int)$task['instance_id'], $conta]);
                $instancia = (string)($st->fetchColumn() ?: '');
            }
            $corpo = json_encode([
                'event'    => 'messages.upsert',
                'origem'   => 'yuris',
                'instance' => $instancia,
                'data'     => $task['payload'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $cabecalhos = ['Content-Type: application/json'];
            $token = self::tokenDaConta($conta);
            if ($token !== '') $cabecalhos[] = 'X-Fleetiflow-Token: ' . $token;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $corpo,
                CURLOPT_HTTPHEADER     => $cabecalhos,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 10,
            ]);
            curl_exec($ch);
            $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $erro = curl_error($ch);
            curl_close($ch);
            if ($erro !== '' || $http >= 400 || $http === 0) {
                // Telefone mascarado no log (F4).
                error_log('[sdr_fleetiflow] encaminhamento falhou http=' . $http . ' ' . $erro
                    . ' jid=' . preg_replace('/\d{5,}/', '*****', (string)($task['remote_jid'] ?? '')));
            }
        } catch (\Throwable $e) {
            error_log('[sdr_fleetiflow] encaminhar falhou: ' . $e->getMessage());
        }
    }

    /**
     * Mensagem própria digitada no aparelho do número (celular, WhatsApp desktop)
     * = uma pessoa do time assumiu: pausa a Vitória naquela conversa, como o botão
     * "Assumir conversa". O envio da Vitória e o do Chat saem pela API ("web");
     * o do Chat é pausado no próprio send.php.
     */
    public static function pausarSeFoiNoAparelho(int $channelId, string $remoteJid, ?string $origem): void
    {
        if (!in_array(strtolower((string)$origem), self::APARELHO, true)) return;
        self::pausar($channelId, $remoteJid, null);
    }

    /**
     * Lead qualificado: o especialista assume no MESMO número, pelo Chat do CRM.
     * A Vitória já avisou o lead; aqui a conversa é pausada (ela não responde mais
     * por cima), o resumo vai para o card da conversa (cria o card se não houver)
     * e a conta recebe a notificação. Idempotente na prática: repetir só acrescenta
     * o resumo de novo ao card e reenvia o aviso.
     *
     * @return array{ok:bool, card_id:?int, erro?:string}
     */
    public static function transferir(int $accountId, int $channelId, string $remoteJid, array $d): array
    {
        $pdo = \App\Core\Database::getConnection();
        $resumo  = trim((string)($d['resumo'] ?? ''));
        $empresa = trim((string)($d['empresa'] ?? ''));
        $contato = trim((string)($d['nome_contato'] ?? ''));
        $fone    = preg_replace('/[^0-9]/', '', (string)($d['telefone'] ?? ''));
        if ($fone === '' && str_ends_with($remoteJid, '@s.whatsapp.net')) {
            $fone = preg_replace('/[^0-9]/', '', explode('@', $remoteJid)[0]);
        }

        self::pausar($channelId, $remoteJid, null);

        $cardId = null;
        try {
            $st = $pdo->prepare('SELECT linked_card_id FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1');
            $st->execute([$channelId, $remoteJid]);
            $cardId = (int)($st->fetchColumn() ?: 0) ?: null;

            if (!$cardId && $fone !== '') {
                $cardId = self::garantirCard($accountId, $channelId, $remoteJid, $fone, $contato, $empresa);
            }
            if ($cardId) {
                $card = \App\Prospeccao\Card::find($cardId);
                $antes = trim((string)($card['descricao'] ?? ''));
                \App\Prospeccao\Card::update($cardId, ['descricao' => trim($antes . "\n\n" . $resumo)]);
                if ($empresa !== '') self::nomearSeAnonimo($cardId, $empresa);
                self::moverEtapa($accountId, $cardId, 'qualificado');
                \App\Prospeccao\Card::logEvento($cardId, null, 'qualificado_vitoria', 'descricao', null, 'Lead qualificado pela Vitória');
            }
        } catch (\Throwable $e) {
            error_log('[sdr_fleetiflow] card da transferência falhou: ' . $e->getMessage());
        }

        try {
            \App\Master\AccountNotification::criar([
                'account_id' => $accountId,
                'user_id'    => null, // a conta toda vê: quem estiver no Chat assume
                'tipo'       => 'sdr_fleetiflow.transferencia',
                'titulo'     => 'Lead qualificado pela Vitória' . ($empresa !== '' ? ': ' . $empresa : ''),
                'mensagem'   => $resumo,
                'payload'    => ['instance_id' => $channelId, 'remote_jid' => $remoteJid, 'card_id' => $cardId],
            ]);
        } catch (\Throwable $e) {
            error_log('[sdr_fleetiflow] notificação da transferência falhou: ' . $e->getMessage());
        }

        return ['ok' => true, 'card_id' => $cardId];
    }

    /* ===================================================================== */
    /* Funil: todo lead da prospecção vira card, e a etapa anda com a conversa */
    /* ===================================================================== */

    /**
     * Etapas do funil Fleetiflow, espelho do funil do Kommo usado no SDR da
     * Schumaher (corretor -> especialista, visita -> demonstração). A chave é o
     * que a Vitória e a cadência mandam; o slug é como a coluna é achada. Renomear
     * a coluna na tela muda o slug, e aí a automação deixa de mover para ela.
     */
    public const ETAPAS = [
        'novo'         => ['Novos leads',                           'novos-leads',                         '#f9deff'],
        'qualificacao' => ['Em qualificação',                       'em-qualificacao',                     '#ffff99'],
        'followup'     => ['Follow-up automático',                  'follow-up-automatico',                '#99ccff'],
        'qualificado'  => ['Qualificado — aguardando especialista', 'qualificado-aguardando-especialista', '#ffcc66'],
        'especialista' => ['Em atendimento pelo especialista',      'em-atendimento-pelo-especialista',    '#ffcccc'],
        'negociacao'   => ['Demonstração / negociação',             'demonstracao-negociacao',             '#f9deff'],
        'bloqueado'    => ['Bloqueado',                             'bloqueado',                           '#99ccff'],
        'fora_escopo'  => ['Fora do escopo',                        'fora-do-escopo',                      '#f2f3f4'],
        'venda'        => ['Venda concluída',                       'venda-concluida',                     '#CCFF66'],
        'perdido'      => ['Perdido / desqualificado',              'perdido-desqualificado',              '#D5D8DB'],
    ];

    /**
     * De onde a AUTOMAÇÃO pode tirar o card para cada destino. Fora destas
     * etapas o card é de uma pessoa: quem arrastou para "Demonstração" não vê a
     * IA puxar de volta para "Em qualificação". Negociação e venda só à mão.
     */
    private const AUTO_DE = [
        'qualificacao' => ['novo', 'followup'],
        'followup'     => ['novo', 'qualificacao'],
        'qualificado'  => ['novo', 'qualificacao', 'followup'],
        'especialista' => ['novo', 'qualificacao', 'followup', 'qualificado'],
        'bloqueado'    => ['novo', 'qualificacao', 'followup'],
        'fora_escopo'  => ['novo', 'qualificacao', 'followup'],
        'perdido'      => ['novo', 'qualificacao', 'followup'],
    ];

    /**
     * Deixa o funil da conta igual a ETAPAS, na ordem. Idempotente. As colunas do
     * seed padrão viram etapas equivalentes (renomeadas, com os cards dentro);
     * "Proposta enviada" só sai se nunca teve card, senão fica no fim.
     *
     * @return list<string> o que foi feito, para o log do script
     */
    public static function montarFunil(\PDO $pdo, int $accountId): array
    {
        $herdadas = [
            'especialista' => ['leads-em-atendimento', 'prospeccao'],
            'negociacao'   => ['negociacao-juridica', 'negociacao'],
            'venda'        => ['contrato-fechado', 'fechado'],
        ];
        // [conta_funil, conta_oportunidade, conta_fechado, conta_perdido]
        $flags = [
            'novo' => [1,0,0,0], 'qualificacao' => [1,0,0,0], 'followup' => [1,0,0,0],
            'qualificado' => [1,1,0,0], 'especialista' => [1,1,0,0], 'negociacao' => [1,1,0,0],
            'bloqueado' => [0,0,0,0], 'fora_escopo' => [0,0,0,0], 'venda' => [0,0,1,0], 'perdido' => [0,0,0,1],
        ];

        $st = $pdo->prepare('SELECT id, slug FROM pipeline_columns WHERE account_id = ?');
        $st->execute([$accountId]);
        $porSlug = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) { $porSlug[(string)$r['slug']] = (int)$r['id']; }

        $feito = [];
        $ordem = 1;
        foreach (self::ETAPAS as $k => [$nome, $slug, $cor]) {
            $id = $porSlug[$slug] ?? null;
            if ($id === null) {
                foreach ($herdadas[$k] ?? [] as $antigo) {
                    if (isset($porSlug[$antigo])) { $id = $porSlug[$antigo]; unset($porSlug[$antigo]); break; }
                }
            }
            [$fu, $op, $fe, $pe] = $flags[$k];
            $dados = ['nome' => $nome, 'cor' => $cor, 'ordem' => $ordem, 'conta_funil' => $fu,
                      'conta_oportunidade' => $op, 'conta_fechado' => $fe, 'conta_perdido' => $pe];
            if ($id !== null) {
                \App\Prospeccao\PipelineColumn::update($id, $dados, [$accountId]);
                $feito[] = "coluna #$id -> $nome";
            } else {
                $id = (int)\App\Prospeccao\PipelineColumn::create($dados + ['account_id' => $accountId]);
                $feito[] = "criada #$id $nome";
            }
            // Slug fixo: o slugify depende do iconv do servidor.
            $pdo->prepare('UPDATE pipeline_columns SET slug = ? WHERE id = ?')->execute([$slug, $id]);
            unset($porSlug[$slug]);
            $ordem++;
        }

        // Sobras do seed (ex.: "Proposta enviada"): sai se nunca teve card, senão vai para o fim.
        foreach ($porSlug as $slug => $id) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM cards WHERE coluna_id = ?');
            $st->execute([$id]);
            if ((int)$st->fetchColumn() === 0) {
                \App\Prospeccao\PipelineColumn::delete($id, [$accountId]);
                $feito[] = "removida #$id ($slug, sem cards)";
            } else {
                \App\Prospeccao\PipelineColumn::update($id, ['ordem' => $ordem++], [$accountId]);
                $feito[] = "mantida #$id ($slug, tem cards) no fim";
            }
        }
        return $feito;
    }

    /** "Qualificado — aguardando especialista" -> "qualificado-aguardando-especialista" */
    private static function chaveTexto(string $s): string
    {
        $s = strtr(mb_strtolower($s), ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i',
                                       'ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    }

    /** @return array<string,int> chave da etapa => id da coluna, só as que existem */
    public static function colunasDaConta(\PDO $pdo, int $accountId): array
    {
        $st = $pdo->prepare('SELECT id, slug, nome FROM pipeline_columns WHERE account_id = ? ORDER BY ordem, id');
        $st->execute([$accountId]);
        $porSlug = [];
        // Pelo nome também: o slugify do PipelineColumn depende do iconv do
        // servidor e pode perder o "ç"/"ã"; o nome normalizado aqui não.
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $porSlug[self::chaveTexto((string)$r['nome'])] ??= (int)$r['id'];
            $porSlug[(string)$r['slug']] ??= (int)$r['id'];
        }
        $out = [];
        foreach (self::ETAPAS as $k => [, $slug]) {
            if (isset($porSlug[$slug])) $out[$k] = $porSlug[$slug];
        }
        return $out;
    }

    /**
     * Garante que o lead tem card e que a conversa aponta para ele. Procura
     * primeiro a conversa já ligada, depois um card com o mesmo telefone (últimos
     * 8 dígitos, como a captação), e só então cria em "Novos leads".
     *
     * Robô de apresentação, Vitória e webhook chamam isto quase no mesmo segundo
     * para o mesmo número; o GET_LOCK por telefone impede dois cards.
     *
     * @return int|null id do card, ou null quando não dá (sem telefone, cliente, sem coluna)
     */
    public static function garantirCard(
        int $accountId,
        ?int $instanceId,
        ?string $remoteJid,
        string $telefone,
        ?string $nome = null,
        ?string $empresa = null
    ): ?int {
        $pdo  = \App\Core\Database::getConnection();
        $fone = preg_replace('/[^0-9]/', '', $telefone);
        if (strlen($fone) < 10 || strlen($fone) > 13) return null;
        $fim  = substr($fone, -8);

        $trava = 'sdr_card_' . $accountId . '_' . $fim;
        $st = $pdo->prepare('SELECT GET_LOCK(?, 5)');
        $st->execute([$trava]);
        try {
            $cardId = null;
            if ($instanceId && $remoteJid) {
                $st = $pdo->prepare(
                    'SELECT wc.linked_card_id FROM whatsapp_chats wc
                       JOIN cards c ON c.id = wc.linked_card_id AND c.deleted_at IS NULL
                      WHERE wc.instance_id = ? AND wc.remote_jid = ? LIMIT 1'
                );
                $st->execute([$instanceId, $remoteJid]);
                $cardId = (int)($st->fetchColumn() ?: 0) ?: null;
            }

            if (!$cardId) {
                $st = $pdo->prepare(
                    "SELECT id FROM cards
                      WHERE account_id = ? AND deleted_at IS NULL
                        AND RIGHT(REGEXP_REPLACE(COALESCE(telefone_whatsapp,''), '[^0-9]', ''), 8) = ?
                   ORDER BY id DESC LIMIT 1"
                );
                $st->execute([$accountId, $fim]);
                $cardId = (int)($st->fetchColumn() ?: 0) ?: null;
            }

            if (!$cardId) {
                // Quem já é cliente não volta para o funil de prospecção.
                $st = $pdo->prepare(
                    "SELECT 1 FROM clientes
                      WHERE account_id = ? AND deleted_at IS NULL
                        AND (RIGHT(REGEXP_REPLACE(COALESCE(whatsapp,''), '[^0-9]', ''), 8) = ?
                          OR RIGHT(REGEXP_REPLACE(COALESCE(telefone,''), '[^0-9]', ''), 8) = ?)
                      LIMIT 1"
                );
                $st->execute([$accountId, $fim, $fim]);
                if ($st->fetchColumn()) return null;

                $colunas = self::colunasDaConta($pdo, $accountId);
                $coluna  = $colunas['novo'] ?? \App\Prospeccao\CaptacaoAutomatica::primeiraColuna($pdo, $accountId);
                if ($coluna === null) return null;

                $empresa = trim((string)$empresa);
                $rotulo  = $empresa !== '' ? $empresa : trim((string)$nome);
                if ($rotulo === '' || preg_match('/^[+0-9 ()-]+$/', $rotulo)) {
                    $rotulo = \App\Prospeccao\CaptacaoAutomatica::telefoneLegivel($fone);
                }
                $rotulo = mb_substr($rotulo, 0, 180);

                $cardId = (int)\App\Prospeccao\Card::create([
                    'account_id'        => $accountId,
                    // O lead é do vendedor dono do número (whatsapp_instances.responsavel_user_id).
                    'responsavel_user_id' => self::donoDoNumero($pdo, $accountId, $instanceId),
                    'titulo'            => $rotulo,
                    'cliente_nome'      => $rotulo,
                    'empresa_nome'      => $empresa !== '' ? mb_substr($empresa, 0, 180) : null,
                    'telefone_whatsapp' => $fone,
                    'coluna_id'         => $coluna,
                    'ordem_na_coluna'   => 0,
                    'status'            => 'aberto',
                    // Sem o nome do produto: a edição CRM serve outras marcas (Inovaize, Via Autodoc).
                    'descricao'         => 'Lead da prospecção ativa pelo WhatsApp.',
                    '_usuario_id'       => null,
                ]) ?: null;
                if (!$cardId) return null;
                \App\Prospeccao\Card::logEvento($cardId, null, 'captado_whatsapp', 'telefone', null, $fone);
            } else {
                if (trim((string)$empresa) !== '') self::nomearSeAnonimo($cardId, (string)$empresa);
                // Card que já existia sem responsável passa a ser do dono do número.
                // Responsável já escolhido (por pessoa ou pelo especialista) não muda.
                $dono = self::donoDoNumero($pdo, $accountId, $instanceId);
                if ($dono) {
                    $pdo->prepare('UPDATE cards SET responsavel_user_id = ? WHERE id = ? AND account_id = ? AND responsavel_user_id IS NULL')
                        ->execute([$dono, $cardId, $accountId]);
                }
            }

            if ($instanceId && $remoteJid) {
                $st = $pdo->prepare('SELECT linked_card_id FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1');
                $st->execute([$instanceId, $remoteJid]);
                $atual = $st->fetch(\PDO::FETCH_ASSOC);
                // Só amarra conversa solta: vínculo feito à mão não é desfeito aqui.
                if ($atual !== false && empty($atual['linked_card_id'])) {
                    (new WhatsAppMessage())->linkChat($instanceId, $remoteJid, ['linked_card_id' => $cardId]);
                }
            }
            return $cardId;
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$trava]);
        }
    }

    /**
     * O vendedor dono do número (whatsapp_instances.responsavel_user_id, migration
     * 137): o lead que a automação cria por esse número é dele. Sem número (o robô
     * avisou antes de a conversa existir), vale o dono quando a conta tem um só.
     * Usuário de outra conta, inativo ou apagado não conta. Sem a coluna (migration
     * ainda não aplicada) ou sem dono: null, e o lead nasce sem responsável.
     */
    public static function donoDoNumero(\PDO $pdo, int $accountId, ?int $instanceId): ?int
    {
        try {
            $sql = 'SELECT DISTINCT wi.responsavel_user_id FROM whatsapp_instances wi
                      JOIN users u ON u.id = wi.responsavel_user_id AND u.account_id = wi.account_id
                                  AND u.deleted_at IS NULL AND u.status = \'active\'
                     WHERE wi.account_id = ? AND wi.responsavel_user_id IS NOT NULL';
            $par = [$accountId];
            if ($instanceId) { $sql .= ' AND wi.id = ?'; $par[] = $instanceId; }
            $st = $pdo->prepare($sql);
            $st->execute($par);
            $donos = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
            return count($donos) === 1 ? $donos[0] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * O card nasceu com o telefone no nome (a mensagem saiu antes de alguém dizer
     * qual empresa era). Quando o nome da empresa chega, ele entra no lugar. Nome
     * que alguém já escreveu não é tocado.
     */
    private static function nomearSeAnonimo(int $cardId, string $empresa): void
    {
        $empresa = mb_substr(trim($empresa), 0, 180);
        $card = \App\Prospeccao\Card::find($cardId);
        if (!$card) return;
        $dados = [];
        if (trim((string)($card['empresa_nome'] ?? '')) === '') $dados['empresa_nome'] = $empresa;
        foreach (['cliente_nome', 'titulo'] as $campo) {
            $v = trim((string)($card[$campo] ?? ''));
            if ($v === '' || preg_match('/^[+0-9 ()-]+$/', $v)) $dados[$campo] = $empresa;
        }
        if ($dados) \App\Prospeccao\Card::update($cardId, $dados);
    }

    /**
     * Move o card para a etapa, se a automação pode. Devolve true quando moveu.
     * Com $forcar (pessoa escolhendo no Chat) a regra de origem não vale.
     */
    public static function moverEtapa(int $accountId, int $cardId, string $etapa, ?int $usuarioId = null, bool $forcar = false): bool
    {
        if (!isset(self::ETAPAS[$etapa])) return false;
        $pdo = \App\Core\Database::getConnection();
        $colunas = self::colunasDaConta($pdo, $accountId);
        if (!isset($colunas[$etapa])) return false;

        $card = \App\Prospeccao\Card::find($cardId);
        if (!$card || (int)$card['account_id'] !== $accountId) return false;
        $atual = (int)($card['coluna_id'] ?? 0);
        if ($atual === $colunas[$etapa]) return false;

        if (!$forcar) {
            $etapaAtual = array_search($atual, $colunas, true);
            if ($etapaAtual === false || !in_array($etapaAtual, self::AUTO_DE[$etapa] ?? [], true)) return false;
        }
        return (bool)\App\Prospeccao\Card::move($cardId, $colunas[$etapa], 0, $usuarioId);
    }

    /**
     * Webhook: mensagem nova numa conversa individual de conta Fleetiflow, nos
     * DOIS sentidos. A captação da Yuris só olha mensagem recebida, e a
     * prospecção ativa começa com a NOSSA mensagem: por isso o lead que ainda não
     * respondeu nunca virava card. Mensagem digitada no aparelho = uma pessoa do
     * time atendendo, então o card vai para "Em atendimento pelo especialista".
     */
    public static function aoMensagem(int $accountId, int $instanceId, string $remoteJid, array $key, bool $fromMe, ?string $origem, ?string $pushName, $ts): void
    {
        try {
            // Reenvio de histórico ao reconectar não é conversa nova.
            if (is_numeric($ts) && abs(time() - (int)$ts) > 900) return;

            if (str_ends_with($remoteJid, '@s.whatsapp.net')) {
                $fone = explode('@', $remoteJid)[0];
            } elseif (str_ends_with($remoteJid, '@lid') && str_ends_with((string)($key['remoteJidAlt'] ?? ''), '@s.whatsapp.net')) {
                $fone = explode('@', (string)$key['remoteJidAlt'])[0];
            } else {
                return; // grupo, canal, ou @lid sem telefone
            }

            $cardId = self::garantirCard($accountId, $instanceId, $remoteJid, $fone, $fromMe ? null : $pushName);
            if ($cardId && $fromMe && in_array(strtolower((string)$origem), self::APARELHO, true)
                && !self::ehTreino($instanceId, $remoteJid, $key)) {
                self::moverEtapa($accountId, $cardId, 'especialista');
                // Celular ou WhatsApp Web: não se sabe QUEM digitou. Vale o
                // responsável já marcado na conversa, ou o especialista padrão.
                self::atribuirEspecialista($accountId, $cardId, null, $instanceId, $remoteJid);
            }
        } catch (\Throwable $e) {
            error_log('[sdr_fleetiflow] card da conversa falhou (mensagem preservada): ' . $e->getMessage());
        }
    }

    /**
     * Conversa individual sem card vivo, cujo telefone já é card da conta: liga as
     * duas. Cobre o que os outros caminhos deixam passar (conversa que entrou pela
     * sincronização e não pelo webhook, card criado à mão na Prospecção, card
     * apagado e refeito). Não cria card: conversa sem card de mesmo telefone não é
     * lead (equipe, fornecedor), e quem decide é a pessoa escolhendo a etapa.
     * Chamado na lista do Chat, então a etapa aparece no próximo refresh.
     *
     * @return int quantas conversas foram ligadas
     */
    public static function ligarConversasSoltas(\PDO $pdo, int $accountId, int $instanceId): int
    {
        $st = $pdo->prepare(
            "SELECT w.remote_jid,
                    COALESCE(
                      (SELECT i.phone FROM whatsapp_identidades i
                        WHERE i.instance_id = w.instance_id AND (i.lid = w.remote_jid OR i.jid = w.remote_jid)
                          AND i.phone REGEXP '^[0-9]{10,13}$' LIMIT 1),
                      CASE WHEN w.remote_jid LIKE '%@s.whatsapp.net' THEN SUBSTRING_INDEX(w.remote_jid, '@', 1) END
                    ) AS fone
               FROM whatsapp_chats w
          LEFT JOIN cards c ON c.id = w.linked_card_id AND c.deleted_at IS NULL
              WHERE w.instance_id = ? AND w.is_group = 0
                AND w.remote_jid NOT LIKE '%@g.us' AND w.remote_jid NOT LIKE '%@broadcast'
                AND c.id IS NULL"
        );
        $st->execute([$instanceId]);
        $soltas = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (!$soltas) return 0;

        $busca = $pdo->prepare(
            "SELECT id FROM cards
              WHERE account_id = ? AND deleted_at IS NULL
                AND RIGHT(REGEXP_REPLACE(COALESCE(telefone_whatsapp,''), '[^0-9]', ''), 8) = ?
           ORDER BY id DESC LIMIT 1"
        );
        $modelo = new WhatsAppMessage();
        $ligadas = 0;
        foreach ($soltas as $s) {
            $fone = preg_replace('/[^0-9]/', '', (string)$s['fone']);
            if (strlen($fone) < 10) continue;
            $busca->execute([$accountId, substr($fone, -8)]);
            $cardId = (int)($busca->fetchColumn() ?: 0);
            if ($cardId <= 0) continue;
            // Vínculo antigo apontando para card apagado sai junto.
            $modelo->linkChat($instanceId, (string)$s['remote_jid'], ['linked_card_id' => $cardId]);
            $ligadas++;
        }
        return $ligadas;
    }

    /**
     * Alguém do time respondeu pelo Chat do CRM: a Vitória sai da conversa e o
     * card vai para "Em atendimento pelo especialista" (se ainda estava com a IA).
     */
    public static function pessoaAssumiu(int $accountId, int $instanceId, string $remoteJid, ?int $userId): void
    {
        self::pausar($instanceId, $remoteJid, $userId);
        try {
            $pdo = \App\Core\Database::getConnection();
            $st = $pdo->prepare('SELECT linked_card_id FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1');
            $st->execute([$instanceId, $remoteJid]);
            $cardId = (int)($st->fetchColumn() ?: 0) ?: null;
            if (!$cardId && str_ends_with($remoteJid, '@s.whatsapp.net')) {
                $cardId = self::garantirCard($accountId, $instanceId, $remoteJid, explode('@', $remoteJid)[0]);
            }
            if ($cardId) {
                self::moverEtapa($accountId, $cardId, 'especialista', $userId);
                // Pelo Chat do CRM sabemos quem respondeu: essa pessoa é a especialista.
                self::atribuirEspecialista($accountId, $cardId, $userId, $instanceId, $remoteJid);
            }
        } catch (\Throwable $e) {
            error_log('[sdr_fleetiflow] etapa do atendimento humano falhou: ' . $e->getMessage());
        }
    }

    /**
     * Card em atendimento tem de dizer QUEM atende. Antes o card ia para "Em
     * atendimento pelo especialista" e ficava "Sem consultor" (01/10/2026).
     *
     * Só preenche card SEM responsável (quem já tem consultor não é trocado) e só
     * com usuário ativo da própria conta. Ordem de quem vira o responsável:
     *   1. quem respondeu pelo Chat do CRM ($userId);
     *   2. o responsável marcado na conversa (whatsapp_chats.linked_user_id);
     *   3. o especialista padrão da conta (configuracoes.sdr.especialista_padrao),
     *      para o que é respondido pelo celular ou WhatsApp Web, onde não há
     *      como saber quem digitou.
     *
     * @return int|null o usuário atribuído, ou null se nada mudou
     */
    public static function atribuirEspecialista(int $accountId, int $cardId, ?int $userId, ?int $instanceId = null, ?string $remoteJid = null): ?int
    {
        try {
            $pdo  = \App\Core\Database::getConnection();
            $card = \App\Prospeccao\Card::find($cardId);
            if (!$card || (int)$card['account_id'] !== $accountId) return null;
            if ((int)($card['responsavel_user_id'] ?? 0) > 0) return null;

            $candidatos = [$userId];
            if ($instanceId && $remoteJid) {
                $st = $pdo->prepare('SELECT linked_user_id FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1');
                $st->execute([$instanceId, $remoteJid]);
                $candidatos[] = (int)($st->fetchColumn() ?: 0) ?: null;
            }
            $candidatos[] = self::especialistaPadrao($accountId);

            foreach ($candidatos as $uid) {
                if (!$uid || !self::usuarioAtivoDaConta($pdo, $accountId, (int)$uid)) continue;
                \App\Prospeccao\Card::update($cardId, ['responsavel_user_id' => (int)$uid, '_usuario_id' => $userId]);
                return (int)$uid;
            }
        } catch (\Throwable $e) {
            error_log('[sdr_fleetiflow] responsável do atendimento falhou: ' . $e->getMessage());
        }
        return null;
    }

    /** Especialista padrão da conta (quem atende pelo celular), ou null. */
    public static function especialistaPadrao(int $accountId): ?int
    {
        $conta = Account::findById($accountId);
        $cfg = json_decode((string)($conta['configuracoes'] ?? ''), true);
        $uid = is_array($cfg) ? (int)($cfg['sdr']['especialista_padrao'] ?? 0) : 0;
        return $uid > 0 ? $uid : null;
    }

    /**
     * Grava (ou limpa, com null) o especialista padrão, preservando o resto de
     * `configuracoes` (produto, marca...). Recusa usuário de outra conta.
     */
    public static function definirEspecialistaPadrao(int $accountId, ?int $userId): bool
    {
        $pdo = \App\Core\Database::getConnection();
        if ($userId !== null && !self::usuarioAtivoDaConta($pdo, $accountId, $userId)) return false;
        $st = $pdo->prepare('SELECT configuracoes FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$accountId]);
        $cfg = json_decode((string)$st->fetchColumn(), true);
        if (!is_array($cfg)) $cfg = [];
        if (!isset($cfg['sdr']) || !is_array($cfg['sdr'])) $cfg['sdr'] = [];
        if ($userId === null) unset($cfg['sdr']['especialista_padrao']);
        else $cfg['sdr']['especialista_padrao'] = $userId;
        if ($cfg['sdr'] === []) unset($cfg['sdr']);
        return $pdo->prepare('UPDATE accounts SET configuracoes = ?, updated_at = NOW() WHERE id = ?')
            ->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $accountId]);
    }

    private static function usuarioAtivoDaConta(\PDO $pdo, int $accountId, int $userId): bool
    {
        $st = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL AND status = 'active' LIMIT 1");
        $st->execute([$userId, $accountId]);
        return (bool)$st->fetchColumn();
    }

    /** Pausa a Vitória numa conversa (mesmo escritor do "Assumir conversa"). */
    public static function pausar(int $channelId, string $remoteJid, ?int $userId): void
    {
        // Número de treino: a Vitória precisa continuar recebendo (ver NUMEROS_TREINO).
        if (self::ehTreino($channelId, $remoteJid)) return;
        try {
            require_once __DIR__ . '/AiIntake/IntakeSessionRepository.php';
            $repo = new \App\WhatsAppAgente\AiIntake\IntakeSessionRepository(\App\Core\Database::getConnection());
            $repo->setChatPaused($channelId, $remoteJid, true, $userId);
        } catch (\Throwable $e) {
            error_log('[sdr_fleetiflow] pausa falhou: ' . $e->getMessage());
        }
    }
}
