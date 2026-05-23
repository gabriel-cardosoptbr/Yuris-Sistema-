<?php
namespace App\Services;

use App\Models\Database;
use App\Helpers\EnvLoader;

/**
 * Mailer — fila de e-mails transacionais.
 *
 * Comportamento:
 *   - send() apenas ENFILEIRA em emails_outbox (não envia direto).
 *   - Worker/cron processa pendentes via processQueue() em intervalos.
 *   - Driver padrão é 'log' (escreve em storage/mail_outbox.log) — útil pra dev.
 *   - Em prod, configure SMTP via .env (MAIL_DRIVER=smtp) e implemente
 *     o branch SMTP em deliver().
 *
 * Uso:
 *   Mailer::send([
 *     'to_email' => 'cliente@example.com',
 *     'to_name'  => 'Cliente',
 *     'subject'  => 'Bem-vindo ao Yuris',
 *     'body_html'=> '<p>Olá!</p>',
 *     'account_id' => 42,
 *     'template_key' => 'welcome',
 *   ]);
 *
 *   // No cron:
 *   Mailer::processQueue(50);  // processa até 50 mensagens pendentes
 */
final class Mailer
{
    /** Enfileira o e-mail pra envio assíncrono. Retorna id da fila. */
    public static function send(array $params): int
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO emails_outbox
               (account_id, to_email, to_name, subject, body_html, body_text, template_key, scheduled_at, status)
             VALUES
               (:aid, :te, :tn, :sub, :html, :txt, :tpl, :sch, "pending")'
        );
        $stmt->execute([
            'aid'  => $params['account_id']   ?? null,
            'te'   => $params['to_email']     ?? '',
            'tn'   => $params['to_name']      ?? null,
            'sub'  => $params['subject']      ?? '(sem assunto)',
            'html' => $params['body_html']    ?? '',
            'txt'  => $params['body_text']    ?? strip_tags($params['body_html'] ?? ''),
            'tpl'  => $params['template_key'] ?? null,
            'sch'  => $params['scheduled_at'] ?? date('Y-m-d H:i:s'),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** Processa fila — chamada pelo cron. */
    public static function processQueue(int $batchSize = 25): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'SELECT * FROM emails_outbox
             WHERE status = "pending" AND scheduled_at <= NOW() AND retries < 5
             ORDER BY id ASC LIMIT :n'
        );
        $stmt->bindValue('n', $batchSize, \PDO::PARAM_INT);
        $stmt->execute();
        $emails = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $sent = []; $failed = [];
        foreach ($emails as $em) {
            // Marca como sending pra evitar reprocessamento concorrente
            $pdo->prepare('UPDATE emails_outbox SET status="sending" WHERE id = :id')
                ->execute(['id' => $em['id']]);

            try {
                self::deliver($em);
                $pdo->prepare(
                    'UPDATE emails_outbox SET status="sent", sent_at=NOW(), error=NULL WHERE id=:id'
                )->execute(['id' => $em['id']]);
                $sent[] = $em['id'];
            } catch (\Throwable $e) {
                $pdo->prepare(
                    'UPDATE emails_outbox SET status="pending", retries=retries+1, error=:e WHERE id=:id'
                )->execute(['id' => $em['id'], 'e' => $e->getMessage()]);
                $failed[] = $em['id'];
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Entrega real. Driver padrão: log no arquivo. */
    private static function deliver(array $email): void
    {
        $driver = EnvLoader::get('MAIL_DRIVER', 'log');

        if ($driver === 'log') {
            $line = sprintf(
                "[%s] TO=%s SUBJECT=%s TEMPLATE=%s ACCOUNT_ID=%s\n",
                date('c'),
                $email['to_email'],
                $email['subject'],
                $email['template_key'] ?? '-',
                $email['account_id']   ?? '-'
            );
            $logDir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'storage';
            if (!is_dir($logDir)) @mkdir($logDir, 0775, true);
            @file_put_contents($logDir . DIRECTORY_SEPARATOR . 'mail_outbox.log', $line, FILE_APPEND);
            return;
        }

        if ($driver === 'smtp') {
            // TODO: implementar PHPMailer/SymfonyMailer aqui.
            // Por enquanto, lança exceção pra registrar como falha.
            throw new \RuntimeException('Driver SMTP não implementado ainda. Configure ou use MAIL_DRIVER=log.');
        }

        throw new \RuntimeException("Driver de mail desconhecido: {$driver}");
    }
}
