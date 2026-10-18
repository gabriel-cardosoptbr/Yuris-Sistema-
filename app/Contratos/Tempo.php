<?php
namespace App\Contratos;

/**
 * Horários do módulo: gravados sempre em UTC (relógio do servidor), mostrados no
 * fuso do CRM (America/Sao_Paulo). Não é carimbo do tempo de terceiro: onde o
 * horário aparece para o cliente, ele é identificado como "registrado pelo servidor".
 */
final class Tempo
{
    public const FUSO = 'America/Sao_Paulo';

    public static function agora(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function agoraMs(): string
    {
        $d = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return $d->format('Y-m-d H:i:s.v');
    }

    public static function daquiA(int $segundos): string
    {
        return gmdate('Y-m-d H:i:s', time() + $segundos);
    }

    /** "2026-10-18 13:05:00" (UTC) → "2026-10-18T13:05:00Z". */
    public static function paraIso(?string $utc): ?string
    {
        if ($utc === null || $utc === '') return null;
        $d = new \DateTimeImmutable(substr($utc, 0, 19), new \DateTimeZone('UTC'));
        return $d->format('Y-m-d\TH:i:s\Z');
    }

    /** UTC → "18/10/2026 10:05" em Brasília. */
    public static function local(?string $utc, bool $segundos = false): string
    {
        if ($utc === null || $utc === '') return '';
        $d = (new \DateTimeImmutable(substr($utc, 0, 19), new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone(self::FUSO));
        return $d->format($segundos ? 'd/m/Y H:i:s' : 'd/m/Y H:i');
    }

    /** Data escolhida na tela (Brasília) → fim daquele dia, em UTC. */
    public static function fimDoDiaLocalEmUtc(string $data): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) return null;
        $d = new \DateTimeImmutable($data . ' 23:59:59', new \DateTimeZone(self::FUSO));
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function passou(?string $utc): bool
    {
        return $utc !== null && $utc !== '' && strtotime($utc . ' UTC') < time();
    }
}
