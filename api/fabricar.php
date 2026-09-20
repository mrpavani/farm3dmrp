<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
$usuario = exigirLoginApi();
$pdo = getDB();

// ------------------------------------------------------------
// GET /api/fabricar.php[?entrega_ate=YYYY-MM-DD][&produto_id=N]
//
// Visão "o que fabricar": itens ainda não produzidos de pedidos em aberto
// ou em produção (de clientes e ordens de estoque), agrupados por produto. Para cada produto:
//   total a fabricar, nº de pedidos, próxima entrega,
//   quantidade por data de entrega e a lista de pedidos (itens).
// Ordenado pelo produto com entrega mais próxima.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Método não suportado.', 405);
}

$where = ["p.status IN ('aberto', 'em_producao')", 'pi.quantidade > pi.quantidade_produzida'];
$params = [];
if (!empty($_GET['entrega_ate'])) {
    $where[] = 'p.data_entrega_prometida <= :ate';
    $params['ate'] = $_GET['entrega_ate'];
}
if (!empty($_GET['produto_id'])) {
    $where[] = 'pi.produto_id = :produto_id';
    $params['produto_id'] = (int) $_GET['produto_id'];
}

$sqlMontavel = sqlProdutoMontavel('pi.produto_id');
$stmt = $pdo->prepare("
    SELECT pi.id AS item_id, pi.pedido_id, pi.produto_id, prod.nome AS produto_nome,
           pi.quantidade, pi.quantidade_produzida,
           pi.quantidade - pi.quantidade_produzida AS restante,
           prod.estoque AS produto_estoque,
           {$sqlMontavel} AS produto_montavel,
           p.tipo, p.data_pedido, p.data_entrega_prometida, p.status, p.observacoes,
           c.nome AS cliente_nome, u.nome AS usuario_nome
    FROM pedido_itens pi
    JOIN pedidos p ON p.id = pi.pedido_id
    JOIN produtos prod ON prod.id = pi.produto_id
    LEFT JOIN clientes c ON c.id = p.cliente_id
    LEFT JOIN usuarios u ON u.id = p.usuario_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY p.data_entrega_prometida, p.id, pi.id
");
$stmt->execute($params);

$produtos = [];
foreach ($stmt->fetchAll() as $row) {
    $pid = (int) $row['produto_id'];
    if (!isset($produtos[$pid])) {
        $produtos[$pid] = [
            'produto_id' => $pid,
            'produto_nome' => $row['produto_nome'],
            'total_restante' => 0,
            'restante_estoque' => 0,
            'pedidos' => [],
            'proxima_entrega' => $row['data_entrega_prometida'], // linhas já vêm em ordem de entrega
            'por_data' => [],
            'itens' => [],
        ];
    }
    $g = &$produtos[$pid];
    $restante = (int) $row['restante'];
    $data = $row['data_entrega_prometida'];

    $g['total_restante'] += $restante;
    if ($row['tipo'] === 'estoque') $g['restante_estoque'] += $restante;
    $g['pedidos'][$row['pedido_id']] = true;
    $g['por_data'][$data] = ($g['por_data'][$data] ?? 0) + $restante;
    $g['itens'][] = $row;
    unset($g);
}

$lista = [];
$totalUnidades = 0;
$totalEstoque = 0;
$todosPedidos = [];
foreach ($produtos as $g) {
    $totalUnidades += $g['total_restante'];
    $totalEstoque += $g['restante_estoque'];
    $todosPedidos += $g['pedidos'];
    $g['qtd_pedidos'] = count($g['pedidos']);
    unset($g['pedidos']);
    $porData = [];
    foreach ($g['por_data'] as $data => $qtd) {
        $porData[] = ['data' => $data, 'quantidade' => $qtd];
    }
    $g['por_data'] = $porData;
    $lista[] = $g;
}

usort($lista, fn($a, $b) => [$a['proxima_entrega'], $a['produto_nome']] <=> [$b['proxima_entrega'], $b['produto_nome']]);

jsonResponse([
    'resumo' => [
        'unidades' => $totalUnidades,
        'unidades_estoque' => $totalEstoque,
        'produtos' => count($lista),
        'pedidos' => count($todosPedidos),
    ],
    'produtos' => $lista,
]);
