<?php
namespace App\WhatsAppAgente\AiIntake;

/**
 * Taxonomy — classificacao PRELIMINAR deterministica (backend), a partir do catalogo de
 * areas e dos sinais de urgencia semeados em database/seeds/ai_intake_catalog.php.
 *
 * Estrutura configuravel (o seed e a fonte; novas areas/aliases entram la, sem mudar codigo).
 * NAO substitui o modelo: serve de apoio determinístico (FakeProvider de teste, deteccao de
 * urgencia critica server-side, e reforco da classificacao). NAO advoga, so triagem.
 */
final class Taxonomy
{
    private static ?array $seed = null;

    private static function seed(): array
    {
        if (self::$seed === null) {
            $path = __DIR__ . '/../../../database/seeds/ai_intake_catalog.php';
            self::$seed = is_file($path) ? (require $path) : ['areas' => [], 'urgency' => ['high' => [], 'critical' => []]];
        }
        return self::$seed;
    }

    /** Lista de areas do catalogo. */
    public static function areas(): array
    {
        return self::seed()['areas'] ?? [];
    }

    /** Normaliza: minusculas + remove acentos (casamento robusto de aliases). */
    public static function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $map = [
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/\s+/', ' ', $s);
        return $s;
    }

    /**
     * Classifica o texto. Retorna ['area'=>?code, 'name'=>?, 'confidence'=>float, 'secondary'=>[codes]].
     * Se $enabledCodes != null, considera apenas essas areas como candidatas validas.
     */
    public static function classify(string $text, ?array $enabledCodes = null): array
    {
        $norm = self::normalize($text);
        if ($norm === '') return ['area' => null, 'name' => null, 'confidence' => 0.0, 'secondary' => []];

        $scores = [];
        $names  = [];
        foreach (self::areas() as $a) {
            $code = $a['code'];
            if ($enabledCodes !== null && !in_array($code, $enabledCodes, true)) continue;
            $names[$code] = $a['name'];
            $hits = 0;
            foreach (($a['aliases'] ?? []) as $alias) {
                $na = self::normalize((string)$alias);
                if ($na !== '' && str_contains($norm, $na)) $hits++;
            }
            // tambem casa pelo proprio code/nome
            if (str_contains($norm, self::normalize($code))) $hits++;
            if ($hits > 0) $scores[$code] = $hits;
        }
        if (!$scores) return ['area' => null, 'name' => null, 'confidence' => 0.0, 'secondary' => []];

        arsort($scores);
        $codes = array_keys($scores);
        $best  = $codes[0];
        $topHits = $scores[$best];
        $confidence = min(0.95, 0.45 + 0.15 * $topHits);
        $secondary = array_slice($codes, 1, 2);

        return [
            'area' => $best,
            'name' => $names[$best] ?? null,
            'confidence' => round($confidence, 3),
            'secondary' => $secondary,
        ];
    }

    /**
     * Detecta urgencia. critical tem prioridade sobre high.
     * Retorna ['level'=>'normal|high|critical', 'reasons'=>[strings casadas]].
     */
    public static function detectUrgency(string $text): array
    {
        $norm = self::normalize($text);
        $u = self::seed()['urgency'] ?? ['high' => [], 'critical' => []];

        $reasons = [];
        foreach (($u['critical'] ?? []) as $kw) {
            if (str_contains($norm, self::normalize((string)$kw))) $reasons[] = $kw;
        }
        if ($reasons) return ['level' => 'critical', 'reasons' => array_values(array_unique($reasons))];

        foreach (($u['high'] ?? []) as $kw) {
            if (str_contains($norm, self::normalize((string)$kw))) $reasons[] = $kw;
        }
        if ($reasons) return ['level' => 'high', 'reasons' => array_values(array_unique($reasons))];

        return ['level' => 'normal', 'reasons' => []];
    }
}
