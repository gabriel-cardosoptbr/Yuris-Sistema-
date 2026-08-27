<?php
namespace App\Core;

/**
 * UserOptions — renderiza listas de usuários em selects HTML
 * com agrupamento visual por conta (matriz/filial).
 *
 * Entrada: array de usuários no formato retornado por AccountContext::getAccessibleUsers()
 *   [['id'=>1,'nome'=>'Fulano','account_id'=>1,'account_nome'=>'Silvana','account_tipo'=>'matriz'], ...]
 *
 * Saída: string HTML pronta para colocar dentro de <select>.
 *
 * Regra de agrupamento:
 *  - Se houver apenas UMA conta na lista, renderiza <option> chapados (sem optgroup).
 *  - Se houver múltiplas contas, agrupa em <optgroup label="Matriz: ..."> e
 *    <optgroup label="Filial: ..."> para deixar claro de onde cada usuário vem.
 */
final class UserOptions
{
    /**
     * Gera <option>...<optgroup>...<option/></optgroup> a partir da lista.
     *
     * @param array  $users         lista retornada por getAccessibleUsers()
     * @param mixed  $selectedId    id selecionado (string/int/null)
     * @param string $placeholder   texto do option vazio inicial; null = sem placeholder
     * @return string HTML escapado (use diretamente dentro de <select>...</select>)
     */
    public static function renderGrouped(array $users, $selectedId = null, ?string $placeholder = '— Selecionar —'): string
    {
        $html = '';
        if ($placeholder !== null) {
            $html .= '<option value="">' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '</option>';
        }

        if (empty($users)) return $html;

        // Agrupa por account_id
        $groups = [];
        foreach ($users as $u) {
            $aid = (int)($u['account_id'] ?? 0);
            if (!isset($groups[$aid])) {
                $groups[$aid] = [
                    'label' => (string)($u['account_nome'] ?? 'Outros'),
                    'tipo'  => (string)($u['account_tipo'] ?? ''),
                    'users' => [],
                ];
            }
            $groups[$aid]['users'][] = $u;
        }

        // Conta única → sem optgroup (UI mais limpa)
        if (count($groups) <= 1) {
            foreach ($users as $u) {
                $html .= self::_optionHtml($u, $selectedId);
            }
            return $html;
        }

        // Múltiplas contas → optgroup (matriz primeiro, filiais depois alfabético)
        uasort($groups, function ($a, $b) {
            $ta = $a['tipo'] === 'matriz' ? 0 : 1;
            $tb = $b['tipo'] === 'matriz' ? 0 : 1;
            if ($ta !== $tb) return $ta - $tb;
            return strcasecmp($a['label'], $b['label']);
        });

        foreach ($groups as $g) {
            $prefix = $g['tipo'] === 'matriz' ? 'Matriz: ' : ($g['tipo'] === 'filial' ? 'Filial: ' : '');
            $labelEsc = htmlspecialchars($prefix . $g['label'], ENT_QUOTES, 'UTF-8');
            $html .= '<optgroup label="' . $labelEsc . '">';
            foreach ($g['users'] as $u) {
                $html .= self::_optionHtml($u, $selectedId);
            }
            $html .= '</optgroup>';
        }

        return $html;
    }

    private static function _optionHtml(array $u, $selectedId): string
    {
        $id   = (string)($u['id'] ?? '');
        $nome = (string)($u['nome'] ?? '');
        $sel  = ($selectedId !== null && (string)$selectedId === $id) ? ' selected' : '';
        return '<option value="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' . $sel . '>'
             . htmlspecialchars($nome, ENT_QUOTES, 'UTF-8') . '</option>';
    }
}
