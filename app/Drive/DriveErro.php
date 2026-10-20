<?php
namespace App\Drive;

/**
 * Falha ao falar com o Google, com mensagem pronta para a tela e um motivo
 * curto (revogado, sem_acesso, nao_encontrado, instavel) para a regra decidir.
 */
final class DriveErro extends \RuntimeException
{
    public function __construct(string $mensagem, int $status = 0, public readonly string $motivo = 'erro')
    {
        parent::__construct($mensagem, $status);
    }
}
