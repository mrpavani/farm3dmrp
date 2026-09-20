<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ------------------------------------------------------------
// POST — ações da bancada da fábrica.
// Toda alteração de saldo passa por aqui e fica registrada em
// movimentos_estoque (ver includes/estoque.php).
// ------------------------------------------------------------
if ($method === 'POST') {
    $acao = $_GET['acao'] ?? '';

    // 1. Imprimiu peça: soma ao saldo. É o botão [+] da bancada.
    if ($acao === 'registrar_producao_peca') {
        $b = readJsonBody();
        $pecaId = (int) ($b['peca_id'] ?? 0);
        $qtd = (int) ($b['quantidade'] ?? 0);

        if (!$pecaId || $qtd <= 0) {
            jsonError('Informe a peça e a quantidade produzida (mínimo 1).');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, produto_id, nome, cor, estoque FROM produto_pecas WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $pecaId]);
            $peca = $stmt->fetch();
            if (!$peca) throw new Exception('Peça não encontrada.');

            $novoSaldo = (int) $peca['estoque'] + $qtd;
            $pdo->prepare("UPDATE produto_pecas SET estoque = :e WHERE id = :id")
                ->execute(['e' => $novoSaldo, 'id' => $pecaId]);

            registrarMovimento($pdo, 'peca_produzida', [
                'produto_id'   => (int) $peca['produto_id'],
                'peca_id'      => $pecaId,
                'quantidade'   => $qtd,
                'saldo_depois' => $novoSaldo,
                'usuario_id'   => $usuario['id'],
            ]);

            $capacidade = capacidadeMontagem($pdo, (int) $peca['produto_id']);
            $pdo->commit();

            jsonResponse([
                'ok' => true,
                'mensagem' => sprintf('+%d de "%s". Saldo: %d.', $qtd, rotuloPeca($peca), $novoSaldo),
                'novo_estoque' => $novoSaldo,
                'produto_id' => (int) $peca['produto_id'],
                'montavel' => $capacidade['capacidade'],
                'gargalos' => $capacidade['gargalos'],
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError($e->getMessage());
        }
    }

    // 2. Correção manual do saldo de uma peça (contagem física).
    if ($acao === 'ajustar_saldo_peca') {
        $b = readJsonBody();
        $pecaId = (int) ($b['peca_id'] ?? 0);
        if (!$pecaId) jsonError('Informe a peça.');
        if (!isset($b['estoque']) && !isset($b['delta'])) {
            jsonError('Informe o novo saldo (estoque) ou a variação (delta).');
        }

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id, produto_id, nome, cor, estoque FROM produto_pecas WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $pecaId]);
            $peca = $stmt->fetch();
            if (!$peca) throw new Exception('Peça não encontrada.');

            $saldoAntes = (int) $peca['estoque'];
            $novoSaldo = isset($b['delta'])
                ? max(0, $saldoAntes + (int) $b['delta'])
                : max(0, (int) $b['estoque']);

            $pdo->prepare("UPDATE produto_pecas SET estoque = :e WHERE id = :id")
                ->execute(['e' => $novoSaldo, 'id' => $pecaId]);

            registrarMovimento($pdo, 'peca_ajuste', [
                'produto_id'   => (int) $peca['produto_id'],
                'peca_id'      => $pecaId,
                'quantidade'   => $novoSaldo - $saldoAntes,
                'saldo_depois' => $novoSaldo,
                'usuario_id'   => $usuario['id'],
                'observacoes'  => 'Ajuste manual de saldo (de ' . $saldoAntes . ' para ' . $novoSaldo . ').',
            ]);

            $capacidade = capacidadeMontagem($pdo, (int) $peca['produto_id']);
            $pdo->commit();

            jsonResponse([
                'ok' => true,
                'novo_estoque' => $novoSaldo,
                'produto_id' => (int) $peca['produto_id'],
                'montavel' => $capacidade['capacidade'],
                'gargalos' => $capacidade['gargalos'],
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError($e->getMessage());
        }
    }

    // 3. Montar: baixa as peças e gera produto pronto no estoque.
    if ($acao === 'montar') {
        $b = readJsonBody();
        $produtoId = (int) ($b['produto_id'] ?? 0);
        $qtd = (int) ($b['quantidade'] ?? 0);
        if (!$produtoId) jsonError('Informe o produto.');

        $pdo->beginTransaction();
        try {
            $stmtP = $pdo->prepare("SELECT nome FROM produtos WHERE id = :id");
            $stmtP->execute(['id' => $produtoId]);
            $nome = $stmtP->fetchColumn();
            if ($nome === false) throw new Exception('Produto não encontrado.');

            $novoEstoque = montarProduto($pdo, $produtoId, $qtd, $usuario['id']);
            $capacidade = capacidadeMontagem($pdo, $produtoId);
            $pdo->commit();

            jsonResponse([
                'ok' => true,
                'mensagem' => sprintf('%d un. de "%s" montada(s). Estoque pronto: %d.', $qtd, $nome, $novoEstoque),
                'novo_estoque' => $novoEstoque,
                'montavel' => $capacidade['capacidade'],
                'gargalos' => $capacidade['gargalos'],
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError($e->getMessage());
        }
    }

    jsonError('Ação não suportada.', 405);
}

// ------------------------------------------------------------
// GET /api/fabrica_pecas.php
// A bancada inteira: por produto, as peças com saldo e fila de
// impressão, quanto dá para montar agora e o que trava.
// ------------------------------------------------------------
if ($method === 'GET') {
    // 1. Demanda pendente de cada produto, vinda dos pedidos abertos/em produção.
    $sqlPedidos = "
        SELECT
            pi.produto_id,
            SUM(pi.quantidade - pi.quantidade_produzida) AS demanda_bruta,
            MIN(p.data_entrega_prometida) AS proxima_entrega,
            COUNT(DISTINCT pi.pedido_id) AS qtd_pedidos
        FROM pedido_itens pi
        INNER JOIN pedidos p ON p.id = pi.pedido_id
        WHERE p.status IN ('aberto', 'em_producao')
          AND pi.quantidade > pi.quantidade_produzida
        GROUP BY pi.produto_id
    ";
    $demanda = [];
    foreach ($pdo->query($sqlPedidos)->fetchAll() as $row) {
        $demanda[(int) $row['produto_id']] = $row;
    }

    // 2. Todas as peças, com o produto pai.
    $linhas = $pdo->query("
        SELECT
            pp.id AS peca_id, pp.produto_id, pp.nome AS peca_nome,
            pp.quantidade AS por_unidade, pp.cor, pp.estoque AS estoque_pecas, pp.foto AS peca_foto,
            prod.nome AS produto_nome, prod.estoque AS estoque_produto_pronto,
            prod.ativo AS produto_ativo, prod.foto AS produto_foto
        FROM produto_pecas pp
        INNER JOIN produtos prod ON prod.id = pp.produto_id
        ORDER BY prod.nome ASC, pp.id ASC
    ")->fetchAll();

    $produtos = [];
    $totalAImprimir = 0;
    $totalEstoquePecas = 0;
    $pecasComFila = 0;

    foreach ($linhas as $l) {
        $pid = (int) $l['produto_id'];

        if (!isset($produtos[$pid])) {
            $d = $demanda[$pid] ?? null;
            $demandaBruta = $d ? (int) $d['demanda_bruta'] : 0;
            $estoquePronto = (int) $l['estoque_produto_pronto'];

            $produtos[$pid] = [
                'id' => $pid,
                'nome' => $l['produto_nome'],
                'foto' => $l['produto_foto'],
                'ativo' => (int) $l['produto_ativo'],
                'estoque' => $estoquePronto,
                // A fabricar = o que os pedidos ainda esperam.
                'em_producao' => $demandaBruta,
                // Líquida = o que ainda precisa ser montado, já descontado o
                // estoque pronto (que é consumido de verdade ao atender o pedido).
                'demanda_liquida' => max(0, $demandaBruta - $estoquePronto),
                'qtd_pedidos' => $d ? (int) $d['qtd_pedidos'] : 0,
                'proxima_entrega' => $d['proxima_entrega'] ?? null,
                'montavel' => PHP_INT_MAX,
                'gargalos' => [],
                'pecas' => [],
            ];
        }

        $porUnidade = max(1, (int) $l['por_unidade']);
        $estoquePeca = (int) $l['estoque_pecas'];
        $demandaLiquida = $produtos[$pid]['demanda_liquida'];

        $produtos[$pid]['montavel'] = min($produtos[$pid]['montavel'], intdiv(max(0, $estoquePeca), $porUnidade));

        $necessario = $demandaLiquida * $porUnidade;
        $aImprimir = max(0, $necessario - $estoquePeca);

        $totalAImprimir += $aImprimir;
        $totalEstoquePecas += $estoquePeca;
        if ($aImprimir > 0) $pecasComFila++;

        $proxima = $produtos[$pid]['proxima_entrega'];
        $status = 'ok';
        if ($aImprimir > 0) {
            $status = ($proxima && $proxima <= date('Y-m-d')) ? 'urgente' : 'imprimir';
        }

        $cor = trim((string) $l['cor']);

        $produtos[$pid]['pecas'][] = [
            'peca_id' => (int) $l['peca_id'],
            'nome' => $l['peca_nome'],
            'cor' => $cor === '' ? 'Padrão' : $cor,
            'foto' => $l['peca_foto'],
            'por_unidade' => $porUnidade,
            'estoque' => $estoquePeca,
            // Quantos produtos esta peça sozinha permite montar.
            'rende' => intdiv(max(0, $estoquePeca), $porUnidade),
            'necessario_pedidos' => $necessario,
            'a_imprimir' => $aImprimir,
            'sobra_estoque' => max(0, $estoquePeca - $necessario),
            'status' => $status,
        ];
    }

    // 3. Fecha os agregados por produto: quem é o gargalo da montagem.
    $lista = [];
    $totalMontavel = 0;
    foreach ($produtos as $p) {
        $p['montavel'] = $p['montavel'] === PHP_INT_MAX ? 0 : $p['montavel'];
        foreach ($p['pecas'] as $peca) {
            if ($peca['rende'] === $p['montavel']) {
                $p['gargalos'][] = $peca['nome'] . ($peca['cor'] !== 'Padrão' ? " ({$peca['cor']})" : '');
            }
        }
        $p['total_pecas_por_unidade'] = array_sum(array_column($p['pecas'], 'por_unidade'));
        $p['total_a_imprimir'] = array_sum(array_column($p['pecas'], 'a_imprimir'));
        $totalMontavel += $p['montavel'];
        $lista[] = $p;
    }

    // Quem tem fila de impressão primeiro; depois quem dá para montar; depois alfabético.
    usort($lista, function ($a, $b) {
        if (($a['total_a_imprimir'] > 0) !== ($b['total_a_imprimir'] > 0)) {
            return $a['total_a_imprimir'] > 0 ? -1 : 1;
        }
        if (($a['montavel'] > 0) !== ($b['montavel'] > 0)) {
            return $a['montavel'] > 0 ? -1 : 1;
        }
        return strcmp($a['nome'], $b['nome']);
    });

    jsonResponse([
        'resumo' => [
            'total_produtos' => count($lista),
            'pecas_com_fila' => $pecasComFila,
            'total_a_imprimir' => $totalAImprimir,
            'total_estoque' => $totalEstoquePecas,
            'total_montavel' => $totalMontavel,
        ],
        'produtos' => $lista,
    ]);
}

jsonError('Método não suportado.', 405);
