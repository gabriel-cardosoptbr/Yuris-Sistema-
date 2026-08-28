<?php
namespace App\Processos\Monitor;

/**
 * AaspProvider — integração com a API de Intimações da AASP.
 *
 * Endpoint (validado 2026-05-24 via Swagger /swagger/v1/swagger.json):
 *   GET https://intimacaoapi.aasp.org.br/api/Associado/intimacao/json
 *   GET https://intimacaoapi.aasp.org.br/api/Empresa/intimacao/json
 *   GET https://intimacaoapi.aasp.org.br/api/(Associado|Empresa)/intimacao/GetJornaisComIntimacoes/json
 *
 * Autenticação: query-param ?chave=<credencial> (sem header Bearer).
 *   • Modo Associado: 1 chave única do associado AASP
 *   • Modo Empresa:   chave do escritório + codigoPessoaAssociado obrigatório
 *                     (1 chamada por código — fan-out quando múltiplos)
 *
 * Envelope da resposta:
 *   /intimacao/json              → { intimacoes: [...], erro: bool, status: string }
 *   /GetJornaisComIntimacoes/json → { datas: [...]|null, erro: bool, status: string }
 *
 * Limitações da API:
 *   • Sem paginação (devolve tudo do dia)
 *   • Sem filtro por período (1 data por chamada — fan-out manual)
 *   • Sem POST/PATCH (status lida/não lida fica 100% no Yuris)
 *   • Sem rate limit declarado — usamos AASP_RATE_LIMIT_MS defensivo
 *   • Erros sempre HTTP 400 (mesmo de auth) com envelope erro=true
 *
 * Segurança:
 *   • Chave NUNCA aparece em logs (maskUrl substitui por ****)
 *   • HTTPS obrigatório (CURLOPT_SSL_VERIFYPEER=true)
 */
final class AaspProvider implements ProviderInterface
{
    private string $baseUrl;
    private int    $defaultTimeout;
    private int    $rateLimitMs;

    public function __construct(string $baseUrl = '', int $defaultTimeout = 25, int $rateLimitMs = 1000)
    {
        $this->baseUrl        = rtrim($baseUrl !== '' ? $baseUrl : 'https://intimacaoapi.aasp.org.br', '/');
        $this->defaultTimeout = $defaultTimeout;
        $this->rateLimitMs    = max(0, $rateLimitMs);
    }

    public function getId(): string { return 'aasp'; }

