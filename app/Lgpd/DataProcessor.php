<?php
namespace App\Lgpd;

/**
 * DataProcessor — inventário de terceiros (operadores) que tratam dados
 * pessoais em nome da Yuris (LGPD Art. 5 VII + Art. 33 + Art. 39).
 *
 * Fluxo típico:
 *   1. Equipe contrata novo terceiro → DataProcessor::create()
 *   2. Negocia DPA → status passa por pendente → em_negociacao → assinado
 *   3. Sistema registra cada mudança em data_processor_history (imutável)
 *   4. DPO acompanha vencimentos (dpa_validade) → renovação ou desativação
 *   5. Quando contrato termina → deactivate() preserva histórico
 *
 * As regras de transferência internacional (Art. 33) ficam no próprio registro
 * e o método exportInventory() gera o documento de inventário p/ ANPD/auditoria.
 */
final class DataProcessor
{
    public const CATEGORIAS = [
        'api_externa', 'hospedagem', 'cdn', 'gateway_pagamento', 'smtp',
        'llm_ia', 'monitoramento', 'suporte', 'analytics', 'backup', 'outro',
    ];

    public const PAPEIS = ['operador', 'suboperador', 'controlador_conjunto'];

    public const DPA_STATUSES = [
        'pendente', 'em_negociacao', 'assinado', 'vencido', 'dispensado', 'rejeitado',
    ];

    public const BASES_LEGAIS_TRANSFERENCIA = [
        'clausulas_contratuais_padrao',
        'regras_corporativas_globais',
        'decisao_anpd_adequacao',
        'autorizacao_anpd_especifica',
        'cooperacao_juridica_internacional',
        'protecao_vida',
        'cumprimento_obrigacao_legal',
        'execucao_contrato_titular',
        'consentimento_especifico',
        'garantias_outras',
        'nao_aplicavel',
    ];

    /**
     * Cria novo operador. Retorna o id criado.
     */
    public static function create(array $data, ?int $byUserId = null): int
    {
        $nome = trim((string)($data['nome'] ?? ''));
        $cat  = (string)($data['categoria'] ?? '');
        if ($nome === '') {
            throw new \InvalidArgumentException('nome obrigatório');
        }
        if (!in_array($cat, self::CATEGORIAS, true)) {
            throw new \InvalidArgumentException("Categoria inválida: $cat");
        }
        $papel = $data['papel'] ?? 'operador';
        if (!in_array($papel, self::PAPEIS, true)) $papel = 'operador';

        $blt = $data['base_legal_transferencia'] ?? null;
        if ($blt !== null && !in_array($blt, self::BASES_LEGAIS_TRANSFERENCIA, true)) {
            $blt = null;
        }

        $dadosTrat = isset($data['dados_tratados']) && is_array($data['dados_tratados'])
            ? json_encode($data['dados_tratados'], JSON_UNESCAPED_UNICODE)
            : null;

        $pdo = Database::getConnection();
        $pdo->prepare(
            "INSERT INTO data_processors
               (nome, categoria, papel, cnpj_ou_id, pais,
                dados_tratados, finalidade, retencao_terceiro,
                transferencia_internacional, base_legal_transferencia,
                dpa_status, dpa_assinado_em, dpa_validade, dpa_url,
                certificacoes, contato_dpo_terceiro, url_politica_privacidade,
                ativo, notas)
             VALUES
               (:nome, :cat, :papel, :cnpj, :pais,
                :dt, :fin, :rt,
                :ti, :blt,
                :ds, :da, :dv, :du,
                :cert, :dpo, :pp,
                :at, :nt)"
        )->execute([
            'nome'  => $nome,
            'cat'   => $cat,
            'papel' => $papel,
            'cnpj'  => $data['cnpj_ou_id'] ?? null,
            'pais'  => $data['pais']       ?? null,
            'dt'    => $dadosTrat,
            'fin'   => $data['finalidade']        ?? null,
            'rt'    => $data['retencao_terceiro'] ?? null,
            'ti'    => !empty($data['transferencia_internacional']) ? 1 : 0,
            'blt'   => $blt,
            'ds'    => $data['dpa_status']      ?? 'pendente',
            'da'    => $data['dpa_assinado_em'] ?? null,
            'dv'    => $data['dpa_validade']    ?? null,
            'du'    => $data['dpa_url']         ?? null,
            'cert'  => $data['certificacoes']   ?? null,
            'dpo'   => $data['contato_dpo_terceiro']    ?? null,
            'pp'    => $data['url_politica_privacidade'] ?? null,
            'at'    => isset($data['ativo']) ? (int)(bool)$data['ativo'] : 1,
            'nt'    => $data['notas'] ?? null,
        ]);
        $id = (int)$pdo->lastInsertId();

        self::addHistory($id, 'criado',
            "Operador '{$nome}' adicionado ao inventário (categoria={$cat}, papel={$papel})",
            ['nome' => $nome, 'categoria' => $cat, 'papel' => $papel,
             'transferencia_internacional' => !empty($data['transferencia_internacional'])],
            $byUserId);

        return $id;
    }

