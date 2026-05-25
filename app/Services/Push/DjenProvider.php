<?php
namespace App\Services\Push;

/**
 * DjenProvider — integração com o Diário de Justiça Eletrônico Nacional.
 *
 * Endpoint público (sem auth, validado em 2026-05-24):
 *   GET https://comunicaapi.pje.jus.br/api/v1/comunicacao?<filtros>
 *
 * Retorno: { status, message, count, items: [...] }
 * Onde cada item tem campos como id, data_disponibilizacao, siglaTribunal,
 * texto, numero_processo, hash, destinatarios, destinatarioadvogados.
 */
final class DjenProvider implements ProviderInterface
{
    private string $baseUrl;
    private int    $defaultTimeout;

    public function __construct(string $baseUrl = '', int $defaultTimeout = 15)
    {
        $this->baseUrl = rtrim(
            $baseUrl !== '' ? $baseUrl : 'https://comunicaapi.pje.jus.br/api/v1',
            '/'
        );
        $this->defaultTimeout = $defaultTimeout;
    }

    public function getId(): string { return 'djen'; }

    public function fetchPublications(array $filters, array $opts = []): array
    {
        $timeout = (int)($opts['timeout_seconds'] ?? $this->defaultTimeout);
        // Default 500: cobre meses inteiros sem cortar. DJEN aceita até 1000.
        $limit   = (int)($opts['limit'] ?? 500);

        // ── Normaliza OAB (aceita "SP357838" ou "357838" ou "357838/SP") ──
        $oab = trim((string)($filters['numero_oab'] ?? ''));
        $uf  = '';
        if ($oab !== '') {
            if (preg_match('/^([A-Z]{2})\s*0*(\d+)$/i', $oab, $m)) {
                $uf  = strtoupper($m[1]);
                $oab = ltrim($m[2], '0');
            } elseif (preg_match('/^(\d+)\s*\/\s*([A-Z]{2})$/i', $oab, $m)) {
                $uf  = strtoupper($m[2]);
                $oab = ltrim($m[1], '0');
            } else {
                $oab = ltrim(preg_replace('/\D/', '', $oab), '0');
            }
        }

        // ── Monta query ──
        $query = [];
        if ($oab !== '')                                    $query['numeroOab']                  = $oab;
        if ($uf  !== '')                                    $query['ufOab']                      = $uf;
        if (!empty($filters['sigla_tribunal']))             $query['siglaTribunal']              = $filters['sigla_tribunal'];
        if (!empty($filters['numero_processo']))            $query['numeroProcesso']             = preg_replace('/\D/', '', $filters['numero_processo']);
        if (!empty($filters['data_inicio']))                $query['dataDisponibilizacaoInicio'] = $filters['data_inicio'];
        if (!empty($filters['data_fim']))                   $query['dataDisponibilizacaoFim']    = $filters['data_fim'];
        if (!empty($filters['nome_advogado']))              $query['nomeAdvogado']               = $filters['nome_advogado'];
        $query['itensPorPagina'] = $limit;

        $url = $this->baseUrl . '/comunicacao?' . http_build_query($query);

        // ── HTTP GET ──
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: Yuris-CRM/1.0'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $rawResp = curl_exec($ch);
        $status  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err     = curl_error($ch);
        curl_close($ch);

        if ($rawResp === false || $status === 0) {
            throw new \RuntimeException("DJEN: erro de rede ({$err})");
        }
        if ($status >= 500) {
            throw new \RuntimeException("DJEN: servidor retornou {$status}");
        }
        if ($status >= 400) {
            throw new \RuntimeException("DJEN: requisição rejeitada ({$status}) — verificar filtros");
        }

        $decoded = json_decode($rawResp, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('DJEN: resposta não-JSON');
        }

        $rawItems = $decoded['items'] ?? [];
        if (!is_array($rawItems)) $rawItems = [];

        $items = [];
        foreach ($rawItems as $r) {
            if (!is_array($r)) continue;
            $items[] = $this->normalize($r);
        }

        return [
            'items'    => $items,
            'total'    => (int)($decoded['count'] ?? count($items)),
            'raw_meta' => [
                'status_http'  => $status,
                'request_url'  => $url,
                'status_api'   => $decoded['status']  ?? null,
                'message_api'  => $decoded['message'] ?? null,
            ],
        ];
    }

