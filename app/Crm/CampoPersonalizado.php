<?php

namespace App\Crm;

use App\Core\Database;

/**
 * CampoPersonalizado — campos que o escritorio cria (bloco C da Fase 2).
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA
 * ---------------------------------------------------------------------------
 * O cadastro de cliente e o de prospeccao tem os campos que o Yuris decidiu:
 * nome, CPF, RG, nome da mae, endereco, telefone. Cada escritorio precisa de
 * outros. Previdenciario quer NIT e data de entrada no RGPS. Trabalhista quer
 * data de admissao e funcao. Nao ha como o produto adivinhar, e acrescentar
 * coluna a cada pedido nao escala.
 *
 * ---------------------------------------------------------------------------
 * DUAS TABELAS, E POR QUE NAO UMA COLUNA JSON
 * ---------------------------------------------------------------------------
 * O caminho curto seria `clientes.campos_json`. Foi recusado por tres motivos
 * concretos:
 *
 *  1. AUDITORIA. O pedido da Fase 1 exigia registro de mudanca campo a campo,
 *     valor antigo para valor novo. Com JSON numa coluna, toda alteracao vira um
 *     unico "campos_json mudou" e o diff fica para quem estiver lendo depois.
 *     Com valor por linha, `salvarValores()` sabe exatamente o que mudou.
 *  2. A DEFINICAO PRECISA EXISTIR SOZINHA. Rotulo, tipo, opcoes, obrigatorio e
 *     ordem sao configuracao da conta, nao dado do cliente. Em JSON no cliente,
 *     criar um campo novo exigiria varrer todas as fichas.
 *  3. FILTRAR E CONTAR. "Todos os clientes com NIT preenchido" e um WHERE numa
 *     tabela indexada, nao um JSON_EXTRACT em varredura completa.
 *
 * ---------------------------------------------------------------------------
 * `chave` E `rotulo` SAO COISAS DIFERENTES
 * ---------------------------------------------------------------------------
 * `chave` e o slug estavel e e o que a auditoria grava. `rotulo` e o que aparece
 * na tela e pode ser reescrito quando quiserem. Trocar "NIT" para "Numero de
 * Identificacao do Trabalhador" nao reescreve nem invalida um evento antigo.
 *
 * ---------------------------------------------------------------------------
 * O VALOR E SEMPRE TEXTO
 * ---------------------------------------------------------------------------
 * Uma coluna `valor TEXT` para todos os tipos, e nao valor_texto/valor_numero/
 * valor_data. Tipo em campo definido pelo usuario muda: o "codigo interno" que
 * era numero passa a aceitar letra. Com colunas por tipo, essa troca exige
 * migrar dado. Com texto e validacao na entrada, e uma linha na definicao.
 *
 * A validacao acontece em `normalizarValor()`, que rejeita o que nao cabe no
 * tipo e normaliza data para Y-m-d e numero para ponto decimal, de modo que
 * ordenar e comparar continue funcionando.
 *
 * ---------------------------------------------------------------------------
 * COPIA NA CONVERSAO
 * ---------------------------------------------------------------------------
 * Valor de campo e opiniao editavel, igual a tag: copia uma vez e fica solto.
 * Mas com uma trava que a tag nao precisa: so copia para campo VAZIO no cliente.
 * Numa vinculacao a cliente que ja existe, o que ele ja tem preenchido vale mais
 * que o que o lead trouxe.
 */
final class CampoPersonalizado
{
    public const TIPOS = [
        'texto', 'texto_longo', 'numero', 'moeda', 'data',
        'selecao', 'multi_selecao', 'sim_nao',
    ];

    /** Tipos que exigem `opcoes_json`. */
    public const TIPOS_COM_OPCOES = ['selecao', 'multi_selecao'];

    public const APLICA_EM = ['cliente', 'card', 'ambos'];

    /* ===================================================================== */
    /* definicoes                                                            */
    /* ===================================================================== */

