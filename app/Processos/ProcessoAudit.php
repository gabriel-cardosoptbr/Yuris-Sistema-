<?php
namespace App\Processos;

use App\Core\Database;

/**
 * ProcessoAudit — auditoria server-side garantida para o histórico processual.
 *
 * Centraliza inserts em processo_history para que NENHUMA ação fique sem registro:
 * - Mesmo se o cliente bloquear/burlar JS, o servidor grava.
 * - Mesmo se a conexão cair após o PUT, o registro já foi commitado.
 * - O autor (user_email) vem da sessão, nunca do payload (não pode ser forjado).
 *
 * Uso típico:
 *   ProcessoAudit::log($processoId, 'Processo criado', 'Processo 0001234 cadastrado');
 *   ProcessoAudit::logChanges($processoId, $prev, $new);
 *
 * Tabela alvo: processo_history
 *   id INT AUTO_INCREMENT
 *   processo_id INT
 *   user_email VARCHAR(150)   ← guarda nome de exibição (legacy nome)
 *   acao VARCHAR(100)
 *   descricao TEXT
 *   created_at TIMESTAMP DEFAULT NOW()
 */
class ProcessoAudit
{
    /** Labels amigáveis (PT-BR) para cada campo trackeável do processo. */
    private const FIELD_LABELS = [
        'numero'                   => 'Número do processo',
        'cliente_nome'             => 'Cliente',
        'parte_contraria'          => 'Parte contrária',
        'cpf_cnpj_parte_contraria' => 'CPF/CNPJ da parte contrária',
        'vara_comarca'             => 'Vara/Comarca',
        'setor_id'                 => 'Setor',
        'responsavel_user_id'      => 'Responsável',
        'status'                   => 'Status',
        'data_inicio'              => 'Data de início',
        'proximo_prazo'            => 'Próximo prazo',
        'observacoes'              => 'Observações',
        'card_id'                  => 'Vínculo com cliente (card)',
        'contato_id'               => 'Contato vinculado',
    ];

    /**
     * Grava uma entrada arbitrária no histórico.
     *
     * $itemTipo ('prazo' | 'tarefa') e $itemId ligam o registro ao prazo ou à tarefa do
     * processo (migration 177), para a ficha do item listar só o que é dela. Sem a migration
     * aplicada, grava como antes (sem o item): auditoria nunca falha por isso.
     */
    public static function log(int $processoId, string $acao, string $descricao = '', ?string $itemTipo = null, ?int $itemId = null): void
    {
        if ($processoId <= 0 || $acao === '') return;
        $user = $_SESSION['user_nome']  ?? $_SESSION['user_email'] ?? 'sistema';
        // Snapshot da conta do autor — permite renderizar badge "MATRIZ"/"FILIAL"
        // no histórico mesmo se o usuário trocar de conta depois (ou for excluído).
        $aId   = isset($_SESSION['account_id'])   ? (int)$_SESSION['account_id']   : null;
        $aTipo = $_SESSION['account_tipo'] ?? null;
        $aNome = $_SESSION['account_nome'] ?? null;

        // Detecta acesso "via vínculo": se a conta autora não é a dona do processo,
        // chegou via resource_share. Prefixa a descricao com [VIA VÍNCULO] pra que
        // o histórico do dono mostre claramente quando outra conta tocou no processo.
        // Falha silenciosa: se DB não responder, segue sem o prefixo (auditoria nunca
        // bloqueia ação).
        try {
            if ($aId) {
                $pdoDono = Database::getConnection();
                $stDono = $pdoDono->prepare("SELECT account_id FROM processos WHERE id = :id LIMIT 1");
                $stDono->execute(['id' => $processoId]);
                $procOwnerAcc = (int) ($stDono->fetchColumn() ?: 0);
                if ($procOwnerAcc > 0 && $procOwnerAcc !== $aId) {
                    $descricao = '[VIA VÍNCULO] ' . $descricao;
                }
            }
        } catch (\Throwable $_e) { /* sem prefixo se falhar */ }

        try {
            // LGPD Etapa 4: ip + user_agent + request_id pra trilha forense
            if (!class_exists('App\\Core\\RequestId')) {
                require_once __DIR__ . '/../Core/RequestId.php';
            }
            $pdo = Database::getConnection();
            $dados = [
                'pid'   => $processoId,
                'user'  => $user,
                'acao'  => mb_substr($acao, 0, 100),
                'desc'  => $descricao,
                'aid'   => $aId,
                'atipo' => $aTipo,
                'anome' => $aNome,
                'ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
                'ua'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
                'rid'   => \App\Core\RequestId::get(),
            ];
            $comItem = in_array($itemTipo, ['prazo', 'tarefa'], true) && $itemId > 0 && self::temColunaDeItem($pdo);
            if ($comItem) {
                $dados['itipo'] = $itemTipo;
                $dados['iid']   = $itemId;
            }
            $pdo->prepare(
                "INSERT INTO processo_history
                   (processo_id, user_email, acao, descricao,"
                . ($comItem ? " item_tipo, item_id," : "") . "
                    author_account_id, author_account_tipo, author_account_nome,
                    ip, user_agent, request_id)
                 VALUES (:pid, :user, :acao, :desc,"
                . ($comItem ? " :itipo, :iid," : "") . " :aid, :atipo, :anome,
                         :ip, :ua, :rid)"
            )->execute($dados);
        } catch (\Throwable $e) {
            // Auditoria nunca quebra a operação principal — apenas loga em error_log
            error_log('ProcessoAudit::log error: ' . $e->getMessage());
        }

        /*
         * NOTIFICACAO: todo movimento avisa. Ver o cabecalho de
         * App\Notificacoes\Movimento: este e um dos QUATRO gravadores de
         * historico do sistema, e por isso e daqui que o aviso sai.
         *
         * Best-effort e DEPOIS do INSERT: aviso nunca derruba a operacao.
         */
        try {
            // O processo guarda o autor como TEXTO (user_email), nao como id.
            // Para a regra "ninguem e avisado do proprio ato" funcionar aqui,
            // o id vem da sessao, que e de onde o proprio $user saiu.
            $uid = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
            \App\Notificacoes\Movimento::registrar('processo', $processoId, $acao, $uid, $descricao);
        } catch (\Throwable $e) { /* silencioso */ }
    }

