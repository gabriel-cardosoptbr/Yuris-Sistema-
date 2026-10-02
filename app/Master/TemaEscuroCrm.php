<?php
namespace App\Master;

/**
 * Tema escuro da edição CRM (Fleetiflow, Inovaize, Via Autodoc), gerado a partir
 * do desenho claro.
 *
 * O desenho da edição CRM foi escrito só para o tema claro: toda regra leva o
 * prefixo html[data-theme="light"], porque o yuris-theme.css é !important em
 * tudo e só perde por especificidade. No tema escuro (o atributo some), nada
 * disso valia, e sobrava o estilo antigo do Yuris por cima do HTML novo: grupos
 * do menu como caixas azuis, a engrenagem de Configurações do tamanho de meia
 * barra, o nome da marca sem cor (relato do cliente, 02/10/2026).
 *
 * Em vez de manter um segundo desenho à mão, que se desencontraria do primeiro
 * a cada mudança, este conversor faz a cópia escura do mesmo CSS:
 *
 *   1. só entram as regras que têm o prefixo do tema claro (as outras, como
 *      :root com as cores da marca, já valem nos dois temas);
 *   2. html[data-theme="light"] vira html:not([data-theme="light"]);
 *   3. cada cor clara vira a escura equivalente (PALETA), e os tons escuros da
 *      marca, usados como texto sobre fundo claro, viram o tom claro dela.
 *
 * Layout, tamanhos e ícones ficam idênticos nos dois temas.
 */
final class TemaEscuroCrm
{
    public const PREFIXO_CLARO = 'html[data-theme="light"]';
    public const PREFIXO_ESCURO = 'html:not([data-theme="light"])';

    /**
     * Cor do tema claro => cor do tema escuro. Chaves em minúsculas, sem espaço.
     * Fundo branco vira superfície escura, cinzas de texto viram cinzas claros
     * com o mesmo peso relativo, e as bordas em azul-ardósia translúcido viram
     * branco translúcido.
     */
    public const PALETA = [
        '#ffffff' => '#121821', '#fff' => '#121821',
        '#f6f7f9' => '#0b1017', '#f1f1f2' => '#1c2430', '#f3f4f6' => '#1c2430', '#f8fafc' => '#151c26',
        '#3d3d3d' => '#e7ebf1', '#1f2937' => '#e7ebf1', '#0f172a' => '#eef1f5', '#111827' => '#eef1f5',
        '#575757' => '#c5ccd6', '#676767' => '#a3adba', '#767676' => '#8c97a6', '#9ca3af' => '#7d8796',
        '#b00000' => '#ff8a8a', '#fff1f1' => '#3a1a1d',
        '#f1f3f6' => '#1c2430', '#f4f6f9' => '#0e141c', '#eef2f7' => '#1a222d', '#e6e9ee' => '#232c38',
        '#e5e7eb' => '#2a3341', '#e2e8f0' => '#2a3341', '#cbd5e1' => '#3a4452', '#d1d5db' => '#3a4452', '#f9fafb' => '#151c26',
        '#475569' => '#b4bdc9', '#64748b' => '#9aa6b5', '#94a3b8' => '#7f8a99', '#9aa3b2' => '#7d8796', '#a0a7b4' => '#7d8796',
        '#b91c1c' => '#ff8a8a', '#fee2e2' => 'rgba(255,138,138,0.14)', '#b45309' => '#f7c24e', '#fff4d6' => 'rgba(247,194,78,0.14)',
        '#eef4ff' => 'rgba(59,130,246,0.16)', '#f1f5f9' => '#1c2430', '#fef2f2' => 'rgba(255,138,138,0.12)', '#dc2626' => '#ff8a8a',
        '#15803d' => '#5be38c', '#dcfce7' => 'rgba(91,227,140,0.14)', '#1d4ed8' => '#93b4ff', '#e8f1ff' => 'rgba(59,130,246,0.18)',
        'rgba(17,29,45,0.04)' => 'rgba(255,255,255,0.04)', 'rgba(17,29,45,0.06)' => 'rgba(255,255,255,0.06)',
        'rgba(17,29,45,0.08)' => 'rgba(255,255,255,0.08)', 'rgba(17,29,45,0.10)' => 'rgba(255,255,255,0.10)',
        'rgba(17,29,45,0.12)' => 'rgba(255,255,255,0.12)', 'rgba(17,29,45,0.16)' => 'rgba(255,255,255,0.14)',
        'rgba(17,29,45,0.18)' => 'rgba(255,255,255,0.16)', 'rgba(17,29,45,0.25)' => 'rgba(255,255,255,0.20)',
    ];

    /**
     * Tons da marca que, no claro, aparecem como TEXTO sobre fundo claro. No
     * escuro somem: viram o tom claro da marca. O fundo suave do item ativo fica
     * mais denso para continuar visível sobre superfície escura.
     */
    public const MARCA = [
        'var(--ff-marca-forte)'  => 'var(--ff-marca-clara)',
        'var(--ff-marca-escura)' => 'var(--ff-marca-media)',
        'rgba(var(--ff-marca-rgb),0.07)' => 'rgba(var(--ff-marca-rgb),0.22)',
        'rgba(var(--ff-marca-rgb),0.06)' => 'rgba(var(--ff-marca-rgb),0.20)',
        'rgba(var(--ff-marca-rgb),0.10)' => 'rgba(var(--ff-marca-rgb),0.26)',
        'var(--ff-marca-suave)' => 'rgba(var(--ff-marca-rgb),0.20)',
        'var(--ff-marca-media)' => 'rgba(var(--ff-marca-rgb),0.45)',
    ];

