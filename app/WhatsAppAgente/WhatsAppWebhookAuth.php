<?php

namespace App\WhatsAppAgente;

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
 * derrubar a entrega.
 *
 * MODO ESTRITO (Fase C, por canal via whatsapp_settings.webhook_token_strict):
 * quando $strict = true, o token AUSENTE tambem rejeita (COMPAT vira REJECT),
 * fechando a janela de compatibilidade. So se liga por canal DEPOIS de a aba
 * "Saude do Webhook" confirmar que aquele canal ja envia o cracha (zero compat).
 * O estrito nao tem efeito sem token configurado (expected vazio curto-circuita
 * em OK), entao ligar o estrito num canal sem token e inocuo.
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
     * @param bool         $strict   modo estrito por canal (Fase C): token ausente tambem rejeita
     * @return string self::OK | self::COMPAT | self::REJECT
     */
    public static function verify(?string $expected, ?string $provided, bool $strict = false): string
    {
        $expected = trim((string) $expected);
        if ($expected === '') {
            return self::OK; // tenant nao configurou o 2o fator: comportamento atual (retrocompat)
        }
        $provided = trim((string) $provided);
        if ($provided === '') {
            // Fase A->B: por padrao aceita e loga (COMPAT). No modo ESTRITO (Fase C),
            // ligado por canal so depois que ele ja envia o cracha, o token ausente
            // tambem rejeita — fecha a janela de compatibilidade.
            return $strict ? self::REJECT : self::COMPAT;
        }
        return hash_equals($expected, $provided) ? self::OK : self::REJECT;
    }
}
