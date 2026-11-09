<?php
/**
 * A publicação do Diário em tópicos (App\Processos\PublicacaoEstrutura), 07/11/2026.
 *
 * Regra pura, não precisa do banco. Os textos seguem os formatos reais do DJEN
 * (TRT e TJSP) e da AASP, com nomes fictícios.
 *
 *   php scripts/tests/publicacao_estrutura_test.php
 */
require_once __DIR__ . '/../../app/bootstrap.php';

use App\Processos\PublicacaoEstrutura as PE;

$ok = 0; $falhas = 0;
function confere(string $nome, bool $cond, string $detalhe = ''): void
{
    global $ok, $falhas;
    if ($cond) { $ok++; return; }
    $falhas++;
    echo "FALHOU: $nome" . ($detalhe !== '' ? "\n   $detalhe" : '') . "\n";
}
function titulos(array $e): array { return array_map(fn ($b) => $b['titulo'], $e['blocos']); }
function bloco(array $e, string $t): ?array { foreach ($e['blocos'] as $b) if ($b['titulo'] === $t) return $b; return null; }

// ── 1. TRT (DJEN): timbre, partes com rótulo, seções, assinaturas, intimados ──
$trt = 'PODER JUDICIÁRIO JUSTIÇA DO TRABALHO TRIBUNAL REGIONAL DO TRABALHO DA 2ª REGIÃO 7ª VARA DO TRABALHO DE SÃO BERNARDO DO CAMPO '
     . 'ATOrd 1000944-23.2024.5.02.0467 RECLAMANTE: MARIA DAS DORES TESTE RECLAMADO: CLINICA EXEMPLO ESPECIALIZADA S/C LTDA '
     . 'INTIMAÇÃO Fica V. Sa. intimado para tomar ciência do Despacho ID f75b378 proferido nos autos. '
     . 'CONCLUSÃO Nesta data, faço o feito concluso ao(a) MM(a) Juiz(a) da 7ª Vara do Trabalho de São Bernardo do Campo/SP. '
     . 'SAO BERNARDO DO CAMPO/SP, data abaixo. ANA PAULA SERVIDORA DESPACHO Vistos Cumpra-se o v. Acórdão (Id c062ede) '
     . 'Fica deferido à parte autora o prazo de 10 (dez) dias para emenda à petição inicial. Na sequência, concede-se desde já '
     . 'à reclamada o prazo de 5 (cinco) dias para manifestação. Intimem-se. SAO BERNARDO DO CAMPO/SP, 04 de novembro de 2026. '
     . "JOSE FICTICIO JUIZ Juiz do Trabalho Substituto\n\nIntimado(s) / Citado(s)\n - MARIA DAS DORES TESTE";
