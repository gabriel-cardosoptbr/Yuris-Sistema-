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
 * Sem a URL nada é encaminhado, e a chave do canal não liga.
 */
final class SdrFleetiflow
{
    /** Nome que aparece na chave do Chat quando o canal é Fleetiflow. */
    public const NOME_AGENTE = 'Vitória (pré-qualificação)';

    /** Origens de mensagem própria que são uma pessoa no aparelho, não a API. */
    private const APARELHO = ['android', 'ios', 'desktop'];

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

    public static function url(): string
    {
        return trim((string)EnvLoader::get('FLEETIFLOW_SDR_WEBHOOK_URL', ''));
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
        $url = self::url();
        if ($url === '') {
            error_log('[sdr_fleetiflow] FLEETIFLOW_SDR_WEBHOOK_URL vazio: mensagem não encaminhada');
            return;
        }
        try {
            $corpo = json_encode([
                'event'  => 'messages.upsert',
                'origem' => 'yuris',
                'data'   => $task['payload'] ?? [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $cabecalhos = ['Content-Type: application/json'];
            $token = trim((string)EnvLoader::get('FLEETIFLOW_SDR_WEBHOOK_TOKEN', ''));
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

            if ($cardId) {
                $card = \App\Prospeccao\Card::find($cardId);
                $antes = trim((string)($card['descricao'] ?? ''));
                \App\Prospeccao\Card::update($cardId, ['descricao' => trim($antes . "\n\n" . $resumo)]);
            } else {
                $coluna = \App\Prospeccao\CaptacaoAutomatica::primeiraColuna($pdo, $accountId);
                if ($coluna !== null) {
                    $nome = $empresa !== '' ? $empresa : ($contato !== '' ? $contato
                        : ($fone !== '' ? \App\Prospeccao\CaptacaoAutomatica::telefoneLegivel($fone) : 'Lead do WhatsApp'));
                    $cardId = \App\Prospeccao\Card::create([
                        'account_id'        => $accountId,
                        'cliente_nome'      => mb_substr($contato !== '' ? $contato : $nome, 0, 180),
                        'empresa_nome'      => $empresa !== '' ? mb_substr($empresa, 0, 180) : null,
                        'titulo'            => mb_substr($nome, 0, 180),
                        'telefone_whatsapp' => $fone !== '' ? $fone : null,
                        'coluna_id'         => $coluna,
                        'ordem_na_coluna'   => 0,
                        'status'            => 'aberto',
                        'descricao'         => $resumo,
                        '_usuario_id'       => null,
                    ]);
                    $cardId = $cardId ? (int)$cardId : null;
                    if ($cardId) {
                        $pdo->prepare('UPDATE whatsapp_chats SET linked_card_id = ? WHERE instance_id = ? AND remote_jid = ? AND linked_card_id IS NULL')
                            ->execute([$cardId, $channelId, $remoteJid]);
                    }
                }
            }
            if ($cardId) {
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

    /** Pausa a Vitória numa conversa (mesmo escritor do "Assumir conversa"). */
    public static function pausar(int $channelId, string $remoteJid, ?int $userId): void
    {
        try {
            require_once __DIR__ . '/AiIntake/IntakeSessionRepository.php';
            $repo = new \App\WhatsAppAgente\AiIntake\IntakeSessionRepository(\App\Core\Database::getConnection());
            $repo->setChatPaused($channelId, $remoteJid, true, $userId);
        } catch (\Throwable $e) {
            error_log('[sdr_fleetiflow] pausa falhou: ' . $e->getMessage());
        }
    }
}
