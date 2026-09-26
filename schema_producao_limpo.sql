-- ============================================================
-- Sistema de Pedidos e Produção - Fábrica de Impressão 3D (MRP)
-- Schema Completo para Nova Instalação Limpa (MySQL / Hostinger)
-- ============================================================

USE u182367286_farm;

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Usuários do sistema (login)
CREATE TABLE IF NOT EXISTS `usuarios` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nome` VARCHAR(100) NOT NULL,
    `login` VARCHAR(150) NOT NULL UNIQUE,
    `senha_hash` VARCHAR(255) NOT NULL,
    `admin` TINYINT(1) NOT NULL DEFAULT 0,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `ultimo_acesso` DATETIME DEFAULT NULL,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Clientes
CREATE TABLE IF NOT EXISTS `clientes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nome` VARCHAR(150) NOT NULL,
    `cidade` VARCHAR(100) DEFAULT NULL,
    `estado` VARCHAR(2) DEFAULT NULL,
    `telefone` VARCHAR(30) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `descricao` TEXT DEFAULT NULL,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Catálogo de Produtos
CREATE TABLE IF NOT EXISTS `produtos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nome` VARCHAR(150) NOT NULL,
    `tipo` ENUM('simples', 'composto', 'componente') NOT NULL DEFAULT 'simples',
    `descricao` TEXT DEFAULT NULL,
    `foto` VARCHAR(255) DEFAULT NULL,
    `preco` DECIMAL(10,2) DEFAULT 0.00,
    `estoque` INT NOT NULL DEFAULT 0,
    `peso_gramas` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tempo_producao_segundos` INT NOT NULL DEFAULT 0,
    `tem_embalagem` TINYINT(1) NOT NULL DEFAULT 0,
    `valor_embalagem` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `valor_outros` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `margem_lucro` DECIMAL(10,2) NOT NULL DEFAULT 100.00,
    `custo_filamento` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `custo_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `ativo` TINYINT(1) DEFAULT 1,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Peças cadastradas diretamente no Produto
CREATE TABLE IF NOT EXISTS `produto_pecas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `produto_id` INT NOT NULL,
    `nome` VARCHAR(150) NOT NULL,
    `quantidade` INT NOT NULL DEFAULT 1,
    `estoque` INT NOT NULL DEFAULT 0,
    `peso_gramas` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tempo_producao_segundos` INT NOT NULL DEFAULT 0,
    `foto` VARCHAR(255) DEFAULT NULL,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Variantes de Cores das Peças (com Saldo de Estoque e Peso opcional)
CREATE TABLE IF NOT EXISTS `produto_pecas_cores` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `peca_id` INT NOT NULL,
    `cor` VARCHAR(50) DEFAULT NULL,
    `estoque` INT NOT NULL DEFAULT 0,
    `peso_gramas` DECIMAL(10,2) DEFAULT NULL,
    `tempo_producao_segundos` INT DEFAULT NULL,
    `foto` VARCHAR(255) DEFAULT NULL,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`peca_id`) REFERENCES `produto_pecas`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Variantes de Cores do Produto (Para produtos simples multicor)
CREATE TABLE IF NOT EXISTS `produto_cores` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `produto_id` INT NOT NULL,
    `cor` VARCHAR(50) NOT NULL,
    `peso_gramas` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tempo_producao_segundos` INT NOT NULL DEFAULT 0,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Composição de Produtos (BOM Ficha Técnica N:N)
