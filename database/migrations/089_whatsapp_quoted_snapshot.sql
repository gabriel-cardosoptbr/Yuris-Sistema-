-- 089_whatsapp_quoted_snapshot.sql
-- Snapshot da mensagem citada (reply) embutido no próprio payload do WhatsApp.
--
-- Problema: o preview da citação só aparecia se a mensagem ORIGINAL já estivesse
-- sincronizada no YURIS (JOIN por quoted_wamid). Ao responder uma mensagem antiga
-- ou nunca sincronizada (ex.: link do Milton, "Teste 02"), a citação não exibia
-- nada — nem autor, nem texto.
--
-- Solução: o WhatsApp envia, dentro de contextInfo, o autor (participant) e um
-- trecho (quotedMessage) da mensagem citada. Guardamos esse snapshot direto na
-- linha da resposta. Assim a citação SEMPRE aparece, independente de ter a
-- original no banco. (O JOIN por quoted_wamid continua tendo prioridade quando a
-- original existe — o snapshot é o fallback.)
--
-- Idempotente: só adiciona se a coluna ainda não existir.

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'whatsapp_messages'
               AND COLUMN_NAME = 'quoted_sender_name');
SET @sql := IF(@col = 0,
  'ALTER TABLE whatsapp_messages ADD COLUMN quoted_sender_name VARCHAR(255) NULL AFTER quoted_wamid',
  'SELECT 1');
PREPARE st FROM @sql; EXECUTE st; DEALLOCATE PREPARE st;

SET @col2 := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE()
                AND TABLE_NAME = 'whatsapp_messages'
                AND COLUMN_NAME = 'quoted_text');
SET @sql2 := IF(@col2 = 0,
  'ALTER TABLE whatsapp_messages ADD COLUMN quoted_text VARCHAR(500) NULL AFTER quoted_sender_name',
  'SELECT 1');
PREPARE st2 FROM @sql2; EXECUTE st2; DEALLOCATE PREPARE st2;
