<?php
require_once __DIR__ . '/../includes/auth.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ------------------------------------------------------------
// POST: Ações rápidas na bancada da fábrica
// ------------------------------------------------------------
if ($method === 'POST') {
    $acao = $_GET['acao'] ?? '';

    // 1. Registrar peças recém-impressas (incrementar estoque de peças)
    if ($acao === 'registrar_producao_peca') {
        $b = readJsonBody();
        $pecaId = (int) ($b['peca_id'] ?? 0);
        $qtd = (int) ($b['quantidade'] ?? 0);

        if (!$pecaId || $qtd <= 0) {
            jsonError('Informe a peça e a quantidade produzida (mínimo 1).');
        }

        $stmt = $pdo->prepare("UPDATE produto_pecas SET estoque = estoque + :qtd WHERE id = :id");
        $stmt->execute(['qtd' => $qtd, 'id' => $pecaId]);

        $stmtNovo = $pdo->prepare("SELECT estoque, nome, cor FROM produto_pecas WHERE id = :id");
        $stmtNovo->execute(['id' => $pecaId]);
        $peca = $stmtNovo->fetch();

        jsonResponse([
            'ok' => true,
            'mensagem' => "+{$qtd} unidade(s) de \"{$peca['nome']}\" adicionada(s) ao estoque.",
            'novo_estoque' => (int) $peca['estoque']
        ]);
    }

    // 2. Ajustar saldo manual de peças
    if ($acao === 'ajustar_saldo_peca') {
        $b = readJsonBody();
        $pecaId = (int) ($b['peca_id'] ?? 0);
        $novoSaldo = max(0, (int) ($b['estoque'] ?? 0));

        if (!$pecaId) jsonError('Informe a peça.');

        $stmt = $pdo->prepare("UPDATE produto_pecas SET estoque = :estoque WHERE id = :id");
        $stmt->execute(['estoque' => $novoSaldo, 'id' => $pecaId]);

        jsonResponse(['ok' => true, 'novo_estoque' => $novoSaldo]);
    }

    jsonError('Ação não suportada.', 405);
}

