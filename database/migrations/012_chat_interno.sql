-- Chat Interno YURIS — tabelas base
-- Executar uma vez via phpMyAdmin ou linha de comando MySQL

CREATE TABLE IF NOT EXISTS chat_conversas (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    nome        VARCHAR(200)    NULL COMMENT 'NULL para conversas diretas; definido para grupos/geral',
    tipo        ENUM('direto','grupo','geral') NOT NULL DEFAULT 'direto',
    criado_por  INT UNSIGNED    NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_tipo       (tipo),
    KEY idx_updated_at (updated_at),
    KEY idx_criado_por (criado_por)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_participantes (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversa_id       INT UNSIGNED NOT NULL,
    usuario_id        INT UNSIGNED NOT NULL,
    ultima_leitura_id INT UNSIGNED NULL DEFAULT NULL COMMENT 'ID da última mensagem lida',
    created_at        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_conv_user (conversa_id, usuario_id),
    KEY idx_usuario (usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_mensagens (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversa_id  INT UNSIGNED NOT NULL,
    usuario_id   INT UNSIGNED NOT NULL,
    mensagem     TEXT         NOT NULL COMMENT 'Texto com tokens de menção: @[tipo|id|display]',
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_conversa   (conversa_id),
    KEY idx_usuario    (usuario_id),
    KEY idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS chat_mencoes (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    mensagem_id    INT UNSIGNED NOT NULL,
    tipo           ENUM('usuario','processo','card') NOT NULL,
    referencia_id  INT UNSIGNED NOT NULL,
    texto_exibido  VARCHAR(200) NOT NULL,
    url_destino    VARCHAR(500) NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_mensagem (mensagem_id),
    KEY idx_tipo_ref (tipo, referencia_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
