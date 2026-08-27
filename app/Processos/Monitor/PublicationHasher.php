<?php
namespace App\Processos\Monitor;

/**
 * PublicationHasher — gera hash canônico por publicação.
 *
 * Usado pra:
 *   • Anti-dup em push_today_cache e push_events (UNIQUE constraint por account_id)
 *   • Idempotência: rodar o mesmo provider 2x não duplica registros
 *
 * Estratégia: sha256 de campos discriminantes. Se o provider já tem hash
 * (DJEN tem campo "hash"), usa o externo como entrada — só rehasha pra
 * normalizar tamanho e evitar dependência de provider.
 */
final class PublicationHasher
{
    /**
     * Gera hash unificado pra uma publicação normalizada.
     *
     * Entrada deve ter pelo menos:
     *   tribunal, data_disponibilizacao, numero_processo, conteudo (ou texto), hash_externo
     */
    public static function hash(array $pub): string
    {
        $parts = [
            (string)($pub['source_id']             ?? 'djen'),
            (string)($pub['hash_externo']          ?? ''),
            (string)($pub['tribunal']              ?? ''),
            (string)($pub['data_disponibilizacao'] ?? ''),
            (string)($pub['numero_processo']       ?? ''),
            // Pega só os primeiros 500 chars pra hash estável mesmo se texto truncado depois
            mb_substr((string)($pub['conteudo'] ?? $pub['texto'] ?? ''), 0, 500),
        ];
        return hash('sha256', implode('|', $parts));
    }

    /**
     * Hash de uma combinação de filtros (pra deduplicar requests no log).
     */
    public static function filtersHash(array $filters): string
    {
        $normalized = $filters;
        ksort($normalized);
        return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE));
    }
}