    /**
     * Definicoes das contas dadas.
     *
     * @param int[]       $accountIds
     * @param string|null $paraEntidade 'cliente' ou 'card': filtra por aplica_em,
     *                                  incluindo sempre os de 'ambos'
     */
    public static function definicoes(array $accountIds, ?string $paraEntidade = null, bool $incluirInativas = false): array
    {
        $accountIds = Entidade::inteiros($accountIds);
        if ($accountIds === []) {
            return [];
        }
        $in     = implode(',', array_fill(0, count($accountIds), '?'));
        $params = $accountIds;

        $sql = "SELECT id, account_id, aplica_em, chave, rotulo, tipo, opcoes_json,
                       obrigatorio, ordem, ativo
                  FROM crm_campos
                 WHERE account_id IN ($in)";

        if ($paraEntidade !== null && in_array($paraEntidade, Entidade::TIPOS, true)) {
            $sql     .= " AND aplica_em IN (?, 'ambos')";
            $params[] = $paraEntidade;
        }
        if (!$incluirInativas) {
            $sql .= ' AND ativo = 1';
        }
        $sql .= ' ORDER BY ordem, rotulo';

        $st = Database::getConnection()->prepare($sql);
        $st->execute($params);

        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($linhas as &$l) {
            $l['opcoes'] = self::opcoes($l['opcoes_json']);
            unset($l['opcoes_json']);
        }
        unset($l);
        return $linhas;
    }

    /**
     * Cria uma definicao. A `chave` e derivada do rotulo e fica unica por conta.
     *
     * @return array<string,mixed> a definicao criada
     * @throws \InvalidArgumentException quando tipo ou opcoes nao fecham
     */
    public static function criarDefinicao(
        int $accountId,
        string $rotulo,
        string $tipo,
        string $aplicaEm = 'ambos',
        array $opcoes = [],
        bool $obrigatorio = false,
        ?int $userId = null
    ): array {
        $rotulo = trim($rotulo);
        if ($rotulo === '') {
            throw new \InvalidArgumentException('Rotulo e obrigatorio');
        }
        if (!in_array($tipo, self::TIPOS, true)) {
            throw new \InvalidArgumentException('Tipo invalido');
        }
        if (!in_array($aplicaEm, self::APLICA_EM, true)) {
            $aplicaEm = 'ambos';
        }
        $opcoes = self::limparOpcoes($opcoes);
        if (in_array($tipo, self::TIPOS_COM_OPCOES, true) && $opcoes === []) {
            throw new \InvalidArgumentException('Campo de selecao precisa de pelo menos uma opcao');
        }

        $rotulo = mb_substr($rotulo, 0, 120);
        $chave  = self::chaveUnica($accountId, Tag::slugify($rotulo));

        $pdo   = Database::getConnection();
        $ordem = (int) $pdo->query(
            'SELECT COALESCE(MAX(ordem), 0) + 1 FROM crm_campos WHERE account_id = ' . (int) $accountId
        )->fetchColumn();

        $pdo->prepare(
            'INSERT INTO crm_campos
               (account_id, aplica_em, chave, rotulo, tipo, opcoes_json, obrigatorio,
                ordem, ativo, created_by, created_at, updated_at)
             VALUES (:acc, :apl, :chave, :rot, :tipo, :op, :obr, :ordem, 1, :uid, NOW(), NOW())'
        )->execute([
            'acc'   => $accountId,
            'apl'   => $aplicaEm,
            'chave' => $chave,
            'rot'   => $rotulo,
            'tipo'  => $tipo,
            'op'    => $opcoes === [] ? null : json_encode($opcoes, JSON_UNESCAPED_UNICODE),
            'obr'   => $obrigatorio ? 1 : 0,
            'ordem' => $ordem,
            'uid'   => $userId,
        ]);

        return [
            'id'          => (int) $pdo->lastInsertId(),
            'account_id'  => $accountId,
            'aplica_em'   => $aplicaEm,
            'chave'       => $chave,
            'rotulo'      => $rotulo,
            'tipo'        => $tipo,
            'opcoes'      => $opcoes,
            'obrigatorio' => $obrigatorio ? 1 : 0,
            'ordem'       => $ordem,
            'ativo'       => 1,
        ];
    }

