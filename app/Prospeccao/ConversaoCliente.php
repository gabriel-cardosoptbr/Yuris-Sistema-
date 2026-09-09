<?php

namespace App\Prospeccao;

use App\Clientes\Cliente;
use App\Core\Database;

/**
 * ConversaoCliente — "Tornar cliente": a prospeccao vira (ou se liga a) um cliente.
 *
 * ---------------------------------------------------------------------------
 * O QUE ELA NAO FAZ
 * ---------------------------------------------------------------------------
 * NAO apaga a prospeccao e NAO copia historico. A prospeccao continua existindo,
 * marcada com status 'convertida' e apontando para o cliente. E o vinculo
 * `cards.cliente_id` que faz a timeline do cliente enxergar tudo que aconteceu
 * enquanto ele ainda era prospeccao (ver App\Core\Timeline).
 *
 * ---------------------------------------------------------------------------
 * DOIS CAMINHOS, UMA OPERACAO
 * ---------------------------------------------------------------------------
 *   1. CRIA um cliente novo a partir dos dados da prospeccao.
 *   2. LIGA a prospeccao a um cliente que JA EXISTE.
 *
 * O segundo caminho e o que impede "Joao 2": alguem que ja e cliente por um
 * processo trabalhista volta meses depois como prospeccao de outro servico. A
 * prospeccao nova se liga ao mesmo cliente, e a timeline dele passa a incluir
 * essa segunda jornada. Por isso `cards.cliente_id` e N-para-1, e nao 1-para-1.
 *
 * ---------------------------------------------------------------------------
 * TRANSACAO
 * ---------------------------------------------------------------------------
 * Tudo dentro de uma transacao: ou existe cliente, vinculo, status e as duas
 * linhas de historico, ou nao existe nada. Meio caminho aqui significaria
 * cliente orfao ou prospeccao marcada como convertida sem cliente do outro lado.
 *
 * ATENCAO ao mexer: Cliente::create() faz o proprio INSERT e o proprio log. Ele
 * roda DENTRO desta transacao de proposito. Se algum dia ele abrir transacao
 * propria, este arquivo precisa mudar junto.
 *
 * ---------------------------------------------------------------------------
 * MULTI-TENANT
 * ---------------------------------------------------------------------------
 * A conta vem SEMPRE do contexto de sessao, nunca do request. O cliente de
 * destino e conferido contra a mesma conta antes de qualquer escrita: vincular
 * prospeccao de um escritorio a cliente de outro seria vazamento entre contas.
 */
final class ConversaoCliente
{
    /** Status que a prospeccao passa a ter. `cards.status` e VARCHAR, nao ENUM. */
    public const STATUS_CONVERTIDA = 'convertida';

    /** Permissao de acao. Vive na mesma tabela das permissoes de pagina. */
    public const PERMISSAO = 'prospeccao.converter_cliente';

    /* ===================================================================== */
    /* leitura: o que a tela precisa saber ANTES de converter                 */
    /* ===================================================================== */

    /**
     * Diagnostico da prospeccao para a tela decidir o que oferecer.
     *
     * @param int[] $accountIds contas que a sessao alcanca
     * @return array{
     *   ok:bool, motivo:?string, card:?array,
     *   ja_convertida:bool, cliente_id:?int, cliente_nome:?string,
     *   candidatos:array
     * }
     */
    public static function previa(int $cardId, array $accountIds): array
    {
        $card = self::cardDaConta($cardId, $accountIds);
        if (!$card) {
            return self::recusa('Prospecção não encontrada nesta conta.');
        }

        $jaConvertida = !empty($card['cliente_id']);
        $clienteNome  = null;
        if ($jaConvertida) {
            $clienteNome = self::nomeDoCliente((int) $card['cliente_id'], $accountIds);
        }

        return [
            'ok'            => !$jaConvertida,
            'motivo'        => $jaConvertida ? 'Esta prospecção já foi convertida.' : null,
            'card'          => $card,
            'ja_convertida' => $jaConvertida,
            'cliente_id'    => $jaConvertida ? (int) $card['cliente_id'] : null,
            'cliente_nome'  => $clienteNome,
            'candidatos'    => $jaConvertida ? [] : self::candidatos($card, $accountIds),
        ];
    }

