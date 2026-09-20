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

// 9. Congelar o preço praticado no item do pedido (antes o valor vinha sempre
//    do preço de tabela atual, mudando o histórico a cada reajuste).
try {
    $pdo->exec("ALTER TABLE pedido_itens ADD COLUMN preco_unitario DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER produto_id");
    echo "<p>✅ Coluna <b>preco_unitario</b> adicionada na tabela <i>pedido_itens</i>!</p>";

    // Só na criação da coluna: carrega os itens antigos com o preço de tabela atual.
    $n = $pdo->exec("
        UPDATE pedido_itens pi JOIN produtos p ON p.id = pi.produto_id
        SET pi.preco_unitario = p.preco
    ");
    echo "<p>↳ {$n} item(ns) de pedido preenchido(s) com o preço de tabela atual.</p>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "<p>⚠️ Coluna <b>preco_unitario</b> já existe em <i>pedido_itens</i>.</p>";
    } else {
        echo "<p>❌ Erro ao adicionar <b>preco_unitario</b>: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// 10. Peça pode ter mais de uma cor (ex.: Chave de Fenda em Cinza e em
//     Laranja) ou nenhuma cor fixa (ex.: Suporte, "qualquer cor"). cor e
//     estoque saem de produto_pecas e passam para produto_pecas_cores —
//     uma ou mais variantes por peça, cada uma com seu próprio saldo.
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS produto_pecas_cores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            peca_id INT NOT NULL,
            cor VARCHAR(50) DEFAULT NULL,
            estoque INT NOT NULL DEFAULT 0,
            foto VARCHAR(255) DEFAULT NULL,
            criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (peca_id) REFERENCES produto_pecas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB
    ");
    echo "<p>✅ Tabela <b>produto_pecas_cores</b> criada/verificada com sucesso!</p>";
} catch (PDOException $e) {
    echo "<p>❌ Erro ao criar tabela <b>produto_pecas_cores</b>: " . htmlspecialchars($e->getMessage()) . "</p>";
}

try {
    $pdo->exec("CREATE INDEX idx_pecas_cores_peca ON produto_pecas_cores(peca_id)");
    echo "<p>✅ Índice em <b>produto_pecas_cores.peca_id</b> criado.</p>";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
        echo "<p>⚠️ Índice já existe em <i>produto_pecas_cores</i>.</p>";
    } else {
        echo "<p>❌ Erro ao criar índice em produto_pecas_cores: " . htmlspecialchars($e->getMessage()) . "</p>";
    }
}

// Migra os dados existentes só se produto_pecas ainda tem as colunas cor e
// estoque (ou seja, esta etapa ainda não rodou nesse banco).
try {
    $temColunaCor = $pdo->query("SHOW COLUMNS FROM produto_pecas LIKE 'cor'")->fetch();
    if ($temColunaCor) {
        $n = $pdo->exec("
            INSERT INTO produto_pecas_cores (peca_id, cor, estoque, foto, criado_em)
            SELECT pp.id, pp.cor, pp.estoque, pp.foto, pp.criado_em
            FROM produto_pecas pp
            WHERE NOT EXISTS (SELECT 1 FROM produto_pecas_cores WHERE peca_id = pp.id)
        ");
        echo "<p>↳ {$n} peça(s) migrada(s) para <b>produto_pecas_cores</b>, com a cor e o saldo que já tinham.</p>";

        $pdo->exec("ALTER TABLE produto_pecas DROP COLUMN cor");
        $pdo->exec("ALTER TABLE produto_pecas DROP COLUMN estoque");
        echo "<p>✅ Colunas <b>cor</b> e <b>estoque</b> removidas de <i>produto_pecas</i> (agora vivem em produto_pecas_cores, uma linha por variante).</p>";
    } else {
        echo "<p>⚠️ <i>produto_pecas</i> já não tem as colunas cor/estoque — esta etapa já tinha sido aplicada.</p>";
    }
} catch (PDOException $e) {
    echo "<p>❌ Erro ao migrar peças para produto_pecas_cores: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<h2>Migração finalizada.</h2>";
echo "<p><a href='fabrica.php'>Ir para o Painel da Fábrica</a> · <a href='produtos.php'>Ir para Produtos</a></p>";



