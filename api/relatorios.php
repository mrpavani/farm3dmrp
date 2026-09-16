<?php
require_once __DIR__ . '/../includes/auth.php';
$usuario = exigirLoginApi();
$pdo = getDB();

// ------------------------------------------------------------
// GET /api/relatorios.php?data_de=YYYY-MM-DD&data_ate=YYYY-MM-DD[&produto_id=N][&usuario_id=N]
//
// Relatório de produção no período (pela data_producao). Retorna:
//   resumo        -> totais do período
//   por_produto   -> peças, lotes e valor por produto
//   por_dia       -> peças e lotes por dia
//   por_usuario   -> peças e lotes por usuário que registrou a produção
//   detalhes      -> cada lançamento de produção
// Valores usam o preço de tabela atual do produto (o sistema não guarda
// o preço histórico no pedido).
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Método não suportado.', 405);
}

$de = $_GET['data_de'] ?? '';
$ate = $_GET['data_ate'] ?? '';
if ($de === '' || $ate === '') {
    jsonError('Informe data_de e data_ate.');
}
if ($ate < $de) {
    jsonError('A data final não pode ser anterior à data inicial.');
}

$where = ['pr.data_producao BETWEEN :de AND :ate'];
$params = ['de' => $de, 'ate' => $ate];
if (!empty($_GET['produto_id'])) {
    $where[] = 'pi.produto_id = :produto_id';
    $params['produto_id'] = (int) $_GET['produto_id'];
}
if (!empty($_GET['usuario_id'])) {
    $where[] = 'pr.usuario_id = :usuario_id';
    $params['usuario_id'] = (int) $_GET['usuario_id'];
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$base = "
    FROM producoes pr
    JOIN pedido_itens pi ON pi.id = pr.pedido_item_id
    JOIN produtos prod ON prod.id = pi.produto_id
    JOIN pedidos p ON p.id = pi.pedido_id
    LEFT JOIN clientes c ON c.id = p.cliente_id
    LEFT JOIN usuarios u ON u.id = pr.usuario_id
    $whereSql
";

function consultar(PDO $pdo, string $sql, array $params): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$resumo = consultar($pdo, "
    SELECT COALESCE(SUM(pr.quantidade), 0) AS pecas,
           COUNT(pr.id) AS lotes,
           COUNT(DISTINCT pi.pedido_id) AS pedidos,
           COUNT(DISTINCT pi.produto_id) AS produtos,
           COALESCE(SUM(pr.quantidade * prod.preco), 0) AS valor
    $base
", $params)[0];

$porProduto = consultar($pdo, "
    SELECT prod.id, prod.nome, prod.preco,
           SUM(pr.quantidade) AS pecas,
           COUNT(pr.id) AS lotes,
           COUNT(DISTINCT pi.pedido_id) AS pedidos,
           SUM(pr.quantidade * prod.preco) AS valor
    $base
    GROUP BY prod.id, prod.nome, prod.preco
    ORDER BY pecas DESC, prod.nome
", $params);

$porDia = consultar($pdo, "
    SELECT pr.data_producao AS data,
           SUM(pr.quantidade) AS pecas,
           COUNT(pr.id) AS lotes
    $base
    GROUP BY pr.data_producao
    ORDER BY pr.data_producao
", $params);

$porUsuario = consultar($pdo, "
    SELECT COALESCE(u.nome, '(não informado)') AS usuario,
           SUM(pr.quantidade) AS pecas,
           COUNT(pr.id) AS lotes
    $base
    GROUP BY pr.usuario_id, u.nome
    ORDER BY pecas DESC
", $params);

$detalhes = consultar($pdo, "
    SELECT pr.id, pr.data_producao, pr.quantidade, u.nome AS usuario_nome, pr.observacoes,
           pi.pedido_id, p.tipo AS pedido_tipo, prod.nome AS produto_nome, c.nome AS cliente_nome, p.status AS pedido_status
    $base
    ORDER BY pr.data_producao, pr.id
", $params);

jsonResponse([
    'periodo' => ['de' => $de, 'ate' => $ate],
    'resumo' => $resumo,
    'por_produto' => $porProduto,
    'por_dia' => $porDia,
    'por_usuario' => $porUsuario,
    'detalhes' => $detalhes,
]);