    /**
     * Busca publicações de UMA data específica. Pra período, chame N vezes (1 por dia)
     * ou use listDiasComIntimacoes pra reduzir chamadas vazias.
     *
     * @param array $filters [
     *   'data' => 'YYYY-MM-DD'  // obrigatório
     * ]
     * @param array $opts [
     *   'chave'              => string,        // obrigatório
     *   'tipo'               => 'associado'|'empresa' (default associado),
     *   'codigos_associados' => array<int>,   // obrigatório em empresa
     *   'diferencial'        => bool (default false),
     *   'timeout_seconds'    => int (default 25),
     * ]
     *
     * @return array ['items' => [...], 'total' => int, 'raw_meta' => [...]]
     * @throws \RuntimeException em erro de rede/auth/validação
     */
    public function fetchPublications(array $filters, array $opts = []): array
    {
        $chave   = trim((string)($opts['chave'] ?? ''));
        $tipo    = ($opts['tipo'] ?? 'associado') === 'empresa' ? 'empresa' : 'associado';
        $codigos = isset($opts['codigos_associados']) && is_array($opts['codigos_associados'])
            ? array_values(array_unique(array_map('intval', array_filter($opts['codigos_associados']))))
            : [];
        $diferencial = !empty($opts['diferencial']);
        $timeout     = (int)($opts['timeout_seconds'] ?? $this->defaultTimeout);

        $data = (string)($filters['data'] ?? '');
        if ($data === '') {
            throw new \RuntimeException('AaspProvider: parâmetro filters[data] obrigatório (YYYY-MM-DD)');
        }
        if ($chave === '') {
            throw new \RuntimeException('AaspProvider: opts[chave] obrigatório');
        }
        if ($tipo === 'empresa' && empty($codigos)) {
            throw new \RuntimeException(
                'AaspProvider: modo empresa exige pelo menos 1 codigo_associado. ' .
                'A API rejeita com "Código associado 0 inválido" sem isso.'
            );
        }

        $dataBR = self::dateToBR($data);
        $rawCodigos = $tipo === 'empresa' ? $codigos : [null];  // associado: 1 chamada sem código

        $allItems   = [];
        $aspectMeta = [];
        $callCount  = 0;
        $lastStatus = 200;

        foreach ($rawCodigos as $codigo) {
            $url    = $this->buildIntimacaoUrl($tipo, $chave, $dataBR, $diferencial, $codigo);
            $logUrl = $this->maskUrl($url);

            if ($callCount > 0 && $this->rateLimitMs > 0) {
                usleep($this->rateLimitMs * 1000);
            }
            $callCount++;

            [$status, $body, $errStr] = $this->httpGet($url, $timeout);
            $lastStatus = $status;

            if ($body === null || $status === 0) {
                throw new \RuntimeException("AASP: erro de rede ({$errStr})");
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                // AASP devolve body vazio em 401/500 — diagnóstico baseado no status
                throw new \RuntimeException(self::describeNonJsonResponse($status, $body));
            }
            // Envelope da AASP: erro=true sinaliza falha (mesmo em HTTP 200)
            if (!empty($decoded['erro'])) {
                $msg = (string)($decoded['status'] ?? 'erro desconhecido');
                throw new \RuntimeException('AASP: ' . $msg);
            }

            $items = $decoded['intimacoes'] ?? [];
            if (!is_array($items)) $items = [];

            foreach ($items as $r) {
                if (!is_array($r)) continue;
                $allItems[] = $this->normalize($r);
            }

            $aspectMeta[] = [
                'codigo' => $codigo,
                'url'    => $logUrl,
                'status' => $status,
                'count'  => count($items),
            ];
        }

        // Dedup global por hash_conteudo (caso códigos diferentes retornem a mesma publicação)
        $seen = [];
        $out  = [];
        foreach ($allItems as $it) {
            $h = $it['hash_conteudo'];
            if (isset($seen[$h])) continue;
            $seen[$h] = true;
            $out[] = $it;
        }

        return [
            'items'    => $out,
            'total'    => count($out),
            'raw_meta' => [
                'status_http'   => $lastStatus,
                'request_count' => $callCount,
                'calls'         => $aspectMeta,
                'tipo'          => $tipo,
                'data_br'       => $dataBR,
                'diferencial'   => $diferencial,
            ],
        ];
    }

    /**
     * Lista os dias (nos últimos $qtdeDias) em que existem intimações para a chave.
     * Permite otimizar busca por período (evita chamar /intimacao em dias vazios).
     *
     * @return array ['datas' => string[], 'raw_meta' => [...]]
     */
    public function listDiasComIntimacoes(
        string $chave,
        string $tipo = 'associado',
        int $qtdeDias = 30,
        ?int $codigoAssoc = null,
        int $timeout = 15
    ): array {
        $chave = trim($chave);
        if ($chave === '') {
            throw new \RuntimeException('AaspProvider::listDiasComIntimacoes: chave obrigatória');
        }
        $tipo = $tipo === 'empresa' ? 'empresa' : 'associado';
        if ($tipo === 'empresa' && !$codigoAssoc) {
            throw new \RuntimeException('listDiasComIntimacoes empresa: codigoAssoc obrigatório');
        }
        $segment = $tipo === 'empresa' ? 'Empresa' : 'Associado';
        $query   = ['chave' => $chave, 'qtdeDias' => max(1, min(365, $qtdeDias))];
        if ($codigoAssoc) $query['codigoPessoaAssociado'] = (int)$codigoAssoc;

        $url = $this->baseUrl . '/api/' . $segment . '/intimacao/GetJornaisComIntimacoes/json?' . http_build_query($query);
        [$status, $body, $err] = $this->httpGet($url, $timeout);
        if ($body === null || $status === 0) {
            throw new \RuntimeException("AASP: erro de rede ({$err})");
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('AASP: resposta não-JSON (jornais)');
        }
        if (!empty($decoded['erro'])) {
            throw new \RuntimeException('AASP: ' . (string)($decoded['status'] ?? 'erro'));
        }
        $datas = $decoded['datas'] ?? [];
        if (!is_array($datas)) $datas = [];
        // AASP devolve datas em formato variado (yyyy-mm-dd ou dd/mm/yyyy) — normalizo
        $norm = [];
        foreach ($datas as $d) {
            $iso = self::dateToISO((string)$d);
            if ($iso !== '') $norm[] = $iso;
        }
        sort($norm);

        return [
            'datas'    => array_values(array_unique($norm)),
            'raw_meta' => [
                'status_http' => $status,
                'url'         => $this->maskUrl($url),
                'qtdeDias'    => $qtdeDias,
            ],
        ];
    }