$payload = [
    'nomeClasse' => 'AçãO TRABALHISTA - RITO ORDINáRIO', 'tipoDocumento' => 'Intimação',
    'link' => 'https://pje.trt2.jus.br/pjekz/validacao/123?instancia=1',
    'destinatarios' => [['nome' => 'CLINICA EXEMPLO ESPECIALIZADA S/C LTDA', 'polo' => 'P'], ['nome' => 'MARIA DAS DORES TESTE', 'polo' => 'A']],
    'destinatarioadvogados' => [
        ['advogado' => ['nome' => 'CARLOS ADVOGADO TESTE', 'numero_oab' => '346592', 'uf_oab' => 'SP']],
        ['advogado' => ['nome' => 'BEATRIZ PATRONA EXEMPLO', 'numero_oab' => '68809', 'uf_oab' => 'SP']],
        ['advogado' => ['nome' => 'CARLOS ADVOGADO TESTE', 'numero_oab' => '346592', 'uf_oab' => 'SP']],
    ],
];
$e = PE::estruturar($trt, json_encode($payload));
confere('trt: timbre separado', str_starts_with($e['preambulo'], 'PODER JUDICIÁRIO') && str_contains($e['preambulo'], '1000944-23.2024.5.02.0467'), $e['preambulo']);
confere('trt: seções na ordem', titulos($e) === ['Intimação', 'Conclusão', 'Despacho'], json_encode(titulos($e), JSON_UNESCAPED_UNICODE));
confere('trt: intimação com uma frase', (bloco($e, 'Intimação')['paragrafos'] ?? []) === ['Fica V. Sa. intimado para tomar ciência do Despacho ID f75b378 proferido nos autos.'], json_encode(bloco($e, 'Intimação'), JSON_UNESCAPED_UNICODE));
$conc = bloco($e, 'Conclusão');
confere('trt: conclusão assinada pela servidora', ($conc['assinatura']['nome'] ?? '') === 'ANA PAULA SERVIDORA' && ($conc['assinatura']['local'] ?? '') === 'SAO BERNARDO DO CAMPO/SP, data abaixo', json_encode($conc, JSON_UNESCAPED_UNICODE));
$desp = bloco($e, 'Despacho');
confere('trt: despacho abre com "Vistos" em linha própria', ($desp['paragrafos'][0] ?? '') === 'Vistos', json_encode($desp['paragrafos'] ?? [], JSON_UNESCAPED_UNICODE));
confere('trt: "v. Acórdão" não quebra a frase', in_array('Cumpra-se o v. Acórdão (Id c062ede) Fica deferido à parte autora o prazo de 10 (dez) dias para emenda à petição inicial.', $desp['paragrafos'] ?? [], true), json_encode($desp['paragrafos'] ?? [], JSON_UNESCAPED_UNICODE));
confere('trt: "Intimem-se." no próprio parágrafo', end($desp['paragrafos']) === 'Intimem-se.');
confere('trt: juiz, cargo e local na assinatura', ($desp['assinatura']['nome'] ?? '') === 'JOSE FICTICIO JUIZ' && ($desp['assinatura']['cargo'] ?? '') === 'Juiz do Trabalho Substituto' && str_contains($desp['assinatura']['local'] ?? '', '04 de novembro de 2026'), json_encode($desp['assinatura'] ?? null, JSON_UNESCAPED_UNICODE));
confere('trt: intimados do fim', $e['intimados'] === ['MARIA DAS DORES TESTE'], json_encode($e['intimados'], JSON_UNESCAPED_UNICODE));
confere('trt: advogados sem repetir, com OAB e UF', $e['advogados'] === [['nome' => 'CARLOS ADVOGADO TESTE', 'oab' => '346592', 'uf' => 'SP'], ['nome' => 'BEATRIZ PATRONA EXEMPLO', 'oab' => '68809', 'uf' => 'SP']], json_encode($e['advogados'], JSON_UNESCAPED_UNICODE));
confere('trt: partes pelo polo, com o rótulo do texto', $e['partes'] === [['rotulo' => 'Reclamante', 'nomes' => ['MARIA DAS DORES TESTE']], ['rotulo' => 'Reclamado', 'nomes' => ['CLINICA EXEMPLO ESPECIALIZADA S/C LTDA']]], json_encode($e['partes'], JSON_UNESCAPED_UNICODE));
confere('trt: classe legível', ($e['cabecalho'][0] ?? null) === ['rotulo' => 'Classe', 'valor' => 'Ação Trabalhista - Rito Ordinário'], json_encode($e['cabecalho'], JSON_UNESCAPED_UNICODE));
confere('trt: documento no cabeçalho', in_array(['rotulo' => 'Documento', 'valor' => 'Intimação'], $e['cabecalho'], true));
confere('trt: link do tribunal', $e['link'] === 'https://pje.trt2.jus.br/pjekz/validacao/123?instancia=1');
confere('trt: nada do cabeçalho no corpo', !str_contains(json_encode($e['blocos'], JSON_UNESCAPED_UNICODE), 'RECLAMANTE'));

