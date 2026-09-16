-- ============================================================
-- ATUALIZAÇÃO PARA PRODUÇÃO (Bancos já existentes)
-- Este arquivo contém todas as migrações (001 a 004) unificadas.
-- Rode este arquivo apenas se você já tinha o sistema rodando em produção
-- antes das atualizações recentes.
-- ============================================================

-- ------------------------------------------------------------
-- Migração 001
-- ------------------------------------------------------------
ALTER TABLE clientes
    ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER telefone,
    ADD COLUMN descricao TEXT DEFAULT NULL AFTER email;

CREATE INDEX idx_clientes_nome ON clientes(nome);

-- ------------------------------------------------------------
-- Migração 002
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    login VARCHAR(50) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_acesso DATETIME DEFAULT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

UPDATE pedidos
   SET observacoes = CONCAT_WS('\n', NULLIF(observacoes, ''), CONCAT('Vendedor (antigo): ', vendedor))
 WHERE vendedor IS NOT NULL AND vendedor <> '';

ALTER TABLE pedidos
    ADD COLUMN usuario_id INT NULL AFTER cliente_id,
    ADD CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    DROP COLUMN vendedor;

UPDATE producoes
   SET observacoes = CONCAT_WS('\n', NULLIF(observacoes, ''), CONCAT('Responsável (antigo): ', responsavel))
 WHERE responsavel IS NOT NULL AND responsavel <> '';

ALTER TABLE producoes
    ADD COLUMN usuario_id INT NULL AFTER quantidade,
    ADD CONSTRAINT fk_producoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    DROP COLUMN responsavel;

-- ------------------------------------------------------------
-- Migração 003
-- ------------------------------------------------------------
ALTER TABLE usuarios
    MODIFY login VARCHAR(150) NOT NULL,
    ADD COLUMN admin TINYINT(1) NOT NULL DEFAULT 0 AFTER senha_hash;

INSERT INTO usuarios (nome, login, senha_hash, admin, ativo)
VALUES ('Administrador', 'admin@mail.com', '$2y$12$c9jgI75ewT6lyFbhBP0z6O0MVaa2sO6A3wPjO8Ml3mMY1P.CwH8fG', 1, 1)
ON DUPLICATE KEY UPDATE admin = 1, ativo = 1;

-- ------------------------------------------------------------
-- Migração 004
-- ------------------------------------------------------------
ALTER TABLE pedidos
    ADD COLUMN tipo ENUM('venda','estoque') NOT NULL DEFAULT 'venda' AFTER id,
    MODIFY cliente_id INT NULL,
    ADD CONSTRAINT chk_pedidos_cliente_venda CHECK (tipo = 'estoque' OR cliente_id IS NOT NULL);

CREATE INDEX idx_pedidos_tipo_status ON pedidos(tipo, status);

-- ------------------------------------------------------------
-- Migração 005
-- ------------------------------------------------------------
ALTER TABLE produtos
    ADD COLUMN estoque INT NOT NULL DEFAULT 0 AFTER preco;

ALTER TABLE pedido_itens
    ADD COLUMN quantidade_estoque INT NOT NULL DEFAULT 0 AFTER quantidade;
