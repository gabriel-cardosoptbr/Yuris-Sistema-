<?php
namespace App\Clientes;

use App\Core\Database;

/**
 * A Pessoa Física responsável por um cliente Pessoa Jurídica (T1, 10/11/2026).
 *
 * A PJ guarda só a referência (clientes.pf_responsavel_id, migration 178). A PF
 * continua com o cadastro dela, independente: editar a PF muda o que a PJ mostra,
 * e tirar o vínculo não apaga a PF.
 *
 * Quem pode ser responsável:
 *   - um cliente Pessoa Física (tipo_cliente = 'PF');
 *   - da MESMA conta da PJ (nunca de outro escritório, nem de filial);
 *   - não arquivado;
 *   - nunca o próprio registro.
 * Cliente PF não tem responsável: a coluna fica NULL.
 */
class ResponsavelPF
{
    /**
     * Confere se $pfId pode ser o responsável da PJ. Devolve a mensagem de erro,
     * ou null quando pode. $pjId é null quando a PJ ainda vai ser criada.
     */
    public static function erro(?int $pjId, int $pfId, int $accountId): ?string
    {
        if ($pfId <= 0) return 'Responsável inválido.';
        if ($pjId !== null && $pfId === $pjId) return 'O cliente não pode ser o responsável dele mesmo.';
        $st = Database::getConnection()->prepare(
            'SELECT tipo_cliente, account_id, deleted_at FROM clientes WHERE id = ? LIMIT 1'
        );
        $st->execute([$pfId]);
        $pf = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$pf || (int) $pf['account_id'] !== $accountId) return 'Responsável não encontrado neste escritório.';
        if ($pf['deleted_at'] !== null) return 'O responsável escolhido está arquivado.';
        if (($pf['tipo_cliente'] ?? 'PF') !== 'PF') return 'O responsável precisa ser Pessoa Física.';
        return null;
    }

    /**
     * PF da conta que batem com o termo, por nome ou CPF (só dígitos). Para o
     * campo de busca do cadastro da PJ. $excluirId tira o próprio registro.
     *
     * @return array<int,array{id:int,nome:string,cpf:?string}>
     */
    public static function buscar(int $accountId, string $termo, ?int $excluirId = null, int $limite = 15): array
    {
        $termo = trim($termo);
        $digitos = preg_replace('/\D+/', '', $termo);
        $where = ["account_id = ?", "deleted_at IS NULL", "tipo_cliente = 'PF'"];
        $p = [$accountId];
        if ($termo !== '') {
            if ($digitos !== '' && strlen($digitos) >= 3) {
                $where[] = '(nome LIKE ? OR cpf_cnpj LIKE ?)';
                $p[] = '%' . $termo . '%';
                $p[] = '%' . $digitos . '%';
            } else {
                $where[] = 'nome LIKE ?';
                $p[] = '%' . $termo . '%';
            }
        }
        if ($excluirId) { $where[] = 'id <> ?'; $p[] = $excluirId; }
        $limite = max(1, min(50, $limite));
        $st = Database::getConnection()->prepare(
            'SELECT id, nome, cpf_cnpj FROM clientes WHERE ' . implode(' AND ', $where)
            . ' ORDER BY nome LIMIT ' . $limite
        );
        $st->execute($p);
        return array_map(static fn ($r) => [
            'id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'cpf' => $r['cpf_cnpj'] ?: null,
        ], $st->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * A PF da conta com este CPF, para não cadastrar a mesma pessoa duas vezes.
     *
     * @return array{id:int,nome:string,cpf:string}|null
     */
    public static function porCpf(int $accountId, ?string $cpf): ?array
    {
        $cpf = preg_replace('/\D+/', '', (string) $cpf);
        if ($cpf === '') return null;
        $st = Database::getConnection()->prepare(
            "SELECT id, nome, cpf_cnpj FROM clientes
              WHERE account_id = ? AND cpf_cnpj = ? AND tipo_cliente = 'PF' AND deleted_at IS NULL
              ORDER BY id LIMIT 1"
        );
        $st->execute([$accountId, $cpf]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ? ['id' => (int) $r['id'], 'nome' => (string) $r['nome'], 'cpf' => (string) $r['cpf_cnpj']] : null;
    }

    /**
     * O valor de pf_responsavel_id que pode ser gravado, já com as regras: vazio
     * ou cliente PF vira null; PF inválida lança InvalidArgumentException.
     */
    public static function normalizar(mixed $valor, string $tipoCliente, ?int $pjId, int $accountId): ?int
    {
        if ($tipoCliente !== 'PJ') return null;
        if ($valor === null || $valor === '' || (int) $valor <= 0) return null;
        $erro = self::erro($pjId, (int) $valor, $accountId);
        if ($erro !== null) throw new \InvalidArgumentException($erro);
        return (int) $valor;
    }
}