// Sem payload, as partes saem pelo rótulo do texto.
$e2 = PE::estruturar($trt);
confere('trt sem payload: partes pelo rótulo', $e2['partes'] === [['rotulo' => 'Reclamante', 'nomes' => ['MARIA DAS DORES TESTE']], ['rotulo' => 'Reclamado', 'nomes' => ['CLINICA EXEMPLO ESPECIALIZADA S/C LTDA']]], json_encode($e2['partes'], JSON_UNESCAPED_UNICODE));
confere('trt sem payload: link só https do payload', $e2['link'] === '');

// ── 2. TJSP: "Processo NNNN - Classe - Assunto - Partes - teor - ADV: ..." ──
$tjsp = 'Processo 1001234-56.2024.8.26.0100 - Procedimento Comum Cível - Indenização por Dano Moral - João Exemplo da Silva - Banco Fictício S.A. - '
      . 'Vistos. Manifeste-se a parte autora sobre a contestação, no prazo de 15 dias. Após, conclusos. Int. - '
      . 'ADV: CARLOS ADVOGADO TESTE (OAB 346592/SP), FERNANDA DEFENSORA (OAB 123.456/SP), LUIZ CONSULTOR (OAB/RJ 98765)';
$e = PE::estruturar($tjsp);
confere('tjsp: processo, classe e assunto', array_slice($e['cabecalho'], 0, 3) === [['rotulo' => 'Processo', 'valor' => '1001234-56.2024.8.26.0100'], ['rotulo' => 'Classe', 'valor' => 'Procedimento Comum Cível'], ['rotulo' => 'Assunto', 'valor' => 'Indenização por Dano Moral']], json_encode($e['cabecalho'], JSON_UNESCAPED_UNICODE));
confere('tjsp: partes', $e['partes'] === [['rotulo' => 'Partes', 'nomes' => ['João Exemplo da Silva', 'Banco Fictício S.A.']]], json_encode($e['partes'], JSON_UNESCAPED_UNICODE));
confere('tjsp: três advogados, OAB sem ponto', $e['advogados'] === [['nome' => 'CARLOS ADVOGADO TESTE', 'oab' => '346592', 'uf' => 'SP'], ['nome' => 'FERNANDA DEFENSORA', 'oab' => '123456', 'uf' => 'SP'], ['nome' => 'LUIZ CONSULTOR', 'oab' => '98765', 'uf' => 'RJ']], json_encode($e['advogados'], JSON_UNESCAPED_UNICODE));
$par = $e['blocos'][0]['paragrafos'] ?? [];
confere('tjsp: teor em parágrafos', $par === ['Vistos.', 'Manifeste-se a parte autora sobre a contestação, no prazo de 15 dias.', 'Após, conclusos.', 'Int.'], json_encode($par, JSON_UNESCAPED_UNICODE));
confere('tjsp: "ADV:" fora do corpo', !str_contains(json_encode($e['blocos'], JSON_UNESCAPED_UNICODE), 'ADV'));

// ── 3. Em linhas (TJSP digital) ─────────────────────────────────────────────
$linhas = "Processo Digital nº: 1009999-11.2025.8.26.0001\nClasse - Assunto: Execução de Título Extrajudicial - Cheque\n"
        . "Exequente: Empresa Credora Ltda\nExecutado: Pedro Devedor\nVistos.\nCite-se o executado para pagamento em 3 dias.";
$e = PE::estruturar($linhas);
confere('linhas: cabeçalho', array_slice($e['cabecalho'], 0, 2) === [['rotulo' => 'Processo', 'valor' => '1009999-11.2025.8.26.0001'], ['rotulo' => 'Classe e assunto', 'valor' => 'Execução de Título Extrajudicial - Cheque']], json_encode($e['cabecalho'], JSON_UNESCAPED_UNICODE));
confere('linhas: partes pelo rótulo', $e['partes'] === [['rotulo' => 'Exequente', 'nomes' => ['Empresa Credora Ltda']], ['rotulo' => 'Executado', 'nomes' => ['Pedro Devedor']]], json_encode($e['partes'], JSON_UNESCAPED_UNICODE));
confere('linhas: teor', ($e['blocos'][0]['paragrafos'] ?? []) === ['Vistos.', 'Cite-se o executado para pagamento em 3 dias.'], json_encode($e['blocos'], JSON_UNESCAPED_UNICODE));

