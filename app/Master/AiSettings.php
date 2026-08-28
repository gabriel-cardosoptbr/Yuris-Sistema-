<?php
namespace App\Master;

use App\Core\Crypto;


/**
 * AiSettings — config GLOBAL de IA da plataforma (Inovaize), em app_settings (key/value).
 *
 * Hoje guarda a Security Key da OpenAI usada por TODAS as instancias do agente: o super
 * admin cadastra uma vez no Painel Master e os escritorios nao precisam informar chave.
 * A chave fica cifrada (AES-256-GCM via Crypto/APP_ENCRYPTION_KEY) e nunca volta em claro.
 *
 * Reutilizado pelo endpoint Master (api/master/ai_openai.php) e pelo webhook (resolucao da
 * chave do agente, com a chave do canal tendo prioridade sobre a global).
 */
final class AiSettings
{
    public const OPENAI_KEY = 'openai_global_api_key_enc';

    /** Chave OpenAI global DECIFRADA, ou null se nao houver / indecifravel. */
    public static function openAiKey(\PDO $pdo): ?string
    {
        $st = $pdo->prepare('SELECT config_value FROM app_settings WHERE config_key = ?');
        $st->execute([self::OPENAI_KEY]);
        $enc = $st->fetchColumn();
        if (!$enc) return null;
        try {
            $k = (string)Crypto::decrypt((string)$enc);
            return $k !== '' ? $k : null;
        } catch (\Throwable $_) {
            error_log('[AiSettings] openai key indecifravel (APP_ENCRYPTION_KEY?)');
            return null;
        }
    }

    /** Salva a chave OpenAI global (cifrada). Lanca se APP_ENCRYPTION_KEY ausente/invalida. */
    public static function saveOpenAiKey(\PDO $pdo, string $plain): void
    {
        $enc = Crypto::encrypt($plain);
        $pdo->prepare('INSERT INTO app_settings (config_key, config_value) VALUES (?, ?)
                       ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)')
            ->execute([self::OPENAI_KEY, $enc]);
    }

    public static function clearOpenAiKey(\PDO $pdo): void
    {
        $pdo->prepare('DELETE FROM app_settings WHERE config_key = ?')->execute([self::OPENAI_KEY]);
    }

    /** Status para a UI: { has_key, masked }. Nunca devolve a chave em claro. */
    public static function openAiStatus(\PDO $pdo): array
    {
        $k = self::openAiKey($pdo);
        return [
            'has_key' => $k !== null,
            'masked'  => $k !== null ? (str_repeat('*', max(0, strlen($k) - 4)) . substr($k, -4)) : '',
        ];
    }
}
