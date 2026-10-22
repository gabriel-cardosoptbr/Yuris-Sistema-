<?php
declare(strict_types=1);

namespace App\Notificacoes;

use App\Core\Database;

/**
 * Destinatarios: quem recebe cada aviso de tempo real da conta (22/10/2026).
 *
 * Pedido da Inovaize: "chegou um lead, dá um bling na tela, só pro Kauê e pros
 * ADM, pra Isa não", e um lugar na plataforma para escolher isso. A escolha
 * fica em `accounts.configuracoes.avisos.<evento>`:
 *
 *   { "admins": true, "usuarios": [128] }
 *
 * `admins` = todo dono e administrador ativo da conta (quem entrar como admin
 * depois já recebe); `usuarios` = as pessoas marcadas uma a uma. Sem nada
 * gravado vale o padrão do evento: na edição CRM, os administradores; na
 * edição jurídica, ninguém (o cadastro de prospecção do escritório nunca
 * avisou, e não passa a avisar sem alguém pedir).
 *
 * A tela é a aba Notificações de Configurações; a API é
 * `public/api/avisos_destinatarios.php` (só dono e admin).
 */
final class Destinatarios
{
    public const EVENTOS = [
        'lead_novo' => [
            'rotulo'    => 'Lead novo',
            'descricao' => 'Quando um lead entra na Prospecção: WhatsApp, site, campanha ou cadastro de alguém da equipe.',
        ],
    ];

    /** A escolha gravada, ou o padrão. @return array{admins:bool, usuarios:list<int>, padrao:bool} */
    public static function config(array $conta, string $evento): array
    {
        $cfg = json_decode((string) ($conta['configuracoes'] ?? ''), true);
        $c = is_array($cfg) ? ($cfg['avisos'][$evento] ?? null) : null;
        if (!is_array($c)) {
            $crm = \App\Master\Account::getProduto($conta) === 'fleetiflow';
            return ['admins' => $crm, 'usuarios' => [], 'padrao' => true];
        }
        return [
            'admins'   => !empty($c['admins']),
            'usuarios' => array_values(array_unique(array_filter(array_map('intval', (array) ($c['usuarios'] ?? [])), fn($i) => $i > 0))),
            'padrao'   => false,
        ];
    }

    /** Os ids de usuário ativos da conta que recebem o evento. @return list<int> */
    public static function usuarios(int $accountId, string $evento): array
    {
        if ($accountId <= 0 || !isset(self::EVENTOS[$evento])) return [];
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT * FROM accounts WHERE id = ? LIMIT 1');
        $st->execute([$accountId]);
        $conta = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$conta) return [];
        $c = self::config($conta, $evento);

        $ids = [];
        $st = $pdo->prepare("SELECT id, role, perfil FROM users WHERE account_id = ? AND deleted_at IS NULL AND (status IS NULL OR status = 'active')");
        $st->execute([$accountId]);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $u) {
            $admin = in_array($u['role'] ?? '', ['owner', 'admin'], true) || ($u['perfil'] ?? '') === 'admin';
            if (($c['admins'] && $admin) || in_array((int) $u['id'], $c['usuarios'], true)) $ids[] = (int) $u['id'];
        }
        return $ids;
    }

    /** Grava a escolha, preservando o resto de `configuracoes`. Só usuários da conta entram. */
    public static function salvar(int $accountId, string $evento, bool $admins, array $usuarios): array
    {
        if (!isset(self::EVENTOS[$evento])) throw new \InvalidArgumentException('evento desconhecido');
        $pdo = Database::getConnection();
        $ids = array_values(array_unique(array_filter(array_map('intval', $usuarios), fn($i) => $i > 0)));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $st = $pdo->prepare("SELECT id FROM users WHERE account_id = ? AND deleted_at IS NULL AND id IN ($in)");
            $st->execute(array_merge([$accountId], $ids));
            $ids = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
            sort($ids);
        }
        $st = $pdo->prepare('SELECT configuracoes FROM accounts WHERE id = ?');
        $st->execute([$accountId]);
        $cfg = json_decode((string) $st->fetchColumn(), true);
        if (!is_array($cfg)) $cfg = [];
        $cfg['avisos'][$evento] = ['admins' => $admins, 'usuarios' => $ids];
        $pdo->prepare('UPDATE accounts SET configuracoes = ? WHERE id = ?')->execute([json_encode($cfg, JSON_UNESCAPED_UNICODE), $accountId]);
        return ['admins' => $admins, 'usuarios' => $ids, 'padrao' => false];
    }
}
