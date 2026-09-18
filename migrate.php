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

echo "<h2>Migração finalizada.</h2>";
echo "<p><a href='index.php'>Voltar para o sistema</a></p>";
