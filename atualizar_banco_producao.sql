-- ============================================================
-- ATUALIZAÇÃO COMPLETA DA ESTRUTURA DO BANCO DE DADOS (PRODUÇÃO)
-- Sistema: Fábrica 3D (MRP / Gestão de Impressão 3D)
-- Versão: 2.0 (Consolida migrações 001 até 013)
-- 
-- CARACTERÍSTICAS DESTE SCRIPT:
-- 1. 100% IDEMPOTENTE: Pode ser executado em bancos novos ou já existentes
--    quantas vezes quiser sem gerar erro ("Duplicate column/table/key").
-- 2. NÃO DESTRUTIVO: Não apaga clientes, pedidos, produtos ou movimentações existentes.
-- 3. COBERTURA TOTAL:
--    - Módulo de Usuários e Permissões (Admin)
--    - Módulo de Clientes e Pedidos (Venda e Ordens Internas de Estoque)
--    - Módulo de Produtos Simples, Compostos e Componentes (BOM)
--    - Módulo de Peças, Fotos e Variantes Multicor
--    - Módulo de Peso em Gramas e Tempo de Impressão (Peças e Cores)
--    - Módulo de Precificação Dinâmica (Embalagem, Custos Adicionais, Margem)
--    - Módulo de Gestão de Filamentos (Lotes PEPS/FIFO, Custo por Grama, Movimentações)
--    - Módulo de Histórico de Produção e Livro de Movimentações de Estoque
--
-- COMO EXECUTAR NO phpMyAdmin (Hostinger / cPanel / Local):
-- 1. Acesse o phpMyAdmin
-- 2. Selecione o banco de dados da aplicação (ex.: u182367286_farm)
-- 3. Clique na aba superior "SQL"
-- 4. Copie todo o conteúdo deste arquivo, cole na caixa de texto e clique em "Executar"
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ============================================================
-- PARTE 1: CRIAÇÃO DE TODAS AS TABELAS SE NÃO EXISTIREM
-- ============================================================

-- 1. TABELA DE USUÁRIOS
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

-- 2. TABELA DE CLIENTES
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

-- 3. TABELA DE PRODUTOS
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

