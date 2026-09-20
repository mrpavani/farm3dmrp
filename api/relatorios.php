<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
$usuario = exigirLoginApi();
$pdo = getDB();

// ------------------------------------------------------------
// GET /api/relatorios.php
//   data_de, data_ate            (obrigatórios)
//   base = pedido | entrega      -> qual data filtra o período
//   produto_id, cliente_id, status, tipo, usuario_id  (opcionais)
//
// O relatório parte do LIVRO DE PEDIDOS (pedido_itens), não do log de
// produção. Assim tudo o que foi pedido aparece, produzido ou não — e cada
// linha carrega quanto foi pedido, quanto saiu e quanto falta, em unidades
// e em valor.
//
// Seções: resumo, por_produto, por_pedido (com itens), por_cliente,
//         agenda de entregas, a_fabricar e o log de produção do conjunto.
//
// Valores usam pedido_itens.preco_unitario — o preço congelado no momento do
// pedido. Reajustar o produto depois não altera o histórico. Em "por produto",
// "preco" é a média praticada e "preco_tabela" é o preço atual do catálogo.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Método não suportado.', 405);
}

$de = $_GET['data_de'] ?? '';
$ate = $_GET['data_ate'] ?? '';
if ($de === '' || $ate === '') jsonError('Informe data_de e data_ate.');
if ($ate < $de) jsonError('A data final não pode ser anterior à data inicial.');

$base = ($_GET['base'] ?? 'pedido') === 'entrega' ? 'entrega' : 'pedido';
$colunaData = $base === 'entrega' ? 'p.data_entrega_prometida' : 'p.data_pedido';

$where = ["$colunaData BETWEEN :de AND :ate"];
$params = ['de' => $de, 'ate' => $ate];