    /**
     * Atualiza rotulo, opcoes, obrigatoriedade, ordem e onde aplica.
     *
     * O `tipo` NAO muda depois de criado, e a `chave` tambem nao. Trocar o tipo
     * de um campo que ja tem valor gravado deixaria dado invalido para tras (o
     * texto "aposentadoria" num campo que virou numero), e escolher entre apagar
     * o valor e manter dado invalido nao e decisao que este metodo possa tomar
     * em silencio. Quem precisa de outro tipo arquiva e cria outro campo.
     */
    public static function atualizarDefinicao(int $campoId, array $accountIds, array $dados): bool
    {
        $def = self::buscarDefinicao($campoId, $accountIds);
        if ($def === null) {
            return false;
        }

        $sets   = [];
        $params = ['id' => $campoId];

        if (isset($dados['rotulo']) && trim((string) $dados['rotulo']) !== '') {
            $sets[]        = 'rotulo = :rot';
            $params['rot'] = mb_substr(trim((string) $dados['rotulo']), 0, 120);
        }
        if (isset($dados['aplica_em']) && in_array($dados['aplica_em'], self::APLICA_EM, true)) {
            $sets[]        = 'aplica_em = :apl';
            $params['apl'] = $dados['aplica_em'];
        }
        if (array_key_exists('obrigatorio', $dados)) {
            $sets[]        = 'obrigatorio = :obr';
            $params['obr'] = !empty($dados['obrigatorio']) ? 1 : 0;
        }
        if (isset($dados['ordem'])) {
            $sets[]          = 'ordem = :ordem';
            $params['ordem'] = (int) $dados['ordem'];
        }
        if (array_key_exists('ativo', $dados)) {
            $sets[]        = 'ativo = :ativo';
            $params['ativo'] = !empty($dados['ativo']) ? 1 : 0;
        }
        if (isset($dados['opcoes']) && is_array($dados['opcoes'])) {
            $op = self::limparOpcoes($dados['opcoes']);
            if (in_array($def['tipo'], self::TIPOS_COM_OPCOES, true) && $op === []) {
                throw new \InvalidArgumentException('Campo de selecao precisa de pelo menos uma opcao');
            }
            $sets[]       = 'opcoes_json = :op';
            $params['op'] = $op === [] ? null : json_encode($op, JSON_UNESCAPED_UNICODE);
        }

        if ($sets === []) {
            return false;
        }
        $sets[] = 'updated_at = NOW()';

        return Database::getConnection()
            ->prepare('UPDATE crm_campos SET ' . implode(', ', $sets) . ' WHERE id = :id')
            ->execute($params);
    }

    /** Arquiva a definicao. Os valores gravados FICAM, igual a tag arquivada. */
    public static function arquivarDefinicao(int $campoId, array $accountIds): bool
    {
        if (self::buscarDefinicao($campoId, $accountIds) === null) {
            return false;
        }
        return Database::getConnection()
            ->prepare('UPDATE crm_campos SET ativo = 0, updated_at = NOW() WHERE id = ?')
            ->execute([$campoId]);
    }

