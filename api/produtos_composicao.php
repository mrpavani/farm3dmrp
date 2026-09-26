<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Busca as peças de um produto com suas cores aninhadas, já com os totais
// (soma das cores), o diagnóstico de capacidade para a $meta informada,
// e os cálculos consolidados de peso em gramas (filamento) e tempo futuro HH:mm:ss.
function diagnosticoPecas(PDO $pdo, int $paiId, int $meta): array {
    $stmtPecas = $pdo->prepare("
        SELECT id AS peca_id, nome, quantidade AS por_unidade, estoque, foto,
               peso_gramas, tempo_producao_segundos
        FROM produto_pecas WHERE produto_id = :pai_id ORDER BY id ASC
    ");
    $stmtPecas->execute(['pai_id' => $paiId]);
    $pecas = $stmtPecas->fetchAll();
    if (!$pecas) {
        return [
            'capacidade_maxima' => 0,
            'gargalos' => [],
            'pecas' => [],
            'resumo_1un' => ['peso_total_gramas' => 0.0, 'tempo_total_segundos' => 0, 'tempo_formatado' => '00:00:00', 'cores' => []],
            'resumo_meta' => [
                'meta' => $meta, 'peso_total_gramas' => 0.0, 'tempo_total_segundos' => 0, 'tempo_formatado' => '00:00:00',
                'peso_faltante_gramas' => 0.0, 'tempo_faltante_segundos' => 0, 'tempo_faltante_formatado' => '00:00:00',
                'cores' => [], 'cores_faltante' => []
            ]
        ];
    }

    $pecaIds = array_column($pecas, 'peca_id');
    $in = implode(',', array_fill(0, count($pecaIds), '?'));
    $stmtCores = $pdo->prepare("
        SELECT id AS cor_id, peca_id, cor, estoque, foto, peso_gramas, tempo_producao_segundos
        FROM produto_pecas_cores WHERE peca_id IN ($in) ORDER BY id ASC
    ");
    $stmtCores->execute($pecaIds);
    $coresPorPeca = [];
    foreach ($stmtCores->fetchAll() as $c) {
        $c['tempo_formatado'] = $c['tempo_producao_segundos'] !== null ? formatarTempoHHMMSS((int)$c['tempo_producao_segundos']) : '';
        $coresPorPeca[(int) $c['peca_id']][] = $c;
    }

    $capacidadeMaxima = PHP_INT_MAX;
    $pesoTotal1un = 0.0;
    $tempoTotal1un = 0;
    $coresConsumo1un = [];

    foreach ($pecas as &$p) {
        $cores = $coresPorPeca[(int) $p['peca_id']] ?? [];
        $p['cores'] = $cores;
        $p['estoque_atual'] = (int) ($p['estoque'] ?? 0);
        $porUnidade = max(1, (int) $p['por_unidade']);
        $p['capacidade_individual'] = intdiv(max(0, $p['estoque_atual']), $porUnidade);
        $capacidadeMaxima = min($capacidadeMaxima, $p['capacidade_individual']);

        $pesoPeca = (float) ($p['peso_gramas'] ?? 0);
        $tempoPeca = (int) ($p['tempo_producao_segundos'] ?? 0);
        $p['peso_gramas'] = $pesoPeca;
        $p['tempo_producao_segundos'] = $tempoPeca;
        $p['tempo_formatado'] = formatarTempoHHMMSS($tempoPeca);

        $pesoTotal1un += $pesoPeca;
        $tempoTotal1un += $tempoPeca;

        if ($cores) {
            $qtdCores = count($cores);
            foreach ($cores as $c) {
                $cNome = trim((string) $c['cor']) ?: 'qualquer cor';
                $pCor = $c['peso_gramas'] !== null ? (float) $c['peso_gramas'] : ($pesoPeca > 0 ? ($pesoPeca / $qtdCores) : 0.0);
                $coresConsumo1un[$cNome] = ($coresConsumo1un[$cNome] ?? 0.0) + $pCor;
            }
        } else {
            $coresConsumo1un['qualquer cor'] = ($coresConsumo1un['qualquer cor'] ?? 0.0) + $pesoPeca;
        }
    }
    unset($p);
    if ($capacidadeMaxima === PHP_INT_MAX) $capacidadeMaxima = 0;

    $gargalos = [];
    $tempoMetaBruto = 0;
    $tempoMetaFaltante = 0;
    $pesoMetaBruto = $pesoTotal1un * $meta;
    $pesoMetaFaltante = 0.0;
    $coresConsumoFaltante = [];

    foreach ($pecas as &$p) {
        $porUnidade = max(1, (int) $p['por_unidade']);
        $p['total_necessario_meta'] = $meta * $porUnidade;
        $p['faltam_para_meta'] = max(0, $p['total_necessario_meta'] - $p['estoque_atual']);
        $p['sobra_apos_meta'] = max(0, $p['estoque_atual'] - $p['total_necessario_meta']);
        $p['situacao'] = $p['faltam_para_meta'] > 0 ? 'insuficiente' : 'ok';
        $p['eh_gargalo'] = ($p['capacidade_individual'] === $capacidadeMaxima);
        if ($p['eh_gargalo']) $gargalos[] = $p['nome'];

        $tempoPeca = (int) $p['tempo_producao_segundos'];
        $pesoPeca = (float) $p['peso_gramas'];

        $tempoPecaMetaTotal = $tempoPeca * $meta;
        $p['tempo_meta_total_segundos'] = $tempoPecaMetaTotal;
        $p['tempo_meta_total_formatado'] = formatarTempoHHMMSS($tempoPecaMetaTotal);
        $tempoMetaBruto += $tempoPecaMetaTotal;

        $tempoFaltantePeca = (int) round($tempoPeca * ($p['faltam_para_meta'] / $porUnidade));
        $p['tempo_meta_faltante_segundos'] = $tempoFaltantePeca;
        $p['tempo_meta_faltante_formatado'] = formatarTempoHHMMSS($tempoFaltantePeca);
        $tempoMetaFaltante += $tempoFaltantePeca;

        $pesoFaltantePeca = (float) ($pesoPeca * ($p['faltam_para_meta'] / $porUnidade));
        $pesoMetaFaltante += $pesoFaltantePeca;

        $cores = $p['cores'] ?? [];
        if ($cores) {
            $qtdCores = count($cores);
            foreach ($cores as $c) {
                $cNome = trim((string) $c['cor']) ?: 'qualquer cor';
                $pCor = $c['peso_gramas'] !== null ? (float) $c['peso_gramas'] : ($pesoPeca > 0 ? ($pesoPeca / $qtdCores) : 0.0);
                $coresConsumoFaltante[$cNome] = ($coresConsumoFaltante[$cNome] ?? 0.0) + ($pCor * ($p['faltam_para_meta'] / $porUnidade));
            }
        }
    }
    unset($p);

    $coresResumo1un = [];
    foreach ($coresConsumo1un as $cNome => $g) {
        $coresResumo1un[] = ['cor' => $cNome, 'peso_gramas' => round($g, 2)];
    }

    $coresResumoMeta = [];
    foreach ($coresConsumo1un as $cNome => $g) {
        $coresResumoMeta[] = ['cor' => $cNome, 'peso_gramas' => round($g * $meta, 2)];
    }

    $coresResumoFaltante = [];
    foreach ($coresConsumoFaltante as $cNome => $g) {
        $coresResumoFaltante[] = ['cor' => $cNome, 'peso_gramas' => round($g, 2)];
    }

    return [
        'capacidade_maxima' => $capacidadeMaxima,
        'gargalos' => $gargalos,
        'pecas' => $pecas,
        'resumo_1un' => [
            'peso_total_gramas' => round($pesoTotal1un, 2),
            'tempo_total_segundos' => $tempoTotal1un,
            'tempo_formatado' => formatarTempoHHMMSS($tempoTotal1un),
            'cores' => $coresResumo1un,
        ],
        'resumo_meta' => [
            'meta' => $meta,
            'peso_total_gramas' => round($pesoMetaBruto, 2),
            'tempo_total_segundos' => $tempoMetaBruto,
            'tempo_formatado' => formatarTempoHHMMSS($tempoMetaBruto),
            'peso_faltante_gramas' => round($pesoMetaFaltante, 2),
            'tempo_faltante_segundos' => $tempoMetaFaltante,
            'tempo_faltante_formatado' => formatarTempoHHMMSS($tempoMetaFaltante),
            'cores' => $coresResumoMeta,
            'cores_faltante' => $coresResumoFaltante,
        ],
    ];
}

// ------------------------------------------------------------
// GET /api/produtos_composicao.php?produto_pai_id=N&meta=M
// Retorna as peças cadastradas do produto (com suas cores), capacidade
// de montagem, diagnóstico de gargalos e cálculos de peso e tempo.
// ------------------------------------------------------------
if ($method === 'GET') {
    $paiId = (int) ($_GET['produto_pai_id'] ?? 0);
    $meta = max(1, (int) ($_GET['meta'] ?? 1));

    if (!$paiId) {
        jsonError('Informe o id do produto.');
    }

    $stmtProd = $pdo->prepare("SELECT id, nome, tipo, estoque, preco, peso_gramas, tempo_producao_segundos, ativo FROM produtos WHERE id = :id");
    $stmtProd->execute(['id' => $paiId]);
    $produto = $stmtProd->fetch();
    if (!$produto) {
        jsonError('Produto não encontrado.', 404);
    }
    $produto['tempo_producao_formatado'] = formatarTempoHHMMSS((int) ($produto['tempo_producao_segundos'] ?? 0));

    $diag = diagnosticoPecas($pdo, $paiId, $meta);

    jsonResponse([
        'produto' => $produto,
        'capacidade_maxima' => $diag['capacidade_maxima'],
        'meta' => $meta,
        'pode_atender_meta' => ($diag['capacidade_maxima'] >= $meta),
        'gargalos' => $diag['gargalos'],
        'pecas' => $diag['pecas'],
        'resumo_1un' => $diag['resumo_1un'],
        'resumo_meta' => $diag['resumo_meta'],
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
        $pesoPeca = max(0.0, (float) ($item['peso_gramas'] ?? 0));
        $tempoPeca = converterParaSegundos($item['tempo_producao_segundos'] ?? ($item['tempo'] ?? 0));
        $coresEntrada = is_array($item['cores'] ?? null) ? $item['cores'] : [];

        $cores = [];
        foreach ($coresEntrada as $c) {
            $corNome = trim($c['cor'] ?? '');
            $pesoCor = isset($c['peso_gramas']) && $c['peso_gramas'] !== '' && $c['peso_gramas'] !== null
                ? max(0.0, (float) $c['peso_gramas'])
                : null;
            $tempoCor = isset($c['tempo_producao_segundos']) && $c['tempo_producao_segundos'] !== '' && $c['tempo_producao_segundos'] !== null
                ? converterParaSegundos($c['tempo_producao_segundos'])
                : null;

            $cores[] = [
                'cor_id' => (int) ($c['cor_id'] ?? 0),
                'cor' => $corNome === '' ? null : $corNome,
                'estoque_inicial' => max(0, (int) ($c['estoque'] ?? 0)),
                'peso_gramas' => $pesoCor,
                'tempo_producao_segundos' => $tempoCor,
                'foto' => trim($c['foto'] ?? '') ?: null,
            ];
        }
        if (!$cores) {
            $cores[] = ['cor_id' => 0, 'cor' => null, 'estoque_inicial' => 0, 'peso_gramas' => null, 'tempo_producao_segundos' => null, 'foto' => null];
        }

        $pecasLimpas[] = [
            'peca_id' => (int) ($item['peca_id'] ?? 0),
            'nome' => $nome,
            'quantidade' => max(1, (int) ($item['quantidade'] ?? 1)),
            'estoque' => max(0, (int) ($item['estoque'] ?? 0)),
            'peso_gramas' => $pesoPeca,
            'tempo_producao_segundos' => $tempoPeca,
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
            UPDATE produto_pecas SET nome = :nome, quantidade = :qtd, peso_gramas = :peso,
                                    tempo_producao_segundos = :tempo, foto = :foto
            WHERE id = :id AND produto_id = :produto_id
        ");
        $stmtInsPeca = $pdo->prepare("
            INSERT INTO produto_pecas (produto_id, nome, quantidade, estoque, peso_gramas, tempo_producao_segundos, foto)
            VALUES (:produto_id, :nome, :qtd, :estoque, :peso, :tempo, :foto)
        ");

        // ---- Cores: upsert por id, sempre amarrado à peça (peca_id) ----
        $stmtCoresAtuais = $pdo->prepare("SELECT id, cor, estoque, peso_gramas, tempo_producao_segundos FROM produto_pecas_cores WHERE peca_id = :peca_id FOR UPDATE");
        $stmtUpdCor = $pdo->prepare("
            UPDATE produto_pecas_cores SET cor = :cor, peso_gramas = :peso, tempo_producao_segundos = :tempo, foto = :foto
            WHERE id = :id AND peca_id = :peca_id
        ");
        $stmtInsCor = $pdo->prepare("
            INSERT INTO produto_pecas_cores (peca_id, cor, estoque, peso_gramas, tempo_producao_segundos, foto)
            VALUES (:peca_id, :cor, :estoque, :peso, :tempo, :foto)
        ");

        $pecasMantidas = [];
        $totalCores = 0;
        foreach ($pecasLimpas as $p) {
            if ($p['peca_id'] && in_array($p['peca_id'], $idsAtuais, true)) {
                $stmtUpdPeca->execute([
                    'nome' => $p['nome'], 'qtd' => $p['quantidade'],
                    'peso' => $p['peso_gramas'], 'tempo' => $p['tempo_producao_segundos'],
                    'foto' => $p['foto'],
                    'id' => $p['peca_id'], 'produto_id' => $paiId,
                ]);
                $pecaId = $p['peca_id'];
            } else {
                $stmtInsPeca->execute([
                    'produto_id' => $paiId, 'nome' => $p['nome'],
                    'qtd' => $p['quantidade'],
                    'estoque' => $p['estoque'],
                    'peso' => $p['peso_gramas'], 'tempo' => $p['tempo_producao_segundos'],
                    'foto' => $p['foto'],
                ]);
                $pecaId = (int) $pdo->lastInsertId();
            }
            $pecasMantidas[] = $pecaId;

            $stmtCoresAtuais->execute(['peca_id' => $pecaId]);
            $coresAtuais = $stmtCoresAtuais->fetchAll(); // id, cor, estoque, peso_gramas
            $idsCoresAtuais = array_map(fn($c) => (int) $c['id'], $coresAtuais);

            $coresMantidas = [];
            foreach ($p['cores'] as $c) {
                if ($c['cor_id'] && in_array($c['cor_id'], $idsCoresAtuais, true)) {
                    $stmtUpdCor->execute([
                        'cor' => $c['cor'], 'peso' => $c['peso_gramas'], 'tempo' => $c['tempo_producao_segundos'], 'foto' => $c['foto'],
                        'id' => $c['cor_id'], 'peca_id' => $pecaId,
                    ]);
                    $coresMantidas[] = $c['cor_id'];
                } else {
                    $stmtInsCor->execute([
                        'peca_id' => $pecaId, 'cor' => $c['cor'],
                        'estoque' => $c['estoque_inicial'], 'peso' => $c['peso_gramas'], 'tempo' => $c['tempo_producao_segundos'], 'foto' => $c['foto'],
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
                SELECT pp.id, pp.nome, COALESCE(pp.estoque, 0) AS total
                FROM produto_pecas pp
                WHERE pp.id IN ($in)
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

        // Consolida o peso e tempo total no produto pai e atualiza para tipo 'composto' se tiver peças
        $stmtTotais = $pdo->prepare("
            SELECT COALESCE(SUM(peso_gramas), 0) AS total_peso,
                   COALESCE(SUM(tempo_producao_segundos), 0) AS total_tempo
            FROM produto_pecas WHERE produto_id = :id
        ");
        $stmtTotais->execute(['id' => $paiId]);
        $totais = $stmtTotais->fetch();

        $pdo->prepare("
            UPDATE produtos
            SET tipo = CASE WHEN :tem_pecas > 0 THEN 'composto' ELSE tipo END,
                peso_gramas = :peso,
                tempo_producao_segundos = :tempo
            WHERE id = :id
        ")->execute([
            'tem_pecas' => count($pecasLimpas),
            'peso' => $totais['total_peso'],
            'tempo' => $totais['total_tempo'],
            'id' => $paiId,
        ]);

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
