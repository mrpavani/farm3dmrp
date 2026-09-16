<?php
require_once __DIR__ . '/../includes/auth.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ------------------------------------------------------------
// GET /api/producoes.php?data_de=...&data_ate=...
// ------------------------------------------------------------
if ($method === 'GET') {
    $where = [];
    $params = [];
    if (!empty($_GET['data_de'])) {
        $where[] = 'pr.data_producao >= :data_de';
        $params['data_de'] = $_GET['data_de'];
    }
    if (!empty($_GET['data_ate'])) {
        $where[] = 'pr.data_producao <= :data_ate';
        $params['data_ate'] = $_GET['data_ate'];
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("
        SELECT pr.*, pi.pedido_id, pi.produto_id, prod.nome AS produto_nome, u.nome AS usuario_nome
        FROM producoes pr
        JOIN pedido_itens pi ON pi.id = pr.pedido_item_id
        JOIN produtos prod ON prod.id = pi.produto_id
        LEFT JOIN usuarios u ON u.id = pr.usuario_id
        $whereSql
        ORDER BY pr.data_producao DESC, pr.id DESC
    ");
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
}

// ------------------------------------------------------------
// POST /api/producoes.php
// Body: { pedido_item_id: 7, data_producao: "2026-09-18", quantidade: 5, observacoes: "..." }
// A produção é registrada em nome do usuário logado (quem clicou).
//
// Registra a produção, soma em pedido_itens.quantidade_produzida e,
// se todos os itens do pedido estiverem completos, marca o pedido como 'pronto'.
// ------------------------------------------------------------
if ($method === 'POST') {
    $b = readJsonBody();

    if (empty($b['pedido_item_id']) || empty($b['data_producao']) || empty($b['quantidade']) || $b['quantidade'] <= 0) {
        jsonError('pedido_item_id, data_producao e quantidade (> 0) são obrigatórios.');
    }

    $pdo->beginTransaction();
    try {
        $itemStmt = $pdo->prepare("
            SELECT pi.*, p.status AS pedido_status, p.tipo AS pedido_tipo
            FROM pedido_itens pi
            JOIN pedidos p ON p.id = pi.pedido_id
            WHERE pi.id = :id
            FOR UPDATE
        ");
        $itemStmt->execute(['id' => $b['pedido_item_id']]);
        $item = $itemStmt->fetch();
        if (!$item) throw new Exception('Item de pedido não encontrado.');
        if (in_array($item['pedido_status'], ['entregue', 'cancelado'], true)) {
            throw new Exception("O pedido está {$item['pedido_status']}; reabra-o antes de registrar produção.");
        }

        $restante = $item['quantidade'] - $item['quantidade_produzida'];
        if ($b['quantidade'] > $restante) {
            throw new Exception("Quantidade ($b[quantidade]) maior que o restante a produzir ($restante).");
        }

        $insStmt = $pdo->prepare("
            INSERT INTO producoes (pedido_item_id, data_producao, quantidade, usuario_id, observacoes)
            VALUES (:pedido_item_id, :data_producao, :quantidade, :usuario_id, :observacoes)
        ");
        $insStmt->execute([
            'pedido_item_id' => $b['pedido_item_id'],
            'data_producao' => $b['data_producao'],
            'quantidade' => $b['quantidade'],
            'usuario_id' => $usuario['id'],
            'observacoes' => $b['observacoes'] ?? null,
        ]);

        $updStmt = $pdo->prepare("UPDATE pedido_itens SET quantidade_produzida = quantidade_produzida + :q WHERE id = :id");
        $updStmt->execute(['q' => $b['quantidade'], 'id' => $b['pedido_item_id']]);

        if ($item['pedido_tipo'] === 'estoque') {
            $pdo->prepare("UPDATE produtos SET estoque = estoque + :qtd WHERE id = :id")
                ->execute(['qtd' => $b['quantidade'], 'id' => $item['produto_id']]);
        }

        // Atualiza o status do pedido (em_producao / pronto) conforme os itens
        $novoStatus = recalcularStatusPedido($pdo, (int) $item['pedido_id']);

        $pdo->commit();
        jsonResponse(['ok' => true, 'status_pedido' => $novoStatus], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao registrar produção: ' . $e->getMessage(), 400);
    }
}

jsonError('Método não suportado.', 405);
