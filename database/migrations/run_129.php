<?php
/**
 * Migration 129 — identidade consolidada do contato de WhatsApp.
 *
 * ---------------------------------------------------------------------------
 * O PROBLEMA, MEDIDO EM PRODUCAO EM 10/09/2026
 * ---------------------------------------------------------------------------
 * O WhatsApp passou a enderecar conversa por `@lid`, um identificador de
 * privacidade que NAO e o telefone. No canal da conta 83, de 1.094 payloads
 * reais gravados, ZERO tinham `remoteJid` em `@s.whatsapp.net`: 493 eram `@lid`
 * e 601 eram grupo.
 *
 * E a MESMA PESSOA existe DUAS VEZES na tabela de contatos da Evolution:
 *
 *   5511997529604@s.whatsapp.net   pushName "Fe VIVO"   <- tem o nome
 *   232366454870257@lid            pushName ""          <- e o que recebe as msgs
 *
 * Os dois com a MESMA profilePicUrl, ou seja, comprovadamente a mesma pessoa.
 *
 * ---------------------------------------------------------------------------
 * POR QUE A EVOLUTION NAO PODE RESOLVER ISSO
 * ---------------------------------------------------------------------------
 * A tabela `Contact` dela (Postgres) e:
 *
 *   remoteJid varchar(100) NOT NULL
 *   pushName  varchar(100)
 *   UNIQUE ("remoteJid","instanceId")
 *
 * NAO existe coluna ligando LID a telefone. Nao e falha de consulta, e o modelo
 * de dados: `findContacts` nunca vai devolver o vinculo porque o vinculo nao e
 * guardado. Insistir em consultar melhor nao resolve.
 *
 * ---------------------------------------------------------------------------
 * MAS O VINCULO CHEGA, NO PAYLOAD DA MENSAGEM
 * ---------------------------------------------------------------------------
 * O Baileys 7.0.0-rc.6 (o que esta instalado) manda, e a Evolution 2.3.6
 * repassa:
 *
 *   {"remoteJid":"232366454870257@lid",
 *    "remoteJidAlt":"5511997529604@s.whatsapp.net",
 *    "addressingMode":"lid"}
 *
 * Presente em 377 dos 1.094 payloads. Nas mensagens RECEBIDAS: 329 sao `@lid` e
 * 249 delas (76%) trazem o telefone real. `participantAlt` faz o mesmo para o
 * autor dentro de grupo, em 423 payloads.
 *
 * Ou seja: a informacao passa por nos todo dia e era jogada fora.
 *
 * ---------------------------------------------------------------------------
 * O QUE ESTA TABELA E
 * ---------------------------------------------------------------------------
 * A identidade consolidada: UMA linha por pessoa e por canal, guardando os TRES
 * enderecos possiveis (telefone, jid, lid) e o melhor nome conhecido, com a
 * origem dele.
 *
 * Ela NAO substitui `whatsapp_contacts` nem `contatos`. Ela COSTURA:
 *   whatsapp_contacts  registro por JID, do jeito que a Evolution manda
 *   contatos           a pessoa no CRM, que ja e a entidade compartilhada
 *   whatsapp_identidades  a ponte que diz que aquele @lid e aquele telefone
 *                         sao a mesma pessoa, e qual nome vale
 *
 * Tabela nova em vez de coluna nova em `whatsapp_contacts` porque ali o UNIQUE e
 * (instance_id, remote_jid): o modelo daquela tabela E "um registro por
 * endereco", e o problema e exatamente que uma pessoa tem varios enderecos.
 * Forcar a consolidacao ali exigiria apagar ou fundir linhas que a Evolution
 * recria no proximo sync.
 *
 * ---------------------------------------------------------------------------
 * OS UNIQUE, E POR QUE ELES ACEITAM NULL
 * ---------------------------------------------------------------------------
 * `jid` e `lid` sao UNIQUE por instancia, mas ANULAVEIS. No MySQL, NULL nao
 * conflita com NULL num indice unico, entao muitas identidades podem estar sem
 * lid conhecido ao mesmo tempo. E o que se quer: a maioria comeca so com um dos
 * dois, e o outro entra quando aparecer um payload com o Alt.
 *
 * `phone` NAO e unique de proposito. O mesmo telefone pode aparecer em duas
 * instancias, e dentro da mesma instancia a deduplicacao por telefone e feita no
 * codigo, comparando os ULTIMOS 8 DIGITOS (o numero chega com e sem 55, com e
 * sem o nono digito). Um UNIQUE na string cheia daria falsa seguranca.
 *
 * NAO APAGA E NAO ALTERA NENHUMA LINHA EXISTENTE. IDEMPOTENTE.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_129.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_129.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void
{
    echo $s . PHP_EOL;
}

function temTabela(\PDO $pdo, string $t): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (bool) $st->fetchColumn();
}

linha('== Migration 129: identidade consolidada do contato de WhatsApp ==');
linha($dryRun ? '   MODO DRY-RUN: nada sera gravado.' : '   MODO APLICACAO.');
linha();

$ddl = "
CREATE TABLE `whatsapp_identidades` (
  `id`          INT NOT NULL AUTO_INCREMENT,
  `account_id`  INT NOT NULL,
  `instance_id` INT NOT NULL,

  `phone`       VARCHAR(20)  NULL COMMENT 'So digitos, com DDI quando conhecido. A chave humana.',
  `jid`         VARCHAR(120) NULL COMMENT 'NNNNN@s.whatsapp.net',
  `lid`         VARCHAR(120) NULL COMMENT 'NNNNN@lid, o id de privacidade do WhatsApp',

  `nome`        VARCHAR(190) NULL COMMENT 'O melhor nome conhecido. NUNCA sobrescrito por fonte mais fraca.',
  `nome_origem` VARCHAR(24)  NULL COMMENT 'manual|crm|lead_form|contacts_upsert|find_contacts|messages_upsert|fallback',
  `nome_peso`   TINYINT      NOT NULL DEFAULT 0 COMMENT 'Peso da fonte que gravou o nome. Ver App\\\\WhatsAppAgente\\\\Identidade::PESOS.',
  `push_name`   VARCHAR(190) NULL COMMENT 'Ultimo pushName visto, CRU. Guardado mesmo quando nao vira nome.',

  `contato_id`  INT NULL COMMENT 'Ponte para `contatos`, a entidade pessoa do CRM',

  `primeira_vez_em`    DATETIME NULL,
  `ultima_mensagem_em` DATETIME NULL,
  `created_at`  DATETIME NULL,
  `updated_at`  DATETIME NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_wai_lid`   (`instance_id`,`lid`),
  UNIQUE KEY `uk_wai_jid`   (`instance_id`,`jid`),
  KEY `idx_wai_phone`       (`instance_id`,`phone`),
  KEY `idx_wai_account`     (`account_id`),
  KEY `idx_wai_contato`     (`contato_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Uma linha por pessoa e por canal: costura @lid, telefone e o melhor nome'";

$feitas = 0;
$puladas = 0;

if (temTabela($pdo, 'whatsapp_identidades')) {
    linha('  [ja existe] tabela whatsapp_identidades');
    $puladas++;
} else {
    if ($dryRun) {
        linha('  [criaria]   tabela whatsapp_identidades');
    } else {
        $pdo->exec($ddl);
        linha('  [criada]    tabela whatsapp_identidades');
    }
    $feitas++;
}

linha();

/* ---------------------------------------------------------------------------
 * Diagnostico: quanto ha para consolidar, e quanto o backfill vai achar.
 * O numero de pares vem dos payloads JA GRAVADOS, entao e ganho imediato, sem
 * depender de ninguem escrever de novo.
 * ------------------------------------------------------------------------- */
$msgs = (int) $pdo->query('SELECT COUNT(*) FROM whatsapp_messages')->fetchColumn();
$comAlt = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_messages WHERE raw_payload LIKE '%JidAlt%'")->fetchColumn();
$chatsLid = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_chats WHERE remote_jid LIKE '%@lid'")->fetchColumn();
$contatosLid = (int) $pdo->query("SELECT COUNT(*) FROM whatsapp_contacts WHERE remote_jid LIKE '%@lid'")->fetchColumn();

linha("  mensagens no banco:                    $msgs");
linha("  payloads que trazem remoteJidAlt:      $comAlt   <- e daqui que os pares saem");
linha("  conversas enderecadas por @lid:        $chatsLid");
linha("  contatos gravados como @lid:           $contatosLid");

linha();
linha("  alteracoes: $feitas   ja existentes: $puladas");
linha($dryRun ? '  DRY-RUN concluido. Rode sem --dry-run para aplicar.' : '  Migration 129 aplicada.');
linha();
linha('  PROXIMO PASSO: scripts/manutencao/backfill_identidades.php --dry-run');
