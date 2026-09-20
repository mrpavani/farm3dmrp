<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

const STATUS_FINAIS = ['entregue', 'cancelado'];

// Nomes de quem registrou produção em cada item (usado nas consultas de itens)
$sqlProduzidoPor = "(SELECT GROUP_CONCAT(DISTINCT u2.nome ORDER BY u2.nome SEPARATOR ', ')
        FROM producoes x JOIN usuarios u2 ON u2.id = x.usuario_id
        WHERE x.pedido_item_id = pi.id) AS produzido_por";

// Estoque de produto pronto e quantas unidades dá para montar agora com as peças
// em saldo (NULL = produto sem ficha técnica, impresso direto). Alimenta o aviso
// "dá para montar" / "usar estoque" na lista de pedidos.
$sqlDisponibilidade = "pr.estoque AS produto_estoque, " . sqlProdutoMontavel('pi.produto_id') . " AS produto_montavel";

// Tipo do pedido: "venda" (cliente obrigatório) ou "estoque" (sem cliente).
function tipoPedido(array $b): string {
    return ($b['tipo'] ?? 'venda') === 'estoque' ? 'estoque' : 'venda';
}

// Retorna o cliente_id informado ou cria um cliente novo a partir de cliente_novo.
// Ordens de estoque não têm cliente (retorna null).
function resolverCliente(PDO $pdo, array $b, string $tipo): ?int {
    if ($tipo === 'estoque') {
        return null;
    }
    if (!empty($b['cliente_id'])) {
        return (int) $b['cliente_id'];
    }
    if (!empty($b['cliente_novo']) && is_array($b['cliente_novo'])) {
        return inserirCliente($pdo, dadosCliente($b['cliente_novo']));
    }
    throw new Exception('Informe um cliente existente (cliente_id) ou os dados de um cliente novo.');
}

function validarCabecalho(array $b): void {
    if (empty($b['itens']) || !is_array($b['itens'])) {
        jsonError('O pedido precisa ter ao menos um item.');
    }
    if (empty($b['data_pedido']) || empty($b['data_entrega_prometida'])) {
        jsonError(tipoPedido($b) === 'estoque'
            ? 'Informe a data prevista de conclusão.'
            : 'Data do pedido e data de entrega prometida são obrigatórias.');
    }
    if ($b['data_entrega_prometida'] < $b['data_pedido']) {
        jsonError('A data prevista/prometida não pode ser anterior à data de criação.');
    }
    foreach ($b['itens'] as $item) {
        if (empty($item['produto_id']) || empty($item['quantidade']) || (int) $item['quantidade'] <= 0) {
            jsonError('Cada item precisa de produto_id e quantidade maior que zero.');
        }
    }
}

// Preço de tabela do produto no momento em que o item entra no pedido.
// Fica congelado em pedido_itens.preco_unitario: reajustar o produto depois
// não altera o valor de pedidos já feitos.
function precoAtualProduto(PDO $pdo, int $produtoId): float {
    $stmt = $pdo->prepare("SELECT preco FROM produtos WHERE id = :id");
    $stmt->execute(['id' => $produtoId]);
    $preco = $stmt->fetchColumn();
    if ($preco === false) throw new Exception("Produto #$produtoId não encontrado.");
    return (float) $preco;
}

function buscarPedidoParaAlterar(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT * FROM pedidos WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $id]);
    $pedido = $stmt->fetch();
    if (!$pedido) throw new Exception('Pedido não encontrado.');
    return $pedido;
}

