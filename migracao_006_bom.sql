-- ============================================================
-- Migração 006: Produtos Compostos (Bill of Materials - BOM)
-- ============================================================

-- 1. Tipo de produto (simples, composto ou componente)
ALTER TABLE produtos 
ADD COLUMN tipo ENUM('simples', 'composto', 'componente') NOT NULL DEFAULT 'simples' 
AFTER nome;

-- 2. Tabela de Peças / Componentes Cadastrados no Produto (com Quantidade e Cor)
CREATE TABLE IF NOT EXISTS produto_pecas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    quantidade INT NOT NULL DEFAULT 1,
    cor VARCHAR(50) DEFAULT NULL,
    estoque INT NOT NULL DEFAULT 0,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX idx_pecas_produto ON produto_pecas(produto_id);

