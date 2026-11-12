<?php
declare(strict_types=1);

namespace App\Billing;

/**
 * O período de teste grátis de uma conta (12/11/2026).
 *
 * Vale só para a conta que está, ao mesmo tempo, com `accounts.status = 'trial'`,
 * no plano `teste_gratis`, com a assinatura em `trialing` **e com a marca
 * `configuracoes.periodo_teste`** (posta por scripts/criar_conta_teste.php). Tudo
 * junto, de propósito: há conta pagante com a assinatura esquecida em `trialing`
 * desde a criação no Master, e contas de teste antigas (78, 79 e 80 em produção)
 * no plano teste_gratis com o prazo vencido em julho de 2026; nenhuma delas pode
 * ganhar tag nem bloqueio que ninguém combinou.
 *
 * O prazo começa a contar no primeiro login, e não na criação: a conta nasce com
 * `configuracoes.periodo_teste = {"dias": 3, "inicio": null}` e o primeiro login
 * grava o início e move `subscriptions.trial_ends_at` para o início mais os dias.
 * Depois disso, nenhum login mexe no prazo de novo.
 *
 * Encerrado o prazo, a conta é bloqueada (12/11/2026, "bloquear ao vencer"):
 * `mensagemBloqueio()` é consultada no login, no "Lembrar", no
 * `AccountContext::assertAccountActive()` e no menu. O bloqueio é calculado, não
 * gravado: para liberar, a equipe muda no Painel Master a data "Teste grátis até"
 * (ou passa a conta para `active`), e o acesso volta na hora.
 */
final class PeriodoTeste
{
    public const PLANO = 'teste_gratis';