    /** A migration 177 já rodou neste banco? (uma consulta por requisição) */
    private static ?bool $colunaDeItem = null;
    private static function temColunaDeItem(\PDO $pdo): bool
    {
        if (self::$colunaDeItem === null) {
            try {
                self::$colunaDeItem = (bool) $pdo->query("SHOW COLUMNS FROM processo_history LIKE 'item_id'")->fetch();
            } catch (\Throwable $e) {
                self::$colunaDeItem = false;
            }
        }
        return self::$colunaDeItem;
    }

    /**
     * O histórico de UM prazo ou de UMA tarefa do processo, do mais novo ao mais antigo.
     *
     * Registro gravado desde a migration 177 vem pela ligação (item_tipo, item_id). O que
     * já existia não tem ligação e não pode ganhar (o histórico é imutável, migration 053):
     * entra pelo NOME, na leitura, sem gravar nada. O registro de prazo (ou tarefa) daquele
     * processo que cita o nome do item conta, desde que o nome mais longo citado seja o
     * dele e nenhum outro item do processo tenha o mesmo nome.
     *
     * Quem chama já conferiu o acesso ao processo (TenantGuard::assertProcessoAcessivel).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function doItem(\PDO $pdo, int $processoId, string $tipo, int $itemId): array
    {
        if (!in_array($tipo, ['prazo', 'tarefa'], true) || $processoId <= 0 || $itemId <= 0) return [];
        $tabela = $tipo === 'prazo' ? 'processo_prazos' : 'processo_tarefas';
        $campo  = $tipo === 'prazo' ? 'descricao' : 'titulo';
        $acao   = $tipo === 'prazo'
            ? "acao LIKE 'Prazo%'"
            : "acao LIKE 'Tarefa%' AND acao NOT LIKE 'Tarefa vinculada%' AND acao NOT LIKE 'Tarefa recorrente%'";

        $st = $pdo->prepare("SELECT id, {$campo} AS nome FROM {$tabela} WHERE processo_id = ?");
        $st->execute([$processoId]);
        $itens = $st->fetchAll(\PDO::FETCH_ASSOC);
        $meu = null;
        foreach ($itens as $it) if ((int) $it['id'] === $itemId) $meu = $it;
        if (!$meu) return [];

        $ligados = [];
        if (self::temColunaDeItem($pdo)) {
            $st = $pdo->prepare("SELECT * FROM processo_history WHERE processo_id = ? AND item_tipo = ? AND item_id = ?");
            $st->execute([$processoId, $tipo, $itemId]);
            $ligados = $st->fetchAll(\PDO::FETCH_ASSOC);
        }

        // Os antigos, pelo nome.
        $semItem = self::temColunaDeItem($pdo) ? ' AND item_tipo IS NULL' : '';
        $st = $pdo->prepare("SELECT * FROM processo_history WHERE processo_id = ? AND {$acao}{$semItem}");
        $st->execute([$processoId]);
        $antigos = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $h) {
            $desc = (string) $h['descricao'];
            $melhor = null; $iguais = 0;
            foreach ($itens as $it) {
                $nome = trim((string) $it['nome']);
                if ($nome === '' || mb_stripos($desc, $nome) === false) continue;
                if ($melhor === null || mb_strlen($nome) > mb_strlen(trim((string) $melhor['nome']))) { $melhor = $it; $iguais = 1; }
                elseif (mb_strlen($nome) === mb_strlen(trim((string) $melhor['nome']))) $iguais++;
            }
            if ($melhor && $iguais === 1 && (int) $melhor['id'] === $itemId) $antigos[] = $h;
        }

        $todos = array_merge($ligados, $antigos);
        usort($todos, function ($a, $b) { return [$b['created_at'], (int) $b['id']] <=> [$a['created_at'], (int) $a['id']]; });
        return $todos;
    }

    /**
     * Compara $prev vs $new e grava UMA entrada listando os campos que mudaram.
     * Se nada relevante mudou, não grava nada.
     *
     * Para `status`, gera também uma linha extra "Status: Ativo → Encerrado" pra rastrear o "de/para".
     */
    public static function logChanges(int $processoId, array $prev, array $new): void
    {
        // Separa labels em 2 grupos para EVITAR DUPLICAÇÃO:
        //  - summaryLabels: campos longos (observações, anexos, etc) — só o label
        //  - detailLines:   campos curtos importantes — label + de→para (não repete em summary)
        $summaryLabels = [];
        $detailLines   = [];
        $detailFields  = ['status','responsavel_user_id','proximo_prazo','data_inicio','setor_id','card_id','contato_id'];

        foreach (self::FIELD_LABELS as $field => $label) {
            if (!array_key_exists($field, $new)) continue;          // só compara o que foi enviado
            $a = (string)($prev[$field] ?? '');
            $b = (string)($new[$field]  ?? '');
            // Normaliza vazio: '', '0', null e 0 são equivalentes para IDs/datas/etc.
            // Antes, prev='' (NULL no DB) vs new='0' (form vazio) escapava do !== e
            // disparava uma entrada falsa "Setor:  → " com strings vazias dos dois lados.
            $aN = ($a === '' || $a === '0') ? '' : $a;
            $bN = ($b === '' || $b === '0') ? '' : $b;
            if ($aN === $bN) continue;
            // A partir daqui $aN/$bN representam o "valor real" — usa pra todo o
            // resto do bloco para evitar o falso-positivo de '' vs '0'.
            $a = $aN;
            $b = $bN;

            if (in_array($field, $detailFields, true)) {
                // Resolve IDs para NOMES legíveis (responsavel/setor/card/contato/status)
                // e monta frase NATURAL em português conforme o caso:
                //  - antes vazio, agora preenchido →  "Label definido como: NOME"
                //  - antes preenchido, agora vazio → "Label removido (era: NOME)"
                //  - antes X, agora Y              → "Label: X → Y"
                $aEmpty = ($a === '' || $a === '0');
                $bEmpty = ($b === '' || $b === '0');
                $aLabel = $aEmpty ? null : self::_humanizeValue($field, $a);
                $bLabel = $bEmpty ? null : self::_humanizeValue($field, $b);
                if ($aEmpty && !$bEmpty) {
                    $detailLines[] = "{$label} definido como: {$bLabel}";
                } elseif (!$aEmpty && $bEmpty) {
                    $detailLines[] = "{$label} removido (era: {$aLabel})";
                } else {
                    $detailLines[] = "{$label}: {$aLabel} → {$bLabel}";
                }
            } else {
                $summaryLabels[] = $label;
            }
        }
        if (empty($summaryLabels) && empty($detailLines)) return;

        // Monta descrição final SEM repetir labels: detail já contém o label
        // (ex.: "Vínculo com cliente: — → 15") e summary só lista os campos
        // longos sem de/para (ex.: "Alterações: Observações, Anexos").
        $parts = [];
        if (!empty($summaryLabels)) $parts[] = 'Alterações: ' . implode(', ', $summaryLabels);
        if (!empty($detailLines))   $parts[] = implode(' · ', $detailLines);
        $desc = implode(' | ', $parts);
        self::log($processoId, 'Processo atualizado', $desc);

        // Se houve mudança de status, grava um evento dedicado pra facilitar filtros
        if (isset($new['status']) && (string)($prev['status'] ?? '') !== (string)$new['status']) {
            $prevS = (string)($prev['status'] ?? '');
            $newS  = (string)$new['status'];
            $prevH = $prevS === '' ? null : self::_humanizeValue('status', $prevS);
            $newH  = $newS  === '' ? null : self::_humanizeValue('status', $newS);
            if ($prevH === null && $newH !== null) {
                $desc = "Status definido como: {$newH}";
            } elseif ($prevH !== null && $newH === null) {
                $desc = "Status removido (era: {$prevH})";
            } else {
                $desc = "Status: {$prevH} → {$newH}";
            }
            self::log($processoId, 'Status alterado', $desc);
        }
    }