    /**
     * Normaliza um item raw da AASP pro schema unificado (mesmo formato do DjenProvider).
     *
     * Schema real da AASP (confirmado empiricamente 2026-05-24 com chave real):
     *   {
     *     "jornal": {
     *       "nomeJornal": "DJENTJMG",                       // → tribunal (extrair sigla após DJEN)
     *       "dataDisponibilizacao_Publicacao": "2026-05-18T00:00:00",  // → data_disponibilizacao
     *       "termoReferenciaData": "Disponibilização",      // qual data o servidor usou
     *       "totalIntimacoes": 0, ...                        // contadores
     *     },
     *     "textoPublicacao": "...",                          // → conteudo (texto bruto da intimação)
     *     "titulo": "TJMG Diário de Justiça Eletrônico Nacional",  // → titulo do diário
     *     "numeroPublicacao": 66308,                         // → numero_comunicacao
     *     "numeroArquivo": 1,                                // referência interna AASP
     *     "cabecalho": "Intimação\r\n",                      // → tipo_comunicacao (sem \r\n)
     *     "rodape": null,
     *     "codigoRelacionamento": 11728878437,               // → hash_externo (ID único da AASP)
     *     "numeroUnicoProcesso": "2026495-05.2026.8.13.0000" // → numero_processo_mascara
     *   }
     *
     * O textoPublicacao traz uma cabeça padronizada com "Processo:", "Órgão:",
     * "Tipo de comunicação:", "Meio:", "Parte(s):", "Advogado(s)" antes do conteúdo
     * propriamente dito. Extraímos via regex.
     */
    public function normalize(array $r): array
    {
        $jornal     = is_array($r['jornal'] ?? null) ? $r['jornal'] : [];
        $textoFull  = (string)($r['textoPublicacao'] ?? '');
        $cabecalho  = trim((string)($r['cabecalho'] ?? ''));
        $tituloRaw  = trim((string)($r['titulo'] ?? ''));

        // Tribunal: extrair de nomeJornal (ex: "DJENTJMG" → "TJMG", "DJENTRT2" → "TRT2")
        $nomeJornal = (string)($jornal['nomeJornal'] ?? '');
        $tribunal   = '';
        if ($nomeJornal !== '') {
            if (preg_match('/^DJEN([A-Z0-9]+)/i', $nomeJornal, $m)) {
                $tribunal = strtoupper($m[1]);
            } else {
                $tribunal = strtoupper($nomeJornal);
            }
        }
        // Fallback: extrair do titulo (ex: "TJMG Diário de Justiça...")
        if ($tribunal === '' && $tituloRaw !== '') {
            if (preg_match('/^(T[A-Z]{2,4}\d?)\b/', $tituloRaw, $m)) {
                $tribunal = $m[1];
            }
        }

        // Data disponibilização: jornal.dataDisponibilizacao_Publicacao "2026-05-18T00:00:00"
        $dataDispRaw = (string)($jornal['dataDisponibilizacao_Publicacao'] ?? '');
        $dataDisp    = self::dateToISO($dataDispRaw) ?: date('Y-m-d');

        // Processo
        $numProcMask = trim((string)($r['numeroUnicoProcesso'] ?? ''));
        $numProc     = $numProcMask !== '' ? preg_replace('/\D/', '', $numProcMask) : '';

        // Tipo de comunicação: cabecalho "Intimação\r\n" → "Intimação"
        $tipoCom = $cabecalho !== ''
            ? trim(preg_replace('/[\r\n]+/', ' ', $cabecalho))
            : 'Intimação';

        // Helper de parse — captura "Label: <valor>" parando no próximo label conhecido
        // ou em quebra de linha. Resiliente a payload com \n quebrado OU tudo inline (TST).
        // Labels conhecidos do header AASP: Processo, Órgão, Data, Tipo de comunicação,
        // Meio, Parte(s), Advogado(s), Agravante, Agravado, Publicação, Nota, Relator.
        $stopLabels = '(?:\s+(?:Processo|[ÓO]rg[ãa]o|Data|Tipo|Meio|Parte\(s\)|Advogado\(s\)|Agravante|Agravado|Publica[çc][aã]o|Nota|Relator)[:\s])';
        $parseLabel = static function (string $label) use ($stopLabels) {
            // Captura "<label>: VALOR" até próximo label ou quebra de linha
            return '/' . $label . '\s*:?\s*(.+?)(?=' . $stopLabels . '|[\r\n]|$)/u';
        };

        // Órgão
        $orgao = '';
        if (preg_match($parseLabel('[ÓO]rg[ãa]o'), $textoFull, $m)) {
            $orgao = trim($m[1]);
        }
        // Fallback se não achou: usa nome do tribunal
        if ($orgao === '') {
            $orgao = $tribunal !== '' ? $tribunal : 'Órgão não identificado';
        }

        // Meio
        $meioCompleto = '';
        if (preg_match($parseLabel('Meio'), $textoFull, $m)) {
            $meioCompleto = trim($m[1]);
        }
        $meio = $meioCompleto !== '' ? 'D' : '';

        // Advogados — captura bloco "Advogado(s) ... <até próximo label>"
        $advogados = [];
        if (preg_match('/Advogado\(s\)\s*:?\s*(.+?)(?=' . $stopLabels . '|$)/su', $textoFull, $blocoMatch)) {
            $bloco = $blocoMatch[1];
            // Pattern: NOME (uppercase + acentos + espaços) seguido de OAB UF-NNNNNN
            if (preg_match_all('/([A-ZÁÉÍÓÚÂÊÎÔÛÃÕÇ][A-ZÁÉÍÓÚÂÊÎÔÛÃÕÇ\s\.\']+?)\s+OAB\s+([A-Z]{2})[-\s]*(\d+)/u', $bloco, $advMatches, PREG_SET_ORDER)) {
                foreach ($advMatches as $am) {
                    $advogados[] = [
                        'nome' => trim($am[1]),
                        'oab'  => $am[3],
                        'uf'   => $am[2],
                    ];
                }
            }
        }

        // Partes
        $destinatarios = [];
        if (preg_match('/Parte\(s\)\s*:\s*(.+?)(?=' . $stopLabels . '|$)/su', $textoFull, $m)) {
            foreach (preg_split('/[;,\r\n]+/', trim($m[1])) as $parte) {
                $parte = trim($parte);
                if ($parte !== '' && !preg_match('/^Advogado/i', $parte)) {
                    $destinatarios[] = ['nome' => $parte, 'polo' => ''];
                }
            }
        }

        // Conteúdo limpo: o textoPublicacao tem \n\r misturado — vira inline (igual AASP nativa renderiza).
        // Mostramos o texto COMPLETO no card pra ter as mesmas informações que a AASP nativa.
        // O meta acima do card (badge tribunal, processo, órgão) é só atalho rápido visual —
        // o texto inline traz tudo integralmente.
        $textoClean = self::cleanTexto($textoFull);
        $conteudo   = $textoClean;

        // Resumo: extrai o "miolo" da intimação (sem header repetitivo) pra ficar conciso
        // em KPIs e notificações. Não afeta o que aparece no card (card mostra o conteudo completo).
        $miolo  = self::extractMiolo($textoClean);
        $resumoFonte = $miolo !== '' ? $miolo : $textoClean;
        $resumo = mb_substr(trim(preg_replace('/\s+/u', ' ', $resumoFonte)), 0, 200);

        $normalized = [
            'source_id'                => 'aasp',
            'tribunal'                 => $tribunal,
            'data_disponibilizacao'    => $dataDisp,
            'data_publicacao'          => null,  // AASP só dá disponibilização
            'numero_processo'          => $numProc,
            'numero_processo_mascara'  => $numProcMask,
            'orgao'                    => $orgao,
            'id_orgao'                 => null,
            'tipo_comunicacao'         => $tipoCom,
            'meio'                     => $meio,
            'meio_completo'            => $meioCompleto,
            'classe_nome'              => '',
            'classe_codigo'            => '',
            'titulo'                   => $tipoCom,
            'resumo'                   => $resumo,
            'conteudo'                 => $conteudo,
            'url_origem'               => '',
            'numero_comunicacao'       => isset($r['numeroPublicacao']) ? (int)$r['numeroPublicacao'] : null,
            'hash_externo'             => isset($r['codigoRelacionamento']) ? (string)$r['codigoRelacionamento'] : '',
            'destinatarios'            => $destinatarios,
            'advogados'                => $advogados,
            'payload_original'         => $r,
        ];
        // Hash canônico (anti-dup), idêntico ao usado pelo DjenProvider
        $normalized['hash_conteudo'] = PublicationHasher::hash($normalized);
        return $normalized;
    }

