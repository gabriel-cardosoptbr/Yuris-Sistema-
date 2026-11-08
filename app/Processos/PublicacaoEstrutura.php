<?php
namespace App\Processos;

/**
 * A publicação do Diário separada em tópicos (07/11/2026).
 *
 * "Tem que separar os OAB, o que é advogado, o que é corpo... separar todos os
 * tópicos." O texto chega do DJEN e da AASP como uma linha corrida só: cabeçalho
 * do juízo, partes, intimação, conclusão, despacho, assinatura e advogados tudo
 * colado. Esta classe devolve isso em pedaços:
 *
 *   cabecalho   rótulo e valor (classe, tipo de documento, assunto, relator...)
 *   preambulo   "PODER JUDICIÁRIO ... 7ª VARA ... ATOrd 1000944-..." (o timbre)
 *   partes      por polo ou pelo rótulo do texto (Reclamante, Requerido...)
 *   advogados   um por linha, cada um com a OAB e a UF, sem repetir
 *   blocos      cada seção (Intimação, Conclusão, Despacho...) com os parágrafos
 *               e a assinatura (local e data, nome, cargo) separada do corpo
 *   intimados   o "Intimado(s) / Citado(s)" do fim
 *   link        o endereço da certidão no tribunal (só https)
 *
 * Regra pura: não consulta banco, não depende de sessão. Os mesmos extratores de
 * advogados e partes servem ao Diário (api/push/list.php delega para cá).
 */
final class PublicacaoEstrutura
{
    private const MAI = 'A-ZÀ-ÖØ-Þ';
    private const MIN = 'a-zß-öø-ÿ';

    /** Rótulos de parte como o tribunal escreve (maiúsculas e dois-pontos). Polo: A ativo, P passivo, '' outro. */
    private const PARTES = [
        'TERCEIRO INTERESSADO' => '', 'POLO ATIVO' => 'A', 'POLO PASSIVO' => 'P', 'PARTE AUTORA' => 'A', 'PARTE RÉ' => 'P',
        'RECLAMANTE' => 'A', 'RECLAMADO' => 'P', 'RECLAMADA' => 'P', 'AUTORES' => 'A', 'AUTORA' => 'A', 'AUTOR' => 'A',
        'RÉUS' => 'P', 'RÉU' => 'P', 'RÉ' => 'P', 'REQUERENTE' => 'A', 'REQUERIDO' => 'P', 'REQUERIDA' => 'P',
        'EXEQUENTE' => 'A', 'EXECUTADO' => 'P', 'EXECUTADA' => 'P', 'AGRAVANTE' => 'A', 'AGRAVADO' => 'P', 'AGRAVADA' => 'P',
        'APELANTE' => 'A', 'APELADO' => 'P', 'APELADA' => 'P', 'RECORRENTE' => 'A', 'RECORRIDO' => 'P', 'RECORRIDA' => 'P',
        'IMPETRANTE' => 'A', 'IMPETRADO' => 'P', 'EMBARGANTE' => 'A', 'EMBARGADO' => 'P', 'EMBARGADA' => 'P',
        'DEPRECANTE' => 'A', 'DEPRECADO' => 'P', 'ALIMENTANDO' => 'A', 'ALIMENTANTE' => 'P', 'CREDOR' => 'A', 'DEVEDOR' => 'P',
        'INTERESSADO' => '', 'INTERESSADA' => '', 'INVENTARIANTE' => '', 'PACIENTE' => '', 'CUSTOS LEGIS' => '', 'PERITO' => '',
    ];

    /** Títulos de seção do teor, em maiúsculas no texto. Os compostos vêm antes. */
    private const SECOES = [
        'DECISÃO INTERLOCUTÓRIA', 'ATO ORDINATÓRIO', 'INTIMAÇÃO', 'NOTIFICAÇÃO', 'CITAÇÃO', 'CONCLUSÃO', 'DESPACHO',
        'DECISÃO', 'SENTENÇA', 'ACÓRDÃO', 'EMENTA', 'RELATÓRIO', 'FUNDAMENTAÇÃO', 'DISPOSITIVO', 'VOTO', 'CERTIDÃO', 'EDITAL',
    ];