-- 4. TABELA DE PEÇAS DE PRODUTOS
CREATE TABLE IF NOT EXISTS `produto_pecas` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `produto_id` INT NOT NULL,
    `nome` VARCHAR(150) NOT NULL,
    `quantidade` INT NOT NULL DEFAULT 1,
    `peso_gramas` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tempo_producao_segundos` INT NOT NULL DEFAULT 0,
    `foto` VARCHAR(255) DEFAULT NULL,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. TABELA DE VARIANTES DE COR DAS PEÇAS
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

-- 6. TABELA DE VARIANTES DE COR DO PRODUTO (Para produtos simples multicor)
CREATE TABLE IF NOT EXISTS `produto_cores` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `produto_id` INT NOT NULL,
    `cor` VARCHAR(50) NOT NULL,
    `peso_gramas` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    `tempo_producao_segundos` INT NOT NULL DEFAULT 0,
    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`produto_id`) REFERENCES `produtos`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. TABELA DE COMPOSIÇÃO DE PRODUTOS (Ficha Técnica / BOM N:N)
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

-- 8. TABELA DE PEDIDOS
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

-- 9. TABELA DE ITENS DO PEDIDO
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

-- 10. TABELA DE HISTÓRICO DE PRODUÇÕES
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

-- 11. TABELA DE LIVRO DE MOVIMENTAÇÕES DE ESTOQUE
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

-- 12. TABELA DE FILAMENTOS (CATÁLOGO / ESTOQUE CONSOLIDADO)
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

-- 13. TABELA DE LOTES DE FILAMENTO (PEPS / FIFO)
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

-- 14. TABELA DE MOVIMENTAÇÕES DE FILAMENTO (ENTRADAS / CONSUMOS)
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

-- ============================================================
-- PARTE 2: ATUALIZAÇÃO CONDICIONAL DE COLUNAS EM TABELAS EXISTENTES
-- (Garante que bancos pré-existentes recebam todas as colunas novas sem erro)
-- ============================================================

-- usuarios.admin
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'admin') > 0, 'SELECT 1', 'ALTER TABLE `usuarios` ADD COLUMN `admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `senha_hash`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- usuarios.ativo
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'ativo') > 0, 'SELECT 1', 'ALTER TABLE `usuarios` ADD COLUMN `ativo` TINYINT(1) NOT NULL DEFAULT 1 AFTER `admin`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- usuarios.ultimo_acesso
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'usuarios' AND COLUMN_NAME = 'ultimo_acesso') > 0, 'SELECT 1', 'ALTER TABLE `usuarios` ADD COLUMN `ultimo_acesso` DATETIME DEFAULT NULL AFTER `ativo`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- clientes.email
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'email') > 0, 'SELECT 1', 'ALTER TABLE `clientes` ADD COLUMN `email` VARCHAR(150) DEFAULT NULL AFTER `telefone`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- clientes.descricao
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'descricao') > 0, 'SELECT 1', 'ALTER TABLE `clientes` ADD COLUMN `descricao` TEXT DEFAULT NULL AFTER `email`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produtos.tipo
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'tipo') > 0, 'SELECT 1', "ALTER TABLE `produtos` ADD COLUMN `tipo` ENUM('simples','composto','componente') NOT NULL DEFAULT 'simples' AFTER `nome`"));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produtos.foto
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'foto') > 0, 'SELECT 1', 'ALTER TABLE `produtos` ADD COLUMN `foto` VARCHAR(255) DEFAULT NULL AFTER `descricao`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produtos.estoque
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'estoque') > 0, 'SELECT 1', 'ALTER TABLE `produtos` ADD COLUMN `estoque` INT NOT NULL DEFAULT 0 AFTER `preco`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produtos.peso_gramas
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'peso_gramas') > 0, 'SELECT 1', 'ALTER TABLE `produtos` ADD COLUMN `peso_gramas` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `estoque`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produtos.tempo_producao_segundos
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'tempo_producao_segundos') > 0, 'SELECT 1', 'ALTER TABLE `produtos` ADD COLUMN `tempo_producao_segundos` INT NOT NULL DEFAULT 0 AFTER `peso_gramas`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produtos.tem_embalagem
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'tem_embalagem') > 0, 'SELECT 1', 'ALTER TABLE `produtos` ADD COLUMN `tem_embalagem` TINYINT(1) NOT NULL DEFAULT 0 AFTER `tempo_producao_segundos`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produtos.valor_embalagem
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'valor_embalagem') > 0, 'SELECT 1', 'ALTER TABLE `produtos` ADD COLUMN `valor_embalagem` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `tem_embalagem`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produtos.valor_outros
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'valor_outros') > 0, 'SELECT 1', 'ALTER TABLE `produtos` ADD COLUMN `valor_outros` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `valor_embalagem`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produtos.margem_lucro
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'margem_lucro') > 0, 'SELECT 1', 'ALTER TABLE `produtos` ADD COLUMN `margem_lucro` DECIMAL(10,2) NOT NULL DEFAULT 100.00 AFTER `valor_outros`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produtos.custo_filamento
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'custo_filamento') > 0, 'SELECT 1', 'ALTER TABLE `produtos` ADD COLUMN `custo_filamento` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `margem_lucro`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produtos.custo_total
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'custo_total') > 0, 'SELECT 1', 'ALTER TABLE `produtos` ADD COLUMN `custo_total` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `custo_filamento`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produto_pecas.foto
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_pecas' AND COLUMN_NAME = 'foto') > 0, 'SELECT 1', 'ALTER TABLE `produto_pecas` ADD COLUMN `foto` VARCHAR(255) DEFAULT NULL AFTER `quantidade`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produto_pecas.peso_gramas
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_pecas' AND COLUMN_NAME = 'peso_gramas') > 0, 'SELECT 1', 'ALTER TABLE `produto_pecas` ADD COLUMN `peso_gramas` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `quantidade`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produto_pecas.tempo_producao_segundos
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_pecas' AND COLUMN_NAME = 'tempo_producao_segundos') > 0, 'SELECT 1', 'ALTER TABLE `produto_pecas` ADD COLUMN `tempo_producao_segundos` INT NOT NULL DEFAULT 0 AFTER `peso_gramas`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produto_pecas_cores.peso_gramas
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_pecas_cores' AND COLUMN_NAME = 'peso_gramas') > 0, 'SELECT 1', 'ALTER TABLE `produto_pecas_cores` ADD COLUMN `peso_gramas` DECIMAL(10,2) DEFAULT NULL AFTER `estoque`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produto_pecas_cores.tempo_producao_segundos
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_pecas_cores' AND COLUMN_NAME = 'tempo_producao_segundos') > 0, 'SELECT 1', 'ALTER TABLE `produto_pecas_cores` ADD COLUMN `tempo_producao_segundos` INT DEFAULT NULL AFTER `peso_gramas`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produto_pecas_cores.foto
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_pecas_cores' AND COLUMN_NAME = 'foto') > 0, 'SELECT 1', 'ALTER TABLE `produto_pecas_cores` ADD COLUMN `foto` VARCHAR(255) DEFAULT NULL AFTER `tempo_producao_segundos`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- pedidos.tipo
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'tipo') > 0, 'SELECT 1', "ALTER TABLE `pedidos` ADD COLUMN `tipo` ENUM('venda','estoque') NOT NULL DEFAULT 'venda' AFTER `id`"));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- pedidos.usuario_id
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'usuario_id') > 0, 'SELECT 1', 'ALTER TABLE `pedidos` ADD COLUMN `usuario_id` INT NULL AFTER `cliente_id`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- pedido_itens.preco_unitario
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedido_itens' AND COLUMN_NAME = 'preco_unitario') > 0, 'SELECT 1', 'ALTER TABLE `pedido_itens` ADD COLUMN `preco_unitario` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `produto_id`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- pedido_itens.quantidade_estoque
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedido_itens' AND COLUMN_NAME = 'quantidade_estoque') > 0, 'SELECT 1', 'ALTER TABLE `pedido_itens` ADD COLUMN `quantidade_estoque` INT NOT NULL DEFAULT 0 AFTER `quantidade`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- pedido_itens.quantidade_produzida
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedido_itens' AND COLUMN_NAME = 'quantidade_produzida') > 0, 'SELECT 1', 'ALTER TABLE `pedido_itens` ADD COLUMN `quantidade_produzida` INT NOT NULL DEFAULT 0 AFTER `quantidade_estoque`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- producoes.usuario_id
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'producoes' AND COLUMN_NAME = 'usuario_id') > 0, 'SELECT 1', 'ALTER TABLE `producoes` ADD COLUMN `usuario_id` INT NULL AFTER `quantidade`'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- PARTE 3: CRIAÇÃO SEGURA DE ÍNDICES (SE NÃO EXISTIREM)
-- ============================================================