    /**
     * Limpa o textoPublicacao da AASP. Vem com \n\r misturado.
     * Vira INLINE — mesma renderização que a AASP nativa faz.
     * Quebras de linha viram espaço; espaços múltiplos colapsam.
     */
    private static function cleanTexto(string $s): string
    {
        if ($s === '') return '';
        // AASP usa \n\r e \r misturados — vira espaço (não quebra linha)
        $s = preg_replace('/[\r\n]+/u', ' ', $s);
        // Tabs e NBSP → espaço
        $s = str_replace(["\t", "\xc2\xa0"], ' ', $s);
        // Espaços múltiplos colapsam pra 1
        $s = preg_replace('/\s{2,}/u', ' ', $s);
        return trim($s);
    }

    /**
     * Extrai o "miolo" da intimação, pulando o header padronizado da AASP
     * (Processo/Órgão/Data/Tipo/Meio/Parte/Advogados) que já aparece como meta
     * no card. Usa heurística de pontos de corte conhecidos.
     *
     * Retorna string vazia se nenhum ponto de corte foi encontrado (caller
     * decide o fallback — geralmente o texto completo limpo).
     */
    private static function extractMiolo(string $textoLimpo): string
    {
        if ($textoLimpo === '') return '';

        // Lista de marcadores que tipicamente PRECEDEM o conteúdo real
        // (em ordem de prioridade — primeiro que matchar ganha)
        $marcadores = [
            // Mais específicos primeiro
            '/Publicação realizada no DJEN\s*:?\s*/u',
            '/Nota de cart[oó]rio\s*:\s*/iu',
            // Tipos de ato judicial seguidos do conteúdo
            '/\s+-\s+(?:Procedimento|Cumprimento|Mandado|Recurso|Agravo|Apela[çc][aã]o|Embargos)\s+[^-]*\s+-\s+/iu',
            '/\bDespacho\s*:\s*/iu',
            '/\bSentença\s*:\s*/iu',
            '/\bDecisão\s*:\s*/iu',
            '/\bAcórdão\s*:\s*/iu',
            '/\bAditamento\s+[àa]\s+/iu',
        ];

        foreach ($marcadores as $regex) {
            if (preg_match($regex, $textoLimpo, $m, PREG_OFFSET_CAPTURE)) {
                $start = $m[0][1] + strlen($m[0][0]);
                $miolo = trim(substr($textoLimpo, $start));
                // Corta o sufixo padrão "Adv - LISTA DE ADVOGADOS." que vem no fim
                $miolo = preg_replace('/\s*Adv\s*-\s*[A-ZÁ-Ú,\s\.\']+\.?\s*$/u', '', $miolo);
                if (mb_strlen($miolo) >= 30) {  // pelo menos algumas palavras
                    return trim($miolo);
                }
            }
        }

        // Fallback: tenta pular tudo após o último "OAB XX-NNNN" no texto
        // (geralmente o bloco de advogados termina antes do conteúdo real)
        if (preg_match_all('/OAB\s+[A-Z]{2}[-\s]?\d+/u', $textoLimpo, $matches, PREG_OFFSET_CAPTURE)) {
            $ultimo = end($matches[0]);
            $start = $ultimo[1] + strlen($ultimo[0]);
            // Pula também o que vier imediatamente depois (espaço + nome separados ainda)
            $resto = trim(substr($textoLimpo, $start));
            // Remove prefixos vazios tipo "Agravante(s) - X" antes do conteúdo
            $resto = preg_replace('/^(?:Agravante|Agravado|Relator|Recorrente|Recorrido)[^\.]*\.\s*/iu', '', $resto);
            if (mb_strlen($resto) >= 30) {
                return $resto;
            }
        }

        return '';  // caller usa o texto completo como fallback
    }