// ── 4. Texto curto, sem nada para separar ───────────────────────────────────
$e = PE::estruturar('Manifeste-se a parte requerida sobre o laudo pericial apresentado, no prazo de 15 dias.');
confere('curto: um bloco, um parágrafo, sem título', count($e['blocos']) === 1 && $e['blocos'][0]['titulo'] === '' && count($e['blocos'][0]['paragrafos']) === 1 && $e['blocos'][0]['assinatura'] === null);
confere('curto: vazio no resto', $e['partes'] === [] && $e['advogados'] === [] && $e['preambulo'] === '' && $e['cabecalho'] === []);
confere('vazio não quebra', PE::estruturar('')['blocos'] === []);

// ── 5. HTML e itens numerados ───────────────────────────────────────────────
$e = PE::estruturar('<p>DECISÃO</p><p>Defiro a tutela. 1) Oficie-se ao banco. 2) Intime-se a ré.</p><style>.x{color:red}</style>');
confere('html: limpo e com a seção', titulos($e) === ['Decisão'], json_encode($e['blocos'], JSON_UNESCAPED_UNICODE));
confere('html: itens numerados em linhas', ($e['blocos'][0]['paragrafos'] ?? []) === ['Defiro a tutela.', '1) Oficie-se ao banco.', '2) Intime-se a ré.'], json_encode($e['blocos'][0]['paragrafos'] ?? [], JSON_UNESCAPED_UNICODE));

// ── 5b. TJSP com processo principal, "- -", nota de cartório e aviso do eproc (08/11/2026) ──
$e = PE::estruturar("Processo 0024389-46.2017.8.26.0554 (processo principal 1017231-88.2015.8.26.0554) - Cumprimento de sentença - "
    . "Responsabilidade do Fornecedor - Carlos Teste - - Maria Exemplo Silva - Empresa Fictícia Ltda e outro - Nota de cartório: ciência da "
    . "pesquisa Sisbajud realizada que restou INFRUTÍFERA, requerendo o que de direito no prazo de 5 dias.\nEm razão da necessidade de "
    . "migração de todo o acervo desta unidade para o sistema eproc, solicita-se aos advogados que providenciem seu cadastro imediato: "
    . "https://portal.tjsp.jus.br/eproc/Duvidas/Duvida?id=2amppagina=2");
confere('tjsp principal: cabeçalho', array_column($e['cabecalho'], 'rotulo') === ['Processo', 'Processo principal', 'Classe', 'Assunto'] && $e['cabecalho'][1]['valor'] === '1017231-88.2015.8.26.0554', json_encode($e['cabecalho'], JSON_UNESCAPED_UNICODE));
confere('tjsp principal: partes sem o campo vazio', $e['partes'] === [['rotulo' => 'Partes', 'nomes' => ['Carlos Teste', 'Maria Exemplo Silva', 'Empresa Fictícia Ltda e outro']]], json_encode($e['partes'], JSON_UNESCAPED_UNICODE));
confere('tjsp principal: nota de cartório vira título', titulos($e) === ['Nota de cartório', 'Aviso do tribunal'] && str_starts_with($e['blocos'][0]['paragrafos'][0] ?? '', 'Ciência da pesquisa'), json_encode($e['blocos'], JSON_UNESCAPED_UNICODE));
confere('tjsp principal: link do aviso consertado', str_ends_with($e['blocos'][1]['paragrafos'][0] ?? '', 'Duvida?id=2&pagina=2'), json_encode($e['blocos'][1] ?? null, JSON_UNESCAPED_UNICODE));
confere('link: "amp" só volta a "&" dentro de URL e entre parâmetros', PE::limpar('exemplo amp https://x.com.br/c?example=1&amp;b=2') === 'exemplo amp https://x.com.br/c?example=1&b=2');

