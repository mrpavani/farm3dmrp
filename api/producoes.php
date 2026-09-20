<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
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
// Body: {
//   pedido_item_id: 7, data_producao: "2026-09-19", quantidade: 5,
//   observacoes: "...",
//   montar_se_faltar: true   -> atalho "montar e aplicar": monta o que
//                               faltar de produto pronto e já aplica no pedido
// }
//
// Atender um item de pedido CONSOME estoque de produto pronto:
//   - pedido de venda   -> baixa produtos.estoque (montando antes, se pedido)
//   - ordem de estoque  -> produz (monta a partir das peças) e o saldo fica em estoque
//
// Assim a cadeia peça -> montagem -> pedido fecha, sem contagem dupla.
// ------------------------------------------------------------
if ($method === 'POST') {
    $b = readJsonBody();
    $qtd = (int) ($b['quantidade'] ?? 0);
    $montarSeFaltar = !empty($b['montar_se_faltar']);

    if (empty($b['pedido_item_id']) || empty($b['data_producao']) || $qtd <= 0) {
        jsonError('pedido_item_id, data_producao e quantidade (> 0) são obrigatórios.');
    }

    $pdo->beginTransaction();
    try {
        $itemStmt = $pdo->prepare("
            SELECT pi.*, p.status AS pedido_status, p.tipo AS pedido_tipo, prod.nome AS produto_nome
            FROM pedido_itens pi
            JOIN pedidos p ON p.id = pi.pedido_id
            JOIN produtos prod ON prod.id = pi.produto_id
            WHERE pi.id = :id
            FOR UPDATE
        ");
        $itemStmt->execute(['id' => $b['pedido_item_id']]);
        $item = $itemStmt->fetch();
        if (!$item) throw new Exception('Item de pedido não encontrado.');
        if (in_array($item['pedido_status'], ['entregue', 'cancelado'], true)) {
            throw new Exception("O pedido está {$item['pedido_status']}; reabra-o antes de registrar produção.");
        }

        $restante = (int) $item['quantidade'] - (int) $item['quantidade_produzida'];
        if ($qtd > $restante) {
            throw new Exception("Quantidade ($qtd) maior que o restante a produzir ($restante).");
        }

        $produtoId = (int) $item['produto_id'];
        $montadasAgora = 0;

        if ($item['pedido_tipo'] === 'estoque') {
            // Ordem de estoque: produzir É o objetivo. O saldo fica no estoque.
            produzirProdutoPronto($pdo, $produtoId, $qtd, $usuario['id'],
                "Ordem de estoque #{$item['pedido_id']}");
            $montadasAgora = $qtd;
        } else {
            // Pedido de venda: precisa sair do estoque de produto pronto.
            $estoqueStmt = $pdo->prepare("SELECT estoque FROM produtos WHERE id = :id FOR UPDATE");
            $estoqueStmt->execute(['id' => $produtoId]);
            $disponivel = (int) $estoqueStmt->fetchColumn();

            if ($disponivel < $qtd) {
                $faltam = $qtd - $disponivel;
                if (!$montarSeFaltar) {
                    $cap = capacidadeMontagem($pdo, $produtoId);
                    $dica = $cap['tem_ficha']
                        ? sprintf(' Dá para montar %d agora com as peças em estoque%s.',
                            $cap['capacidade'],
                            $cap['gargalos'] ? ' (gargalo: ' . implode(', ', $cap['gargalos']) . ')' : '')
                        : '';
                    throw new Exception(sprintf(
                        'Faltam %d un. de "%s": há %d pronto(s) no estoque e o pedido precisa de %d.%s',
                        $faltam, $item['produto_nome'], $disponivel, $qtd, $dica
                    ));
                }
                // Atalho "montar e aplicar": monta só o que falta.
                produzirProdutoPronto($pdo, $produtoId, $faltam, $usuario['id'],
                    "Montagem para o pedido #{$item['pedido_id']}");
                $montadasAgora = $faltam;
            }

            consumirProdutoPronto($pdo, $produtoId, $qtd, $usuario['id'], (int) $item['id']);
        }

        $pdo->prepare("
            INSERT INTO producoes (pedido_item_id, data_producao, quantidade, usuario_id, observacoes)
            VALUES (:pedido_item_id, :data_producao, :quantidade, :usuario_id, :observacoes)
        ")->execute([
            'pedido_item_id' => $b['pedido_item_id'],
            'data_producao' => $b['data_producao'],
            'quantidade' => $qtd,
            'usuario_id' => $usuario['id'],
            'observacoes' => $b['observacoes'] ?? null,
        ]);

        $pdo->prepare("UPDATE pedido_itens SET quantidade_produzida = quantidade_produzida + :q WHERE id = :id")
            ->execute(['q' => $qtd, 'id' => $b['pedido_item_id']]);

        $novoStatus = recalcularStatusPedido($pdo, (int) $item['pedido_id']);

        $pdo->commit();
        jsonResponse([
            'ok' => true,
            'status_pedido' => $novoStatus,
            'montadas_agora' => $montadasAgora,
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError($e->getMessage(), 400);
    }
}

jsonError('Método não suportado.', 405);
