<?php
namespace App\Core;

/**
 * Um .zip escrito em fluxo (21/10/2026): cada arquivo vai para a saída enquanto
 * chega do Google, sem passar pelo disco nem pela memória. O servidor de
 * produção não tem a extensão zip do PHP e o disco é curto, por isso o formato é
 * escrito à mão: arquivos guardados sem compressão (método 0, o que mídia já
 * comprimida pede), com o "descritor de dados" depois de cada um (CRC e tamanho
 * só se sabem no fim) e nomes em UTF-8. Sem ZIP64: até 4 GB no total, e quem
 * chama limita antes.
 */
final class ZipEmFluxo
{
    /** @var callable(string): void */
    private $saida;
    private int $posicao = 0;
    private array $central = [];
    private array $nomes = [];

    public function __construct(callable $saida) { $this->saida = $saida; }

    private function escrever(string $b): void { ($this->saida)($b); $this->posicao += strlen($b); }

    private static function dataDos(int $t): array
    {
        $d = getdate($t);
        $hora = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
        $dia = ((max(1980, $d['year']) - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
        return [$hora, $dia];
    }

    /** Nome único dentro do zip ("foto.png", "foto (2).png"). */
    private function nomeLivre(string $nome): string
    {
        $nome = trim(str_replace(['\\', "\0"], ['/', ''], $nome), '/') ?: 'arquivo';
        $base = $nome; $n = 1;
        while (isset($this->nomes[mb_strtolower($nome)])) {
            $n++;
            $p = strrpos($base, '.');
            $nome = $p ? substr($base, 0, $p) . " ($n)" . substr($base, $p) : "$base ($n)";
        }
        $this->nomes[mb_strtolower($nome)] = true;
        return $nome;
    }

    /**
     * Um arquivo: $produtor recebe a função que escreve cada pedaço.
     * @param callable(callable(string): void): void $produtor
     */
    public function adicionar(string $nome, callable $produtor, ?int $quando = null): void
    {
        $nome = $this->nomeLivre($nome);
        [$hora, $dia] = self::dataDos($quando ?? time());
        $inicio = $this->posicao;
        // Cabeçalho local: bit 3 (descritor depois) + bit 11 (UTF-8), sem compressão.
        $this->escrever(pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0808, 0, $hora, $dia, 0, 0, 0, strlen($nome), 0) . $nome);
        $crc = hash_init('crc32b');
        $tam = 0;
        $produtor(function (string $pedaco) use ($crc, &$tam) { hash_update($crc, $pedaco); $tam += strlen($pedaco); $this->escrever($pedaco); });
        $c = hexdec(hash_final($crc));
        $this->escrever(pack('VVVV', 0x08074b50, $c, $tam, $tam));
        $this->central[] = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0808, 0, $hora, $dia, $c, $tam, $tam, strlen($nome), 0, 0, 0, 0, 0, $inicio) . $nome;
    }

    /** Fecha o zip (diretório central e o fim). */
    public function fechar(): void
    {
        $inicio = $this->posicao;
        foreach ($this->central as $c) $this->escrever($c);
        $tam = $this->posicao - $inicio;
        $this->escrever(pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->central), count($this->central), $tam, $inicio, 0));
    }
}
