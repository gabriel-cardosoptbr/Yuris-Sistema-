<?php
namespace App\Processos\Monitor;

/**
 * ProviderInterface — contrato para providers de publicações jurídicas.
 *
 * Implementações concretas: DjenProvider, DataJudProvider.
 * O sistema chama via factory que escolhe pelo source_id.
 *
 * Toda implementação deve:
 *   • Receber filtros normalizados (array)
 *   • Retornar array de publicações no FORMATO NORMALIZADO (não provider-specific)
 *   • Lançar \RuntimeException em erro de rede/auth/timeout
 *   • Respeitar timeout passado
 */
interface ProviderInterface
{
    /** Slug identificador único do provider. Ex: 'djen', 'datajud'. */
    public function getId(): string;

    /**
     * Busca publicações com base nos filtros.
     *
     * Filtros suportados (provider pode ignorar os não suportados):
     *   - numero_oab        (string com ou sem UF, ex: "357838" ou "SP357838")
     *   - sigla_tribunal    (ex: "TJSP", "TRT2")
     *   - numero_processo   (CNJ ou só dígitos)
     *   - data_inicio       (Y-m-d)
     *   - data_fim          (Y-m-d)
     *   - nome_advogado     (busca textual)
     *
     * Opções:
     *   - limit             (int, padrão provider-specific)
     *   - timeout_seconds   (int, padrão 15)
     *
     * Retorno: ['items' => [...publicações normalizadas...], 'total' => int, 'raw_meta' => array]
     */
    public function fetchPublications(array $filters, array $opts = []): array;

    /**
     * Normaliza uma publicação raw do provider pro formato unificado:
     *
     *   [
     *     'source_id'                => 'djen',
     *     'tribunal'                 => 'TJSP',
     *     'data_disponibilizacao'    => '2026-05-22',
     *     'data_publicacao'          => null,
     *     'numero_processo'          => '10021071620258260554',
     *     'numero_processo_mascara'  => '1002107-16.2025.8.26.0554',
     *     'orgao'                    => 'Foro de Santo André - 2ª Vara Cível',
     *     'id_orgao'                 => 93400,
     *     'tipo_comunicacao'         => 'Intimação',
     *     'meio'                     => 'D',
     *     'meio_completo'            => 'Diário de Justiça Eletrônico Nacional',
     *     'classe_nome'              => 'PROCEDIMENTO COMUM CÍVEL',
     *     'classe_codigo'            => '7',
     *     'titulo'                   => 'Intimação',          // tipo + nome curto
     *     'resumo'                   => primeiros 200 chars,
     *     'conteudo'                 => texto completo,
     *     'url_origem'               => 'https://...',
     *     'numero_comunicacao'       => 59595,
     *     'hash_externo'             => 'QJDEM7aXY8GsxgAtrTVzl4gZoWe2dL',
     *     'destinatarios'            => [...],
     *     'advogados'                => [...],
     *     'payload_original'         => [array raw]
     *   ]
     */
    public function normalize(array $rawItem): array;
}
