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

            registrarMovimento($pdo, 'peca_ajuste', [
                'produto_id'   => (int) $peca['produto_id'],
                'peca_id'      => $pecaId,
                'quantidade'   => $novoSaldo - $saldoAntes,
                'saldo_depois' => $novoSaldo,
                'usuario_id'   => $usuario['id'],
                'observacoes'  => "Ajuste manual de saldo da peça {$peca['peca_nome']}, de {$saldoAntes} para {$novoSaldo}.",
            ]);

            $capacidade = capacidadeMontagem($pdo, (int) $peca['produto_id']);
            $pdo->commit();

            jsonResponse([
                'ok' => true,
                'peca_id' => $pecaId,
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

    jsonError('Ação não suportada.', 405);
}

// ------------------------------------------------------------
// GET /api/fabrica_pecas.php
// A bancada inteira: por produto, as peças (com saldo da peça e cores/gramas)
// e fila de impressão, quanto dá para montar agora e o que trava.
// ------------------------------------------------------------
if ($method === 'GET') {
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

    $pecasLinhas = $pdo->query("
        SELECT
            pp.id AS peca_id, pp.produto_id, pp.nome AS peca_nome,
            pp.quantidade AS por_unidade, pp.estoque AS peca_estoque, pp.foto AS peca_foto,
            pp.peso_gramas AS peca_peso, pp.tempo_producao_segundos AS peca_tempo,
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
                'em_producao' => $demandaBruta,
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
        if ($aImprimir > 0) {
            $status = ($proxima && $proxima <= date('Y-m-d')) ? 'urgente' : 'imprimir';
        }

        $tempoPeca1un = (int) ($l['peca_tempo'] ?? 0);
        $pesoPeca1un = (float) ($l['peca_peso'] ?? 0);
        $tempoUnitario = $porUnidade > 0 ? ($tempoPeca1un / $porUnidade) : 0;
        $pesoUnitario = $porUnidade > 0 ? ($pesoPeca1un / $porUnidade) : 0;
        $tempoFilaSegundos = (int) round($tempoUnitario * $aImprimir);
        $pesoFilaGramas = round($pesoUnitario * $aImprimir, 2);

        $produtos[$pid]['pecas'][] = [
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
            'cores' => array_map(fn($c) => [
                'cor_id' => (int) $c['cor_id'],
                'cor' => trim((string) $c['cor']) === '' ? null : $c['cor'],
                'foto' => $c['foto'],
                'estoque' => $estoquePeca,
                'peso_gramas' => $c['peso_gramas'] !== null ? (float) $c['peso_gramas'] : null,
                'tempo_producao_segundos' => $c['tempo_producao_segundos'] !== null ? (int) $c['tempo_producao_segundos'] : null,
                'tempo_formatado' => $c['tempo_producao_segundos'] !== null ? formatarTempoHHMMSS((int)$c['tempo_producao_segundos']) : null,
            ], $cores),
        ];
    }

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
        $tempoFilaProd = array_sum(array_column($p['pecas'], 'tempo_fila_segundos'));
        $p['tempo_fila_segundos'] = $tempoFilaProd;
        $p['tempo_fila_formatado'] = formatarTempoHHMMSS($tempoFilaProd);
        $p['peso_fila_gramas'] = round(array_sum(array_column($p['pecas'], 'peso_fila_gramas')), 2);

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

    $totalTempoFilaGeral = array_sum(array_column($lista, 'tempo_fila_segundos'));
    $totalPesoFilaGeral = round(array_sum(array_column($lista, 'peso_fila_gramas')), 2);

    jsonResponse([
        'resumo' => [
            'total_produtos' => count($lista),
            'pecas_com_fila' => $pecasComFila,
            'total_a_imprimir' => $totalAImprimir,
            'total_estoque' => $totalEstoquePecas,
            'total_montavel' => $totalMontavel,
            'total_tempo_fila_segundos' => $totalTempoFilaGeral,
            'total_tempo_fila_formatado' => formatarTempoHHMMSS($totalTempoFilaGeral),
            'total_peso_fila_gramas' => $totalPesoFilaGeral,
        ],
        'produtos' => $lista,
    ]);
}
