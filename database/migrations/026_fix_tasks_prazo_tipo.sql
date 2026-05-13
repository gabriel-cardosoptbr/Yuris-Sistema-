-- 026_fix_tasks_prazo_tipo.sql
-- O que faz: Converte tasks.prazo_tipo de ENUM fixo para VARCHAR(50).
--            O sistema agora suporta tipos de prazo personalizados (via localStorage),
--            então o ENUM restrito a ('legal','interno','administrativo') quebra
--            ao salvar novos tipos criados pelo usuário.

SET NAMES utf8mb4;

ALTER TABLE `tasks`
  MODIFY COLUMN `prazo_tipo` VARCHAR(50) NOT NULL DEFAULT 'interno';
