<?php
namespace App\Tarefas;

use App\Core\Database;

class TaskRecurrence
{
    public static function findById(int $id): array|false
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM task_recurrences WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    public static function create(array $data): int
    {
        $pdo = Database::getConnection();
        // FIX (auditoria 2026-06-01 / ALTA #19): persiste `unidade` (day|week|month|year).
        // Sem ela, a recorrencia 'custom' caia sempre no fallback 'day' em
        // calcularProximaData(). Default 'day' espelha esse fallback.
        $stmt = $pdo->prepare('
            INSERT INTO task_recurrences (tipo, intervalo, unidade, dias_semana, dia_mes, data_inicio, data_fim)
            VALUES (:tipo, :intervalo, :unidade, :dias_semana, :dia_mes, :data_inicio, :data_fim)
        ');
        $stmt->execute([
            'tipo'        => $data['tipo'],
            'intervalo'   => $data['intervalo'] ?? 1,
            'unidade'     => $data['unidade'] ?? 'day',
            'dias_semana' => isset($data['dias_semana']) ? json_encode($data['dias_semana']) : null,
            'dia_mes'     => $data['dia_mes'] ?? null,
            'data_inicio' => $data['data_inicio'],
            'data_fim'    => $data['data_fim'] ?? null,
        ]);
        return (int)$pdo->lastInsertId();
    }

    public static function deactivate(int $id): void
    {
        $pdo = Database::getConnection();
        $pdo->prepare('UPDATE task_recurrences SET ativa = 0 WHERE id = ?')->execute([$id]);
    }

    /**
     * Calcula a próxima data a partir de $dataAtual (string Y-m-d ou Y-m-d H:i:s)
     */
    public function calcularProximaData(string $dataAtual): string
    {
        $dt   = new \DateTime($dataAtual);
        $tipo = $this->data['tipo'];
        $int  = (int)($this->data['intervalo'] ?? 1);

        switch ($tipo) {
            case 'diaria':
                $dt->modify("+{$int} day");
                break;

            case 'semanal':
            case 'quinzenal':
                $dias = json_decode($this->data['dias_semana'] ?? '[]', true);
                $step = $tipo === 'quinzenal' ? 14 : 7;
                if ($dias) {
                    // próximo dia da semana configurado
                    $cur  = (int)$dt->format('N'); // 1=Mon … 7=Sun
                    $found = false;
                    for ($i = 1; $i <= 7; $i++) {
                        $candidate = ($cur + $i - 1) % 7 + 1;
                        if (in_array($candidate, $dias)) {
                            $dt->modify("+{$i} day");
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) $dt->modify("+{$step} day");
                } else {
                    $dt->modify("+{$step} day");
                }
                break;

            case 'mensal':
                $diaMes = (int)($this->data['dia_mes'] ?? $dt->format('d'));

                /*
                 * O DIA 1 ANTES DE SOMAR O MÊS NÃO É FRESCURA.
                 *
                 * `(new DateTime('2026-01-31'))->modify('+1 month')` dá
                 * 2026-03-03, porque o PHP transborda: fevereiro não tem 31.
                 * O código antigo somava o mês primeiro e só depois procurava o
                 * último dia, mas já do mês TRANSBORDADO. Resultado: uma tarefa
                 * mensal do dia 31 caía em 31 de MARÇO e PULAVA FEVEREIRO
                 * inteiro. Num escritório de advocacia, prazo mensal que pula um
                 * mês é prazo perdido.
                 *
                 * Indo para o dia 1, a soma do mês nunca transborda, e só então
                 * o dia certo é escolhido, limitado ao tamanho do mês de destino:
                 * 31 de janeiro vira 28 (ou 29) de fevereiro.
                 */
                $dt->setDate((int)$dt->format('Y'), (int)$dt->format('m'), 1);
                $dt->modify('+1 month');
                $maxDia = (int)$dt->format('t');
                $dt->setDate((int)$dt->format('Y'), (int)$dt->format('m'), min($diaMes, $maxDia));
                break;

            case 'anual':
                $dt->modify('+1 year');
                break;

            case 'custom':
                // Allowlist: `modify('+1 unidade_qualquer')` NAO lança, apenas
                // emite warning e devolve a data intocada. Sem isto, uma unidade
                // inválida vira uma recorrência que nunca anda, e quem chama fica
                // girando sem entender por quê.
                $unit = (string)($this->data['unidade'] ?? 'day');
                if (!in_array($unit, ['day', 'week', 'month', 'year'], true)) {
                    $unit = 'day';
                }
                $dt->modify("+{$int} {$unit}");
                break;
        }

        // respeita data_fim
        if (!empty($this->data['data_fim'])) {
            $fim = new \DateTime($this->data['data_fim']);
            if ($dt > $fim) return '';
        }

        return $dt->format('Y-m-d H:i:s');
    }

    /**
     * A próxima data que ainda está NO FUTURO.
     *
     * ---------------------------------------------------------------------
     * POR QUE ISTO EXISTE, SEPARADO DE calcularProximaData()
     * ---------------------------------------------------------------------
     * `calcularProximaData()` avança UM período, e para quem acabou de concluir
     * uma tarefa isso é o certo: concluiu a de hoje, a próxima é a da semana que
     * vem.
     *
     * Mas para uma tarefa que está vencida há meses, avançar um período deixa
     * ela AINDA vencida. O renovador automático então a pegava de novo na
     * próxima passada, e de novo, e de novo. Medido em produção em 12/09/2026:
     * 214 tarefas recorrentes vencidas sendo reprocessadas a CADA abertura da
     * tela de Tarefas, sem nunca convergir, e gravando 214 linhas de histórico
     * imutável por vez. Era a origem das 14.508 linhas em `task_history` para
     * 344 tarefas.
     *
     * Aqui a data avança até passar de agora. Uma passada resolve, e a tarefa
     * sai da lista de vencidas de verdade.
     *
     * ---------------------------------------------------------------------
     * AS DUAS PROTEÇÕES CONTRA LAÇO INFINITO, E POR QUE SÃO DUAS
     * ---------------------------------------------------------------------
     * A que realmente protege é a comparação `$proxima === $data`: se a data
     * não andou (intervalo zero, unidade inválida), para na hora. Essa é a
     * única falha capaz de girar para sempre.
     *
     * O teto de voltas é a segunda linha, para um caso que eu não tenha
     * previsto. Ele precisa ser ALTO: uma tarefa diária parada desde 2020 são
     * mais de 2.000 voltas até alcançar hoje. A primeira versão tinha teto 400 e
     * o teste pegou: a data parava em 2021 e a tarefa continuava vencida, que é
     * justamente o defeito que este método existe para acabar.
     *
     * 20.000 cobre mais de 50 anos de recorrência diária e roda em poucos
     * milissegundos, porque é só aritmética de calendário.
     *
     * @param  string $dataAtual prazo atual, vencido ou não
     * @return string 'Y-m-d H:i:s' no futuro, ou '' se a recorrência acabou
     */
    public function proximaDataFutura(string $dataAtual): string
    {
        $agora = new \DateTime();
        $data  = $dataAtual;

        for ($volta = 0; $volta < 20000; $volta++) {
            $proxima = $this->calcularProximaData($data);

            // '' significa que passou da data_fim: a recorrência terminou.
            if ($proxima === '') {
                return '';
            }

            // A data não andou: intervalo inválido. Para aqui em vez de girar.
            if ($proxima === $data) {
                return $proxima;
            }

            if (new \DateTime($proxima) > $agora) {
                return $proxima;
            }
            $data = $proxima;
        }

        // Teto atingido. Devolve o que deu, e registra: 20 mil voltas sem
        // alcançar hoje é sinal de recorrência mal configurada, não de uso real.
        error_log('[TaskRecurrence] proximaDataFutura: teto de voltas na recorrência #'
                  . ($this->data['id'] ?? '?') . ' a partir de ' . $dataAtual);
        return $data;
    }

    public function gerarProximaInstancia(array $taskConcluida): int|false
    {
        $proximaData = $this->calcularProximaData($taskConcluida['prazo'] ?? date('Y-m-d'));
        if (!$proximaData) {
            self::deactivate($this->data['id']);
            return false;
        }

        // Idempotência: não cria se já existe instância ativa para essa data
        $pdo   = Database::getConnection();
        $check = $pdo->prepare(
            "SELECT id FROM tasks
             WHERE recorrencia_id = ? AND DATE(prazo) = DATE(?) AND status != 'arquivada'
             LIMIT 1"
        );
        $check->execute([$this->data['id'], $proximaData]);
        if ($check->fetchColumn()) return false;

        $coluna = TaskColumn::initialColumn($taskConcluida['board_id']);
        if (!$coluna) return false;

        return Task::create([
            'board_id'        => $taskConcluida['board_id'],
            'column_id'       => $coluna['id'],
            'titulo'          => $taskConcluida['titulo'],
            'descricao'       => $taskConcluida['descricao'],
            'prioridade'      => $taskConcluida['prioridade'],
            'prazo'           => $proximaData,
            'prazo_tipo'      => $taskConcluida['prazo_tipo'],
            'responsavel_id'  => $taskConcluida['responsavel_id'],
            'criado_por_id'   => $taskConcluida['criado_por_id'],
            'recorrencia_id'  => $this->data['id'],
            'origem_task_id'  => $taskConcluida['id'],
        ]);
    }

    public static function loadById(int $id): self|false
    {
        $row = self::findById($id);
        if (!$row) return false;
        $obj = new self();
        $obj->data = $row;
        return $obj;
    }

    public static function allActive(): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT * FROM task_recurrences WHERE ativa = 1");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private array $data = [];
}
