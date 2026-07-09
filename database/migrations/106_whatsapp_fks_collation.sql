-- 106_whatsapp_fks_collation.sql
--
-- Onda 3 da auditoria (janela): integridade referencial (J1-J4) + collation (H2).
-- Recon de prod (2026-07-06): 0 account_id NULL, 0 instance_id orfao, 0 account_id
-- dangling em TODAS as tabelas -> as FKs passam. O delete-instancia do Master ja
-- limpa os filhos em ordem (patch adiciona whatsapp_chat_processos a essa limpeza),
-- entao FK RESTRICT nao quebra o "Excluir".
--
-- J4 + H2: whatsapp_group_members e whatsapp_reactions estao em int UNSIGNED e
-- collation general_ci (o resto do modulo e int signed + unicode_ci). Alinhamos os
-- dois: sem isso a FK nao casa o tipo e o JOIN precisa de COLLATE inline (que mata o
-- indice composto). Tabelas pequenas -> CONVERT rapido.

ALTER TABLE whatsapp_group_members
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  MODIFY instance_id INT NOT NULL,
  MODIFY account_id  INT NOT NULL;

ALTER TABLE whatsapp_reactions
  CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  MODIFY instance_id INT NOT NULL,
  MODIFY account_id  INT NOT NULL;

-- J1: FK instance_id -> whatsapp_instances(id). RESTRICT (nunca CASCADE: delete de
-- instancia passa pela limpeza explicita do Master, nao apaga msgs em avalanche).
ALTER TABLE whatsapp_messages       ADD CONSTRAINT fk_wa_msg_inst  FOREIGN KEY (instance_id) REFERENCES whatsapp_instances(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE whatsapp_chats          ADD CONSTRAINT fk_wa_chat_inst FOREIGN KEY (instance_id) REFERENCES whatsapp_instances(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE whatsapp_contacts       ADD CONSTRAINT fk_wa_ctt_inst  FOREIGN KEY (instance_id) REFERENCES whatsapp_instances(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE whatsapp_group_members  ADD CONSTRAINT fk_wa_gm_inst   FOREIGN KEY (instance_id) REFERENCES whatsapp_instances(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE whatsapp_reactions      ADD CONSTRAINT fk_wa_rx_inst   FOREIGN KEY (instance_id) REFERENCES whatsapp_instances(id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- chat_processos pode ter linha ORFA de delete de instancia antigo (o delete manual do
-- Master nao limpava chat_processos ate esta onda). Apaga o orfao ANTES da FK, senao
-- ERROR 1452. Em prod (2026-07-06) foram 2 linhas.
DELETE cp FROM whatsapp_chat_processos cp
  LEFT JOIN whatsapp_instances i ON i.id = cp.instance_id
 WHERE i.id IS NULL;
ALTER TABLE whatsapp_chat_processos ADD CONSTRAINT fk_wa_cp_inst   FOREIGN KEY (instance_id) REFERENCES whatsapp_instances(id) ON DELETE RESTRICT ON UPDATE RESTRICT;

-- J2 + J3: FK account_id -> accounts(id). Mantem NULL onde ja e nullable (nao forco
-- NOT NULL: chat_processos tem 1 linha legada com account_id NULL). FK aceita NULL.
ALTER TABLE whatsapp_messages       ADD CONSTRAINT fk_wa_msg_acc  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE whatsapp_chats          ADD CONSTRAINT fk_wa_chat_acc FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE whatsapp_contacts       ADD CONSTRAINT fk_wa_ctt_acc  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE whatsapp_group_members  ADD CONSTRAINT fk_wa_gm_acc   FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE whatsapp_reactions      ADD CONSTRAINT fk_wa_rx_acc   FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
ALTER TABLE whatsapp_chat_processos ADD CONSTRAINT fk_wa_cp_acc   FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT ON UPDATE RESTRICT;
