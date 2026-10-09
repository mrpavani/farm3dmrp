<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
$usuario = exigirLoginApi();
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Método não suportado.', 405);
}

try {
    // ==========================================
    // 1. PRODUTOS & ESTOQUE PRONTO
    // ==========================================
    $sqlDemanda = "
        SELECT pi.produto_id, 
               SUM(GREATEST(pi.quantidade - pi.quantidade_produzida, 0)) AS demanda_aberta
        FROM pedido_itens pi
        JOIN pedidos p ON p.id = pi.pedido_id
        WHERE p.status IN ('aberto', 'em_producao')
        GROUP BY pi.produto_id
    ";
    $demandas = $pdo->query($sqlDemanda)->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmtProds = $pdo->query("
        SELECT id, nome, tipo, descricao, preco, estoque, custo_filamento, custo_total, margem_lucro,
               peso_gramas, tempo_producao_segundos, ativo
        FROM produtos
        ORDER BY estoque DESC, nome ASC
    ");
    $todosProdutos = $stmtProds->fetchAll();

    $totalUnidadesProntas = 0;
    $modelosProntos = 0;
    $valorVendaEstoquePronto = 0.0;
    $valorCustoEstoquePronto = 0.0;
    $produtosComEstoque = [];

    foreach ($todosProdutos as &$prod) {
        $est = (int) $prod['estoque'];
        $preco = (float) $prod['preco'];
        $custo = (float) $prod['custo_total'];
        $demanda = (int) ($demandas[$prod['id']] ?? 0);

        $prod['demanda_aberta'] = $demanda;
        $prod['valor_estoque_venda'] = round($est * $preco, 2);
        $prod['valor_estoque_custo'] = round($est * $custo, 2);
        $prod['lucro_unitario'] = round($preco - $custo, 2);

        if ($est > 0) {
            $totalUnidadesProntas += $est;
            $modelosProntos++;
            $valorVendaEstoquePronto += ($est * $preco);
            $valorCustoEstoquePronto += ($est * $custo);
            $produtosComEstoque[] = $prod;
        }
    }
    unset($prod);

    // ==========================================
    // 2. FILAMENTOS & MATÉRIA-PRIMA
    // ==========================================
    $stmtFil = $pdo->query("SELECT id FROM filamentos WHERE ativo = 1 ORDER BY cor ASC");
    $filIds = $stmtFil->fetchAll(PDO::FETCH_COLUMN);

    $totalGramasFil = 0.0;
    $totalValorFil = 0.0;
    $totalRolosFil = 0.0;
    $qtdAlertasFil = 0;
    $filamentosLista = [];

    // Pre-calcula resumo das cores ativas para apurar alertas estritamente por COR
    $resumoCores = obterResumoEstoqueCores($pdo);
    foreach ($resumoCores as $c) {
        if ($c['status'] !== 'ok') {
            $qtdAlertasFil++;
        }
    }

    foreach ($filIds as $fId) {
        $info = obterResumoFilamento($pdo, (int) $fId, $resumoCores);
        if ($info) {
            $totalGramasFil += (float) $info['estoque_gramas'];
            $totalValorFil += (float) $info['valor_total_estoque'];
            $totalRolosFil += (float) $info['estoque_rolos'];
            $filamentosLista[] = $info;
        }
    }

    $custoMedioGeralKg = $totalGramasFil > 0 ? (($totalValorFil / $totalGramasFil) * 1000) : 0.0;

    // ==========================================
    // 3. PEDIDOS & FLUXO DE OPERAÇÃO
    // ==========================================
    $stmtPedidos = $pdo->query("
        SELECT p.id, p.tipo, p.status, p.data_pedido, p.data_entrega_prometida,
               c.nome AS cliente_nome,
               COALESCE(SUM(pi.quantidade), 0) AS total_itens,
               COALESCE(SUM(pi.quantidade * pi.preco_unitario), 0) AS valor_total,
               DATEDIFF(p.data_entrega_prometida, CURDATE()) AS dias_prazo
        FROM pedidos p
        LEFT JOIN pedido_itens pi ON pi.pedido_id = p.id
        LEFT JOIN clientes c ON c.id = p.cliente_id
        GROUP BY p.id
        ORDER BY p.data_entrega_prometida ASC, p.id DESC
    ");
    $todosPedidos = $stmtPedidos->fetchAll();

    $pedidosPorStatus = [
        'aberto' => 0,
        'em_producao' => 0,
        'pronto' => 0,
        'entregue' => 0,
        'cancelado' => 0,
    ];
    $valoresPorStatus = [
        'aberto' => 0.0,
        'em_producao' => 0.0,
        'pronto' => 0.0,
        'entregue' => 0.0,
    ];
    $pedidosAtrasados = 0;
    $pedidosUrgentes = 0; // entrega em até 3 dias
    $pedidosCriticosLista = [];

    foreach ($todosPedidos as $ped) {
        $st = $ped['status'];
        if (isset($pedidosPorStatus[$st])) {
            $pedidosPorStatus[$st]++;
        }
        $v = (float) $ped['valor_total'];
        if (isset($valoresPorStatus[$st])) {
            $valoresPorStatus[$st] += $v;
        }

        // Se está pendente (não entregue e não cancelado)
        if (in_array($st, ['aberto', 'em_producao', 'pronto'], true)) {
            $dias = $ped['dias_prazo'];
            if ($dias !== null && $dias < 0) {
                $pedidosAtrasados++;
                $ped['alerta_prazo'] = 'atrasado';
                $pedidosCriticosLista[] = $ped;
            } elseif ($dias !== null && $dias <= 3) {
                $pedidosUrgentes++;
                $ped['alerta_prazo'] = 'urgente';
                $pedidosCriticosLista[] = $ped;
            }
        }
    }

    $totalPedidosValidos = count($todosPedidos) - $pedidosPorStatus['cancelado'];
    $valorEmCarteira = $valoresPorStatus['aberto'] + $valoresPorStatus['em_producao'] + $valoresPorStatus['pronto'];
    $valorTotalRealizado = $valoresPorStatus['entregue'];
    $ticketMedio = $totalPedidosValidos > 0 ? (($valorEmCarteira + $valorTotalRealizado) / $totalPedidosValidos) : 0.0;

    // Resposta JSON estruturada
    jsonResponse([
        'kpis' => [
            // Produtos Prontos
            'produtos_prontos_unidades' => $totalUnidadesProntas,
            'produtos_prontos_modelos' => $modelosProntos,
            'produtos_total_catalogo' => count($todosProdutos),
            'produtos_valor_venda_estoque' => round($valorVendaEstoquePronto, 2),
            'produtos_valor_custo_estoque' => round($valorCustoEstoquePronto, 2),
            'produtos_lucro_potencial' => round($valorVendaEstoquePronto - $valorCustoEstoquePronto, 2),

            // Filamentos
            'filamentos_total_cadastrados' => count($filamentosLista),
            'filamentos_total_kg' => round($totalGramasFil / 1000, 2),
            'filamentos_total_gramas' => round($totalGramasFil, 2),
            'filamentos_total_rolos' => round($totalRolosFil, 2),
            'filamentos_valor_total' => round($totalValorFil, 2),
            'filamentos_custo_medio_kg' => round($custoMedioGeralKg, 2),
            'filamentos_alertas' => $qtdAlertasFil,

            // Pedidos
            'pedidos_total' => count($todosPedidos),
            'pedidos_em_aberto' => $pedidosPorStatus['aberto'],
            'pedidos_em_producao' => $pedidosPorStatus['em_producao'],
            'pedidos_prontos' => $pedidosPorStatus['pronto'],
            'pedidos_entregues' => $pedidosPorStatus['entregue'],
            'pedidos_cancelados' => $pedidosPorStatus['cancelado'],
            'pedidos_valor_em_carteira' => round($valorEmCarteira, 2),
            'pedidos_valor_entregue' => round($valorTotalRealizado, 2),
            'pedidos_ticket_medio' => round($ticketMedio, 2),
            'pedidos_atrasados' => $pedidosAtrasados,
            'pedidos_urgentes' => $pedidosUrgentes,
        ],
        'produtos_prontos' => $produtosComEstoque,
        'todos_produtos' => $todosProdutos,
        'filamentos' => $filamentosLista,
        'cores_filamentos' => array_values($resumoCores),
        'pedidos_criticos' => array_slice($pedidosCriticosLista, 0, 10),
    ]);

} catch (Exception $e) {
    jsonError('Erro ao carregar dados do dashboard: ' . $e->getMessage(), 500);
}