    // ─────────────────────────────────────────────────────────────────────────
    // INTERNAL
    // ─────────────────────────────────────────────────────────────────────────

    /** Monta URL completa de /intimacao/json (Associado ou Empresa). */
    private function buildIntimacaoUrl(string $tipo, string $chave, string $dataBR, bool $diferencial, ?int $codigoAssoc): string
    {
        $segment = $tipo === 'empresa' ? 'Empresa' : 'Associado';
        $query   = ['chave' => $chave, 'data' => $dataBR];
        if ($diferencial) $query['diferencial'] = 'true';
        if ($tipo === 'empresa' && $codigoAssoc) $query['codigoPessoaAssociado'] = (int)$codigoAssoc;
        return $this->baseUrl . '/api/' . $segment . '/intimacao/json?' . http_build_query($query);
    }

    /** Substitui chave=... por chave=***MASKED*** pra log seguro. */
    public function maskUrl(string $url): string
    {
        return (string)preg_replace('/(chave=)[^&#]*/i', '$1***MASKED***', $url);
    }

    /**
     * Gera mensagem amigável quando o body não é JSON.
     * Cobre os 3 casos observados empiricamente em 2026-05-24:
     *   401 + body vazio → chave formato "real" mas não autorizada
     *   500 + body vazio → chave malformada/longa demais ou hiccup do servidor
     *   outro → fallback genérico com trecho do body (sem credencial)
     */
    private static function describeNonJsonResponse(int $status, ?string $body): string
    {
        $len  = $body === null ? 0 : strlen($body);
        $body = (string)$body;

        if ($status === 401) {
            return 'AASP retornou 401 (não autorizado): a chave tem formato válido mas não está autorizada. '
                . 'Confirme em intimacaoapi-cadastro.aasp.org.br se sua chave está ativa e renovada.';
        }
        if ($status === 500 && $len === 0) {
            return 'AASP retornou 500 com body vazio: causa mais provável é chave mal-formada '
                . '(ex: copiou texto extra junto). Verifique que copiou só a chave, sem espaços ou aspas.';
        }
        if ($status === 500) {
            // 500 com body — talvez HTML de erro do servidor
            $trecho = self::sanitizeBodySnippet($body, 200);
            return "AASP retornou 500. Resposta (não-JSON): " . $trecho;
        }
        if ($status === 503 || $status === 502 || $status === 504) {
            return "AASP indisponível temporariamente (HTTP {$status}). Tente novamente em alguns minutos.";
        }
        $trecho = self::sanitizeBodySnippet($body, 200);
        return "AASP: resposta inesperada (status {$status}, body: {$trecho})";
    }

