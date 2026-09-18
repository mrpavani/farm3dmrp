-- ============================================================
-- ATUALIZAÇÃO DO BANCO DE DADOS EM PRODUÇÃO
-- Sistema: Fábrica 3D (MRP / Gestão de Impressão 3D)
--
-- Como executar:
-- 1. Acesse o phpMyAdmin da Hostinger / cPanel
-- 2. Selecione o banco de dados de produção (ex: u182367286_farm)
-- 3. Vá na aba "SQL"
-- 4. Cole o conteúdo deste arquivo e clique em "Executar"
--
-- NOTA: Você também pode simplesmente acessar no navegador:
--       https://farm3d.mrplabs.com.br/migrate.php
-- ============================================================

-- 1. Saldo de estoque em produtos (caso ainda não tenha)
ALTER TABLE produtos 
    ADD COLUMN IF NOT EXISTS estoque INT NOT NULL DEFAULT 0 AFTER preco;

-- 2. Quantidade atendida por estoque nos itens do pedido
ALTER TABLE pedido_itens 
    ADD COLUMN IF NOT EXISTS quantidade_estoque INT NOT NULL DEFAULT 0 AFTER quantidade;

-- 3. Tipo do produto (simples, composto ou componente)
ALTER TABLE produtos 
    ADD COLUMN IF NOT EXISTS tipo ENUM('simples', 'composto', 'componente') NOT NULL DEFAULT 'simples' AFTER nome;

-- 4. Tabela de Peças / Componentes cadastrados no Produto
CREATE TABLE IF NOT EXISTS produto_pecas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_id INT NOT NULL,
    nome VARCHAR(150) NOT NULL,
    quantidade INT NOT NULL DEFAULT 1,
    cor VARCHAR(50) DEFAULT NULL,
    foto VARCHAR(255) DEFAULT NULL,
    estoque INT NOT NULL DEFAULT 0,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE INDEX IF NOT EXISTS idx_pecas_produto ON produto_pecas(produto_id);

-- 5. Coluna de foto nas peças (caso a tabela já existisse sem ela)
ALTER TABLE produto_pecas 
    ADD COLUMN IF NOT EXISTS foto VARCHAR(255) DEFAULT NULL AFTER cor;

-- 6. Coluna de foto no catálogo de produtos
ALTER TABLE produtos 
    ADD COLUMN IF NOT EXISTS foto VARCHAR(255) DEFAULT NULL AFTER descricao;

-- 7. Tabela opcional de composição direta entre produtos (BOM N:N)
CREATE TABLE IF NOT EXISTS produto_composicao (
    id INT AUTO_INCREMENT PRIMARY KEY,
    produto_pai_id INT NOT NULL,
    componente_id INT NOT NULL,
    quantidade INT NOT NULL DEFAULT 1,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (produto_pai_id) REFERENCES produtos(id) ON DELETE CASCADE,
    FOREIGN KEY (componente_id) REFERENCES produtos(id) ON DELETE RESTRICT,
    UNIQUE KEY uq_pai_componente (produto_pai_id, componente_id)
) ENGINE=InnoDB;
