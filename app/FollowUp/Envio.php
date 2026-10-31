<?php
namespace App\FollowUp;

use App\Core\Database;
use App\WhatsAppAgente\AutomacaoSdr;
use App\WhatsAppAgente\EvolutionApiService;
use App\WhatsAppAgente\WhatsAppChannelAccessService;
use App\WhatsAppAgente\WhatsAppInstance;
use App\WhatsAppAgente\WhatsAppMessage;

/**
 * Envio: manda os follow-ups que chegaram na hora (bin/followup_worker.php, a
 * cada minuto).
 *
 * Cuidados, na ordem em que acontecem:
 *   - a chave "Follow-up" do Chat da conta (AutomacaoSdr) desligada para tudo;
 *   - um envio por número por rodada, e de 30 a 40 minutos entre um envio e o
 *     próximo no mesmo número (o número 1 da Fleet caiu em 05/10/2026 com
 *     envio em rajada; era 5 a 10 até 30/10/2026, quando o dono pediu mais espaço);
 *   - só dentro do horário de envio da cadência, conferido também na hora de
 *     mandar: a fila que sobra no fim do expediente sai na próxima janela;
 *   - logo antes de mandar, relê a conversa e refaz a conta: se o lead
 *     respondeu um segundo antes, não manda;
 *   - a linha de followup_envios nasce `enviando` ANTES do envio, com chave
 *     única por (conversa, cadência, início do silêncio, passo): dois workers
 *     juntos, ou o worker rodando de novo, nunca mandam o mesmo passo duas vezes.
 *     Falhou, volta a tentar até 3 vezes, se aquele passo ainda for o da vez.
 */
final class Envio
{
    public const INTERVALO_MIN = 30;
    public const INTERVALO_MAX = 40;
    public const TENTATIVAS = 3;
    public const REPETE_FALHA_MIN = 20;
    public const ENVIANDO_MAX_MIN = 10;
    /** Só olha conversas com mensagem nos últimos N dias (a cadência mais longa cabe folgada). */
    public const JANELA_DIAS = 60;

