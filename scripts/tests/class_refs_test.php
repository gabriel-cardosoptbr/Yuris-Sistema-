<?php
/**
 * class_refs_test.php - confere se TODO nome de classe citado no projeto
 * resolve para uma classe que existe de verdade.
 *
 * POR QUE ISTO EXISTE
 * Em 2026-08-27, ao reorganizar app/ por dominio, 28 referencias quebraram do
 * seguinte jeito: duas classes no mesmo namespace se enxergam sem `use`, entao
 * ao dividir o namespace o nome curto passou a apontar para o vazio.
 *
 * Nada disso e detectavel pelo que se costuma rodar:
 *   - php -l nao resolve nome de classe
 *   - carregar o arquivo tambem nao: type hint so resolve na CHAMADA
 *   - varredura HTTP sem sessao redireciona pro login antes da linha quebrar
 *     (deu 164/164 "sem fatal" com o sistema quebrado em 28 pontos)
 *
 * Este teste cobre ate o caminho de codigo que nenhuma requisicao executa,
 * porque e analise estatica com o tokenizer do proprio PHP (nao confunde
 * comentario nem string).
 *
 * Aplica as regras reais de resolucao do PHP:
 *   \X   -> X global
 *   X    -> o `use ...\X` se houver; senao NamespaceAtual\X
 *           (para CLASSE nao existe fallback para o global, so para funcao)
 *   em arquivo sem namespace, X -> X global
 *
 * Nao precisa de banco. Sai com codigo != 0 se achar problema.
 */
$raiz = strtr(dirname(__DIR__, 2), DIRECTORY_SEPARATOR, '/');

// 1) carrega tudo de app/ para saber o que existe
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/app'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        require_once $f->getPathname();
    }
}
$existe = [];
foreach (array_merge(get_declared_classes(), get_declared_interfaces(), get_declared_traits()) as $c) {
    $existe[strtolower($c)] = true;
}
// classes de extensao que podem estar desligadas nesta maquina mas existem em
// producao; o codigo as chama com barra e guardadas por class_exists
foreach (['ZipArchive', 'Redis', 'Imagick'] as $c) {
    $existe[strtolower($c)] = true;
}

// 2) percorre os arquivos do projeto
$ignorar = ['.git', 'node_modules', 'vendor', 'storage', '.vscode'];
$dir = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($raiz, RecursiveDirectoryIterator::SKIP_DOTS),
        function ($a) use ($ignorar) {
            return !($a->isDir() && in_array($a->getFilename(), $ignorar, true));
        }
    )
);

$problemas = [];
$conferidos = 0;

