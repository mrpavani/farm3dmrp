<?php
require_once __DIR__ . '/../includes/auth.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

function clienteExiste(PDO $pdo, int $id): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE id = :id");
    $stmt->execute(['id' => $id]);
    return (int) $stmt->fetchColumn() > 0;
}

// ------------------------------------------------------------
// GET /api/clientes.php                 -> todos (com resumo de pedidos)
// GET /api/clientes.php?busca=texto     -> filtra por nome, telefone, e-mail ou cidade
// GET /api/clientes.php?id=N            -> um cliente
// ------------------------------------------------------------
if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM clientes WHERE id = :id");
        $stmt->execute(['id' => $_GET['id']]);
        $cliente = $stmt->fetch();
        if (!$cliente) jsonError('Cliente não encontrado.', 404);
        jsonResponse($cliente);
    }

    $where = '';
    $params = [];
    $termo = trim($_GET['busca'] ?? '');
    if ($termo !== '') {
        $where = "WHERE c.nome LIKE :t1 OR c.telefone LIKE :t2 OR c.email LIKE :t3 OR c.cidade LIKE :t4";
        $params = ['t1' => "%$termo%", 't2' => "%$termo%", 't3' => "%$termo%", 't4' => "%$termo%"];
    }

    $stmt = $pdo->prepare("
        SELECT c.*,
               COUNT(p.id) AS qtd_pedidos,
               MAX(p.data_pedido) AS ultimo_pedido
        FROM clientes c
        LEFT JOIN pedidos p ON p.cliente_id = c.id
        $where
        GROUP BY c.id
        ORDER BY c.nome
    ");
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
}

// ------------------------------------------------------------
// POST /api/clientes.php
// Body: { nome, telefone, email, cidade, estado, descricao }
// ------------------------------------------------------------
if ($method === 'POST') {
    try {
        $id = inserirCliente($pdo, dadosCliente(readJsonBody()));
    } catch (Exception $e) {
        jsonError($e->getMessage());
    }
    jsonResponse(['id' => $id], 201);
}

// ------------------------------------------------------------
// PUT /api/clientes.php?id=N   (mesmo body do POST)
// ------------------------------------------------------------
if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('Informe o id do cliente.');
    try {
        $d = dadosCliente(readJsonBody());
    } catch (Exception $e) {
        jsonError($e->getMessage());
    }
    if (!clienteExiste($pdo, $id)) jsonError('Cliente não encontrado.', 404);
    if (emailDuplicado($pdo, $d['email'], $id)) jsonError('Já existe outro cliente com esse e-mail.');

    $stmt = $pdo->prepare("
        UPDATE clientes SET nome = :nome, telefone = :telefone, email = :email,
               cidade = :cidade, estado = :estado, descricao = :descricao
        WHERE id = :id
    ");
    $stmt->execute($d + ['id' => $id]);
    jsonResponse(['id' => $id]);
}

// ------------------------------------------------------------
// DELETE /api/clientes.php?id=N
// Só exclui clientes sem pedidos (para não perder o histórico).
// ------------------------------------------------------------
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('Informe o id do cliente.');
    if (!clienteExiste($pdo, $id)) jsonError('Cliente não encontrado.', 404);

    $uso = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE cliente_id = :id");
    $uso->execute(['id' => $id]);
    $qtd = (int) $uso->fetchColumn();
    if ($qtd > 0) {
        jsonError("Este cliente possui $qtd pedido(s) e não pode ser excluído.");
    }

    $pdo->prepare("DELETE FROM clientes WHERE id = :id")->execute(['id' => $id]);
    jsonResponse(['ok' => true]);
}

jsonError('Método não suportado.', 405);
