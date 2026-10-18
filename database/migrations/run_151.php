<?php
/**
 * Migration 151: contratos e assinatura eletrônica (edição CRM).
 *
 * ---------------------------------------------------------------------------
 * PARA QUE SERVE
 * ---------------------------------------------------------------------------
 * Pedido do dono do produto em 18/10/2026: a equipe prepara um contrato (PDF),
 * gera um link individual para cada participante, e cada um confirma o acesso
 * por um código enviado ao WhatsApp, confere o documento e assina, sem conta no
 * CRM. Regra em app/Contratos/ (ver o README de lá).
 *
 *   crm_contratos               o contrato (UUID permanente, código de verificação)
 *   crm_contrato_versoes        cada versão fechada do PDF, com o SHA-256 dos bytes
 *   crm_contrato_arquivos       original, comprovante, conclusão e manifesto, no disco
 *   crm_contrato_participantes  contratante, contratado, representante, testemunha
 *   crm_contrato_convites       o link de cada participante (só o hash do token)
 *   crm_contrato_sessoes        a sessão curta da página externa, presa a um convite
 *   crm_contrato_desafios       o código de uso único (só o HMAC do código)
 *   crm_contrato_assinaturas    a assinatura e as evidências dela
 *   crm_contrato_eventos        a trilha de eventos, encadeada por hash
 *   crm_contrato_config         ajustes da conta (validade do código, tentativas...)
 *
 * PROTEÇÃO NO BANCO (gatilhos): assinaturas, eventos e arquivos não aceitam UPDATE
 * nem DELETE; versão aberta para assinatura não troca de arquivo nem de hash e não
 * se apaga. Isso barra a aplicação e qualquer consulta comum, mas quem tem
 * privilégio de DROP TRIGGER no banco consegue tirar a trava: por isso existe
 * também o encadeamento dos eventos e o manifesto assinado (detecção). Detalhe
 * em app/Contratos/README.md, "O que impede e o que detecta".
 *
 * IDEMPOTENTE. Não altera dado de outras tabelas.
 *
 * Uso local: C:\xampp\php\php.exe database/migrations/run_151.php --dry-run
 * Uso prod:  docker exec -i yuris_app php /var/www/html/database/migrations/run_151.php --dry-run
 */

$dryRun = in_array('--dry-run', $argv ?? [], true);

require_once __DIR__ . '/../../app/bootstrap.php';

use App\Core\Database;

$pdo = Database::getConnection();
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function linha(string $s = ''): void { echo $s . PHP_EOL; }

function tabelaExiste(\PDO $pdo, string $t): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$t]);
    return (int) $st->fetchColumn() > 0;
}

function gatilhoExiste(\PDO $pdo, string $g): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?');
    $st->execute([$g]);
    return (int) $st->fetchColumn() > 0;
}

linha('Migration 151' . ($dryRun ? ' (dry-run)' : ''));

$sufixo = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

