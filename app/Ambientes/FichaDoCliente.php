<?php
namespace App\Ambientes;

use App\Core\Database;
use App\Digital\Entrega;
use App\Drive\Pasta;

/**
 * A ficha do cliente em cada ambiente (21/10/2026). O cadastro é um só; o que
 * muda é o que a ficha e o card do quadro mostram:
 *
 *   Digital    projeto: entregas, postagem (com o link e a arte do Drive),
 *              redes e briefing da marca; sem financeiro
 *   Software   o sistema do cliente: projetos ligados, demandas, perfil técnico
 *   Comercial  a venda: negociações de origem, valores, contratos
 *   Empresa    tudo
 *
 * Aqui ficam os dados novos dessa ficha: o perfil por área
 * (`crm_cliente_perfil`, migration 162), a postagem do mês, o bloco de
 * Software, as negociações e o resumo de cada card do quadro de Clientes.
 * Toda leitura é da conta passada; quadros só os que a pessoa vê.
 */
final class FichaDoCliente
{
    /** Campos do perfil de cada área, com o tamanho máximo. */
    public const CAMPOS = [
        'digital' => ['instagram' => 100, 'tiktok' => 100, 'youtube' => 255, 'facebook' => 255, 'linkedin' => 255, 'site' => 255, 'briefing' => 5000],
        'software' => ['sistema_url' => 255, 'repositorio' => 255, 'stack' => 255, 'hospedagem' => 255, 'notas_tecnicas' => 5000],
    ];
    /** Campos que guardam endereço (http/https). */
    private const LINKS = ['youtube', 'facebook', 'linkedin', 'site', 'sistema_url', 'repositorio'];

    private static function in(array $ids): string { return implode(',', array_fill(0, count($ids), '?')); }

    // ── Perfil ────────────────────────────────────────────────────────────