    /** Busca por id (decoda dados_tratados JSON). */
    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT * FROM data_processors WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$r) return null;
        if (!empty($r['dados_tratados'])) {
            $d = json_decode($r['dados_tratados'], true);
            if (is_array($d)) $r['dados_tratados'] = $d;
        }
        return $r;
    }

    /**
     * Lista para Painel Master.
     * Filtros: categoria, dpa_status, transferencia_internacional, q (busca em nome),
     *          ativo (1/0), pendentes (DPA pendente|em_negociacao), vencendo_dias (N)
     */
    public static function listForMaster(array $f = [], int $limit = 200): array
    {
        $pdo = Database::getConnection();
        $w = []; $p = [];

        if (!empty($f['categoria']) && in_array($f['categoria'], self::CATEGORIAS, true)) {
            $w[] = 'categoria = :cat'; $p['cat'] = $f['categoria'];
        }
        if (!empty($f['dpa_status']) && in_array($f['dpa_status'], self::DPA_STATUSES, true)) {
            $w[] = 'dpa_status = :ds'; $p['ds'] = $f['dpa_status'];
        }
        if (isset($f['transferencia_internacional']) && $f['transferencia_internacional'] !== '') {
            $w[] = 'transferencia_internacional = :ti';
            $p['ti'] = !empty($f['transferencia_internacional']) ? 1 : 0;
        }
        if (isset($f['ativo']) && $f['ativo'] !== '') {
            $w[] = 'ativo = :at'; $p['at'] = !empty($f['ativo']) ? 1 : 0;
        }
        if (!empty($f['q'])) {
            $w[] = 'nome LIKE :q'; $p['q'] = '%' . $f['q'] . '%';
        }
        if (!empty($f['pendentes'])) {
            $w[] = "dpa_status IN ('pendente','em_negociacao')";
        }
        if (!empty($f['vencendo_dias'])) {
            $d = max(1, min(365, (int)$f['vencendo_dias']));
            $w[] = "dpa_validade IS NOT NULL AND dpa_validade <= DATE_ADD(CURDATE(), INTERVAL $d DAY)";
        }

        $where = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
        $limit = max(1, min(500, $limit));

        $sql = "SELECT id, nome, categoria, papel, pais, transferencia_internacional,
                       base_legal_transferencia, dpa_status, dpa_assinado_em, dpa_validade,
                       ativo, contato_dpo_terceiro, created_at, updated_at
                FROM data_processors
                $where
                ORDER BY
                  CASE dpa_status WHEN 'vencido' THEN 0
                                  WHEN 'pendente' THEN 1
                                  WHEN 'em_negociacao' THEN 2
                                  WHEN 'rejeitado' THEN 3
                                  ELSE 4 END,
                  ativo DESC,
                  nome ASC
                LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute($p);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Atualiza campos permitidos. Auto-registra history em mudanças relevantes:
     *  - dpa_status (vira history dpa_status_alterado)
     *  - dpa_assinado_em (vira history dpa_assinado, se antes era NULL)
     *  - ativo (vira history desativado/reativado)
     */
    public static function update(int $id, array $data, ?int $byUserId = null): bool
    {
        $pdo = Database::getConnection();
        $old = self::findById($id);
        if (!$old) return false;

        $allowed = [
            'nome', 'categoria', 'papel', 'cnpj_ou_id', 'pais',
            'finalidade', 'retencao_terceiro',
            'transferencia_internacional', 'base_legal_transferencia',
            'dpa_status', 'dpa_assinado_em', 'dpa_validade', 'dpa_url',
            'certificacoes', 'contato_dpo_terceiro', 'url_politica_privacidade',
            'ativo', 'notas',
        ];
        $fields = []; $params = ['id' => $id];

        foreach ($allowed as $f) {
            if (!array_key_exists($f, $data)) continue;
            $v = $data[$f];

            if ($f === 'categoria'  && !in_array($v, self::CATEGORIAS, true))      continue;
            if ($f === 'papel'      && !in_array($v, self::PAPEIS, true))          continue;
            if ($f === 'dpa_status' && !in_array($v, self::DPA_STATUSES, true))    continue;
            if ($f === 'base_legal_transferencia'
                && $v !== null
                && !in_array($v, self::BASES_LEGAIS_TRANSFERENCIA, true))          continue;
            if ($f === 'transferencia_internacional') $v = !empty($v) ? 1 : 0;
            if ($f === 'ativo')                       $v = !empty($v) ? 1 : 0;

            $fields[]   = "$f = :$f";
            $params[$f] = $v;
        }
        // dados_tratados sempre serializa
        if (array_key_exists('dados_tratados', $data)) {
            $fields[] = 'dados_tratados = :dt';
            $params['dt'] = is_array($data['dados_tratados'])
                ? json_encode($data['dados_tratados'], JSON_UNESCAPED_UNICODE)
                : $data['dados_tratados'];
        }

        // Quando desativar, marca desativado_em + motivo (se vier)
        if (isset($data['ativo']) && (int)(bool)$data['ativo'] === 0 && (int)$old['ativo'] === 1) {
            $fields[] = 'desativado_em = NOW()';
            if (isset($data['motivo_desativacao'])) {
                $fields[] = 'motivo_desativacao = :mot';
                $params['mot'] = (string)$data['motivo_desativacao'];
            }
        }
        // Quando reativar, limpa desativado_em
        if (isset($data['ativo']) && (int)(bool)$data['ativo'] === 1 && (int)$old['ativo'] === 0) {
            $fields[] = 'desativado_em = NULL';
            $fields[] = 'motivo_desativacao = NULL';
        }

        if (empty($fields)) return false;
        $sql = 'UPDATE data_processors SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);

        // History
        $changes = array_intersect_key($data, array_flip($allowed));
        if (!empty($changes)) {
            self::addHistory($id, 'atualizado',
                'Operador atualizado: ' . implode(', ', array_keys($changes)),
                ['campos' => array_keys($changes)],
                $byUserId);
        }
        if (isset($data['dpa_status']) && $data['dpa_status'] !== $old['dpa_status']) {
            self::addHistory($id, 'dpa_status_alterado',
                "DPA status: {$old['dpa_status']} → {$data['dpa_status']}",
                ['from' => $old['dpa_status'], 'to' => $data['dpa_status']],
                $byUserId);
        }
        if (isset($data['dpa_assinado_em']) && $data['dpa_assinado_em']
            && empty($old['dpa_assinado_em'])) {
            self::addHistory($id, 'dpa_assinado',
                "DPA assinado em {$data['dpa_assinado_em']}" .
                (!empty($data['dpa_validade']) ? " (valido ate {$data['dpa_validade']})" : ''),
                ['data' => $data['dpa_assinado_em'], 'validade' => $data['dpa_validade'] ?? null],
                $byUserId);
        }
        if (isset($data['ativo']) && (int)(bool)$data['ativo'] !== (int)$old['ativo']) {
            $acao = !empty($data['ativo']) ? 'reativado' : 'desativado';
            self::addHistory($id, $acao,
                "Operador {$acao}" .
                (isset($data['motivo_desativacao']) ? " — motivo: {$data['motivo_desativacao']}" : ''),
                ['ativo' => (int)(bool)$data['ativo']],
                $byUserId);
        }
        return true;
    }

    /** Atalho: assina DPA (e marca status assinado). */
    public static function signDpa(int $id, string $assinadoEm, ?string $validade = null, ?string $url = null, ?int $byUserId = null): bool
    {
        return self::update($id, [
            'dpa_status'      => 'assinado',
            'dpa_assinado_em' => $assinadoEm,
            'dpa_validade'    => $validade,
            'dpa_url'         => $url,
        ], $byUserId);
    }

    /** Atalho: desativa operador (preserva history). */
    public static function deactivate(int $id, ?string $motivo = null, ?int $byUserId = null): bool
    {
        return self::update($id, [
            'ativo'               => 0,
            'motivo_desativacao'  => $motivo,
        ], $byUserId);
    }

    /** Insere history. Captura IP/UA/request_id automaticamente. */
    public static function addHistory(
        int $processorId,
        string $acao,
        ?string $descricao = null,
        ?array $payload = null,
        ?int $userId = null
    ): void {
        $pdo = Database::getConnection();
        $rid = null;
        if (class_exists('App\\Core\\RequestId')) {
            $rid = \App\Core\RequestId::get();
        }
        $pdo->prepare(
            "INSERT INTO data_processor_history
               (processor_id, acao, descricao, payload, by_user_id, ip, user_agent, request_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $processorId, $acao, $descricao,
            $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            $userId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $rid,
        ]);
    }

    /** Lista history cronológico (decoda payload JSON). */
    public static function listHistory(int $processorId): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare(
            'SELECT h.*, u.nome AS user_nome
             FROM data_processor_history h
             LEFT JOIN users u ON u.id = h.by_user_id
             WHERE h.processor_id = ?
             ORDER BY h.created_at ASC, h.id ASC'
        );
        $st->execute([$processorId]);
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            if (!empty($r['payload'])) {
                $d = json_decode($r['payload'], true);
                if (is_array($d)) $r['payload'] = $d;
            }
        }
        return $rows;
    }

    /**
     * Contagens p/ badge do master.php:
     *   total, ativos, dpa_pendente, dpa_vencido, vencendo_30d, transferencia_intl
     */
    public static function countByStatus(): array
    {
        $pdo = Database::getConnection();
        $out = [
            'total' => 0,
            'ativos' => 0,
            'dpa_pendente' => 0,
            'dpa_vencido'  => 0,
            'vencendo_30d' => 0,
            'transferencia_intl' => 0,
        ];
        $rows = $pdo->query(
            "SELECT
               COUNT(*) AS total,
               SUM(CASE WHEN ativo = 1 THEN 1 ELSE 0 END) AS ativos,
               SUM(CASE WHEN ativo = 1 AND dpa_status IN ('pendente','em_negociacao') THEN 1 ELSE 0 END) AS dpa_pendente,
               SUM(CASE WHEN ativo = 1 AND dpa_status = 'vencido' THEN 1 ELSE 0 END) AS dpa_vencido,
               SUM(CASE WHEN ativo = 1 AND dpa_validade IS NOT NULL
                              AND dpa_validade <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                              AND dpa_validade >= CURDATE()
                        THEN 1 ELSE 0 END) AS vencendo_30d,
               SUM(CASE WHEN ativo = 1 AND transferencia_internacional = 1 THEN 1 ELSE 0 END) AS transferencia_intl
             FROM data_processors"
        )->fetch(\PDO::FETCH_ASSOC);
        return array_map('intval', array_merge($out, $rows ?: []));
    }

    /**
     * Gera inventário completo (para ANPD/auditoria interna).
     * Retorna estrutura organizada por categoria + sumário.
     */
    public static function exportInventory(): array
    {
        $pdo = Database::getConnection();
        $rows = $pdo->query(
            "SELECT * FROM data_processors WHERE ativo = 1 ORDER BY categoria, nome"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $byCategoria = [];
        $totalIntl = 0;
        foreach ($rows as $r) {
            if (!empty($r['dados_tratados'])) {
                $d = json_decode($r['dados_tratados'], true);
                if (is_array($d)) $r['dados_tratados'] = $d;
            }
            $byCategoria[$r['categoria']][] = $r;
            if ((int)$r['transferencia_internacional'] === 1) $totalIntl++;
        }

        return [
            'gerado_em'         => date('Y-m-d H:i:s'),
            'total_operadores'  => count($rows),
            'transferencia_internacional' => $totalIntl,
            'por_categoria'     => $byCategoria,
            'fonte'             => 'data_processors',
            'legislacao'        => 'LGPD Art. 33 (transferência internacional) + Art. 39 (contrato com operador)',
        ];
    }
}