-- produto_pecas (produto_id)
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_pecas' AND INDEX_NAME = 'idx_pecas_produto') > 0, 'SELECT 1', 'CREATE INDEX idx_pecas_produto ON produto_pecas(produto_id)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produto_pecas_cores (peca_id)
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_pecas_cores' AND INDEX_NAME = 'idx_pecas_cores_peca') > 0, 'SELECT 1', 'CREATE INDEX idx_pecas_cores_peca ON produto_pecas_cores(peca_id)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- produto_cores (produto_id, cor)
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_cores' AND INDEX_NAME = 'idx_prod_cores_produto') > 0, 'SELECT 1', 'CREATE INDEX idx_prod_cores_produto ON produto_cores(produto_id)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_cores' AND INDEX_NAME = 'idx_prod_cores_cor') > 0, 'SELECT 1', 'CREATE INDEX idx_prod_cores_cor ON produto_cores(cor)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- pedidos (filtros frequentes)
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND INDEX_NAME = 'idx_pedidos_data_pedido') > 0, 'SELECT 1', 'CREATE INDEX idx_pedidos_data_pedido ON pedidos(data_pedido)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND INDEX_NAME = 'idx_pedidos_data_entrega') > 0, 'SELECT 1', 'CREATE INDEX idx_pedidos_data_entrega ON pedidos(data_entrega_prometida)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND INDEX_NAME = 'idx_pedidos_tipo_status') > 0, 'SELECT 1', 'CREATE INDEX idx_pedidos_tipo_status ON pedidos(tipo, status)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- producoes (data_producao)
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'producoes' AND INDEX_NAME = 'idx_producoes_data') > 0, 'SELECT 1', 'CREATE INDEX idx_producoes_data ON producoes(data_producao)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- clientes (nome)
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND INDEX_NAME = 'idx_clientes_nome') > 0, 'SELECT 1', 'CREATE INDEX idx_clientes_nome ON clientes(nome)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- movimentos_estoque (criado_em, peca_id, produto_id, tipo)
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimentos_estoque' AND INDEX_NAME = 'idx_mov_data') > 0, 'SELECT 1', 'CREATE INDEX idx_mov_data ON movimentos_estoque(criado_em)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimentos_estoque' AND INDEX_NAME = 'idx_mov_peca') > 0, 'SELECT 1', 'CREATE INDEX idx_mov_peca ON movimentos_estoque(peca_id)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimentos_estoque' AND INDEX_NAME = 'idx_mov_produto') > 0, 'SELECT 1', 'CREATE INDEX idx_mov_produto ON movimentos_estoque(produto_id)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimentos_estoque' AND INDEX_NAME = 'idx_mov_tipo') > 0, 'SELECT 1', 'CREATE INDEX idx_mov_tipo ON movimentos_estoque(tipo)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- filamentos (cor, tipo)
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'filamentos' AND INDEX_NAME = 'idx_filamento_cor') > 0, 'SELECT 1', 'CREATE INDEX idx_filamento_cor ON filamentos(cor)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'filamentos' AND INDEX_NAME = 'idx_filamento_tipo') > 0, 'SELECT 1', 'CREATE INDEX idx_filamento_tipo ON filamentos(tipo)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- filamento_lotes (filamento_id + gramas_saldo, data_compra)
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'filamento_lotes' AND INDEX_NAME = 'idx_lote_filamento_saldo') > 0, 'SELECT 1', 'CREATE INDEX idx_lote_filamento_saldo ON filamento_lotes(filamento_id, gramas_saldo)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'filamento_lotes' AND INDEX_NAME = 'idx_lote_data') > 0, 'SELECT 1', 'CREATE INDEX idx_lote_data ON filamento_lotes(data_compra)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- filamento_movimentacoes (filamento_id, data, tipo)
SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'filamento_movimentacoes' AND INDEX_NAME = 'idx_mov_filamento') > 0, 'SELECT 1', 'CREATE INDEX idx_mov_filamento ON filamento_movimentacoes(filamento_id)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'filamento_movimentacoes' AND INDEX_NAME = 'idx_mov_data') > 0, 'SELECT 1', 'CREATE INDEX idx_mov_data ON filamento_movimentacoes(criado_em)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s = (SELECT IF((SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'filamento_movimentacoes' AND INDEX_NAME = 'idx_mov_tipo') > 0, 'SELECT 1', 'CREATE INDEX idx_mov_tipo ON filamento_movimentacoes(tipo)'));
PREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ============================================================
-- PARTE 4: MIGRAÇÃO DE DADOS HISTÓRICOS & INTEGRIDADE
-- ============================================================

