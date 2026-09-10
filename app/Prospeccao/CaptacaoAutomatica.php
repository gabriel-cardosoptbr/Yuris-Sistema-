<?php

namespace App\Prospeccao;

use App\Core\Database;

/**
 * CaptacaoAutomatica — quem manda mensagem no WhatsApp já vira card na prospecção.
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA RELATADO
 * ---------------------------------------------------------------------------
 * "Esse negócio de vincular está confundindo totalmente elas, já expliquei
 * várias vezes mas elas esperam algo automático."
 *
 * E a expectativa está certa. Pedir que a pessoa aperte "Vincular" depois de
 * cada conversa nova é empurrar trabalho de sistema para quem está atendendo. O
 * lead que chega no WhatsApp já é um lead: o funil devia saber disso sozinho.
 *
 * ---------------------------------------------------------------------------
 * O QUE ELE FAZ
 * ---------------------------------------------------------------------------
 * Na primeira mensagem RECEBIDA de uma conversa individual ainda sem vínculo,
 * cria o card com nome e telefone (o que a advogada apontou como o essencial) e
 * JÁ AMARRA a conversa nele. Os dois passos juntos: criar sem vincular deixaria
 * o "Vincular" existindo do mesmo jeito.
 *
 * ---------------------------------------------------------------------------
 * POR QUE NASCE DESLIGADO
 * ---------------------------------------------------------------------------
 * A lista de conversas de um escritório real tem banco, cobrança, entregador e
 * grupo de condomínio. Ligar isso para todos os inquilinos de uma vez encheria o
 * funil de gente que não é lead, e um funil poluído é pior que um funil manual:
 * ninguém confia mais nele.
 *
 * Então é opção por conta (`whatsapp_settings.auto_card_prospeccao`), desligada
 * por padrão, ligada em quem pedir. Nenhuma migration: aquela tabela já é
 * chave/valor por conta.
 *
 * ---------------------------------------------------------------------------
 * AS TRAVAS, E O QUE CADA UMA EVITA
 * ---------------------------------------------------------------------------
 *  · só INBOUND e só individual   grupo e transmissão não são lead
 *  · só conversa SEM vínculo      não atropela o que já foi ligado à mão
 *  · telefone já é card?          não duplica o funil a cada mensagem nova
 *  · telefone já é cliente?       quem já é cliente não volta para o funil
 *  · conta tem coluna no funil?   card sem coluna some da tela (bug conhecido)
 *
 * A trava do telefone é a que mais importa: sem ela, CADA mensagem recebida
 * criaria um card, e uma conversa de vinte mensagens viraria vinte cards.
 *
 * ---------------------------------------------------------------------------
 * NUNCA DERRUBA O WEBHOOK
 * ---------------------------------------------------------------------------
 * Tudo dentro de try/catch, como o resto do webhook. Perder o card é ruim;
 * perder a MENSAGEM porque a criação do card falhou seria muito pior.
 */
final class CaptacaoAutomatica
{
    /** Chave em `whatsapp_settings`, por conta. */
    public const CHAVE = 'auto_card_prospeccao';