$tabelas = [
    'crm_contratos' => "
        CREATE TABLE crm_contratos (
          id                     INT NOT NULL AUTO_INCREMENT,
          uuid                   CHAR(36) NOT NULL,
          account_id             INT NOT NULL,
          codigo_verificacao     CHAR(14) NOT NULL,
          titulo                 VARCHAR(200) NOT NULL,
          responsavel_id         INT NULL,
          cliente_id             INT NULL,
          card_id                INT NULL,
          status                 ENUM('rascunho','aguardando','parcial','concluido','recusado','expirado','cancelado') NOT NULL DEFAULT 'rascunho',
          versao_atual_id        INT NULL,
          prazo_em               DATETIME NULL COMMENT 'UTC',
          criado_por             INT NULL,
          criado_em              DATETIME NOT NULL COMMENT 'UTC',
          atualizado_em          DATETIME NOT NULL COMMENT 'UTC',
          concluido_em           DATETIME NULL COMMENT 'UTC',
          encerrado_em           DATETIME NULL COMMENT 'UTC: recusa, expiração ou cancelamento',
          encerrado_por          INT NULL,
          motivo_encerramento    VARCHAR(500) NULL,
          finalizacao_status     ENUM('nao_se_aplica','pendente','ok','erro') NOT NULL DEFAULT 'nao_se_aplica',
          finalizacao_tentativas INT NOT NULL DEFAULT 0,
          finalizacao_erro       VARCHAR(500) NULL,
          finalizacao_proxima    DATETIME NULL COMMENT 'UTC',
          PRIMARY KEY (id),
          UNIQUE KEY uq_contrato_uuid (uuid),
          UNIQUE KEY uq_contrato_codigo (codigo_verificacao),
          KEY idx_contrato_conta (account_id, status),
          KEY idx_contrato_cliente (account_id, cliente_id),
          KEY idx_contrato_card (account_id, card_id),
          KEY idx_contrato_finalizacao (finalizacao_status, finalizacao_proxima)
        )",
    'crm_contrato_arquivos' => "
        CREATE TABLE crm_contrato_arquivos (
          id          INT NOT NULL AUTO_INCREMENT,
          uuid        CHAR(36) NOT NULL,
          account_id  INT NOT NULL,
          contrato_id INT NOT NULL,
          versao_id   INT NULL,
          tipo        ENUM('original','comprovante','conclusao','manifesto') NOT NULL,
          sha256      CHAR(64) NOT NULL,
          bytes       BIGINT NOT NULL,
          mime        VARCHAR(80) NOT NULL,
          caminho     VARCHAR(255) NOT NULL COMMENT 'relativo à pasta de armazenamento',
          criado_em   DATETIME NOT NULL COMMENT 'UTC',
          criado_por  INT NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_arquivo_uuid (uuid),
          KEY idx_arquivo_contrato (contrato_id, tipo),
          KEY idx_arquivo_conta (account_id, tipo)
        )",
    'crm_contrato_versoes' => "
        CREATE TABLE crm_contrato_versoes (
          id                 INT NOT NULL AUTO_INCREMENT,
          uuid               CHAR(36) NOT NULL,
          account_id         INT NOT NULL,
          contrato_id        INT NOT NULL,
          numero             INT NOT NULL,
          arquivo_id         INT NOT NULL,
          sha256             CHAR(64) NOT NULL,
          nome_arquivo       VARCHAR(200) NOT NULL,
          status             ENUM('rascunho','aberta','substituida','concluida','encerrada') NOT NULL DEFAULT 'rascunho',
          aberta_em          DATETIME NULL COMMENT 'UTC: fechada para assinatura',
          substituida_em     DATETIME NULL COMMENT 'UTC',
          substituida_por_id INT NULL,
          criado_por         INT NULL,
          criado_em          DATETIME NOT NULL COMMENT 'UTC',
          PRIMARY KEY (id),
          UNIQUE KEY uq_versao_uuid (uuid),
          UNIQUE KEY uq_versao_numero (contrato_id, numero),
          KEY idx_versao_conta (account_id)
        )",
    'crm_contrato_participantes' => "
        CREATE TABLE crm_contrato_participantes (
          id               INT NOT NULL AUTO_INCREMENT,
          uuid             CHAR(36) NOT NULL,
          account_id       INT NOT NULL,
          contrato_id      INT NOT NULL,
          papel            ENUM('contratante','contratado','representante','testemunha') NOT NULL,
          obrigatorio      TINYINT(1) NOT NULL DEFAULT 1,
          ordem            INT NOT NULL DEFAULT 0,
          nome             VARCHAR(160) NOT NULL,
          telefone_cifrado TEXT NOT NULL COMMENT 'cifrado com App Core Crypto',
          telefone_final   CHAR(4) NOT NULL,
          cpf_cifrado      TEXT NULL COMMENT 'CPF esperado, cifrado com App Core Crypto',
          cpf_hmac         CHAR(64) NULL,
          cpf_mascara      VARCHAR(14) NULL,
          empresa          VARCHAR(160) NULL,
          cnpj             VARCHAR(18) NULL,
          funcao           VARCHAR(80) NULL,
          cliente_id       INT NULL,
          status           ENUM('pendente','convidado','assinou','recusou','bloqueado','cancelado','expirado') NOT NULL DEFAULT 'pendente',
          bloqueio_motivo  VARCHAR(300) NULL,
          criado_em        DATETIME NOT NULL COMMENT 'UTC',
          atualizado_em    DATETIME NOT NULL COMMENT 'UTC',
          PRIMARY KEY (id),
          UNIQUE KEY uq_participante_uuid (uuid),
          KEY idx_participante_contrato (contrato_id, ordem),
          KEY idx_participante_conta (account_id)
        )",
    'crm_contrato_convites' => "
        CREATE TABLE crm_contrato_convites (
          id              INT NOT NULL AUTO_INCREMENT,
          uuid            CHAR(36) NOT NULL,
          account_id      INT NOT NULL,
          contrato_id     INT NOT NULL,
          participante_id INT NOT NULL,
          versao_id       INT NOT NULL,
          token_hash      CHAR(64) NOT NULL COMMENT 'SHA-256 do token; o token nunca é gravado',
          criado_em       DATETIME NOT NULL COMMENT 'UTC',
          expira_em       DATETIME NOT NULL COMMENT 'UTC',
          revogado_em     DATETIME NULL COMMENT 'UTC',
          revogado_motivo VARCHAR(80) NULL,
          usado_em        DATETIME NULL COMMENT 'UTC: assinatura feita',
          enviado_em      DATETIME NULL COMMENT 'UTC: mandado pelo WhatsApp',
          criado_por      INT NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_convite_uuid (uuid),
          UNIQUE KEY uq_convite_token (token_hash),
          KEY idx_convite_participante (participante_id, versao_id)
        )",
    'crm_contrato_sessoes' => "
        CREATE TABLE crm_contrato_sessoes (
          id                  INT NOT NULL AUTO_INCREMENT,
          sessao_hash         CHAR(64) NOT NULL,
          account_id          INT NOT NULL,
          contrato_id         INT NOT NULL,
          convite_id          INT NOT NULL,
          participante_id     INT NOT NULL,
          versao_id           INT NOT NULL,
          sha256              CHAR(64) NOT NULL,
          csrf                CHAR(64) NOT NULL,
          criada_em           DATETIME NOT NULL COMMENT 'UTC',
          expira_em           DATETIME NOT NULL COMMENT 'UTC',
          canal_confirmado_em DATETIME NULL COMMENT 'UTC',
          desafio_id          INT NULL,
          identificacao_json  TEXT NULL COMMENT 'dados confirmados ou declarados, com CPF cifrado',
          encerrada_em        DATETIME NULL COMMENT 'UTC',
          ip                  VARCHAR(45) NULL,
          user_agent          VARCHAR(400) NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_sessao_hash (sessao_hash),
          KEY idx_sessao_convite (convite_id)
        )",
    'crm_contrato_desafios' => "
        CREATE TABLE crm_contrato_desafios (
          id                INT NOT NULL AUTO_INCREMENT,
          uuid              CHAR(36) NOT NULL,
          account_id        INT NOT NULL,
          sessao_id         INT NOT NULL,
          convite_id        INT NOT NULL,
          participante_id   INT NOT NULL,
          versao_id         INT NOT NULL,
          sha256            CHAR(64) NOT NULL,
          codigo_hmac       CHAR(64) NOT NULL COMMENT 'HMAC-SHA256 com chave do servidor; o código nunca é gravado',
          canal             ENUM('whatsapp') NOT NULL DEFAULT 'whatsapp',
          contato_mascarado VARCHAR(40) NOT NULL,
          criado_em         DATETIME NOT NULL COMMENT 'UTC',
          expira_em         DATETIME NOT NULL COMMENT 'UTC',
          tentativas        INT NOT NULL DEFAULT 0,
          max_tentativas    INT NOT NULL,
          consumido_em      DATETIME NULL COMMENT 'UTC',
          invalidado_em     DATETIME NULL COMMENT 'UTC',
          entrega_status    VARCHAR(20) NOT NULL DEFAULT 'pendente',
          ip                VARCHAR(45) NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_desafio_uuid (uuid),
          KEY idx_desafio_convite (convite_id, criado_em)
        )",
    'crm_contrato_assinaturas' => "
        CREATE TABLE crm_contrato_assinaturas (
          id                 INT NOT NULL AUTO_INCREMENT,
          uuid               CHAR(36) NOT NULL,
          account_id         INT NOT NULL,
          contrato_id        INT NOT NULL,
          versao_id          INT NOT NULL,
          participante_id    INT NOT NULL,
          convite_id         INT NOT NULL,
          desafio_id         INT NOT NULL,
          sha256_documento   CHAR(64) NOT NULL,
          assinado_em        DATETIME(3) NOT NULL COMMENT 'UTC, relógio do servidor',
          ip                 VARCHAR(45) NULL,
          user_agent         VARCHAR(400) NULL,
          metodo             VARCHAR(80) NOT NULL,
          contato_mascarado  VARCHAR(40) NOT NULL,
          aceite_texto       VARCHAR(300) NOT NULL,
          aceite_versao      VARCHAR(20) NOT NULL,
          identificacao_json TEXT NOT NULL COMMENT 'fotografia dos dados no momento da assinatura',
          evidencia_json     TEXT NOT NULL COMMENT 'o registro de evidências, canônico',
          evidencia_sha256   CHAR(64) NOT NULL,
          idempotencia       CHAR(64) NOT NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_assinatura_uuid (uuid),
          UNIQUE KEY uq_assinatura_unica (versao_id, participante_id),
          UNIQUE KEY uq_assinatura_idem (idempotencia),
          KEY idx_assinatura_contrato (contrato_id)
        )",
    'crm_contrato_eventos' => "
        CREATE TABLE crm_contrato_eventos (
          id              BIGINT NOT NULL AUTO_INCREMENT,
          account_id      INT NOT NULL,
          contrato_id     INT NOT NULL,
          versao_id       INT NULL,
          participante_id INT NULL,
          tipo            VARCHAR(40) NOT NULL,
          dados_json      TEXT NULL,
          user_id         INT NULL,
          ip              VARCHAR(45) NULL,
          user_agent      VARCHAR(400) NULL,
          criado_em       DATETIME(3) NOT NULL COMMENT 'UTC',
          hash_anterior   CHAR(64) NOT NULL,
          hash            CHAR(64) NOT NULL,
          PRIMARY KEY (id),
          KEY idx_evento_contrato (contrato_id, id),
          KEY idx_evento_conta (account_id, criado_em)
        )",
    'crm_contrato_config' => "
        CREATE TABLE crm_contrato_config (
          account_id                INT NOT NULL,
          otp_validade_min          INT NOT NULL DEFAULT 5,
          otp_max_tentativas        INT NOT NULL DEFAULT 5,
          otp_intervalo_reenvio_seg INT NOT NULL DEFAULT 60,
          convite_validade_dias     INT NOT NULL DEFAULT 15,
          atualizado_em             DATETIME NULL COMMENT 'UTC',
          atualizado_por            INT NULL,
          PRIMARY KEY (account_id)
        )",
];