    public static function perfil(int $accountId, int $clienteId): array
    {
        $st = Database::getConnection()->prepare('SELECT p.*, u.nome AS por FROM crm_cliente_perfil p LEFT JOIN users u ON u.id = p.atualizado_por WHERE p.account_id = ? AND p.cliente_id = ?');
        $st->execute([$accountId, $clienteId]);
        $r = $st->fetch(\PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach (self::CAMPOS as $campos) foreach ($campos as $c => $_) $out[$c] = $r[$c] ?? null;
        $out['instagram_url'] = $out['instagram'] ? 'https://www.instagram.com/' . rawurlencode($out['instagram']) . '/' : null;
        $out['tiktok_url'] = $out['tiktok'] ? 'https://www.tiktok.com/@' . rawurlencode($out['tiktok']) : null;
        $out['atualizado_em'] = $r['atualizado_em'] ?? null;
        $out['atualizado_por'] = $r['por'] ?? null;
        return $out;
    }

    /** Usuário de rede social: aceita "@fulano", "fulano" ou o link do perfil. */
    public static function usuarioDeRede(string $v, string $rede): ?string
    {
        $v = trim($v);
        if ($v === '') return null;
        if (preg_match('~^(https?://)?(www\.|m\.)?(instagram\.com|tiktok\.com)/@?([A-Za-z0-9._]+)~i', $v, $m)) $v = $m[4];
        $v = ltrim($v, '@');
        if (!preg_match('/^[A-Za-z0-9._]{1,60}$/', $v)) throw new \InvalidArgumentException('Usuário do ' . ($rede === 'tiktok' ? 'TikTok' : 'Instagram') . ' inválido: use só o nome do perfil (ex.: @inovaize).');
        return $v;
    }

    /** Grava só os campos da área passada; os da outra área ficam como estão. */
    public static function salvarPerfil(int $accountId, int $userId, int $clienteId, string $area, array $dados): array
    {
        if (!isset(self::CAMPOS[$area])) throw new \InvalidArgumentException('Área inválida.');
        $vals = [];
        foreach (self::CAMPOS[$area] as $campo => $max) {
            if (!array_key_exists($campo, $dados)) continue;
            $v = trim((string) $dados[$campo]);
            if (in_array($campo, ['instagram', 'tiktok'], true)) $v = (string) self::usuarioDeRede($v, $campo);
            if ($v !== '' && in_array($campo, self::LINKS, true)) {
                if (!preg_match('~^https?://~i', $v)) $v = 'https://' . $v;
                if (!filter_var($v, FILTER_VALIDATE_URL) || !preg_match('~^https?://~i', $v)) throw new \InvalidArgumentException('Endereço inválido em ' . $campo . '.');
            }
            if (mb_strlen($v) > $max) throw new \InvalidArgumentException('Texto longo demais em ' . $campo . '.');
            $vals[$campo] = $v === '' ? null : $v;
        }
        if ($vals) {
            $cols = array_keys($vals);
            $sql = 'INSERT INTO crm_cliente_perfil (account_id, cliente_id, atualizado_por, ' . implode(', ', $cols) . ') VALUES (?, ?, ?, ' . self::in($cols) . ')
                    ON DUPLICATE KEY UPDATE atualizado_por = VALUES(atualizado_por), ' . implode(', ', array_map(fn($c) => "$c = VALUES($c)", $cols));
            Database::getConnection()->prepare($sql)->execute(array_merge([$accountId, $clienteId, $userId ?: null], array_values($vals)));
        }
        return self::perfil($accountId, $clienteId);
    }

    // ── Postagem (Digital) ────────────────────────────────────────────────

    /**
     * As entregas do mês do cliente, com a situação da publicação, o link do post
     * e a primeira arte do Drive ligada ao cartão (a miniatura da grade).
     * @param int[] $boardIds quadros que a pessoa vê
     */
    public static function postagem(int $accountId, int $clienteId, string $mes, array $boardIds, ?string $agora = null): array
    {
        $agora ??= date('Y-m-d H:i:s');
        $out = ['mes' => substr($mes, 0, 7), 'itens' => [], 'totais' => ['publicadas' => 0, 'agendadas' => 0, 'a_publicar' => 0, 'nao_se_aplica' => 0, 'atrasadas' => 0]];
        $boardIds = array_values(array_map('intval', $boardIds));
        if (!$boardIds) return $out;
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT e.task_id, e.link_publicacao, t.titulo, t.prazo, t.board_id FROM task_entregas_digital e JOIN tasks t ON t.id = e.task_id
                              WHERE e.account_id = ? AND e.cliente_id = ? AND e.competencia = ? AND t.status <> \'arquivada\' AND t.board_id IN (' . self::in($boardIds) . ')
                              ORDER BY COALESCE(e.publicado_em, t.prazo) IS NULL, COALESCE(e.publicado_em, t.prazo), e.task_id');
        $st->execute(array_merge([$accountId, $clienteId, substr($mes, 0, 7) . '-01'], $boardIds));
        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (!$linhas) return $out;
        $ids = array_map(fn($l) => (int) $l['task_id'], $linhas);
        // Primeira arte de cada cartão: imagem ou vídeo antes de documento.
        $arq = [];
        $sa = $pdo->prepare('SELECT task_id, file_id, nome, mime, link FROM task_drive_arquivos WHERE account_id = ? AND task_id IN (' . self::in($ids) . ')
                              ORDER BY (mime LIKE \'image/%\' OR mime LIKE \'video/%\') DESC, id');
        $sa->execute(array_merge([$accountId], $ids));
        foreach ($sa->fetchAll(\PDO::FETCH_ASSOC) as $a) {
            $arq[(int) $a['task_id']] ??= ['file_id' => $a['file_id'], 'nome' => $a['nome'], 'tipo' => Pasta::tipo((string) $a['mime']), 'link' => $a['link']];
        }
        foreach ($linhas as $l) {
            $e = Entrega::doCartao((int) $l['task_id']) ?? [];
            $atrasada = ($e['publicacao'] ?? '') !== 'publicado' && ($e['publicacao'] ?? '') !== 'nao_se_aplica' && $l['prazo'] && $l['prazo'] < $agora;
            $k = ['publicado' => 'publicadas', 'agendado' => 'agendadas', 'pendente' => 'a_publicar', 'nao_se_aplica' => 'nao_se_aplica'][$e['publicacao'] ?? ''] ?? null;
            if ($k) $out['totais'][$k]++;
            if ($atrasada) $out['totais']['atrasadas']++;
            $out['itens'][] = $e + ['titulo' => $l['titulo'], 'prazo' => $l['prazo'], 'board_id' => (int) $l['board_id'], 'link_publicacao' => $l['link_publicacao'],
                'arquivo' => $arq[(int) $l['task_id']] ?? null, 'atrasada' => $atrasada];
        }
        return $out;
    }

    /**
     * O quadro de entregas da ficha (aba Produção, no modelo do Trello): as
     * entregas do cliente com competência entre $de e $ate (AAAA-MM), nos quadros
     * que a pessoa vê, cada uma com os arquivos do Drive ligados ao cartão e se a
     * pessoa pode editar.
     * @param array $quadrosVisiveis TaskBoard::findForUser
     */
    public static function producao(int $accountId, int $clienteId, string $de, string $ate, array $quadrosVisiveis, callable $podeEditar, ?string $agora = null): array
    {
        $agora ??= date('Y-m-d H:i:s');
        $ids = array_values(array_map(fn($b) => (int) $b['id'], $quadrosVisiveis));
        if (!$ids) return [];
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT e.task_id, e.link_publicacao, t.titulo, t.prazo, t.board_id, b.nome AS quadro, u.nome AS responsavel, t.responsavel_id
                               FROM task_entregas_digital e JOIN tasks t ON t.id = e.task_id JOIN task_boards b ON b.id = t.board_id
                               LEFT JOIN users u ON u.id = t.responsavel_id
                              WHERE e.account_id = ? AND e.cliente_id = ? AND e.competencia BETWEEN ? AND ? AND t.status <> \'arquivada\' AND t.board_id IN (' . self::in($ids) . ')
                              ORDER BY e.competencia, t.prazo IS NULL, t.prazo, e.task_id');
        $st->execute(array_merge([$accountId, $clienteId, substr($de, 0, 7) . '-01', substr($ate, 0, 7) . '-01'], $ids));
        $linhas = $st->fetchAll(\PDO::FETCH_ASSOC);
        if (!$linhas) return [];
        $tids = array_map(fn($l) => (int) $l['task_id'], $linhas);
        $arq = [];
        $sa = $pdo->prepare('SELECT id, task_id, file_id, nome, mime, link FROM task_drive_arquivos WHERE account_id = ? AND task_id IN (' . self::in($tids) . ') ORDER BY (mime LIKE \'image/%\' OR mime LIKE \'video/%\') DESC, id');
        $sa->execute(array_merge([$accountId], $tids));
        foreach ($sa->fetchAll(\PDO::FETCH_ASSOC) as $a) $arq[(int) $a['task_id']][] = ['id' => (int) $a['id'], 'file_id' => $a['file_id'], 'nome' => $a['nome'], 'tipo' => Pasta::tipo((string) $a['mime']), 'link' => $a['link']];
        $editavel = [];
        return array_map(function ($l) use ($arq, $agora, $podeEditar, &$editavel) {
            $e = Entrega::doCartao((int) $l['task_id']) ?? [];
            $bid = (int) $l['board_id'];
            $editavel[$bid] ??= (bool) $podeEditar($bid);
            return $e + ['titulo' => $l['titulo'], 'prazo' => $l['prazo'], 'board_id' => $bid, 'quadro' => $l['quadro'], 'responsavel' => $l['responsavel'],
                'link_publicacao' => $l['link_publicacao'], 'arquivos' => $arq[(int) $l['task_id']] ?? [], 'pode_editar' => $editavel[$bid],
                'atrasada' => ($e['entrega'] ?? '') !== 'entregue' && $l['prazo'] && $l['prazo'] < $agora];
        }, $linhas);
    }

    /**
     * Entrega nova direto da ficha: vira um cartão no quadro de Digital do cliente
     * (o primeiro que a pessoa edita; sem nenhum, cria "Produção · Cliente").
     * @param array $quadrosVisiveis TaskBoard::findForUser
     */
    public static function novaEntrega(int $accountId, int $userId, int $clienteId, string $nomeCliente, array $d, array $quadrosVisiveis, callable $podeEditar): int
    {
        $titulo = trim((string) ($d['titulo'] ?? ''));
        if ($titulo === '') throw new \InvalidArgumentException('Dê um nome à entrega (ex.: Vídeo depoimento).');
        if (mb_strlen($titulo) > 255) throw new \InvalidArgumentException('Nome longo demais.');
        $tipo = (string) ($d['tipo'] ?? '');
        if (!isset(Entrega::TIPOS[$tipo])) throw new \InvalidArgumentException('Escolha o tipo da entrega.');
        $prazo = trim((string) ($d['prazo'] ?? ''));
        if ($prazo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prazo)) throw new \InvalidArgumentException('Prazo inválido.');
        $resp = (int) ($d['responsavel_id'] ?? 0) ?: null;
        if ($resp) {
            $st = Database::getConnection()->prepare("SELECT id FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL");
            $st->execute([$resp, $accountId]);
            if (!$st->fetchColumn()) throw new \InvalidArgumentException('Responsável de outra conta.');
        }
        $board = null;
        foreach (QuadroDaArea::filtrar($quadrosVisiveis, 'digital', $clienteId) as $b) if ($podeEditar((int) $b['id'])) { $board = (int) $b['id']; break; }
        if (!$board) {
            $board = \App\Tarefas\TaskBoard::create(['nome' => mb_substr('Produção · ' . $nomeCliente, 0, 150), 'tipo' => 'compartilhado', 'owner_id' => $userId, 'account_id' => $accountId, 'cor' => '#6366f1']);
            QuadroDaArea::definir($accountId, $board, ['area' => 'digital', 'cliente_id' => $clienteId, 'modelo' => 'etapas']);
            QuadroDaArea::semearColunas($board, 'etapas');
        }
        $col = \App\Tarefas\TaskColumn::initialColumn($board);
        $tid = \App\Tarefas\Task::create(['board_id' => $board, 'column_id' => (int) $col['id'], 'titulo' => $titulo, 'prazo' => $prazo !== '' ? $prazo . ' 18:00:00' : null,
            'criado_por_id' => $userId, 'responsavel_id' => $resp, 'prioridade' => 'media']);
        $comp = preg_match('/^\d{4}-\d{2}$/', (string) ($d['competencia'] ?? '')) ? (string) $d['competencia'] : ($prazo !== '' ? substr($prazo, 0, 7) : date('Y-m'));
        $b = \App\Tarefas\TaskBoard::findById($board, [$accountId]);
        Entrega::salvar(['task' => \App\Tarefas\Task::findById($tid), 'board' => $b], $userId, ['tipo' => $tipo, 'competencia' => $comp,
            'publicacao' => in_array($tipo, ['artigo', 'outro', 'campanha'], true) ? 'nao_se_aplica' : 'pendente']);
        return $tid;
    }

    /** Grava o link do post publicado (quem edita o cartão). */
    public static function salvarLinkPublicacao(array $cartao, int $userId, string $link): ?string
    {
        $link = trim($link);
        if ($link !== '') {
            if (!preg_match('~^https?://~i', $link)) $link = 'https://' . $link;
            if (!filter_var($link, FILTER_VALIDATE_URL) || mb_strlen($link) > 500) throw new \InvalidArgumentException('Link do post inválido.');
        }
        $tid = (int) $cartao['task']['id'];
        $st = Database::getConnection()->prepare('UPDATE task_entregas_digital SET link_publicacao = ? WHERE task_id = ? AND account_id = ?');
        $st->execute([$link === '' ? null : $link, $tid, (int) $cartao['board']['account_id']]);
        if ($st->rowCount() === 0 && !Entrega::doCartao($tid)) throw new \InvalidArgumentException('Marque o cartão como entrega antes (aba "Entrega" do cartão).');
        \App\Tarefas\Task::history($tid, $userId, 'entrega_link', null, ['link' => $link === '' ? null : $link]);
        return $link === '' ? null : $link;
    }

    // ── Software ──────────────────────────────────────────────────────────

    /**
     * O sistema do cliente: números e demandas abertas dos quadros de Software
     * dele que a pessoa vê, e as concluídas no mês.
     * @param array $quadros quadros de Software do cliente (já filtrados pela permissão)
     */
    public static function software(array $quadros, ?string $agora = null): array
    {
        $agora ??= date('Y-m-d H:i:s');
        $ids = array_values(array_map(fn($b) => (int) $b['id'], $quadros));
        $num = Visao::tarefas($ids, $agora);
        $out = ['abertas' => $num['abertas'], 'atrasadas' => $num['atrasadas'], 'sem_responsavel' => $num['sem_responsavel'], 'concluidas_mes' => 0,
                'suporte_abertas' => 0, 'proximo' => null, 'quadros' => [], 'demandas' => []];
        foreach ($quadros as $b) {
            $q = $num['por_quadro'][(int) $b['id']] ?? ['abertas' => 0, 'atrasadas' => 0, 'proximo' => null];
            $out['quadros'][] = ['id' => (int) $b['id'], 'nome' => $b['nome'], 'modelo' => $b['modelo'] ?? null] + $q;
            if (($b['modelo'] ?? '') === 'suporte') $out['suporte_abertas'] += $q['abertas'];
            if ($q['proximo'] && (!$out['proximo'] || $q['proximo'] < $out['proximo'])) $out['proximo'] = $q['proximo'];
        }
        if (!$ids) return $out;
        $pdo = Database::getConnection();
        $in = self::in($ids);
        $st = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE board_id IN ($in) AND status = 'concluida' AND concluida_em >= ?");
        $st->execute(array_merge($ids, [substr($agora, 0, 7) . '-01']));
        $out['concluidas_mes'] = (int) $st->fetchColumn();
        $st = $pdo->prepare("SELECT t.id, t.titulo, t.prazo, t.prioridade, t.board_id, col.nome AS coluna, u.nome AS responsavel
                               FROM tasks t LEFT JOIN task_columns col ON col.id = t.column_id LEFT JOIN users u ON u.id = t.responsavel_id
                              WHERE t.board_id IN ($in) AND t.status = 'ativa' AND COALESCE(col.is_coluna_concluido, 0) = 0
                              ORDER BY t.prazo IS NULL, t.prazo, FIELD(t.prioridade, 'urgente', 'alta', 'media', 'baixa'), t.id DESC LIMIT 60");
        $st->execute($ids);
        $nomes = array_column($out['quadros'], 'nome', 'id');
        $out['demandas'] = array_map(fn($t) => ['id' => (int) $t['id'], 'titulo' => $t['titulo'], 'prazo' => $t['prazo'], 'prioridade' => $t['prioridade'],
            'board_id' => (int) $t['board_id'], 'quadro' => $nomes[(int) $t['board_id']] ?? '', 'coluna' => $t['coluna'], 'responsavel' => $t['responsavel'],
            'atrasada' => $t['prazo'] && $t['prazo'] < $agora], $st->fetchAll(\PDO::FETCH_ASSOC));
        return $out;
    }

    /**
     * Projetos (pasta Projetos) ligados ao cliente e os que ainda estão sem
     * cliente, para ligar. Só quem vê Projetos chama.
     */
    public static function projetos(int $accountId, int $clienteId): array
    {
        $st = Database::getConnection()->prepare('SELECT id, slug, nome, cliente, tipo, status, url, repositorio, stack, responsavel, parent_id, cliente_id
                                                    FROM projetos WHERE account_id = ? AND (cliente_id = ? OR cliente_id IS NULL) ORDER BY parent_id IS NOT NULL, nome');
        $st->execute([$accountId, $clienteId]);
        $out = ['ligados' => [], 'livres' => []];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $p) {
            $x = ['id' => (int) $p['id'], 'slug' => $p['slug'], 'nome' => $p['nome'], 'cliente' => $p['cliente'], 'tipo' => $p['tipo'],
                  'tipo_rotulo' => \App\Projetos\Projeto::TIPOS[$p['tipo']] ?? $p['tipo'], 'status' => $p['status'],
                  'status_rotulo' => \App\Projetos\Projeto::STATUS[$p['status']] ?? $p['status'], 'url' => $p['url'], 'repositorio' => $p['repositorio'],
                  'stack' => $p['stack'], 'responsavel' => $p['responsavel'], 'subprojeto' => $p['parent_id'] !== null];
            if ((int) $p['cliente_id'] === $clienteId) $out['ligados'][] = $x; else $out['livres'][] = $x;
        }
        return $out;
    }

    public static function ligarProjeto(int $accountId, int $clienteId, int $projetoId, bool $ligar): void
    {
        $pdo = Database::getConnection();
        if ($ligar) {
            $st = $pdo->prepare('UPDATE projetos SET cliente_id = ? WHERE id = ? AND account_id = ? AND cliente_id IS NULL');
            $st->execute([$clienteId, $projetoId, $accountId]);
        } else {
            $st = $pdo->prepare('UPDATE projetos SET cliente_id = NULL WHERE id = ? AND account_id = ? AND cliente_id = ?');
            $st->execute([$projetoId, $accountId, $clienteId]);
        }
        if ($st->rowCount() === 0) throw new \InvalidArgumentException($ligar ? 'Projeto não encontrado ou já ligado a outro cliente.' : 'Projeto não está ligado a este cliente.');
    }

    // ── Comercial ─────────────────────────────────────────────────────────

    /** As prospecções que apontam para o cliente (origem, vendedor, etapa, valor). */
    public static function negociacoes(int $accountId, int $clienteId): array
    {
        $st = Database::getConnection()->prepare(
            "SELECT c.id, COALESCE(NULLIF(c.titulo, ''), NULLIF(c.empresa_nome, ''), c.cliente_nome) AS titulo, c.status, c.area_servico, c.created_at, c.data_fechamento,
                    COALESCE(NULLIF(c.valor_fechado_final, 0), NULLIF(c.valor_proposta, 0), IFNULL(c.valor_estimado, 0)) AS valor,
                    pc.nome AS etapa, u.nome AS vendedor, o.nome AS origem
               FROM cards c LEFT JOIN pipeline_columns pc ON pc.id = c.coluna_id AND pc.account_id = c.account_id
               LEFT JOIN users u ON u.id = c.responsavel_user_id LEFT JOIN clientes_origens o ON o.id = c.origem_id AND o.account_id = c.account_id
              WHERE c.account_id = ? AND c.cliente_id = ? AND c.deleted_at IS NULL ORDER BY c.created_at DESC LIMIT 30");
        $st->execute([$accountId, $clienteId]);
        $out = ['itens' => [], 'fechado' => 0.0, 'aberto' => 0.0];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
            $v = round((float) $c['valor'], 2);
            if ($c['status'] === 'fechado') $out['fechado'] += $v; elseif ($c['status'] === 'aberto') $out['aberto'] += $v;
            $out['itens'][] = ['id' => (int) $c['id'], 'titulo' => $c['titulo'], 'status' => $c['status'], 'area_servico' => $c['area_servico'], 'etapa' => $c['etapa'],
                'vendedor' => $c['vendedor'], 'origem' => $c['origem'], 'valor' => $v, 'criado_em' => $c['created_at'], 'fechado_em' => $c['data_fechamento']];
        }
        return $out;
    }

    // ── Card do quadro de Clientes ────────────────────────────────────────

    /**
     * O miolo do card de cada cliente no ambiente: entregas e postagem do mês
     * (Digital), demandas (Software) e o Instagram.
     * @param int[] $clienteIds clientes que a pessoa vê
     * @param array $quadrosVisiveis quadros que a pessoa vê (TaskBoard::findForUser)
     */
    public static function quadro(int $accountId, array $clienteIds, array $quadrosVisiveis, string $mes, ?string $agora = null): array
    {
        $agora ??= date('Y-m-d H:i:s');
        $clienteIds = array_values(array_unique(array_map('intval', $clienteIds)));
        $out = [];
        if (!$clienteIds) return $out;
        $areas = AreaDoCliente::mapa($accountId, $clienteIds);
        $res = Entrega::resumo($accountId, $mes, $clienteIds, $agora)['clientes'];
        $porCliente = [];
        foreach ($quadrosVisiveis as $b) if (!empty($b['cliente_id']) && ($b['area'] ?? null) === 'software') $porCliente[(int) $b['cliente_id']][] = (int) $b['id'];
        $todos = array_merge(...array_values($porCliente ?: [[]]));
        $num = Visao::tarefas($todos, $agora)['por_quadro'];
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT cliente_id, instagram FROM crm_cliente_perfil WHERE account_id = ? AND cliente_id IN (' . self::in($clienteIds) . ')');
        $st->execute(array_merge([$accountId], $clienteIds));
        $insta = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
        // Próxima entrega a vencer (cartão de entrega ainda não concluído).
        $st = $pdo->prepare("SELECT e.cliente_id, MIN(t.prazo) FROM task_entregas_digital e JOIN tasks t ON t.id = e.task_id
                              WHERE e.account_id = ? AND e.cliente_id IN (" . self::in($clienteIds) . ") AND t.status = 'ativa' AND e.entrega <> 'entregue' AND t.prazo >= ?
                              GROUP BY e.cliente_id");
        $st->execute(array_merge([$accountId], $clienteIds, [$agora]));
        $prox = $st->fetchAll(\PDO::FETCH_KEY_PAIR);
        foreach ($clienteIds as $c) {
            $r = $res[$c] ?? [];
            $sw = ['quadros' => 0, 'abertas' => 0, 'atrasadas' => 0, 'proximo' => null];
            foreach ($porCliente[$c] ?? [] as $bid) {
                $q = $num[$bid] ?? ['abertas' => 0, 'atrasadas' => 0, 'proximo' => null];
                $sw['quadros']++; $sw['abertas'] += $q['abertas']; $sw['atrasadas'] += $q['atrasadas'];
                if ($q['proximo'] && (!$sw['proximo'] || $q['proximo'] < $sw['proximo'])) $sw['proximo'] = $q['proximo'];
            }
            $out[$c] = [
                'areas' => $areas[$c] ?? [],
                'instagram' => $insta[$c] ?? null,
                'digital' => ['contratadas' => (int) ($r['contratadas'] ?? 0), 'cadastradas' => (int) ($r['cadastradas'] ?? 0), 'concluidas' => (int) ($r['concluidas'] ?? 0),
                    'publicadas' => (int) ($r['publicadas'] ?? 0), 'agendadas' => (int) ($r['agendadas'] ?? 0), 'atrasadas' => (int) ($r['atrasadas'] ?? 0),
                    'aprovacao' => (int) ($r['aprovacao'] ?? 0), 'esperado_ate_hoje' => (int) ($r['esperado_ate_hoje'] ?? 0), 'ritmo' => $r['ritmo'] ?? 'sem_meta',
                    'proxima' => $prox[$c] ?? null],
                'software' => $sw,
            ];
        }
        return $out;
    }
}