    /**
     * Alias público — qualquer helper de auditoria pode chamar.
     */
    public static function humanizeFieldValue(string $field, string $value): string
    {
        return self::_humanizeValue($field, $value);
    }

    /**
     * Converte o valor cru do campo em algo legível para o usuário final.
     *
     * - IDs (responsavel_user_id, setor_id, card_id, contato_id, column_id,
     *   board_id, criado_por_id, etc.) → consulta o nome correspondente no
     *   banco e devolve "Nome" ou "#id" como fallback.
     * - Status → label PT-BR ("ativo" → "Ativo").
     * - Prioridade de tarefa → "baixa" → "Baixa", etc.
     * - Datas YYYY-MM-DD → dd/mm/aaaa.
     * - Vazio/null → "—".
     *
     * Falhas silenciosas: se a consulta no banco falhar, devolve "#id" pra
     * sinalizar que é um identificador técnico.
     */
    private static function _humanizeValue(string $field, string $value): string
    {
        $v = trim($value);
        if ($v === '' || $v === '0') return '—';

        // Status (processo): capitaliza
        if ($field === 'status') {
            $map = [
                'ativo'      => 'Ativo',
                'arquivado'  => 'Arquivado',
                'encerrado'  => 'Encerrado',
                'concluido'  => 'Concluído',
                'concluído'  => 'Concluído',
                'suspenso'   => 'Suspenso',
                'aberto'     => 'Aberto',
                'fechado'    => 'Fechado',
                'perdido'    => 'Perdido',
                'ativa'      => 'Ativa',
                'concluida'  => 'Concluída',
            ];
            $low = mb_strtolower($v, 'UTF-8');
            return $map[$low] ?? ucfirst($v);
        }

        // Prioridade (tarefas)
        if ($field === 'prioridade') {
            $map = ['baixa'=>'Baixa','media'=>'Média','média'=>'Média','alta'=>'Alta','urgente'=>'Urgente'];
            $low = mb_strtolower($v, 'UTF-8');
            return $map[$low] ?? ucfirst($v);
        }

        // Datas YYYY-MM-DD → dd/mm/aaaa
        if (in_array($field, ['proximo_prazo','data_inicio','prazo','data_prevista_fechamento','data_fechamento','data_referencia','data_limite','data_tarefa'], true)) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) {
                return "{$m[3]}/{$m[2]}/{$m[1]}";
            }
            return $v;
        }

        // IDs → resolve nome legível
        $id = (int) $v;
        if ($id <= 0) return $v;
        try {
            $pdo = Database::getConnection();
            $sql = null;
            switch ($field) {
                // Usuários
                case 'responsavel_user_id':
                case 'responsavel_id':
                case 'criado_por_id':
                case 'user_id':
                case 'owner_id':
                    $sql = 'SELECT nome FROM users WHERE id = :id LIMIT 1'; break;

                // Setores / times
                case 'setor_id':
                case 'team_id':
                    $sql = 'SELECT nome FROM teams WHERE id = :id LIMIT 1'; break;

                // Cards (leads)
                case 'card_id':
                    $sql = 'SELECT COALESCE(NULLIF(cliente_nome,""), NULLIF(titulo,""), CONCAT("Card #", id)) AS nome FROM cards WHERE id = :id LIMIT 1'; break;

                // Contatos
                case 'contato_id':
                    $sql = 'SELECT COALESCE(NULLIF(nome,""), CONCAT("Contato #", id)) AS nome FROM contatos WHERE id = :id LIMIT 1'; break;

                // Processos
                case 'processo_id':
                    $sql = 'SELECT COALESCE(NULLIF(numero,""), CONCAT("Processo #", id)) AS nome FROM processos WHERE id = :id LIMIT 1'; break;

                // Quadros / colunas de tarefas
                case 'board_id':
                    $sql = 'SELECT nome FROM task_boards WHERE id = :id LIMIT 1'; break;
                case 'column_id':
                    $sql = 'SELECT nome FROM task_columns WHERE id = :id LIMIT 1'; break;

                // Coluna do pipeline (prospecção)
                case 'coluna_id':
                    $sql = 'SELECT nome FROM pipeline_columns WHERE id = :id LIMIT 1'; break;

                // Conta
                case 'account_id':
                    $sql = 'SELECT nome FROM accounts WHERE id = :id LIMIT 1'; break;
            }
            if ($sql) {
                $stmt = $pdo->prepare($sql);
                $stmt->execute(['id' => $id]);
                $nome = $stmt->fetchColumn();
                if ($nome) return (string) $nome;
            }
        } catch (\Throwable $_e) { /* fall through */ }

        // Não identificou — devolve com prefixo "#" pra deixar claro que é um id legacy
        return '#' . $id;
    }
}
