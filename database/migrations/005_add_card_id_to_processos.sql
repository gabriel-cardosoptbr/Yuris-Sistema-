-- Migration: add card_id to processos and create processo_prazos, processo_tarefas, processo_history

ALTER TABLE processos ADD COLUMN IF NOT EXISTS card_id INT DEFAULT NULL AFTER alerts;

CREATE TABLE IF NOT EXISTS processo_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    processo_id INT NOT NULL,
    user_email VARCHAR(150),
    acao VARCHAR(100),
    descricao TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(processo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS processo_prazos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    processo_id INT NOT NULL,
    descricao VARCHAR(255) NOT NULL,
    data_limite DATE,
    responsavel VARCHAR(150),
    status ENUM('pendente','concluido','vencido') DEFAULT 'pendente',
    prioridade ENUM('baixa','media','alta') DEFAULT 'media',
    observacao TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX(processo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS processo_tarefas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    processo_id INT NOT NULL,
    titulo VARCHAR(255) NOT NULL,
    concluido TINYINT DEFAULT 0,
    prioridade ENUM('baixa','media','alta') DEFAULT 'media',
    responsavel VARCHAR(150),
    data_tarefa DATE,
    ordem INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX(processo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
