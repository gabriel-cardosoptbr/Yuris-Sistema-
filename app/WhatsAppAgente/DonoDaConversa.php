<?php
namespace App\WhatsAppAgente;

/**
 * Dono por conversa: um número de WhatsApp atendido por mais de um vendedor
 * (Inovaize, 22/10/2026: a Isa e o Kauê no mesmo número, sem WhatsApp Web, tudo
 * pela plataforma).
 *
 * Sem o modo, o vendedor da edição CRM alcança só o número de que é dono
 * (whatsapp_instances.responsavel_user_id) e vê todas as conversas dele. Com o modo
 * ligado num número (whatsapp_instances.dono_conversa_desde, migration 164):
 *
 *   - todo vendedor ativo da conta alcança o número, mas vê e mexe só nas conversas
 *     de que é dono (whatsapp_chats.linked_user_id) e nas que estão sem dono;
 *   - conversa que ganha a primeira mensagem sem dono recebe um: mensagem de antes
 *     de dono_conversa_desde vai para dono_antigas_user_id (histórico sincronizado
 *     depois não cai com quem chegou agora), de depois vai para dono_novas_user_id;
 *   - "Assumir" (inclusive o botão que pausa o agente) passa a conversa para quem
 *     clicou e "Passar para" para outra pessoa: ela some para o anterior.
 *
 * Dono e admin veem tudo. O que roda sem sessão (webhook, workers, automações por
 * token) não é filtrado. O canal continua um só por número: nada aqui cria
 * instância, QR ou webhook. Toda consulta é escopada pelo número já autorizado.
 */
final class DonoDaConversa
{
    /** @var array<int, array{desde:string, novas:?int, antigas:?int, account_id:int}|null> */
    private static array $modo = [];

    /**
     * O modo do número, ou null quando desligado (ou antes da migration 164 rodar:
     * sem as colunas, tudo segue como antes, sem derrubar o chat).
     * @return array{desde:string, novas:?int, antigas:?int, account_id:int}|null
     */
    public static function modo(\PDO $pdo, int $instanceId): ?array
    {
        if ($instanceId <= 0) return null;
        if (array_key_exists($instanceId, self::$modo)) return self::$modo[$instanceId];
        self::$modo[$instanceId] = null;
        try {
            $st = $pdo->prepare('SELECT account_id, dono_conversa_desde, dono_novas_user_id, dono_antigas_user_id FROM whatsapp_instances WHERE id = ? LIMIT 1');
            $st->execute([$instanceId]);
            $r = $st->fetch(\PDO::FETCH_ASSOC);
            if ($r && !empty($r['dono_conversa_desde'])) {
                self::$modo[$instanceId] = [
                    'desde'      => (string) $r['dono_conversa_desde'],
                    'novas'      => $r['dono_novas_user_id'] !== null ? (int) $r['dono_novas_user_id'] : null,
                    'antigas'    => $r['dono_antigas_user_id'] !== null ? (int) $r['dono_antigas_user_id'] : null,
                    'account_id' => (int) $r['account_id'],
                ];
            }
        } catch (\Throwable $e) {
            self::$modo[$instanceId] = null; // coluna ainda não existe
        }
        return self::$modo[$instanceId];
    }

    /** Esquece o modo lido (teste e script de configuração). */
    public static function esquecer(): void { self::$modo = []; }