-- 1. Migração de variantes de produto_pecas (se as colunas legadas 'cor' ou 'estoque' ainda existirem)
SET @tem_cor = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_pecas' AND COLUMN_NAME = 'cor');
SET @s1 = (SELECT IF(@tem_cor > 0, 'INSERT INTO `produto_pecas_cores` (`peca_id`, `cor`, `estoque`, `foto`, `criado_em`) SELECT `id`, `cor`, `estoque`, `foto`, `criado_em` FROM `produto_pecas` WHERE `id` NOT IN (SELECT DISTINCT `peca_id` FROM `produto_pecas_cores`)', 'SELECT 1'));
PREPARE stmt FROM @s1; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @s2 = (SELECT IF(@tem_cor > 0, 'ALTER TABLE `produto_pecas` DROP COLUMN `cor`', 'SELECT 1'));
PREPARE stmt FROM @s2; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 1. Assegurar coluna estoque em produto_pecas (peça física com estoque unificado)
SET @tem_est = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produto_pecas' AND COLUMN_NAME = 'estoque');
SET @s3 = (SELECT IF(@tem_est = 0, 'ALTER TABLE `produto_pecas` ADD COLUMN `estoque` INT NOT NULL DEFAULT 0 AFTER `quantidade`', 'SELECT 1'));
PREPARE stmt FROM @s3; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: unifica saldo físico na peça a partir do estoque das cores (migração 014)
UPDATE `produto_pecas` pp
SET pp.estoque = (
    SELECT COALESCE(MAX(pc.estoque), 0)
    FROM `produto_pecas_cores` pc
    WHERE pc.peca_id = pp.id
)
WHERE pp.estoque = 0
  AND EXISTS (SELECT 1 FROM `produto_pecas_cores` pc WHERE pc.peca_id = pp.id AND pc.estoque > 0);

-- 2. Preenchimento de preco_unitario em itens antigos que estejam zerados
UPDATE `pedido_itens` pi
JOIN `produtos` p ON p.id = pi.produto_id
SET pi.preco_unitario = p.preco
WHERE pi.preco_unitario = 0.00;

-- 3. Criação ou atualização do Usuário Administrador Padrão
-- Login: admin@mail.com | Senha padrão: A123456 (troque no primeiro acesso em Perfil)
INSERT INTO `usuarios` (`id`, `nome`, `login`, `senha_hash`, `admin`, `ativo`)
VALUES (1, 'Administrador', 'admin@mail.com', '$2y$12$c9jgI75ewT6lyFbhBP0z6O0MVaa2sO6A3wPjO8Ml3mMY1P.CwH8fG', 1, 1)
ON DUPLICATE KEY UPDATE `admin` = 1, `ativo` = 1;

-- ============================================================
-- FINALIZAÇÃO E ATIVAÇÃO DAS RESTRIÇÕES
-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;

-- Verificação da integridade das 14 tabelas principais
SELECT 'Verificação concluída!' AS `Status`,
       (SELECT COUNT(*) FROM `usuarios`) AS `usuarios`,
       (SELECT COUNT(*) FROM `clientes`) AS `clientes`,
       (SELECT COUNT(*) FROM `produtos`) AS `produtos`,
       (SELECT COUNT(*) FROM `produto_pecas`) AS `pecas`,
       (SELECT COUNT(*) FROM `produto_pecas_cores`) AS `pecas_cores`,
       (SELECT COUNT(*) FROM `produto_cores`) AS `produto_cores`,
       (SELECT COUNT(*) FROM `filamentos`) AS `filamentos`,
       (SELECT COUNT(*) FROM `pedidos`) AS `pedidos`;
