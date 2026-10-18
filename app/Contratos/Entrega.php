<?php
namespace App\Contratos;

use App\Core\Database;
use App\WhatsAppAgente\EvolutionApiService;
use App\WhatsAppAgente\WhatsAppChannelAccessService;
use App\WhatsAppAgente\WhatsAppInstance;

/**
 * Entrega do convite e do código de uso único pelo WhatsApp do número conectado
 * da PRÓPRIA conta (a mesma instância da Evolution que o Chat usa; nada de segunda
 * instância). O texto do código nunca vai para log.
 *
 * Os testes trocam o envio por um falso (Entrega::$enviador).
 */
final class Entrega
{
    /** @var callable|null fn(int $accountId, string $numero, string $texto): array{ok:bool, erro?:string} */
    public static $enviador = null;

    /** @return array{ok:bool, erro?:string} */
    public static function whatsapp(int $accountId, string $telefone, string $texto): array
    {
        $numero = self::numeroInternacional($telefone);
        if ($numero === null) return ['ok' => false, 'erro' => 'Telefone inválido.'];
        if (self::$enviador) return (self::$enviador)($accountId, $numero, $texto);
        // Só fora de produção: grava a mensagem num arquivo em vez de mandar (teste da página no navegador).
        $falso = \App\Core\EnvLoader::get('CONTRATOS_WHATSAPP_ARQUIVO_TESTE');
        if ($falso !== '' && strtolower(\App\Core\EnvLoader::get('APP_ENV')) !== 'production') {
            file_put_contents($falso, '[' . gmdate('c') . "] conta $accountId para $numero\n$texto\n\n", FILE_APPEND | LOCK_EX);
            return ['ok' => true];
        }

        $pdo = Database::getConnection();
        $canal = WhatsAppChannelAccessService::ownChannelId($pdo, $accountId);
        $ch = $canal ? WhatsAppChannelAccessService::check($pdo, $accountId, $canal, 'send') : null;
        if (!$ch) return ['ok' => false, 'erro' => 'A conta não tem número de WhatsApp conectado.'];
        if ((string) ($ch['status'] ?? '') !== 'open') return ['ok' => false, 'erro' => 'O WhatsApp da conta está desconectado.'];
        try {
            $inst = new WhatsAppInstance();
            $row  = $inst->find($canal);
            $cfg  = WhatsAppInstance::aplicarCanal($inst->getSettings((int) $ch['owner_account_id']), is_array($row) ? $row : []);
            $r    = (new EvolutionApiService($cfg))->sendText((string) $ch['instance_name'], $numero . '@s.whatsapp.net', $texto);
        } catch (\Throwable $e) {
            error_log('[contratos] falha ao enviar WhatsApp pela conta ' . $accountId . ': ' . get_class($e));
            return ['ok' => false, 'erro' => 'O WhatsApp não aceitou a mensagem.'];
        }
        if (!empty($r['_error']) || (int) ($r['_http'] ?? 0) >= 400) {
            error_log('[contratos] WhatsApp recusou envio da conta ' . $accountId . ' http=' . (int) ($r['_http'] ?? 0));
            return ['ok' => false, 'erro' => 'O WhatsApp não aceitou a mensagem.'];
        }
        return ['ok' => true];
    }

    /** "11 99999-8888" → "5511999998888"; null se não parece telefone. */
    public static function numeroInternacional(string $bruto): ?string
    {
        $d = ltrim((string) preg_replace('/\D+/', '', $bruto), '0');
        if (strlen($d) === 10 || strlen($d) === 11) return '55' . $d;
        if (str_starts_with($d, '55') && in_array(strlen($d), [12, 13], true)) return $d;
        if (strlen($d) >= 12 && strlen($d) <= 15) return $d;
        return null;
    }

    /** "5511982624764" → "WhatsApp terminado em 4764". */
    public static function mascarar(string $telefone): string
    {
        $d = preg_replace('/\D+/', '', $telefone) ?? '';
        return 'WhatsApp terminado em ' . substr($d, -4);
    }
}
