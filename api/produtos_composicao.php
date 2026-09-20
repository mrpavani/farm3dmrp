<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Busca as peças de um produto com suas cores aninhadas, já com os totais
// (soma das cores) e o diagnóstico de capacidade para a $meta informada.
// Uma peça pode ter várias cores em estoque (ex.: Chave de fenda em Cinza
// e em Laranja) — qualquer cor serve para montar, então o que conta para
// a capacidade é a SOMA do estoque das cores da peça.
function diagnosticoPecas(PDO $pdo, int $paiId, int $meta): array {
    $stmtPecas = $pdo->prepare("
        SELECT id AS peca_id, nome, quantidade AS por_unidade, foto
        FROM produto_pecas WHERE produto_id = :pai_id ORDER BY id ASC
    ");
    $stmtPecas->execute(['pai_id' => $paiId]);
    $pecas = $stmtPecas->fetchAll();
    if (!$pecas) return ['capacidade_maxima' => 0, 'gargalos' => [], 'pecas' => []];

    $pecaIds = array_column($pecas, 'peca_id');
    $in = implode(',', array_fill(0, count($pecaIds), '?'));
    $stmtCores = $pdo->prepare("
        SELECT id AS cor_id, peca_id, cor, estoque, foto
        FROM produto_pecas_cores WHERE peca_id IN ($in) ORDER BY id ASC
    ");
    $stmtCores->execute($pecaIds);
    $coresPorPeca = [];
    foreach ($stmtCores->fetchAll() as $c) {
        $coresPorPeca[(int) $c['peca_id']][] = $c;
    }

    $capacidadeMaxima = PHP_INT_MAX;
    foreach ($pecas as &$p) {
        $cores = $coresPorPeca[(int) $p['peca_id']] ?? [];
        $p['cores'] = $cores;
        $p['estoque_atual'] = array_sum(array_map(fn($c) => (int) $c['estoque'], $cores));
        $porUnidade = max(1, (int) $p['por_unidade']);
        $p['capacidade_individual'] = intdiv(max(0, $p['estoque_atual']), $porUnidade);
        $capacidadeMaxima = min($capacidadeMaxima, $p['capacidade_individual']);
    }
    unset($p);
    if ($capacidadeMaxima === PHP_INT_MAX) $capacidadeMaxima = 0;

    $gargalos = [];
    foreach ($pecas as &$p) {
        $porUnidade = max(1, (int) $p['por_unidade']);
        $p['total_necessario_meta'] = $meta * $porUnidade;
        $p['faltam_para_meta'] = max(0, $p['total_necessario_meta'] - $p['estoque_atual']);
        $p['sobra_apos_meta'] = max(0, $p['estoque_atual'] - $p['total_necessario_meta']);
        $p['situacao'] = $p['faltam_para_meta'] > 0 ? 'insuficiente' : 'ok';
        $p['eh_gargalo'] = ($p['capacidade_individual'] === $capacidadeMaxima);
        if ($p['eh_gargalo']) $gargalos[] = $p['nome'];
    }
    unset($p);

    return ['capacidade_maxima' => $capacidadeMaxima, 'gargalos' => $gargalos, 'pecas' => $pecas];
}

// ------------------------------------------------------------
// GET /api/produtos_composicao.php?produto_pai_id=N&meta=M
// Retorna as peças cadastradas do produto (com suas cores), capacidade
// de montagem e diagnóstico de gargalos para a meta informada.
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

    $diag = diagnosticoPecas($pdo, $paiId, $meta);

    jsonResponse([
        'produto' => $produto,
        'capacidade_maxima' => $diag['capacidade_maxima'],
        'meta' => $meta,
        'pode_atender_meta' => ($diag['capacidade_maxima'] >= $meta),
        'gargalos' => $diag['gargalos'],
        'pecas' => $diag['pecas'],
    ]);
}

// ------------------------------------------------------------
// POST /api/produtos_composicao.php?acao=montar
// Body: { produto_pai_id: N, quantidade: Q }
// Baixa as peças do estoque (de qualquer cor disponível) e incrementa o
// produto final montado. Usa a mesma regra de includes/estoque.php que a
// Bancada da Fábrica, para não haver dois caminhos de montagem divergentes.
// ------------------------------------------------------------
if ($method === 'POST' && ($_GET['acao'] ?? '') === 'montar') {
    $b = readJsonBody();
    $paiId = (int) ($b['produto_pai_id'] ?? 0);
    $qtdMontar = (int) ($b['quantidade'] ?? 0);

    if (!$paiId) jsonError('Informe o id do produto.');
    if ($qtdMontar <= 0) jsonError('A quantidade a ser montada deve ser no mínimo 1.');

    $stmtProd = $pdo->prepare("SELECT id, nome FROM produtos WHERE id = :id");
    $stmtProd->execute(['id' => $paiId]);
    $produto = $stmtProd->fetch();
    if (!$produto) jsonError('Produto não encontrado.', 404);

    $pdo->beginTransaction();
    try {
        $novoEstoque = montarProduto($pdo, $paiId, $qtdMontar, $usuario['id']);
        $pdo->commit();

        jsonResponse([
            'ok' => true,
            'mensagem' => "Montagem de {$qtdMontar} unidade(s) de \"{$produto['nome']}\" realizada com sucesso!",
            'quantidade_montada' => $qtdMontar,
            'novo_estoque' => $novoEstoque
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao registrar montagem: ' . $e->getMessage());
    }
}

// ------------------------------------------------------------
// POST /api/produtos_composicao.php?produto_pai_id=N
// Body: { itens: [ { peca_id, nome, quantidade, foto,
//                     cores: [ { cor_id, cor, estoque, foto }, ... ] }, ... ] }
//
// Salva/atualiza a ficha técnica (peças) e o cadastro de cores de cada
// peça. Uma peça pode ter várias cores (ex.: Chave de fenda em Cinza e em
// Laranja) ou uma só linha de cor vazia/"qualquer cor" (ex.: Suporte).
//
// O saldo (estoque) de uma cor JÁ CADASTRADA nunca é sobrescrito por aqui
// — esse saldo pertence à Bancada da Fábrica. "estoque" só é usado como
// saldo inicial quando a cor é nova (sem cor_id ainda existente).
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

    // Normaliza o payload: cada peça, com sua lista de cores. Peça sem
    // nenhuma cor informada recebe uma variante padrão "qualquer cor".
    $pecasLimpas = [];
    foreach ($itens as $item) {
        $nome = trim($item['nome'] ?? '');
        if ($nome === '') continue;

        $foto = trim($item['foto'] ?? '');
        $coresEntrada = is_array($item['cores'] ?? null) ? $item['cores'] : [];

        $cores = [];
        foreach ($coresEntrada as $c) {
            $corNome = trim($c['cor'] ?? '');
            $cores[] = [
                'cor_id' => (int) ($c['cor_id'] ?? 0),
                'cor' => $corNome === '' ? null : $corNome,
                'estoque_inicial' => max(0, (int) ($c['estoque'] ?? 0)),
                'foto' => trim($c['foto'] ?? '') ?: null,
            ];
        }
        if (!$cores) {
            $cores[] = ['cor_id' => 0, 'cor' => null, 'estoque_inicial' => 0, 'foto' => null];
        }

        $pecasLimpas[] = [
            'peca_id' => (int) ($item['peca_id'] ?? 0),
            'nome' => $nome,
            'quantidade' => max(1, (int) ($item['quantidade'] ?? 1)),
            'foto' => $foto === '' ? null : $foto,
            'cores' => $cores,
        ];
    }

    $pdo->beginTransaction();
    try {
        // ---- Peças: upsert por id (mesma regra de sempre) ----
        $stmtAtuais = $pdo->prepare("SELECT id FROM produto_pecas WHERE produto_id = :pai_id FOR UPDATE");
        $stmtAtuais->execute(['pai_id' => $paiId]);
        $idsAtuais = array_map('intval', $stmtAtuais->fetchAll(PDO::FETCH_COLUMN));

        $stmtUpdPeca = $pdo->prepare("
            UPDATE produto_pecas SET nome = :nome, quantidade = :qtd, foto = :foto
            WHERE id = :id AND produto_id = :produto_id
        ");
        $stmtInsPeca = $pdo->prepare("
            INSERT INTO produto_pecas (produto_id, nome, quantidade, foto)
            VALUES (:produto_id, :nome, :qtd, :foto)
        ");

        // ---- Cores: upsert por id, sempre amarrado à peça (peca_id) ----
        $stmtCoresAtuais = $pdo->prepare("SELECT id, cor, estoque FROM produto_pecas_cores WHERE peca_id = :peca_id FOR UPDATE");
        $stmtUpdCor = $pdo->prepare("
            UPDATE produto_pecas_cores SET cor = :cor, foto = :foto
            WHERE id = :id AND peca_id = :peca_id
        ");
        $stmtInsCor = $pdo->prepare("
            INSERT INTO produto_pecas_cores (peca_id, cor, estoque, foto)
            VALUES (:peca_id, :cor, :estoque, :foto)
        ");

        $pecasMantidas = [];
        $totalCores = 0;
        foreach ($pecasLimpas as $p) {
            if ($p['peca_id'] && in_array($p['peca_id'], $idsAtuais, true)) {
                $stmtUpdPeca->execute([
                    'nome' => $p['nome'], 'qtd' => $p['quantidade'], 'foto' => $p['foto'],
                    'id' => $p['peca_id'], 'produto_id' => $paiId,
                ]);
                $pecaId = $p['peca_id'];
            } else {
                $stmtInsPeca->execute([
                    'produto_id' => $paiId, 'nome' => $p['nome'],
                    'qtd' => $p['quantidade'], 'foto' => $p['foto'],
                ]);
                $pecaId = (int) $pdo->lastInsertId();
            }
            $pecasMantidas[] = $pecaId;

            $stmtCoresAtuais->execute(['peca_id' => $pecaId]);
            $coresAtuais = $stmtCoresAtuais->fetchAll(); // id, cor, estoque
            $idsCoresAtuais = array_map(fn($c) => (int) $c['id'], $coresAtuais);

            $coresMantidas = [];
            foreach ($p['cores'] as $c) {
                if ($c['cor_id'] && in_array($c['cor_id'], $idsCoresAtuais, true)) {
                    $stmtUpdCor->execute([
                        'cor' => $c['cor'], 'foto' => $c['foto'],
                        'id' => $c['cor_id'], 'peca_id' => $pecaId,
                    ]);
                    $coresMantidas[] = $c['cor_id'];
                } else {
                    $stmtInsCor->execute([
                        'peca_id' => $pecaId, 'cor' => $c['cor'],
                        'estoque' => $c['estoque_inicial'], 'foto' => $c['foto'],
                    ]);
                }
                $totalCores++;
            }

            $idsCoresRemovidas = array_diff($idsCoresAtuais, $coresMantidas);
            if ($idsCoresRemovidas) {
                // Removida do cadastro, mas se tinha saldo, esse saldo precisa
                // ficar registrado como baixa — senão a peça só "some" do livro.
                foreach ($coresAtuais as $c) {
                    if (!in_array((int) $c['id'], $idsCoresRemovidas, true)) continue;
                    if ((int) $c['estoque'] > 0) {
                        registrarMovimento($pdo, 'peca_ajuste', [
                            'produto_id'   => $paiId,
                            'peca_id'      => $pecaId,
                            'quantidade'   => -(int) $c['estoque'],
                            'saldo_depois' => 0,
                            'usuario_id'   => $usuario['id'],
                            'observacoes'  => 'Cor removida da ficha técnica (cor: ' . rotuloCor($c['cor']) . ', tinha ' . $c['estoque'] . ' em estoque).',
                        ]);
                    }
                }
                $in = implode(',', array_fill(0, count($idsCoresRemovidas), '?'));
                $pdo->prepare("DELETE FROM produto_pecas_cores WHERE peca_id = ? AND id IN ($in)")
                    ->execute(array_merge([$pecaId], array_values($idsCoresRemovidas)));
            }
        }

        $pecasRemovidas = array_diff($idsAtuais, $pecasMantidas);
        if ($pecasRemovidas) {
            // Mesma lógica: se a peça removida ainda tinha saldo em alguma cor,
            // registra a baixa antes do DELETE (a cascade tira as cores junto).
            $in = implode(',', array_fill(0, count($pecasRemovidas), '?'));
            $stmtSaldoRemovido = $pdo->prepare("
                SELECT pp.id, pp.nome, COALESCE(SUM(pc.estoque), 0) AS total
                FROM produto_pecas pp LEFT JOIN produto_pecas_cores pc ON pc.peca_id = pp.id
                WHERE pp.id IN ($in) GROUP BY pp.id, pp.nome
            ");
            $stmtSaldoRemovido->execute(array_values($pecasRemovidas));
            foreach ($stmtSaldoRemovido->fetchAll() as $r) {
                if ((int) $r['total'] > 0) {
                    registrarMovimento($pdo, 'peca_ajuste', [
                        'produto_id'   => $paiId,
                        'peca_id'      => (int) $r['id'],
                        'quantidade'   => -(int) $r['total'],
                        'saldo_depois' => 0,
                        'usuario_id'   => $usuario['id'],
                        'observacoes'  => 'Peça "' . $r['nome'] . '" removida da ficha técnica (tinha ' . $r['total'] . ' em estoque).',
                    ]);
                }
            }
            $pdo->prepare("DELETE FROM produto_pecas WHERE produto_id = ? AND id IN ($in)")
                ->execute(array_merge([$paiId], array_values($pecasRemovidas)));
        }

        if (!empty($pecasLimpas)) {
            $pdo->prepare("UPDATE produtos SET tipo = 'composto' WHERE id = :id AND tipo <> 'composto'")
                ->execute(['id' => $paiId]);
        }

        $pdo->commit();

        jsonResponse([
            'ok' => true,
            'total_pecas' => count($pecasLimpas),
            'total_cores' => $totalCores,
            'removidas' => count($pecasRemovidas),
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao salvar peças do produto: ' . $e->getMessage(), 500);
    }
}

jsonError('Método não suportado.', 405);
