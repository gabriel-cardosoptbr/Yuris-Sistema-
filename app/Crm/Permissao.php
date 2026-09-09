<?php

namespace App\Crm;

/**
 * Permissao — quem pode mexer nos CATALOGOS da Fase 2.
 *
 * ---------------------------------------------------------------------------
 * DUAS COISAS DIFERENTES
 * ---------------------------------------------------------------------------
 * Aplicar uma etiqueta num cliente e preencher um campo personalizado sao uso
 * normal da ficha: quem abre a tela de Clientes ou de Prospeccao pode fazer.
 *
 * CRIAR a etiqueta, renomear, arquivar, definir que campo personalizado existe
 * na conta e de que tipo ele e, isso e configuracao: muda o que todo mundo ve.
 * Um estagiario que cria dez etiquetas parecidas estraga o catalogo do
 * escritorio inteiro, e um campo personalizado apagado por engano leva os
 * valores dele para o arquivo morto.
 *
 * ---------------------------------------------------------------------------
 * POR QUE UMA CHAVE COM PONTO
 * ---------------------------------------------------------------------------
 * O Yuris guarda permissao em `user_permissions.page`, uma linha por tela. A
 * chave 'crm.catalogos_gerenciar' tem ponto no nome, e nenhuma tela se chama
 * assim: e o mesmo truque da Fase 1 com 'prospeccao.converter_cliente'. A acao
 * ganha controle proprio sem migration e sem um segundo sistema de permissao
 * concorrendo com o que ja existe.
 *
 * ATENCAO, A ARMADILHA JA CONHECIDA: /api/users.php filtra os INSERTs por uma
 * whitelist ($_validPages). Chave que nao esta la e descartada EM SILENCIO, o
 * checkbox aparece marcado na tela e nao grava nada. Ao acrescentar chave nova,
 * acrescente nos dois lugares.
 */
final class Permissao
{
    /** Criar, renomear e arquivar etiqueta e campo personalizado. */
    public const CATALOGOS = 'crm.catalogos_gerenciar';

    /**
     * Owner e admin passam pelo curinga '*' que o AuthController grava na
     * sessao. Os outros precisam da chave explicita.
     *
     * Le de $_SESSION porque e onde a lista mora depois do login. Consequencia
     * que vale dizer em voz alta: quem JA estava logado quando a permissao foi
     * concedida so a enxerga no proximo login.
     */
    public static function podeGerenciarCatalogos(): bool
    {
        $perms = (array) ($_SESSION['user_permissions'] ?? []);
        return in_array('*', $perms, true) || in_array(self::CATALOGOS, $perms, true);
    }
}