    /** A conta tem a captação automática ligada? */
    public static function ligada(int $accountId): bool
    {
        if ($accountId <= 0) {
            return false;
        }
        try {
            $st = Database::getConnection()->prepare(
                'SELECT config_value FROM whatsapp_settings
                  WHERE account_id = ? AND config_key = ? LIMIT 1'
            );
            $st->execute([$accountId, self::CHAVE]);
            $v = $st->fetchColumn();
            return $v !== false && (string) $v === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Liga ou desliga para a conta. */
    public static function definir(int $accountId, bool $ligar): bool
    {
        try {
            $pdo = Database::getConnection();
            $st  = $pdo->prepare(
                'SELECT id FROM whatsapp_settings WHERE account_id = ? AND config_key = ? LIMIT 1'
            );
            $st->execute([$accountId, self::CHAVE]);
            $id = $st->fetchColumn();

            if ($id) {
                $pdo->prepare('UPDATE whatsapp_settings SET config_value = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([$ligar ? '1' : '0', (int) $id]);
            } else {
                $pdo->prepare(
                    'INSERT INTO whatsapp_settings (account_id, config_key, config_value, updated_at)
                     VALUES (?, ?, ?, NOW())'
                )->execute([$accountId, self::CHAVE, $ligar ? '1' : '0']);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Tenta criar o card a partir de uma mensagem recebida.
     *
     * @param  string      $remoteJid  JID da conversa (já resolvido de @lid quando dá)
     * @param  string|null $pushName   nome que o WhatsApp mandou, pode ser null
     * @return int|null    id do card criado, ou null quando alguma trava barrou
     */
    public static function daMensagem(
        int $accountId,
        int $instanceId,
        string $remoteJid,
        ?string $pushName
    ): ?int {
        try {
            if (!self::ligada($accountId)) {
                return null;
            }

            // Grupo, transmissão e newsletter não são lead.
            if (str_contains($remoteJid, '@g.us')
                || str_contains($remoteJid, '@broadcast')
                || str_contains($remoteJid, '@newsletter')) {
                return null;
            }

            $telefone = preg_replace('/[^0-9]/', '', explode('@', $remoteJid)[0]);
            // Telefone brasileiro discável tem 10 a 13 dígitos com DDI/DDD. Um
            // @lid não resolvido cai fora daqui, e é o comportamento certo: sem
            // telefone o card não serviria para ligar de volta.
            if ($telefone === '' || strlen($telefone) < 10 || strlen($telefone) > 13) {
                return null;
            }

            $pdo = Database::getConnection();

            // A conversa já foi ligada a alguma coisa? Então alguém já cuidou dela.
            $st = $pdo->prepare(
                'SELECT linked_card_id, linked_processo_id, linked_user_id, contato_id
                   FROM whatsapp_chats
                  WHERE instance_id = ? AND remote_jid = ? LIMIT 1'
            );
            $st->execute([$instanceId, $remoteJid]);
            $chat = $st->fetch(\PDO::FETCH_ASSOC);
            if ($chat && (
                !empty($chat['linked_card_id']) || !empty($chat['linked_processo_id'])
                || !empty($chat['linked_user_id']) || !empty($chat['contato_id'])
            )) {
                return null;
            }

            if (self::telefoneJaConhecido($pdo, $accountId, $telefone)) {
                return null;
            }

            $coluna = self::primeiraColuna($pdo, $accountId);
            if ($coluna === null) {
                return null; // funil sem coluna: card nasceria invisível
            }

            $nome = trim((string) ($pushName ?? ''));
            if ($nome === '' || preg_match('/^[+0-9 ()-]+$/', $nome)) {
                // Sem nome utilizável, o telefone identifica melhor que "Contato".
                $nome = self::telefoneLegivel($telefone);
            }
            $nome = mb_substr($nome, 0, 180);

            $cardId = Card::create([
                'account_id'        => $accountId,
                'cliente_nome'      => $nome,
                'telefone_whatsapp' => $telefone,
                'coluna_id'         => $coluna,
                'ordem_na_coluna'   => 0,
                'status'            => 'aberto',
                'descricao'         => 'Lead recebido pelo WhatsApp.',
                '_usuario_id'       => null, // o sistema criou, não uma pessoa
            ]);

            if (!$cardId) {
                return null;
            }

            // Sem esta linha o "Vincular" continuaria existindo: o card apareceria
            // no funil e a conversa continuaria solta, que é o que confunde hoje.
            $pdo->prepare(
                'UPDATE whatsapp_chats SET linked_card_id = ?
                  WHERE instance_id = ? AND remote_jid = ? AND linked_card_id IS NULL'
            )->execute([(int) $cardId, $instanceId, $remoteJid]);

            // O histórico precisa dizer que não foi ninguém que digitou isso.
            Card::logEvento((int) $cardId, null, 'captado_whatsapp', 'telefone', null, $telefone);

            return (int) $cardId;
        } catch (\Throwable $e) {
            error_log('[CaptacaoAutomatica] falhou (mensagem preservada): ' . $e->getMessage());
            return null;
        }
    }

    /* ===================================================================== */
    /* helpers                                                               */
    /* ===================================================================== */

    /**
     * O telefone já existe como prospecção ou como cliente da conta?
     *
     * Compara pelos ÚLTIMOS 8 DÍGITOS, e não pela string inteira, porque o mesmo
     * número aparece gravado de formas diferentes conforme a origem: com e sem o
     * 55, com e sem o nono dígito, com e sem máscara. Comparar a string cheia
     * criaria card novo para quem já está no funil, que é o duplicado que a
     * trava existe para impedir.
     */
    private static function telefoneJaConhecido(\PDO $pdo, int $accountId, string $telefone): bool
    {
        $fim = substr($telefone, -8);
        if (strlen($fim) < 8) {
            return true; // curto demais para comparar com segurança: não arrisca duplicar
        }

        $st = $pdo->prepare(
            "SELECT 1 FROM cards
              WHERE account_id = ? AND deleted_at IS NULL
                AND RIGHT(REGEXP_REPLACE(COALESCE(telefone_whatsapp,''), '[^0-9]', ''), 8) = ?
              LIMIT 1"
        );
        $st->execute([$accountId, $fim]);
        if ($st->fetchColumn()) {
            return true;
        }

        $st = $pdo->prepare(
            "SELECT 1 FROM clientes
              WHERE account_id = ? AND deleted_at IS NULL
                AND (
                     RIGHT(REGEXP_REPLACE(COALESCE(whatsapp,''), '[^0-9]', ''), 8) = ?
                  OR RIGHT(REGEXP_REPLACE(COALESCE(telefone,''), '[^0-9]', ''), 8) = ?
                )
              LIMIT 1"
        );
        $st->execute([$accountId, $fim, $fim]);
        return (bool) $st->fetchColumn();
    }

    /**
     * Primeira coluna do funil da conta.
     *
     * Filial vinculada usa o funil da matriz, e é por isso que a busca cai para
     * `account_vinculos` quando a conta não tem coluna própria: sem isso, toda
     * filial ficaria de fora da captação.
     */
    private static function primeiraColuna(\PDO $pdo, int $accountId): ?int
    {
        $st = $pdo->prepare(
            'SELECT id FROM pipeline_columns WHERE account_id = ? ORDER BY ordem, id LIMIT 1'
        );
        $st->execute([$accountId]);
        $id = $st->fetchColumn();
        if ($id) {
            return (int) $id;
        }

        // As colunas são `matriz_account_id` / `filial_account_id`. Escrever
        // `matriz_id` aqui não daria erro visível: o try/catch engoliria, e a
        // filial simplesmente nunca captaria lead nenhum, em silêncio.
        // Só vínculo ATIVO conta: filial suspensa não usa o funil da matriz.
        try {
            $st = $pdo->prepare(
                "SELECT pc.id
                   FROM account_vinculos av
                   JOIN pipeline_columns pc ON pc.account_id = av.matriz_account_id
                  WHERE av.filial_account_id = ?
                    AND av.status = 'active'
               ORDER BY pc.ordem, pc.id LIMIT 1"
            );
            $st->execute([$accountId]);
            $id = $st->fetchColumn();
            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** (11) 98435-8434 a partir de 5511984358434. */
    public static function telefoneLegivel(string $digitos): string
    {
        $d = preg_replace('/[^0-9]/', '', $digitos);
        if (str_starts_with($d, '55') && strlen($d) >= 12) {
            $d = substr($d, 2);
        }
        if (strlen($d) === 11) {
            return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7);
        }
        if (strlen($d) === 10) {
            return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6);
        }
        return $digitos;
    }
}
