<?php
namespace App\Contratos;

/**
 * CPF: formato e dígitos verificadores. Só matemática: um CPF válido aqui NÃO
 * prova que a pessoa é a titular nem que o número está regular na Receita.
 */
final class Cpf
{
    public static function digitos(string $bruto): string
    {
        return preg_replace('/\D+/', '', $bruto) ?? '';
    }

    public static function valido(string $bruto): bool
    {
        $c = self::digitos($bruto);
        if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) return false;
        for ($t = 9; $t < 11; $t++) {
            $s = 0;
            for ($i = 0; $i < $t; $i++) $s += (int) $c[$i] * (($t + 1) - $i);
            $d = ((10 * $s) % 11) % 10;
            if ((int) $c[$t] !== $d) return false;
        }
        return true;
    }

    /** "12345678909" → "***.456.789-**" (o meio, como na prática do governo). */
    public static function mascarar(string $bruto): string
    {
        $c = self::digitos($bruto);
        if (strlen($c) !== 11) return '***.***.***-**';
        return '***.' . substr($c, 3, 3) . '.' . substr($c, 6, 3) . '-**';
    }

    public static function hmac(string $bruto): string
    {
        return Cripto::hmac('cpf', self::digitos($bruto));
    }
}
