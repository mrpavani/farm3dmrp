-- ============================================================
-- Migração 005 — controle de estoque integrado à produção.
-- Adiciona saldo de estoque aos produtos e registra quanto 
-- de um pedido de venda foi atendido pelo estoque pronto.
--
--   mysql -u root -p pedidos3d < migracao_005_estoque.sql
-- ============================================================

ALTER TABLE produtos
    ADD COLUMN estoque INT NOT NULL DEFAULT 0 AFTER preco;

ALTER TABLE pedido_itens
    ADD COLUMN quantidade_estoque INT NOT NULL DEFAULT 0 AFTER quantidade;
