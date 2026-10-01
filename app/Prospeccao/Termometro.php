<?php
namespace App\Prospeccao;

use App\Core\Database;
use App\Master\Account;

/**
 * Termometro: o que faz um lead da edição CRM ser quente, morno, frio ou congelado.
 *
 * A regra é MISTA (01/10/2026). Só dias não serve: lead novo de disparo não pode
 * nascer quente só porque o card foi criado hoje. Então a temperatura sai de três
 * coisas, nesta ordem:
 *
 *   1. A ETAPA dá a temperatura de partida, que é também o TETO. O padrão segue o
 *      funil do SDR:
 *        Novos leads            frio       (disparo, ninguém respondeu ainda)
 *        Em qualificação        morno      (respondeu, a IA está conversando)
 *        Follow-up automático   frio       (parou de responder)
 *        Qualificado, Em atendimento, Demonstração   quente
 *        Bloqueado, Fora do escopo, Perdido          congelado
 *      Coluna fora do funil padrão parte de quente, e só o tempo decide.
 *
 *   2. O TEMPO SEM CONTATO só esfria, nunca esquenta: quem passa de `quente_dias`
 *      sem contato não pode ser mais que morno, de `morno_dias` não mais que frio,
 *      de `frio_dias` fica congelado. Lead em atendimento esquecido há 4 dias cai
 *      para morno; lead novo parado há 40 dias congela.
 *
 *   3. A RESPOSTA DO LEAD esquenta até morno (`resposta_esquenta`): se a última
 *      mensagem da conversa é do lead, e de até `quente_dias` atrás, ele está
 *      esperando resposta. Lead novo ou em follow-up que respondeu vira morno;
 *      quem já é morno ou quente não muda (quem esquenta para quente é a etapa,
 *      isto é, gente atendendo).
 *
 * Etapa congelada não esquenta com nada. A temperatura escolhida à mão no card
 * (`cards.temperatura`) vence tudo. "Último contato" é a última mensagem da
 * conversa ligada ao card ou, sem conversa, a última alteração do card.
 *
 * Gravado em `accounts.configuracoes.termometro`:
 *   { "etapas": {"novo":"frio", ...}, "quente_dias": 2, "morno_dias": 7,
 *     "frio_dias": 30, "resposta_esquenta": true }
 * O formato antigo (etapas_quentes / etapas_congeladas) é ignorado: o que faltar
 * vem do padrão.
 *
 * A tela da Prospecção aplica a mesma regra em JS (getTemperatureBadge) com a
 * configuração que esta classe entrega. `detalhar()` é o espelho em PHP, PURO,
 * usado pelos testes.
 */
final class Termometro
{
    /** Do mais quente para o mais frio: a posição é a "distância" do quente. */
    public const NIVEIS = ['quente', 'morno', 'frio', 'congelado'];

    public const PADRAO = [
        'etapas' => [
            'novo'         => 'frio',
            'qualificacao' => 'morno',
            'followup'     => 'frio',
            'qualificado'  => 'quente',
            'especialista' => 'quente',
            'negociacao'   => 'quente',
            'bloqueado'    => 'congelado',
            'fora_escopo'  => 'congelado',
            'venda'        => 'quente',
            'perdido'      => 'congelado',
        ],
        'quente_dias'       => 2,
        'morno_dias'        => 7,
        'frio_dias'         => 30,
        'resposta_esquenta' => true,
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
            return self::normalizar($t);
        } catch (\InvalidArgumentException $e) {
            return self::PADRAO; // gravado inválido à mão: volta ao padrão, nunca quebra a tela
        }
    }

    /**
     * Confere e limpa a regra vinda da tela. O que faltar vem do padrão; etapa
     * desconhecida é descartada. Lança InvalidArgumentException com mensagem
     * para o usuário. PURA.
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

        $etapas = self::PADRAO['etapas'];
        $validas = self::etapasValidas();
        foreach ($validas as $e) {
            if (!isset($etapas[$e])) $etapas[$e] = 'quente';
        }
        if (isset($in['etapas']) && !is_array($in['etapas'])) {
            throw new \InvalidArgumentException('Etapas inválidas.');
        }
        foreach ((array)($in['etapas'] ?? []) as $e => $nivel) {
            if (!in_array((string)$e, $validas, true)) continue;
            $nivel = strtolower(trim((string)$nivel));
            if (!in_array($nivel, self::NIVEIS, true)) {
                throw new \InvalidArgumentException('Temperatura de etapa inválida: use quente, morno, frio ou congelado.');
            }
            $etapas[(string)$e] = $nivel;
        }

        $resp = $in['resposta_esquenta'] ?? self::PADRAO['resposta_esquenta'];
        $resp = is_bool($resp) ? $resp : in_array(strtolower((string)$resp), ['1', 'true', 'on', 'sim'], true);

        return ['etapas' => $etapas] + $dias + ['resposta_esquenta' => $resp];
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

    /** O mais frio dos dois níveis. */
    private static function maisFrio(string $a, string $b): string
    {
        return array_search($a, self::NIVEIS, true) >= array_search($b, self::NIVEIS, true) ? $a : $b;
    }

    /**
     * A temperatura de um card e o porquê. PURA (o espelho da tela).
     *
     * @param ?string $manual          cards.temperatura (escolhida à mão), ou null
     * @param ?string $etapa           chave da etapa do card ('novo', 'negociacao'...), ou null
     * @param ?int    $diasSemContato  dias desde o último contato, null = sem registro
     * @param bool    $leadFalouPorUltimo  a última mensagem da conversa é do lead
     * @return array{nivel:string, motivo:string}
     */
    public static function detalhar(array $regra, ?string $manual, ?string $etapa, ?int $diasSemContato, bool $leadFalouPorUltimo = false): array
    {
        if ($manual !== null && in_array($manual, self::NIVEIS, true)) {
            return ['nivel' => $manual, 'motivo' => 'escolhido à mão'];
        }
        $base = ($etapa !== null && isset($regra['etapas'][$etapa])) ? $regra['etapas'][$etapa] : 'quente';
        $motivo = $etapa !== null && isset($regra['etapas'][$etapa]) ? 'etapa' : 'tempo';
        if ($base === 'congelado') {
            return ['nivel' => 'congelado', 'motivo' => 'etapa'];
        }
        $nivel = $base;
        if ($diasSemContato !== null) {
            $peloTempo = $diasSemContato <= $regra['quente_dias'] ? 'quente'
                : ($diasSemContato <= $regra['morno_dias'] ? 'morno'
                : ($diasSemContato <= $regra['frio_dias'] ? 'frio' : 'congelado'));
            $novo = self::maisFrio($base, $peloTempo);
            if ($novo !== $base) { $nivel = $novo; $motivo = 'esfriou'; }
        }
        if (!empty($regra['resposta_esquenta']) && $leadFalouPorUltimo
            && $diasSemContato !== null && $diasSemContato <= $regra['quente_dias']
            && in_array($nivel, ['frio', 'congelado'], true)) {
            $nivel = 'morno';
            $motivo = 'respondeu';
        }
        return ['nivel' => $nivel, 'motivo' => $motivo];
    }

    /** Só o nível (atalho de detalhar). PURA. */
    public static function classificar(array $regra, ?string $manual, ?string $etapa, ?int $diasSemContato, bool $leadFalouPorUltimo = false): string
    {
        return self::detalhar($regra, $manual, $etapa, $diasSemContato, $leadFalouPorUltimo)['nivel'];
    }
}
