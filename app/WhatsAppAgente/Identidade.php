<?php

namespace App\WhatsAppAgente;

use App\Core\Database;

/**
 * Identidade — quem é a pessoa do outro lado, independente do endereço que o
 * WhatsApp usou hoje.
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA, EM UMA FRASE
 * ---------------------------------------------------------------------------
 * O mesmo ser humano chega ao sistema por endereços diferentes:
 *
 *   232366454870257@lid            <- é assim que a mensagem chega hoje
 *   5511997529604@s.whatsapp.net   <- é aqui que o nome está guardado
 *
 * A Evolution guarda os dois como CONTATOS SEPARADOS, e a tabela `Contact` dela
 * não tem coluna nenhuma ligando um ao outro. Medido em 10/09/2026: os dois
 * registros com a MESMA `profilePicUrl`, um com pushName "Fe VIVO" e o outro com
 * pushName vazio. As mensagens chegam pelo segundo.
 *
 * Esta classe é a ponte que a Evolution não tem.
 *
 * ---------------------------------------------------------------------------
 * DE ONDE VEM A PROVA DE QUE OS DOIS SÃO A MESMA PESSOA
 * ---------------------------------------------------------------------------
 * Do próprio payload da mensagem. O Baileys 7 manda, e a Evolution 2.3.6
 * repassa:
 *
 *   key.remoteJid     = 232366454870257@lid
 *   key.remoteJidAlt  = 5511997529604@s.whatsapp.net    <- o telefone REAL
 *   key.addressingMode= lid
 *
 * E dentro de grupo, para o autor da mensagem:
 *
 *   key.participant    = ...@lid
 *   key.participantAlt = ...@s.whatsapp.net
 *
 * Não é dedução nem heurística: é o próprio WhatsApp dizendo que aquele LID é
 * aquele telefone. Por isso o vínculo criado a partir do Alt é tratado como
 * CERTO, e não como palpite.
 *
 * ---------------------------------------------------------------------------
 * NUNCA CRIAR DUAS PESSOAS PELO MESMO MOTIVO
 * ---------------------------------------------------------------------------
 * Antes de o Alt aparecer, é normal existir uma identidade só com o LID e outra
 * só com o telefone: são dois endereços que ainda não se sabia serem da mesma
 * pessoa. Quando o Alt finalmente chega, `fundir()` junta as duas em uma,
 * escolhendo o melhor nome entre elas e preservando a mais antiga.
 *
 * ---------------------------------------------------------------------------
 * O NOME BOM NÃO PODE SER ATROPELADO PELO NOME RUIM
 * ---------------------------------------------------------------------------
 * "João da Silva Ferreira", digitado por alguém no CRM, não pode virar "João"
 * porque o WhatsApp mandou o apelido no pushName. Por isso toda gravação de nome
 * carrega uma ORIGEM, cada origem tem um PESO, e nome só é trocado por fonte de
 * peso maior. Ver PESOS e `melhorNome()`.
 *
 * ---------------------------------------------------------------------------
 * O TELEFONE É COMPARADO PELOS ÚLTIMOS 8 DÍGITOS
 * ---------------------------------------------------------------------------
 * O mesmo número aparece como 5511987654321, 11987654321 e 987654321 conforme a
 * origem: com e sem DDI, com e sem o nono dígito. Comparar a string inteira
 * criaria a duplicata que esta classe existe para impedir. Oito dígitos é o
 * maior sufixo estável em número brasileiro.
 */
final class Identidade
{
    /**
     * Peso de cada fonte de nome. Maior ganha.
     *
     * A ordem não é estética, é a ordem de CONFIANÇA: quem digitou à mão sabe
     * mais que o CRM, que sabe mais que a agenda do celular, que sabe mais que
     * o apelido que a pessoa escolheu para si no WhatsApp.
     */
    public const PESOS = [
        'manual'          => 100,  // alguém digitou na tela de Contatos
        'crm'             => 90,   // nome do cliente ou da prospecção já ligada
        'lead_form'       => 80,   // formulário preenchido pela própria pessoa
        'contacts_upsert' => 60,   // agenda do celular, via evento da Evolution
        'find_contacts'   => 55,   // agenda do celular, via consulta
        'messages_upsert' => 40,   // pushName: o apelido que a pessoa escolheu
        'fallback'        => 10,   // o telefone formatado
    ];

    /** Sufixos que identificam o tipo de endereço. */
    public const SUFIXO_TELEFONE = '@s.whatsapp.net';
    public const SUFIXO_LID      = '@lid';