    /**
     * O estado do teste da conta, ou null quando ela não está em teste grátis.
     *
     * @return array{ate:?string, dias:?int, aguardando_login:bool, encerrado:bool, rotulo:string, detalhe:string}|null
     */
    public static function daConta(\PDO $pdo, int $accountId, ?\DateTimeImmutable $agora = null): ?array
    {
        if ($accountId <= 0) return null;
        $st = $pdo->prepare(
            "SELECT a.configuracoes, s.trial_ends_at
               FROM accounts a
               JOIN subscriptions s ON s.account_id = a.id AND s.status = 'trialing'
               JOIN plans p ON p.id = s.plan_id AND p.slug = :slug
              WHERE a.id = :aid AND a.status = 'trial' AND a.deleted_at IS NULL
           ORDER BY s.id DESC LIMIT 1"
        );
        $st->execute(['slug' => self::PLANO, 'aid' => $accountId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        $cfg = json_decode((string) ($row['configuracoes'] ?? ''), true);
        $pt  = is_array($cfg['periodo_teste'] ?? null) ? $cfg['periodo_teste'] : null;
        if ($pt === null) return null; // conta de teste antiga, sem a marca: nem tag nem bloqueio
        $aguardando = empty($pt['inicio']);

        return self::estado($row['trial_ends_at'] ?: null, $aguardando, $agora ?? self::agora(), (int) ($pt['dias'] ?? 0));
    }

    public const MENSAGEM_ENCERRADO = 'Seu período de teste grátis terminou. Fale com a equipe do Yuris para continuar usando o sistema.';

    /**
     * O teste da conta terminou? Devolve a mensagem para a tela de login, ou null
     * quando o acesso segue liberado (conta fora do teste, teste em andamento ou
     * ainda esperando o primeiro login). Nunca lança: na dúvida, libera.
     */
    public static function mensagemBloqueio(\PDO $pdo, int $accountId): ?string
    {
        try {
            $e = self::daConta($pdo, $accountId);
        } catch (\Throwable $t) {
            error_log('[periodo_teste/bloqueio] ' . $t->getMessage());
            return null;
        }
        return ($e !== null && $e['encerrado']) ? self::MENSAGEM_ENCERRADO : null;
    }

    /**
     * Regra pura: dado o fim do teste, diz quantos dias faltam e o texto da tag.
     * Dias contados por data de calendário em São Paulo: termina amanhã = 1 dia.
     */
    public static function estado(?string $trialEndsAt, bool $aguardandoLogin, \DateTimeImmutable $agora, int $diasContratados = 0): array
    {
        if ($aguardandoLogin) {
            $d = max(1, $diasContratados);
            return [
                'ate' => null, 'dias' => $d, 'aguardando_login' => true, 'encerrado' => false,
                'rotulo'  => 'Teste grátis · ' . self::diasTexto($d),
                'detalhe' => 'Todos os módulos liberados para você testar.',
            ];
        }
        if ($trialEndsAt === null) {
            return ['ate' => null, 'dias' => null, 'aguardando_login' => false, 'encerrado' => false,
                    'rotulo' => 'Teste grátis', 'detalhe' => 'Todos os módulos liberados para você testar.'];
        }
        $fuso = new \DateTimeZone('America/Sao_Paulo');
        // O banco grava em UTC (Core/Database.php); a tela mostra a hora de São Paulo.
        $fim  = (new \DateTimeImmutable($trialEndsAt, new \DateTimeZone('UTC')))->setTimezone($fuso);
        $hoje = $agora->setTimezone($fuso);
        if ($fim <= $hoje) {
            return ['ate' => $fim->format('Y-m-d H:i:s'), 'dias' => 0, 'aguardando_login' => false, 'encerrado' => true,
                    'rotulo' => 'Teste grátis encerrado', 'detalhe' => 'Fale com a equipe do Yuris para continuar usando.'];
        }
        $dias = (int) $hoje->setTime(0, 0)->diff($fim->setTime(0, 0))->days;
        $rotulo = $dias === 0 ? 'Teste grátis · termina hoje às ' . $fim->format('H:i') : 'Teste grátis · ' . self::diasTexto($dias);
        return [
            'ate' => $fim->format('Y-m-d H:i:s'), 'dias' => $dias, 'aguardando_login' => false, 'encerrado' => false,
            'rotulo'  => $rotulo,
            'detalhe' => 'Todos os módulos liberados até ' . $fim->format('d/m') . ' às ' . $fim->format('H:i') . '.',
        ];
    }

    public static function diasTexto(int $dias): string
    {
        return $dias === 1 ? 'falta 1 dia' : 'faltam ' . $dias . ' dias';
    }

    /**
     * Primeiro login: começa a contar o teste. Idempotente; devolve true só na vez
     * que gravou. Nunca derruba o login (quem chama envolve em try/catch).
     */
    public static function iniciarNoPrimeiroLogin(\PDO $pdo, int $accountId): bool
    {
        if ($accountId <= 0) return false;
        $st = $pdo->prepare("SELECT configuracoes FROM accounts WHERE id = :aid AND status = 'trial' AND deleted_at IS NULL LIMIT 1");
        $st->execute(['aid' => $accountId]);
        $bruto = $st->fetchColumn();
        if ($bruto === false) return false;
        $cfg = json_decode((string) $bruto, true);
        if (!is_array($cfg) || !is_array($cfg['periodo_teste'] ?? null) || !empty($cfg['periodo_teste']['inicio'])) return false;

        $dias = max(1, min(90, (int) ($cfg['periodo_teste']['dias'] ?? 3)));

        $pdo->beginTransaction();
        try {
            // A condição no JSON evita que dois logins simultâneos gravem duas vezes.
            // JSON_SET mexe só na chave (o resto de configuracoes fica como está), e a
            // comparação funciona igual no MySQL 8 (coluna JSON) e no MariaDB (texto):
            // chave ausente dá NULL e null do JSON dá 'null'.
            $up = $pdo->prepare(
                "UPDATE accounts SET configuracoes = JSON_SET(configuracoes, '$.periodo_teste.inicio', :ini)
                  WHERE id = :aid
                    AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(configuracoes, '$.periodo_teste.inicio')), 'null') = 'null'"
            );
            $up->execute(['ini' => gmdate('Y-m-d H:i:s'), 'aid' => $accountId]); // UTC, como o resto do banco
            if ($up->rowCount() !== 1) { $pdo->rollBack(); return false; }
            $pdo->prepare(
                "UPDATE subscriptions s JOIN plans p ON p.id = s.plan_id AND p.slug = :slug
                    SET s.trial_ends_at = DATE_ADD(NOW(), INTERVAL :d DAY), s.current_period_start = NOW()
                  WHERE s.account_id = :aid AND s.status = 'trialing'"
            )->execute(['slug' => self::PLANO, 'd' => $dias, 'aid' => $accountId]);
            $pdo->commit();
            return true;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    private static function agora(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('America/Sao_Paulo'));
    }
}
