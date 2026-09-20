<?php
// Script para aplicar as colunas de estoque no banco de dados.
// Acesse este arquivo pelo navegador para rodar a migração.
// Exemplo: https://farm3d.mrplabs.com.br/migrate.php

require_once __DIR__ . '/includes/db.php';
$pdo = getDB();

echo "<h1>Atualização do Banco de Dados</h1>";

// 1. Adicionar coluna 'estoque' na tabela 'produtos'
try {
    $pdo->exec("ALTER TABLE produtos ADD COLUMN estoque INT NOT NULL DEFAULT 0 AFTER preco");
    echo "<p>✅ Coluna <b>estoque</b> adicionada na tabela <i>produtos</i> com sucesso!</p>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "<p>⚠️ Coluna <b>estoque</b> já existe na tabela <i>produtos</i>.</p>";
    } else {
        echo "<p>❌ Erro ao adicionar coluna <b>estoque</b>: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// 2. Adicionar coluna 'quantidade_estoque' na tabela 'pedido_itens'
try {
    $pdo->exec("ALTER TABLE pedido_itens ADD COLUMN quantidade_estoque INT NOT NULL DEFAULT 0 AFTER quantidade");
    echo "<p>✅ Coluna <b>quantidade_estoque</b> adicionada na tabela <i>pedido_itens</i> com sucesso!</p>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "<p>⚠️ Coluna <b>quantidade_estoque</b> já existe na tabela <i>pedido_itens</i>.</p>";
    } else {
        echo "<p>❌ Erro ao adicionar coluna <b>quantidade_estoque</b>: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// 3. Adicionar coluna 'tipo' na tabela 'produtos' (simples, composto, componente)
try {
    $pdo->exec("ALTER TABLE produtos ADD COLUMN tipo ENUM('simples', 'composto', 'componente') NOT NULL DEFAULT 'simples' AFTER nome");
    echo "<p>✅ Coluna <b>tipo</b> adicionada na tabela <i>produtos</i> com sucesso!</p>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "<p>⚠️ Coluna <b>tipo</b> já existe na tabela <i>produtos</i>.</p>";
    } else {
        echo "<p>❌ Erro ao adicionar coluna <b>tipo</b>: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// 4. Criar tabela 'produto_composicao' (BOM - Ficha Técnica)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS produto_composicao (
            id INT AUTO_INCREMENT PRIMARY KEY,
            produto_pai_id INT NOT NULL,
            componente_id INT NOT NULL,
            quantidade INT NOT NULL DEFAULT 1,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (produto_pai_id) REFERENCES produtos(id) ON DELETE CASCADE,
            FOREIGN KEY (componente_id) REFERENCES produtos(id) ON DELETE RESTRICT,
            UNIQUE KEY uq_pai_componente (produto_pai_id, componente_id),
            CONSTRAINT chk_nao_auto_relacionado CHECK (produto_pai_id <> componente_id)
        ) ENGINE=InnoDB
    ");
    echo "<p>✅ Tabela <b>produto_composicao</b> criada/verificada com sucesso!</p>";
} catch (PDOException $e) {
    echo "<p>❌ Erro ao criar tabela <b>produto_composicao</b>: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 5. Criar tabela 'produto_pecas' (Peças cadastradas diretamente no produto com quantidade, cor e estoque)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS produto_pecas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            produto_id INT NOT NULL,
            nome VARCHAR(150) NOT NULL,
            quantidade INT NOT NULL DEFAULT 1,
            cor VARCHAR(50) DEFAULT NULL,
            estoque INT NOT NULL DEFAULT 0,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
    echo "<p>✅ Tabela <b>produto_pecas</b> criada/verificada com sucesso!</p>";
} catch (PDOException $e) {
    echo "<p>❌ Erro ao criar tabela <b>produto_pecas</b>: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// 6. Adicionar coluna 'foto' na tabela 'produto_pecas'
try {
    $pdo->exec("ALTER TABLE produto_pecas ADD COLUMN foto VARCHAR(255) DEFAULT NULL AFTER cor");
    echo "<p>✅ Coluna <b>foto</b> adicionada na tabela <i>produto_pecas</i> com sucesso!</p>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "<p>⚠️ Coluna <b>foto</b> já existe na tabela <i>produto_pecas</i>.</p>";
    } else {
        echo "<p>❌ Erro ao adicionar coluna <b>foto</b> em produto_pecas: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// 7. Adicionar coluna 'foto' na tabela 'produtos'
try {
    $pdo->exec("ALTER TABLE produtos ADD COLUMN foto VARCHAR(255) DEFAULT NULL AFTER descricao");
    echo "<p>✅ Coluna <b>foto</b> adicionada na tabela <i>produtos</i> com sucesso!</p>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "<p>⚠️ Coluna <b>foto</b> já existe na tabela <i>produtos</i>.</p>";
    } else {
        echo "<p>❌ Erro ao adicionar coluna <b>foto</b> em produtos: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// 8. Criar tabela 'movimentos_estoque' (livro de entradas e saídas de peça e produto)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS movimentos_estoque (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tipo ENUM(
                'peca_produzida', 'peca_ajuste', 'peca_consumida',
                'produto_montado', 'produto_ajuste',
                'pedido_atendido', 'pedido_estorno'
            ) NOT NULL,
            produto_id INT DEFAULT NULL,
            peca_id INT DEFAULT NULL,
            pedido_item_id INT DEFAULT NULL,
            quantidade INT NOT NULL,
            saldo_depois INT DEFAULT NULL,
            usuario_id INT DEFAULT NULL,
            observacoes VARCHAR(255) DEFAULT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_mov_data (criado_em),
            INDEX idx_mov_peca (peca_id),
            INDEX idx_mov_produto (produto_id),
            INDEX idx_mov_tipo (tipo)
        ) ENGINE=InnoDB
    ");
    echo "<p>✅ Tabela <b>movimentos_estoque</b> criada/verificada com sucesso!</p>";
} catch (PDOException $e) {
    echo "<p>❌ Erro ao criar tabela <b>movimentos_estoque</b>: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>Migração finalizada.</h2>";
echo "<p><a href='fabrica.php'>Ir para o Painel da Fábrica</a> · <a href='produtos.php'>Ir para Produtos</a></p>";



