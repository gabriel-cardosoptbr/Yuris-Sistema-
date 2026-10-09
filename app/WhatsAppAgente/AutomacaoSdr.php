<?php
namespace App\WhatsAppAgente;

use App\Core\Database;

/**
 * AutomacaoSdr: as duas chaves da prospecção automática que roda no n8n, ligadas e
 * desligadas por botão no Chat do CRM (pedido de 09/10/2026, depois de segurar o
 * disparo da Fleet à mão):
 *
 *   disparo   a mensagem de ABERTURA para lead novo da planilha (robô de disparo)
 *   followup  a cadência de follow-up para quem recebeu a abertura e não respondeu
 *
 * O Yuris guarda a decisão e o n8n pergunta antes de cada mensagem
 * (api/whatsapp/sdr_automacao.php): desligar vale na hora, até no meio de uma
 * rodada, sem mexer no fluxo. Se o n8n não conseguir perguntar, ele não manda (o
 * fluxo trata "sem resposta" como desligado).
 *
 * Guardado em whatsapp_settings (chave/valor por conta), como a captação
 * automática: sem migration. Sem registro = ligado, que é como os fluxos rodavam
 * antes de existir o botão.
 */
final class AutomacaoSdr
{
    public const CHAVES = [
        'disparo'  => 'sdr_disparo_ligado',
        'followup' => 'sdr_followup_ligado',
    ];

    /** @return array{disparo:bool, followup:bool} */
    public static function estado(int $accountId): array
    {
        $saida = ['disparo' => true, 'followup' => true];
        if ($accountId <= 0) return ['disparo' => false, 'followup' => false];
        $st = Database::getConnection()->prepare(
            'SELECT config_key, config_value FROM whatsapp_settings WHERE account_id = ? AND config_key IN (?, ?)'
        );
        $st->execute([$accountId, self::CHAVES['disparo'], self::CHAVES['followup']]);
        $porChave = array_flip(self::CHAVES);
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $saida[$porChave[$r['config_key']]] = (string)$r['config_value'] === '1';
        }
        return $saida;
    }

    /** Liga ou desliga uma das chaves ('disparo' ou 'followup'). */
    public static function definir(int $accountId, string $qual, bool $ligado, ?int $userId = null): bool
    {
        if ($accountId <= 0 || !isset(self::CHAVES[$qual])) return false;
        $pdo = Database::getConnection();
        $chave = self::CHAVES[$qual];
        $st = $pdo->prepare('SELECT id FROM whatsapp_settings WHERE account_id = ? AND config_key = ? LIMIT 1');
        $st->execute([$accountId, $chave]);
        $id = $st->fetchColumn();
        if ($id) {
            $pdo->prepare('UPDATE whatsapp_settings SET config_value = ?, updated_at = NOW() WHERE id = ?')
                ->execute([$ligado ? '1' : '0', (int)$id]);
        } else {
            $pdo->prepare('INSERT INTO whatsapp_settings (account_id, config_key, config_value, updated_at) VALUES (?, ?, ?, NOW())')
                ->execute([$accountId, $chave, $ligado ? '1' : '0']);
        }
        \App\Master\Account::audit($accountId, 'sdr.' . $qual . ($ligado ? '.ligado' : '.desligado'), [
            'user_id' => $userId, 'entidade' => 'whatsapp_settings', 'entidade_id' => null,
            'detalhes' => ['chave' => $chave, 'ligado' => $ligado],
        ]);
        return true;
    }

    /**
     * A conta que os fluxos do n8n atendem: a dona do token global da prospecção
     * (SdrFleetiflow::contaDoTokenGlobal, a Fleetiflow). null se não houver
     * exatamente uma, e aí ninguém dispara.
     */
    public static function contaDoRobo(): ?int
    {
        $ids = [];
        foreach (Database::getConnection()->query("SELECT id FROM accounts WHERE deleted_at IS NULL AND configuracoes LIKE '%fleetiflow%'")->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            if (SdrFleetiflow::contaDoTokenGlobal((int)$id)) $ids[] = (int)$id;
        }
        return count($ids) === 1 ? $ids[0] : null;
    }
}
