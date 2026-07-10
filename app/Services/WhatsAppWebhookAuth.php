<?php

namespace App\Services;

/**
 * WhatsAppWebhookAuth — validacao do 2o fator (webhook_token) do webhook inbound
 * da Evolution (B3, Onda 4).
 *
 * O tenant e resolvido pela apikey (WhatsAppInstance::findAccountByApiKey). Este
 * helper adiciona um SEGUNDO fator: um segredo DEDICADO ao webhook
 * (whatsapp_settings.webhook_token), separado da evolution_api_key, que a Evolution
 * passa a enviar em cada evento. Assim o segredo de entrega deixa de ser a mesma
 * chave que autentica NA Evolution, e pode ser rotacionado sem mexer na conexao.
 *
 * ROLLOUT COMPATIVEL (nao quebra producao):
 *   - tenant SEM webhook_token configurado          -> OK      (comportamento atual)
 *   - tenant COM token, requisicao SEM token         -> COMPAT  (janela Fase A->B: aceita e loga)
 *   - tenant COM token, requisicao COM token correto -> OK
 *   - tenant COM token, requisicao COM token ERRADO  -> REJECT  (401)
 *
 * So um token PRESENTE e ERRADO rejeita: isso permite LIGAR o token no Painel
 * (Fase A) ANTES de a Evolution ser reconfigurada para envia-lo (Fase B), sem
 * derrubar a entrega. O endurecimento (token ausente tambem rejeita) e a Fase C.
 *
 * A comparacao usa hash_equals (constant-time) para nao vazar o segredo por timing.
 *
 * Funcao PURA (sem I/O, sem DB, sem estado): caracterizada em
 * scripts/tests/wa_webhook_token_test.php.
 */
final class WhatsAppWebhookAuth
{
    /** Sem token configurado, ou token recebido bate: aceita. */
    public const OK = 'ok';

    /** Token configurado mas ausente na requisicao (janela de transicao): aceita e loga. */
    public const COMPAT = 'compat';

    /** Token configurado e presente, mas ERRADO: rejeita (401). */
    public const REJECT = 'reject';

    /**
     * @param string|null $expected webhook_token gravado no tenant (whatsapp_settings)
     * @param string|null $provided token recebido na requisicao (header X-Webhook-Token ou ?wtoken=)
     * @return string self::OK | self::COMPAT | self::REJECT
     */
    public static function verify(?string $expected, ?string $provided): string
    {
        $expected = trim((string) $expected);
        if ($expected === '') {
            return self::OK; // tenant nao configurou o 2o fator: comportamento atual (retrocompat)
        }
        $provided = trim((string) $provided);
        if ($provided === '') {
            return self::COMPAT; // Evolution ainda nao envia o token (janela Fase A->B)
        }
        return hash_equals($expected, $provided) ? self::OK : self::REJECT;
    }
}
