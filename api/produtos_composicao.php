<?php
require_once __DIR__ . '/../includes/auth.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ------------------------------------------------------------
// GET /api/produtos_composicao.php?produto_pai_id=N&meta=M
// Retorna as peças cadastradas do produto, capacidade de montagem e diagnóstico de gargalos
// ------------------------------------------------------------
if ($method === 'GET') {
    $paiId = (int) ($_GET['produto_pai_id'] ?? 0);
    $meta = max(1, (int) ($_GET['meta'] ?? 1));

    if (!$paiId) {
        jsonError('Informe o id do produto.');
    }

    $stmtProd = $pdo->prepare("SELECT id, nome, tipo, estoque, preco, ativo FROM produtos WHERE id = :id");
    $stmtProd->execute(['id' => $paiId]);
    $produto = $stmtProd->fetch();
    if (!$produto) {
        jsonError('Produto não encontrado.', 404);
    }

    $sql = "
        SELECT 
            id AS peca_id,
            produto_id,
            nome,
            quantidade AS por_unidade,
            cor,
            estoque AS estoque_atual,
            foto
        FROM produto_pecas
        WHERE produto_id = :pai_id
        ORDER BY id ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['pai_id' => $paiId]);
    $pecas = $stmt->fetchAll();

    if (empty($pecas)) {
        jsonResponse([
            'produto' => $produto,
            'capacidade_maxima' => 0,
            'meta' => $meta,
            'pode_atender_meta' => false,
            'gargalos' => [],
            'pecas' => []
        ]);
    }

    $capacidadeMaxima = PHP_INT_MAX;
    $detalhes = [];

    foreach ($pecas as $p) {
        $porUnidade = max(1, (int) $p['por_unidade']);
        $estoque = (int) $p['estoque_atual'];
        $capacidadeItem = intdiv(max(0, $estoque), $porUnidade);

        if ($capacidadeItem < $capacidadeMaxima) {
            $capacidadeMaxima = $capacidadeItem;
        }

        $totalNecessarioMeta = $meta * $porUnidade;
        $faltamParaMeta = max(0, $totalNecessarioMeta - $estoque);
        $sobraAposMeta = max(0, $estoque - $totalNecessarioMeta);

        $detalhes[] = [
            'peca_id' => (int) $p['peca_id'],
            'nome' => $p['nome'],
            'cor' => $p['cor'] ?: 'Padrão',
            'foto' => $p['foto'],
            'por_unidade' => $porUnidade,
            'estoque_atual' => $estoque,
            'capacidade_individual' => $capacidadeItem,
            'total_necessario_meta' => $totalNecessarioMeta,
            'faltam_para_meta' => $faltamParaMeta,
            'sobra_apos_meta' => $sobraAposMeta,
            'situacao' => $faltamParaMeta > 0 ? 'insuficiente' : 'ok',
        ];
    }


    $gargalos = [];
    foreach ($detalhes as &$det) {
        $det['eh_gargalo'] = ($det['capacidade_individual'] === $capacidadeMaxima);
        if ($det['eh_gargalo']) {
            $gargalos[] = $det['nome'] . ($det['cor'] && $det['cor'] !== 'Padrão' ? " ({$det['cor']})" : '');
        }
    }

    jsonResponse([
        'produto' => $produto,
        'capacidade_maxima' => $capacidadeMaxima,
        'meta' => $meta,
        'pode_atender_meta' => ($capacidadeMaxima >= $meta),
        'gargalos' => $gargalos,
        'pecas' => $detalhes
    ]);
}