    /** Dos números dados, os que estão no modo dono por conversa. @param int[] $ids @return int[] */
    public static function numerosNoModo(\PDO $pdo, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        try {
            $st = $pdo->prepare('SELECT id FROM whatsapp_instances WHERE dono_conversa_desde IS NOT NULL AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
            $st->execute($ids);
            return array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN) ?: []);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** O vendedor alcança o número pelo modo (é usuário ativo da conta do número)? */
    public static function vendedorAlcanca(\PDO $pdo, int $instanceId, int $vendedor): bool
    {
        $m = self::modo($pdo, $instanceId);
        return $m !== null && self::usuarioAtivo($pdo, $m['account_id'], $vendedor);
    }

    /** Dono atual da conversa (null = sem dono). */
    public static function dono(\PDO $pdo, int $instanceId, string $jid): ?int
    {
        $st = $pdo->prepare('SELECT linked_user_id FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1');
        $st->execute([$instanceId, $jid]);
        $v = $st->fetchColumn();
        return ($v === false || $v === null) ? null : (int) $v;
    }

    /**
     * O vendedor pode ver/mexer nesta conversa? Sem vendedor (dono, admin, sistema)
     * ou número fora do modo: sim (a regra do número já valeu antes). No modo: só a
     * conversa dele ou a sem dono. Conversa que ainda não existe é livre (vai nascer
     * com dono quando a primeira mensagem passar).
     */
    public static function podeVer(\PDO $pdo, int $instanceId, string $jid, ?int $vendedor): bool
    {
        if ($vendedor === null || $jid === '' || self::modo($pdo, $instanceId) === null) return true;
        $dono = self::dono($pdo, $instanceId, $jid);
        return $dono === null || $dono === $vendedor;
    }

    /** Igual a podeVer com o vendedor da sessão; nega com o mesmo 404 de "não existe". */
    public static function exigir(\PDO $pdo, int $instanceId, string $jid): void
    {
        if (self::podeVer($pdo, $instanceId, $jid, \App\Core\AccountContext::vendedorDaSessao())) return;
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Conversa não encontrada', 'conversa_de_outro' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Pedaço de WHERE para listar só o que o vendedor pode ver neste número
     * (string vazia quando não há restrição). @return array{0:string, 1:array<int,int>}
     */
    public static function filtroSql(\PDO $pdo, int $instanceId, ?int $vendedor, string $alias = 'c'): array
    {
        if ($vendedor === null || self::modo($pdo, $instanceId) === null) return ['', []];
        return [" AND ($alias.linked_user_id = ? OR $alias.linked_user_id IS NULL)", [$vendedor]];
    }

    /**
     * A conversa recebeu uma mensagem (webhook, envio, sincronização): sem dono, ganha
     * um. Mensagem de antes do corte vai para quem fica com as antigas; de depois,
     * para quem recebe as novas. Quem já tem dono não muda.
     */
    public static function aoSalvarMensagem(\PDO $pdo, int $instanceId, string $jid, ?string $quando): void
    {
        $m = self::modo($pdo, $instanceId);
        if ($m === null || $jid === '') return;
        $quando = $quando ?: date('Y-m-d H:i:s');
        $uid = $quando < $m['desde'] ? $m['antigas'] : $m['novas'];
        if (!$uid) return;
        try {
            $pdo->prepare('UPDATE whatsapp_chats SET linked_user_id = ? WHERE instance_id = ? AND remote_jid = ? AND linked_user_id IS NULL')
                ->execute([$uid, $instanceId, $jid]);
        } catch (\Throwable $e) {
            error_log('[dono_conversa] atribuir falhou: ' . $e->getMessage());
        }
    }

    /**
     * Mensagem nova recebida (webhook, só a genuinamente nova e recente): avisa o
     * dono da conversa no sino, com "bling" e no celular (23/10/2026, pedido da
     * Inovaize: "liga o aviso para o dono da conversa"). Só no número com o modo
     * ligado, só conversa individual com dono; cada um é avisado só das suas.
     */
    public static function avisarDono(\PDO $pdo, int $instanceId, string $jid, ?string $nomeDoPayload, string $tipo, ?string $texto): void
    {
        try {
            $m = self::modo($pdo, $instanceId);
            if ($m === null || $jid === '' || str_ends_with($jid, '@g.us')) return;
            $st = $pdo->prepare('SELECT linked_user_id, contact_name, phone FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1');
            $st->execute([$instanceId, $jid]);
            $chat = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$chat || empty($chat['linked_user_id'])) return;
            $nome = trim((string) ($chat['contact_name'] ?? '')) ?: trim((string) $nomeDoPayload) ?: (string) ($chat['phone'] ?? '');
            \App\Notificacoes\Aviso::mensagemNova($m['account_id'], (int) $chat['linked_user_id'], $instanceId, $jid, $nome, self::previa($tipo, $texto));
        } catch (\Throwable $e) {
            error_log('[dono_conversa] aviso falhou: ' . $e->getMessage());
        }
    }

    /** O texto do aviso: a mensagem, ou o tipo da mídia. */
    public static function previa(string $tipo, ?string $texto): string
    {
        $texto = trim((string) $texto);
        $rotulo = ['image' => 'Foto', 'video' => 'Vídeo', 'audio' => 'Áudio', 'sticker' => 'Figurinha', 'document' => 'Documento',
                   'location' => 'Localização', 'contact' => 'Contato'][$tipo] ?? '';
        if ($rotulo === '') return $texto !== '' ? $texto : 'Mensagem nova';
        return $texto !== '' && $tipo !== 'audio' && $tipo !== 'sticker' ? $rotulo . ': ' . $texto : $rotulo;
    }

    /**
     * Passa a conversa para $paraUserId ("Assumir" é passar para si). Quem pode: dono e
     * admin (qualquer conversa do número), e o vendedor sobre a conversa que é dele
     * ou está sem dono. O destino tem de ser usuário ativo da conta do número.
     */
    public static function passar(\PDO $pdo, int $instanceId, string $jid, int $paraUserId, ?int $vendedor): void
    {
        $m = self::modo($pdo, $instanceId);
        if ($m === null) throw new \DomainException('Este número não separa as conversas por dono.');
        if (!self::usuarioAtivo($pdo, $m['account_id'], $paraUserId)) throw new \DomainException('Escolha uma pessoa da equipe.');
        $st = $pdo->prepare('SELECT id, linked_user_id FROM whatsapp_chats WHERE instance_id = ? AND remote_jid = ? LIMIT 1');
        $st->execute([$instanceId, $jid]);
        $chat = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$chat) throw new \DomainException('Conversa não encontrada.');
        $atual = $chat['linked_user_id'] !== null ? (int) $chat['linked_user_id'] : null;
        if ($vendedor !== null && $atual !== null && $atual !== $vendedor) throw new \DomainException('Conversa não encontrada.');
        $pdo->prepare('UPDATE whatsapp_chats SET linked_user_id = ? WHERE id = ?')->execute([$paraUserId, (int) $chat['id']]);
    }

