-- ============================================================
-- Migração 007: Fotos de Peças e Produtos
-- ============================================================

-- 1. Foto para peças cadastradas nos produtos
ALTER TABLE produto_pecas 
ADD COLUMN foto VARCHAR(255) DEFAULT NULL 
AFTER cor;

-- 2. Foto para produtos do catálogo
ALTER TABLE produtos 
ADD COLUMN foto VARCHAR(255) DEFAULT NULL 
AFTER descricao;
