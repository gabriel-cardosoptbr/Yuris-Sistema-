<?php
namespace App\Contratos;

use App\Core\Database;

/**
 * A trilha de eventos de cada contrato: criação, disponibilização, acesso, código
 * enviado e confirmado, assinatura, recusa, expiração, cancelamento...
 *
 * Só acrescenta (gatilho no banco barra UPDATE e DELETE). Cada evento guarda o
 * hash do anterior e o próprio: hash = SHA-256(hash_anterior + JSON canônico do
 * evento). Apagar ou mexer em um evento do meio quebra a corrente, e verificar()
 * aponta onde. O primeiro elo parte do UUID do contrato.
 *
 * Para dois eventos simultâneos não lerem o mesmo "anterior", registrar() trava a
 * linha do contrato (SELECT ... FOR UPDATE) dentro da transação.
 */
final class Eventos
{
    public static function registrar(int $contratoId, string $tipo, array $dados = [], array $quem = []): void
    {
        $pdo = Database::getConnection();
        $propria = !$pdo->inTransaction();
        if ($propria) $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT account_id, uuid FROM crm_contratos WHERE id = ? FOR UPDATE');
            $st->execute([$contratoId]);
            $c = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$c) throw new \RuntimeException('Contrato não encontrado para o evento.');

            $a = $pdo->prepare('SELECT hash FROM crm_contrato_eventos WHERE contrato_id = ? ORDER BY id DESC LIMIT 1');
            $a->execute([$contratoId]);
            $anterior = (string) ($a->fetchColumn() ?: self::genese((string) $c['uuid']));

            $agora = Tempo::agoraMs();
            $evento = [
                'contrato_id'     => $contratoId,
                'versao_id'       => isset($quem['versao_id']) ? (int) $quem['versao_id'] : null,
                'participante_id' => isset($quem['participante_id']) ? (int) $quem['participante_id'] : null,
                'tipo'            => $tipo,
                'dados'           => $dados,
                'user_id'         => isset($quem['user_id']) ? (int) $quem['user_id'] : null,
                'ip'              => $quem['ip'] ?? null,
                'user_agent'      => $quem['user_agent'] ?? null,
                'criado_em'       => $agora,
            ];
            $hash = hash('sha256', $anterior . "\n" . Cripto::canonico($evento));

            $pdo->prepare(
                'INSERT INTO crm_contrato_eventos (account_id, contrato_id, versao_id, participante_id, tipo, dados_json, user_id, ip, user_agent, criado_em, hash_anterior, hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                (int) $c['account_id'], $contratoId, $evento['versao_id'], $evento['participante_id'], $tipo,
                $dados ? Cripto::canonico($dados) : null, $evento['user_id'], $evento['ip'], $evento['user_agent'],
                $agora, $anterior, $hash,
            ]);
            if ($propria) $pdo->commit();
        } catch (\Throwable $e) {
            if ($propria && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function genese(string $contratoUuid): string
    {
        return hash('sha256', 'contrato:' . $contratoUuid);
    }

    /** @return array{integra:bool, quantidade:int, hash_final:?string, quebra_em:?int} */
    public static function verificar(int $contratoId): array
    {
        $pdo = Database::getConnection();
        $st = $pdo->prepare('SELECT uuid FROM crm_contratos WHERE id = ?');
        $st->execute([$contratoId]);
        $uuid = (string) $st->fetchColumn();
        $ev = $pdo->prepare('SELECT * FROM crm_contrato_eventos WHERE contrato_id = ? ORDER BY id');
        $ev->execute([$contratoId]);
        $anterior = self::genese($uuid);
        $n = 0;
        foreach ($ev->fetchAll(\PDO::FETCH_ASSOC) as $e) {
            $n++;
            $evento = [
                'contrato_id'     => (int) $e['contrato_id'],
                'versao_id'       => $e['versao_id'] !== null ? (int) $e['versao_id'] : null,
                'participante_id' => $e['participante_id'] !== null ? (int) $e['participante_id'] : null,
                'tipo'            => (string) $e['tipo'],
                'dados'           => $e['dados_json'] ? json_decode((string) $e['dados_json'], true) : [],
                'user_id'         => $e['user_id'] !== null ? (int) $e['user_id'] : null,
                'ip'              => $e['ip'],
                'user_agent'      => $e['user_agent'],
                'criado_em'       => self::ms((string) $e['criado_em']),
            ];
            $calc = hash('sha256', $anterior . "\n" . Cripto::canonico($evento));
            if (!hash_equals((string) $e['hash_anterior'], $anterior) || !hash_equals((string) $e['hash'], $calc)) {
                return ['integra' => false, 'quantidade' => $n, 'hash_final' => null, 'quebra_em' => (int) $e['id']];
            }
            $anterior = (string) $e['hash'];
        }
        return ['integra' => true, 'quantidade' => $n, 'hash_final' => $n ? $anterior : null, 'quebra_em' => null];
    }

    public static function doContrato(int $contratoId): array
    {
        $st = Database::getConnection()->prepare(
            'SELECT e.id, e.tipo, e.dados_json, e.criado_em, e.participante_id, e.versao_id, e.ip, u.nome AS usuario
               FROM crm_contrato_eventos e LEFT JOIN users u ON u.id = e.user_id
              WHERE e.contrato_id = ? ORDER BY e.id'
        );
        $st->execute([$contratoId]);
        return array_map(static fn($e) => [
            'id' => (int) $e['id'], 'tipo' => (string) $e['tipo'],
            'dados' => $e['dados_json'] ? json_decode((string) $e['dados_json'], true) : [],
            'criado_em' => Tempo::paraIso((string) $e['criado_em']),
            'participante_id' => $e['participante_id'] !== null ? (int) $e['participante_id'] : null,
            'versao_id' => $e['versao_id'] !== null ? (int) $e['versao_id'] : null,
            'usuario' => $e['usuario'],
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /** O DATETIME(3) do banco volta como "Y-m-d H:i:s.vvv"; MariaDB e MySQL às vezes sem os milésimos. */
    private static function ms(string $v): string
    {
        return strlen($v) === 19 ? $v . '.000' : substr($v, 0, 23);
    }
}
