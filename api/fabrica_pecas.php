<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Busca uma peça com seus dados e cores associadas
function buscarPecaCompleta(PDO $pdo, int $pecaId): ?array {
    $stmt = $pdo->prepare("
        SELECT pp.id AS peca_id, pp.produto_id, pp.nome AS peca_nome, pp.quantidade AS peca_qtd,
               pp.estoque AS peca_estoque, pp.peso_gramas AS peca_peso,
               pp.tempo_producao_segundos AS peca_tempo, pp.foto AS peca_foto,
               prod.nome AS produto_nome
        FROM produto_pecas pp
        JOIN produtos prod ON prod.id = pp.produto_id
        WHERE pp.id = :id FOR UPDATE
    ");
    $stmt->execute(['id' => $pecaId]);
    $p = $stmt->fetch();
    if (!$p) return null;

    $stmtCores = $pdo->prepare("
        SELECT id AS cor_id, peca_id, cor, estoque, foto, peso_gramas, tempo_producao_segundos
        FROM produto_pecas_cores
        WHERE peca_id = :peca_id ORDER BY id ASC
    ");
    $stmtCores->execute(['peca_id' => $pecaId]);
    $p['cores'] = $stmtCores->fetchAll();
    return $p;
}

// Busca uma variante de cor com os dados da peça pai (fallback de compatibilidade)
function buscarCor(PDO $pdo, int $corId): ?array {
    $stmt = $pdo->prepare("
        SELECT pc.id AS cor_id, pc.cor, pc.estoque, pc.peso_gramas AS cor_peso, pc.peca_id,
               pp.nome AS peca_nome, pp.produto_id, pp.peso_gramas AS peca_peso, pp.quantidade AS peca_qtd,
               pp.estoque AS peca_estoque
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
// O saldo de estoque pertence à PEÇA (produto_pecas.estoque).
// Se a peça tiver mais de uma cor, consome filamento de todas as cores proporcionalmente.
// ------------------------------------------------------------
if ($method === 'POST') {
    $acao = $_GET['acao'] ?? '';

    // 1. Imprimiu peça: soma ao saldo da peça e baixa filamento de cada cor.
    if ($acao === 'registrar_producao_peca') {
        $b = readJsonBody();
        $pecaId = (int) ($b['peca_id'] ?? 0);
        $corId = (int) ($b['cor_id'] ?? 0);
        $qtd = (int) ($b['quantidade'] ?? 0);

        if ((!$pecaId && !$corId) || $qtd <= 0) {
            jsonError('Informe a peça e a quantidade produzida (mínimo 1).');
        }

        $pdo->beginTransaction();
        try {
            if (!$pecaId && $corId > 0) {
                $c = buscarCor($pdo, $corId);
                if ($c) $pecaId = (int) $c['peca_id'];
            }

            $peca = buscarPecaCompleta($pdo, $pecaId);
            if (!$peca) throw new Exception('Peça não encontrada.');

            $novoSaldo = (int) $peca['peca_estoque'] + $qtd;
            $pdo->prepare("UPDATE produto_pecas SET estoque = :e WHERE id = :id")
                ->execute(['e' => $novoSaldo, 'id' => $pecaId]);

            // Se ainda existirem registros em produto_pecas_cores, atualiza também para manter compatibilidade
            $pdo->prepare("UPDATE produto_pecas_cores SET estoque = :e WHERE peca_id = :id")
                ->execute(['e' => $novoSaldo, 'id' => $pecaId]);

            registrarMovimento($pdo, 'peca_produzida', [
                'produto_id'   => (int) $peca['produto_id'],
                'peca_id'      => $pecaId,
                'quantidade'   => $qtd,
                'saldo_depois' => $novoSaldo,
                'usuario_id'   => $usuario['id'],
                'observacoes'  => "Impressão de {$qtd} un. da peça {$peca['peca_nome']}",
            ]);

            // Baixa automática de filamento: se a peça é multicor, baixa de CADA cor!
            $coresComPeso = array_filter($peca['cores'] ?? [], fn($c) => $c['peso_gramas'] !== null && (float)$c['peso_gramas'] > 0);
            $totalBaixado = 0.0;
            $detalhesCoresBaixa = [];

            if ($coresComPeso && count($coresComPeso) > 0) {
                foreach ($coresComPeso as $c) {
                    $pesoCor = (float) $c['peso_gramas'];
                    $gastoCor = round($pesoCor * $qtd, 2);
                    if ($gastoCor > 0) {
                        darBaixaFilamentoConsumo($pdo, (string)$c['cor'], $gastoCor, [
                            'peca_id' => $pecaId,
                            'produto_id' => (int) $peca['produto_id'],
                            'usuario_id' => $usuario['id'],
                            'observacoes' => "Impressão de {$qtd} un. da peça {$peca['peca_nome']} (Cor: " . rotuloCor($c['cor']) . ")",
                        ]);
                        $totalBaixado += $gastoCor;
                        $detalhesCoresBaixa[] = sprintf('%.1fg (%s)', $gastoCor, rotuloCor($c['cor']));
                    }
                }
            } elseif ($peca['peca_peso'] !== null && (float)$peca['peca_peso'] > 0) {
                // Peça sem especificação por cor mas com peso total na peça
                $porUn = max(1, (int)$peca['peca_qtd']);
                $pesoUn = (float)$peca['peca_peso'] / $porUn;
                $gastoTotal = round($pesoUn * $qtd, 2);
                if ($gastoTotal > 0) {
                    $corPadrao = (!empty($peca['cores']) && !empty($peca['cores'][0]['cor'])) ? (string)$peca['cores'][0]['cor'] : 'Padrão / Única';
                    darBaixaFilamentoConsumo($pdo, $corPadrao, $gastoTotal, [
                        'peca_id' => $pecaId,
                        'produto_id' => (int) $peca['produto_id'],
                        'usuario_id' => $usuario['id'],
                        'observacoes' => "Impressão de {$qtd} un. da peça {$peca['peca_nome']} ($corPadrao)",
                    ]);
                    $totalBaixado += $gastoTotal;
                    $detalhesCoresBaixa[] = sprintf('%.1fg (%s)', $gastoTotal, $corPadrao);
                }
            }

            $capacidade = capacidadeMontagem($pdo, (int) $peca['produto_id']);
            $pdo->commit();

            $msg = sprintf('+%d de "%s". Saldo: %d.', $qtd, $peca['peca_nome'], $novoSaldo);
            if ($detalhesCoresBaixa) {
                $msg .= ' Filamento baixado: ' . implode(', ', $detalhesCoresBaixa) . '.';
            }

            jsonResponse([
                'ok' => true,
                'mensagem' => $msg,
                'peca_id' => $pecaId,
                'novo_estoque' => $novoSaldo,
                'produto_id' => (int) $peca['produto_id'],
                'montavel' => $capacidade['capacidade'],
                'gargalos' => $capacidade['gargalos'],
                'filamento_baixado_gramas' => round($totalBaixado, 2),
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
        $corId = (int) ($b['cor_id'] ?? 0);

        if (!$pecaId && $corId > 0) {
            $c = buscarCor($pdo, $corId);
            if ($c) $pecaId = (int) $c['peca_id'];
        }

        if (!$pecaId) jsonError('Informe a peça.');
        if (!isset($b['estoque']) && !isset($b['delta'])) {
            jsonError('Informe o novo saldo (estoque) ou a variação (delta).');
        }

        $pdo->beginTransaction();
        try {
            $peca = buscarPecaCompleta($pdo, $pecaId);
            if (!$peca) throw new Exception('Peça não encontrada.');

            $saldoAntes = (int) $peca['peca_estoque'];
            $novoSaldo = isset($b['delta'])
                ? max(0, $saldoAntes + (int) $b['delta'])
                : max(0, (int) $b['estoque']);

            $pdo->prepare("UPDATE produto_pecas SET estoque = :e WHERE id = :id")
                ->execute(['e' => $novoSaldo, 'id' => $pecaId]);

            $pdo->prepare("UPDATE produto_pecas_cores SET estoque = :e WHERE peca_id = :id")
                ->execute(['e' => $novoSaldo, 'id' => $pecaId]);

            $motivo = trim($b['motivo'] ?? '');
            $obs = $motivo !== ''
                ? "Inventário da peça \"{$peca['peca_nome']}\" ({$saldoAntes} → {$novoSaldo}): {$motivo}"
                : "Ajuste manual de saldo da peça {$peca['peca_nome']}, de {$saldoAntes} para {$novoSaldo}.";

            registrarMovimento($pdo, 'peca_ajuste', [
                'produto_id'   => (int) $peca['produto_id'],
                'peca_id'      => $pecaId,
                'quantidade'   => $novoSaldo - $saldoAntes,
                'saldo_depois' => $novoSaldo,
                'usuario_id'   => $usuario['id'],
                'observacoes'  => $obs,
            ]);

            $capacidade = capacidadeMontagem($pdo, (int) $peca['produto_id']);
            $pdo->commit();

            jsonResponse([
                'ok' => true,
                'mensagem' => "Ajuste de inventário da peça \"{$peca['peca_nome']}\" gravado com sucesso! Novo saldo: {$novoSaldo} un.",
                'peca_id' => $pecaId,
                'saldo_anterior' => $saldoAntes,
                'novo_estoque' => $novoSaldo,
                'delta' => $novoSaldo - $saldoAntes,
                'produto_id' => (int) $peca['produto_id'],
                'montavel' => $capacidade['capacidade'],
                'gargalos' => $capacidade['gargalos'],
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError($e->getMessage());
        }
    }

    // 3. Nova cor / insumo de cor para uma peça já cadastrada
    if ($acao === 'adicionar_cor') {
        $b = readJsonBody();
        $pecaId = (int) ($b['peca_id'] ?? 0);
        $corNome = trim((string) ($b['cor'] ?? ''));
        $pesoGramas = isset($b['peso_gramas']) && $b['peso_gramas'] !== '' ? max(0.0, (float)$b['peso_gramas']) : null;
        $tempoSegs = isset($b['tempo_producao_segundos']) && $b['tempo_producao_segundos'] !== '' ? max(0, (int)$b['tempo_producao_segundos']) : null;

        if (!$pecaId) jsonError('Informe a peça.');
        if ($corNome === '') jsonError('Informe o nome da cor.');

        $pdo->beginTransaction();
        try {
            $stmtP = $pdo->prepare("SELECT id, produto_id, nome, estoque FROM produto_pecas WHERE id = :id FOR UPDATE");
            $stmtP->execute(['id' => $pecaId]);
            $peca = $stmtP->fetch();
            if (!$peca) throw new Exception('Peça não encontrada.');

            $existe = $pdo->prepare("SELECT id FROM produto_pecas_cores WHERE peca_id = :peca_id AND cor = :cor");
            $existe->execute(['peca_id' => $pecaId, 'cor' => $corNome]);
            if ($existe->fetch()) throw new Exception("A cor \"$corNome\" já está cadastrada para esta peça.");

            $pdo->prepare("
                INSERT INTO produto_pecas_cores (peca_id, cor, estoque, peso_gramas, tempo_producao_segundos)
                VALUES (:peca_id, :cor, :estoque, :peso_gramas, :tempo_producao_segundos)
            ")->execute([
                'peca_id' => $pecaId,
                'cor' => $corNome,
                'estoque' => (int) $peca['estoque'],
                'peso_gramas' => $pesoGramas,
                'tempo_producao_segundos' => $tempoSegs,
            ]);
            $corId = (int) $pdo->lastInsertId();

            // Atualiza peso_gramas da peça somando as cores
            $stmtSoma = $pdo->prepare("SELECT SUM(peso_gramas) FROM produto_pecas_cores WHERE peca_id = :id AND peso_gramas > 0");
            $stmtSoma->execute(['id' => $pecaId]);
            $somaPeso = (float) $stmtSoma->fetchColumn();
            if ($somaPeso > 0) {
                $pdo->prepare("UPDATE produto_pecas SET peso_gramas = :p WHERE id = :id")->execute(['p' => $somaPeso, 'id' => $pecaId]);
            }

            $capacidade = capacidadeMontagem($pdo, (int) $peca['produto_id']);
            $pdo->commit();

            jsonResponse([
                'ok' => true,
                'mensagem' => "Cor \"$corNome\" adicionada à peça \"{$peca['nome']}\".",
                'cor_id' => $corId,
                'peca_id' => $pecaId,
                'cor' => $corNome,
                'peso_gramas' => $pesoGramas,
                'estoque' => (int) $peca['estoque'],
                'produto_id' => (int) $peca['produto_id'],
                'montavel' => $capacidade['capacidade'],
                'gargalos' => $capacidade['gargalos'],
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError($e->getMessage());
        }
    }

    // 4. Remover cor de uma peça
    if ($acao === 'remover_cor') {
        $b = readJsonBody();
        $corId = (int) ($b['cor_id'] ?? 0);
        $pecaId = (int) ($b['peca_id'] ?? 0);

        if (!$corId) jsonError('Informe a cor a remover.');

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM produto_pecas_cores WHERE id = :id")->execute(['id' => $corId]);

            if ($pecaId > 0) {
                $stmtSoma = $pdo->prepare("SELECT SUM(peso_gramas) FROM produto_pecas_cores WHERE peca_id = :id AND peso_gramas > 0");
                $stmtSoma->execute(['id' => $pecaId]);
                $somaPeso = (float) $stmtSoma->fetchColumn();
                if ($somaPeso > 0) {
                    $pdo->prepare("UPDATE produto_pecas SET peso_gramas = :p WHERE id = :id")->execute(['p' => $somaPeso, 'id' => $pecaId]);
                }
            }

            $pdo->commit();
            jsonResponse(['ok' => true, 'mensagem' => 'Cor removida com sucesso.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError($e->getMessage());
        }
    }

    // 5. Montar: baixa as peças direto de produto_pecas e gera produto pronto.
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

    // 6. Registrar produção de produto simples (ou impressão direta de produto)
    if ($acao === 'registrar_producao_simples') {
        $b = readJsonBody();
        $produtoId = (int) ($b['produto_id'] ?? 0);
        $qtd = max(1, (int) ($b['quantidade'] ?? 1));

        if (!$produtoId) jsonError('Informe o produto.');

        $pdo->beginTransaction();
        try {
            $stmtP = $pdo->prepare("SELECT id, nome, tipo, estoque, peso_gramas, tempo_producao_segundos FROM produtos WHERE id = :id FOR UPDATE");
            $stmtP->execute(['id' => $produtoId]);
            $prod = $stmtP->fetch();
            if (!$prod) throw new Exception('Produto não encontrado.');

            // Busca pedidos em aberto demandando esse produto
            $stmtItens = $pdo->prepare("
                SELECT pi.id, pi.pedido_id, pi.quantidade, pi.quantidade_produzida, pi.cor_variacao, pi.variacoes_json, p.tipo AS pedido_tipo
                FROM pedido_itens pi
                JOIN pedidos p ON p.id = pi.pedido_id
                WHERE pi.produto_id = :pid AND p.status IN ('aberto', 'em_producao') AND pi.quantidade > pi.quantidade_produzida
                ORDER BY p.data_entrega_prometida ASC, p.id ASC
                FOR UPDATE
            ");
            $stmtItens->execute(['pid' => $produtoId]);
            $itensPendentes = $stmtItens->fetchAll();

            $qtdRestante = $qtd;
            $pedidosAfetados = [];

            foreach ($itensPendentes as $item) {
                if ($qtdRestante <= 0) break;
                $falta = (int)$item['quantidade'] - (int)$item['quantidade_produzida'];
                $aplicar = min($falta, $qtdRestante);

                $contextoItem = [
                    'cor_variacao' => $item['cor_variacao'] ?? null,
                    'variacoes_json' => $item['variacoes_json'] ?? null,
                    'pedido_item_id' => (int) $item['id'],
                ];

                // Produz e consome filamento
                produzirProdutoPronto($pdo, $produtoId, $aplicar, $usuario['id'], "Produção via Fila de Impressão 3D (Pedido #{$item['pedido_id']})", $contextoItem);

                // Registra em producoes
                $pdo->prepare("
                    INSERT INTO producoes (pedido_item_id, data_producao, quantidade, usuario_id, observacoes)
                    VALUES (:pedido_item_id, :data_producao, :quantidade, :usuario_id, :observacoes)
                ")->execute([
                    'pedido_item_id' => $item['id'],
                    'data_producao' => date('Y-m-d'),
                    'quantidade' => $aplicar,
                    'usuario_id' => $usuario['id'],
                    'observacoes' => "Fila de impressão rápida da fábrica",
                ]);

                // Atualiza item do pedido
                $pdo->prepare("UPDATE pedido_itens SET quantidade_produzida = quantidade_produzida + :q WHERE id = :id")
                    ->execute(['q' => $aplicar, 'id' => $item['id']]);

                recalcularStatusPedido($pdo, (int)$item['pedido_id']);
                $pedidosAfetados[] = $item['pedido_id'];
                $qtdRestante -= $aplicar;
            }

            // Se produziu mais do que a demanda aberta, incrementa o estoque pronto do produto
            if ($qtdRestante > 0) {
                produzirProdutoPronto($pdo, $produtoId, $qtdRestante, $usuario['id'], "Produção avulsa / para estoque via Fila de Impressão");
            }

            $stmtNovoEstoque = $pdo->prepare("SELECT estoque FROM produtos WHERE id = :id");
            $stmtNovoEstoque->execute(['id' => $produtoId]);
            $novoEstoque = (int)$stmtNovoEstoque->fetchColumn();

            $pdo->commit();

            jsonResponse([
                'ok' => true,
                'mensagem' => sprintf('%d un. de "%s" produzida(s) com sucesso!', $qtd, $prod['nome']),
                'produto_id' => $produtoId,
                'novo_estoque' => $novoEstoque,
                'pedidos_afetados' => array_unique($pedidosAfetados),
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
// A bancada inteira: por produto, as peças (com saldo da peça e cores/gramas)
// e a Fila de Impressão 3D consolidada (o que a fábrica deve imprimir).
// ------------------------------------------------------------
if ($method === 'GET') {
    // Pedidos detalhados abertos
    $stmtItensAbertos = $pdo->query("
        SELECT
            pi.produto_id,
            pi.pedido_id,
            p.data_entrega_prometida,
            p.tipo AS pedido_tipo,
            c.nome AS cliente_nome,
            (pi.quantidade - pi.quantidade_produzida) AS item_restante,
            pi.cor_variacao
        FROM pedido_itens pi
        INNER JOIN pedidos p ON p.id = pi.pedido_id
        LEFT JOIN clientes c ON c.id = p.cliente_id
        WHERE p.status IN ('aberto', 'em_producao')
          AND pi.quantidade > pi.quantidade_produzida
        ORDER BY p.data_entrega_prometida ASC, pi.id ASC
    ");
    $itensAbertos = $stmtItensAbertos->fetchAll();

    $demanda = [];
    $pedidosPorProduto = [];
    foreach ($itensAbertos as $row) {
        $pid = (int) $row['produto_id'];
        if (!isset($demanda[$pid])) {
            $demanda[$pid] = [
                'demanda_bruta' => 0,
                'proxima_entrega' => $row['data_entrega_prometida'],
                'pedidos_set' => [],
            ];
        }
        $demanda[$pid]['demanda_bruta'] += (int) $row['item_restante'];
        $demanda[$pid]['pedidos_set'][$row['pedido_id']] = true;
        if ($row['data_entrega_prometida'] && (!$demanda[$pid]['proxima_entrega'] || $row['data_entrega_prometida'] < $demanda[$pid]['proxima_entrega'])) {
            $demanda[$pid]['proxima_entrega'] = $row['data_entrega_prometida'];
        }

        $pedidosPorProduto[$pid][] = [
            'pedido_id' => (int) $row['pedido_id'],
            'cliente' => $row['pedido_tipo'] === 'estoque' ? 'Estoque' : ($row['cliente_nome'] ?: 'Cliente'),
            'entrega' => $row['data_entrega_prometida'],
            'quantidade' => (int) $row['item_restante'],
            'cor' => $row['cor_variacao'] ?: null,
        ];
    }
    foreach ($demanda as $pid => &$d) {
        $d['qtd_pedidos'] = count($d['pedidos_set']);
        unset($d['pedidos_set']);
    }
    unset($d);

    // Peças cadastradas dos produtos compostos
    $pecasLinhas = $pdo->query("
        SELECT
            pp.id AS peca_id, pp.produto_id, pp.nome AS peca_nome,
            pp.quantidade AS por_unidade, pp.estoque AS peca_estoque, pp.foto AS peca_foto,
            pp.peso_gramas AS peca_peso, pp.tempo_producao_segundos AS peca_tempo,
            prod.nome AS produto_nome, prod.tipo AS produto_tipo, prod.estoque AS estoque_produto_pronto,
            prod.ativo AS produto_ativo, prod.foto AS produto_foto,
            prod.peso_gramas AS prod_peso_total, prod.tempo_producao_segundos AS prod_tempo_total
        FROM produto_pecas pp
        INNER JOIN produtos prod ON prod.id = pp.produto_id
        ORDER BY prod.nome ASC, pp.id ASC
    ")->fetchAll();

    $coresPorPeca = [];
    if ($pecasLinhas) {
        $pecaIds = array_column($pecasLinhas, 'peca_id');
        $in = implode(',', array_fill(0, count($pecaIds), '?'));
        $stmtCores = $pdo->prepare("
            SELECT id AS cor_id, peca_id, cor, estoque, foto, peso_gramas, tempo_producao_segundos
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
    $filaImpressao = [];

    // 1. Processa produtos compostos (que possuem peças em produto_pecas)
    foreach ($pecasLinhas as $l) {
        $pid = (int) $l['produto_id'];

        if (!isset($produtos[$pid])) {
            $d = $demanda[$pid] ?? null;
            $demandaBruta = $d ? (int) $d['demanda_bruta'] : 0;
            $estoquePronto = (int) $l['estoque_produto_pronto'];

            $produtos[$pid] = [
                'id' => $pid,
                'nome' => $l['produto_nome'],
                'tipo' => $l['produto_tipo'] ?: 'composto',
                'foto' => $l['produto_foto'],
                'ativo' => (int) $l['produto_ativo'],
                'estoque' => $estoquePronto,
                'em_producao' => $demandaBruta,
                'demanda_liquida' => max(0, $demandaBruta - $estoquePronto),
                'qtd_pedidos' => $d ? (int) $d['qtd_pedidos'] : 0,
                'proxima_entrega' => $d['proxima_entrega'] ?? null,
                'pedidos_detalhe' => $pedidosPorProduto[$pid] ?? [],
                'montavel' => PHP_INT_MAX,
                'gargalos' => [],
                'pecas' => [],
                'is_simples' => false,
            ];
        }

        $porUnidade = max(1, (int) $l['por_unidade']);
        $cores = $coresPorPeca[(int) $l['peca_id']] ?? [];
        $estoquePeca = (int) ($l['peca_estoque'] ?? 0);
        $demandaLiquida = $produtos[$pid]['demanda_liquida'];

        $produtos[$pid]['montavel'] = min($produtos[$pid]['montavel'], intdiv(max(0, $estoquePeca), $porUnidade));

        $necessario = $demandaLiquida * $porUnidade;
        $aImprimir = max(0, $necessario - $estoquePeca);

        $totalAImprimir += $aImprimir;
        $totalEstoquePecas += $estoquePeca;
        if ($aImprimir > 0) $pecasComFila++;

        $proxima = $produtos[$pid]['proxima_entrega'];
        $status = 'ok';
        $urgencia = 'normal';
        if ($aImprimir > 0) {
            $hoje = date('Y-m-d');
            $amanha = date('Y-m-d', strtotime('+1 day'));
            if ($proxima && $proxima < $hoje) {
                $status = 'urgente';
                $urgencia = 'atrasado';
            } elseif ($proxima && $proxima <= $amanha) {
                $status = 'urgente';
                $urgencia = 'hoje';
            } elseif ($proxima) {
                $status = 'imprimir';
                $urgencia = 'proximo';
            } else {
                $status = 'imprimir';
                $urgencia = 'estoque';
            }
        }

        $tempoPeca1un = (int) ($l['peca_tempo'] ?? 0);
        $pesoPeca1un = (float) ($l['peca_peso'] ?? 0);
        $tempoUnitario = $porUnidade > 0 ? ($tempoPeca1un / $porUnidade) : 0;
        $pesoUnitario = $porUnidade > 0 ? ($pesoPeca1un / $porUnidade) : 0;
        $tempoFilaSegundos = (int) round($tempoUnitario * $aImprimir);
        $pesoFilaGramas = round($pesoUnitario * $aImprimir, 2);

        $coresFormatadas = array_map(fn($c) => [
            'cor_id' => (int) $c['cor_id'],
            'cor' => trim((string) $c['cor']) === '' ? null : $c['cor'],
            'foto' => $c['foto'],
            'estoque' => $estoquePeca,
            'peso_gramas' => $c['peso_gramas'] !== null ? (float) $c['peso_gramas'] : null,
            'tempo_producao_segundos' => $c['tempo_producao_segundos'] !== null ? (int) $c['tempo_producao_segundos'] : null,
            'tempo_formatado' => $c['tempo_producao_segundos'] !== null ? formatarTempoHHMMSS((int)$c['tempo_producao_segundos']) : null,
        ], $cores);

        $pecaInfo = [
            'peca_id' => (int) $l['peca_id'],
            'nome' => $l['peca_nome'],
            'foto' => $l['peca_foto'],
            'por_unidade' => $porUnidade,
            'tempo_producao_segundos' => $tempoPeca1un,
            'tempo_formatado' => formatarTempoHHMMSS($tempoPeca1un),
            'tempo_fila_segundos' => $tempoFilaSegundos,
            'tempo_fila_formatado' => formatarTempoHHMMSS($tempoFilaSegundos),
            'peso_gramas' => $pesoPeca1un,
            'peso_fila_gramas' => $pesoFilaGramas,
            'estoque' => $estoquePeca,
            'rende' => intdiv(max(0, $estoquePeca), $porUnidade),
            'necessario_pedidos' => $necessario,
            'a_imprimir' => $aImprimir,
            'sobra_estoque' => max(0, $estoquePeca - $necessario),
            'status' => $status,
            'urgencia' => $urgencia,
            'cores' => $coresFormatadas,
        ];

        $produtos[$pid]['pecas'][] = $pecaInfo;

        // Se falta imprimir essa peça, adiciona à Fila Prática de Impressão
        if ($aImprimir > 0) {
            $corPrincipal = !empty($coresFormatadas) ? ($coresFormatadas[0]['cor'] ?? null) : null;
            $filaImpressao[] = [
                'id' => 'peca_' . $l['peca_id'],
                'tipo' => 'peca',
                'peca_id' => (int) $l['peca_id'],
                'produto_id' => $pid,
                'produto_nome' => $l['produto_nome'],
                'item_nome' => $l['peca_nome'],
                'peca_nome' => $l['peca_nome'],
                'foto' => $l['peca_foto'] ?: $l['produto_foto'],
                'a_imprimir' => $aImprimir,
                'quantidade_a_imprimir' => $aImprimir,
                'estoque' => $estoquePeca,
                'estoque_atual' => $estoquePeca,
                'por_unidade' => $porUnidade,
                'unidades_por_produto' => $porUnidade,
                'necessario' => $necessario,
                'necessario_pedidos' => $necessario,
                'tempo_1un_segundos' => $tempoPeca1un,
                'tempo_1un_formatado' => formatarTempoHHMMSS($tempoPeca1un),
                'tempo_unitario_formatado' => formatarTempoHHMMSS($tempoPeca1un),
                'tempo_total_segundos' => $tempoFilaSegundos,
                'tempo_total_formatado' => formatarTempoHHMMSS($tempoFilaSegundos),
                'peso_1un_gramas' => $pesoPeca1un,
                'peso_unitario_gramas' => $pesoPeca1un,
                'peso_total_gramas' => $pesoFilaGramas,
                'cor' => $corPrincipal,
                'cores' => $coresFormatadas,
                'proxima_entrega' => $produtos[$pid]['proxima_entrega'],
                'urgencia' => $urgencia,
                'urgencia_tag' => $urgencia,
                'pedidos' => $pedidosPorProduto[$pid] ?? [],
            ];
        }
    }

    // 2. Processa produtos simples (sem peças cadastradas) que possuem demanda aberta ou pedidos
    $idsCompostos = array_keys($produtos);
    $clausulaNot = $idsCompostos ? ('AND id NOT IN (' . implode(',', $idsCompostos) . ')') : '';
    $stmtSimples = $pdo->query("
        SELECT id, nome, tipo, foto, ativo, estoque, peso_gramas, tempo_producao_segundos
        FROM produtos
        WHERE (ativo = 1 OR id IN (SELECT DISTINCT produto_id FROM pedido_itens))
        $clausulaNot
        ORDER BY nome ASC
    ");
    $produtosSimples = $stmtSimples->fetchAll();

    // Carrega cores de produtos simples (produto_cores)
    $stmtCoresSimples = $pdo->query("SELECT id, produto_id, cor, peso_gramas, tempo_producao_segundos FROM produto_cores ORDER BY id ASC");
    $coresPorProdSimples = [];
    foreach ($stmtCoresSimples->fetchAll() as $cs) {
        $coresPorProdSimples[(int)$cs['produto_id']][] = [
            'cor_id' => (int)$cs['id'],
            'cor' => $cs['cor'],
            'peso_gramas' => (float)$cs['peso_gramas'],
            'tempo_producao_segundos' => (int)$cs['tempo_producao_segundos'],
            'tempo_formatado' => formatarTempoHHMMSS((int)$cs['tempo_producao_segundos']),
        ];
    }

    foreach ($produtosSimples as $ps) {
        $pid = (int) $ps['id'];
        $d = $demanda[$pid] ?? null;
        $demandaBruta = $d ? (int) $d['demanda_bruta'] : 0;
        $estoquePronto = (int) $ps['estoque'];
        $demandaLiquida = max(0, $demandaBruta - $estoquePronto);

        // Se não tem demanda e estoque é 0 e não está ativo, ignora
        if ($demandaBruta <= 0 && $estoquePronto <= 0 && !(int)$ps['ativo']) continue;

        $proxima = $d['proxima_entrega'] ?? null;
        $urgencia = 'normal';
        if ($demandaLiquida > 0) {
            $hoje = date('Y-m-d');
            $amanha = date('Y-m-d', strtotime('+1 day'));
            if ($proxima && $proxima < $hoje) $urgencia = 'atrasado';
            elseif ($proxima && $proxima <= $amanha) $urgencia = 'hoje';
            elseif ($proxima) $urgencia = 'proximo';
            else $urgencia = 'estoque';
        }

        $tempo1un = (int) ($ps['tempo_producao_segundos'] ?? 0);
        $peso1un = (float) ($ps['peso_gramas'] ?? 0);
        $tempoFila = $tempo1un * $demandaLiquida;
        $pesoFila = round($peso1un * $demandaLiquida, 2);

        $coresProd = $coresPorProdSimples[$pid] ?? [];

        $produtos[$pid] = [
            'id' => $pid,
            'nome' => $ps['nome'],
            'tipo' => $ps['tipo'] ?: 'simples',
            'foto' => $ps['foto'],
            'ativo' => (int) $ps['ativo'],
            'estoque' => $estoquePronto,
            'em_producao' => $demandaBruta,
            'demanda_liquida' => $demandaLiquida,
            'qtd_pedidos' => $d ? (int) $d['qtd_pedidos'] : 0,
            'proxima_entrega' => $proxima,
            'pedidos_detalhe' => $pedidosPorProduto[$pid] ?? [],
            'montavel' => $estoquePronto,
            'gargalos' => $demandaLiquida > 0 ? [$ps['nome']] : [],
            'pecas' => [],
            'is_simples' => true,
            'total_pecas_por_unidade' => 1,
            'total_a_imprimir' => $demandaLiquida,
            'tempo_fila_segundos' => $tempoFila,
            'tempo_fila_formatado' => formatarTempoHHMMSS($tempoFila),
            'peso_fila_gramas' => $pesoFila,
            'tempo_un_formatado' => formatarTempoHHMMSS($tempo1un),
            'peso_un_gramas' => $peso1un,
            'cores' => $coresProd,
        ];

        if ($demandaLiquida > 0) {
            $totalAImprimir += $demandaLiquida;
            $pecasComFila++;

            $corPrincipal = !empty($coresProd) ? ($coresProd[0]['cor'] ?? null) : null;
            $filaImpressao[] = [
                'id' => 'prod_' . $pid,
                'tipo' => 'simples',
                'peca_id' => 0,
                'produto_id' => $pid,
                'produto_nome' => $ps['nome'],
                'item_nome' => $ps['nome'],
                'peca_nome' => $ps['nome'],
                'foto' => $ps['foto'],
                'a_imprimir' => $demandaLiquida,
                'quantidade_a_imprimir' => $demandaLiquida,
                'estoque' => $estoquePronto,
                'estoque_atual' => $estoquePronto,
                'por_unidade' => 1,
                'unidades_por_produto' => 1,
                'necessario' => $demandaBruta,
                'necessario_pedidos' => $demandaBruta,
                'tempo_1un_segundos' => $tempo1un,
                'tempo_1un_formatado' => formatarTempoHHMMSS($tempo1un),
                'tempo_unitario_formatado' => formatarTempoHHMMSS($tempo1un),
                'tempo_total_segundos' => $tempoFila,
                'tempo_total_formatado' => formatarTempoHHMMSS($tempoFila),
                'peso_1un_gramas' => $peso1un,
                'peso_unitario_gramas' => $peso1un,
                'peso_total_gramas' => $pesoFila,
                'cor' => $corPrincipal,
                'cores' => $coresProd,
                'proxima_entrega' => $proxima,
                'urgencia' => $urgencia,
                'urgencia_tag' => $urgencia,
                'pedidos' => $pedidosPorProduto[$pid] ?? [],
            ];
        }
    }

    $lista = [];
    $totalMontavel = 0;
    foreach ($produtos as $p) {
        if (!$p['is_simples']) {
            $p['montavel'] = $p['montavel'] === PHP_INT_MAX ? 0 : $p['montavel'];
            foreach ($p['pecas'] as $peca) {
                if ($peca['rende'] === $p['montavel']) {
                    $p['gargalos'][] = $peca['nome'];
                }
            }
            $p['total_pecas_por_unidade'] = array_sum(array_column($p['pecas'], 'por_unidade'));
            $p['total_a_imprimir'] = array_sum(array_column($p['pecas'], 'a_imprimir'));
            $tempoFilaProd = array_sum(array_column($p['pecas'], 'tempo_fila_segundos'));
            $p['tempo_fila_segundos'] = $tempoFilaProd;
            $p['tempo_fila_formatado'] = formatarTempoHHMMSS($tempoFilaProd);
            $p['peso_fila_gramas'] = round(array_sum(array_column($p['pecas'], 'peso_fila_gramas')), 2);
        }

        $totalMontavel += $p['montavel'];
        $lista[] = $p;
    }

    usort($lista, function ($a, $b) {
        if (($a['total_a_imprimir'] > 0) !== ($b['total_a_imprimir'] > 0)) {
            return $a['total_a_imprimir'] > 0 ? -1 : 1;
        }
        if (($a['montavel'] > 0) !== ($b['montavel'] > 0)) {
            return $a['montavel'] > 0 ? -1 : 1;
        }
        return strcmp($a['nome'], $b['nome']);
    });

    // Ordena a fila de impressão por urgência
    $ordemUrgencia = ['atrasado' => 1, 'hoje' => 2, 'proximo' => 3, 'normal' => 4, 'estoque' => 5];
    usort($filaImpressao, function ($a, $b) use ($ordemUrgencia) {
        $uA = $ordemUrgencia[$a['urgencia']] ?? 9;
        $uB = $ordemUrgencia[$b['urgencia']] ?? 9;
        if ($uA !== $uB) return $uA <=> $uB;
        if ($a['proxima_entrega'] && $b['proxima_entrega']) {
            $cmp = strcmp($a['proxima_entrega'], $b['proxima_entrega']);
            if ($cmp !== 0) return $cmp;
        }
        return strcmp($a['item_nome'], $b['item_nome']);
    });

    $totalTempoFilaGeral = array_sum(array_column($filaImpressao, 'tempo_total_segundos'));
    $totalPesoFilaGeral = round(array_sum(array_column($filaImpressao, 'peso_total_gramas')), 2);
    $totalItensFila = array_sum(array_column($filaImpressao, 'a_imprimir'));

    jsonResponse([
        'resumo' => [
            'total_produtos' => count($lista),
            'pecas_com_fila' => count($filaImpressao),
            'total_a_imprimir' => $totalItensFila,
            'total_estoque' => $totalEstoquePecas,
            'total_montavel' => $totalMontavel,
            'total_tempo_fila_segundos' => $totalTempoFilaGeral,
            'total_tempo_fila_formatado' => formatarTempoHHMMSS($totalTempoFilaGeral),
            'total_peso_fila_gramas' => $totalPesoFilaGeral,
        ],
        'produtos' => $lista,
        'fila_impressao' => $filaImpressao,
    ]);
}

