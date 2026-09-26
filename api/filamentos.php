<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ------------------------------------------------------------
// GET /api/filamentos.php
// GET /api/filamentos.php?id=N
// GET /api/filamentos.php?extrato=1&filamento_id=N
// GET /api/filamentos.php?resumo=1
// ------------------------------------------------------------
if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);

    // Resumo consolidado para dashboards e relatórios
    if (!empty($_GET['resumo'])) {
        $stmt = $pdo->query("SELECT id FROM filamentos WHERE ativo = 1 ORDER BY cor ASC");
        $filIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $totalGramas = 0.0;
        $totalValor = 0.0;
        $totalRolos = 0.0;
        $qtdAlertas = 0;
        $filamentosList = [];

        foreach ($filIds as $fId) {
            $info = obterResumoFilamento($pdo, (int) $fId);
            if ($info) {
                $totalGramas += (float) $info['estoque_gramas'];
                $totalValor += (float) $info['valor_total_estoque'];
                $totalRolos += (float) $info['estoque_rolos'];
                if ($info['status'] !== 'ok') {
                    $qtdAlertas++;
                }
                $filamentosList[] = $info;
            }
        }

        $custoMedioGeralKg = $totalGramas > 0 ? (($totalValor / $totalGramas) * 1000) : 0.0;

        jsonResponse([
            'resumo' => [
                'total_filamentos' => count($filamentosList),
                'total_gramas' => round($totalGramas, 2),
                'total_kg' => round($totalGramas / 1000, 2),
                'total_rolos' => round($totalRolos, 2),
                'valor_total_estoque' => round($totalValor, 2),
                'custo_medio_geral_kg' => round($custoMedioGeralKg, 2),
                'qtd_alertas' => $qtdAlertas,
            ],
            'filamentos' => $filamentosList,
        ]);
    }

    // Extrato de movimentações
    if (!empty($_GET['extrato'])) {
        $filId = (int) ($_GET['filamento_id'] ?? 0);
        $limite = min(200, max(10, (int) ($_GET['limite'] ?? 50)));

        $where = [];
        $params = [];
        if ($filId > 0) {
            $where[] = 'm.filamento_id = :fil_id';
            $params['fil_id'] = $filId;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $pdo->prepare("
            SELECT m.*, f.nome AS filamento_nome, f.cor AS filamento_cor, f.tipo AS filamento_tipo,
                   u.nome AS usuario_nome, p.nome AS produto_nome, pp.nome AS peca_nome
            FROM filamento_movimentacoes m
            JOIN filamentos f ON f.id = m.filamento_id
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            LEFT JOIN produtos p ON p.id = m.produto_id
            LEFT JOIN produto_pecas pp ON pp.id = m.peca_id
            $whereSql
            ORDER BY m.criado_em DESC, m.id DESC
            LIMIT $limite
        ");
        $stmt->execute($params);
        jsonResponse($stmt->fetchAll());
    }

    // Detalhe de um filamento com seus lotes
    if ($id > 0) {
        $info = obterResumoFilamento($pdo, $id);
        if (!$info) {
            jsonError('Filamento não encontrado.', 404);
        }

        $stmtMov = $pdo->prepare("
            SELECT m.*, u.nome AS usuario_nome
            FROM filamento_movimentacoes m
            LEFT JOIN usuarios u ON u.id = m.usuario_id
            WHERE m.filamento_id = :id
            ORDER BY m.criado_em DESC, m.id DESC
            LIMIT 30
        ");
        $stmtMov->execute(['id' => $id]);
        $info['movimentacoes_recentes'] = $stmtMov->fetchAll();

        jsonResponse($info);
    }

    // Listagem geral de todos os filamentos
    $stmt = $pdo->query("SELECT id FROM filamentos ORDER BY ativo DESC, cor ASC, tipo ASC");
    $filIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $lista = [];
    foreach ($filIds as $fId) {
        $info = obterResumoFilamento($pdo, (int) $fId);
        if ($info) {
            $lista[] = $info;
        }
    }
    jsonResponse($lista);
}

// ------------------------------------------------------------
// POST /api/filamentos.php
// POST /api/filamentos.php?acao=entrada_lote
// POST /api/filamentos.php?acao=ajuste_estoque
// ------------------------------------------------------------
if ($method === 'POST') {
    $acao = $_GET['acao'] ?? '';
    $b = readJsonBody();

    // 1. Entrada de novos rolos / lote de compra
    if ($acao === 'entrada_lote') {
        $filId = (int) ($b['filamento_id'] ?? 0);
        $qtdRolos = max(1, (int) ($b['quantidade_rolos'] ?? 1));
        $pesoRolo = (float) ($b['peso_rolo_gramas'] ?? 1000.0);
        $precoRolo = (float) ($b['preco_rolo'] ?? 0.0);
        $precoTotal = (float) ($b['preco_total'] ?? ($precoRolo * $qtdRolos));

        if ($filId <= 0) {
            jsonError('Informe o filamento.');
        }
        if ($precoRolo <= 0 && $precoTotal <= 0) {
            jsonError('Informe o valor pago pelo rolo ou lote.');
        }

        $pdo->beginTransaction();
        try {
            $loteId = adicionarLoteFilamento($pdo, [
                'filamento_id' => $filId,
                'quantidade_rolos' => $qtdRolos,
                'peso_rolo_gramas' => $pesoRolo,
                'preco_rolo' => $precoRolo,
                'preco_total' => $precoTotal,
                'data_compra' => $b['data_compra'] ?? date('Y-m-d'),
                'observacoes' => $b['observacoes'] ?? null,
                'usuario_id' => $usuario['id'],
            ]);
            $pdo->commit();

            $resumo = obterResumoFilamento($pdo, $filId);
            jsonResponse([
                'ok' => true,
                'lote_id' => $loteId,
                'mensagem' => "Lote de {$qtdRolos} rolo(s) adicionado com sucesso!",
                'filamento' => $resumo,
            ], 201);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError($e->getMessage());
        }
    }

    // 2. Ajuste manual de estoque (balança / conferência física)
    if ($acao === 'ajuste_estoque') {
        $filId = (int) ($b['filamento_id'] ?? 0);
        if ($filId <= 0) jsonError('Informe o filamento.');

        if (!isset($b['novo_estoque_gramas'])) {
            jsonError('Informe o novo estoque em gramas.');
        }
        $novoEstoque = max(0.0, (float) $b['novo_estoque_gramas']);
        $motivo = trim($b['motivo'] ?? 'Conferência física na balança');

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT estoque_gramas FROM filamentos WHERE id = :id FOR UPDATE");
            $stmt->execute(['id' => $filId]);
            $fil = $stmt->fetch();
            if (!$fil) throw new Exception('Filamento não encontrado.');

            $saldoAnt = (float) $fil['estoque_gramas'];
            $delta = $novoEstoque - $saldoAnt;

            $pdo->prepare("UPDATE filamentos SET estoque_gramas = :g WHERE id = :id")
                ->execute(['g' => $novoEstoque, 'id' => $filId]);

            // Se diminuiu, abate dos lotes PEPS; se aumentou, ajusta no lote mais recente ou cria lote de ajuste
            if ($delta < 0) {
                $abatimento = abs($delta);
                $stmtLotes = $pdo->prepare("SELECT id, gramas_saldo FROM filamento_lotes WHERE filamento_id = :id AND gramas_saldo > 0 ORDER BY data_compra ASC, id ASC FOR UPDATE");
                $stmtLotes->execute(['id' => $filId]);
                $stmtUpd = $pdo->prepare("UPDATE filamento_lotes SET gramas_saldo = :s WHERE id = :id");
                foreach ($stmtLotes->fetchAll() as $lt) {
                    if ($abatimento <= 0) break;
                    $s = (float) $lt['gramas_saldo'];
                    $tira = min($s, $abatimento);
                    $stmtUpd->execute(['s' => $s - $tira, 'id' => $lt['id']]);
                    $abatimento -= $tira;
                }
            } elseif ($delta > 0) {
                // Aumentou: adiciona no lote mais recente ou cria lote de acerto
                $stmtUltimo = $pdo->prepare("SELECT id, gramas_saldo FROM filamento_lotes WHERE filamento_id = :id ORDER BY id DESC LIMIT 1 FOR UPDATE");
                $stmtUltimo->execute(['id' => $filId]);
                $ultimoLote = $stmtUltimo->fetch();
                if ($ultimoLote) {
                    $pdo->prepare("UPDATE filamento_lotes SET gramas_saldo = gramas_saldo + :d WHERE id = :id")
                        ->execute(['d' => $delta, 'id' => $ultimoLote['id']]);
                }
            }

            $pdo->prepare("
                INSERT INTO filamento_movimentacoes
                    (filamento_id, tipo, gramas, saldo_anterior_gramas, saldo_posterior_gramas, usuario_id, observacoes)
                VALUES
                    (:fil_id, 'ajuste', :gramas, :saldo_ant, :saldo_post, :usr, :obs)
            ")->execute([
                'fil_id' => $filId,
                'gramas' => $delta,
                'saldo_ant' => $saldoAnt,
                'saldo_post' => $novoEstoque,
                'usr' => $usuario['id'],
                'obs' => "Ajuste manual: $motivo (variação: " . ($delta >= 0 ? "+{$delta}g" : "{$delta}g") . ")",
            ]);

            $pdo->commit();
            jsonResponse([
                'ok' => true,
                'novo_estoque' => $novoEstoque,
                'filamento' => obterResumoFilamento($pdo, $filId),
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            jsonError($e->getMessage());
        }
    }

    // 3. Cadastro de novo filamento
    $nome = trim($b['nome'] ?? '');
    $marca = trim($b['marca'] ?? '');
    $tipo = trim($b['tipo'] ?? 'PLA');
    $cor = trim($b['cor'] ?? '');
    $corHex = trim($b['cor_hex'] ?? '#6366f1');
    $estoqueMinimo = max(0.0, (float) ($b['estoque_minimo_gramas'] ?? 500.0));

    if ($marca === '') jsonError('Marca do filamento é obrigatória.');
    if ($cor === '') jsonError('Cor do filamento é obrigatória.');
    if ($nome === '') {
        $nome = "$tipo $cor - $marca";
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO filamentos (nome, marca, tipo, cor, cor_hex, estoque_gramas, estoque_minimo_gramas, ativo)
            VALUES (:nome, :marca, :tipo, :cor, :cor_hex, 0.00, :minimo, 1)
        ");
        $stmt->execute([
            'nome' => $nome,
            'marca' => $marca,
            'tipo' => $tipo,
            'cor' => $cor,
            'cor_hex' => $corHex,
            'minimo' => $estoqueMinimo,
        ]);
        $filId = (int) $pdo->lastInsertId();

        // Se informou lote inicial no mesmo formulário
        if (!empty($b['quantidade_rolos']) && (int)$b['quantidade_rolos'] > 0 && !empty($b['preco_rolo'])) {
            adicionarLoteFilamento($pdo, [
                'filamento_id' => $filId,
                'quantidade_rolos' => (int) $b['quantidade_rolos'],
                'peso_rolo_gramas' => (float) ($b['peso_rolo_gramas'] ?? 1000.0),
                'preco_rolo' => (float) $b['preco_rolo'],
                'data_compra' => $b['data_compra'] ?? date('Y-m-d'),
                'observacoes' => 'Estoque inicial cadastrado',
                'usuario_id' => $usuario['id'],
            ]);
        }

        $pdo->commit();
        jsonResponse(obterResumoFilamento($pdo, $filId), 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError($e->getMessage());
    }
}

// ------------------------------------------------------------
// PUT /api/filamentos.php?id=N (Editar dados cadastrais)
// ------------------------------------------------------------
if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('Informe o id do filamento.');

    $b = readJsonBody();
    $nome = trim($b['nome'] ?? '');
    $marca = trim($b['marca'] ?? '');
    $tipo = trim($b['tipo'] ?? 'PLA');
    $cor = trim($b['cor'] ?? '');
    $corHex = trim($b['cor_hex'] ?? '#6366f1');
    $estoqueMinimo = max(0.0, (float) ($b['estoque_minimo_gramas'] ?? 500.0));
    $ativo = array_key_exists('ativo', $b) ? (int)(bool)$b['ativo'] : 1;

    if ($marca === '') jsonError('Marca é obrigatória.');
    if ($cor === '') jsonError('Cor é obrigatória.');
    if ($nome === '') $nome = "$tipo $cor - $marca";

    $stmt = $pdo->prepare("
        UPDATE filamentos
        SET nome = :nome, marca = :marca, tipo = :tipo, cor = :cor, cor_hex = :cor_hex,
            estoque_minimo_gramas = :minimo, ativo = :ativo
        WHERE id = :id
    ");
    $stmt->execute([
        'nome' => $nome,
        'marca' => $marca,
        'tipo' => $tipo,
        'cor' => $cor,
        'cor_hex' => $corHex,
        'minimo' => $estoqueMinimo,
        'ativo' => $ativo,
        'id' => $id,
    ]);

    jsonResponse(obterResumoFilamento($pdo, $id));
}

// ------------------------------------------------------------
// DELETE /api/filamentos.php?id=N
// ------------------------------------------------------------
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) jsonError('Informe o id.');

    $stmtUso = $pdo->prepare("SELECT COUNT(*) FROM filamento_movimentacoes WHERE filamento_id = :id AND tipo = 'consumo_producao'");
    $stmtUso->execute(['id' => $id]);
    if ((int)$stmtUso->fetchColumn() > 0) {
        // Se já houve consumo em produção, apenas desativa
        $pdo->prepare("UPDATE filamentos SET ativo = 0 WHERE id = :id")->execute(['id' => $id]);
        jsonResponse(['ok' => true, 'mensagem' => 'Filamento desativado para preservar o histórico de produção.']);
    }

    $pdo->prepare("DELETE FROM filamentos WHERE id = :id")->execute(['id' => $id]);
    jsonResponse(['ok' => true]);
}

jsonError('Método não suportado.', 405);
