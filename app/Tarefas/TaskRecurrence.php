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
                $dt->modify('+1 month');
                // fallback pro último dia se o mês não tiver aquele dia
                $maxDia = (int)$dt->format('t');
                $dt->setDate((int)$dt->format('Y'), (int)$dt->format('m'), min($diaMes, $maxDia));
                break;

            case 'anual':
                $dt->modify('+1 year');
                break;

            case 'custom':
                $unit = $this->data['unidade'] ?? 'day';
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
