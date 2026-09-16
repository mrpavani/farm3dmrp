-- ============================================================
-- Migração 004 — produção para estoque (sem pedido de cliente).
-- Um "pedido" passa a ter um tipo:
--   venda   -> pedido de cliente (cliente obrigatório)
--   estoque -> ordem de fabricação criada pela fábrica (sem cliente)
--
--   mysql -u root -p pedidos3d < migracao_004_producao_estoque.sql
-- ============================================================

ALTER TABLE pedidos
    ADD COLUMN tipo ENUM('venda','estoque') NOT NULL DEFAULT 'venda' AFTER id,
    MODIFY cliente_id INT NULL,
    ADD CONSTRAINT chk_pedidos_cliente_venda CHECK (tipo = 'estoque' OR cliente_id IS NOT NULL);

CREATE INDEX idx_pedidos_tipo_status ON pedidos(tipo, status);