    /** CSS claro (com ou sem a tag <style>) => <style> com a cópia escura, ou '' sem regra clara. */
    public static function estilo(string $cssClaro): string
    {
        $escuro = self::converter($cssClaro);
        return $escuro === '' ? '' : "<style>/* tema escuro da edição CRM, gerado de TemaEscuroCrm */\n" . $escuro . "</style>\n";
    }

    /**
     * Converte o CSS claro na cópia escura (só o CSS, sem tag).
     *
     * $tambemSemPrefixo: também copia as regras SEM o prefixo do tema claro cujas
     * cores mudam (com o prefixo escuro na frente, que ganha por especificidade).
     * É para folhas como a das fichas, desenhadas para valer nos dois temas com
     * cores fixas. No menu lateral não se usa: lá o que não tem prefixo (as cores
     * da marca em :root) já serve aos dois temas.
     */
    public static function converter(string $css, bool $tambemSemPrefixo = false): string
    {
        $css = (string) preg_replace('~</?style[^>]*>~i', '', $css);
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $saida = '';
        foreach (self::regras($css) as [$cabeca, $corpo]) {
            if (str_starts_with(ltrim($cabeca), '@media') || str_starts_with(ltrim($cabeca), '@supports')) {
                $dentro = self::converter($corpo, $tambemSemPrefixo);
                if ($dentro !== '') $saida .= trim($cabeca) . "{\n" . $dentro . "}\n";
                continue;
            }
            if (str_starts_with(ltrim($cabeca), '@')) continue; // @keyframes etc: já valem nos dois temas
            $todos  = array_values(array_filter(array_map('trim', explode(',', $cabeca)), fn($s) => $s !== ''));
            $claros = array_values(array_filter($todos, fn($s) => str_contains($s, self::PREFIXO_CLARO)));
            $escuroCorpo = self::cores($corpo);
            if ($claros) {
                $seletores = array_map(fn($s) => str_replace(self::PREFIXO_CLARO, self::PREFIXO_ESCURO, $s), $claros);
            } elseif ($tambemSemPrefixo && $escuroCorpo !== $corpo && !preg_grep('/^(:root|html|body)\b/', $todos)) {
                $seletores = array_map(fn($s) => self::PREFIXO_ESCURO . ' ' . $s, $todos);
            } else {
                continue;
            }
            $saida .= implode(', ', $seletores) . '{' . $escuroCorpo . "}\n";
        }
        return $saida;
    }

    /** Troca as cores de um bloco de declarações. */
    public static function cores(string $corpo): string
    {
        // Tons da marca escritos com cor reserva (var(--ff-marca-forte, #013DF2)) contam igual.
        $corpo = (string) preg_replace('~var\(--ff-marca-(forte|escura|suave|media)\s*,\s*#[0-9a-fA-F]{3,6}\s*\)~', 'var(--ff-marca-$1)', $corpo);
        $corpo = str_ireplace(array_keys(self::MARCA), array_values(self::MARCA), $corpo);
        // A cor da marca como TEXTO, traço ou ícone (não como fundo) vira o tom claro dela:
        // azul-marinho de marca some sobre superfície escura.
        $corpo = (string) preg_replace('~(?<![\w-])(color|stroke|fill|caret-color)(\s*:\s*)var\(--ff-marca(\s*,\s*#[0-9a-fA-F]{3,6})?\)~i', '$1$2var(--ff-marca-clara)', $corpo);
        return (string) preg_replace_callback(
            '~#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b|rgba\(\s*17\s*,\s*29\s*,\s*45\s*,\s*[0-9.]+\s*\)~',
            function (array $m): string {
                $k = strtolower((string) preg_replace('/\s+/', '', $m[0]));
                if (preg_match('/^rgba\(17,29,45,(\.\d+)\)$/', $k, $mm)) $k = 'rgba(17,29,45,0' . $mm[1] . ')';
                if (isset(self::PALETA[$k])) return self::PALETA[$k];
                // Borda/sombra em ardósia translúcida fora da lista: vira branco com a mesma opacidade.
                if (preg_match('/^rgba\(17,29,45,([0-9.]+)\)$/', $k, $mm)) return 'rgba(255,255,255,' . $mm[1] . ')';
                return $m[0];
            },
            $corpo
        );
    }

    /**
     * Quebra o CSS em [cabeça, corpo] no nível de cima, respeitando chaves
     * aninhadas (@media). Não é um parser completo, e não precisa ser: o CSS de
     * entrada é o nosso.
     *
     * @return list<array{0:string,1:string}>
     */
    private static function regras(string $css): array
    {
        $out = []; $n = strlen($css); $i = 0;
        while ($i < $n) {
            $a = strpos($css, '{', $i);
            if ($a === false) break;
            $cabeca = substr($css, $i, $a - $i);
            $prof = 1; $j = $a + 1;
            while ($j < $n && $prof > 0) {
                if ($css[$j] === '{') $prof++;
                elseif ($css[$j] === '}') $prof--;
                $j++;
            }
            $out[] = [$cabeca, substr($css, $a + 1, $j - $a - 2)];
            $i = $j;
        }
        return $out;
    }
}