    /** @param int[] $accountIds */
    public static function buscarDefinicao(int $campoId, array $accountIds): ?array
    {
        $accountIds = Entidade::inteiros($accountIds);
        if ($campoId <= 0 || $accountIds === []) {
            return null;
        }
        $in = implode(',', array_fill(0, count($accountIds), '?'));
        $st = Database::getConnection()->prepare(
            "SELECT * FROM crm_campos WHERE id = ? AND account_id IN ($in) LIMIT 1"
        );
        $st->execute(array_merge([$campoId], $accountIds));
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /* ===================================================================== */
    /* valores                                                               */
    /* ===================================================================== */

    /**
     * Definicoes que valem para esta entidade, JA com o valor gravado nela.
     *
     * LEFT JOIN e nao INNER: campo criado hoje aparece vazio numa ficha antiga,
     * que e o comportamento certo. Com INNER, o campo novo ficaria invisivel
     * exatamente nas fichas onde ainda ninguem preencheu.
     *
     * @param int[] $accountIds
     */
    public static function valores(string $entidade, int $entidadeId, array $accountIds): array
    {
        if (!in_array($entidade, Entidade::TIPOS, true)) {
            return [];
        }
        $accountIds = Entidade::inteiros($accountIds);
        if ($accountIds === [] || $entidadeId <= 0) {
            return [];
        }
        $in = implode(',', array_fill(0, count($accountIds), '?'));

        $st = Database::getConnection()->prepare(
            "SELECT c.id AS campo_id, c.chave, c.rotulo, c.tipo, c.opcoes_json,
                    c.obrigatorio, c.ordem, v.valor, v.updated_at, v.updated_by,
                    u.nome AS atualizado_por_nome
               FROM crm_campos c
          LEFT JOIN crm_campo_valores v
                 ON v.campo_id = c.id AND v.entidade = ? AND v.entidade_id = ?
          LEFT JOIN users u ON u.id = v.updated_by
              WHERE c.account_id IN ($in)
                AND c.ativo = 1
                AND c.aplica_em IN (?, 'ambos')
           ORDER BY c.ordem, c.rotulo"
        );
        $st->execute(array_merge([$entidade, $entidadeId], $accountIds, [$entidade]));

        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($linhas as &$l) {
            $l['opcoes'] = self::opcoes($l['opcoes_json']);
            unset($l['opcoes_json']);
        }
        unset($l);
        return $linhas;
    }

    /**
     * Grava valores. Um evento de historico POR CAMPO QUE MUDOU, com valor
     * antigo e novo. Campo que veio igual nao gera evento.
     *
     * @param array{entidade:string,id:int,account_id:int,titulo:string} $alvo
     * @param array<int|string,mixed> $novos  campo_id ou chave => valor
     * @return array{gravados:int,erros:array<string,string>}
     */
    public static function salvarValores(array $alvo, array $novos, array $accountIds, ?int $userId): array
    {
        $defs = self::valores($alvo['entidade'], $alvo['id'], $accountIds);
        if ($defs === []) {
            return ['gravados' => 0, 'erros' => []];
        }

        // Aceita chave OU campo_id como indice, porque o front manda chave (que e
        // legivel no payload) e a conversao manda id (que ela ja tem em mao).
        $porChave = [];
        $porId    = [];
        foreach ($defs as $d) {
            $porChave[$d['chave']]      = $d;
            $porId[(int) $d['campo_id']] = $d;
        }

        $pdo      = Database::getConnection();
        $gravados = 0;
        $erros    = [];

        foreach ($novos as $ref => $bruto) {
            $def = $porChave[$ref] ?? $porId[(int) $ref] ?? null;
            if ($def === null) {
                continue; // campo que nao existe nesta conta: ignora em silencio
            }

            try {
                $valor = self::normalizarValor($bruto, (string) $def['tipo'], $def['opcoes']);
            } catch (\InvalidArgumentException $e) {
                $erros[(string) $def['chave']] = $e->getMessage();
                continue;
            }

            $antigo = $def['valor'];
            if ((string) $antigo === (string) ($valor ?? '')) {
                continue; // nao mudou: nem UPDATE nem evento
            }

            if ($valor === null) {
                $pdo->prepare(
                    'DELETE FROM crm_campo_valores
                      WHERE campo_id = ? AND entidade = ? AND entidade_id = ?'
                )->execute([(int) $def['campo_id'], $alvo['entidade'], $alvo['id']]);
            } else {
                // ON DUPLICATE KEY sobre o UNIQUE (campo_id, entidade, entidade_id):
                // grava ou atualiza numa ida so, sem SELECT antes.
                $pdo->prepare(
                    'INSERT INTO crm_campo_valores
                       (campo_id, account_id, entidade, entidade_id, valor, updated_by, created_at, updated_at)
                     VALUES (:cid, :acc, :ent, :eid, :val, :uid, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE valor = VALUES(valor),
                                             updated_by = VALUES(updated_by),
                                             updated_at = NOW()'
                )->execute([
                    'cid' => (int) $def['campo_id'],
                    'acc' => $alvo['account_id'],
                    'ent' => $alvo['entidade'],
                    'eid' => $alvo['id'],
                    'val' => $valor,
                    'uid' => $userId,
                ]);
            }

            // O historico grava a CHAVE, nao o rotulo: o rotulo pode ser
            // reescrito amanha e o evento de hoje continuaria dizendo a verdade.
            Auditoria::registrar(
                $alvo,
                $userId,
                'campo_personalizado',
                (string) $def['chave'],
                $antigo,
                $valor
            );
            $gravados++;
        }

        return ['gravados' => $gravados, 'erros' => $erros];
    }

    /**
     * Copia valores de uma prospeccao para o cliente, SO onde o cliente esta
     * vazio. Chamado pela conversao, dentro da transacao dela.
     *
     * O `aplica_em` importa aqui: um campo marcado so como 'card' e do funil
     * comercial e nao faz sentido na ficha do cliente. Ele nao e copiado, e o
     * valor continua visivel pela prospeccao de origem.
     *
     * @return string[] rotulos copiados
     */
    public static function copiarCardParaCliente(int $cardId, int $clienteId, int $accountId, ?int $userId): array
    {
        $pdo = Database::getConnection();

        $st = $pdo->prepare(
            "SELECT v.campo_id, v.valor, c.rotulo
               FROM crm_campo_valores v
               JOIN crm_campos c ON c.id = v.campo_id
              WHERE v.entidade = 'card' AND v.entidade_id = ? AND v.account_id = ?
                AND c.aplica_em IN ('cliente','ambos')
                AND c.ativo = 1
                AND v.valor IS NOT NULL AND v.valor <> ''
                AND NOT EXISTS (
                      SELECT 1 FROM crm_campo_valores ex
                       WHERE ex.campo_id = v.campo_id
                         AND ex.entidade = 'cliente'
                         AND ex.entidade_id = ?
                         AND ex.valor IS NOT NULL AND ex.valor <> ''
                )"
        );
        $st->execute([$cardId, $accountId, $clienteId]);
        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        if ($linhas === []) {
            return [];
        }

        $ins = $pdo->prepare(
            'INSERT INTO crm_campo_valores
               (campo_id, account_id, entidade, entidade_id, valor, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE valor = VALUES(valor),
                                     updated_by = VALUES(updated_by),
                                     updated_at = NOW()'
        );

        $rotulos = [];
        foreach ($linhas as $l) {
            $ins->execute([
                (int) $l['campo_id'], $accountId, Entidade::CLIENTE, $clienteId,
                $l['valor'], $userId,
            ]);
            $rotulos[] = (string) $l['rotulo'];
        }
        return $rotulos;
    }

    /* ===================================================================== */
    /* helpers                                                               */
    /* ===================================================================== */

    /**
     * Valida e normaliza o valor para o tipo. Devolve null para vazio, que
     * significa "apaga o valor".
     *
     * @param string[] $opcoes
     * @throws \InvalidArgumentException quando o valor nao cabe no tipo
     */
    public static function normalizarValor($bruto, string $tipo, array $opcoes): ?string
    {
        if (is_array($bruto)) {
            if ($tipo !== 'multi_selecao') {
                throw new \InvalidArgumentException('Este campo aceita um valor so');
            }
            $escolhidas = [];
            foreach ($bruto as $x) {
                $s = trim((string) $x);
                if ($s !== '' && in_array($s, $opcoes, true)) {
                    $escolhidas[$s] = $s;
                }
            }
            if ($escolhidas === []) {
                return null;
            }
            return json_encode(array_values($escolhidas), JSON_UNESCAPED_UNICODE);
        }

        $v = trim((string) ($bruto ?? ''));
        if ($v === '') {
            return null;
        }

        switch ($tipo) {
            case 'numero':
            case 'moeda':
                // Aceita "1.234,56" (BR) e "1234.56". Guarda sempre com ponto,
                // para ordenar e somar sem tradutor no meio.
                $n = str_replace(' ', '', $v);
                if (str_contains($n, ',')) {
                    $n = str_replace('.', '', $n);
                    $n = str_replace(',', '.', $n);
                }
                if (!is_numeric($n)) {
                    throw new \InvalidArgumentException('Valor precisa ser numerico');
                }
                return (string) (0 + $n);

            case 'data':
                // Aceita Y-m-d e d/m/Y. Guarda Y-m-d.
                $d = \DateTime::createFromFormat('Y-m-d', $v)
                  ?: \DateTime::createFromFormat('d/m/Y', $v);
                if (!$d) {
                    throw new \InvalidArgumentException('Data invalida');
                }
                return $d->format('Y-m-d');

            case 'sim_nao':
                $sim = ['1', 'sim', 'true', 'on', 's'];
                $nao = ['0', 'nao', 'não', 'false', 'off', 'n'];
                $low = mb_strtolower($v);
                if (in_array($low, $sim, true)) {
                    return '1';
                }
                if (in_array($low, $nao, true)) {
                    return '0';
                }
                throw new \InvalidArgumentException('Use sim ou nao');

            case 'selecao':
                if (!in_array($v, $opcoes, true)) {
                    throw new \InvalidArgumentException('Opcao nao esta na lista do campo');
                }
                return $v;

            case 'multi_selecao':
                // Chegou escalar num campo multi: trata como escolha unica.
                if (!in_array($v, $opcoes, true)) {
                    throw new \InvalidArgumentException('Opcao nao esta na lista do campo');
                }
                return json_encode([$v], JSON_UNESCAPED_UNICODE);

            case 'texto_longo':
                return mb_substr($v, 0, 20000);

            default:
                return mb_substr($v, 0, 500);
        }
    }

    /** @return string[] */
    private static function opcoes($json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $d = json_decode($json, true);
        if (!is_array($d)) {
            return [];
        }
        $out = [];
        foreach ($d as $x) {
            if (is_scalar($x)) {
                $s = trim((string) $x);
                if ($s !== '') {
                    $out[] = $s;
                }
            }
        }
        return $out;
    }

    /** @return string[] */
    private static function limparOpcoes(array $opcoes): array
    {
        $out = [];
        foreach ($opcoes as $x) {
            if (!is_scalar($x)) {
                continue;
            }
            $s = mb_substr(trim((string) $x), 0, 120);
            if ($s !== '') {
                $out[$s] = $s;
            }
        }
        return array_values(array_slice($out, 0, 60));
    }

    private static function chaveUnica(int $accountId, string $base): string
    {
        $pdo  = Database::getConnection();
        $cand = $base;
        $n    = 1;
        while (true) {
            $st = $pdo->prepare('SELECT 1 FROM crm_campos WHERE account_id = ? AND chave = ? LIMIT 1');
            $st->execute([$accountId, $cand]);
            if (!$st->fetchColumn()) {
                return $cand;
            }
            $n++;
            $cand = $base . '_' . $n;
            if ($n > 100) {
                return $base . '_' . bin2hex(random_bytes(3));
            }
        }
    }
}