    /* ===================================================================== */
    /* normalização                                                          */
    /* ===================================================================== */

    /**
     * Decompõe um JID em tipo e dígitos, sem adivinhar nada.
     *
     * @return array{tipo:string,digitos:string,jid:string}
     *         tipo: telefone | lid | grupo | broadcast | newsletter | desconhecido
     */
    public static function analisarJid(?string $jid): array
    {
        $jid = trim((string) $jid);
        if ($jid === '') {
            return ['tipo' => 'desconhecido', 'digitos' => '', 'jid' => ''];
        }

        $digitos = preg_replace('/[^0-9]/', '', explode('@', $jid)[0]);

        if (str_contains($jid, '@g.us'))        return ['tipo' => 'grupo',      'digitos' => $digitos, 'jid' => $jid];
        if (str_contains($jid, '@broadcast'))   return ['tipo' => 'broadcast',  'digitos' => $digitos, 'jid' => $jid];
        if (str_contains($jid, '@newsletter'))  return ['tipo' => 'newsletter', 'digitos' => $digitos, 'jid' => $jid];
        if (str_ends_with($jid, self::SUFIXO_LID))      return ['tipo' => 'lid',      'digitos' => $digitos, 'jid' => $jid];
        if (str_ends_with($jid, self::SUFIXO_TELEFONE)) return ['tipo' => 'telefone', 'digitos' => $digitos, 'jid' => $jid];

        // Sem sufixo conhecido: só é telefone se PARECER telefone. Um LID tem
        // 15 dígitos ou mais e não é discável, então o teto de 13 é o que separa.
        if ($digitos !== '' && strlen($digitos) >= 10 && strlen($digitos) <= 13) {
            return ['tipo' => 'telefone', 'digitos' => $digitos, 'jid' => $digitos . self::SUFIXO_TELEFONE];
        }
        return ['tipo' => 'desconhecido', 'digitos' => $digitos, 'jid' => $jid];
    }

    /** Um telefone é discável? 10 a 13 dígitos cobre com e sem DDI, com e sem nono dígito. */
    public static function telefoneValido(?string $d): bool
    {
        $d = preg_replace('/[^0-9]/', '', (string) $d);
        return $d !== '' && strlen($d) >= 10 && strlen($d) <= 13;
    }

    /** Os últimos 8 dígitos, que é o sufixo estável para comparar telefones. */
    public static function sufixo(?string $telefone): ?string
    {
        $d = preg_replace('/[^0-9]/', '', (string) $telefone);
        return strlen($d) >= 8 ? substr($d, -8) : null;
    }

    /**
     * Extrai os endereços de uma `key` de mensagem, do jeito que o Baileys 7
     * realmente manda.
     *
     * `remoteJidAlt` e `participantAlt` foram confirmados no payload REAL desta
     * instalação, não deduzidos da documentação.
     *
     * Em conversa 1:1 o par que interessa é remoteJid/remoteJidAlt. Em GRUPO,
     * `remoteJid` é o grupo e quem escreve está em participant/participantAlt:
     * por isso o segundo par existe e é lido separado.
     *
     * @param array $key           `$msg['key']` cru
     * @return array{jid:?string,lid:?string,phone:?string,de_grupo:bool}
     */
    public static function enderecosDaKey(array $key): array
    {
        $remoto    = (string) ($key['remoteJid'] ?? '');
        $remotoAlt = (string) ($key['remoteJidAlt'] ?? '');
        $part      = (string) ($key['participant'] ?? '');
        $partAlt   = (string) ($key['participantAlt'] ?? '');

        $ehGrupo = str_contains($remoto, '@g.us');

        // Em grupo, a pessoa é o participante. Fora de grupo, é o próprio remoto.
        $principal = $ehGrupo ? $part      : $remoto;
        $alt       = $ehGrupo ? $partAlt   : $remotoAlt;

        $a = self::analisarJid($principal);
        $b = self::analisarJid($alt);

        $lid = null; $jid = null; $phone = null;

        foreach ([$a, $b] as $x) {
            if ($x['tipo'] === 'lid')      { $lid   = $x['jid']; }
            if ($x['tipo'] === 'telefone') { $jid   = $x['jid']; $phone = $x['digitos']; }
        }

        return ['jid' => $jid, 'lid' => $lid, 'phone' => $phone, 'de_grupo' => $ehGrupo];
    }

    /* ===================================================================== */
    /* resolução e persistência                                              */
    /* ===================================================================== */

