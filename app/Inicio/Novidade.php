<?php
namespace App\Inicio;

/**
 * O aviso de "atualização nova" que aparece uma vez para quem entra no sistema
 * (10/11/2026). Aqui mora só o CONTEÚDO e a regra de quem vê o quê; o desenho e a
 * animação estão em assets/novidade.css e assets/novidade.js, e o menu compartilhado
 * (includes/sidebar.php) entrega o resultado à tela em window.YURIS_NOVIDADE.
 *
 * Regras:
 *   - Cada pessoa vê o aviso uma vez por navegador, até o `ID` mudar. Para anunciar
 *     uma atualização nova, troque o `ID` e a lista em `ITENS`: quem já tinha visto o
 *     anterior vê de novo.
 *   - Cada item só aparece para quem tem a permissão da tela que ele anuncia
 *     (o mesmo critério do menu), para ninguém ler sobre uma tela que não enxerga.
 *     O Financeiro some também para o vendedor da edição CRM, que não o vê.
 *   - Itens do jurídico (processos, publicações) não aparecem na edição CRM.
 *   - Sem nenhum item para a pessoa, o aviso não aparece.
 *   - `?novidade=1` na URL abre o aviso de novo, mesmo já visto (para prévia).
 *
 * Os textos não usam travessão e falam com a pessoa (português do Brasil).
 */
class Novidade
{
    /** Muda a cada atualização anunciada. Entra na chave guardada no navegador. */
    public const ID = '2026-11-10-financeiro';

    public const TITULO = 'Atualização nova';
    public const SUBTITULO = 'Veja o que mudou no sistema';

    /**
     * perm: chave da permissão da tela (null = todos). yuris: só no jurídico.
     * financeiro: some para o vendedor da edição CRM.
     * texto_crm: o texto na edição CRM, quando o de sempre fala de algo que ela não tem (processo).
     * icone: nome do desenho em assets/novidade.js.
     */
    public const ITENS = [
        [
            'icone' => 'moeda', 'perm' => 'financas', 'financeiro' => true,
            'titulo' => 'Parcelas e baixa no Financeiro',
            'texto' => 'Parcele por cliente e processo, dê baixa com juros e observação e veja de onde vem cada valor no DRE.',
            'texto_crm' => 'Parcele por cliente, dê baixa com juros e observação e veja de onde vem cada valor no DRE.',
        ],
        [
            'icone' => 'grafico', 'perm' => 'financas', 'financeiro' => true,
            'titulo' => 'Projeção de recebimentos',
            'texto' => 'Uma aba nova mostra o que entra neste mês, no próximo e o saldo futuro total.',
        ],
        [
            'icone' => 'balanca', 'perm' => 'processos', 'yuris' => true,
            'titulo' => 'Valores do processo',
            'texto' => 'Valor da causa, valor projetado e resultado. O resultado vira receita no Financeiro, sem digitar de novo.',
        ],
        [
            'icone' => 'pessoa', 'perm' => 'clientes',
            'titulo' => 'Responsável no cliente PJ',
            'texto' => 'Vincule a Pessoa Física responsável a uma empresa, buscando por nome ou CPF, ou cadastrando na hora.',
        ],
        [
            'icone' => 'publicacao', 'perm' => 'intimacoes', 'yuris' => true,
            'titulo' => 'Publicações mais claras',
            'texto' => 'Marcou como lida, saiu de Não lidas. E dá para vincular ao processo sem criar prazo.',
        ],
    ];

    /**
     * O aviso para esta pessoa, ou null quando não há nada para ela.
     *
     * @param callable(string):bool $pode      diz se a pessoa tem a permissão da tela
     * @param bool                  $crm       a conta é da edição CRM (sem jurídico)
     * @param bool                  $vendedor  é o vendedor da edição CRM
     * @return array{id:string,titulo:string,subtitulo:string,itens:array<int,array{icone:string,titulo:string,texto:string}>}|null
     */
    public static function para(callable $pode, bool $crm = false, bool $vendedor = false): ?array
    {
        $itens = [];
        foreach (self::ITENS as $it) {
            if (!empty($it['yuris']) && $crm) continue;
            if (!empty($it['financeiro']) && $vendedor) continue;
            if (!empty($it['perm']) && !$pode((string) $it['perm'])) continue;
            $itens[] = ['icone' => $it['icone'], 'titulo' => $it['titulo'],
                       'texto' => ($crm && !empty($it['texto_crm'])) ? $it['texto_crm'] : $it['texto']];
        }
        if (!$itens) return null;
        return ['id' => self::ID, 'titulo' => self::TITULO, 'subtitulo' => self::SUBTITULO, 'itens' => $itens];
    }
}