    /**
     * Liga, muda ou desliga o modo no número (script de manutenção). $desde null
     * desliga. Os donos têm de ser usuários ativos da conta do número.
     */
    public static function configurar(\PDO $pdo, int $instanceId, ?string $desde, ?int $novas, ?int $antigas): void
    {
        $st = $pdo->prepare('SELECT account_id FROM whatsapp_instances WHERE id = ?');
        $st->execute([$instanceId]);
        $conta = (int) $st->fetchColumn();
        if (!$conta) throw new \DomainException('Número não encontrado.');
        foreach ([$novas, $antigas] as $u) {
            if ($u !== null && !self::usuarioAtivo($pdo, $conta, $u)) throw new \DomainException("Usuário $u não é da conta $conta.");
        }
        $pdo->prepare('UPDATE whatsapp_instances SET dono_conversa_desde = ?, dono_novas_user_id = ?, dono_antigas_user_id = ? WHERE id = ?')
            ->execute([$desde, $desde ? $novas : null, $desde ? $antigas : null, $instanceId]);
        self::esquecer();
    }

    /**
     * Distribui as conversas que ainda não têm dono: criadas antes de dono_conversa_desde
     * vão para quem fica com as antigas, as de depois para quem recebe as novas.
     * @return array{antigas:int, novas:int}
     */
    public static function distribuirSemDono(\PDO $pdo, int $instanceId, bool $aplicar): array
    {
        $m = self::modo($pdo, $instanceId);
        if ($m === null) throw new \DomainException('Ligue o modo antes de distribuir.');
        $conta = function (string $op) use ($pdo, $instanceId, $m): int {
            $st = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_chats WHERE instance_id = ? AND linked_user_id IS NULL AND created_at $op ?");
            $st->execute([$instanceId, $m['desde']]);
            return (int) $st->fetchColumn();
        };
        $r = ['antigas' => $conta('<'), 'novas' => $conta('>=')];
        if ($aplicar) {
            if ($m['antigas']) $pdo->prepare('UPDATE whatsapp_chats SET linked_user_id = ? WHERE instance_id = ? AND linked_user_id IS NULL AND created_at < ?')->execute([$m['antigas'], $instanceId, $m['desde']]);
            if ($m['novas'])   $pdo->prepare('UPDATE whatsapp_chats SET linked_user_id = ? WHERE instance_id = ? AND linked_user_id IS NULL AND created_at >= ?')->execute([$m['novas'], $instanceId, $m['desde']]);
        }
        return $r;
    }

    /** Pessoas ativas da conta do número (para "Passar para"). @return array<int, array{id:int, nome:string}> */
    public static function equipe(\PDO $pdo, int $instanceId): array
    {
        $m = self::modo($pdo, $instanceId);
        if ($m === null) return [];
        $st = $pdo->prepare("SELECT id, nome FROM users WHERE account_id = ? AND deleted_at IS NULL AND status = 'active' ORDER BY nome");
        $st->execute([$m['account_id']]);
        return array_map(fn($r) => ['id' => (int) $r['id'], 'nome' => (string) $r['nome']], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    private static function usuarioAtivo(\PDO $pdo, int $accountId, int $userId): bool
    {
        if ($userId <= 0 || $accountId <= 0) return false;
        $st = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND account_id = ? AND deleted_at IS NULL AND status = 'active'");
        $st->execute([$userId, $accountId]);
        return (bool) $st->fetchColumn();
    }
}
