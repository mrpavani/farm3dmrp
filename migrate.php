<?php
// ============================================================
// Script de Migração e Atualização do Banco de Dados (Web)
// Sistema: Fábrica 3D (MRP / Gestão de Impressão 3D)
// Acesse este arquivo pelo navegador: https://seusite.com.br/migrate.php
// ============================================================

require_once __DIR__ . '/includes/db.php';
$pdo = getDB();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Migração do Banco de Dados — Fábrica 3D</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #0f172a; color: #f8fafc; padding: 30px; line-height: 1.6; }
        .container { max-width: 800px; margin: 0 auto; background: #1e293b; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.5); }
        h1 { color: #38bdf8; border-bottom: 2px solid #334155; padding-bottom: 12px; margin-top: 0; font-size: 24px; }
        h2 { color: #e2e8f0; font-size: 18px; margin-top: 25px; }
        .msg { padding: 10px 14px; margin: 8px 0; border-radius: 6px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .ok { background: #064e3b; color: #a7f3d0; border-left: 4px solid #10b981; }
        .warn { background: #78350f; color: #fde68a; border-left: 4px solid #f59e0b; }
        .err { background: #7f1d1d; color: #fecaca; border-left: 4px solid #ef4444; }
        .actions { margin-top: 30px; padding-top: 20px; border-top: 1px solid #334155; display: flex; gap: 15px; }
        .btn { display: inline-block; background: #3b82f6; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-weight: 600; font-size: 14px; }
        .btn:hover { background: #2563eb; }
    </style>
</head>
<body>
<div class="container">
    <h1>🚀 Atualização e Migração do Banco de Dados</h1>
<?php

function execSafe($pdo, $sql, $sucessoMsg, $warningMsg = '') {
    try {
        $pdo->exec($sql);
        echo "<div class='msg ok'>✅ {$sucessoMsg}</div>";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column name') !== false ||
            strpos($msg, 'Duplicate key name') !== false ||
            strpos($msg, 'already exists') !== false) {
            $w = $warningMsg ? $warningMsg : "Já atualizado previamente.";
            echo "<div class='msg warn'>⚠️ {$w}</div>";
        } else {
            echo "<div class='msg err'>❌ Erro: " . htmlspecialchars($msg) . "</div>";
        }
    }
}

// 1. Tabela usuarios
execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS usuarios (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(100) NOT NULL,
        login VARCHAR(150) NOT NULL UNIQUE,
        senha_hash VARCHAR(255) NOT NULL,
        admin TINYINT(1) NOT NULL DEFAULT 0,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        ultimo_acesso DATETIME DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>usuarios</b> verificada/criada.");

execSafe($pdo, "ALTER TABLE usuarios ADD COLUMN admin TINYINT(1) NOT NULL DEFAULT 0 AFTER senha_hash", "Coluna <b>admin</b> em <i>usuarios</i> adicionada.", "Coluna <b>admin</b> já existe em <i>usuarios</i>.");
execSafe($pdo, "ALTER TABLE usuarios ADD COLUMN ativo TINYINT(1) NOT NULL DEFAULT 1 AFTER admin", "Coluna <b>ativo</b> em <i>usuarios</i> adicionada.", "Coluna <b>ativo</b> já existe em <i>usuarios</i>.");

// 2. Tabela clientes
execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS clientes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(150) NOT NULL,
        cidade VARCHAR(100) DEFAULT NULL,
        estado VARCHAR(2) DEFAULT NULL,
        telefone VARCHAR(30) DEFAULT NULL,
        email VARCHAR(150) DEFAULT NULL,
        descricao TEXT DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>clientes</b> verificada/criada.");

execSafe($pdo, "ALTER TABLE clientes ADD COLUMN email VARCHAR(150) DEFAULT NULL AFTER telefone", "Coluna <b>email</b> em <i>clientes</i> adicionada.", "Coluna <b>email</b> já existe em <i>clientes</i>.");
execSafe($pdo, "ALTER TABLE clientes ADD COLUMN descricao TEXT DEFAULT NULL AFTER email", "Coluna <b>descricao</b> em <i>clientes</i> adicionada.", "Coluna <b>descricao</b> já existe em <i>clientes</i>.");

// 3. Tabela produtos e suas colunas
execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS produtos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(150) NOT NULL,
        tipo ENUM('simples', 'composto', 'componente') NOT NULL DEFAULT 'simples',
        descricao TEXT DEFAULT NULL,
        foto VARCHAR(255) DEFAULT NULL,
        preco DECIMAL(10,2) DEFAULT 0.00,
        estoque INT NOT NULL DEFAULT 0,
        peso_gramas DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tempo_producao_segundos INT NOT NULL DEFAULT 0,
        tem_embalagem TINYINT(1) NOT NULL DEFAULT 0,
        valor_embalagem DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        valor_outros DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        margem_lucro DECIMAL(10,2) NOT NULL DEFAULT 100.00,
        custo_filamento DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        custo_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        ativo TINYINT(1) DEFAULT 1,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>produtos</b> verificada/criada.");

execSafe($pdo, "ALTER TABLE produtos ADD COLUMN tipo ENUM('simples', 'composto', 'componente') NOT NULL DEFAULT 'simples' AFTER nome", "Coluna <b>tipo</b> em <i>produtos</i> adicionada.", "Coluna <b>tipo</b> já existe em <i>produtos</i>.");
execSafe($pdo, "ALTER TABLE produtos ADD COLUMN foto VARCHAR(255) DEFAULT NULL AFTER descricao", "Coluna <b>foto</b> em <i>produtos</i> adicionada.", "Coluna <b>foto</b> já existe em <i>produtos</i>.");
execSafe($pdo, "ALTER TABLE produtos ADD COLUMN estoque INT NOT NULL DEFAULT 0 AFTER preco", "Coluna <b>estoque</b> em <i>produtos</i> adicionada.", "Coluna <b>estoque</b> já existe em <i>produtos</i>.");
execSafe($pdo, "ALTER TABLE produtos ADD COLUMN peso_gramas DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER estoque", "Coluna <b>peso_gramas</b> em <i>produtos</i> adicionada.", "Coluna <b>peso_gramas</b> já existe em <i>produtos</i>.");
execSafe($pdo, "ALTER TABLE produtos ADD COLUMN tempo_producao_segundos INT NOT NULL DEFAULT 0 AFTER peso_gramas", "Coluna <b>tempo_producao_segundos</b> em <i>produtos</i> adicionada.", "Coluna <b>tempo_producao_segundos</b> já existe em <i>produtos</i>.");
execSafe($pdo, "ALTER TABLE produtos ADD COLUMN tem_embalagem TINYINT(1) NOT NULL DEFAULT 0 AFTER tempo_producao_segundos", "Coluna <b>tem_embalagem</b> em <i>produtos</i> adicionada.", "Coluna <b>tem_embalagem</b> já existe em <i>produtos</i>.");
execSafe($pdo, "ALTER TABLE produtos ADD COLUMN valor_embalagem DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tem_embalagem", "Coluna <b>valor_embalagem</b> em <i>produtos</i> adicionada.", "Coluna <b>valor_embalagem</b> já existe em <i>produtos</i>.");
execSafe($pdo, "ALTER TABLE produtos ADD COLUMN valor_outros DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER valor_embalagem", "Coluna <b>valor_outros</b> em <i>produtos</i> adicionada.", "Coluna <b>valor_outros</b> já existe em <i>produtos</i>.");
execSafe($pdo, "ALTER TABLE produtos ADD COLUMN margem_lucro DECIMAL(10,2) NOT NULL DEFAULT 100.00 AFTER valor_outros", "Coluna <b>margem_lucro</b> em <i>produtos</i> adicionada.", "Coluna <b>margem_lucro</b> já existe em <i>produtos</i>.");
execSafe($pdo, "ALTER TABLE produtos ADD COLUMN custo_filamento DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER margem_lucro", "Coluna <b>custo_filamento</b> em <i>produtos</i> adicionada.", "Coluna <b>custo_filamento</b> já existe em <i>produtos</i>.");
execSafe($pdo, "ALTER TABLE produtos ADD COLUMN custo_total DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER custo_filamento", "Coluna <b>custo_total</b> em <i>produtos</i> adicionada.", "Coluna <b>custo_total</b> já existe em <i>produtos</i>.");

// 4. Tabela produto_pecas
execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS produto_pecas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        produto_id INT NOT NULL,
        nome VARCHAR(150) NOT NULL,
        quantidade INT NOT NULL DEFAULT 1,
        peso_gramas DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tempo_producao_segundos INT NOT NULL DEFAULT 0,
        foto VARCHAR(255) DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>produto_pecas</b> verificada/criada.");

execSafe($pdo, "ALTER TABLE produto_pecas ADD COLUMN estoque INT NOT NULL DEFAULT 0 AFTER quantidade", "Coluna <b>estoque</b> em <i>produto_pecas</i> adicionada.", "Coluna <b>estoque</b> já existe em <i>produto_pecas</i>.");
execSafe($pdo, "ALTER TABLE produto_pecas ADD COLUMN foto VARCHAR(255) DEFAULT NULL AFTER estoque", "Coluna <b>foto</b> em <i>produto_pecas</i> adicionada.", "Coluna <b>foto</b> já existe em <i>produto_pecas</i>.");
execSafe($pdo, "ALTER TABLE produto_pecas ADD COLUMN peso_gramas DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER foto", "Coluna <b>peso_gramas</b> em <i>produto_pecas</i> adicionada.", "Coluna <b>peso_gramas</b> já existe em <i>produto_pecas</i>.");
execSafe($pdo, "ALTER TABLE produto_pecas ADD COLUMN tempo_producao_segundos INT NOT NULL DEFAULT 0 AFTER peso_gramas", "Coluna <b>tempo_producao_segundos</b> em <i>produto_pecas</i> adicionada.", "Coluna <b>tempo_producao_segundos</b> já existe em <i>produto_pecas</i>.");

// 5. Tabela produto_pecas_cores
execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS produto_pecas_cores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        peca_id INT NOT NULL,
        cor VARCHAR(50) DEFAULT NULL,
        estoque INT NOT NULL DEFAULT 0,
        peso_gramas DECIMAL(10,2) DEFAULT NULL,
        tempo_producao_segundos INT DEFAULT NULL,
        foto VARCHAR(255) DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (peca_id) REFERENCES produto_pecas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>produto_pecas_cores</b> verificada/criada.");

execSafe($pdo, "ALTER TABLE produto_pecas_cores ADD COLUMN peso_gramas DECIMAL(10,2) DEFAULT NULL AFTER estoque", "Coluna <b>peso_gramas</b> em <i>produto_pecas_cores</i> adicionada.", "Coluna <b>peso_gramas</b> já existe em <i>produto_pecas_cores</i>.");
execSafe($pdo, "ALTER TABLE produto_pecas_cores ADD COLUMN tempo_producao_segundos INT DEFAULT NULL AFTER peso_gramas", "Coluna <b>tempo_producao_segundos</b> em <i>produto_pecas_cores</i> adicionada.", "Coluna <b>tempo_producao_segundos</b> já existe em <i>produto_pecas_cores</i>.");
execSafe($pdo, "ALTER TABLE produto_pecas_cores ADD COLUMN foto VARCHAR(255) DEFAULT NULL AFTER tempo_producao_segundos", "Coluna <b>foto</b> em <i>produto_pecas_cores</i> adicionada.", "Coluna <b>foto</b> já existe em <i>produto_pecas_cores</i>.");

// Migração 014: Unificação do saldo físico na peça (produto_pecas.estoque)
try {
    $n = $pdo->exec("
        UPDATE produto_pecas pp
        SET pp.estoque = (
            SELECT COALESCE(MAX(pc.estoque), 0)
            FROM produto_pecas_cores pc
            WHERE pc.peca_id = pp.id
        )
        WHERE pp.estoque = 0
          AND EXISTS (SELECT 1 FROM produto_pecas_cores pc WHERE pc.peca_id = pp.id AND pc.estoque > 0)
    ");
    if ($n > 0) {
        echo "<div class='msg ok'>↳ {$n} peça(s) tiveram o estoque físico unificado na peça.</div>";
    }
} catch (PDOException $e) {}

// 6. Tabela produto_cores (Multicor para produtos simples)
execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS produto_cores (
        id INT AUTO_INCREMENT PRIMARY KEY,
        produto_id INT NOT NULL,
        cor VARCHAR(50) NOT NULL,
        peso_gramas DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        tempo_producao_segundos INT NOT NULL DEFAULT 0,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>produto_cores</b> verificada/criada.");

// 7. Tabela produto_composicao (BOM Ficha Técnica N:N)
execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS produto_composicao (
        id INT AUTO_INCREMENT PRIMARY KEY,
        produto_pai_id INT NOT NULL,
        componente_id INT NOT NULL,
        quantidade INT NOT NULL DEFAULT 1,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (produto_pai_id) REFERENCES produtos(id) ON DELETE CASCADE,
        FOREIGN KEY (componente_id) REFERENCES produtos(id) ON DELETE RESTRICT,
        UNIQUE KEY uq_pai_componente (produto_pai_id, componente_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>produto_composicao</b> verificada/criada.");

// 8. Tabela pedidos
execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS pedidos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('venda','estoque') NOT NULL DEFAULT 'venda',
        cliente_id INT DEFAULT NULL,
        usuario_id INT DEFAULT NULL,
        data_pedido DATE NOT NULL,
        data_entrega_prometida DATE NOT NULL,
        status ENUM('aberto','em_producao','pronto','entregue','cancelado') NOT NULL DEFAULT 'aberto',
        observacoes TEXT DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (cliente_id) REFERENCES clientes(id) ON DELETE SET NULL,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>pedidos</b> verificada/criada.");

execSafe($pdo, "ALTER TABLE pedidos ADD COLUMN tipo ENUM('venda','estoque') NOT NULL DEFAULT 'venda' AFTER id", "Coluna <b>tipo</b> em <i>pedidos</i> adicionada.", "Coluna <b>tipo</b> já existe em <i>pedidos</i>.");
execSafe($pdo, "ALTER TABLE pedidos ADD COLUMN usuario_id INT NULL AFTER cliente_id", "Coluna <b>usuario_id</b> em <i>pedidos</i> adicionada.", "Coluna <b>usuario_id</b> já existe em <i>pedidos</i>.");

// 9. Tabela pedido_itens
execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS pedido_itens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pedido_id INT NOT NULL,
        produto_id INT NOT NULL,
        preco_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        quantidade INT NOT NULL,
        quantidade_estoque INT NOT NULL DEFAULT 0,
        quantidade_produzida INT NOT NULL DEFAULT 0,
        FOREIGN KEY (pedido_id) REFERENCES pedidos(id) ON DELETE CASCADE,
        FOREIGN KEY (produto_id) REFERENCES produtos(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>pedido_itens</b> verificada/criada.");

execSafe($pdo, "ALTER TABLE pedido_itens ADD COLUMN preco_unitario DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER produto_id", "Coluna <b>preco_unitario</b> em <i>pedido_itens</i> adicionada.", "Coluna <b>preco_unitario</b> já existe em <i>pedido_itens</i>.");
execSafe($pdo, "ALTER TABLE pedido_itens ADD COLUMN quantidade_estoque INT NOT NULL DEFAULT 0 AFTER quantidade", "Coluna <b>quantidade_estoque</b> em <i>pedido_itens</i> adicionada.", "Coluna <b>quantidade_estoque</b> já existe em <i>pedido_itens</i>.");
execSafe($pdo, "ALTER TABLE pedido_itens ADD COLUMN quantidade_produzida INT NOT NULL DEFAULT 0 AFTER quantidade_estoque", "Coluna <b>quantidade_produzida</b> em <i>pedido_itens</i> adicionada.", "Coluna <b>quantidade_produzida</b> já existe em <i>pedido_itens</i>.");

// Backfill preco_unitario em itens antigos
try {
    $n = $pdo->exec("UPDATE pedido_itens pi JOIN produtos p ON p.id = pi.produto_id SET pi.preco_unitario = p.preco WHERE pi.preco_unitario = 0.00");
    if ($n > 0) {
        echo "<div class='msg ok'>↳ {$n} item(ns) de pedidos antigos tiveram o preço unitário congelado com base no catálogo.</div>";
    }
} catch (PDOException $e) {}

// 10. Tabela producoes
execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS producoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pedido_item_id INT NOT NULL,
        data_producao DATE NOT NULL,
        quantidade INT NOT NULL,
        usuario_id INT DEFAULT NULL,
        observacoes TEXT DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (pedido_item_id) REFERENCES pedido_itens(id) ON DELETE CASCADE,
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>producoes</b> verificada/criada.");

execSafe($pdo, "ALTER TABLE producoes ADD COLUMN usuario_id INT NULL AFTER quantidade", "Coluna <b>usuario_id</b> em <i>producoes</i> adicionada.", "Coluna <b>usuario_id</b> já existe em <i>producoes</i>.");

// 11. Tabela movimentos_estoque
execSafe($pdo, "
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
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>movimentos_estoque</b> verificada/criada.");

// 12. Tabelas de Filamentos (Estoque, Lotes PEPS e Movimentações)
execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS filamentos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(150) NOT NULL,
        marca VARCHAR(100) NOT NULL,
        tipo VARCHAR(50) NOT NULL DEFAULT 'PLA',
        cor VARCHAR(50) NOT NULL,
        cor_hex VARCHAR(7) DEFAULT '#6366f1',
        estoque_gramas DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        estoque_minimo_gramas DECIMAL(10,2) NOT NULL DEFAULT 500.00,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>filamentos</b> verificada/criada.");

execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS filamento_lotes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filamento_id INT NOT NULL,
        quantidade_rolos INT NOT NULL DEFAULT 1,
        peso_rolo_gramas DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
        peso_total_gramas DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
        preco_rolo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        preco_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        preco_por_grama DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        gramas_saldo DECIMAL(10,2) NOT NULL DEFAULT 1000.00,
        data_compra DATE NOT NULL,
        observacoes VARCHAR(255) DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (filamento_id) REFERENCES filamentos(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>filamento_lotes</b> verificada/criada.");

execSafe($pdo, "
    CREATE TABLE IF NOT EXISTS filamento_movimentacoes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filamento_id INT NOT NULL,
        lote_id INT DEFAULT NULL,
        tipo ENUM('compra', 'consumo_producao', 'ajuste', 'estorno') NOT NULL,
        gramas DECIMAL(10,2) NOT NULL,
        saldo_anterior_gramas DECIMAL(10,2) NOT NULL,
        saldo_posterior_gramas DECIMAL(10,2) NOT NULL,
        preco_por_grama DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
        custo_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        peca_id INT DEFAULT NULL,
        produto_id INT DEFAULT NULL,
        pedido_item_id INT DEFAULT NULL,
        usuario_id INT DEFAULT NULL,
        observacoes VARCHAR(255) DEFAULT NULL,
        criado_em DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (filamento_id) REFERENCES filamentos(id) ON DELETE CASCADE,
        FOREIGN KEY (lote_id) REFERENCES filamento_lotes(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
", "Tabela <b>filamento_movimentacoes</b> verificada/criada.");

// 13. Administrador padrão
try {
    $pdo->exec("
        INSERT INTO usuarios (id, nome, login, senha_hash, admin, ativo)
        VALUES (1, 'Administrador', 'admin@mail.com', '$2y$12$c9jgI75ewT6lyFbhBP0z6O0MVaa2sO6A3wPjO8Ml3mMY1P.CwH8fG', 1, 1)
        ON DUPLICATE KEY UPDATE admin = 1, ativo = 1
    ");
    echo "<div class='msg ok'>✅ Usuário Administrador (admin@mail.com) assegurado com permissões totais.</div>";
} catch (PDOException $e) {}

?>
    <h2>🎉 Migração concluída com sucesso!</h2>
    <p>O banco de dados está sincronizado com todas as tabelas, colunas, módulos de precificação e controle de filamento.</p>
    
    <div class="actions">
        <a href="fabrica.php" class="btn">Painel da Fábrica</a>
        <a href="produtos.php" class="btn">Catálogo de Produtos</a>
        <a href="filamentos.php" class="btn">Estoque de Filamentos</a>
        <a href="pedidos.php" class="btn">Gestão de Pedidos</a>
    </div>
</div>
</body>
</html>
