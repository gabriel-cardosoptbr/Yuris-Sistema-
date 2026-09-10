<?php

namespace App\Relatorios;

use App\Core\Database;

/**
 * Listagem — o relatorio de conjunto: "todos os clientes ativos", "todos os
 * processos em aberto", "as prospeccoes paradas".
 *
 * ---------------------------------------------------------------------------
 * A DIFERENCA PARA O DOSSIE
 * ---------------------------------------------------------------------------
 * O dossie (App\Relatorios\Dossie) e o retrato de UMA pessoa ou UM processo,
 * com o historico inteiro. Este arquivo e o oposto: MUITOS registros, poucas
 * colunas, para a advogada ver a carteira e levar para planilha.
 *
 * Os dois devolvem estruturas diferentes de proposito. Tentar unificar faria a
 * listagem carregar campos que ela nao usa e o dossie perder profundidade.
 *
 * ---------------------------------------------------------------------------
 * FILTRO E SEGURANCA
 * ---------------------------------------------------------------------------
 * NENHUM valor de filtro entra no SQL por concatenacao. O que o usuario manda
 * vira sempre placeholder. As unicas partes montadas em texto sao:
 *
 *   - o IN (?) dos account_ids, que sao inteiros vindos da sessao
 *   - o nome da coluna de ordenacao, escolhido de uma ALLOWLIST por fonte
 *
 * O escopo de conta e resolvido pelo CHAMADOR, com o modulo certo de cada
 * fonte, e chega aqui pronto. Se chegar vazio, a resposta e lista vazia, nunca
 * "tudo".
 */
final class Listagem
{
    /**
     * As tres fontes, com o modulo de permissao de cada uma.
     *
     * O modulo importa: alguem que ve Clientes mas nao ve Processos pode abrir
     * a tela de relatorios e ainda assim NAO pode listar processos. O gate e por
     * fonte, nao pela tela.
     */
    public const FONTES = [
        'clientes'    => ['titulo' => 'Clientes',     'modulo' => 'clientes'],
        'prospeccoes' => ['titulo' => 'Prospecções',  'modulo' => 'prospeccao'],
        'processos'   => ['titulo' => 'Processos',    'modulo' => 'processos'],
    ];

    /** Teto duro. Um relatorio de 50 mil linhas nao ajuda ninguem e derruba a tela. */
    public const LIMITE_MAXIMO = 5000;

    /**
     * @param  string $fonte      clientes | prospeccoes | processos
     * @param  array  $filtros    ['status','setor_id','responsavel_id','de','ate','busca','ordem']
     * @param  int[]  $accountIds contas acessiveis NO MODULO da fonte
     * @return array{fonte:string,titulo:string,colunas:string[],linhas:array,total:int,filtros:array,gerado_em:string,truncado:bool}
     */
    public static function montar(string $fonte, array $filtros, array $accountIds): array
    {
        $fonte      = strtolower(trim($fonte));
        $accountIds = self::inteiros($accountIds);

        if (!isset(self::FONTES[$fonte]) || $accountIds === []) {
            return self::vazio($fonte);
        }

        return match ($fonte) {
            'clientes'    => self::clientes($filtros, $accountIds),
            'prospeccoes' => self::prospeccoes($filtros, $accountIds),
            'processos'   => self::processos($filtros, $accountIds),
        };
    }