    /**
     * Clientes que PODEM ser a mesma pessoa. Nao decide nada: quem decide e o
     * usuario. Criar um cliente novo em silencio quando ja existe um igual e
     * como o cadastro duplicado nasce.
     *
     * Forca do indicio, em ordem: CPF/CNPJ, e-mail, telefone.
     *
     * @param int[] $accountIds
     */
    public static function candidatos(array $card, array $accountIds): array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === []) {
            return [];
        }

        $doc      = self::digitos($card['cpf_cnpj'] ?? null);
        $email    = self::minusculo($card['email'] ?? null);
        $telefone = self::digitos($card['telefone_whatsapp'] ?? null);

        if ($doc === null && $email === null && $telefone === null) {
            return [];
        }

        $pdo   = Database::getConnection();
        $inAcc = implode(',', array_fill(0, count($accountIds), '?'));

        $ondes  = [];
        $params = $accountIds;

        if ($doc !== null) {
            $ondes[] = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cl.cpf_cnpj,''),'.',''),'-',''),'/',''),' ','') = ?";
            $params[] = $doc;
        }
        if ($email !== null) {
            $ondes[]  = 'LOWER(COALESCE(cl.email,\'\')) = ?';
            $params[] = $email;
        }
        if ($telefone !== null) {
            // Compara pelos ultimos 8 digitos: o cadastro varia em DDI e mascara,
            // mas o final do numero e estavel. Menos falso negativo, e o usuario
            // confirma antes de qualquer escrita.
            $ondes[]  = "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cl.telefone,''),'(',''),')',''),'-',''),' ',''), 8) = ?";
            $params[] = substr($telefone, -8);
            $ondes[]  = "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(cl.whatsapp,''),'(',''),')',''),'-',''),' ',''), 8) = ?";
            $params[] = substr($telefone, -8);
        }

        $sql = "SELECT cl.id, cl.nome, cl.cpf_cnpj, cl.email, cl.telefone, cl.whatsapp,
                       cl.status, cl.created_at
                  FROM clientes cl
                 WHERE cl.deleted_at IS NULL
                   AND cl.account_id IN ($inAcc)
                   AND (" . implode(' OR ', $ondes) . ')
                 ORDER BY cl.id
                 LIMIT 10';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($linhas as &$l) {
            $l['motivo'] = self::motivoDoIndicio($l, $doc, $email, $telefone);
            $l['forca']  = $l['motivo'] === 'cpf_cnpj' ? 'forte' : ($l['motivo'] === 'email' ? 'media' : 'fraca');
            // Nunca devolver documento inteiro para a tela: mascarado basta para
            // a pessoa reconhecer o cadastro.
            $l['cpf_cnpj'] = self::mascara($l['cpf_cnpj']);
            $l['telefone'] = self::mascara($l['telefone']);
            $l['whatsapp'] = self::mascara($l['whatsapp']);
            $l['email']    = self::mascaraEmail($l['email']);
        }
        unset($l);

        return $linhas;
    }

    /* ===================================================================== */
    /* escrita: a conversao                                                   */
    /* ===================================================================== */

    /**
     * Converte a prospeccao. Tudo ou nada.
     *
     * @param int      $cardId
     * @param int      $accountId          conta de ESCRITA, sempre da sessao
     * @param int[]    $accountIds         contas que a sessao alcanca (leitura)
     * @param int|null $userId             quem esta convertendo
     * @param int|null $clienteExistenteId quando informado, LIGA em vez de criar
     * @param int|null $setorId            setor do cliente novo; null = primeiro do funil
     *
     * @return array{ok:bool, erro:?string, cliente_id:?int, criado:bool}
     */
    public static function converter(
        int $cardId,
        int $accountId,
        array $accountIds,
        ?int $userId = null,
        ?int $clienteExistenteId = null,
        ?int $setorId = null
    ): array {
        $pdo = Database::getConnection();

        $card = self::cardDaConta($cardId, $accountIds);
        if (!$card) {
            return ['ok' => false, 'erro' => 'Prospecção não encontrada nesta conta.', 'cliente_id' => null, 'criado' => false];
        }
        if (!empty($card['cliente_id'])) {
            return [
                'ok'         => false,
                'erro'       => 'Esta prospecção já foi convertida no cliente #' . (int) $card['cliente_id'] . '.',
                'cliente_id' => (int) $card['cliente_id'],
                'criado'     => false,
            ];
        }
        // A escrita acontece na conta do proprio card, nunca na conta "ativa" da
        // sessao: uma matriz que enxerga a filial nao pode puxar o cliente para si.
        $contaDoCard = (int) $card['account_id'];
        if ($contaDoCard <= 0) {
            return ['ok' => false, 'erro' => 'Prospecção sem conta definida.', 'cliente_id' => null, 'criado' => false];
        }

        if ($clienteExistenteId !== null) {
            $destino = self::clienteDaConta($clienteExistenteId, [$contaDoCard]);
            if (!$destino) {
                return ['ok' => false, 'erro' => 'Cliente de destino não pertence à mesma conta da prospecção.', 'cliente_id' => null, 'criado' => false];
            }
        }

        $jaEstavaEmTransacao = $pdo->inTransaction();
        if (!$jaEstavaEmTransacao) {
            $pdo->beginTransaction();
        }

        try {
            if ($clienteExistenteId !== null) {
                $clienteId = $clienteExistenteId;
                $criado    = false;
                Cliente::registrarEvento($clienteId, $contaDoCard, $userId, 'prospeccao_vinculada', [
                    'card_id' => $cardId,
                    'titulo'  => $card['cliente_nome'] ?? null,
                ]);
            } else {
                $clienteId = Cliente::create(self::dadosDoCard($card, $contaDoCard, $setorId), $userId);
                $criado    = true;

                $pdo->prepare(
                    'UPDATE clientes
                        SET card_origem_id = :card, convertido_em = NOW(), convertido_por = :uid
                      WHERE id = :id AND account_id = :acc'
                )->execute(['card' => $cardId, 'uid' => $userId, 'id' => $clienteId, 'acc' => $contaDoCard]);

                Cliente::registrarEvento($clienteId, $contaDoCard, $userId, 'convertido_de_prospeccao', [
                    'card_id' => $cardId,
                    'titulo'  => $card['cliente_nome'] ?? null,
                ]);
            }

            // O contato (a pessoa) e a costura entre os dois lados. Se a
            // prospeccao ja tinha um e o cliente ainda nao, o cliente herda.
            if (!empty($card['contato_id'])) {
                $pdo->prepare(
                    'UPDATE clientes SET contato_id = :cont
                      WHERE id = :id AND account_id = :acc AND contato_id IS NULL'
                )->execute(['cont' => (int) $card['contato_id'], 'id' => $clienteId, 'acc' => $contaDoCard]);
            }

            // ── Os PROCESSOS acompanham ──────────────────────────────────────
            // Processo aberto enquanto a pessoa era lead ficava apontando so
            // para a prospeccao, e a ficha do cliente nascia sem os casos dela:
            // o que mais importa naquele cadastro. Agora o vinculo passa a
            // apontar TAMBEM para o cliente.
            //
            // `card_id` e mantido de proposito: e o rastro de que aquele
            // processo entrou pela prospecao. Sobrescrever apagaria a origem, e
            // rastreabilidade e justamente o ponto desta funcionalidade.
            //
            // `cliente_id IS NULL` evita roubar processo ja atribuido a outro
            // cliente, no caso de a mesma prospeccao ser religada.
            $up = $pdo->prepare(
                'UPDATE processos
                    SET cliente_id = :cli
                  WHERE card_id = :card
                    AND account_id = :acc
                    AND cliente_id IS NULL'
            );
            $up->execute(['cli' => $clienteId, 'card' => $cardId, 'acc' => $contaDoCard]);
            $processosLigados = $up->rowCount();

            if ($processosLigados > 0) {
                Cliente::registrarEvento($clienteId, $contaDoCard, $userId, 'processos_vinculados', [
                    'quantidade' => $processosLigados,
                    'card_id'    => $cardId,
                ]);
                self::registrarNoCard($cardId, $userId, 'processos_vinculados', 'cliente_id', null, (string) $processosLigados);
            }

            $ok = $pdo->prepare(
                'UPDATE cards
                    SET cliente_id = :cli, convertido_em = NOW(), convertido_por = :uid,
                        status = :st, updated_at = NOW()
                  WHERE id = :id AND account_id = :acc AND cliente_id IS NULL'
            )->execute([
                'cli' => $clienteId,
                'uid' => $userId,
                'st'  => self::STATUS_CONVERTIDA,
                'id'  => $cardId,
                'acc' => $contaDoCard,
            ]);

            // rowCount 0 = alguem converteu entre a checagem e o UPDATE. A
            // condicao `cliente_id IS NULL` e a trava real contra corrida: sem
            // ela, dois cliques simultaneos criariam dois clientes.
            $st = $pdo->prepare('SELECT cliente_id FROM cards WHERE id = ? AND account_id = ?');
            $st->execute([$cardId, $contaDoCard]);
            $agora = $st->fetchColumn();
            if (!$ok || (int) $agora !== (int) $clienteId) {
                throw new \RuntimeException('A prospecção foi convertida por outra pessoa enquanto esta conversão acontecia.');
            }

            self::registrarNoCard($cardId, $userId, 'convertido_cliente', 'cliente_id', null, (string) $clienteId);

            if (!$jaEstavaEmTransacao) {
                $pdo->commit();
            }

            return ['ok' => true, 'erro' => null, 'cliente_id' => (int) $clienteId, 'criado' => $criado];
        } catch (\Throwable $e) {
            if (!$jaEstavaEmTransacao && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'erro' => $e->getMessage(), 'cliente_id' => null, 'criado' => false];
        }
    }

    /* ===================================================================== */
    /* helpers                                                                */
    /* ===================================================================== */

    /** Mapeia o cadastro da prospeccao para o do cliente. */
    private static function dadosDoCard(array $card, int $accountId, ?int $setorId): array
    {
        $doc  = self::digitos($card['cpf_cnpj'] ?? null);
        $tipo = ($doc !== null && strlen($doc) > 11) ? 'PJ' : 'PF';

        return [
            'account_id'     => $accountId,
            'setor_id'       => $setorId ?: self::primeiroSetor($accountId),
            'responsavel_id' => !empty($card['responsavel_user_id']) ? (int) $card['responsavel_user_id'] : null,
            'nome'           => $card['cliente_nome'] ?? '',
            'tipo_cliente'   => $tipo,
            'cpf_cnpj'       => $card['cpf_cnpj'] ?? null,
            'rg'             => $card['rg'] ?? null,
            'nome_mae'       => $card['nome_mae'] ?? null,
            'telefone'       => $card['telefone_whatsapp'] ?? null,
            'whatsapp'       => $card['telefone_whatsapp'] ?? null,
            'email'          => $card['email'] ?? null,
            'cep'            => $card['cep'] ?? null,
            'logradouro'     => $card['logradouro'] ?? null,
            'numero'         => $card['numero'] ?? null,
            'complemento'    => $card['complemento'] ?? null,
            'bairro'         => $card['bairro'] ?? null,
            'cidade'         => $card['cidade'] ?? null,
            'uf'             => $card['uf'] ?? null,
            'origem'         => 'prospeccao',
            'status'         => 'ativo',
            // A descricao da prospeccao e o motivo/interesse dela. Perder isso na
            // conversao seria perder por que aquela pessoa procurou o escritorio.
            'observacoes'    => self::observacoesDoCard($card),
        ];
    }

    private static function observacoesDoCard(array $card): ?string
    {
        $partes = [];
        $desc   = trim((string) ($card['descricao'] ?? ''));
        if ($desc !== '') {
            $partes[] = $desc;
        }
        if (!empty($card['empresa_nome'])) {
            $partes[] = 'Empresa: ' . $card['empresa_nome'];
        }
        foreach (['valor_estimado' => 'Valor estimado', 'valor_proposta' => 'Proposta', 'valor_fechado_final' => 'Fechado'] as $col => $rot) {
            if (isset($card[$col]) && (float) $card[$col] > 0) {
                $partes[] = $rot . ': ' . number_format((float) $card[$col], 2, ',', '.');
            }
        }
        return $partes ? implode("\n", $partes) : null;
    }

    /** Primeiro setor do funil de clientes da conta. */
    private static function primeiroSetor(int $accountId): int
    {
        $pdo = Database::getConnection();
        $st  = $pdo->prepare(
            'SELECT id FROM clientes_setores
              WHERE account_id = ? AND ativo = 1
           ORDER BY ordem, id LIMIT 1'
        );
        $st->execute([$accountId]);
        $id = $st->fetchColumn();
        if (!$id) {
            throw new \RuntimeException('A conta não tem nenhum setor de clientes configurado.');
        }
        return (int) $id;
    }

    /** @param int[] $accountIds */
    private static function cardDaConta(int $cardId, array $accountIds): ?array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || $cardId <= 0) {
            return null;
        }
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $st  = $pdo->prepare(
            "SELECT * FROM cards
              WHERE id = ? AND deleted_at IS NULL AND account_id IN ($in) LIMIT 1"
        );
        $st->execute(array_merge([$cardId], $accountIds));
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /** @param int[] $accountIds */
    private static function clienteDaConta(int $clienteId, array $accountIds): ?array
    {
        $accountIds = self::inteiros($accountIds);
        if ($accountIds === [] || $clienteId <= 0) {
            return null;
        }
        $pdo = Database::getConnection();
        $in  = implode(',', array_fill(0, count($accountIds), '?'));
        $st  = $pdo->prepare(
            "SELECT * FROM clientes
              WHERE id = ? AND deleted_at IS NULL AND account_id IN ($in) LIMIT 1"
        );
        $st->execute(array_merge([$clienteId], $accountIds));
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    private static function nomeDoCliente(int $clienteId, array $accountIds): ?string
    {
        $c = self::clienteDaConta($clienteId, $accountIds);
        return $c['nome'] ?? null;
    }

    /** Grava em card_history no mesmo formato que o resto do modulo usa. */
    private static function registrarNoCard(int $cardId, ?int $userId, string $acao, ?string $campo, ?string $de, ?string $para): void
    {
        try {
            $pdo = Database::getConnection();
            $pdo->prepare(
                'INSERT INTO card_history
                   (card_id, usuario_id, acao, campo_alterado, valor_anterior, valor_novo,
                    ip, user_agent, request_id, created_at)
                 VALUES (:card, :uid, :acao, :campo, :de, :para, :ip, :ua, :rid, NOW())'
            )->execute([
                'card'  => $cardId,
                'uid'   => $userId,
                'acao'  => $acao,
                'campo' => $campo,
                'de'    => $de,
                'para'  => $para,
                'ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua'    => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                'rid'   => \App\Core\RequestId::get(),
            ]);
        } catch (\Throwable $e) {
            // Historico nao pode derrubar a conversao, mas a conversao ja esta
            // registrada do lado do cliente, entao o rastro nao se perde.
        }
    }

    private static function motivoDoIndicio(array $cliente, ?string $doc, ?string $email, ?string $telefone): string
    {
        if ($doc !== null && self::digitos($cliente['cpf_cnpj'] ?? null) === $doc) {
            return 'cpf_cnpj';
        }
        if ($email !== null && self::minusculo($cliente['email'] ?? null) === $email) {
            return 'email';
        }
        return 'telefone';
    }

    private static function digitos($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $d = preg_replace('/\D/', '', (string) $v);
        return ($d === null || $d === '') ? null : $d;
    }

    private static function minusculo($v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = strtolower(trim((string) $v));
        return $s === '' ? null : $s;
    }

    /** Mostra so o final: o suficiente para reconhecer, sem expor o dado. */
    private static function mascara(?string $v): ?string
    {
        $d = self::digitos($v);
        if ($d === null) {
            return null;
        }
        return strlen($d) <= 4 ? str_repeat('*', strlen($d)) : str_repeat('*', strlen($d) - 4) . substr($d, -4);
    }

    private static function mascaraEmail(?string $v): ?string
    {
        $e = self::minusculo($v);
        if ($e === null || !str_contains($e, '@')) {
            return $e;
        }
        [$local, $dominio] = explode('@', $e, 2);
        $visivel = substr($local, 0, 2);
        return $visivel . str_repeat('*', max(1, strlen($local) - 2)) . '@' . $dominio;
    }

    private static function recusa(string $motivo): array
    {
        return [
            'ok' => false, 'motivo' => $motivo, 'card' => null,
            'ja_convertida' => false, 'cliente_id' => null, 'cliente_nome' => null,
            'candidatos' => [],
        ];
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
