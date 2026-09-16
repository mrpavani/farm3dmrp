-- ============================================================
-- Migração 001 — adiciona e-mail e descrição ao cadastro de clientes.
-- Rode apenas em bancos criados com a versão anterior do schema.sql:
--   mysql -u root -p pedidos3d < migracao_001_clientes_email_descricao.sql
-- ============================================================

ALTER TABLE clientes
    ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER telefone,
    ADD COLUMN descricao TEXT DEFAULT NULL AFTER email;

CREATE INDEX idx_clientes_nome ON clientes(nome);