foreach ([
    'produto_id' => 'pi.produto_id',
    'cliente_id' => 'p.cliente_id',
    'usuario_id' => 'p.usuario_id',
] as $get => $coluna) {
    if (!empty($_GET[$get])) {
        $where[] = "$coluna = :$get";
        $params[$get] = (int) $_GET[$get];
    }
}
if (!empty($_GET['status'])) {
    $where[] = 'p.status = :status';
    $params['status'] = $_GET['status'];
}
if (!empty($_GET['tipo'])) {
    $where[] = 'p.tipo = :tipo';
    $params['tipo'] = $_GET['tipo'] === 'estoque' ? 'estoque' : 'venda';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

// Cancelado não conta como venda nem como fabricação pendente.
$vivo = "p.status <> 'cancelado'";
$pendente = "p.status IN ('aberto','em_producao','pronto')";

$baseSql = "
    FROM pedido_itens pi
    JOIN pedidos p   ON p.id = pi.pedido_id
    JOIN produtos prod ON prod.id = pi.produto_id
    LEFT JOIN clientes c ON c.id = p.cliente_id
    $whereSql
";

function consultar(PDO $pdo, string $sql, array $params): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// Expressões reaproveitadas: falta = o que ainda não foi produzido.
$falta = "GREATEST(pi.quantidade - pi.quantidade_produzida, 0)";

// ---------- Resumo ----------
$resumo = consultar($pdo, "
    SELECT
        COUNT(DISTINCT p.id) AS pedidos,
        COUNT(DISTINCT CASE WHEN p.tipo = 'venda' THEN p.cliente_id END) AS clientes,
        COUNT(DISTINCT pi.produto_id) AS produtos,
        COALESCE(SUM(CASE WHEN $vivo THEN pi.quantidade END), 0) AS itens_pedidos,
        COALESCE(SUM(CASE WHEN $vivo THEN pi.quantidade_produzida END), 0) AS itens_produzidos,
        COALESCE(SUM(CASE WHEN $vivo THEN $falta END), 0) AS itens_falta,
        COALESCE(SUM(CASE WHEN $vivo THEN pi.quantidade * pi.preco_unitario END), 0) AS valor_pedido,
        COALESCE(SUM(CASE WHEN $vivo THEN pi.quantidade_produzida * pi.preco_unitario END), 0) AS valor_produzido,
        COALESCE(SUM(CASE WHEN $vivo THEN $falta * pi.preco_unitario END), 0) AS valor_falta,
        COALESCE(SUM(CASE WHEN p.status = 'entregue' THEN pi.quantidade * pi.preco_unitario END), 0) AS valor_entregue,
        COALESCE(SUM(CASE WHEN p.status = 'cancelado' THEN pi.quantidade * pi.preco_unitario END), 0) AS valor_cancelado,
        COALESCE(SUM(CASE WHEN $pendente AND p.data_entrega_prometida < CURDATE() THEN $falta END), 0) AS itens_atrasados,
        COUNT(DISTINCT CASE WHEN $pendente AND p.data_entrega_prometida < CURDATE() THEN p.id END) AS pedidos_atrasados,
        COALESCE(SUM(CASE WHEN $pendente AND p.data_entrega_prometida BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN $falta END), 0) AS itens_7dias
    $baseSql
", $params)[0];

// ---------- Por produto: pedido x produzido x falta ----------
$sqlMontavelProduto = sqlProdutoMontavel('prod.id');
$porProduto = consultar($pdo, "
    SELECT prod.id, prod.nome, prod.preco AS preco_tabela,
           ROUND(SUM(pi.quantidade * pi.preco_unitario) / NULLIF(SUM(pi.quantidade), 0), 2) AS preco,
           SUM(CASE WHEN $vivo THEN pi.quantidade ELSE 0 END) AS qtd_pedida,
           SUM(CASE WHEN $vivo THEN pi.quantidade_produzida ELSE 0 END) AS qtd_produzida,
           SUM(CASE WHEN $vivo THEN $falta ELSE 0 END) AS qtd_falta,
           SUM(CASE WHEN $vivo THEN pi.quantidade * pi.preco_unitario ELSE 0 END) AS valor_pedido,
           SUM(CASE WHEN $vivo THEN $falta * pi.preco_unitario ELSE 0 END) AS valor_falta,
           COUNT(DISTINCT p.id) AS pedidos,
           prod.estoque AS estoque_pronto,
           {$sqlMontavelProduto} AS montavel
    $baseSql
    GROUP BY prod.id, prod.nome, prod.preco, prod.estoque
    ORDER BY qtd_pedida DESC, prod.nome
", $params);

// ---------- Por pedido, com os itens aninhados ----------
$pedidos = consultar($pdo, "
    SELECT p.id, p.tipo, p.status, p.data_pedido, p.data_entrega_prometida, p.observacoes,
           c.nome AS cliente_nome, c.cidade AS cliente_cidade, c.estado AS cliente_estado,
           u.nome AS usuario_nome,
           SUM(pi.quantidade) AS itens_pedidos,
           SUM(pi.quantidade_produzida) AS itens_produzidos,
           SUM($falta) AS itens_falta,
           SUM(pi.quantidade * pi.preco_unitario) AS valor_pedido,
           SUM($falta * pi.preco_unitario) AS valor_falta,
           DATEDIFF(p.data_entrega_prometida, CURDATE()) AS dias_para_entrega
    FROM pedido_itens pi
    JOIN pedidos p   ON p.id = pi.pedido_id
    JOIN produtos prod ON prod.id = pi.produto_id
    LEFT JOIN clientes c ON c.id = p.cliente_id
    LEFT JOIN usuarios u ON u.id = p.usuario_id
    $whereSql
    GROUP BY p.id
    ORDER BY p.data_entrega_prometida, p.id
", $params);

if ($pedidos) {
    $ids = array_column($pedidos, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $itens = consultar($pdo, "
        SELECT pi.pedido_id, pi.id AS item_id, pi.produto_id, prod.nome AS produto_nome, pi.preco_unitario AS preco,
               pi.quantidade AS qtd_pedida,
               pi.quantidade_produzida AS qtd_produzida,
               pi.quantidade_estoque AS qtd_do_estoque,
               GREATEST(pi.quantidade - pi.quantidade_produzida, 0) AS qtd_falta,
               pi.quantidade * pi.preco_unitario AS valor_item,
               GREATEST(pi.quantidade - pi.quantidade_produzida, 0) * pi.preco_unitario AS valor_falta
        FROM pedido_itens pi
        JOIN produtos prod ON prod.id = pi.produto_id
        WHERE pi.pedido_id IN ($in)
        ORDER BY pi.id
    ", $ids);

    $porPedidoId = [];
    foreach ($itens as $it) $porPedidoId[$it['pedido_id']][] = $it;
    foreach ($pedidos as &$ped) $ped['itens'] = $porPedidoId[$ped['id']] ?? [];
    unset($ped);
}

// ---------- Por cliente ----------
$porCliente = consultar($pdo, "
    SELECT COALESCE(c.nome, CASE WHEN p.tipo = 'estoque' THEN '(produção para estoque)' ELSE '(sem cliente)' END) AS cliente,
           c.cidade, c.estado,
           COUNT(DISTINCT p.id) AS pedidos,
           SUM(CASE WHEN $vivo THEN pi.quantidade ELSE 0 END) AS qtd_pedida,
           SUM(CASE WHEN $vivo THEN $falta ELSE 0 END) AS qtd_falta,
           SUM(CASE WHEN $vivo THEN pi.quantidade * pi.preco_unitario ELSE 0 END) AS valor_pedido
    $baseSql
    GROUP BY c.id, c.nome, c.cidade, c.estado, p.tipo
    ORDER BY valor_pedido DESC
", $params);

// ---------- Agenda de entregas (só o que ainda está em aberto) ----------
$agenda = consultar($pdo, "
    SELECT p.id, p.tipo, p.status, p.data_entrega_prometida,
           DATEDIFF(p.data_entrega_prometida, CURDATE()) AS dias,
           COALESCE(c.nome, '(estoque)') AS cliente,
           SUM($falta) AS itens_falta,
           SUM($falta * pi.preco_unitario) AS valor_falta,
           CASE
               WHEN p.data_entrega_prometida < CURDATE() THEN 'atrasado'
               WHEN p.data_entrega_prometida = CURDATE() THEN 'hoje'
               WHEN p.data_entrega_prometida <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'semana'
               ELSE 'depois'
           END AS janela
    $baseSql AND $pendente
    GROUP BY p.id
    HAVING itens_falta > 0
    ORDER BY p.data_entrega_prometida, p.id
", $params);

// ---------- O que falta fabricar, por produto ----------
$aFabricar = consultar($pdo, "
    SELECT prod.id, prod.nome, prod.estoque AS estoque_pronto,
           SUM($falta) AS falta_produzir,
           SUM($falta * pi.preco_unitario) AS valor_falta,
           MIN(p.data_entrega_prometida) AS entrega_mais_proxima,
           DATEDIFF(MIN(p.data_entrega_prometida), CURDATE()) AS dias,
           COUNT(DISTINCT p.id) AS pedidos,
           {$sqlMontavelProduto} AS montavel
    $baseSql AND $pendente
    GROUP BY prod.id, prod.nome, prod.estoque
    HAVING falta_produzir > 0
    ORDER BY entrega_mais_proxima, falta_produzir DESC
", $params);

// ---------- Log de produção dos itens selecionados ----------
$baseProducao = "
    FROM producoes pr
    JOIN pedido_itens pi ON pi.id = pr.pedido_item_id
    JOIN pedidos p   ON p.id = pi.pedido_id
    JOIN produtos prod ON prod.id = pi.produto_id
    LEFT JOIN clientes c ON c.id = p.cliente_id
    LEFT JOIN usuarios u ON u.id = pr.usuario_id
    $whereSql
";

$producaoPorDia = consultar($pdo, "
    SELECT pr.data_producao AS data, SUM(pr.quantidade) AS pecas, COUNT(pr.id) AS lotes
    $baseProducao
    GROUP BY pr.data_producao
    ORDER BY pr.data_producao
", $params);

$producaoPorUsuario = consultar($pdo, "
    SELECT COALESCE(u.nome, '(não informado)') AS usuario,
           SUM(pr.quantidade) AS pecas, COUNT(pr.id) AS lotes
    $baseProducao
    GROUP BY pr.usuario_id, u.nome
    ORDER BY pecas DESC
", $params);

jsonResponse([
    'periodo' => ['de' => $de, 'ate' => $ate, 'base' => $base],
    'resumo' => $resumo,
    'por_produto' => $porProduto,
    'por_pedido' => $pedidos,
    'por_cliente' => $porCliente,
    'agenda' => $agenda,
    'a_fabricar' => $aFabricar,
    'producao_por_dia' => $producaoPorDia,
    'producao_por_usuario' => $producaoPorUsuario,
]);