// ------------------------------------------------------------
// POST /api/produtos_composicao.php?acao=montar
// Body: { produto_pai_id: N, quantidade: Q }
// Baixa as peças do estoque e incrementa o produto final montado
// ------------------------------------------------------------
if ($method === 'POST' && ($_GET['acao'] ?? '') === 'montar') {
    $b = readJsonBody();
    $paiId = (int) ($b['produto_pai_id'] ?? 0);
    $qtdMontar = (int) ($b['quantidade'] ?? 0);

    if (!$paiId) jsonError('Informe o id do produto.');
    if ($qtdMontar <= 0) jsonError('A quantidade a ser montada deve ser no mínimo 1.');

    $stmtProd = $pdo->prepare("SELECT id, nome, tipo, estoque FROM produtos WHERE id = :id");
    $stmtProd->execute(['id' => $paiId]);
    $produto = $stmtProd->fetch();
    if (!$produto) jsonError('Produto não encontrado.', 404);

    // Buscar peças cadastradas do produto
    $stmtPecas = $pdo->prepare("SELECT id, nome, cor, quantidade, estoque FROM produto_pecas WHERE produto_id = :pai_id");
    $stmtPecas->execute(['pai_id' => $paiId]);
    $pecas = $stmtPecas->fetchAll();

    if (empty($pecas)) {
        jsonError('Este produto não possui peças cadastradas na ficha técnica.');
    }

    // Validar se todas as peças têm estoque suficiente
    foreach ($pecas as $p) {
        $necessario = $p['quantidade'] * $qtdMontar;
        if ($p['estoque'] < $necessario) {
            $falta = $necessario - $p['estoque'];
            $rotulo = $p['nome'] . ($p['cor'] ? " ({$p['cor']})" : '');
            jsonError(sprintf(
                'Estoque insuficiente da peça "%s": disponível %d, necessário %d (faltam %d peças).',
                $rotulo,
                $p['estoque'],
                $necessario,
                $falta
            ));
        }
    }

    // Executar transação atômica de montagem
    $pdo->beginTransaction();
    try {
        $stmtBaixa = $pdo->prepare("UPDATE produto_pecas SET estoque = estoque - :consumo WHERE id = :id");
        foreach ($pecas as $p) {
            $consumo = $p['quantidade'] * $qtdMontar;
            $stmtBaixa->execute([
                'consumo' => $consumo,
                'id' => $p['id']
            ]);
        }

        $stmtAumenta = $pdo->prepare("UPDATE produtos SET estoque = estoque + :qtd WHERE id = :id");
        $stmtAumenta->execute([
            'qtd' => $qtdMontar,
            'id' => $paiId
        ]);

        $pdo->commit();

        // Buscar novo estoque do produto final
        $stmtNovo = $pdo->prepare("SELECT estoque FROM produtos WHERE id = :id");
        $stmtNovo->execute(['id' => $paiId]);
        $novoEstoque = (int) $stmtNovo->fetchColumn();

        jsonResponse([
            'ok' => true,
            'mensagem' => "Montagem de {$qtdMontar} unidade(s) de \"{$produto['nome']}\" realizada com sucesso!",
            'quantidade_montada' => $qtdMontar,
            'novo_estoque' => $novoEstoque
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao registrar montagem: ' . $e->getMessage(), 500);
    }
}

// ------------------------------------------------------------
// POST /api/produtos_composicao.php?acao=ajustar_estoque_peca
// Body: { peca_id: N, estoque: E } ou { peca_id: N, delta: D }
// Ajusta ou adiciona entrada de peças impressas
// ------------------------------------------------------------
if ($method === 'POST' && ($_GET['acao'] ?? '') === 'ajustar_estoque_peca') {
    $b = readJsonBody();
    $pecaId = (int) ($b['peca_id'] ?? 0);
    if (!$pecaId) jsonError('Informe o id da peça.');

    if (isset($b['delta'])) {
        $delta = (int) $b['delta'];
        $stmt = $pdo->prepare("UPDATE produto_pecas SET estoque = GREATEST(0, estoque + :delta) WHERE id = :id");
        $stmt->execute(['delta' => $delta, 'id' => $pecaId]);
    } elseif (isset($b['estoque'])) {
        $novoEstoque = max(0, (int) $b['estoque']);
        $stmt = $pdo->prepare("UPDATE produto_pecas SET estoque = :estoque WHERE id = :id");
        $stmt->execute(['estoque' => $novoEstoque, 'id' => $pecaId]);
    } else {
        jsonError('Informe o saldo ou a quantidade de entrada.');
    }

    $stmtP = $pdo->prepare("SELECT estoque FROM produto_pecas WHERE id = :id");
    $stmtP->execute(['id' => $pecaId]);
    jsonResponse(['ok' => true, 'novo_estoque' => (int) $stmtP->fetchColumn()]);
}

// ------------------------------------------------------------
// POST /api/produtos_composicao.php?produto_pai_id=N
// Body: { itens: [ { nome, quantidade, cor, estoque }, ... ] }
// Salva/atualiza as peças cadastradas do produto
// ------------------------------------------------------------
if ($method === 'POST') {
    $paiId = (int) ($_GET['produto_pai_id'] ?? 0);
    if (!$paiId) jsonError('Informe o id do produto.');

    $stmtProd = $pdo->prepare("SELECT id, nome, tipo FROM produtos WHERE id = :id");
    $stmtProd->execute(['id' => $paiId]);
    $produto = $stmtProd->fetch();
    if (!$produto) jsonError('Produto não encontrado.', 404);

    $b = readJsonBody();
    $itens = $b['itens'] ?? [];
    if (!is_array($itens)) jsonError('Lista de peças inválida.');

    $pecasLimpos = [];
    foreach ($itens as $item) {
        $nome = trim($item['nome'] ?? '');
        if ($nome === '') continue;

        $cor = trim($item['cor'] ?? '');
        $foto = trim($item['foto'] ?? '');

        $pecasLimpos[] = [
            'peca_id' => (int) ($item['peca_id'] ?? 0),
            'nome' => $nome,
            'quantidade' => max(1, (int) ($item['quantidade'] ?? 1)),
            'cor' => $cor === '' ? null : $cor,
            // Só vale para peça nova: o saldo de peça já cadastrada pertence à
            // Bancada e nunca é sobrescrito por este formulário de cadastro.
            'estoque_inicial' => max(0, (int) ($item['estoque'] ?? 0)),
            'foto' => $foto === '' ? null : $foto
        ];
    }

    $pdo->beginTransaction();
    try {
        // Upsert por id: quem continua na lista mantém o id (e o saldo), quem
        // sumiu é removido, quem chegou é inserido. Nunca DELETE + INSERT geral,
        // senão o estoque impresso e o histórico de movimentos viram órfãos.
        $stmtAtuais = $pdo->prepare("SELECT id FROM produto_pecas WHERE produto_id = :pai_id FOR UPDATE");
        $stmtAtuais->execute(['pai_id' => $paiId]);
        $idsAtuais = array_map('intval', $stmtAtuais->fetchAll(PDO::FETCH_COLUMN));

        $stmtUpd = $pdo->prepare("
            UPDATE produto_pecas SET nome = :nome, quantidade = :qtd, cor = :cor, foto = :foto
            WHERE id = :id AND produto_id = :produto_id
        ");
        $stmtIns = $pdo->prepare("
            INSERT INTO produto_pecas (produto_id, nome, quantidade, cor, estoque, foto)
            VALUES (:produto_id, :nome, :qtd, :cor, :estoque, :foto)
        ");

        $mantidos = [];
        foreach ($pecasLimpos as $p) {
            if ($p['peca_id'] && in_array($p['peca_id'], $idsAtuais, true)) {
                $stmtUpd->execute([
                    'nome' => $p['nome'], 'qtd' => $p['quantidade'], 'cor' => $p['cor'],
                    'foto' => $p['foto'], 'id' => $p['peca_id'], 'produto_id' => $paiId,
                ]);
                $mantidos[] = $p['peca_id'];
            } else {
                $stmtIns->execute([
                    'produto_id' => $paiId, 'nome' => $p['nome'], 'qtd' => $p['quantidade'],
                    'cor' => $p['cor'], 'estoque' => $p['estoque_inicial'], 'foto' => $p['foto'],
                ]);
            }
        }

        $removidos = array_diff($idsAtuais, $mantidos);
        if ($removidos) {
            $in = implode(',', array_fill(0, count($removidos), '?'));
            $pdo->prepare("DELETE FROM produto_pecas WHERE produto_id = ? AND id IN ($in)")
                ->execute(array_merge([$paiId], array_values($removidos)));
        }

        if (!empty($pecasLimpos)) {
            $pdo->prepare("UPDATE produtos SET tipo = 'composto' WHERE id = :id AND tipo <> 'composto'")
                ->execute(['id' => $paiId]);
        }

        $pdo->commit();

        jsonResponse([
            'ok' => true,
            'total_pecas' => count($pecasLimpos),
            'removidas' => count($removidos),
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao salvar peças do produto: ' . $e->getMessage(), 500);
    }
}

jsonError('Método não suportado.', 405);