CREATE TABLE IF NOT EXISTS `produto_composicao` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `produto_pai_id` INT NOT NULL,
    `componente_id` INT NOT NULL,
    `quantidade` INT NOT NULL DEFAULT 1,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`produto_pai_id`) REFERENCES `produtos`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`componente_id`) REFERENCES `produtos`(`id`) ON DELETE RESTRICT,
    UNIQUE KEY `uq_pai_componente` (`produto_pai_id`, `componente_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. Pedidos
CREATE TABLE IF NOT EXISTS `pedidos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tipo` ENUM('venda','estoque') NOT NULL DEFAULT 'venda',
    `cliente_id` INT DEFAULT NULL,
    `usuario_id` INT DEFAULT NULL,
    `data_pedido` DATE NOT NULL,
    `data_entrega_prometida` DATE NOT NULL,
    `status` ENUM('aberto','em_producao','pronto','entregue','cancelado') NOT NULL DEFAULT 'aberto',
    `observacoes` TEXT DEFAULT NULL,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`cliente_id`) REFERENCES `clientes`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. Itens do Pedido
CREATE TABLE IF NOT EXISTS `pedido_itens` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pedido_id` INT NOT NULL,
    `produto_id` INT NOT NULL,
    `preco_unitario` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `quantidade` INT NOT NULL,
    `quantidade_estoque` INT NOT NULL DEFAULT 0,
    `quantidade_produzida` INT NOT NULL DEFAULT 0,
    FOREIGN KEY (`pedido_id`) REFERENCES `pedidos`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 10. Histórico de Produções
CREATE TABLE IF NOT EXISTS `producoes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `pedido_item_id` INT NOT NULL,
    `data_producao` DATE NOT NULL,
    `quantidade` INT NOT NULL,
    `usuario_id` INT DEFAULT NULL,
    `observacoes` TEXT DEFAULT NULL,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`pedido_item_id`) REFERENCES `pedido_itens`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`usuario_id`) REFERENCES `usuarios`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Movimentações de Estoque (Livro Razão)
CREATE TABLE IF NOT EXISTS `movimentos_estoque` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `tipo` ENUM(
        'peca_produzida', 'peca_ajuste', 'peca_consumida',
        'produto_montado', 'produto_ajuste',
        'pedido_atendido', 'pedido_estorno'
    ) NOT NULL,
    `produto_id` INT DEFAULT NULL,
    `peca_id` INT DEFAULT NULL,
    `pedido_item_id` INT DEFAULT NULL,
    `quantidade` INT NOT NULL,
    `saldo_depois` INT DEFAULT NULL,
    `usuario_id` INT DEFAULT NULL,
    `observacoes` VARCHAR(255) DEFAULT NULL,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Filamentos (Catálogo / Estoque de Matéria-Prima)
CREATE TABLE IF NOT EXISTS `filamentos` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `nome` VARCHAR(150) NOT NULL,
    `marca` VARCHAR(100) NOT NULL,
    `tipo` VARCHAR(50) NOT NULL DEFAULT 'PLA',
    `cor` VARCHAR(50) NOT NULL,
    `cor_hex` VARCHAR(7) DEFAULT '#6366f1',
    `estoque_gramas` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `estoque_minimo_gramas` DECIMAL(10,2) NOT NULL DEFAULT 500.00,
    `ativo` TINYINT(1) NOT NULL DEFAULT 1,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Lotes de Filamento (PEPS / FIFO)
CREATE TABLE IF NOT EXISTS `filamento_lotes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `filamento_id` INT NOT NULL,
    `quantidade_rolos` INT NOT NULL DEFAULT 1,
    `peso_rolo_gramas` DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
    `peso_total_gramas` DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
    `preco_rolo` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `preco_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `preco_por_grama` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    `gramas_saldo` DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
    `data_compra` DATE NOT NULL,
    `observacoes` VARCHAR(255) DEFAULT NULL,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`filamento_id`) REFERENCES `filamentos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Movimentações de Filamento (Entradas e Consumos)
CREATE TABLE IF NOT EXISTS `filamento_movimentacoes` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `filamento_id` INT NOT NULL,
    `lote_id` INT DEFAULT NULL,
    `tipo` ENUM('compra', 'consumo_producao', 'ajuste', 'estorno') NOT NULL,
    `gramas` DECIMAL(10,2) NOT NULL,
    `saldo_anterior_gramas` DECIMAL(10,2) NOT NULL,
    `saldo_posterior_gramas` DECIMAL(10,2) NOT NULL,
    `preco_por_grama` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
    `custo_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `peca_id` INT DEFAULT NULL,
    `produto_id` INT DEFAULT NULL,
    `pedido_item_id` INT DEFAULT NULL,
    `usuario_id` INT DEFAULT NULL,
    `observacoes` VARCHAR(255) DEFAULT NULL,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`filamento_id`) REFERENCES `filamentos`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`lote_id`) REFERENCES `filamento_lotes`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Índices
CREATE INDEX idx_pecas_produto ON produto_pecas(produto_id);
CREATE INDEX idx_pecas_cores_peca ON produto_pecas_cores(peca_id);
CREATE INDEX idx_prod_cores_produto ON produto_cores(produto_id);
CREATE INDEX idx_prod_cores_cor ON produto_cores(cor);
CREATE INDEX idx_pedidos_data_pedido ON pedidos(data_pedido);
CREATE INDEX idx_pedidos_data_entrega ON pedidos(data_entrega_prometida);
CREATE INDEX idx_pedidos_tipo_status ON pedidos(tipo, status);
CREATE INDEX idx_producoes_data ON producoes(data_producao);
CREATE INDEX idx_clientes_nome ON clientes(nome);
CREATE INDEX idx_mov_data ON movimentos_estoque(criado_em);
CREATE INDEX idx_mov_peca ON movimentos_estoque(peca_id);
CREATE INDEX idx_mov_produto ON movimentos_estoque(produto_id);
CREATE INDEX idx_mov_tipo ON movimentos_estoque(tipo);
CREATE INDEX idx_filamento_cor ON filamentos(cor);
CREATE INDEX idx_filamento_tipo ON filamentos(tipo);
CREATE INDEX idx_lote_filamento_saldo ON filamento_lotes(filamento_id, gramas_saldo);
CREATE INDEX idx_lote_data ON filamento_lotes(data_compra);
CREATE INDEX idx_mov_filamento ON filamento_movimentacoes(filamento_id);
CREATE INDEX idx_mov_fil_data ON filamento_movimentacoes(criado_em);
CREATE INDEX idx_mov_fil_tipo ON filamento_movimentacoes(tipo);

-- Administrador inicial padrão (login: admin@mail.com / senha: A123456)
INSERT INTO `usuarios` (`id`, `nome`, `login`, `senha_hash`, `admin`, `ativo`)
VALUES (1, 'Administrador', 'admin@mail.com', '$2y$12$c9jgI75ewT6lyFbhBP0z6O0MVaa2sO6A3wPjO8Ml3mMY1P.CwH8fG', 1, 1)
ON DUPLICATE KEY UPDATE `admin` = 1, `ativo` = 1;

SET FOREIGN_KEY_CHECKS = 1;
