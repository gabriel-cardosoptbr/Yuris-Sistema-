<?php

namespace App\Crm;

use App\Clientes\Cliente;
use App\Prospeccao\Card;

/**
 * Auditoria — um evento, dois destinos, um formato.
 *
 * ---------------------------------------------------------------------------
 * POR QUE ELA EXISTE
 * ---------------------------------------------------------------------------
 * O historico do Yuris mora em duas tabelas com formatos diferentes:
 *
 *   card_history      colunas: campo_alterado / valor_anterior / valor_novo
 *   clientes_history  JSON:    antes_json / depois_json
 *
 * A Fase 2 acrescenta quatro servicos (anexo, tag, campo personalizado,
 * interacao) e cada um deles age nos DOIS lados. Sem este ponto unico, cada
 * servico precisaria de um if para escolher a tabela e inventar o proprio
 * formato de linha. Oito lugares para divergir.
 *
 * Aqui a chamada e sempre a mesma:
 *
 *     Auditoria::registrar($alvo, $userId, 'anexo_adicionado', 'documento', null, 'RG.pdf');
 *
 * e o `$alvo` (a saida de Entidade::resolver) decide para onde vai.
 *
 * ---------------------------------------------------------------------------
 * O FORMATO, E POR QUE ELE IMPORTA
 * ---------------------------------------------------------------------------
 * `clientes_history` guarda um evento de campo como acao='updated' com os dois
 * lados em JSON. E assim que App\Core\Timeline sabe explodir uma linha em um
 * evento por campo. Um evento da Fase 2 NAO e 'updated': anexar documento nao e
 * alterar cadastro. Entao ele grava a acao propria e poe de/para no JSON com as
 * chaves 'de' e 'para', que a Timeline le como evento unico.
 *
 * As acoes usam prefixo de bloco (`anexo_`, `tag_`, `campo_`, `interacao_`) de
 * proposito: App\Core\Timeline::categoria() classifica por substring, e
 * `anexo_adicionado` cai sozinho na categoria 'documentos' que ja existia
 * reservada e vazia desde a Fase 1.
 *
 * ---------------------------------------------------------------------------
 * FALHA EM SILENCIO
 * ---------------------------------------------------------------------------
 * Igual a Card::logEvento e a Cliente::_logHistory: historico nao derruba a
 * operacao que ele esta descrevendo. Um anexo salvo com o log falhando e melhor
 * que um anexo perdido porque o log falhou.
 */
final class Auditoria
{
    /**
     * @param array{entidade:string,id:int,account_id:int,titulo:string} $alvo
     *        saida de Entidade::resolver()
     * @param string $acao   crua; o front traduz com Yuris.translateAuditAcao
     * @param mixed  $de     valor anterior, ou null quando o evento nao tem um
     * @param mixed  $para   valor novo
     */
    public static function registrar(
        array $alvo,
        ?int $userId,
        string $acao,
        ?string $campo = null,
        $de = null,
        $para = null
    ): void {
        if ($alvo['entidade'] === Entidade::CARD) {
            Card::logEvento($alvo['id'], $userId, $acao, $campo, $de, $para);
            return;
        }

        // Cliente: os dois lados vao em JSON. Só as chaves que existem, para a
        // Timeline nao renderizar "de: (vazio)" em evento que nao tem "de".
        $detalhes = [];
        if ($campo !== null) {
            $detalhes['campo'] = $campo;
        }
        if ($de !== null && $de !== '') {
            $detalhes['de'] = (string) $de;
        }
        if ($para !== null && $para !== '') {
            $detalhes['para'] = (string) $para;
        }

        Cliente::registrarEvento(
            $alvo['id'],
            $alvo['account_id'],
            $userId,
            $acao,
            $detalhes === [] ? null : $detalhes
        );
    }
}