    /** Rótulos de cabeçalho quando o texto vem em linhas ("Classe - Assunto: ..."). */
    private const CABECALHO = [
        'processo' => 'Processo', 'processo digital nº' => 'Processo', 'processo nº' => 'Processo', 'nº do processo' => 'Processo',
        'número do processo' => 'Processo', 'processo principal' => 'Processo principal', 'processo de origem' => 'Processo de origem',
        'classe' => 'Classe', 'classe judicial' => 'Classe', 'classe - assunto' => 'Classe e assunto', 'assunto' => 'Assunto',
        'assuntos' => 'Assunto', 'órgão julgador' => 'Órgão julgador', 'relator' => 'Relator', 'relatora' => 'Relator',
        'relator(a)' => 'Relator', 'juiz(a) de direito' => 'Juiz', 'juiz de direito' => 'Juiz', 'juíza de direito' => 'Juiz',
        'comarca' => 'Comarca', 'vara' => 'Vara', 'foro' => 'Foro', 'origem' => 'Origem', 'valor da causa' => 'Valor da causa',
    ];

    // ── O conjunto ──────────────────────────────────────────────────────────

    /**
     * @param string            $texto   o teor (push_events.conteudo, ou o resumo)
     * @param array|string|null $payload push_events.payload_original (JSON do DJEN ou da AASP)
     */
    public static function estruturar(string $texto, array|string|null $payload = null): array
    {
        $pl = self::payload($payload);
        $t  = self::limpar($texto !== '' ? $texto : (string) ($pl['texto'] ?? $pl['textoPublicacao'] ?? ''));

        $saida = ['cabecalho' => [], 'preambulo' => '', 'partes' => [], 'advogados' => [], 'blocos' => [], 'intimados' => [], 'link' => ''];

        // 1. O que fica no fim: "Intimado(s) / Citado(s)" e a lista "ADV:".
        if (preg_match('/\s*Intimado\(s\)\s*\/\s*Citado\(s\)\s*:?\s*(.*)$/su', $t, $m, PREG_OFFSET_CAPTURE)) {
            $saida['intimados'] = self::itensDeLista($m[1][0]);
            $t = rtrim(substr($t, 0, $m[0][1]));
        }
        $advTexto = [];
        if (preg_match('/\s*[-–]?\s*(?:ADVS?|Advogad[oa]s?(?:\(as?\)|\(s\))?)\s*:\s*(.+)$/su', $t, $m, PREG_OFFSET_CAPTURE)
            && preg_match('/OAB/i', $m[1][0])) {
            $advTexto = self::advogadosDoTexto($m[1][0]);
            if ($advTexto) $t = rtrim(substr($t, 0, $m[0][1]), " -–\t\n");
        }

        // 2. O cabeçalho: em linhas, no formato "Processo NNNN - Classe - Assunto - Partes - teor", ou com rótulos em maiúsculas.
        $partesTexto = [];
        [$t, $cabLinhas, $partesLinhas] = self::cabecalhoEmLinhas($t);
        $saida['cabecalho'] = $cabLinhas;
        $partesTexto = $partesLinhas;
        if (!$cabLinhas && !$partesLinhas) {
            [$t, $cabTraco, $partesTraco] = self::cabecalhoComTracos($t);
            $saida['cabecalho'] = $cabTraco;
            $partesTexto = $partesTraco;
        }
        if (!$partesTexto) {
            [$t, $pre, $partesRotulo] = self::partesComRotulo($t);
            $saida['preambulo'] = $pre;
            $partesTexto = $partesRotulo;
        }

        // 3. Dados que o DJEN manda prontos.
        if ($pl) {
            $classe = self::rotuloBonito((string) ($pl['nomeClasse'] ?? ''));
            if ($classe !== '' && !self::temRotulo($saida['cabecalho'], ['Classe', 'Classe e assunto'])) array_unshift($saida['cabecalho'], ['rotulo' => 'Classe', 'valor' => $classe]);
            $doc = self::rotuloBonito((string) ($pl['tipoDocumento'] ?? ''));
            if ($doc !== '') $saida['cabecalho'][] = ['rotulo' => 'Documento', 'valor' => $doc];
            $link = (string) ($pl['link'] ?? '');
            if (preg_match('#^https://[^\s"<>]+$#', $link)) $saida['link'] = $link;
        }

        // 4. Partes: o polo do DJEN manda; sem ele, os rótulos do texto.
        $saida['partes'] = self::partesPorPolo($pl, $partesTexto);

        // 5. Advogados: os do DJEN, os da lista "ADV:" e os citados com OAB, sem repetir.
        $saida['advogados'] = self::juntarAdvogados(self::advogadosDoPayload($pl), $advTexto, self::advogadosDoTexto($t));

        // 6. O teor em seções, parágrafos e assinatura.
        $saida['blocos'] = self::blocos($t);
        return $saida;
    }