// ------------------------------------------------------------
// GET /api/pedidos.php
//   ?data_de=YYYY-MM-DD&data_ate=YYYY-MM-DD  -> filtra por data_pedido
//   ?status=aberto                            -> filtra por status
//   ?tipo=venda|estoque                       -> filtra por tipo
//   ?id=123                                   -> retorna 1 pedido com itens
// ------------------------------------------------------------
if ($method === 'GET') {

    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare("
            SELECT p.*, c.nome AS cliente_nome, c.cidade AS cliente_cidade, c.estado AS cliente_estado,
                   u.nome AS usuario_nome
            FROM pedidos p
            LEFT JOIN clientes c ON c.id = p.cliente_id
            LEFT JOIN usuarios u ON u.id = p.usuario_id
            WHERE p.id = :id
        ");
        $stmt->execute(['id' => $_GET['id']]);
        $pedido = $stmt->fetch();
        if (!$pedido) jsonError('Pedido não encontrado.', 404);

        $itensStmt = $pdo->prepare("
            SELECT pi.*, pr.nome AS produto_nome, {$sqlDisponibilidade}, {$sqlProduzidoPor}
            FROM pedido_itens pi
            JOIN produtos pr ON pr.id = pi.produto_id
            WHERE pi.pedido_id = :id
            ORDER BY pi.id
        ");
        $itensStmt->execute(['id' => $_GET['id']]);
        $pedido['itens'] = $itensStmt->fetchAll();

        jsonResponse($pedido);
    }

    $where = [];
    $params = [];
    if (!empty($_GET['data_de'])) {
        $where[] = 'p.data_pedido >= :data_de';
        $params['data_de'] = $_GET['data_de'];
    }
    if (!empty($_GET['data_ate'])) {
        $where[] = 'p.data_pedido <= :data_ate';
        $params['data_ate'] = $_GET['data_ate'];
    }
    if (!empty($_GET['status'])) {
        $where[] = 'p.status = :status';
        $params['status'] = $_GET['status'];
    }
    if (!empty($_GET['tipo'])) {
        $where[] = 'p.tipo = :tipo';
        $params['tipo'] = tipoPedido(['tipo' => $_GET['tipo']]);
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $pdo->prepare("
        SELECT p.*, c.nome AS cliente_nome, c.cidade AS cliente_cidade, c.estado AS cliente_estado,
               u.nome AS usuario_nome
        FROM pedidos p
        LEFT JOIN clientes c ON c.id = p.cliente_id
        LEFT JOIN usuarios u ON u.id = p.usuario_id
        $whereSql
        ORDER BY p.data_pedido DESC, p.id DESC
    ");
    $stmt->execute($params);
    $pedidos = $stmt->fetchAll();

    // Anexa os itens de cada pedido numa segunda query (simples e evita N+1 real)
    if ($pedidos) {
        $ids = array_column($pedidos, 'id');
        $in = implode(',', array_fill(0, count($ids), '?'));
        $itensStmt = $pdo->prepare("
            SELECT pi.*, pr.nome AS produto_nome, {$sqlDisponibilidade}, {$sqlProduzidoPor}
            FROM pedido_itens pi
            JOIN produtos pr ON pr.id = pi.produto_id
            WHERE pi.pedido_id IN ($in)
            ORDER BY pi.id
        ");
        $itensStmt->execute($ids);
        $todosItens = $itensStmt->fetchAll();

        $itensPorPedido = [];
        foreach ($todosItens as $item) {
            $itensPorPedido[$item['pedido_id']][] = $item;
        }
        foreach ($pedidos as &$p) {
            $p['itens'] = $itensPorPedido[$p['id']] ?? [];
        }
        unset($p);
    }

    jsonResponse($pedidos);
}

// ------------------------------------------------------------
// POST /api/pedidos.php
// O pedido é registrado em nome do usuário logado.
// Body: {
//   tipo: "venda" (padrão) | "estoque"  -> estoque = produção sem cliente
//   cliente_id: 1 (ou cliente_novo: {...})  -> obrigatório só em "venda",
//   data_pedido: "2026-09-16",
//   data_entrega_prometida: "2026-09-25",
//   observacoes: "...",
//   itens: [ {produto_id: 2, quantidade: 10}, {produto_id: 5, quantidade: 3} ]
// }
// ------------------------------------------------------------
if ($method === 'POST') {
    $b = readJsonBody();
    validarCabecalho($b);

    $pdo->beginTransaction();
    try {
        $tipo = tipoPedido($b);
        $clienteId = resolverCliente($pdo, $b, $tipo);

        $stmt = $pdo->prepare("
            INSERT INTO pedidos (tipo, cliente_id, usuario_id, data_pedido, data_entrega_prometida, observacoes)
            VALUES (:tipo, :cliente_id, :usuario_id, :data_pedido, :data_entrega_prometida, :observacoes)
        ");
        $stmt->execute([
            'tipo' => $tipo,
            'cliente_id' => $clienteId,
            'usuario_id' => $usuario['id'],
            'data_pedido' => $b['data_pedido'],
            'data_entrega_prometida' => $b['data_entrega_prometida'],
            'observacoes' => $b['observacoes'] ?? null,
        ]);
        $pedidoId = $pdo->lastInsertId();

        $itemStmt = $pdo->prepare("
            INSERT INTO pedido_itens (pedido_id, produto_id, preco_unitario, quantidade, quantidade_estoque, quantidade_produzida)
            VALUES (:pedido_id, :produto_id, :preco_unitario, :quantidade, :quantidade_estoque, :quantidade_produzida)
        ");
        $updEstoque = $pdo->prepare("UPDATE produtos SET estoque = estoque - :qtd WHERE id = :id");

        foreach ($b['itens'] as $item) {
            $qtd = (int) $item['quantidade'];
            $qtdEstoque = 0;
            $preco = precoAtualProduto($pdo, (int) $item['produto_id']);

            if ($tipo === 'venda') {
                $prodStmt = $pdo->prepare("SELECT estoque FROM produtos WHERE id = :id FOR UPDATE");
                $prodStmt->execute(['id' => $item['produto_id']]);
                $estoqueAtual = (int) $prodStmt->fetchColumn();

                if ($estoqueAtual > 0) {
                    $qtdEstoque = min($qtd, $estoqueAtual);
                    $updEstoque->execute(['qtd' => $qtdEstoque, 'id' => $item['produto_id']]);
                    registrarMovimento($pdo, 'pedido_atendido', [
                        'produto_id'   => (int) $item['produto_id'],
                        'quantidade'   => -$qtdEstoque,
                        'saldo_depois' => $estoqueAtual - $qtdEstoque,
                        'usuario_id'   => $usuario['id'],
                        'observacoes'  => "Alocado do estoque na criação do pedido #$pedidoId",
                    ]);
                }
            }

            $itemStmt->execute([
                'pedido_id' => $pedidoId,
                'produto_id' => $item['produto_id'],
                'preco_unitario' => $preco,
                'quantidade' => $qtd,
                'quantidade_estoque' => $qtdEstoque,
                'quantidade_produzida' => $qtdEstoque,
            ]);
        }

        $pdo->commit();
        jsonResponse(['id' => (int) $pedidoId, 'tipo' => $tipo], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao criar pedido: ' . $e->getMessage(), 400);
    }
}

// ------------------------------------------------------------
// PUT /api/pedidos.php?id=123
// Mesmo body do POST (o tipo do pedido não muda na edição). Nos itens:
//   - com "id"  -> item existente (atualiza produto/quantidade)
//   - sem "id"  -> item novo
//   - itens existentes que não vierem na lista são removidos
// Regras:
//   - pedidos entregues/cancelados não podem ser editados (reabra antes);
//   - item com produção registrada não pode ser removido nem trocar de
//     produto, e sua quantidade não pode ficar abaixo do já produzido.
// ------------------------------------------------------------
if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('Informe o id do pedido.');
    $b = readJsonBody();
    validarCabecalho($b);

    $pdo->beginTransaction();
    try {
        $pedido = buscarPedidoParaAlterar($pdo, $id);
        if (in_array($pedido['status'], STATUS_FINAIS, true)) {
            throw new Exception("Pedido {$pedido['status']} não pode ser editado. Reabra-o primeiro.");
        }

        $clienteId = resolverCliente($pdo, $b, $pedido['tipo']);

        $pdo->prepare("
            UPDATE pedidos SET cliente_id = :cliente_id, data_pedido = :data_pedido,
                   data_entrega_prometida = :data_entrega_prometida, observacoes = :observacoes
            WHERE id = :id
        ")->execute([
            'cliente_id' => $clienteId,
            'data_pedido' => $b['data_pedido'],
            'data_entrega_prometida' => $b['data_entrega_prometida'],
            'observacoes' => $b['observacoes'] ?? null,
            'id' => $id,
        ]);

        // Itens atuais do pedido, indexados por id
        $atuaisStmt = $pdo->prepare("
            SELECT pi.*, pr.nome AS produto_nome
            FROM pedido_itens pi JOIN produtos pr ON pr.id = pi.produto_id
            WHERE pi.pedido_id = :id FOR UPDATE
        ");
        $atuaisStmt->execute(['id' => $id]);
        $atuais = [];
        foreach ($atuaisStmt->fetchAll() as $row) {
            $atuais[(int) $row['id']] = $row;
        }

        $insStmt = $pdo->prepare("INSERT INTO pedido_itens (pedido_id, produto_id, preco_unitario, quantidade, quantidade_estoque, quantidade_produzida) VALUES (:pedido_id, :produto_id, :preco_unitario, :quantidade, :quantidade_estoque, :quantidade_produzida)");
        // Item que continua com o mesmo produto mantém o preço praticado.
        $updStmt = $pdo->prepare("UPDATE pedido_itens SET produto_id = :produto_id, quantidade = :quantidade WHERE id = :id");
        $updComPreco = $pdo->prepare("UPDATE pedido_itens SET produto_id = :produto_id, preco_unitario = :preco_unitario, quantidade = :quantidade WHERE id = :id");
        $delStmt = $pdo->prepare("DELETE FROM pedido_itens WHERE id = :id");

        $mantidos = [];
        foreach ($b['itens'] as $item) {
            $produtoId = (int) $item['produto_id'];
            $qtd = (int) $item['quantidade'];
            $itemId = (int) ($item['id'] ?? 0);

            if (!$itemId) {
                $qtdEstoque = 0;
                if ($pedido['tipo'] === 'venda') {
                    $prodStmt = $pdo->prepare("SELECT estoque FROM produtos WHERE id = :id FOR UPDATE");
                    $prodStmt->execute(['id' => $produtoId]);
                    $estoqueAtual = (int) $prodStmt->fetchColumn();
                    if ($estoqueAtual > 0) {
                        $qtdEstoque = min($qtd, $estoqueAtual);
                        $pdo->prepare("UPDATE produtos SET estoque = estoque - :qtd WHERE id = :id")
                            ->execute(['qtd' => $qtdEstoque, 'id' => $produtoId]);
                        registrarMovimento($pdo, 'pedido_atendido', [
                            'produto_id'   => $produtoId,
                            'quantidade'   => -$qtdEstoque,
                            'saldo_depois' => $estoqueAtual - $qtdEstoque,
                            'usuario_id'   => $usuario['id'],
                            'observacoes'  => "Alocado do estoque ao adicionar item no pedido #$id",
                        ]);
                    }
                }
                $insStmt->execute([
                    'pedido_id' => $id,
                    'produto_id' => $produtoId,
                    'preco_unitario' => precoAtualProduto($pdo, $produtoId),
                    'quantidade' => $qtd,
                    'quantidade_estoque' => $qtdEstoque,
                    'quantidade_produzida' => $qtdEstoque
                ]);
                continue;
            }
            if (!isset($atuais[$itemId])) {
                throw new Exception("Item #$itemId não pertence a este pedido.");
            }
            $atual = $atuais[$itemId];
            $produzido = (int) $atual['quantidade_produzida'];
            if ($produzido > 0 && $produtoId !== (int) $atual['produto_id']) {
                throw new Exception("O item \"{$atual['produto_nome']}\" já tem produção registrada; o produto não pode ser trocado.");
            }
            if ($qtd < $produzido) {
                // The new quantity is less than what was produced.
                // If it's just stock, we can return the stock. If factory produced it, we error.
                $prodFabrica = $produzido - (int) $atual['quantidade_estoque'];
                if ($qtd < $prodFabrica) {
                    throw new Exception("A quantidade de \"{$atual['produto_nome']}\" não pode ser menor que o já produzido pela fábrica ($prodFabrica).");
                }
                
                // Return difference to stock
                $devolver = $produzido - $qtd;
                if ($devolver > 0) {
                    $pdo->prepare("UPDATE produtos SET estoque = estoque + :dev WHERE id = :id")
                        ->execute(['dev' => $devolver, 'id' => $atual['produto_id']]);
                    $pdo->prepare("UPDATE pedido_itens SET quantidade_estoque = quantidade_estoque - :dev, quantidade_produzida = quantidade_produzida - :dev WHERE id = :id")
                        ->execute(['dev' => $devolver, 'id' => $itemId]);
                    registrarMovimento($pdo, 'pedido_estorno', [
                        'produto_id'     => (int) $atual['produto_id'],
                        'pedido_item_id' => $itemId,
                        'quantidade'     => $devolver,
                        'usuario_id'     => $usuario['id'],
                        'observacoes'    => "Quantidade reduzida no pedido #$id",
                    ]);
                }
            }
            // Trocou de produto: pega o preço do produto novo. Mesmo produto:
            // preserva o preço praticado quando o pedido foi feito.
            if ($produtoId !== (int) $atual['produto_id']) {
                $updComPreco->execute([
                    'produto_id' => $produtoId,
                    'preco_unitario' => precoAtualProduto($pdo, $produtoId),
                    'quantidade' => $qtd,
                    'id' => $itemId,
                ]);
            } else {
                $updStmt->execute(['produto_id' => $produtoId, 'quantidade' => $qtd, 'id' => $itemId]);
            }
            $mantidos[$itemId] = true;
        }

        foreach ($atuais as $itemId => $atual) {
            if (isset($mantidos[$itemId])) continue;
            
            $prodFabrica = (int) $atual['quantidade_produzida'] - (int) $atual['quantidade_estoque'];
            if ($prodFabrica > 0) {
                throw new Exception("O item \"{$atual['produto_nome']}\" já tem produção registrada pela fábrica e não pode ser removido.");
            }
            
            if ((int) $atual['quantidade_estoque'] > 0) {
                $pdo->prepare("UPDATE produtos SET estoque = estoque + :dev WHERE id = :id")
                    ->execute(['dev' => $atual['quantidade_estoque'], 'id' => $atual['produto_id']]);
                registrarMovimento($pdo, 'pedido_estorno', [
                    'produto_id'     => (int) $atual['produto_id'],
                    'pedido_item_id' => $itemId,
                    'quantidade'     => (int) $atual['quantidade_estoque'],
                    'usuario_id'     => $usuario['id'],
                    'observacoes'    => "Item removido do pedido #$id",
                ]);
            }
            $delStmt->execute(['id' => $itemId]);
        }

        $status = recalcularStatusPedido($pdo, $id);

        $pdo->commit();
        jsonResponse(['id' => $id, 'status' => $status]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao editar pedido: ' . $e->getMessage(), 400);
    }
}

// ------------------------------------------------------------
// PATCH /api/pedidos.php?id=123
// Body: { acao: "entregar" | "cancelar" | "reabrir" }
//   entregar -> só pedidos "pronto" (tudo produzido)
//   cancelar -> qualquer pedido que não esteja entregue
//   reabrir  -> pedidos entregues/cancelados voltam ao status de produção
// ------------------------------------------------------------
if ($method === 'PATCH') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('Informe o id do pedido.');
    $acao = readJsonBody()['acao'] ?? '';

    $pdo->beginTransaction();
    try {
        $pedido = buscarPedidoParaAlterar($pdo, $id);
        $atual = $pedido['status'];

        switch ($acao) {
            case 'entregar':
                if ($atual !== 'pronto') {
                    throw new Exception('Só é possível marcar como entregue um pedido com toda a produção concluída (status "pronto").');
                }
                $novo = 'entregue';
                $pdo->prepare("UPDATE pedidos SET status = 'entregue' WHERE id = :id")->execute(['id' => $id]);
                break;
            case 'cancelar':
                if ($atual === 'entregue') throw new Exception('Pedido já entregue não pode ser cancelado. Reabra-o primeiro.');
                if ($atual === 'cancelado') throw new Exception('O pedido já está cancelado.');
                
                // Return stock
                $itensStmt = $pdo->prepare("SELECT id, produto_id, quantidade_estoque FROM pedido_itens WHERE pedido_id = :id AND quantidade_estoque > 0");
                $itensStmt->execute(['id' => $id]);
                $updEstoque = $pdo->prepare("UPDATE produtos SET estoque = estoque + :qtd WHERE id = :id");
                $updItem = $pdo->prepare("UPDATE pedido_itens SET quantidade_estoque = 0, quantidade_produzida = quantidade_produzida - quantidade_estoque WHERE id = :id");
                foreach ($itensStmt->fetchAll() as $it) {
                    $updEstoque->execute(['qtd' => $it['quantidade_estoque'], 'id' => $it['produto_id']]);
                    $updItem->execute(['id' => $it['id']]);
                    registrarMovimento($pdo, 'pedido_estorno', [
                        'produto_id'     => (int) $it['produto_id'],
                        'pedido_item_id' => (int) $it['id'],
                        'quantidade'     => (int) $it['quantidade_estoque'],
                        'usuario_id'     => $usuario['id'],
                        'observacoes'    => "Cancelamento do pedido #$id",
                    ]);
                }
                
                $novo = 'cancelado';
                $pdo->prepare("UPDATE pedidos SET status = 'cancelado' WHERE id = :id")->execute(['id' => $id]);
                break;
            case 'reabrir':
                if (!in_array($atual, STATUS_FINAIS, true)) throw new Exception('Só pedidos entregues ou cancelados podem ser reabertos.');
                $novo = recalcularStatusPedido($pdo, $id, true);
                break;
            default:
                throw new Exception('Ação inválida. Use entregar, cancelar ou reabrir.');
        }

        $pdo->commit();
        jsonResponse(['id' => $id, 'status' => $novo]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError($e->getMessage(), 400);
    }
}

// ------------------------------------------------------------
// DELETE /api/pedidos.php?id=123
// Só exclui pedidos sem nenhuma produção registrada (para não apagar o
// histórico usado nos relatórios). Pedidos com produção devem ser cancelados.
// ------------------------------------------------------------
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('Informe o id do pedido.');

    $pdo->beginTransaction();
    try {
        buscarPedidoParaAlterar($pdo, $id);

        $prodStmt = $pdo->prepare("
            SELECT COUNT(*) FROM producoes pr
            JOIN pedido_itens pi ON pi.id = pr.pedido_item_id
            WHERE pi.pedido_id = :id
        ");
        $prodStmt->execute(['id' => $id]);
        if ((int) $prodStmt->fetchColumn() > 0) {
            throw new Exception('Este pedido já tem produção registrada e não pode ser excluído. Use "Cancelar".');
        }

        // Devolve ao estoque o que o pedido tinha reservado na criação.
        // Sem isso o produto pronto sumia junto com o pedido (o "cancelar"
        // já devolvia; o "excluir" não).
        $itensStmt = $pdo->prepare("SELECT produto_id, quantidade_estoque FROM pedido_itens WHERE pedido_id = :id AND quantidade_estoque > 0");
        $itensStmt->execute(['id' => $id]);
        $devolve = $pdo->prepare("UPDATE produtos SET estoque = estoque + :qtd WHERE id = :id");
        foreach ($itensStmt->fetchAll() as $it) {
            $devolve->execute(['qtd' => $it['quantidade_estoque'], 'id' => $it['produto_id']]);
            registrarMovimento($pdo, 'pedido_estorno', [
                'produto_id'  => (int) $it['produto_id'],
                'quantidade'  => (int) $it['quantidade_estoque'],
                'usuario_id'  => $usuario['id'],
                'observacoes' => "Exclusão do pedido #$id",
            ]);
        }

        // pedido_itens é removido em cascata (ON DELETE CASCADE)
        $pdo->prepare("DELETE FROM pedidos WHERE id = :id")->execute(['id' => $id]);

        $pdo->commit();
        jsonResponse(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao excluir pedido: ' . $e->getMessage(), 400);
    }
}

jsonError('Método não suportado.', 405);
