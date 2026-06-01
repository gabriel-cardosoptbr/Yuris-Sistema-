-- ═══════════════════════════════════════════════════════════
-- 091_add_dados_pessoais.sql
-- Adiciona dados pessoais + endereço estruturado em CLIENTES e CARDS (Prospecção).
--
-- Motivação: jurídico SaaS precisa desses dados pra montar petições,
-- procurações, contratos. Endereço estruturado em vez de campo único
-- permite usar os pedaços individualmente nos documentos.
--
-- Idempotência: garantida pelo runner PHP (run_091.php) via information_schema.
-- Este SQL é referência; o runner é a fonte da verdade pra apply.
-- ═══════════════════════════════════════════════════════════

-- CLIENTES
ALTER TABLE clientes
  ADD COLUMN rg          VARCHAR(30)  NULL AFTER cpf_cnpj,
  ADD COLUMN nome_mae    VARCHAR(190) NULL AFTER rg,
  ADD COLUMN cep         VARCHAR(10)  NULL AFTER nome_mae,
  ADD COLUMN logradouro  VARCHAR(190) NULL AFTER cep,
  ADD COLUMN numero      VARCHAR(20)  NULL AFTER logradouro,
  ADD COLUMN complemento VARCHAR(100) NULL AFTER numero,
  ADD COLUMN bairro      VARCHAR(100) NULL AFTER complemento,
  ADD COLUMN cidade      VARCHAR(100) NULL AFTER bairro,
  ADD COLUMN uf          CHAR(2)      NULL AFTER cidade;

-- CARDS (Prospecção) — adiciona TODOS, incluindo cpf_cnpj que ainda não existia
ALTER TABLE cards
  ADD COLUMN cpf_cnpj    VARCHAR(20)  NULL AFTER email,
  ADD COLUMN rg          VARCHAR(30)  NULL AFTER cpf_cnpj,
  ADD COLUMN nome_mae    VARCHAR(190) NULL AFTER rg,
  ADD COLUMN cep         VARCHAR(10)  NULL AFTER nome_mae,
  ADD COLUMN logradouro  VARCHAR(190) NULL AFTER cep,
  ADD COLUMN numero      VARCHAR(20)  NULL AFTER logradouro,
  ADD COLUMN complemento VARCHAR(100) NULL AFTER numero,
  ADD COLUMN bairro      VARCHAR(100) NULL AFTER complemento,
  ADD COLUMN cidade      VARCHAR(100) NULL AFTER bairro,
  ADD COLUMN uf          CHAR(2)      NULL AFTER cidade;