    /** As opcoes que a tela precisa para montar os filtros, ja no escopo da conta. */
    public static function opcoes(string $fonte, array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || !isset(self::FONTES[$fonte])) {
            return ['status' => [], 'setores' => [], 'responsaveis' => [], 'etapas' => []];
        }
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));

        $status = match ($fonte) {
            'clientes'    => ['ativo' => 'Ativos', 'inativo' => 'Inativos'],
            'prospeccoes' => ['aberto' => 'Em aberto', 'fechado' => 'Fechadas', 'perdido' => 'Perdidas'],
            'processos'   => ['ativo' => 'Em aberto', 'concluido' => 'Concluídos', 'suspenso' => 'Suspensos', 'encerrado' => 'Encerrados'],
        };

        $setores = [];
        $st = $pdo->prepare("SELECT id, nome FROM clientes_setores WHERE ativo = 1 AND account_id IN ($in) ORDER BY ordem, nome");
        $st->execute($accountIds);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $setores[(int) $r['id']] = (string) $r['nome'];
        }

        $etapas = [];
        if ($fonte === 'prospeccoes') {
            $st = $pdo->prepare("SELECT id, nome FROM pipeline_columns WHERE account_id IN ($in) ORDER BY ordem, nome");
            $st->execute($accountIds);
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $etapas[(int) $r['id']] = (string) $r['nome'];
            }
        }

        /*
         * Responsaveis: quem de fato aparece como responsavel nesta fonte, e nao
         * a lista inteira de usuarios. Uma lista com 40 nomes onde so 6 tem
         * registro nao ajuda a filtrar.
         */
        $col = match ($fonte) {
            'clientes'    => ['clientes', 'responsavel_id'],
            'prospeccoes' => ['cards', 'responsavel_user_id'],
            'processos'   => ['processos', 'responsavel_user_id'],
        };
        $responsaveis = [];
        $st = $pdo->prepare(
            "SELECT DISTINCT u.id, u.nome
               FROM `{$col[0]}` t
               JOIN users u ON u.id = t.`{$col[1]}`
              WHERE t.deleted_at IS NULL AND t.account_id IN ($in)
           ORDER BY u.nome"
        );
        $st->execute($accountIds);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $responsaveis[(int) $r['id']] = (string) $r['nome'];
        }

        return ['status' => $status, 'setores' => $setores, 'responsaveis' => $responsaveis, 'etapas' => $etapas];
    }

    /* ===================================================================== */
    /* clientes                                                               */
    /* ===================================================================== */

    private static function clientes(array $f, array $accountIds): array
    {
        $where  = ['c.deleted_at IS NULL'];
        $params = [];
        self::comum($f, $where, $params, 'c', ['c.nome', 'c.cpf_cnpj', 'c.telefone', 'c.whatsapp', 'c.email'], 'setor_id', 'responsavel_id');

        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $ordem = self::ordem($f['ordem'] ?? '', [
            'nome'    => 'c.nome ASC',
            'recente' => 'c.created_at DESC',
            'antigo'  => 'c.created_at ASC',
            'setor'   => 's.nome ASC, c.nome ASC',
        ], 'c.nome ASC');

        $sql = "SELECT c.id, c.nome, c.tipo_cliente, c.cpf_cnpj, c.telefone, c.whatsapp, c.email,
                       c.status, c.created_at, c.data_nascimento,
                       s.nome AS setor_nome, r.nome AS responsavel_nome, o.nome AS origem_nome,
                       (SELECT COUNT(*) FROM processos p
                         WHERE p.cliente_id = c.id AND p.deleted_at IS NULL) AS qtd_processos
                  FROM clientes c
             LEFT JOIN clientes_setores s ON s.id = c.setor_id
             LEFT JOIN users            r ON r.id = c.responsavel_id
             LEFT JOIN clientes_origens o ON o.id = c.origem
                 WHERE " . implode(' AND ', $where) . "
                   AND c.account_id IN ($in)
              ORDER BY $ordem
                 LIMIT " . (self::LIMITE_MAXIMO + 1);

        $st = Database::getConnection()->prepare($sql);
        $st->execute(array_merge($params, $accountIds));
        $brutas = $st->fetchAll(\PDO::FETCH_ASSOC);
        [$brutas, $truncado] = self::corta($brutas);

        $linhas = [];
        foreach ($brutas as $r) {
            $linhas[] = [
                (string) $r['nome'],
                self::tipoCliente($r['tipo_cliente'] ?? null),
                (string) ($r['cpf_cnpj'] ?? ''),
                (string) (($r['whatsapp'] ?? '') ?: ($r['telefone'] ?? '')),
                (string) ($r['email'] ?? ''),
                (string) ($r['setor_nome'] ?? ''),
                (string) ($r['responsavel_nome'] ?? ''),
                (string) ($r['origem_nome'] ?? ''),
                self::statusCliente($r['status'] ?? null),
                (string) $r['qtd_processos'],
                self::data($r['created_at'] ?? null),
            ];
        }

        return self::resultado('clientes', ['Nome', 'Tipo', 'CPF / CNPJ', 'Telefone', 'E-mail', 'Setor', 'Responsável', 'Canal', 'Situação', 'Processos', 'Cliente desde'], $linhas, $f, $truncado);
    }

    /* ===================================================================== */
    /* prospeccoes                                                            */
    /* ===================================================================== */

    private static function prospeccoes(array $f, array $accountIds): array
    {
        $where  = ['k.deleted_at IS NULL'];
        $params = [];
        self::comum($f, $where, $params, 'k', ['k.cliente_nome', 'k.empresa_nome', 'k.telefone_whatsapp', 'k.email', 'k.cpf_cnpj'], null, 'responsavel_user_id');

        // Etapa do funil e filtro exclusivo desta fonte.
        $etapa = (int) ($f['etapa_id'] ?? 0);
        if ($etapa > 0) {
            $where[]  = 'k.coluna_id = ?';
            $params[] = $etapa;
        }

        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $ordem = self::ordem($f['ordem'] ?? '', [
            'nome'    => 'k.cliente_nome ASC',
            'recente' => 'k.created_at DESC',
            'antigo'  => 'k.created_at ASC',
            'valor'   => 'k.valor_estimado DESC',
            'parada'  => 'k.updated_at ASC',
        ], 'k.created_at DESC');

        $sql = "SELECT k.id, k.cliente_nome, k.empresa_nome, k.telefone_whatsapp, k.email,
                       k.status, k.valor_estimado, k.created_at, k.updated_at,
                       col.nome AS coluna_nome, r.nome AS responsavel_nome, o.nome AS origem_nome,
                       DATEDIFF(NOW(), k.updated_at) AS dias_parada
                  FROM cards k
             LEFT JOIN pipeline_columns col ON col.id = k.coluna_id
             LEFT JOIN users            r   ON r.id   = k.responsavel_user_id
             LEFT JOIN clientes_origens o   ON o.id   = k.origem_id
                 WHERE " . implode(' AND ', $where) . "
                   AND k.account_id IN ($in)
              ORDER BY $ordem
                 LIMIT " . (self::LIMITE_MAXIMO + 1);

        $st = Database::getConnection()->prepare($sql);
        $st->execute(array_merge($params, $accountIds));
        $brutas = $st->fetchAll(\PDO::FETCH_ASSOC);
        [$brutas, $truncado] = self::corta($brutas);

        $linhas = [];
        foreach ($brutas as $r) {
            $linhas[] = [
                (string) ($r['cliente_nome'] ?? ''),
                (string) ($r['empresa_nome'] ?? ''),
                (string) ($r['telefone_whatsapp'] ?? ''),
                (string) ($r['email'] ?? ''),
                (string) ($r['coluna_nome'] ?? ''),
                self::statusCard($r['status'] ?? null),
                (string) ($r['responsavel_nome'] ?? ''),
                (string) ($r['origem_nome'] ?? ''),
                self::dinheiro($r['valor_estimado'] ?? null),
                self::data($r['created_at'] ?? null),
                $r['dias_parada'] === null ? '' : (string) (int) $r['dias_parada'],
            ];
        }

        return self::resultado('prospeccoes', ['Nome', 'Empresa', 'Telefone', 'E-mail', 'Etapa', 'Situação', 'Responsável', 'Canal', 'Valor estimado', 'Criada em', 'Dias sem mexer'], $linhas, $f, $truncado);
    }

    /* ===================================================================== */
    /* processos                                                              */
    /* ===================================================================== */

    private static function processos(array $f, array $accountIds): array
    {
        $where  = ['p.deleted_at IS NULL'];
        $params = [];
        self::comum($f, $where, $params, 'p', ['p.numero', 'p.numero_cnj', 'p.cliente_nome', 'p.parte_contraria', 'p.tipo_acao'], 'setor_id', 'responsavel_user_id');

        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $ordem = self::ordem($f['ordem'] ?? '', [
            'prazo'   => 'p.proximo_prazo IS NULL, p.proximo_prazo ASC',
            'recente' => 'p.created_at DESC',
            'antigo'  => 'p.created_at ASC',
            'cliente' => 'p.cliente_nome ASC',
            'parada'  => 'p.ultima_movimentacao IS NULL, p.ultima_movimentacao ASC',
        ], 'p.proximo_prazo IS NULL, p.proximo_prazo ASC');

        $sql = "SELECT p.id, p.numero, p.numero_cnj, p.cliente_nome, p.parte_contraria,
                       p.tipo_acao, p.vara_comarca, p.status, p.data_inicio,
                       p.proximo_prazo, p.ultima_movimentacao,
                       s.nome AS setor_nome, r.nome AS responsavel_nome,
                       cli.nome AS cliente_vinculado_nome,
                       DATEDIFF(p.proximo_prazo, CURDATE()) AS dias_para_prazo
                  FROM processos p
             LEFT JOIN clientes_setores s   ON s.id   = p.setor_id
             LEFT JOIN users            r   ON r.id   = p.responsavel_user_id
             LEFT JOIN clientes         cli ON cli.id = p.cliente_id
                 WHERE " . implode(' AND ', $where) . "
                   AND p.account_id IN ($in)
              ORDER BY $ordem
                 LIMIT " . (self::LIMITE_MAXIMO + 1);

        $st = Database::getConnection()->prepare($sql);
        $st->execute(array_merge($params, $accountIds));
        $brutas = $st->fetchAll(\PDO::FETCH_ASSOC);
        [$brutas, $truncado] = self::corta($brutas);

        $linhas = [];
        foreach ($brutas as $r) {
            $linhas[] = [
                (string) (($r['numero_cnj'] ?? '') ?: ($r['numero'] ?? '')),
                (string) (($r['cliente_vinculado_nome'] ?? '') ?: ($r['cliente_nome'] ?? '')),
                (string) ($r['parte_contraria'] ?? ''),
                (string) ($r['tipo_acao'] ?? ''),
                (string) ($r['vara_comarca'] ?? ''),
                (string) ($r['setor_nome'] ?? ''),
                (string) ($r['responsavel_nome'] ?? ''),
                self::statusProcesso($r['status'] ?? null),
                self::data($r['data_inicio'] ?? null),
                self::data($r['proximo_prazo'] ?? null),
                // Dias para o prazo: negativo significa vencido. Sai como texto
                // legivel, porque "-4" numa folha impressa nao diz nada.
                self::prazoEmTexto($r['dias_para_prazo'] ?? null),
                self::data($r['ultima_movimentacao'] ?? null),
            ];
        }

        return self::resultado('processos', ['Número', 'Cliente', 'Parte contrária', 'Tipo de ação', 'Vara / comarca', 'Setor', 'Responsável', 'Situação', 'Início', 'Próximo prazo', 'Prazo', 'Última movimentação'], $linhas, $f, $truncado);
    }

    /* ===================================================================== */
    /* filtros compartilhados                                                 */
    /* ===================================================================== */

    /**
     * Os filtros que as tres fontes tem em comum: situacao, setor, responsavel,
     * periodo e busca livre.
     *
     * Escreve em $where e $params por referencia. Todo valor entra como
     * placeholder; nada de vindo do usuario e concatenado.
     *
     * @param string[] $camposBusca colunas ja qualificadas pelo alias
     */
    private static function comum(array $f, array &$where, array &$params, string $alias, array $camposBusca, ?string $colSetor, string $colResponsavel): void
    {
        $status = trim((string) ($f['status'] ?? ''));
        if ($status !== '' && $status !== 'todos') {
            $where[]  = "$alias.status = ?";
            $params[] = $status;
        }

        $setor = (int) ($f['setor_id'] ?? 0);
        if ($setor > 0 && $colSetor !== null) {
            $where[]  = "$alias.`$colSetor` = ?";
            $params[] = $setor;
        }

        $resp = (int) ($f['responsavel_id'] ?? 0);
        if ($resp > 0) {
            $where[]  = "$alias.`$colResponsavel` = ?";
            $params[] = $resp;
        }

        // Periodo pelo cadastro. Data invalida e IGNORADA em vez de virar
        // filtro vazio: um relatorio que devolve zero por causa de um campo mal
        // digitado parece um sistema quebrado.
        $de = self::dataIso($f['de'] ?? '');
        if ($de !== null) {
            $where[]  = "$alias.created_at >= ?";
            $params[] = $de . ' 00:00:00';
        }
        $ate = self::dataIso($f['ate'] ?? '');
        if ($ate !== null) {
            $where[]  = "$alias.created_at <= ?";
            $params[] = $ate . ' 23:59:59';
        }

        $busca = trim((string) ($f['busca'] ?? ''));
        if ($busca !== '' && $camposBusca !== []) {
            $ors = [];
            foreach ($camposBusca as $campo) {
                $ors[]    = "$campo LIKE ?";
                $params[] = '%' . $busca . '%';
            }
            $where[] = '(' . implode(' OR ', $ors) . ')';
        }
    }

    /**
     * A ordenacao e a UNICA parte da consulta montada como texto, e por isso ela
     * NUNCA usa o que o usuario mandou: usa a chave dele para escolher um valor
     * da allowlist. Chave desconhecida cai no padrao.
     */
    private static function ordem(string $pedida, array $permitidas, string $padrao): string
    {
        $pedida = strtolower(trim($pedida));
        return $permitidas[$pedida] ?? $padrao;
    }

    /**
     * Consultamos LIMITE+1 e cortamos aqui. E assim que se sabe que havia mais
     * sem fazer um COUNT separado, e e por isso que o relatorio consegue avisar
     * "esta lista foi cortada" em vez de mentir por omissao.
     *
     * @return array{0:array,1:bool}
     */
    private static function corta(array $linhas): array
    {
        if (count($linhas) > self::LIMITE_MAXIMO) {
            return [array_slice($linhas, 0, self::LIMITE_MAXIMO), true];
        }
        return [$linhas, false];
    }

    /**
     * Quais colunas de cada fonte podem QUEBRAR em varias linhas.
     *
     * As outras vao com `nowrap`, e o motivo e concreto: numa coluna espremida o
     * navegador partia "20/01/2026" ao meio, virando "20/01/20" e "26" em linhas
     * diferentes. Numa folha de processo isso nao e feio, e errado: quem le ve
     * duas datas.
     *
     * A informacao mora aqui, e nao no CSS, porque quem sabe que a coluna 4 e
     * "Vara / comarca" e quem monta a coluna 4.
     */
    private const COLUNAS_LIVRES = [
        'clientes'    => [0, 4],           // Nome, E-mail
        'prospeccoes' => [0, 1, 3],        // Nome, Empresa, E-mail
        'processos'   => [1, 2, 3, 4],     // Cliente, Parte contraria, Tipo, Vara
    ];

    private static function resultado(string $fonte, array $colunas, array $linhas, array $filtros, bool $truncado): array
    {
        return [
            'fonte'     => $fonte,
            'titulo'    => self::FONTES[$fonte]['titulo'],
            'colunas'   => $colunas,
            'colunas_livres' => self::COLUNAS_LIVRES[$fonte] ?? [],
            'linhas'    => $linhas,
            'total'     => count($linhas),
            'filtros'   => $filtros,
            'truncado'  => $truncado,
            'limite'    => self::LIMITE_MAXIMO,
            'gerado_em' => date('d/m/Y H:i'),
        ];
    }

    private static function vazio(string $fonte): array
    {
        return [
            'fonte'     => $fonte,
            'titulo'    => self::FONTES[$fonte]['titulo'] ?? 'Relatório',
            'colunas'   => [],
            'colunas_livres' => [],
            'linhas'    => [],
            'total'     => 0,
            'filtros'   => [],
            'truncado'  => false,
            'limite'    => self::LIMITE_MAXIMO,
            'gerado_em' => date('d/m/Y H:i'),
        ];
    }

    /* ===================================================================== */
    /* formatacao                                                             */
    /* ===================================================================== */

    /** aaaa-mm-dd, ou null quando a data nao existe de verdade. */
    private static function dataIso($v): ?string
    {
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', substr($s, 0, 10));
        // `createFromFormat` aceita 2026-13-45 e "corrige" para 2027-02-14. Sem
        // esta conferencia, uma data impossivel viraria um filtro silencioso.
        return ($d && $d->format('Y-m-d') === substr($s, 0, 10)) ? $d->format('Y-m-d') : null;
    }

    private static function data($v): string
    {
        $s = trim((string) ($v ?? ''));
        if ($s === '' || str_starts_with($s, '0000')) {
            return '';
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', substr($s, 0, 10));
        return $d ? $d->format('d/m/Y') : $s;
    }

    private static function dinheiro($v): string
    {
        if ($v === null || $v === '' || (float) $v == 0.0) {
            return '';
        }
        return number_format((float) $v, 2, ',', '.');
    }

    private static function prazoEmTexto($dias): string
    {
        if ($dias === null || $dias === '') {
            return '';
        }
        $d = (int) $dias;
        if ($d < 0)  return 'vencido há ' . abs($d) . ' dia' . (abs($d) === 1 ? '' : 's');
        if ($d === 0) return 'vence hoje';
        return 'em ' . $d . ' dia' . ($d === 1 ? '' : 's');
    }

    private static function tipoCliente($v): string
    {
        return match (strtolower(trim((string) $v))) {
            'pf' => 'Pessoa física',
            'pj' => 'Pessoa jurídica',
            default => (string) $v,
        };
    }

    private static function statusCliente($v): string
    {
        return match (strtolower(trim((string) $v))) {
            'ativo'   => 'Ativo',
            'inativo' => 'Inativo',
            default   => (string) $v,
        };
    }

    private static function statusCard($v): string
    {
        return match (strtolower(trim((string) $v))) {
            'aberto'  => 'Em aberto',
            'fechado' => 'Fechada',
            'perdido' => 'Perdida',
            default   => (string) $v,
        };
    }

    private static function statusProcesso($v): string
    {
        return match (strtolower(trim((string) $v))) {
            'ativo'     => 'Em aberto',
            'concluido' => 'Concluído',
            'suspenso'  => 'Suspenso',
            'encerrado' => 'Encerrado',
            default     => (string) $v,
        };
    }

    /** @return int[] */
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
