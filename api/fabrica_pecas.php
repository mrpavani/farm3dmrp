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
// Visão completa do painel de peças: foto, estoque e fila de impressão, agrupado por produto
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
            pp.foto AS peca_foto,
            prod.nome AS produto_nome,
            prod.estoque AS estoque_produto_pronto,
            prod.ativo AS produto_ativo,
            prod.foto AS produto_foto
        FROM produto_pecas pp
        INNER JOIN produtos prod ON prod.id = pp.produto_id
        ORDER BY prod.nome ASC, pp.nome ASC
    ";
    $linhas = $pdo->query($sqlPecas)->fetchAll();

    $produtosIndexados = [];
    $totalAImprimirGeral = 0;
    $totalEstoqueGeral = 0;
    $pecasComFila = 0;

    foreach ($linhas as $linha) {
        $prodId = (int) $linha['produto_id'];
        
        if (!isset($produtosIndexados[$prodId])) {
            $demandaBruta = $demandasPorProduto[$prodId] ?? 0;
            $estoquePronto = (int) $linha['estoque_produto_pronto'];
            $demandaLiquidaProd = max(0, $demandaBruta - $estoquePronto);

            $produtosIndexados[$prodId] = [
                'id' => $prodId,
                'nome' => $linha['produto_nome'],
                'foto' => $linha['produto_foto'],
                'estoque' => $estoquePronto,
                'ativo' => $linha['produto_ativo'],
                'em_producao' => $demandaBruta,
                'demanda_liquida' => $demandaLiquidaProd,
                'proxima_entrega' => $proximaEntregaPorProduto[$prodId] ?? null,
                'capacidade_montagem' => null, // será calculado depois
                'maior_peca_qtd' => 0,
                'pecas' => []
            ];
        }

        $porUnidade = max(1, (int) $linha['por_unidade']);
        $estoquePeca = (int) $linha['estoque_pecas'];
        
        // Atualiza a maior quantidade de peças por unidade para o produto
        if ($porUnidade > $produtosIndexados[$prodId]['maior_peca_qtd']) {
            $produtosIndexados[$prodId]['maior_peca_qtd'] = $porUnidade;
        }

        // Atualiza a capacidade de montagem (o mínimo possível de produtos que podem ser montados com o estoque atual dessa peça)
        $capacidadeDestaPeca = floor($estoquePeca / $porUnidade);
        if ($produtosIndexados[$prodId]['capacidade_montagem'] === null || $capacidadeDestaPeca < $produtosIndexados[$prodId]['capacidade_montagem']) {
            $produtosIndexados[$prodId]['capacidade_montagem'] = $capacidadeDestaPeca;
        }

        $demandaLiquidaProd = $produtosIndexados[$prodId]['demanda_liquida'];
        $necessarioParaPedidos = $demandaLiquidaProd * $porUnidade;
        $aImprimir = max(0, $necessarioParaPedidos - $estoquePeca);
        $sobraEstoque = max(0, $estoquePeca - $necessarioParaPedidos);

        $totalAImprimirGeral += $aImprimir;
        $totalEstoqueGeral += $estoquePeca;
        if ($aImprimir > 0) {
            $pecasComFila++;
        }

        $proximaEntrega = $produtosIndexados[$prodId]['proxima_entrega'];
        $statusUrgencia = 'ok';
        if ($aImprimir > 0) {
            if ($proximaEntrega && $proximaEntrega <= date('Y-m-d')) {
                $statusUrgencia = 'urgente';
            } else {
                $statusUrgencia = 'imprimir';
            }
        }

        $corNormalizada = trim($linha['cor'] ?? '');
        if ($corNormalizada === '') $corNormalizada = 'Padrão';

        $produtosIndexados[$prodId]['pecas'][] = [
            'peca_id' => (int) $linha['peca_id'],
            'nome' => $linha['peca_nome'],
            'cor' => $corNormalizada,
            'foto' => $linha['peca_foto'],
            'por_unidade' => $porUnidade,
            'estoque' => $estoquePeca,
            'necessario_pedidos' => $necessarioParaPedidos,
            'a_imprimir' => $aImprimir,
            'sobra_estoque' => $sobraEstoque,
            'status' => $statusUrgencia,
        ];
    }

    $produtosArray = array_values($produtosIndexados);

    // Ordena: produtos com fila de impressão primeiro, depois alfabético
    usort($produtosArray, function($a, $b) {
        $aTemFila = array_sum(array_column($a['pecas'], 'a_imprimir')) > 0;
        $bTemFila = array_sum(array_column($b['pecas'], 'a_imprimir')) > 0;
        if ($aTemFila && !$bTemFila) return -1;
        if (!$aTemFila && $bTemFila) return 1;
        return strcmp($a['nome'], $b['nome']);
    });

    jsonResponse([
        'resumo' => [
            'total_produtos' => count($produtosArray),
            'pecas_com_fila' => $pecasComFila,
            'total_a_imprimir' => $totalAImprimirGeral,
            'total_estoque' => $totalEstoqueGeral
        ],
        'produtos' => $produtosArray
    ]);
}