// ------------------------------------------------------------
// GET /api/fabrica_pecas.php
// Visão completa do painel de peças: foto, estoque e fila de impressão
// ------------------------------------------------------------
if ($method === 'GET') {
    // 1. Calcular demanda líquida de cada produto final a partir de pedidos abertos ou em produção
    $sqlPedidos = "
        SELECT 
            pi.produto_id,
            SUM(pi.quantidade - pi.quantidade_produzida) AS demanda_bruta,
            MIN(p.data_entrega_prometida) AS proxima_entrega
        FROM pedido_itens pi
        INNER JOIN pedidos p ON p.id = pi.pedido_id
        WHERE p.status IN ('aberto', 'em_producao')
          AND pi.quantidade > pi.quantidade_produzida
        GROUP BY pi.produto_id
    ";
    $demandasPorProduto = [];
    $proximaEntregaPorProduto = [];
    foreach ($pdo->query($sqlPedidos)->fetchAll() as $row) {
        $pid = (int) $row['produto_id'];
        $demandasPorProduto[$pid] = (int) $row['demanda_bruta'];
        $proximaEntregaPorProduto[$pid] = $row['proxima_entrega'];
    }

    // 2. Buscar todas as peças cadastradas e seus produtos pais
    $sqlPecas = "
        SELECT 
            pp.id AS peca_id,
            pp.produto_id,
            pp.nome AS peca_nome,
            pp.quantidade AS por_unidade,
            pp.cor,
            pp.estoque AS estoque_pecas,
            pp.foto,
            prod.nome AS produto_nome,
            prod.estoque AS estoque_produto_pronto,
            prod.ativo AS produto_ativo
        FROM produto_pecas pp
        INNER JOIN produtos prod ON prod.id = pp.produto_id
        ORDER BY prod.nome ASC, pp.nome ASC
    ";
    $linhas = $pdo->query($sqlPecas)->fetchAll();

    $pecasResultado = [];
    $totalAImprimirGeral = 0;
    $totalEstoqueGeral = 0;
    $pecasComFila = 0;
    $produtosFiltro = [];
    $coresFiltro = [];

    foreach ($linhas as $linha) {
        $prodId = (int) $linha['produto_id'];
        $porUnidade = max(1, (int) $linha['por_unidade']);
        $estoquePeca = (int) $linha['estoque_pecas'];
        $estoquePronto = (int) $linha['estoque_produto_pronto'];
        $demandaBruta = $demandasPorProduto[$prodId] ?? 0;
        $proximaEntrega = $proximaEntregaPorProduto[$prodId] ?? null;

        // Demanda líquida do produto final (abatendo o que já está montado no estoque)
        $demandaLiquidaProd = max(0, $demandaBruta - $estoquePronto);

        // Quantidade total desta peça necessária para cobrir a demanda
        $necessarioParaPedidos = $demandaLiquidaProd * $porUnidade;

        // Quantidade que efetivamente precisa ser impressa
        $aImprimir = max(0, $necessarioParaPedidos - $estoquePeca);
        $sobraEstoque = max(0, $estoquePeca - $necessarioParaPedidos);

        $totalAImprimirGeral += $aImprimir;
        $totalEstoqueGeral += $estoquePeca;
        if ($aImprimir > 0) {
            $pecasComFila++;
        }

        // Determina nível de urgência
        $statusUrgencia = 'ok';
        if ($aImprimir > 0) {
            if ($proximaEntrega && $proximaEntrega <= date('Y-m-d')) {
                $statusUrgencia = 'urgente'; // atrasado ou entrega hoje
            } else {
                $statusUrgencia = 'imprimir';
            }
        }

        $corNormalizada = trim($linha['cor'] ?? '');
        if ($corNormalizada === '') $corNormalizada = 'Padrão';

        $produtosFiltro[$prodId] = $linha['produto_nome'];
        $coresFiltro[$corNormalizada] = true;

        $pecasResultado[] = [
            'peca_id' => (int) $linha['peca_id'],
            'produto_id' => $prodId,
            'peca_nome' => $linha['peca_nome'],
            'produto_nome' => $linha['produto_nome'],
            'por_unidade' => $porUnidade,
            'cor' => $corNormalizada,
            'foto' => $linha['foto'],
            'estoque_pecas' => $estoquePeca,
            'pedidos_pendentes_produto' => $demandaBruta,
            'estoque_produto_pronto' => $estoquePronto,
            'necessario_pedidos' => $necessarioParaPedidos,
            'a_imprimir' => $aImprimir,
            'sobra_estoque' => $sobraEstoque,
            'proxima_entrega' => $proximaEntrega,
            'status' => $statusUrgencia,
        ];
    }

    // Ordena: primeiro as peças que mais precisam ser impressas (a_imprimir desc, proxima_entrega asc)
    usort($pecasResultado, function($a, $b) {
        if ($a['a_imprimir'] > 0 && $b['a_imprimir'] === 0) return -1;
        if ($a['a_imprimir'] === 0 && $b['a_imprimir'] > 0) return 1;
        if ($a['a_imprimir'] !== $b['a_imprimir']) return $b['a_imprimir'] <=> $a['a_imprimir'];
        return strcmp($a['peca_nome'], $b['peca_nome']);
    });

    jsonResponse([
        'resumo' => [
            'total_tipos_pecas' => count($pecasResultado),
            'pecas_com_fila' => $pecasComFila,
            'total_a_imprimir' => $totalAImprimirGeral,
            'total_estoque' => $totalEstoqueGeral
        ],
        'pecas' => $pecasResultado,
        'produtos_filtro' => $produtosFiltro,
        'cores_filtro' => array_keys($coresFiltro)
    ]);
}
