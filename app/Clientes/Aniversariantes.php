<?php

namespace App\Clientes;

use App\Core\Database;

/**
 * Aniversariantes — quem faz aniversário no mês, para o escritório dar parabéns.
 *
 * ---------------------------------------------------------------------------
 * O PEDIDO, NAS PALAVRAS DE QUEM PEDIU
 * ---------------------------------------------------------------------------
 * "Data de nascimento no cadastro do cliente com possibilidade de relatório
 * mensal para dar parabéns. Uma forma de contactar o cliente novamente."
 *
 * A última frase é o requisito de verdade. Não é um relatório de RH: é uma
 * desculpa boa para reabrir conversa com quem já confiou no escritório uma vez.
 * Por isso a lista entrega o WhatsApp pronto e diz quantos anos a pessoa faz,
 * que é o que alguém precisa para escrever a mensagem sem abrir outra tela.
 *
 * ---------------------------------------------------------------------------
 * POR QUE MÊS, E NÃO "PRÓXIMOS 30 DIAS"
 * ---------------------------------------------------------------------------
 * Trinta dias corridos atravessam a virada do mês e mudam a lista todo dia, o
 * que impede a pessoa de conferir "já mandei para todo mundo?". Mês fechado é
 * uma lista estável, que se abre uma vez e se percorre até o fim.
 *
 * ---------------------------------------------------------------------------
 * 29 DE FEVEREIRO
 * ---------------------------------------------------------------------------
 * Quem nasceu em 29/02 aparece em fevereiro todo ano, inclusive nos anos sem 29.
 * A consulta compara MÊS e DIA guardados, não constrói a data do aniversário no
 * ano corrente, então não há data inválida para tratar. Em ano comum a pessoa
 * aparece no fim da lista de fevereiro, e é a decisão do escritório felicitar
 * no 28 ou no 1º de março.
 *
 * ---------------------------------------------------------------------------
 * MULTI-TENANT
 * ---------------------------------------------------------------------------
 * Filtra pelas contas acessíveis, como todo o resto. Uma sessão matriz vê os
 * aniversariantes das filiais que ela alcança, e a lista diz de qual conta cada
 * um é, para a felicitação sair de quem tem relação com a pessoa.
 */
final class Aniversariantes
{
    /**
     * Clientes que fazem aniversário no mês.
     *
     * @param int[] $accountIds contas que a sessão alcança
     * @param int   $mes        1 a 12; fora disso, o mês corrente
     * @return array<int,array<string,mixed>>
     */
    public static function doMes(array $accountIds, int $mes = 0): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === []) {
            return [];
        }
        if ($mes < 1 || $mes > 12) {
            $mes = (int) date('n');
        }

        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $pdo = Database::getConnection();

        $st = $pdo->prepare(
            "SELECT c.id, c.nome, c.data_nascimento, c.telefone, c.whatsapp, c.email,
                    c.status, c.account_id,
                    a.nome AS conta_nome,
                    s.nome AS setor_nome,
                    u.nome AS responsavel_nome,
                    DAY(c.data_nascimento)   AS dia,
                    MONTH(c.data_nascimento) AS mes
               FROM clientes c
          LEFT JOIN accounts        a ON a.id = c.account_id
          LEFT JOIN clientes_setores s ON s.id = c.setor_id
          LEFT JOIN users           u ON u.id = c.responsavel_id
              WHERE c.deleted_at IS NULL
                AND c.anonymized_at IS NULL
                AND c.data_nascimento IS NOT NULL
                AND MONTH(c.data_nascimento) = ?
                AND c.account_id IN ($in)
           ORDER BY DAY(c.data_nascimento), c.nome"
        );
        $st->execute(array_merge([$mes], $accountIds));

        $hoje    = new \DateTimeImmutable('today');
        $anoAtual = (int) $hoje->format('Y');
        $mesHoje  = (int) $hoje->format('n');
        $diaHoje  = (int) $hoje->format('j');

        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($linhas as &$l) {
            $nasc = (string) $l['data_nascimento'];
            $ano  = (int) substr($nasc, 0, 4);

            // Quantos anos a pessoa COMPLETA neste ano. É o número que vai na
            // mensagem, e não a idade de hoje: felicitar alguém pelos 39 no dia
            // em que ela faz 40 é o tipo de erro que ninguém perdoa.
            $l['idade_que_faz'] = $anoAtual - $ano;

            $l['dia']  = (int) $l['dia'];
            $l['mes']  = (int) $l['mes'];
            $l['hoje'] = ($l['mes'] === $mesHoje && $l['dia'] === $diaHoje);
            $l['ja_passou'] = ($l['mes'] === $mesHoje && $l['dia'] < $diaHoje);

            // Telefone pronto para o link do WhatsApp, sem máscara.
            $tel = preg_replace('/[^0-9]/', '', (string) ($l['whatsapp'] ?: $l['telefone']));
            if ($tel !== '' && strlen($tel) >= 10 && strlen($tel) <= 11) {
                $tel = '55' . $tel;   // número local: acrescenta o DDI para o wa.me
            }
            $l['whatsapp_digits'] = (strlen($tel) >= 12 && strlen($tel) <= 13) ? $tel : null;

            unset($l['telefone'], $l['email']); // a lista não precisa, e é PII à toa
        }
        unset($l);

        return $linhas;
    }

    /**
     * Quantos aniversariantes há em cada mês do ano.
     *
     * Serve para a tela oferecer os meses que TÊM alguém em vez de doze abas
     * mudas, e para responder de cara "vale a pena preencher esse campo?".
     *
     * @param int[] $accountIds
     * @return array<int,int> mês (1-12) => quantidade
     */
    public static function porMes(array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        $contagem   = array_fill(1, 12, 0);
        if ($accountIds === []) {
            return $contagem;
        }

        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $st = Database::getConnection()->prepare(
            "SELECT MONTH(data_nascimento) AS m, COUNT(*) AS n
               FROM clientes
              WHERE deleted_at IS NULL
                AND anonymized_at IS NULL
                AND data_nascimento IS NOT NULL
                AND account_id IN ($in)
           GROUP BY MONTH(data_nascimento)"
        );
        $st->execute($accountIds);

        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $m = (int) $r['m'];
            if ($m >= 1 && $m <= 12) {
                $contagem[$m] = (int) $r['n'];
            }
        }
        return $contagem;
    }

    /**
     * Quantos clientes ainda estão sem data de nascimento.
     *
     * A tela mostra isso junto com a lista: um relatório de aniversariantes que
     * cobre 3 de 46 clientes precisa dizer isso em voz alta, senão o escritório
     * acha que só tem três aniversários no ano.
     *
     * @param int[] $accountIds
     * @return array{com:int,sem:int}
     */
    public static function cobertura(array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === []) {
            return ['com' => 0, 'sem' => 0];
        }
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $st = Database::getConnection()->prepare(
            "SELECT SUM(data_nascimento IS NOT NULL) AS com,
                    SUM(data_nascimento IS NULL)     AS sem
               FROM clientes
              WHERE deleted_at IS NULL AND anonymized_at IS NULL
                AND account_id IN ($in)"
        );
        $st->execute($accountIds);
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        return ['com' => (int) ($r['com'] ?? 0), 'sem' => (int) ($r['sem'] ?? 0)];
    }

    /** @param int[] $v @return int[] */
    private static function inteiros(array $v): array
    {
        $out = [];
        foreach ($v as $x) {
            $i = (int) $x;
            if ($i > 0) {
                $out[$i] = $i;
            }
        }
        return array_values($out);
    }
}
