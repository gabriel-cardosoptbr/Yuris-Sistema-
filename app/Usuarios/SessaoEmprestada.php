<?php
namespace App\Usuarios;

/**
 * SessaoEmprestada: a sessão de UMA requisição do WhatsApp feita como o usuário
 * da mesma pessoa em outra conta (ver PessoaVinculada::emprestarSessaoSePreciso).
 *
 * É o gerenciador de sessão do PHP só daquela requisição: a leitura devolve a
 * sessão montada para o usuário da outra conta, e a gravação não faz nada. Assim
 * o endpoint abre a sessão do jeito dele (session_start, com ou sem
 * read_and_close) e enxerga a outra conta, e a sessão de verdade, no arquivo,
 * continua intacta.
 */
final class SessaoEmprestada extends \SessionHandler
{
    public function __construct(private string $dados) {}

    public function read(string $id): string|false
    {
        return $this->dados;
    }

    public function write(string $id, string $data): bool
    {
        return true; // nunca grava: a sessão de verdade não muda
    }

    public function destroy(string $id): bool
    {
        return true; // nem apaga
    }

    /**
     * A sessão no formato do session.serialize_handler em uso ("php" ou
     * "php_serialize"). null em outro formato: aí não se empresta.
     */
    public static function codificar(array $dados): ?string
    {
        $formato = (string) ini_get('session.serialize_handler');
        if ($formato === 'php_serialize') return serialize($dados);
        if ($formato !== 'php') return null;
        $s = '';
        foreach ($dados as $chave => $valor) {
            if (!is_string($chave) || $chave === '' || strpbrk($chave, '|!') !== false) return null;
            $s .= $chave . '|' . serialize($valor);
        }
        return $s;
    }
}