    /**
     * Encontra ou cria a identidade a partir dos endereços conhecidos, e grava o
     * nome quando a fonte for boa o bastante.
     *
     * É o único caminho de escrita. Devolve o id da identidade, ou null quando
     * não há endereço utilizável (grupo, transmissão, canal).
     *
     * @param string|null $nome    nome a considerar (pushName, nome do CRM, etc)
     * @param string      $origem  chave de PESOS
     */
    public static function registrar(
        int $accountId,
        int $instanceId,
        ?string $jid,
        ?string $lid,
        ?string $phone,
        ?string $nome = null,
        string $origem = 'messages_upsert',
        ?string $quando = null
    ): ?int {
        if ($accountId <= 0 || $instanceId <= 0) {
            return null;
        }
        $phone = self::telefoneValido($phone) ? preg_replace('/[^0-9]/', '', (string) $phone) : null;
        $jid   = $jid !== null && trim($jid) !== '' ? trim($jid) : null;
        $lid   = $lid !== null && trim($lid) !== '' ? trim($lid) : null;

        if ($jid === null && $lid === null && $phone === null) {
            return null;
        }
        // Telefone sem jid: monta o jid, para as duas colunas contarem a mesma história.
        if ($jid === null && $phone !== null) {
            $jid = $phone . self::SUFIXO_TELEFONE;
        }

        $pdo = Database::getConnection();

        try {
            $porLid   = $lid   !== null ? self::buscarPor($pdo, $instanceId, 'lid', $lid)   : null;
            $porJid   = $jid   !== null ? self::buscarPor($pdo, $instanceId, 'jid', $jid)   : null;
            $porFone  = ($porLid === null && $porJid === null && $phone !== null)
                ? self::buscarPorTelefone($pdo, $instanceId, $phone)
                : null;

            $alvo = $porLid ?? $porJid ?? $porFone;

            /*
             * O momento que justifica a classe inteira: o LID e o telefone
             * existiam como DUAS identidades, e o payload acabou de provar que
             * são a mesma pessoa. Funde antes de qualquer escrita, senão a
             * gravação seguinte violaria um dos UNIQUE.
             */
            if ($porLid !== null && $porJid !== null && (int) $porLid['id'] !== (int) $porJid['id']) {
                $alvo = self::fundir($pdo, $porLid, $porJid);
            }

            if ($alvo === null) {
                $id = self::criar($pdo, $accountId, $instanceId, $jid, $lid, $phone, $quando);
            } else {
                $id = (int) $alvo['id'];
                self::completar($pdo, $alvo, $jid, $lid, $phone, $quando);
            }

            if ($nome !== null && trim($nome) !== '') {
                self::registrarNome($id, $nome, $origem);
            }
            // pushName cru é guardado SEMPRE, mesmo quando não vira nome: serve
            // para a tela oferecer "usar o apelido do WhatsApp" e para auditar.
            if ($origem === 'messages_upsert' && $nome !== null && trim($nome) !== '') {
                $pdo->prepare('UPDATE whatsapp_identidades SET push_name = ?, updated_at = NOW() WHERE id = ?')
                    ->execute([mb_substr(trim($nome), 0, 190), $id]);
            }

            return $id;
        } catch (\Throwable $e) {
            error_log('[Identidade] falhou (mensagem preservada): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Grava o nome SE a fonte for melhor que a que está lá.
     *
     * @return bool true se o nome mudou
     */
    public static function registrarNome(int $id, string $nome, string $origem): bool
    {
        $nome = trim($nome);
        if ($id <= 0 || $nome === '') {
            return false;
        }
        // Nome que é só número não é nome: é o telefone disfarçado, e entra como
        // fallback ou não entra.
        if (preg_match('/^[+0-9 ()\-]+$/', $nome) && $origem !== 'fallback') {
            return false;
        }
        // "Você" é como o WhatsApp chama o dono da conta. Nunca é o contato.
        if (in_array(mb_strtolower($nome), ['voce', 'você', 'you', 'eu'], true)) {
            return false;
        }

        $peso = self::PESOS[$origem] ?? 0;
        $pdo  = Database::getConnection();

        $st = $pdo->prepare('SELECT nome, nome_peso FROM whatsapp_identidades WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        $atual = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$atual) {
            return false;
        }

        if (!self::melhorNome((string) ($atual['nome'] ?? ''), (int) $atual['nome_peso'], $nome, $peso)) {
            return false;
        }

        $pdo->prepare(
            'UPDATE whatsapp_identidades
                SET nome = ?, nome_origem = ?, nome_peso = ?, updated_at = NOW()
              WHERE id = ?'
        )->execute([mb_substr($nome, 0, 190), $origem, $peso, $id]);

        return true;
    }

    /**
     * O nome novo deve substituir o atual?
     *
     * Três regras, nesta ordem:
     *
     *  1. Não há nome guardado: qualquer nome é melhor que nenhum.
     *  2. Fonte mais forte ganha. É o que impede o pushName "João" de apagar o
     *     "João da Silva Ferreira" que alguém digitou no CRM.
     *  3. MESMA fonte: só troca se o novo for estritamente mais completo, isto é,
     *     se o atual estiver contido nele. "João" -> "João Silva" entra;
     *     "João Silva" -> "João" NÃO entra. Sem essa regra, a mesma fonte ficaria
     *     alternando entre as duas formas a cada mensagem.
     */
    public static function melhorNome(string $atual, int $pesoAtual, string $novo, int $pesoNovo): bool
    {
        $atual = trim($atual);
        $novo  = trim($novo);
        if ($novo === '')   return false;
        if ($atual === '')  return true;
        if ($pesoNovo > $pesoAtual) return true;
        if ($pesoNovo < $pesoAtual) return false;

        // Empate de peso: aceita só se o novo contém o atual e é maior.
        $a = mb_strtolower($atual);
        $n = mb_strtolower($novo);
        return $a !== $n && mb_strlen($novo) > mb_strlen($atual) && str_contains($n, $a);
    }

    /* ===================================================================== */
    /* leitura                                                               */
    /* ===================================================================== */

    /** A identidade de um endereço qualquer, ou null. */
    public static function porEndereco(int $instanceId, string $endereco): ?array
    {
        $a = self::analisarJid($endereco);
        $pdo = Database::getConnection();

        if ($a['tipo'] === 'lid') {
            $r = self::buscarPor($pdo, $instanceId, 'lid', $a['jid']);
            if ($r) return $r;
        }
        if ($a['tipo'] === 'telefone') {
            $r = self::buscarPor($pdo, $instanceId, 'jid', $a['jid']);
            if ($r) return $r;
            return self::buscarPorTelefone($pdo, $instanceId, $a['digitos']);
        }
        return null;
    }

    /**
     * O que o resto do sistema (e o n8n) consome: a identidade resolvida, no
     * formato combinado.
     *
     * `contact_resolved` diz se sobrou alguma coisa melhor que o telefone. É o
     * campo que a automação usa para decidir se pergunta o nome à pessoa.
     */
    public static function resolvido(int $instanceId, string $endereco): array
    {
        $i = self::porEndereco($instanceId, $endereco);
        if ($i === null) {
            $a = self::analisarJid($endereco);
            return [
                'phone'            => $a['tipo'] === 'telefone' ? $a['digitos'] : null,
                'jid'              => $a['tipo'] === 'telefone' ? $a['jid'] : null,
                'lid'              => $a['tipo'] === 'lid' ? $a['jid'] : null,
                'name'             => null,
                'push_name'        => null,
                'name_source'      => null,
                'contact_resolved' => false,
            ];
        }
        return [
            'phone'            => $i['phone'],
            'jid'              => $i['jid'],
            'lid'              => $i['lid'],
            'name'             => $i['nome'],
            'push_name'        => $i['push_name'],
            'name_source'      => $i['nome_origem'],
            'contact_resolved' => $i['nome'] !== null && $i['nome'] !== '',
        ];
    }

    /* ===================================================================== */
    /* internos                                                              */
    /* ===================================================================== */

    private static function buscarPor(\PDO $pdo, int $instanceId, string $coluna, string $valor): ?array
    {
        if (!in_array($coluna, ['lid', 'jid'], true)) {
            return null;
        }
        $st = $pdo->prepare("SELECT * FROM whatsapp_identidades WHERE instance_id = ? AND `$coluna` = ? LIMIT 1");
        $st->execute([$instanceId, $valor]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    /** Busca por telefone comparando os últimos 8 dígitos. Ver o cabeçalho. */
    private static function buscarPorTelefone(\PDO $pdo, int $instanceId, string $phone): ?array
    {
        $suf = self::sufixo($phone);
        if ($suf === null) {
            return null;
        }
        $st = $pdo->prepare(
            "SELECT * FROM whatsapp_identidades
              WHERE instance_id = ? AND phone IS NOT NULL AND RIGHT(phone, 8) = ?
           ORDER BY id LIMIT 1"
        );
        $st->execute([$instanceId, $suf]);
        $r = $st->fetch(\PDO::FETCH_ASSOC);
        return $r ?: null;
    }

    private static function criar(
        \PDO $pdo, int $accountId, int $instanceId,
        ?string $jid, ?string $lid, ?string $phone, ?string $quando
    ): int {
        $pdo->prepare(
            'INSERT INTO whatsapp_identidades
               (account_id, instance_id, phone, jid, lid, primeira_vez_em, ultima_mensagem_em, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,NOW(),NOW())'
        )->execute([$accountId, $instanceId, $phone, $jid, $lid, $quando, $quando]);
        return (int) $pdo->lastInsertId();
    }

    /**
     * Preenche o que faltava numa identidade que já existe.
     *
     * NUNCA sobrescreve endereço já gravado: se a linha já tem um lid e chega
     * outro, o segundo é ignorado em vez de trocar. Trocar transformaria a
     * identidade em outra pessoa em silêncio.
     */
    private static function completar(\PDO $pdo, array $atual, ?string $jid, ?string $lid, ?string $phone, ?string $quando): void
    {
        $sets = []; $params = [];

        if ($lid   !== null && empty($atual['lid']))   { $sets[] = 'lid = ?';   $params[] = $lid; }
        if ($jid   !== null && empty($atual['jid']))   { $sets[] = 'jid = ?';   $params[] = $jid; }
        if ($phone !== null && empty($atual['phone'])) { $sets[] = 'phone = ?'; $params[] = $phone; }

        if ($quando !== null) {
            $sets[]   = 'ultima_mensagem_em = GREATEST(COALESCE(ultima_mensagem_em, ?), ?)';
            $params[] = $quando; $params[] = $quando;
            if (empty($atual['primeira_vez_em'])) { $sets[] = 'primeira_vez_em = ?'; $params[] = $quando; }
        }

        if ($sets === []) {
            return;
        }
        $sets[]   = 'updated_at = NOW()';
        $params[] = (int) $atual['id'];
        $pdo->prepare('UPDATE whatsapp_identidades SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    /**
     * Funde duas identidades que se provaram a mesma pessoa.
     *
     * Sobrevive a MAIS ANTIGA (menor id): ela é a que outras tabelas já podem
     * estar apontando. A outra é apagada depois de doar o que tinha de melhor.
     *
     * @return array a linha sobrevivente, relida do banco
     */
    private static function fundir(\PDO $pdo, array $a, array $b): array
    {
        $fica = ((int) $a['id'] <= (int) $b['id']) ? $a : $b;
        $sai  = ((int) $a['id'] <= (int) $b['id']) ? $b : $a;

        $sets = []; $params = [];
        foreach (['lid', 'jid', 'phone', 'contato_id', 'push_name'] as $c) {
            if (empty($fica[$c]) && !empty($sai[$c])) { $sets[] = "`$c` = ?"; $params[] = $sai[$c]; }
        }
        // O melhor nome entre as duas, pela mesma regra de peso.
        if (self::melhorNome((string) ($fica['nome'] ?? ''), (int) $fica['nome_peso'],
                             (string) ($sai['nome'] ?? ''),  (int) $sai['nome_peso'])) {
            $sets[] = 'nome = ?';        $params[] = $sai['nome'];
            $sets[] = 'nome_origem = ?'; $params[] = $sai['nome_origem'];
            $sets[] = 'nome_peso = ?';   $params[] = (int) $sai['nome_peso'];
        }
        // As datas extremas das duas.
        $sets[] = 'primeira_vez_em = LEAST(COALESCE(primeira_vez_em, ?), COALESCE(?, primeira_vez_em))';
        $params[] = $sai['primeira_vez_em']; $params[] = $sai['primeira_vez_em'];
        $sets[] = 'ultima_mensagem_em = GREATEST(COALESCE(ultima_mensagem_em, ?), COALESCE(?, ultima_mensagem_em))';
        $params[] = $sai['ultima_mensagem_em']; $params[] = $sai['ultima_mensagem_em'];

        // A que sai some ANTES do UPDATE: os dois UNIQUE (instance,lid) e
        // (instance,jid) impediriam a sobrevivente de assumir os endereços dela.
        $pdo->prepare('DELETE FROM whatsapp_identidades WHERE id = ?')->execute([(int) $sai['id']]);

        $sets[]   = 'updated_at = NOW()';
        $params[] = (int) $fica['id'];
        $pdo->prepare('UPDATE whatsapp_identidades SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);

        $st = $pdo->prepare('SELECT * FROM whatsapp_identidades WHERE id = ? LIMIT 1');
        $st->execute([(int) $fica['id']]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: $fica;
    }
}
