<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=pedidos3d;charset=utf8mb4', 'root', 'm@P599152');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    try {
        $pdo->exec("ALTER TABLE produtos ADD COLUMN estoque INT NOT NULL DEFAULT 0 AFTER preco;");
        echo "Coluna estoque adicionada.\n";
    } catch (Exception $e) {
        echo "Erro estoque: " . $e->getMessage() . "\n";
    }

    try {
        $pdo->exec("ALTER TABLE pedido_itens ADD COLUMN quantidade_estoque INT NOT NULL DEFAULT 0 AFTER quantidade;");
        echo "Coluna quantidade_estoque adicionada.\n";
    } catch (Exception $e) {
        echo "Erro quantidade_estoque: " . $e->getMessage() . "\n";
    }
} catch (Exception $e) {
    echo "Falha ao conectar localmente: " . $e->getMessage() . "\n";
}
