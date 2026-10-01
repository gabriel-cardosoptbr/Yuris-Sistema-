<?php
namespace App\Prospeccao;

use App\Core\Database;
use App\Master\Account;

/**
 * Termometro: o que faz um lead da edição CRM ser quente, morno, frio ou congelado.
 *
 * Antes o termômetro do card era uma conta fixa herdada do jurídico (valor,
 * checklist e prazo), que não fala de prospecção: quase todo lead aparecia
 * "Morno". Agora cada conta define a regra no botão "Termômetro" da Prospecção,
 * guardada em `accounts.configuracoes.termometro`:
 *
 *   {
 *     "quente_dias": 2,   último contato há até 2 dias        -> Quente
 *     "morno_dias":  7,   até 7 dias                          -> Morno
 *     "frio_dias":   30,  até 30 dias                         -> Frio
 *                         mais que isso, ou nunca             -> Congelado
 *     "etapas_quentes":    ["qualificado","especialista","negociacao"],
 *     "etapas_congeladas": ["bloqueado","fora_escopo","perdido"]
 *   }
 *
 * A ETAPA vence o tempo (lead em negociação é quente mesmo sem mensagem hoje;
 * lead perdido é congelado mesmo com mensagem recente), e a temperatura
 * escolhida à mão no card (`cards.temperatura`) vence tudo. "Último contato" é a
 * última mensagem da conversa ligada ao card, ou a última alteração do card.
 *
 * A tela da Prospecção aplica a mesma regra em JS (getTemperatureBadge) com a
 * configuração que esta classe entrega. `classificar()` é o espelho em PHP,
 * PURO, usado pelos testes.
 */
final class Termometro
{
    public const NIVEIS = ['quente', 'morno', 'frio', 'congelado'];

    public const PADRAO = [
        'quente_dias'       => 2,
        'morno_dias'        => 7,
        'frio_dias'         => 30,
        'etapas_quentes'    => ['qualificado', 'especialista', 'negociacao'],
        'etapas_congeladas' => ['bloqueado', 'fora_escopo', 'perdido'],
    ];

    /** Chaves de etapa aceitas (as do funil comercial, App\WhatsAppAgente\SdrFleetiflow::ETAPAS). */
    private static function etapasValidas(): array
    {
        return array_keys(\App\WhatsAppAgente\SdrFleetiflow::ETAPAS);
    }

    /** A regra da conta, completa (o que faltar vem do padrão). */
    public static function daConta(int $accountId): array
    {
        $conta = Account::findById($accountId);
        $cfg = json_decode((string)($conta['configuracoes'] ?? ''), true);
        $t = is_array($cfg) && is_array($cfg['termometro'] ?? null) ? $cfg['termometro'] : [];
        try {
            return self::normalizar($t + self::PADRAO);
        } catch (\InvalidArgumentException $e) {
            return self::PADRAO; // gravado inválido à mão: volta ao padrão, nunca quebra a tela
        }
    }

    /**
     * Confere e limpa a regra vinda da tela. Lança InvalidArgumentException com
     * mensagem para o usuário. PURA.
     */
    public static function normalizar(array $in): array
    {
        $dias = [];
        foreach (['quente_dias', 'morno_dias', 'frio_dias'] as $k) {
            $v = $in[$k] ?? self::PADRAO[$k];
            if (!is_numeric($v) || (int)$v < 0 || (int)$v > 365 || (string)(int)$v !== trim((string)$v)) {
                throw new \InvalidArgumentException('Os dias têm de ser números inteiros entre 0 e 365.');
            }
            $dias[$k] = (int)$v;
        }
        if (!($dias['quente_dias'] < $dias['morno_dias'] && $dias['morno_dias'] < $dias['frio_dias'])) {
            throw new \InvalidArgumentException('Os dias têm de crescer: quente < morno < frio.');
        }
        $validas = self::etapasValidas();
        $etapas = static fn($lista) => array_values(array_unique(array_filter(
            is_array($lista) ? array_map('strval', $lista) : [],
            static fn($e) => in_array($e, $validas, true)
        )));
        $quentes    = $etapas($in['etapas_quentes'] ?? []);
        $congeladas = $etapas($in['etapas_congeladas'] ?? []);
        if (array_intersect($quentes, $congeladas)) {
            throw new \InvalidArgumentException('Uma etapa não pode ser quente e congelada ao mesmo tempo.');
        }
        return $dias + ['etapas_quentes' => $quentes, 'etapas_congeladas' => $congeladas];
    }

    /** Grava a regra da conta, preservando o resto de `configuracoes`. */
    public static function gravar(int $accountId, array $regra): void
    {
        $regra = self::normalizar($regra);
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT configuracoes FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$accountId]);
        $cfg = json_decode((string)$st->fetchColumn(), true);
        if (!is_array($cfg)) $cfg = [];
        $cfg['termometro'] = $regra;
        $pdo->prepare('UPDATE accounts SET configuracoes = ?, updated_at = NOW() WHERE id = ?')
            ->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $accountId]);
    }

    /**
     * A temperatura de um card. PURA (o espelho da tela).
     *
     * @param ?string $manual   cards.temperatura (escolhida à mão), ou null
     * @param ?string $etapa    chave da etapa do card ('novo', 'negociacao'...), ou null
     * @param ?int    $diasSemContato dias desde o último contato, null = nunca
     */
    public static function classificar(array $regra, ?string $manual, ?string $etapa, ?int $diasSemContato): string
    {
        if ($manual !== null && in_array($manual, self::NIVEIS, true)) return $manual;
        if ($etapa !== null && in_array($etapa, $regra['etapas_congeladas'], true)) return 'congelado';
        if ($etapa !== null && in_array($etapa, $regra['etapas_quentes'], true)) return 'quente';
        if ($diasSemContato === null) return 'congelado';
        if ($diasSemContato <= $regra['quente_dias']) return 'quente';
        if ($diasSemContato <= $regra['morno_dias'])  return 'morno';
        if ($diasSemContato <= $regra['frio_dias'])   return 'frio';
        return 'congelado';
    }
}