foreach ($dir as $f) {
    if (!$f->isFile() || $f->getExtension() !== 'php') {
        continue;
    }
    $caminho = str_replace('\\', '/', $f->getPathname());
    $rel = str_replace($raiz . '/', '', $caminho);
    $fonte = (string)file_get_contents($caminho);
    $tokens = @token_get_all($fonte);
    if (!$tokens) {
        continue;
    }
    // classes declaradas NO PROPRIO arquivo contam como existentes
    if (preg_match_all('/^\s*(?:final\s+|abstract\s+)*(?:class|interface|trait)\s+(\w+)/mi',
                       $fonte, $mm)) {
        foreach ($mm[1] as $c) {
            $existe[strtolower($c)] = true;
        }
    }

    $ns = '';
    $usos = [];           // alias minusculo => FQCN
    $n = count($tokens);

    // --- primeira passada: namespace e use ---
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            continue;
        }
        if ($t[0] === T_NAMESPACE) {
            $buf = '';
            for ($j = $i + 1; $j < $n; $j++) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{') break;
                if (is_array($tokens[$j]) && $tokens[$j][0] !== T_WHITESPACE) $buf .= $tokens[$j][1];
            }
            $ns = trim($buf, '\\');
        }
        if ($t[0] === T_USE) {
            // ignora "use" de closure  (function () use ($x))
            $k = $i - 1;
            while ($k >= 0 && is_array($tokens[$k]) && $tokens[$k][0] === T_WHITESPACE) $k--;
            if ($tokens[$k] === ')') continue;

            // monta preservando o T_AS como marcador explicito
            $fq = '';
            $alias = null;
            $viuAs = false;
            $ehFuncConst = false;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($tokens[$j] === ';' || $tokens[$j] === '{' || $tokens[$j] === ',') break;
                if (!is_array($tokens[$j])) continue;
                if ($tokens[$j][0] === T_WHITESPACE) continue;
                if ($tokens[$j][0] === T_AS) { $viuAs = true; continue; }
                if (in_array($tokens[$j][0], [T_FUNCTION, T_CONST], true)) { $ehFuncConst = true; break; }
                if ($viuAs) $alias = $tokens[$j][1];
                else $fq .= $tokens[$j][1];
            }
            if ($ehFuncConst || $fq === '') continue;
            if ($alias === null) {
                $partes = explode('\\', $fq);
                $alias = end($partes);
            }
            $usos[strtolower(trim($alias))] = trim($fq, '\\');
        }
    }

    // --- segunda passada: referencias a classe ---
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) continue;
        $id = null;

        // PHP 8: nome qualificado vem em tokens proprios
        $tiposNome = [T_STRING];
        if (defined('T_NAME_QUALIFIED')) $tiposNome[] = T_NAME_QUALIFIED;
        if (defined('T_NAME_FULLY_QUALIFIED')) $tiposNome[] = T_NAME_FULLY_QUALIFIED;
        if (!in_array($t[0], $tiposNome, true)) continue;

        $nome = $t[1];

        // contexto anterior relevante
        $p = $i - 1;
        while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) $p--;
        $antes = $tokens[$p] ?? null;

        // contexto seguinte
        $q = $i + 1;
        while ($q < $n && is_array($tokens[$q]) && $tokens[$q][0] === T_WHITESPACE) $q++;
        $depois = $tokens[$q] ?? null;

        $ehRef = false;
        if (is_array($antes) && in_array($antes[0], [T_NEW, T_INSTANCEOF, T_EXTENDS, T_IMPLEMENTS], true)) {
            $ehRef = true;
        }
        if (is_array($depois) && $depois[0] === T_DOUBLE_COLON) {
            $ehRef = true;
        }
        // catch (X $e)
        if (is_array($antes) && $antes[0] === T_CATCH) $ehRef = true;
        if ($antes === '(' ) {
            $r = $p - 1;
            while ($r >= 0 && is_array($tokens[$r]) && $tokens[$r][0] === T_WHITESPACE) $r--;
            if (is_array($tokens[$r]) && $tokens[$r][0] === T_CATCH) $ehRef = true;
        }

        if (!$ehRef) continue;

        // ignora palavras reservadas de contexto
        $baixo = strtolower($nome);
        if (in_array($baixo, ['self', 'static', 'parent', 'class', 'true', 'false', 'null'], true)) continue;

        // resolve
        if ($nome[0] === '\\') {
            $fqcn = ltrim($nome, '\\');                       // \X -> global
        } elseif (strpos($nome, '\\') !== false) {
            $primeiro = strtolower(explode('\\', $nome)[0]);   // A\B -> use A, ou ns\A\B
            if (isset($usos[$primeiro])) {
                $resto = substr($nome, strpos($nome, '\\'));
                $fqcn = $usos[$primeiro] . $resto;
            } else {
                $fqcn = ($ns ? $ns . '\\' : '') . $nome;
            }
        } elseif (isset($usos[$baixo])) {
            $fqcn = $usos[$baixo];
        } elseif ($ns) {
            $fqcn = $ns . '\\' . $nome;                        // SEM fallback global
        } else {
            $fqcn = $nome;
        }

        $conferidos++;
        if (!isset($existe[strtolower($fqcn)])) {
            $linha = $t[2];
            $problemas[] = ['arq' => $rel, 'linha' => $linha, 'citado' => $nome, 'resolve' => $fqcn];
        }
    }
}

echo "referencias de classe conferidas: $conferidos\n";
if (!$problemas) {
    echo "todas resolvem para uma classe existente.\n";
    exit(0);
}

// agrupa por FQCN nao resolvido
$porFqcn = [];
foreach ($problemas as $p) {
    $porFqcn[$p['resolve']][] = $p['arq'] . ':' . $p['linha'];
}
echo "\nNAO RESOLVEM (" . count($problemas) . " referencias, " . count($porFqcn) . " nomes):\n\n";
ksort($porFqcn);
foreach ($porFqcn as $fqcn => $onde) {
    echo "  $fqcn  (" . count($onde) . "x)\n";
    foreach (array_slice($onde, 0, 4) as $o) echo "      $o\n";
    if (count($onde) > 4) echo "      ... +" . (count($onde) - 4) . "\n";
}
exit(1);