    /**
     * Sanitiza trecho do body pra incluir em mensagem de erro sem vazar chave
     * nem caracteres binários/de controle.
     */
    private static function sanitizeBodySnippet(string $body, int $maxLen): string
    {
        if ($body === '') return '(vazio)';
        // Remove caracteres não-printáveis
        $t = preg_replace('/[\x00-\x1F\x7F]/', ' ', $body);
        // Mascarar qualquer string que pareça chave (key=valor com >20 chars)
        $t = (string)preg_replace('/(chave|key|token|auth)=[^&\s"\']{3,}/i', '$1=***', (string)$t);
        $t = trim($t);
        if (strlen($t) > $maxLen) $t = substr($t, 0, $maxLen) . '…';
        return $t === '' ? '(vazio após sanitização)' : '"' . $t . '"';
    }

    /** HTTP GET defensivo. Retorna [status, body, errStr]. */
    private function httpGet(string $url, int $timeout): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: Yuris-CRM/1.0'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $body   = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        curl_close($ch);
        return [$status, $body === false ? null : $body, $err];
    }

    /** Converte YYYY-MM-DD pra DD/MM/YYYY (formato BR aceito pela AASP). */
    private static function dateToBR(string $iso): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $iso, $m)) {
            return "{$m[3]}/{$m[2]}/{$m[1]}";
        }
        // Já vem em dd/mm/yyyy?
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $iso)) return $iso;
        throw new \RuntimeException("AaspProvider: data '{$iso}' em formato inválido (esperado YYYY-MM-DD ou DD/MM/YYYY)");
    }

    /** Converte DD/MM/YYYY (ou já ISO) pra YYYY-MM-DD. Retorna '' se inválido. */
    private static function dateToISO(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        // Já em ISO?
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        // dd/mm/yyyy
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $s, $m)) return "{$m[3]}-{$m[2]}-{$m[1]}";
        // ISO com hora — pega só a data
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T/', $s, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
        return '';
    }

    /** Aplica máscara CNJ se o número estiver puro (20 dígitos). */
    private static function maskProcesso(string $numero): string
    {
        $d = preg_replace('/\D/', '', $numero);
        if (strlen($d) === 20) {
            return substr($d,0,7) . '-' . substr($d,7,2) . '.' . substr($d,9,4) . '.'
                 . substr($d,13,1) . '.' . substr($d,14,2) . '.' . substr($d,16,4);
        }
        return $numero;
    }
}