// ── 6. Extratores do Diário (api/push/list.php delega para cá) ──────────────
confere('aasp: OAB no formato "NOME OAB SP-123"', PE::advogadosDoPayload(['textoPublicacao' => 'Advogado(s): CARLOS TESTE OAB SP-12345']) === [['nome' => 'CARLOS TESTE', 'oab' => '12345', 'uf' => 'SP']]);
confere('aasp: bloco Parte(s)', PE::partesDoPayload(['textoPublicacao' => "Parte(s): FULANO X\nBELTRANO Y Advogado(s): Z"]) === ['FULANO X BELTRANO Y']);
confere('payload inválido devolve vazio', PE::advogadosDoPayload('{quebrado') === [] && PE::partesDoPayload(null) === []);
confere('rótulo bonito', PE::rotuloBonito('PROCEDIMENTO COMUM CÍVEL') === 'Procedimento Comum Cível');
$listPhp = file_get_contents(__DIR__ . '/../../public/api/push/list.php');
confere('list.php delega à classe', str_contains($listPhp, "PublicacaoEstrutura::advogadosDoPayload") && str_contains($listPhp, "PublicacaoEstrutura::partesDoPayload"));

// A tela Publicações em tópicos (09/11/2026, "arrume a identação de todas na plataforma").
$tjsp = 'Processo 0002455-90.2021.8.26.0554 (processo principal 1028039-50.2018.8.26.0554) - Cumprimento de sentença - Alimentos - E.C.C.P. - - S.G.F.F.P. - G.F.P.F. - Juiz de Direito: Dr Rodrigo Augusto de Oliveira' . "\n\n" . 'Vistos. Fls. 1122/1123: providencie a Serventia o necessário. Int. Santo André, 06 de novembro de 2026. - ADV: EKETI DA COSTA TASCA (OAB 265288/SP), BRUNO CARREIRA FERREIRA (OAB 357838/SP)';
$e = PE::doItem(['conteudo' => $tjsp]);
$juiz = array_values(array_filter($e['cabecalho'], fn ($c) => $c['rotulo'] === 'Juiz'));
confere('TJSP: "Juiz de Direito:" vai para o cabeçalho, não fica no teor', ($juiz[0]['valor'] ?? '') === 'Dr Rodrigo Augusto de Oliveira' && !str_contains(json_encode($e['blocos'], JSON_UNESCAPED_UNICODE), 'Juiz de Direito'));
confere('TJSP: o teor sai em parágrafos e a assinatura à parte', count($e['blocos'][0]['paragrafos'] ?? []) >= 2 && ($e['blocos'][0]['assinatura']['local'] ?? '') === 'Santo André, 06 de novembro de 2026');
confere('TJSP: advogados um a um, com a OAB', count($e['advogados']) === 2 && $e['advogados'][1]['oab'] === '357838');
confere('doItem usa o resumo quando não há conteúdo', PE::doItem(['conteudo' => '', 'resumo' => 'Vistos. Intime-se.'])['blocos'] !== []);
foreach (['public/api/push/list.php', 'public/api/push/search.php', 'public/api/aasp/search.php'] as $f) {
    $src = (string) file_get_contents(__DIR__ . '/../../' . $f);
    $pE = strpos($src, "PublicacaoEstrutura::doItem(\$it)"); $pU = strpos($src, "unset(\$it['payload_original'])");
    confere("$f manda a estrutura (antes de apagar o payload)", $pE !== false && $pU !== false && $pE < $pU);
}
$intJs = (string) file_get_contents(__DIR__ . '/../../public/assets/intimacoes.js');
confere('a tela Publicações desenha a estrutura, e cai no texto quando não há', str_contains($intJs, '_renderEstrutura(it, procMask) {') && str_contains($intJs, 'const est   = this._renderEstrutura(it, procMask);') && str_contains($intJs, ': `<div class="int-pub-text" data-text-len='));

echo "publicacao_estrutura: $ok ok / $falhas falhas\n";
exit($falhas ? 1 : 0);