    /**
     * @param callable|null $enviar fn(array $cfg, string $instancia, string $jid, string $texto): array (testes passam um falso)
     * @return array{contas:int, na_hora:int, enviadas:int, falhas:int, puladas:int}
     */
    public static function processar(?callable $enviar = null, ?int $agora = null): array
    {
        $agora = $agora ?? time();
        $enviar = $enviar ?? static fn(array $cfg, string $inst, string $jid, string $txt): array
            => (new EvolutionApiService($cfg))->sendText($inst, $jid, $txt);
        $pdo = Database::getConnection();
        $res = ['contas' => 0, 'na_hora' => 0, 'enviadas' => 0, 'falhas' => 0, 'puladas' => 0];

        // Envio que começou e não terminou (o worker morreu no meio) vira falha;
        // se a mensagem saiu, o passo seguinte já é outro e ela não se repete.
        $pdo->prepare("UPDATE followup_envios SET status = 'falhou', erro = 'O envio foi interrompido.', atualizado_em = ?
                        WHERE status = 'enviando' AND atualizado_em < ?")
            ->execute([date('Y-m-d H:i:s', $agora), date('Y-m-d H:i:s', $agora - self::ENVIANDO_MAX_MIN * 60)]);

        $contas = $pdo->query('SELECT DISTINCT account_id FROM followup_cadencias WHERE ativo = 1')->fetchAll(\PDO::FETCH_COLUMN);
        foreach ($contas as $acc) {
            $acc = (int) $acc;
            if (!AutomacaoSdr::estado($acc)['followup']) continue;
            $cads = Cadencia::daConta($acc, true);
            if (!$cads) continue;
            $res['contas']++;

            $fila = self::naHora($acc, $cads, $agora);
            $res['na_hora'] += count($fila);
            $usados = [];
            foreach ($fila as $item) {
                $inst = (int) $item['fatos']['chat']['instance_id'];
                // Fora do horário de envio da cadência, espera a próxima janela (a fila
                // que sobra às 18h não sai à noite; desde 30/10/2026, com 30 a 40 min).
                if (!self::dentroDoHorario($item['cadencia'], $agora)) continue;
                if (isset($usados[$inst]) || !self::numeroLivre($inst, $agora)) { $usados[$inst] = true; continue; }
                $usados[$inst] = true;
                $r = self::mandar($acc, $item['card_id'], $cads, $enviar, $agora);
                $res[$r]++;
            }
        }
        return $res;
    }

    /**
     * Conversas da conta cujo próximo follow-up já passou da hora, da mais
     * atrasada para a mais nova.
     *
     * @return list<array{card_id:int, fatos:array, cadencia:array, situacao:array}>
     */
    public static function naHora(int $accountId, array $cads, int $agora): array
    {
        return array_values(array_filter(self::agendados($accountId, $cads, $agora), fn($i) => $i['situacao']['previsto'] <= $agora));
    }

    /**
     * Todas as conversas da conta com follow-up agendado, pela ordem de saída.
     * Também alimenta a lista "Próximos envios" da tela.
     *
     * @return list<array{card_id:int, fatos:array, cadencia:array, situacao:array}>
     */
    public static function agendados(int $accountId, array $cads, int $agora): array
    {
        $etapas = [];
        foreach ($cads as $c) {
            if (!$c['etapas']) { $etapas = null; break; }
            $etapas = array_merge($etapas, $c['etapas']);
        }
        if ($etapas === []) return [];
        $pdo = Database::getConnection();
        $sql = 'SELECT DISTINCT c.id FROM cards c
                  JOIN whatsapp_chats wc ON wc.linked_card_id = c.id
                 WHERE c.account_id = ? AND c.deleted_at IS NULL AND wc.last_message_at >= ?';
        $p = [$accountId, date('Y-m-d H:i:s', $agora - self::JANELA_DIAS * 86400)];
        if ($etapas !== null) {
            $etapas = array_values(array_unique($etapas));
            $sql .= ' AND c.coluna_id IN (' . implode(',', array_fill(0, count($etapas), '?')) . ')';
            $p = array_merge($p, $etapas);
        }
        $st = $pdo->prepare($sql);
        $st->execute($p);
        $ids = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
        $saida = [];
        foreach (array_chunk($ids, 400) as $lote) {
            foreach (Fatos::porCards([$accountId], $lote) as $id => $f) {
                if (!$f['chat']) continue;
                [$cad, $sit] = Regra::escolher($cads, $f, $agora);
                if ($cad && $sit['estado'] === 'agendado') $saida[] = ['card_id' => $id, 'fatos' => $f, 'cadencia' => $cad, 'situacao' => $sit];
            }
        }
        usort($saida, fn($a, $b) => $a['situacao']['previsto'] <=> $b['situacao']['previsto']);
        return $saida;
    }

    /**
     * O número pode mandar outro follow-up? Espera de 30 a 40 minutos desde o
     * último, sorteada pelo id do último envio (fixa entre uma rodada e outra).
     */
    private static function numeroLivre(int $instanceId, int $agora): bool
    {
        return self::liberaEm($instanceId) <= $agora;
    }

    /** Quando o número pode mandar o próximo follow-up (0 = nunca mandou). */
    private static function liberaEm(int $instanceId): int
    {
        $st = Database::getConnection()->prepare(
            "SELECT id, atualizado_em FROM followup_envios WHERE instance_id = ? AND status IN ('enviando', 'enviado') ORDER BY atualizado_em DESC LIMIT 1"
        );
        $st->execute([$instanceId]);
        $u = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$u) return 0;
        $espera = self::INTERVALO_MIN + ((int) $u['id'] % (self::INTERVALO_MAX - self::INTERVALO_MIN + 1));
        return (int) strtotime((string) $u['atualizado_em']) + $espera * 60;
    }