    // ── Advogados e partes (também usados pelo Diário) ──────────────────────

    /** Advogados do payload: DJEN (destinatarioadvogados) ou AASP (regex no textoPublicacao). */
    public static function advogadosDoPayload(array|string|null $payload): array
    {
        $pl = self::payload($payload);
        if (!$pl) return [];
        $advs = [];
        if (!empty($pl['destinatarioadvogados']) && is_array($pl['destinatarioadvogados'])) {
            // O tribunal manda o mesmo advogado mais de uma vez quando ele representa
            // duas partes do mesmo processo: a OAB identifica, sem ela vale o nome.
            $vistos = [];
            foreach ($pl['destinatarioadvogados'] as $da) {
                $adv = is_array($da) ? ($da['advogado'] ?? []) : [];
                if (!is_array($adv)) continue;
                $nome = trim((string) ($adv['nome'] ?? ''));
                $oab  = trim((string) ($adv['numero_oab'] ?? ''));
                $uf   = trim((string) ($adv['uf_oab'] ?? ''));
                if ($nome === '' && $oab === '') continue;
                $chave = $oab !== '' ? mb_strtoupper($uf . '-' . $oab) : mb_strtoupper($nome);
                if (isset($vistos[$chave])) continue;
                $vistos[$chave] = true;
                $advs[] = ['nome' => $nome, 'oab' => $oab, 'uf' => $uf];
            }
        }
        if (!$advs && !empty($pl['textoPublicacao'])) {
            $texto = (string) $pl['textoPublicacao'];
            if (preg_match_all('/([A-ZÁÉÍÓÚÂÊÎÔÛÃÕÇ][A-ZÁÉÍÓÚÂÊÎÔÛÃÕÇ\s\.\']+?)\s+OAB\s+([A-Z]{2})[-\s]*(\d+)/u', $texto, $m, PREG_SET_ORDER)) {
                $seen = [];
                foreach ($m as $row) {
                    $key = "{$row[2]}-{$row[3]}";
                    if (isset($seen[$key])) continue;
                    $seen[$key] = true;
                    $advs[] = ['nome' => trim($row[1]), 'oab' => $row[3], 'uf' => $row[2]];
                }
            }
        }
        return $advs;
    }

    /** Nomes das partes do payload: DJEN (destinatarios) ou AASP (bloco "Parte(s):"). */
    public static function partesDoPayload(array|string|null $payload): array
    {
        $pl = self::payload($payload);
        if (!$pl) return [];
        $partes = [];
        if (!empty($pl['destinatarios']) && is_array($pl['destinatarios'])) {
            foreach ($pl['destinatarios'] as $d) {
                if (!is_array($d)) continue;
                $nome = trim((string) ($d['nome'] ?? ''));
                if ($nome !== '') $partes[] = $nome;
            }
        }
        if (!$partes && !empty($pl['textoPublicacao'])) {
            $texto = (string) $pl['textoPublicacao'];
            $stop = '\s+(?:Advogado\(s\)|Agravante|Agravado|Recorrente|Recorrido|Publicação|Nota|Relator|Processo)';
            if (preg_match('/Parte\(s\)\s*:\s*(.+?)(?=' . $stop . '|$)/su', $texto, $m)) {
                $bloco = trim((string) preg_replace('/\s{2,}/', ' ', (string) preg_replace('/[\r\n]+/', ' ', $m[1])));
                if ($bloco !== '') $partes[] = $bloco;
            }
        }
        return $partes;
    }

