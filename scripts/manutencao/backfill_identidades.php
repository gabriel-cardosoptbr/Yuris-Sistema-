<?php
/**
 * backfill_identidades.php — constrói a identidade consolidada a partir do que
 * o sistema JÁ TEM guardado.
 *
 * ---------------------------------------------------------------------------
 * POR QUE ISTO EXISTE, E POR QUE O GANHO É IMEDIATO
 * ---------------------------------------------------------------------------
 * O vínculo entre o `@lid` e o telefone chega dentro do payload da mensagem, em
 * `key.remoteJidAlt` (e `key.participantAlt` dentro de grupo). O webhook passou
 * a gravar isso, mas só do momento da correção em diante.
 *
 * O passado já está no banco: `whatsapp_messages.raw_payload` guarda o payload
 * INTEIRO de cada mensagem que já chegou. Este script relê aquilo e extrai os
 * pares que estavam ali o tempo todo, jogados fora por não haver onde guardar.
 *
 * Medido no canal da conta 83 em 10/09/2026: 377 payloads com o Alt, 32 pares
 * `@lid` -> telefone únicos, e 8 das 26 conversas que aparecem sem identificação
 * passam a ter telefone e nome.
 *
 * ---------------------------------------------------------------------------
 * AS QUATRO FONTES, NA ORDEM EM QUE SÃO LIDAS
 * ---------------------------------------------------------------------------
 * A ordem importa: cada passo enriquece o que o anterior criou, e a regra de
 * peso de `Identidade::registrarNome` garante que uma fonte fraca lida depois
 * não estrague o que uma forte gravou antes.
 *
 *  1. PAYLOADS   o vínculo @lid <-> telefone. É o único lugar onde ele existe.
 *  2. CONTATOS DO WHATSAPP (`whatsapp_contacts`)  nomes da agenda do celular.
 *     `is_manual_name = 1` entra como 'manual', porque alguém digitou na tela.
 *  3. CLIENTES E PROSPECÇÕES  o nome que o escritório usa de verdade, que é o
 *     mais confiável de todos depois do digitado à mão.
 *  4. CONVERSAS (`whatsapp_chats`)  garante identidade para quem ainda não tem,
 *     mesmo sem nome, para a tela conseguir mostrar o telefone.
 *
 * ---------------------------------------------------------------------------
 * SEGURANÇA
 * ---------------------------------------------------------------------------
 * NÃO apaga nem altera nenhuma linha de mensagem, conversa, contato ou cliente.
 * Só escreve em `whatsapp_identidades`. Idempotente: rodar de novo não duplica,
 * porque `Identidade::registrar` resolve pelo endereço antes de criar.
 *
 * Uso local: C:\xampp\php\php.exe scripts/manutencao/backfill_identidades.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/scripts/manutencao/backfill_identidades.php --dry-run
 *
 * Flags:
 *   --dry-run          diz o que faria, sem gravar. RODE ISSO PRIMEIRO.
 *   --instance=ID      só um canal (padrão: todos)
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }

$dryRun   = in_array('--dry-run', $argv ?? [], true);
$soCanal  = null;
foreach ($argv ?? [] as $a) {
    if (strncmp($a, '--instance=', 11) === 0) { $soCanal = (int) substr($a, 11); }
}

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;
use App\WhatsAppAgente\Identidade;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

linha('== Backfill da identidade consolidada de contato ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$canais = $pdo->query(
    'SELECT id, account_id, instance_name FROM whatsapp_instances'
    . ($soCanal ? ' WHERE id = ' . (int) $soCanal : '')
    . ' ORDER BY id'
)->fetchAll(\PDO::FETCH_ASSOC);

if (!$canais) { linha('  Nenhum canal encontrado.'); exit(0); }

$totalGeral = ['pares' => 0, 'identidades' => 0, 'nomes' => 0];

foreach ($canais as $canal) {
    $iid = (int) $canal['id'];
    $acc = (int) $canal['account_id'];

    $temMsg = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_messages WHERE instance_id = $iid")->fetchColumn();
    if ($temMsg === 0) { continue; }

    linha("── canal #{$iid} ({$canal['instance_name']}), conta {$acc} ──");

    $antes = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_identidades WHERE instance_id = $iid")->fetchColumn();

    /* ─── 1. PAYLOADS: o vínculo, que só existe aqui ─────────────────────── */
    $pares = 0; $comAlt = 0;
    $st = $pdo->prepare(
        "SELECT raw_payload FROM whatsapp_messages
          WHERE instance_id = ? AND raw_payload LIKE '%JidAlt%'"
    );
    $st->execute([$iid]);
    while ($r = $st->fetch(\PDO::FETCH_ASSOC)) {
        $d = json_decode((string) $r['raw_payload'], true);
        if (!is_array($d) || !isset($d['key']) || !is_array($d['key'])) { continue; }
        $comAlt++;

        $end = Identidade::enderecosDaKey($d['key']);
        if ($end['lid'] === null && $end['phone'] === null) { continue; }
        if ($end['lid'] !== null && $end['phone'] !== null) { $pares++; }

        if ($dryRun) { continue; }

        // pushName só quando a mensagem foi RECEBIDA: em mensagem própria ele é
        // o nome do dono da conta, não do contato.
        $ehDeMim = !empty($d['key']['fromMe']);
        Identidade::registrar(
            $acc, $iid, $end['jid'], $end['lid'], $end['phone'],
            $ehDeMim ? null : ($d['pushName'] ?? null),
            'messages_upsert',
            isset($d['messageTimestamp']) && is_numeric($d['messageTimestamp'])
                ? date('Y-m-d H:i:s', (int) $d['messageTimestamp'])
                : null
        );
    }
    linha("  payloads com Alt: {$comAlt}   pares @lid<->telefone: {$pares}");

    /* ─── 2. CONTATOS DO WHATSAPP: nomes da agenda ───────────────────────── */
    $nomesAgenda = 0;
    $st = $pdo->prepare(
        "SELECT remote_jid, push_name, phone, is_manual_name
           FROM whatsapp_contacts
          WHERE instance_id = ? AND remote_jid NOT LIKE '%@g.us'"
    );
    $st->execute([$iid]);
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
        $a = Identidade::analisarJid((string) $c['remote_jid']);
        if (!in_array($a['tipo'], ['lid', 'telefone'], true)) { continue; }
        $nome = trim((string) ($c['push_name'] ?? ''));
        if ($nome !== '') { $nomesAgenda++; }
        if ($dryRun) { continue; }

        Identidade::registrar(
            $acc, $iid,
            $a['tipo'] === 'telefone' ? $a['jid'] : null,
            $a['tipo'] === 'lid'      ? $a['jid'] : null,
            Identidade::telefoneValido($c['phone']) ? $c['phone'] : ($a['tipo'] === 'telefone' ? $a['digitos'] : null),
            $nome !== '' ? $nome : null,
            // Nome digitado na tela de Contatos vale como manual e ganha de tudo.
            !empty($c['is_manual_name']) ? 'manual' : 'contacts_upsert'
        );
    }
    linha("  contatos do WhatsApp com nome: {$nomesAgenda}");

    /* ─── 3. CRM: o nome que o escritório usa ────────────────────────────── */
    $nomesCrm = 0;
    $st = $pdo->prepare(
        "SELECT c.remote_jid, ch.linked_card_id, ch.contato_id,
                -- COLLATE explicito: `clientes` e `cards` sao utf8mb4_general_ci e as
                -- tabelas de WhatsApp sao utf8mb4_unicode_ci. Sem declarar, o MySQL
                -- recusa a comparacao com Illegal mix of collations. Declarar aqui
                -- e o certo: mudar a colacao de `clientes` seria ALTER numa tabela
                -- grande de producao para resolver um SELECT de manutencao.
                (SELECT cli.nome FROM clientes cli
                  WHERE cli.account_id = ? AND cli.deleted_at IS NULL
                    AND RIGHT(REGEXP_REPLACE(COALESCE(cli.whatsapp, cli.telefone, ''), '[^0-9]', ''), 8) COLLATE utf8mb4_unicode_ci
                      = RIGHT(REGEXP_REPLACE(COALESCE(c.phone,''), '[^0-9]', ''), 8) COLLATE utf8mb4_unicode_ci
                    AND LENGTH(REGEXP_REPLACE(COALESCE(c.phone,''), '[^0-9]', '')) >= 8
                  LIMIT 1) AS nome_cliente,
                (SELECT cd.cliente_nome FROM cards cd
                  WHERE cd.id = ch.linked_card_id AND cd.deleted_at IS NULL LIMIT 1) AS nome_card
           FROM whatsapp_chats ch
           JOIN whatsapp_contacts c ON c.instance_id = ch.instance_id AND c.remote_jid = ch.remote_jid
          WHERE ch.instance_id = ? AND ch.remote_jid NOT LIKE '%@g.us'"
    );
    $st->execute([$acc, $iid]);
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
        $nome = trim((string) ($c['nome_cliente'] ?: $c['nome_card'] ?: ''));
        if ($nome === '') { continue; }
        $nomesCrm++;
        if ($dryRun) { continue; }

        $ident = Identidade::porEndereco($iid, (string) $c['remote_jid']);
        if ($ident) { Identidade::registrarNome((int) $ident['id'], $nome, 'crm'); }
    }
    linha("  nomes vindos de cliente/prospecção: {$nomesCrm}");

    /* ─── 4. CONVERSAS: identidade para todo mundo, mesmo sem nome ───────── */
    $conversas = 0;
    $st = $pdo->prepare(
        "SELECT remote_jid, phone, last_message_at FROM whatsapp_chats
          WHERE instance_id = ? AND remote_jid NOT LIKE '%@g.us'
            AND remote_jid NOT LIKE '%@broadcast' AND remote_jid NOT LIKE '%@newsletter'"
    );
    $st->execute([$iid]);
    foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $c) {
        $a = Identidade::analisarJid((string) $c['remote_jid']);
        if (!in_array($a['tipo'], ['lid', 'telefone'], true)) { continue; }
        $conversas++;
        if ($dryRun) { continue; }

        Identidade::registrar(
            $acc, $iid,
            $a['tipo'] === 'telefone' ? $a['jid'] : null,
            $a['tipo'] === 'lid'      ? $a['jid'] : null,
            Identidade::telefoneValido($c['phone']) ? $c['phone'] : ($a['tipo'] === 'telefone' ? $a['digitos'] : null),
            null, 'messages_upsert', $c['last_message_at'] ?: null
        );
    }
    linha("  conversas 1:1 cobertas: {$conversas}");

    if (!$dryRun) {
        $depois = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_identidades WHERE instance_id = $iid")->fetchColumn();
        $comNome = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_identidades WHERE instance_id = $iid AND nome IS NOT NULL AND nome <> ''")->fetchColumn();
        $comAmbos = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_identidades WHERE instance_id = $iid AND lid IS NOT NULL AND phone IS NOT NULL")->fetchColumn();
        linha("  identidades: {$antes} -> {$depois}   com nome: {$comNome}   com @lid E telefone: {$comAmbos}");
        $totalGeral['identidades'] += $depois - $antes;
        $totalGeral['nomes'] += $comNome;
    }
    $totalGeral['pares'] += $pares;
    linha();
}

linha("  pares @lid<->telefone encontrados: {$totalGeral['pares']}");
linha($dryRun
    ? '  DRY-RUN concluido. Rode sem --dry-run para aplicar.'
    : "  identidades criadas: {$totalGeral['identidades']}   com nome: {$totalGeral['nomes']}");