    /** $agora cai dentro do horário de envio da cadência? */
    private static function dentroDoHorario(array $cad, int $agora): bool
    {
        return Regra::proximaJanela($agora, (array) ($cad['horario'] ?? [])) === $agora;
    }

    /**
     * A previsão de saída de cada follow-up agendado, contando a fila do número:
     * um por vez, 30 a 40 min entre um e outro, e só dentro do horário da
     * cadência. A data da cadência (`previsto`) é quando o lead ENTRA na fila;
     * isto diz em que ORDEM ele sai e numa FAIXA de horário (`de` com todas as
     * esperas de 30 min, `ate` com todas de 40), porque a espera é sorteada.
     *
     * Simula o que o worker faz: por número, na ordem de agendados(), cada um sai
     * no mais tarde entre a data da cadência, a vaga do número e agora, empurrado
     * para a próxima janela de horário se cair fora dela. A vaga do número depois
     * do último envio é exata (a espera dele já está sorteada).
     *
     * @param list<array{card_id:int, fatos:array, cadencia:array, situacao:array}> $agendados de agendados()
     * @param array<int,int>|null $livreEm instance_id => quando o número libera (teste injeta; null lê do banco)
     * @return array<int, array{ordem:int, de:int, ate:int, na_frente:int}> por card_id; ordem começa em 1, por número
     */
    public static function estimar(array $agendados, int $agora, ?array $livreEm = null): array
    {
        $livre = [];   // instance_id => [vaga mais cedo, vaga mais tarde]
        $ordem = [];
        $saida = [];
        foreach ($agendados as $i) {
            $inst = (int) $i['fatos']['chat']['instance_id'];
            if (!isset($livre[$inst])) {
                $l = $livreEm !== null ? (int) ($livreEm[$inst] ?? 0) : self::liberaEm($inst);
                $livre[$inst] = [$l, $l];
                $ordem[$inst] = 0;
            }
            $entra = max((int) $i['situacao']['previsto'], $agora);
            $horario = (array) ($i['cadencia']['horario'] ?? []);
            $de  = Regra::proximaJanela(max($entra, $livre[$inst][0]), $horario);
            $ate = Regra::proximaJanela(max($entra, $livre[$inst][1]), $horario);
            $ordem[$inst]++;
            // "Na frente": os do mesmo número que saem antes e seguram este (fila de verdade).
            $saida[(int) $i['card_id']] = ['ordem' => $ordem[$inst], 'de' => $de, 'ate' => $ate, 'na_frente' => $ate > $entra ? $ordem[$inst] - 1 : 0];
            $livre[$inst] = [$de + self::INTERVALO_MIN * 60, $ate + self::INTERVALO_MAX * 60];
        }
        return $saida;
    }