    /**
     * Advogados citados no texto com a OAB, nas formas que os diários usam:
     * "NOME (OAB 346592/SP)", "NOME (OAB/SP 346.592)", "NOME - OAB/SP 346592", "NOME OAB SP-346592".
     */
    public static function advogadosDoTexto(string $texto): array
    {
        $M = self::MAI;
        $nome = "([$M][$M'´`\\.]*(?:\\s+[$M][$M'´`\\.]*){1,8})";
        $formas = [
            "/$nome\\s*\\(\\s*OAB\\s*:?\\s*(\\d[\\d\\.]*[A-Z]?)\\s*\\/\\s*([A-Z]{2})\\s*\\)/u"                       => [1, 2, 3],
            "/$nome\\s*\\(\\s*OAB\\s*\\/?\\s*([A-Z]{2})\\s*:?\\s*(?:n[ºo°]\\.?\\s*)?(\\d[\\d\\.]*[A-Z]?)\\s*\\)/u"   => [1, 3, 2],
            "/$nome\\s*[-–,]\\s*OAB\\s*\\/\\s*([A-Z]{2})\\s*(?:n[ºo°]\\.?\\s*)?(\\d[\\d\\.]*[A-Z]?)/u"               => [1, 3, 2],
            "/$nome\\s+OAB\\s+([A-Z]{2})[-\\s]*(\\d[\\d\\.]*)/u"                                                  => [1, 3, 2],
        ];
        $achados = [];
        foreach ($formas as $re => [$iNome, $iOab, $iUf]) {
            if (!preg_match_all($re, $texto, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) continue;
            foreach ($mm as $m) {
                $n = self::semRotuloNoNome(trim($m[$iNome][0]));
                if ($n === '' || mb_strlen($n) < 5) continue;
                $achados[$m[0][1]] = ['nome' => $n, 'oab' => str_replace('.', '', $m[$iOab][0]), 'uf' => $m[$iUf][0]];
            }
        }
        ksort($achados);
        return self::juntarAdvogados(array_values($achados));
    }

    // ── As partes do texto ──────────────────────────────────────────────────

    /** "RECLAMANTE: FULANO RECLAMADO: EMPRESA LTDA INTIMAÇÃO Fica..." → partes, e o teor começa na seção. */
    private static function partesComRotulo(string $t): array
    {
        $M = self::MAI; $m = self::MIN;
        $rot = implode('|', array_map(fn ($r) => preg_quote($r, '/'), array_keys(self::PARTES)));
        $reRot = "/(?<![$M$m])($rot)(?:\\((?:S|A|AS|ES|OS)\\))?\\s*:\\s*/u";
        if (!preg_match_all($reRot, $t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) return [$t, '', []];

        // Só os rótulos antes da primeira seção do teor contam (depois, é o juiz citando a parte).
        $limite = strlen($t);
        if (preg_match(self::reSecao(), $t, $s, PREG_OFFSET_CAPTURE)) $limite = $s[0][1];
        $mm = array_values(array_filter($mm, fn ($x) => $x[0][1] < $limite));
        if (!$mm) return [$t, '', []];

        $secoes = implode('|', array_map(fn ($r) => preg_quote($r, '/'), self::SECOES));
        $partes = [];
        $fim = $mm[0][0][1];
        foreach ($mm as $i => $x) {
            $ini = $x[0][1] + strlen($x[0][0]);
            $ate = isset($mm[$i + 1]) ? $mm[$i + 1][0][1] : strlen($t);
            $trecho = substr($t, $ini, $ate - $ini);
            // O nome vai até a próxima seção ou a primeira palavra em caixa mista.
            if (preg_match("/^\\s*(.+?)(?=\\s+(?:$secoes)(?![$M$m])|\\s+[$M][$m]|\\s*$)/su", $trecho, $n)) {
                $nome = trim($n[1], " \t\n,;-–");
                if ($nome !== '') $partes[] = ['rotulo' => self::rotuloBonito($x[1][0]), 'polo' => self::PARTES[$x[1][0]] ?? '', 'nome' => $nome];
                $fim = $ini + strlen($n[0]);
            } else {
                $fim = $ate;
            }
        }
        $pre = trim(substr($t, 0, $mm[0][0][1]));
        return [trim(substr($t, $fim)), $pre, $partes];
    }

    /** Texto em linhas: "Processo Digital nº: ...", "Classe - Assunto: ...", "Requerente: ...". */
    private static function cabecalhoEmLinhas(string $t): array
    {
        if (!str_contains($t, "\n")) return [$t, [], []];
        $linhas = explode("\n", $t);
        $cab = []; $partes = []; $usadas = 0;
        foreach ($linhas as $i => $l) {
            $l = trim($l);
            if ($l === '') { $usadas = $i + 1; continue; }
            if (!preg_match('/^([^:]{2,40}?)\s*:\s*(.+)$/u', $l, $m)) break;
            $chave = mb_strtolower(trim($m[1]));
            $maiu  = mb_strtoupper(trim(preg_replace('/\((?:s|a|as|es|os)\)$/i', '', trim($m[1]))));
            if (isset(self::CABECALHO[$chave])) {
                $cab[] = ['rotulo' => self::CABECALHO[$chave], 'valor' => trim($m[2])];
            } elseif (isset(self::PARTES[$maiu])) {
                $partes[] = ['rotulo' => self::rotuloBonito($maiu), 'polo' => self::PARTES[$maiu], 'nome' => trim($m[2])];
            } else {
                break;
            }
            $usadas = $i + 1;
        }
        if (!$cab && !$partes) return [$t, [], []];
        return [trim(implode("\n", array_slice($linhas, $usadas))), $cab, $partes];
    }

    /** "Processo 1001234-56.2024.8.26.0100 - Procedimento Comum Cível - Indenização - FULANO - BANCO X - Vistos..." (TJSP). */
    private static function cabecalhoComTracos(string $t): array
    {
        // O TJSP põe "(processo principal NNNN)" logo depois do número no cumprimento de sentença.
        $cnj = '\d{7}-\d{2}\.\d{4}\.\d\.\d{2}\.\d{4}';
        if (!preg_match("/^\\s*Processo\\s+(?:n[ºo°]\\.?\\s*)?($cnj)(?:\\s*\\(\\s*processo\\s+principal\\s*(?:n[ºo°]\\.?\\s*)?($cnj)\\s*\\))?\\s*[-–]\\s+/iu", $t, $m)) return [$t, [], []];
        $resto = substr($t, strlen($m[0]));
        // " - - " é campo vazio entre as partes: o segmento vazio some.
        $seg = preg_split('/\s+[-–](?=\s)/u', $resto);
        $cab = [];
        $i = 0;
        for (; $i < count($seg); $i++) {
            if (trim($seg[$i]) === '') continue;
            if (self::pareceTeor($seg[$i])) break;
            $cab[] = trim($seg[$i]);
        }
        if (!$cab || $i >= count($seg)) return [$t, [], []];
        $saida = [['rotulo' => 'Processo', 'valor' => $m[1]]];
        if (!empty($m[2])) $saida[] = ['rotulo' => 'Processo principal', 'valor' => $m[2]];
        $partes = [];
        foreach ($cab as $k => $v) {
            if ($k === 0) $saida[] = ['rotulo' => 'Classe', 'valor' => $v];
            elseif ($k === 1 && count($cab) >= 3) $saida[] = ['rotulo' => 'Assunto', 'valor' => $v];
            else $partes[] = ['rotulo' => 'Partes', 'polo' => '', 'nome' => $v];
        }
        $seg = array_map('trim', array_slice($seg, $i));
        return [trim(implode(' - ', $seg)), $saida, $partes];
    }

    private static function pareceTeor(string $s): bool
    {
        $s = trim($s);
        if (mb_strlen($s) > 160) return true;
        if (preg_match('/[a-zß-öø-ÿ]{3,}[.;]\s+\S/u', $s)) return true;
        return (bool) preg_match('/^(Vistos|Nota de cart|Certidão|Publique|Intim|Manifest|Fica|Defiro|Indefiro|Ante |Diante|Trata-se|Cuida-se|Ciência|Certifico|Ato |Nos termos|Considerando|Recebo|Cite|Tendo|Aguarde|Vista|Dê|Expeça|Junte|Proceda|Providencie|Remetam|Arquive|Cumpra|Homologo|Julgo|Concedo|Designo|Determino|Diga|Anote|Oficie|Conclusos|Em face|Em razão|Conforme|Nada|Comprove|Regularize|Apresente|Esclareça|Emende|Mantenho|Retifique|Encaminhe|Reitere|Despacho|Decisão|Sentença|Int\.)/u', $s);
    }

    private static function partesPorPolo(array $pl, array $partesTexto): array
    {
        $grupos = [];
        $dest = is_array($pl['destinatarios'] ?? null) ? $pl['destinatarios'] : [];
        $comPolo = array_filter($dest, fn ($d) => is_array($d) && in_array($d['polo'] ?? '', ['A', 'P'], true) && trim((string) ($d['nome'] ?? '')) !== '');
        if ($comPolo) {
            // O rótulo do texto (Reclamante, Requerido) dá nome ao polo quando existe.
            $nomeDoPolo = ['A' => 'Polo ativo', 'P' => 'Polo passivo'];
            $doTexto = [];
            foreach ($partesTexto as $p) {
                if ($p['polo'] !== '' && !isset($doTexto[$p['polo']]) && !str_starts_with($p['rotulo'], 'Polo')) $doTexto[$p['polo']] = $p['rotulo'];
            }
            $nomeDoPolo = array_replace($nomeDoPolo, $doTexto);
            foreach (['A', 'P'] as $polo) {
                $nomes = [];
                foreach ($comPolo as $d) if ($d['polo'] === $polo) $nomes[mb_strtoupper(trim($d['nome']))] = trim($d['nome']);
                if ($nomes) $grupos[] = ['rotulo' => $nomeDoPolo[$polo], 'nomes' => array_values($nomes)];
            }
            return $grupos;
        }
        foreach ($partesTexto as $p) {
            $r = $p['rotulo'];
            $achou = false;
            foreach ($grupos as &$g) if ($g['rotulo'] === $r) { $g['nomes'][] = $p['nome']; $achou = true; }
            unset($g);
            if (!$achou) $grupos[] = ['rotulo' => $r, 'nomes' => [$p['nome']]];
        }
        if (!$grupos) {
            $nomes = self::partesDoPayload($pl);
            if ($nomes) $grupos[] = ['rotulo' => 'Partes', 'nomes' => $nomes];
        }
        return $grupos;
    }

    // ── O teor ──────────────────────────────────────────────────────────────

    private static function reSecao(): string
    {
        $M = self::MAI; $m = self::MIN;
        $s = implode('|', array_map(fn ($r) => preg_quote($r, '/'), self::SECOES));
        // Em maiúsculas, sozinho, e seguido de frase (não de "ID f75b378", que é a referência a um documento).
        return "/(?<![$M$m])($s)(?![$M$m])(?=\\s*$|\\s*[:\\-–]?\\s+(?!I[Dd]\\b)[\"“$M\\d])/u";
    }

    /** O teor em seções; cada seção em parágrafos e com a assinatura à parte. */
    private static function blocos(string $t): array
    {
        $t = trim($t);
        if ($t === '') return [];
        // Comunicado do tribunal no fim ("migração para o eproc, solicita-se aos advogados..."):
        // não é o teor, vira um bloco próprio no fim.
        $aviso = [];
        $linhas = explode("\n", $t);
        while (count($linhas) > 1 && preg_match('/solicita-se\s+aos\s+(?:senhores\s+)?advogad|migra[çc][ãa]o\s+d[eo]\s+(?:todo\s+o\s+)?acervo|sistema\s+eproc|^\s*(?:AVISO|COMUNICADO)\b/iu', end($linhas))) {
            array_unshift($aviso, trim(array_pop($linhas)));
        }
        if ($aviso) $t = trim(implode("\n", $linhas));
        $pedacos = [];
        if (preg_match_all(self::reSecao(), $t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            $antes = trim(substr($t, 0, $mm[0][0][1]));
            if ($antes !== '') $pedacos[] = ['', $antes];
            foreach ($mm as $i => $x) {
                $ini = $x[0][1] + strlen($x[0][0]);
                $ate = isset($mm[$i + 1]) ? $mm[$i + 1][0][1] : strlen($t);
                $pedacos[] = [$x[1][0], ltrim(substr($t, $ini, $ate - $ini), " :-–\t\n")];
            }
        } else {
            $pedacos[] = ['', $t];
        }
        // Rótulo em caixa normal no começo ("Nota de cartório: ciência..."): vira o título.
        if ($pedacos && $pedacos[0][0] === ''
            && preg_match('/^(Nota de cart[óo]rio|Ato ordinat[óo]rio|Certid[ãa]o|Despacho|Decis[ãa]o|Senten[çc]a|Intima[çc][ãa]o)\s*:\s*/iu', $pedacos[0][1], $r)) {
            $corpo = substr($pedacos[0][1], strlen($r[0]));
            $pedacos[0] = [$r[1], mb_strtoupper(mb_substr($corpo, 0, 1)) . mb_substr($corpo, 1)];
        }
        if ($aviso) $pedacos[] = ['Aviso do tribunal', implode("\n", $aviso)];
        $blocos = [];
        foreach ($pedacos as [$tit, $corpo]) {
            [$corpo, $assin] = self::assinatura(trim($corpo));
            $par = self::paragrafos($corpo);
            if (!$par && !$assin && $tit === '') continue;
            $blocos[] = ['titulo' => $tit === '' ? '' : self::tituloDeSecao($tit), 'paragrafos' => $par, 'assinatura' => $assin];
        }
        return $blocos;
    }

    /** Separa do fim do bloco: "CIDADE/UF, 04 de novembro de 2026. NOME EM MAIÚSCULAS Cargo". */
    private static function assinatura(string $b): array
    {
        $M = self::MAI;
        $a = ['local' => '', 'nome' => '', 'cargo' => ''];
        $cargo = '(?:Ju[ií]z(?:a|\(a\))?(?:\s+(?:Federal|do Trabalho|de Direito|Substitut[oa]|Titular|Auxiliar|Convocad[oa]|Relator(?:a)?|da\s+\S+\s+Vara))*'
               . '|Desembargador(?:a|\(a\))?(?:\s+(?:Federal|Relator(?:a)?|do Trabalho))*|Diretor(?:a|\(a\))?\s+de\s+Secretaria'
               . '|Analista\s+Judici[áa]ri[oa]|T[ée]cnic[oa]\s+Judici[áa]ri[oa]|Servidor(?:a|\(a\))?|Escriv[ãa](?:o|\(ã\))?(?:\s+Judicial)?'
               . '|Assistente\s+de\s+Ju[ií]z|Secret[áa]ri[oa](?:\s+de\s+Audi[êe]ncias)?)';
        if (preg_match("/\\s+($cargo)\\.?\\s*$/u", $b, $m)) { $a['cargo'] = $m[1]; $b = rtrim(substr($b, 0, -strlen($m[0]))); }
        // O nome em maiúsculas é uma frase sozinha no fim (vem depois de ponto).
        if (preg_match("/(?:^|(?<=[.!?:]))\\s*([$M][$M'´`\\.]*(?:\\s+(?:DE|DA|DO|DOS|DAS|E|[$M][$M'´`\\.]*)){1,7})\\s*$/u", $b, $m)
            && mb_strlen($m[1]) >= 8 && !preg_match('/\/[A-Z]{2}$/', $m[1])) {
            $a['nome'] = trim($m[1]); $b = rtrim(substr($b, 0, -strlen($m[0])));
        }
        if (preg_match("/(?:^|(?<=[.!?:]))\\s*((?:[$M][$M\\s'\\-]+\\/[A-Z]{2}|[$M][\\p{L}\\s'\\-]{2,40}?)\\s*,\\s*(?:data abaixo|data da assinatura(?:\\s+eletr[ôo]nica)?|\\d{1,2}º?\\s+de\\s+[a-zç]+\\s+de\\s+\\d{4}|\\d{2}\\/\\d{2}\\/\\d{4}))\\.?\\s*$/u", $b, $m)) {
            $a['local'] = trim($m[1]); $b = rtrim(substr($b, 0, -strlen($m[0])));
        }
        if ($a['nome'] === '' && $a['local'] === '') {
            // Cargo sem nome não é assinatura: devolve ao texto.
            if ($a['cargo'] !== '') $b .= ' ' . $a['cargo'];
            return [$b, null];
        }
        return [$b, $a];
    }

    /** Uma frase por parágrafo; "Vistos" e os itens numerados também abrem linha. */
    private static function paragrafos(string $b): array
    {
        $b = trim($b);
        if ($b === '') return [];
        $M = self::MAI;
        $b = (string) preg_replace("/^(Vistos(?:,?\\s+etc)?\\.?)\\s+(?=[$M])/u", "$1\n", $b);
        // Abreviações não terminam frase.
        $b = (string) preg_replace('/(?<![\p{L}])(V|S|Sa|Sr|Sra|Srs|Dr|Dra|Drs|MM|Exmo|Exma|Ilmo|Ilma|art|arts|Art|Arts|n|nº|Nº|fls|fl|Fls|Id|inc|p|pág|pg|v|Min|Des|Rel|Proc|Av|Cia|Ltda|LTDA|Adv|Obs|ex|c|ss|cf|Cf|al|Prof|Profa|Of|cód|Cód|Tel|Jr|Res|Ref|ref|doc|docs|Doc|Mov|mov|[A-Z])\./u', "$1\x01", $b);
        $b = (string) preg_replace("/(?<=[.!?])\\s+(?=[\"“(]?[$M\\d])/u", "\n", $b);
        $b = (string) preg_replace("/(?<=\\s)((?:\\d{1,2}|[IVX]{1,5})\\s*[\\)\\-–]\\s+)(?=[$M])/u", "\n$1", $b);
        $out = [];
        foreach (preg_split('/\n+/', $b) as $p) {
            $p = trim(str_replace("\x01", '.', $p));
            if ($p !== '') $out[] = $p;
        }
        return $out;
    }

    // ── Auxiliares ──────────────────────────────────────────────────────────

    private static function payload(array|string|null $payload): array
    {
        if (is_array($payload)) return $payload;
        if (!is_string($payload) || $payload === '') return [];
        $d = json_decode($payload, true);
        return is_array($d) ? $d : [];
    }

    /** O texto como o Diário limpa: sem HTML, sem CSS colado, espaços normais. */
    public static function limpar(string $s): string
    {
        if ($s === '') return '';
        if (preg_match('/<\w/', $s)) {
            $s = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $s);
            $s = (string) preg_replace('#<\s*br\s*/?>#i', "\n", $s);
            $s = (string) preg_replace('#</(p|div|tr|li|h\d)>#i', "\n", $s);
            $s = (string) preg_replace('#</td>#i', ' ', $s);
            $s = strip_tags($s);
            $s = (string) preg_replace('/[@#.]?[a-zA-Z_][\w-]*\s*\{[^{}]*\}/', ' ', $s);
        }
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Endereço que chegou com "&amp;" sem o "&" e o ";" ("id=2amppagina=2"): o "amp"
        // entre o valor de um parâmetro e o nome do seguinte volta a ser "&". Só dentro de URL.
        $s = (string) preg_replace_callback('#https?://[^\s<>"]+#u', fn ($u) => (string) preg_replace('/(=[^=&\s]*?)&?amp;?(?=[a-z_]{2,}=)/i', '$1&', $u[0]), $s);
        $s = str_replace(["\r\n", "\r", "\u{00A0}", "\t"], ["\n", "\n", ' ', ' '], $s);
        $s = (string) preg_replace('/ {2,}/', ' ', $s);
        $s = (string) preg_replace('/ *\n */', "\n", $s);
        $s = (string) preg_replace('/\n{3,}/', "\n\n", $s);
        return trim($s);
    }

    private static function itensDeLista(string $s): array
    {
        $itens = [];
        foreach (preg_split('/\s*(?:\n|^)\s*[-–•]\s*|\n+|\s+[-–]\s+(?=[A-ZÀ-ÖØ-Þ])/u', trim($s)) as $x) {
            $x = trim($x, " \t\n-–•;,");
            if ($x !== '') $itens[mb_strtoupper($x)] = $x;
        }
        return array_values($itens);
    }

    private static function juntarAdvogados(array ...$listas): array
    {
        $vistos = []; $out = [];
        foreach ($listas as $lista) {
            foreach ($lista as $a) {
                $oab = preg_replace('/\D/', '', (string) ($a['oab'] ?? ''));
                $uf  = mb_strtoupper(trim((string) ($a['uf'] ?? '')));
                $chave = $oab !== '' ? $uf . '-' . ltrim($oab, '0') : mb_strtoupper(trim((string) $a['nome']));
                if ($chave === '' || isset($vistos[$chave])) continue;
                $vistos[$chave] = true;
                $out[] = ['nome' => trim((string) $a['nome']), 'oab' => (string) ($a['oab'] ?? ''), 'uf' => $uf];
            }
        }
        return $out;
    }

    /** "ADV: FULANO" e "Advogado: FULANO" deixam o rótulo grudado no nome; tira. */
    private static function semRotuloNoNome(string $n): string
    {
        $n = (string) preg_replace('/^(?:ADVS?|ADVOGAD[OA]S?|E|DR|DRA)\.?\s*:?\s+/u', '', $n);
        return trim($n, " ,;-–");
    }

    private static function temRotulo(array $cab, array $rotulos): bool
    {
        foreach ($cab as $c) if (in_array($c['rotulo'], $rotulos, true)) return true;
        return false;
    }

    /** "AçãO TRABALHISTA - RITO ORDINáRIO" → "Ação Trabalhista - Rito Ordinário". */
    public static function rotuloBonito(string $s): string
    {
        $s = trim((string) preg_replace('/\s+/', ' ', $s));
        if ($s === '') return '';
        $miudas = ['de', 'da', 'do', 'das', 'dos', 'e', 'em', 'por', 'para', 'a', 'o', 'com', 'ou', 'na', 'no'];
        $pal = explode(' ', mb_strtolower($s));
        foreach ($pal as $i => $p) {
            if ($i > 0 && in_array($p, $miudas, true)) continue;
            $pal[$i] = mb_strtoupper(mb_substr($p, 0, 1)) . mb_substr($p, 1);
        }
        return implode(' ', $pal);
    }

    /** "ATO ORDINATÓRIO" → "Ato ordinatório". */
    private static function tituloDeSecao(string $s): string
    {
        $s = mb_strtolower($s);
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }
}
