<?php

namespace App\Notificacoes;

use App\Core\Database;

/**
 * Movimento — transforma uma linha de histórico em um aviso legível.
 *
 * ---------------------------------------------------------------------------
 * POR QUE AQUI, E NÃO EM CADA TELA
 * ---------------------------------------------------------------------------
 * O pedido foi "todas as movimentações têm que chegar". Espalhar chamadas de
 * notificação por dezenas de endpoints garantiria que alguém esqueceria de uma,
 * e o buraco só apareceria meses depois, quando alguém perguntasse por que um
 * evento nunca avisa.
 *
 * O sistema já tem QUATRO funções por onde todo movimento obrigatoriamente
 * passa, porque é onde o histórico é gravado:
 *
 *   App\Prospeccao\Card::logEvento()      -> card_history
 *   App\Clientes\Cliente::_logHistory()   -> clientes_history
 *   App\Processos\ProcessoAudit::log()    -> processo_history
 *   App\Tarefas\Task::history()           -> task_history
 *
 * Pendurar o aviso nessas quatro cobre tudo, inclusive o que for escrito
 * amanhã: quem gravar histórico avisa, sem precisar lembrar.
 *
 * ---------------------------------------------------------------------------
 * O QUE NÃO VIRA AVISO
 * ---------------------------------------------------------------------------
 * Reordenar card dentro da coluna é arrastar, não é fato. Um Kanban sendo
 * organizado gera dezenas dessas por minuto, e nenhuma delas interessa a
 * ninguém depois. Ver `IGNORADAS`.
 *
 * ---------------------------------------------------------------------------
 * O CUSTO, QUE FOI PENSADO
 * ---------------------------------------------------------------------------
 * Cada aviso precisa do TÍTULO da entidade, e isso é uma consulta. Numa edição
 * que muda cinco campos, `Card::logEvento` é chamado cinco vezes.
 *
 * Por isso a ordem aqui é: monta a chave de dedupe, PERGUNTA se já avisou, e só
 * então busca o título. As cinco chamadas viram uma consulta de índice barata e
 * um único aviso: "Prospecção Fulano foi alterada". Que é, aliás, o que a
 * pessoa quer ler, e não cinco linhas de campo.
 */
final class Movimento
{
    /**
     * Ações que NÃO viram aviso.
     *
     * `reorder` é arrastar dentro da mesma coluna. `viewed`/`opened` seriam
     * ruído puro caso passem a ser registrados.
     */
    public const IGNORADAS = ['reorder', 'reordered', 'viewed', 'opened', 'listed'];

    /**
     * Verbos, em português, para o texto do aviso.
     *
     * `{o}` é o marcador de GÊNERO, e não é frescura: "Prospecção foi alterado"
     * é o tipo de erro que faz a advogada achar que o sistema é mal feito antes
     * mesmo de ler o que o aviso diz. Prospecção e Tarefa são femininas,
     * Cliente e Processo masculinos, e o mesmo verbo serve para os quatro.
     */
    public const VERBOS = [
        'created'              => 'foi cadastrad{o}',
        'updated'              => 'foi alterad{o}',
        'deleted'              => 'foi excluíd{o}',
        'archived'             => 'foi arquivad{o}',
        'restored'             => 'foi restaurad{o}',
        'moved'                => 'mudou de etapa',
        'stage_changed'        => 'mudou de etapa',
        'status_changed'       => 'mudou de situação',
        'convertido_cliente'   => 'virou cliente',
        'vinculado_cliente'    => 'foi vinculad{o} a um cliente',
        'anexo_adicionado'     => 'recebeu um documento',
        'anexo_removido'       => 'teve um documento removido',
        'tag_aplicada'         => 'recebeu uma etiqueta',
        'tag_removida'         => 'teve uma etiqueta removida',
        'campo_preenchido'     => 'teve um campo preenchido',
        'interacao_registrada' => 'recebeu um registro de contato',
        'nota_interna'         => 'recebeu uma anotação',
        'completed'            => 'foi concluíd{o}',
        'concluida'            => 'foi concluíd{o}',
    ];

    /** Gênero de cada entidade, para flexionar os verbos acima. */
    private const GENERO = [
        'cliente'  => 'o',
        'card'     => 'a',   // "Prospecção"
        'processo' => 'o',
        'tarefa'   => 'a',
    ];