    public function normalize(array $r): array
    {
        $texto = self::cleanText((string)($r['texto'] ?? ''));
        // Tipo de comunicação como título (DJEN não tem titulo separado, usa tipoComunicacao)
        $tipo  = (string)($r['tipoComunicacao'] ?? 'Intimação');

        // Destinatários simplificados
        $dests = [];
        foreach (($r['destinatarios'] ?? []) as $d) {
            if (!is_array($d)) continue;
            $dests[] = [
                'nome' => $d['nome'] ?? '',
                'polo' => $d['polo'] ?? '',
            ];
        }

        // Advogados simplificados
        $advs = [];
        foreach (($r['destinatarioadvogados'] ?? []) as $da) {
            if (!is_array($da)) continue;
            $adv = $da['advogado'] ?? [];
            $advs[] = [
                'nome' => $adv['nome']       ?? '',
                'oab'  => $adv['numero_oab'] ?? '',
                'uf'   => $adv['uf_oab']     ?? '',
            ];
        }

        $normalized = [
            'source_id'                => 'djen',
            'tribunal'                 => (string)($r['siglaTribunal'] ?? ''),
            'data_disponibilizacao'    => (string)($r['data_disponibilizacao'] ?? date('Y-m-d')),
            'data_publicacao'          => null,
            'numero_processo'          => (string)($r['numero_processo'] ?? ''),
            'numero_processo_mascara'  => (string)($r['numeroprocessocommascara'] ?? ''),
            'orgao'                    => (string)($r['nomeOrgao'] ?? ''),
            'id_orgao'                 => isset($r['idOrgao']) ? (int)$r['idOrgao'] : null,
            'tipo_comunicacao'         => $tipo,
            'meio'                     => (string)($r['meio'] ?? ''),
            'meio_completo'            => (string)($r['meiocompleto'] ?? ''),
            'classe_nome'              => (string)($r['nomeClasse'] ?? ''),
            'classe_codigo'            => (string)($r['codigoClasse'] ?? ''),
            'titulo'                   => $tipo,
            'resumo'                   => mb_substr(trim(preg_replace('/\s+/u', ' ', $texto)), 0, 200),
            'conteudo'                 => $texto,
            'conteudo_html'            => null, // reservado: HTML original se quisermos render rich no futuro
            'url_origem'               => (string)($r['link'] ?? ''),
            'numero_comunicacao'       => isset($r['numeroComunicacao']) ? (int)$r['numeroComunicacao'] : null,
            'hash_externo'             => (string)($r['hash'] ?? ''),
            'destinatarios'            => $dests,
            'advogados'                => $advs,
            'payload_original'         => $r,
        ];

        // Hash canônico (anti-dup)
        $normalized['hash_conteudo'] = PublicationHasher::hash($normalized);

        return $normalized;
    }

    /**
     * Limpa texto vindo do DJEN. Algumas publicações chegam com HTML/CSS inline
     * (<!DOCTYPE>, <style>body{...}</style>, <td>, <br>, &nbsp;, etc.) — strip
     * pra texto puro legível, sem perder o conteúdo da intimação.
     */
    private static function cleanText(string $s): string
    {
        if ($s === '') return '';

        // 1) Remove <script>...</script> e <style>...</style> INTEIROS (conteúdo + tags)
        //    Multiline: [\s\S] pega quebras de linha. Não-greedy: pega o menor match.
        $s = preg_replace('/<script\b[^>]*>[\s\S]*?<\/script>/i', ' ', $s);
        $s = preg_replace('/<style\b[^>]*>[\s\S]*?<\/style>/i',   ' ', $s);

        // 2) Remove doctype + tags de estrutura órfãs
        $s = preg_replace('/<!DOCTYPE[^>]*>/i', ' ', $s);
        $s = preg_replace('/<(html|head|body|meta|title|link|style|script)\b[^>]*>/i', ' ', $s);
        $s = preg_replace('/<\/(html|head|body|meta|title|link|style|script)>/i', ' ', $s);

        // 3) HOTFIX: blocos CSS soltos (caso <style> não tenha </style> e o
        //    conteúdo CSS sobre como texto). Mata padrões como:
        //      body{...}  #divHeader{...}  .className{...}  @media(...){...}
        $s = preg_replace('/[@#.]?[a-zA-Z_][\w-]*\s*\{[^{}]*\}/u', ' ', $s);
        // Limpa restos de CSS multi-bloco: "body { foo: bar; baz: qux }"
        $s = preg_replace('/\b[a-zA-Z-]+\s*:\s*[^;{}]+;/u', ' ', $s);

        // 4) Substitui <br>, <p>, <tr>, <li> por quebra de linha antes de strip
        $s = preg_replace('/<\s*br\s*\/?>/i', "\n", $s);
        $s = preg_replace('/<\/(p|div|tr|li)>/i', "\n", $s);
        $s = preg_replace('/<\/td>/i',         ' ', $s);
        $s = preg_replace('/<\s*hr\s*\/?>/i', "\n---\n", $s);

        // 5) Strip tags restantes
        $s = strip_tags($s);

        // 6) Decode entidades (&amp; &nbsp; &#xxx;)
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 7) Normaliza whitespace: NBSP, tabs, múltiplos espaços, quebras
        $s = str_replace(["\xc2\xa0", "\t"], ' ', $s);
        $s = preg_replace('/[ ]{2,}/u', ' ', $s);
        $s = preg_replace('/\n{3,}/u', "\n\n", $s);

        return trim($s);
    }
}
