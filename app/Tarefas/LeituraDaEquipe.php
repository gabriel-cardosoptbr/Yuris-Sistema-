<?php
namespace App\Tarefas;

use App\WhatsAppAgente\MetricasAtendimento;

/**
 * LeituraDaEquipe: transforma os números da tela Desempenho em frases do que pede
 * atenção agora. Só relata o que está nos dados, sem nota nem previsão, e a ordem é
 * a de gravidade. Pura: recebe o que a tela já calculou.
 */
final class LeituraDaEquipe
{
    /** Abaixo disto o percentual de OTIF oscila demais para virar conclusão. */
    public const AMOSTRA_PEQUENA = 5;

    private const LIMITE = 8;

    /**
     * @param array  $colaboradores linhas de Otif::relatorio
     * @param array  $carga         CargaDaEquipe::agora
     * @param ?array $comercial     linhas de DesempenhoComercial::linhasDaTabela (null fora da edição CRM)
     * @return array<int, array{nivel:string, texto:string, user_id:?int}>
     */
    public static function pontos(array $colaboradores, array $carga, ?array $comercial = null): array
    {
        $plural = static fn(int $n, string $um, string $varios) => $n === 1 ? "1 $um" : "$n $varios";
        $ruins = []; $atencao = []; $info = [];

        foreach ($colaboradores as $c) {
            $uid = (int) $c['user_id'];
            if ($uid === 0) continue;
            $vencidas = (int) ($carga['pessoas'][$uid]['vencidas'] ?? 0);
            if ($vencidas > 0) {
                $ruins[] = ['nivel' => 'ruim', 'user_id' => $uid, 'texto' => $c['nome'] . ' tem ' . $plural($vencidas, 'tarefa vencida', 'tarefas vencidas') . ' em aberto.'];
            }
            if ((int) $c['nao_entregues'] > 0 && $vencidas === 0) {
                $ruins[] = ['nivel' => 'ruim', 'user_id' => $uid, 'texto' => $c['nome'] . ' deixou ' . $plural((int) $c['nao_entregues'], 'compromisso', 'compromissos') . ' sem entrega no período.'];
            }
            if ((int) $c['atrasadas'] > 0 && $c['atraso_medio_h'] !== null) {
                $atencao[] = ['nivel' => 'atencao', 'user_id' => $uid, 'texto' => $c['nome'] . ' entregou com atraso médio de ' . self::horas((float) $c['atraso_medio_h']) . '.'];
            }
        }

        $semDono = (int) ($carga['pessoas'][0]['abertas'] ?? 0);
        if ($semDono > 0) {
            $atencao[] = ['nivel' => 'atencao', 'user_id' => 0, 'texto' => $plural($semDono, 'tarefa aberta está', 'tarefas abertas estão') . ' sem responsável.'];
        }

        foreach ($comercial ?? [] as $l) {
            $uid = (int) $l['user_id'];
            if ($uid === 0) continue;
            if ($l['sem_resposta'] > 0) {
                $ruins[] = ['nivel' => 'ruim', 'user_id' => $uid, 'texto' => $l['nome'] . ' tem ' . $plural((int) $l['sem_resposta'], 'conversa sem resposta', 'conversas sem resposta') . ' no período.'];
            }
            if ($l['media_resposta'] !== null && $l['media_resposta'] > MetricasAtendimento::META_ATENCAO) {
                $atencao[] = ['nivel' => 'atencao', 'user_id' => $uid, 'texto' => $l['nome'] . ' responde em ' . MetricasAtendimento::duracao((float) $l['media_resposta']) . ' em média.'];
            }
            if ($l['parados'] > 0) {
                $atencao[] = ['nivel' => 'atencao', 'user_id' => $uid, 'texto' => $l['nome'] . ' tem ' . $plural((int) $l['parados'], 'lead parado', 'leads parados') . ' há 7 dias ou mais.'];
            }
        }

        $semTarefa = [];
        foreach ($colaboradores as $c) {
            $uid = (int) $c['user_id'];
            if ($uid !== 0 && (int) $c['compromissos'] === 0 && (int) ($carga['pessoas'][$uid]['abertas'] ?? 0) === 0) $semTarefa[] = $c['nome'];
        }
        if ($semTarefa) {
            $info[] = ['nivel' => 'sem', 'user_id' => null, 'texto' => 'Sem tarefa com prazo no período, então sem OTIF: ' . implode(', ', $semTarefa) . '.'];
        }

        return array_slice(array_merge($ruins, $atencao, $info), 0, self::LIMITE);
    }

    /** Horas em texto: "12 min", "3,5 h" ou, de um dia para cima, "2,1 dias". */
    public static function horas(float $h): string
    {
        if ($h < 1) return max(1, (int) round($h * 60)) . ' min';
        if ($h < 24) return number_format($h, 1, ',', '.') . ' h';
        $d = $h / 24;
        return number_format($d, 1, ',', '.') . ($d < 2 ? ' dia' : ' dias');
    }
}
