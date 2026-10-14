<?php
namespace App\Projetos;

use App\Core\Crypto;
use App\Core\Database;

/**
 * Acessos de um projeto: links, login e acesso master (pedido da Inovaize,
 * 14/10/2026). Tabela `projeto_acessos` (migration 147).
 *
 * Regras:
 *
 *  - A senha é gravada cifrada (App\Core\Crypto, AES-256-GCM com a
 *    APP_ENCRYPTION_KEY) em `senha_enc`. Nunca sai na listagem: o painel só diz
 *    se existe (`tem_senha`). Para ver, a tela pede `revelar()`, e a API audita
 *    quem revelou (projeto.acesso_revelado).
 *  - Sem a chave de criptografia configurada, salvar senha é recusado (o resto
 *    do acesso grava normalmente).
 *  - Ao editar, senha em branco mantém a guardada; `limpar_senha` apaga.
 *  - Toda leitura e escrita filtra pela lista de contas acessíveis.
 */
final class Acesso
{
    /** @return array{0:?array,1:?string} */
    public static function validar(array $in): array
    {
        $rot = trim((string) ($in['rotulo'] ?? ''));
        if ($rot === '') return [null, 'Dê um nome ao acesso (ex: Painel Master).'];
        $d = [];
        foreach (['rotulo' => 120, 'url' => 255, 'usuario' => 150, 'observacao' => 2000] as $c => $max) {
            $v = trim((string) ($in[$c] ?? ''));
            if (mb_strlen($v) > $max) return [null, "O campo $c passa de $max caracteres."];
            $d[$c] = $v === '' ? null : $v;
        }
        $senha = (string) ($in['senha'] ?? '');
        if (mb_strlen($senha) > 500) return [null, 'A senha passa de 500 caracteres.'];
        $d['senha'] = $senha === '' ? null : $senha;
        $d['limpar_senha'] = !empty($in['limpar_senha']);
        return [$d, null];
    }

    /** Cifra a senha; null se não veio; exceção se não há chave. */
    private static function cifrar(?string $senha): ?string
    {
        if ($senha === null) return null;
        if (!Crypto::isConfigured()) throw new \DomainException('A chave de criptografia do sistema não está configurada; a senha não foi guardada.');
        return Crypto::encrypt($senha);
    }

    public static function criar(int $projetoId, array $contas, array $d, ?int $userId): ?int
    {
        $acc = Projeto::contaDoProjeto($projetoId, $contas);
        if ($acc === null) return null;
        $pdo = Database::getConnection();
        $pdo->prepare("INSERT INTO projeto_acessos (account_id, projeto_id, rotulo, url, usuario, senha_enc, observacao, created_by)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$acc, $projetoId, $d['rotulo'], $d['url'], $d['usuario'], self::cifrar($d['senha']), $d['observacao'], $userId]);
        return (int) $pdo->lastInsertId();
    }

    public static function atualizar(int $id, array $contas, array $d): bool
    {
        $acc = Projeto::donoDa('projeto_acessos', $id, $contas);
        if ($acc === null) return false;
        $pdo = Database::getConnection();
        $pdo->prepare("UPDATE projeto_acessos SET rotulo = ?, url = ?, usuario = ?, observacao = ? WHERE id = ? AND account_id = ?")
            ->execute([$d['rotulo'], $d['url'], $d['usuario'], $d['observacao'], $id, $acc]);
        if ($d['senha'] !== null) {
            $pdo->prepare("UPDATE projeto_acessos SET senha_enc = ? WHERE id = ? AND account_id = ?")->execute([self::cifrar($d['senha']), $id, $acc]);
        } elseif ($d['limpar_senha']) {
            $pdo->prepare("UPDATE projeto_acessos SET senha_enc = NULL WHERE id = ? AND account_id = ?")->execute([$id, $acc]);
        }
        return true;
    }

    public static function excluir(int $id, array $contas): bool
    {
        $acc = Projeto::donoDa('projeto_acessos', $id, $contas);
        if ($acc === null) return false;
        Database::getConnection()->prepare("UPDATE projeto_acessos SET deleted_at = NOW() WHERE id = ? AND account_id = ?")->execute([$id, $acc]);
        return true;
    }

    /** A senha em claro, ou null (sem senha, de outra conta, ou não decifra). */
    public static function revelar(int $id, array $contas): ?string
    {
        $acc = Projeto::donoDa('projeto_acessos', $id, $contas);
        if ($acc === null) return null;
        $st = Database::getConnection()->prepare("SELECT senha_enc FROM projeto_acessos WHERE id = ? AND account_id = ?");
        $st->execute([$id, $acc]);
        $enc = $st->fetchColumn();
        if (!$enc) return null;
        try { return Crypto::decrypt((string) $enc); } catch (\Throwable $e) { error_log('[projetos] acesso ' . $id . ': ' . $e->getMessage()); return null; }
    }
}