foreach ($tabelas as $nome => $sql) {
    if (tabelaExiste($pdo, $nome)) { linha("  = $nome já existe"); continue; }
    linha("  + $nome");
    if (!$dryRun) $pdo->exec(trim($sql) . $sufixo);
}

$bloqueia = static fn(string $msg): string => "BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '$msg'; END";

$gatilhos = [
    'trg_ctr_assinatura_sem_update' => 'CREATE TRIGGER trg_ctr_assinatura_sem_update BEFORE UPDATE ON crm_contrato_assinaturas FOR EACH ROW '
        . $bloqueia('Assinatura de contrato nao se altera'),
    'trg_ctr_assinatura_sem_delete' => 'CREATE TRIGGER trg_ctr_assinatura_sem_delete BEFORE DELETE ON crm_contrato_assinaturas FOR EACH ROW '
        . $bloqueia('Assinatura de contrato nao se apaga'),
    'trg_ctr_evento_sem_update' => 'CREATE TRIGGER trg_ctr_evento_sem_update BEFORE UPDATE ON crm_contrato_eventos FOR EACH ROW '
        . $bloqueia('Evento de contrato nao se altera'),
    'trg_ctr_evento_sem_delete' => 'CREATE TRIGGER trg_ctr_evento_sem_delete BEFORE DELETE ON crm_contrato_eventos FOR EACH ROW '
        . $bloqueia('Evento de contrato nao se apaga'),
    'trg_ctr_arquivo_sem_update' => 'CREATE TRIGGER trg_ctr_arquivo_sem_update BEFORE UPDATE ON crm_contrato_arquivos FOR EACH ROW '
        . $bloqueia('Arquivo de contrato nao se altera'),
    'trg_ctr_arquivo_sem_delete' => 'CREATE TRIGGER trg_ctr_arquivo_sem_delete BEFORE DELETE ON crm_contrato_arquivos FOR EACH ROW '
        . $bloqueia('Arquivo de contrato nao se apaga'),
    'trg_ctr_versao_fechada' => "CREATE TRIGGER trg_ctr_versao_fechada BEFORE UPDATE ON crm_contrato_versoes FOR EACH ROW BEGIN
          IF OLD.aberta_em IS NOT NULL AND (NOT (NEW.sha256 <=> OLD.sha256) OR NOT (NEW.arquivo_id <=> OLD.arquivo_id)
                                            OR NOT (NEW.aberta_em <=> OLD.aberta_em) OR NOT (NEW.uuid <=> OLD.uuid)) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Versao fechada para assinatura nao troca de arquivo';
          END IF;
        END",
    'trg_ctr_versao_sem_delete' => "CREATE TRIGGER trg_ctr_versao_sem_delete BEFORE DELETE ON crm_contrato_versoes FOR EACH ROW BEGIN
          IF OLD.aberta_em IS NOT NULL THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Versao fechada para assinatura nao se apaga';
          END IF;
        END",
];

foreach ($gatilhos as $nome => $sql) {
    if (gatilhoExiste($pdo, $nome)) { linha("  = gatilho $nome já existe"); continue; }
    linha("  + gatilho $nome");
    if (!$dryRun) $pdo->exec($sql);
}

linha($dryRun ? 'Nada gravado (dry-run).' : 'Pronto.');
