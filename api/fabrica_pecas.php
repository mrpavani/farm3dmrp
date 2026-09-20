<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Busca uma variante de cor com os dados da peça pai (para mensagens e para
// recalcular a capacidade de montagem do produto depois de mexer no saldo).
function buscarCor(PDO $pdo, int $corId): ?array {
    $stmt = $pdo->prepare("
        SELECT pc.id AS cor_id, pc.cor, pc.estoque, pc.peca_id,
               pp.nome AS peca_nome, pp.produto_id
        FROM produto_pecas_cores pc
        JOIN produto_pecas pp ON pp.id = pc.peca_id
        WHERE pc.id = :id FOR UPDATE
    ");
    $stmt->execute(['id' => $corId]);
    $r = $stmt->fetch();
    return $r ?: null;
}

// ------------------------------------------------------------
// POST — ações da bancada da fábrica.
// Toda alteração de saldo passa por aqui e fica registrada em
// movimentos_estoque (ver includes/estoque.php). O saldo vive na
// variante de cor (produto_pecas_cores) — uma peça pode ter várias.
// ------------------------------------------------------------
if ($method === 'POST') {
    $acao = $_GET['acao'] ?? '';

    // 1. Imprimiu peça: soma ao saldo de uma cor. É o botão [+] da bancada.
    if ($acao === 'registrar_producao_peca') {
        $b = readJsonBody();
        $corId = (int) ($b['cor_id'] ?? 0);
        $qtd = (int) ($b['quantidade'] ?? 0);

        if (!$corId || $qtd <= 0) {
            jsonError('Informe a peça (cor) e a quantidade produzida (mínimo 1).');
        }

        $pdo->beginTransaction();
        try {
            $cor = buscarCor($pdo, $corId);
            if (!$cor) throw new Exception('Peça não encontrada.');

            $novoSaldo = (int) $cor['estoque'] + $qtd;
            $pdo->prepare("UPDATE produto_pecas_cores SET estoque = :e WHERE id = :id")
                ->execute(['e' => $novoSaldo, 'id' => $corId]);

            registrarMovimento($pdo, 'peca_produzida', [
                'produto_id'   => (int) $cor['produto_id'],
                'peca_id'      => (int) $cor['peca_id'],
                'quantidade'   => $qtd,
                'saldo_depois' => $novoSaldo,
                'usuario_id'   => $usuario['id'],
                'observacoes'  => 'cor: ' . rotuloCor($cor['cor']),
            ]);

            $capacidade = capacidadeMontagem($pdo, (int) $cor['produto_id']);
            $pdo->commit();

            jsonResponse([
                'ok' => true,
                'mensagem' => sprintf('+%d de "%s". Saldo: %d.', $qtd, rotuloPeca(['nome' => $cor['peca_nome']], $cor['cor']), $novoSaldo),
                'novo_estoque' => $novoSaldo,
                'produto_id' => (int) $cor['produto_id'],
                'montavel' => $capacidade['capacidade'],
                'gargalos' => $capacidade['gargalos'],
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError($e->getMessage());
        }
    }

    // 2. Correção manual do saldo de uma cor (contagem física).
    if ($acao === 'ajustar_saldo_peca') {
        $b = readJsonBody();
        $corId = (int) ($b['cor_id'] ?? 0);
        if (!$corId) jsonError('Informe a peça (cor).');
        if (!isset($b['estoque']) && !isset($b['delta'])) {
            jsonError('Informe o novo saldo (estoque) ou a variação (delta).');
        }

        $pdo->beginTransaction();
        try {
            $cor = buscarCor($pdo, $corId);
            if (!$cor) throw new Exception('Peça não encontrada.');

            $saldoAntes = (int) $cor['estoque'];
            $novoSaldo = isset($b['delta'])
                ? max(0, $saldoAntes + (int) $b['delta'])
                : max(0, (int) $b['estoque']);

            $pdo->prepare("UPDATE produto_pecas_cores SET estoque = :e WHERE id = :id")
                ->execute(['e' => $novoSaldo, 'id' => $corId]);

            registrarMovimento($pdo, 'peca_ajuste', [
                'produto_id'   => (int) $cor['produto_id'],
                'peca_id'      => (int) $cor['peca_id'],
                'quantidade'   => $novoSaldo - $saldoAntes,
                'saldo_depois' => $novoSaldo,
                'usuario_id'   => $usuario['id'],
                'observacoes'  => 'Ajuste manual (cor: ' . rotuloCor($cor['cor']) . '), de ' . $saldoAntes . ' para ' . $novoSaldo . '.',
            ]);

            $capacidade = capacidadeMontagem($pdo, (int) $cor['produto_id']);
            $pdo->commit();

            jsonResponse([
                'ok' => true,
                'novo_estoque' => $novoSaldo,
                'produto_id' => (int) $cor['produto_id'],
                'montavel' => $capacidade['capacidade'],
                'gargalos' => $capacidade['gargalos'],
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError($e->getMessage());
        }
    }

    // 3. Nova cor de uma peça já cadastrada, direto da bancada — ex.: a
    // fábrica passou a imprimir a Chave de Fenda também em Laranja, e só
    // tinha o Cinza cadastrado. Evita ter que ir até Produtos.
    if ($acao === 'adicionar_cor') {
        $b = readJsonBody();
        $pecaId = (int) ($b['peca_id'] ?? 0);
        $corNome = trim((string) ($b['cor'] ?? ''));
        $qtd = max(0, (int) ($b['quantidade'] ?? 0));
        if (!$pecaId) jsonError('Informe a peça.');
        if ($corNome === '') jsonError('Informe o nome da cor.');

        $pdo->beginTransaction();
        try {
            $stmtP = $pdo->prepare("SELECT id, produto_id, nome FROM produto_pecas WHERE id = :id FOR UPDATE");
            $stmtP->execute(['id' => $pecaId]);
            $peca = $stmtP->fetch();
            if (!$peca) throw new Exception('Peça não encontrada.');

            $existe = $pdo->prepare("SELECT id FROM produto_pecas_cores WHERE peca_id = :peca_id AND cor = :cor");
            $existe->execute(['peca_id' => $pecaId, 'cor' => $corNome]);
            if ($existe->fetch()) throw new Exception("A cor \"$corNome\" já está cadastrada para esta peça.");

            $pdo->prepare("INSERT INTO produto_pecas_cores (peca_id, cor, estoque) VALUES (:peca_id, :cor, :estoque)")
                ->execute(['peca_id' => $pecaId, 'cor' => $corNome, 'estoque' => $qtd]);
            $corId = (int) $pdo->lastInsertId();

            if ($qtd > 0) {
                registrarMovimento($pdo, 'peca_produzida', [
                    'produto_id'   => (int) $peca['produto_id'],
                    'peca_id'      => $pecaId,
                    'quantidade'   => $qtd,
                    'saldo_depois' => $qtd,
                    'usuario_id'   => $usuario['id'],
                    'observacoes'  => "Nova cor cadastrada na bancada: $corNome",
                ]);
            }

            $capacidade = capacidadeMontagem($pdo, (int) $peca['produto_id']);
            $pdo->commit();

            jsonResponse([
                'ok' => true,
                'mensagem' => "Cor \"$corNome\" adicionada a \"{$peca['nome']}\".",
                'cor_id' => $corId,
                'cor' => $corNome,
                'estoque' => $qtd,
                'produto_id' => (int) $peca['produto_id'],
                'montavel' => $capacidade['capacidade'],
                'gargalos' => $capacidade['gargalos'],
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError($e->getMessage());
        }
    }

    // 4. Montar: baixa as peças (de qualquer cor disponível) e gera produto pronto.
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
// A bancada inteira: por produto, as peças (cada uma com suas cores e
// saldo) e fila de impressão, quanto dá para montar agora e o que trava.
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

    // 2. Todas as peças com o produto pai, e as cores de cada peça numa
    // segunda consulta (evita repetir a linha da peça por cor no join).
    $pecasLinhas = $pdo->query("
        SELECT
            pp.id AS peca_id, pp.produto_id, pp.nome AS peca_nome,
            pp.quantidade AS por_unidade, pp.foto AS peca_foto,
            prod.nome AS produto_nome, prod.estoque AS estoque_produto_pronto,
            prod.ativo AS produto_ativo, prod.foto AS produto_foto
        FROM produto_pecas pp
        INNER JOIN produtos prod ON prod.id = pp.produto_id
        ORDER BY prod.nome ASC, pp.id ASC
    ")->fetchAll();

    $coresPorPeca = [];
    if ($pecasLinhas) {
        $pecaIds = array_column($pecasLinhas, 'peca_id');
        $in = implode(',', array_fill(0, count($pecaIds), '?'));
        $stmtCores = $pdo->prepare("
            SELECT id AS cor_id, peca_id, cor, estoque, foto
            FROM produto_pecas_cores WHERE peca_id IN ($in) ORDER BY id ASC
        ");
        $stmtCores->execute($pecaIds);
        foreach ($stmtCores->fetchAll() as $c) {
            $coresPorPeca[(int) $c['peca_id']][] = $c;
        }
    }

    $produtos = [];
    $totalAImprimir = 0;
    $totalEstoquePecas = 0;
    $pecasComFila = 0;

    foreach ($pecasLinhas as $l) {
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
        $cores = $coresPorPeca[(int) $l['peca_id']] ?? [];
        $estoquePeca = array_sum(array_map(fn($c) => (int) $c['estoque'], $cores));
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

        $produtos[$pid]['pecas'][] = [
            'peca_id' => (int) $l['peca_id'],
            'nome' => $l['peca_nome'],
            'foto' => $l['peca_foto'],
            'por_unidade' => $porUnidade,
            'estoque' => $estoquePeca,
            // Quantos produtos esta peça (somando todas as cores) permite montar.
            'rende' => intdiv(max(0, $estoquePeca), $porUnidade),
            'necessario_pedidos' => $necessario,
            'a_imprimir' => $aImprimir,
            'sobra_estoque' => max(0, $estoquePeca - $necessario),
            'status' => $status,
            'cores' => array_map(fn($c) => [
                'cor_id' => (int) $c['cor_id'],
                'cor' => trim((string) $c['cor']) === '' ? null : $c['cor'],
                'foto' => $c['foto'],
                'estoque' => (int) $c['estoque'],
            ], $cores),
        ];
    }

    // 3. Fecha os agregados por produto: quem é o gargalo da montagem.
    $lista = [];
    $totalMontavel = 0;
    foreach ($produtos as $p) {
        $p['montavel'] = $p['montavel'] === PHP_INT_MAX ? 0 : $p['montavel'];
        foreach ($p['pecas'] as $peca) {
            if ($peca['rende'] === $p['montavel']) {
                $p['gargalos'][] = $peca['nome'];
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
