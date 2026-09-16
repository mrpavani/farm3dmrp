-- ============================================================
-- Sistema de Pedidos e Produção - Fábrica de Impressão 3D
-- Schema do banco de dados (MySQL)
-- ============================================================

CREATE DATABASE IF NOT EXISTS pedidos3d CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE pedidos3d;

-- Usuários do sistema (login). Só administradores cadastram usuários.
CREATE TABLE usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    login VARCHAR(150) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    admin TINYINT(1) NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_acesso DATETIME DEFAULT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Clientes que compram os produtos (quem revende, quem é o consumidor final, etc.)
CREATE TABLE clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    cidade VARCHAR(100) DEFAULT NULL,
    estado VARCHAR(2) DEFAULT NULL,
    telefone VARCHAR(30) DEFAULT NULL,
    email VARCHAR(150) DEFAULT NULL,
    descricao TEXT DEFAULT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Catálogo de produtos impressos em 3D
CREATE TABLE produtos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    descricao TEXT DEFAULT NULL,
    preco DECIMAL(10,2) DEFAULT 0,
    ativo TINYINT(1) DEFAULT 1,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Pedido: cabeçalho. Pode ter vários itens (mesmo produto ou produtos diferentes).
--   tipo = venda   -> pedido de um cliente (cliente obrigatório)
--   tipo = estoque -> ordem de fabricação criada pela fábrica, sem cliente
CREATE TABLE pedidos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('venda','estoque') NOT NULL DEFAULT 'venda',
    cliente_id INT DEFAULT NULL,
    usuario_id INT DEFAULT NULL,          -- quem criou o pedido
    data_pedido DATE NOT NULL,
    data_entrega_prometida DATE NOT NULL,
    status ENUM('aberto','em_producao','pronto','entregue','cancelado') NOT NULL DEFAULT 'aberto',
    observacoes TEXT DEFAULT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (cliente_id) REFERENCES clientes(id),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    CONSTRAINT chk_pedidos_cliente_venda CHECK (tipo = 'estoque' OR cliente_id IS NOT NULL)
) ENGINE=InnoDB;

-- Itens do pedido: cada linha é um produto + quantidade dentro de um pedido.
CREATE TABLE pedido_itens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pedido_id INT NOT NULL,
    produto_id INT NOT NULL,
    quantidade INT NOT NULL,
    quantidade_produzida INT NOT NULL DEFAULT 0,
    FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
    FOREIGN KEY (produto_id) REFERENCES produtos(id)
) ENGINE=InnoDB;

-- Produções: cada vez que a fábrica imprime/produz uma quantidade para
-- cumprir um item de pedido, gera-se um registro aqui. Isso permite produção
-- parcial (ex.: pedido de 100 peças produzido em 3 lotes diferentes).
CREATE TABLE producoes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pedido_item_id INT NOT NULL,
    data_producao DATE NOT NULL,
    quantidade INT NOT NULL,
    usuario_id INT DEFAULT NULL,          -- quem registrou a produção
    observacoes TEXT DEFAULT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pedido_item_id) REFERENCES pedido_itens(id) ON DELETE CASCADE,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- Índices úteis para os filtros mais comuns da tela da fábrica
CREATE INDEX idx_pedidos_data_pedido ON pedidos(data_pedido);
CREATE INDEX idx_pedidos_data_entrega ON pedidos(data_entrega_prometida);
CREATE INDEX idx_producoes_data ON producoes(data_producao);
CREATE INDEX idx_clientes_nome ON clientes(nome);
CREATE INDEX idx_pedidos_tipo_status ON pedidos(tipo, status);

-- Administrador inicial — login: admin@mail.com / senha: A123456 (troque após o primeiro acesso)
INSERT INTO usuarios (nome, login, senha_hash, admin) VALUES
    ('Administrador', 'admin@mail.com', '$2y$12$c9jgI75ewT6lyFbhBP0z6O0MVaa2sO6A3wPjO8Ml3mMY1P.CwH8fG', 1);

-- Dados de exemplo (opcional — remova se não quiser)
INSERT INTO produtos (nome, descricao, preco) VALUES
    ('Vaso Geométrico P', 'Vaso decorativo pequeno, PLA', 25.00),
    ('Suporte de Celular', 'Suporte de mesa articulado', 18.00),
    ('Chaveiro Personalizado', 'Chaveiro com nome/logo', 8.00);