    /**
     * Registra o movimento. NUNCA lança: é chamado de dentro do gravador de
     * histórico, que por sua vez não pode derrubar a operação que descreve.
     *
     * @param string      $entidade cliente|card|processo|tarefa
     * @param string      $acao     crua, do jeito que foi gravada no histórico
     * @param string|null $detalhe  texto opcional que enriquece a mensagem
     */
    public static function registrar(string $entidade, int $entidadeId, string $acao, ?int $userId = null, ?string $detalhe = null): void
    {
        try {
            $acao = trim($acao);
            if ($entidadeId <= 0 || $acao === '') {
                return;
            }
            if (in_array(strtolower($acao), self::IGNORADAS, true)) {
                return;
            }

            /*
             * Chave de dedupe SEM o detalhe: é ela que junta "mudou telefone",
             * "mudou e-mail" e "mudou endereço" da mesma edição num aviso só.
             * Incluir o detalhe aqui devolveria as cinco linhas.
             */
            $chave = "mov:$entidade:$entidadeId:" . strtolower($acao);

            $accountId = self::contaDa($entidade, $entidadeId);
            if ($accountId <= 0) {
                return;
            }

            // Pergunta ANTES de buscar o título: ver o cabeçalho.
            if (Aviso::deduplicado($accountId, null, $chave)) {
                return;
            }

            $titulo = self::tituloDa($entidade, $entidadeId);
            if ($titulo === '') {
                return;
            }

            $rotulo = Aviso::ROTULO_ENTIDADE[$entidade] ?? $entidade;
            $verbo  = str_replace(
                '{o}',
                self::GENERO[$entidade] ?? 'o',
                self::VERBOS[strtolower($acao)] ?? self::verboGenerico($acao)
            );
            $quem   = self::nomeDe($userId);

            $mensagem = $quem !== null ? ('por ' . $quem) : 'pelo sistema';
            if ($detalhe !== null && trim($detalhe) !== '') {
                $mensagem = mb_substr(trim($detalhe), 0, 180) . ' (' . $mensagem . ')';
            }

            Aviso::paraConta($accountId, [
                'tipo'           => 'movimento.' . $entidade,
                'titulo'         => $rotulo . ' ' . $titulo . ' ' . $verbo,
                'mensagem'       => $mensagem,
                'entidade'       => $entidade,
                'entidade_id'    => $entidadeId,
                'origem_user_id' => $userId,
                'url'            => self::urlDe($entidade, $entidadeId),
                'chave_dedupe'   => $chave,
            ]);
        } catch (\Throwable $e) {
            error_log('[Movimento] ' . $e->getMessage());
        }
    }

    /* ===================================================================== */
    /* de onde vêm conta, título e link                                       */
    /* ===================================================================== */

    /**
     * A conta dona da entidade.
     *
     * `tasks` NÃO tem account_id: quem tem é o quadro. Mesmo cuidado de
     * VinculosCliente e do módulo de relatórios.
     */
    private static function contaDa(string $entidade, int $id): int
    {
        $sql = match ($entidade) {
            'cliente'  => 'SELECT account_id FROM clientes  WHERE id = ? LIMIT 1',
            'card'     => 'SELECT account_id FROM cards     WHERE id = ? LIMIT 1',
            'processo' => 'SELECT account_id FROM processos WHERE id = ? LIMIT 1',
            'tarefa'   => 'SELECT b.account_id FROM tasks t JOIN task_boards b ON b.id = t.board_id WHERE t.id = ? LIMIT 1',
            default    => null,
        };
        if ($sql === null) {
            return 0;
        }
        $st = Database::getConnection()->prepare($sql);
        $st->execute([$id]);
        return (int) ($st->fetchColumn() ?: 0);
    }

    private static function tituloDa(string $entidade, int $id): string
    {
        $sql = match ($entidade) {
            'cliente'  => 'SELECT nome FROM clientes WHERE id = ? LIMIT 1',
            // O card pode ter nome do contato OU só título; um dos dois existe.
            'card'     => "SELECT COALESCE(NULLIF(cliente_nome,''), NULLIF(titulo,''), CONCAT('#', id)) FROM cards WHERE id = ? LIMIT 1",
            'processo' => "SELECT COALESCE(NULLIF(numero_cnj,''), NULLIF(numero,''), CONCAT('#', id)) FROM processos WHERE id = ? LIMIT 1",
            'tarefa'   => 'SELECT titulo FROM tasks WHERE id = ? LIMIT 1',
            default    => null,
        };
        if ($sql === null) {
            return '';
        }
        $st = Database::getConnection()->prepare($sql);
        $st->execute([$id]);
        return mb_substr(trim((string) ($st->fetchColumn() ?: '')), 0, 120);
    }

    /** Para onde o clique leva. */
    public static function urlDe(string $entidade, int $id): ?string
    {
        return match ($entidade) {
            'cliente'  => '/clientes.php?abrir=' . $id,
            'card'     => '/prospeccao.php?card=' . $id,
            'processo' => '/processos.php?abrir=' . $id,
            'tarefa'   => '/tarefas.php?tarefa=' . $id,
            default    => null,
        };
    }

    private static function nomeDe(?int $userId): ?string
    {
        if (!$userId) {
            return null;
        }
        try {
            $st = Database::getConnection()->prepare('SELECT nome FROM users WHERE id = ? LIMIT 1');
            $st->execute([$userId]);
            $n = trim((string) ($st->fetchColumn() ?: ''));
            return $n !== '' ? $n : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Ação que não está no mapa vira texto legível em vez de sumir.
     *
     * O histórico ganha verbos novos com o tempo, e um verbo novo aparecendo
     * como "teve uma alteração (anexo_movido)" é muito melhor que um aviso
     * mudo, ou que nenhum aviso.
     */
    private static function verboGenerico(string $acao): string
    {
        return 'teve uma alteração (' . trim(str_replace('_', ' ', $acao)) . ')';
    }
}