    /** @return string enviadas | falhas | puladas */
    private static function mandar(int $acc, int $cardId, array $cads, callable $enviar, int $agora): string
    {
        $pdo = Database::getConnection();
        // Relê agora: o lead pode ter respondido entre a lista e o envio.
        $f = Fatos::porCards([$acc], [$cardId])[$cardId] ?? null;
        if (!$f || !$f['chat']) return 'puladas';
        [$cad, $sit] = Regra::escolher($cads, $f, $agora);
        if (!$cad || $sit['estado'] !== 'agendado' || $sit['previsto'] > $agora) return 'puladas';
        if (!self::dentroDoHorario($cad, $agora)) return 'puladas';

        $chat = $f['chat'];
        // Número fora do ar: o lead espera ele voltar (não troca de número no meio da conversa).
        if (!$chat['aberto']) return 'puladas';
        $passo = (int) $sit['passo'];
        $vendedor = \App\WhatsAppAgente\VendedorDoLead::doCard($acc, $cardId);
        $texto = Regra::texto((string) $cad['passos'][$passo - 1]['texto'], ['nome' => $f['nome'], 'empresa' => $f['empresa'], 'vendedor' => $vendedor['vendedor'] ?? '']);
        if ($texto === '') return 'puladas';
        $chave = [$chat['instance_id'], $chat['remote_jid'], $cad['id'], date('Y-m-d H:i:s', (int) $sit['ciclo_desde']), $passo];
        $quando = date('Y-m-d H:i:s', $agora);

        // Pega o passo antes de mandar. Já existe: só repete se falhou e ainda dá.
        try {
            $pdo->prepare("INSERT INTO followup_envios (account_id, cadencia_id, passo, instance_id, remote_jid, card_id, ciclo_desde, status, texto, created_at, atualizado_em)
                           VALUES (?, ?, ?, ?, ?, ?, ?, 'enviando', ?, ?, ?)")
                ->execute([$acc, $cad['id'], $passo, $chat['instance_id'], $chat['remote_jid'], $cardId, $chave[3], $texto, $quando, $quando]);
            $envioId = (int) $pdo->lastInsertId();
        } catch (\PDOException $e) {
            if ((string) $e->getCode() !== '23000') throw $e;
            $st = $pdo->prepare("UPDATE followup_envios SET status = 'enviando', tentativas = tentativas + 1, texto = ?, atualizado_em = ?
                                  WHERE instance_id = ? AND remote_jid = ? AND cadencia_id = ? AND ciclo_desde = ? AND passo = ?
                                    AND status = 'falhou' AND tentativas < ? AND atualizado_em < ?");
            $st->execute(array_merge([$texto, $quando], $chave, [self::TENTATIVAS, date('Y-m-d H:i:s', $agora - self::REPETE_FALHA_MIN * 60)]));
            if ($st->rowCount() !== 1) return 'puladas';
            $st = $pdo->prepare('SELECT id FROM followup_envios WHERE instance_id = ? AND remote_jid = ? AND cadencia_id = ? AND ciclo_desde = ? AND passo = ?');
            $st->execute($chave);
            $envioId = (int) $st->fetchColumn();
        }

        $ch = WhatsAppChannelAccessService::check($pdo, $acc, (int) $chat['instance_id'], 'send');
        if (!$ch || strtolower((string) ($ch['status'] ?? '')) !== 'open') {
            self::falhou($envioId, $ch ? 'O número está desconectado.' : 'A conta não pode mandar por este número.', $agora);
            return 'falhas';
        }
        try {
            $inst = new WhatsAppInstance();
            $row = $inst->find((int) $chat['instance_id']);
            $cfg = WhatsAppInstance::aplicarCanal($inst->getSettings((int) $ch['owner_account_id']), is_array($row) ? $row : []);
            $r = $enviar($cfg, (string) $ch['instance_name'], (string) $chat['remote_jid'], $texto);
        } catch (\Throwable $e) {
            error_log('[followup] envio ' . $envioId . ': ' . $e->getMessage());
            $r = ['_error' => 'exception'];
        }
        if (!empty($r['_error']) || (int) ($r['_http'] ?? 0) >= 400) {
            error_log('[followup] falha envio ' . $envioId . ' http=' . (int) ($r['_http'] ?? 0) . ' ' . substr((string) ($r['_error'] ?? ($r['message'] ?? '')), 0, 200));
            self::falhou($envioId, 'O WhatsApp não aceitou a mensagem.', $agora);
            return 'falhas';
        }

        $wamid = $r['key']['id'] ?? ($r['id'] ?? null);
        $pdo->prepare("UPDATE followup_envios SET status = 'enviado', wamid = ?, erro = NULL, enviado_em = ?, atualizado_em = ? WHERE id = ?")
            ->execute([$wamid ? mb_substr((string) $wamid, 0, 120) : null, $quando, $quando, $envioId]);
        self::depoisDeEnviar($acc, $cardId, $cad, $passo, $sit['total'], $ch, (string) $chat['remote_jid'], $texto, $wamid, $agora);
        return 'enviadas';
    }

    /** O que acontece depois de a mensagem sair. Nada aqui desfaz o envio se falhar. */
    private static function depoisDeEnviar(int $acc, int $cardId, array $cad, int $passo, int $total, array $ch, string $jid, string $texto, $wamid, int $agora): void
    {
        $canal = (int) $ch['channel_id'];
        $passos = [
            // Grava já: o card e a próxima rodada enxergam o envio sem esperar o webhook.
            'mensagem' => function () use ($ch, $canal, $jid, $texto, $wamid, $agora) {
                (new WhatsAppMessage())->save([
                    'account_id' => (int) $ch['owner_account_id'] ?: null, 'instance_id' => $canal, 'wamid' => $wamid,
                    'remote_jid' => $jid, 'contact_name' => null, 'phone' => preg_replace('/[^0-9]/', '', explode('@', $jid)[0]),
                    'message_type' => 'text', 'message_content' => $texto, 'direction' => 'outbound', 'status' => 'sent',
                    'created_at' => date('Y-m-d H:i:s', $agora),
                ]);
                (new WhatsAppInstance())->bumpEvents($canal);
            },
            // "Ao mandar a primeira, mover o card para": só sai de etapa da própria cadência.
            'etapa' => function () use ($acc, $cardId, $cad, $passo) {
                if ($passo !== 1 || !$cad['mover_para']) return;
                $card = \App\Prospeccao\Card::find($cardId);
                if (!$card || (int) $card['account_id'] !== $acc) return;
                $atual = (int) $card['coluna_id'];
                if ($atual === $cad['mover_para'] || ($cad['etapas'] && !in_array($atual, $cad['etapas'], true))) return;
                \App\Prospeccao\Card::move($cardId, $cad['mover_para'], 0, null);
            },
            'interacao' => function () use ($acc, $cardId, $cad, $passo, $total, $texto) {
                \App\Crm\Interacao::registrar(
                    ['entidade' => 'card', 'id' => $cardId, 'account_id' => $acc, 'titulo' => ''],
                    ['tipo' => 'whatsapp', 'direcao' => 'saida', 'assunto' => "Follow-up automático {$passo} de {$total} ({$cad['nome']})",
                     'conteudo' => $texto, 'ocorrido_em' => \App\Tarefas\TaskEntrega::agoraLocal()],
                    null
                );
            },
        ];
        foreach ($passos as $nome => $p) {
            try { $p(); } catch (\Throwable $e) { error_log("[followup] depois de enviar ({$nome}) card {$cardId}: " . $e->getMessage()); }
        }
    }

    private static function falhou(int $envioId, string $motivo, int $agora): void
    {
        Database::getConnection()->prepare("UPDATE followup_envios SET status = 'falhou', erro = ?, atualizado_em = ? WHERE id = ?")
            ->execute([mb_substr($motivo, 0, 255), date('Y-m-d H:i:s', $agora), $envioId]);
    }

    /** Os últimos envios da conta, para a tela. */
    public static function historico(int $accountId, int $limite = 60): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT e.id, e.cadencia_id, e.passo, e.card_id, e.status, e.erro, e.texto, e.created_at, e.enviado_em, e.tentativas,
                    COALESCE(NULLIF(c.empresa_nome, ""), NULLIF(c.cliente_nome, ""), wc.contact_name, e.remote_jid) AS lead,
                    f.nome AS cadencia
               FROM followup_envios e
               LEFT JOIN cards c ON c.id = e.card_id AND c.account_id = e.account_id
               LEFT JOIN whatsapp_chats wc ON wc.instance_id = e.instance_id AND wc.remote_jid = e.remote_jid
               LEFT JOIN followup_cadencias f ON f.id = e.cadencia_id
              WHERE e.account_id = ?
              ORDER BY e.id DESC LIMIT ' . max(1, min(200, $limite))
        );
        $st->execute([$accountId]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }
}
