<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/estoque.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Valida e normaliza os campos enviados no cadastro/edição
function dadosProduto(array $b): array {
    $nome = trim($b['nome'] ?? '');
    if ($nome === '') jsonError('Nome do produto é obrigatório.');
    if (mb_strlen($nome) > 150) jsonError('O nome do produto deve ter no máximo 150 caracteres.');

    $tipo = trim($b['tipo'] ?? 'simples');
    if (!in_array($tipo, ['simples', 'composto', 'componente'], true)) {
        $tipo = 'simples';
    }

    $peso = $b['peso_gramas'] ?? 0;
    if ($peso === '' || $peso === null) $peso = 0;
    if (!is_numeric($peso) || $peso < 0) jsonError('Peso em gramas inválido.');

    $tempoSegundos = converterParaSegundos($b['tempo_producao_segundos'] ?? ($b['tempo_producao'] ?? 0));

    // Precificação e composição de custos
    $temEmbalagem = !empty($b['tem_embalagem']) ? 1 : 0;
    $valorEmbalagem = $temEmbalagem ? max(0.0, (float)($b['valor_embalagem'] ?? 0.0)) : 0.0;
    $valorOutros = max(0.0, (float)($b['valor_outros'] ?? 0.0));
    $margemLucro = isset($b['margem_lucro']) && is_numeric($b['margem_lucro']) ? (float)$b['margem_lucro'] : 100.0;
    $custoFilamento = max(0.0, (float)($b['custo_filamento'] ?? 0.0));

    // Custo Total Base
    $custoTotal = round($custoFilamento + $valorEmbalagem + $valorOutros, 2);

    // Se informou preço manual ou se calculou com a margem:
    $preco = $b['preco'] ?? null;
    if ($preco === '' || $preco === null) {
        $preco = round($custoTotal * (1 + ($margemLucro / 100)), 2);
    } else {
        $preco = round((float) $preco, 2);
    }
    if ($preco < 0) jsonError('Preço inválido.');

    $descricao = trim($b['descricao'] ?? '');
    return [
        'nome' => $nome,
        'tipo' => $tipo,
        'descricao' => $descricao === '' ? null : $descricao,
        'preco' => $preco,
        'estoque' => (int) ($b['estoque'] ?? 0),
        'peso_gramas' => round((float) $peso, 2),
        'tempo_producao_segundos' => $tempoSegundos,
        'tem_embalagem' => $temEmbalagem,
        'valor_embalagem' => $valorEmbalagem,
        'valor_outros' => $valorOutros,
        'margem_lucro' => $margemLucro,
        'custo_filamento' => $custoFilamento,
        'custo_total' => $custoTotal,
        'ativo' => array_key_exists('ativo', $b) ? (int) (bool) $b['ativo'] : 1,
    ];
}

function nomeDuplicado(PDO $pdo, string $nome, int $ignorarId = 0): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE nome = :nome AND id <> :id");
    $stmt->execute(['nome' => $nome, 'id' => $ignorarId]);
    return (int) $stmt->fetchColumn() > 0;
}

// ------------------------------------------------------------
// GET /api/produtos.php            -> só ativos (usado na tela de pedido)
// GET /api/produtos.php?todos=1    -> todos, com contagem de pedidos e componentes
// GET /api/produtos.php?tipo=componente -> filtrar por tipo
// GET /api/produtos.php?id=N       -> um produto
// ------------------------------------------------------------
if ($method === 'GET') {
    if (!empty($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = :id");
        $stmt->execute(['id' => $_GET['id']]);
        $produto = $stmt->fetch();
        if (!$produto) jsonError('Produto não encontrado.', 404);

        // Verifica se o produto tem peças cadastradas na ficha técnica
        $stmtPecas = $pdo->prepare("
            SELECT id, nome, quantidade, peso_gramas, tempo_producao_segundos, foto
            FROM produto_pecas WHERE produto_id = :id ORDER BY id ASC
        ");
        $stmtPecas->execute(['id' => $_GET['id']]);
        $pecas = $stmtPecas->fetchAll();

        $produto['tem_pecas'] = count($pecas) > 0;
        $produto['qtd_pecas'] = count($pecas);

        if ($produto['tem_pecas']) {
            $somaPeso = 0.0;
            $somaTempo = 0;
            // Carrega cores de cada peça
            $pecaIds = array_column($pecas, 'id');
            $in = implode(',', array_fill(0, count($pecaIds), '?'));
            $stmtCoresPeca = $pdo->prepare("
                SELECT id, peca_id, cor, estoque, peso_gramas, tempo_producao_segundos, foto
                FROM produto_pecas_cores WHERE peca_id IN ($in) ORDER BY id ASC
            ");
            $stmtCoresPeca->execute($pecaIds);
            $coresPorPeca = [];
            foreach ($stmtCoresPeca->fetchAll() as $cp) {
                $coresPorPeca[(int)$cp['peca_id']][] = [
                    'cor_id' => (int)$cp['id'],
                    'cor' => $cp['cor'],
                    'estoque' => (int)$cp['estoque'],
                    'peso_gramas' => $cp['peso_gramas'] !== null ? (float)$cp['peso_gramas'] : null,
                    'tempo_producao_segundos' => $cp['tempo_producao_segundos'] !== null ? (int)$cp['tempo_producao_segundos'] : null,
                    'tempo_formatado' => $cp['tempo_producao_segundos'] !== null ? formatarTempoHHMMSS((int)$cp['tempo_producao_segundos']) : '',
                ];
            }

            foreach ($pecas as &$pec) {
                $somaPeso += (float)$pec['peso_gramas'];
                $somaTempo += (int)$pec['tempo_producao_segundos'];
                $pec['tempo_formatado'] = formatarTempoHHMMSS((int)$pec['tempo_producao_segundos']);
                $pec['cores'] = $coresPorPeca[(int)$pec['id']] ?? [];
            }
            unset($pec);

            $produto['pecas'] = $pecas;
            $produto['peso_gramas'] = round($somaPeso, 2);
            $produto['tempo_producao_segundos'] = $somaTempo;
            $produto['cores'] = [];
            $produto['tem_multicor'] = false;
        } else {
            $produto['pecas'] = [];
            // Se não tem peças, verifica se tem cores cadastradas em produto_cores
            $stmtCores = $pdo->prepare("SELECT id, cor, peso_gramas, tempo_producao_segundos FROM produto_cores WHERE produto_id = :id ORDER BY id ASC");
            $stmtCores->execute(['id' => $_GET['id']]);
            $coresProd = $stmtCores->fetchAll();
            $somaPeso = 0.0;
            $somaTempo = 0;
            foreach ($coresProd as &$cp) {
                $somaPeso += (float)$cp['peso_gramas'];
                $somaTempo += (int)$cp['tempo_producao_segundos'];
                $cp['tempo_formatado'] = formatarTempoHHMMSS((int)$cp['tempo_producao_segundos']);
            }
            unset($cp);
            $produto['cores'] = $coresProd;
            $produto['tem_multicor'] = count($coresProd) > 0;
            if (count($coresProd) > 0) {
                $produto['peso_gramas'] = round($somaPeso, 2);
                $produto['tempo_producao_segundos'] = $somaTempo;
            }
        }

        $produto['tempo_producao_formatado'] = formatarTempoHHMMSS((int) ($produto['tempo_producao_segundos'] ?? 0));

        // Enriquece com o cálculo dinâmico de custo de filamento baseado no PEPS atual
        $infoCusto = calcularCustoFilamentoProduto($pdo, (int)$_GET['id']);
        $produto['calculo_custo_filamento'] = $infoCusto;
        if (!empty($infoCusto['peso_total_gramas'])) {
            $produto['peso_gramas_calculado'] = $infoCusto['peso_total_gramas'];
        }

        jsonResponse($produto);
    }

    if (!empty($_GET['todos'])) {
        $stmt = $pdo->query("
            SELECT p.*,
                   COUNT(DISTINCT pi.pedido_id) AS qtd_pedidos,
                   COALESCE(SUM(pi.quantidade_produzida), 0) AS qtd_produzida,
                   COUNT(DISTINCT pp.id) AS qtd_pecas,
                   (SELECT COUNT(*) FROM produto_cores pc WHERE pc.produto_id = p.id) AS qtd_cores
            FROM produtos p
            LEFT JOIN pedido_itens pi ON pi.produto_id = p.id
            LEFT JOIN produto_pecas pp ON pp.produto_id = p.id
            GROUP BY p.id
            ORDER BY p.ativo DESC, p.nome
        ");
        $prods = $stmt->fetchAll();
        foreach ($prods as &$p) {
            $p['tempo_producao_formatado'] = formatarTempoHHMMSS((int) ($p['tempo_producao_segundos'] ?? 0));
            $p['tem_pecas'] = (int)$p['qtd_pecas'] > 0;
            $p['tem_multicor'] = (int)$p['qtd_cores'] > 0;
        }
        unset($p);
        jsonResponse($prods);
    }

    if (!empty($_GET['tipo'])) {
        $tipo = $_GET['tipo'];
        $ignorarId = (int) ($_GET['ignorar_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, nome, tipo, descricao, preco, estoque, peso_gramas, tempo_producao_segundos, tem_embalagem, valor_embalagem, valor_outros, margem_lucro, custo_filamento, custo_total FROM produtos WHERE ativo = 1 AND tipo = :tipo AND id <> :ignorar ORDER BY nome");
        $stmt->execute(['tipo' => $tipo, 'ignorar' => $ignorarId]);
        $prods = $stmt->fetchAll();
        foreach ($prods as &$p) {
            $p['tempo_producao_formatado'] = formatarTempoHHMMSS((int) ($p['tempo_producao_segundos'] ?? 0));
        }
        unset($p);
        jsonResponse($prods);
    }

    // Listar para componentes de BOM (peças ou simples, exceto o próprio produto pai)
    if (!empty($_GET['para_composicao'])) {
        $ignorarId = (int) ($_GET['ignorar_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT id, nome, tipo, descricao, preco, estoque, peso_gramas, tempo_producao_segundos 
            FROM produtos 
            WHERE ativo = 1 AND id <> :ignorar AND tipo IN ('componente', 'simples')
            ORDER BY tipo DESC, nome ASC
        ");
        $stmt->execute(['ignorar' => $ignorarId]);
        $prods = $stmt->fetchAll();
        foreach ($prods as &$p) {
            $p['tempo_producao_formatado'] = formatarTempoHHMMSS((int) ($p['tempo_producao_segundos'] ?? 0));
        }
        unset($p);
        jsonResponse($prods);
    }

    $stmt = $pdo->query("SELECT id, nome, tipo, descricao, preco, estoque, peso_gramas, tempo_producao_segundos, tem_embalagem, valor_embalagem, valor_outros, margem_lucro, custo_filamento, custo_total FROM produtos WHERE ativo = 1 ORDER BY nome");
    $prods = $stmt->fetchAll();
    foreach ($prods as &$p) {
        $p['tempo_producao_formatado'] = formatarTempoHHMMSS((int) ($p['tempo_producao_segundos'] ?? 0));
        $cons = calcularConsumoProduto($pdo, (int)$p['id'], 1);
        $p['consumo_cores'] = $cons['cores'] ?? [];
        if (!empty($cons['peso_gramas_1un'])) {
            $p['peso_gramas'] = $cons['peso_gramas_1un'];
        }
        if (!empty($cons['tempo_segundos_1un'])) {
            $p['tempo_producao_segundos'] = $cons['tempo_segundos_1un'];
            $p['tempo_producao_formatado'] = $cons['tempo_formatado_1un'];
        }
    }
    unset($p);
    jsonResponse($prods);
}

// ------------------------------------------------------------
// POST /api/produtos.php   Body com dados de produto e composição de custos
// ------------------------------------------------------------
if ($method === 'POST') {
    $raw = readJsonBody();
    $d = dadosProduto($raw);
    if (nomeDuplicado($pdo, $d['nome'])) jsonError('Já existe um produto com esse nome.');

    // 1. Verifica se foram enviadas peças para o produto
    $pecasEntrada = is_array($raw['pecas'] ?? null) ? $raw['pecas'] : [];
    $pecasValidas = [];
    foreach ($pecasEntrada as $item) {
        $pNome = trim($item['nome'] ?? '');
        if ($pNome === '') continue;
        $pPeso = max(0.0, (float)($item['peso_gramas'] ?? 0));
        $pTempo = converterParaSegundos($item['tempo_producao_segundos'] ?? ($item['tempo'] ?? 0));
        $pecasValidas[] = [
            'nome' => $pNome,
            'quantidade' => max(1, (int)($item['quantidade'] ?? 1)),
            'peso_gramas' => $pPeso,
            'tempo_producao_segundos' => $pTempo,
            'foto' => trim($item['foto'] ?? '') ?: null,
            'cores' => is_array($item['cores'] ?? null) ? $item['cores'] : [],
        ];
    }

    // 2. Se tem peças, o peso e tempo do produto DEVEM ser a soma das peças
    if (!empty($pecasValidas)) {
        $d['tipo'] = 'composto';
        $d['peso_gramas'] = round(array_sum(array_column($pecasValidas, 'peso_gramas')), 2);
        $d['tempo_producao_segundos'] = (int)array_sum(array_column($pecasValidas, 'tempo_producao_segundos'));
    } else {
        // 3. Se NÃO tem peças: verifica se é produto multicor (tem mais de uma cor)
        $coresEntrada = is_array($raw['cores'] ?? null) ? $raw['cores'] : [];
        $coresValidas = [];
        foreach ($coresEntrada as $c) {
            $cNome = trim($c['cor'] ?? '');
            if ($cNome === '') continue;
            $cPeso = max(0.0, (float)($c['peso_gramas'] ?? 0));
            $cTempo = converterParaSegundos($c['tempo_producao_segundos'] ?? ($c['tempo'] ?? 0));
            $coresValidas[] = [
                'cor' => $cNome,
                'peso_gramas' => $cPeso,
                'tempo_producao_segundos' => $cTempo,
            ];
        }

        // Se tem cores cadastradas (multicor), o peso e o tempo DEVEM ser a soma das cores
        if (!empty($coresValidas)) {
            $d['peso_gramas'] = round(array_sum(array_column($coresValidas, 'peso_gramas')), 2);
            $d['tempo_producao_segundos'] = (int)array_sum(array_column($coresValidas, 'tempo_producao_segundos'));
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            INSERT INTO produtos 
                (nome, tipo, descricao, preco, estoque, peso_gramas, tempo_producao_segundos, tem_embalagem, valor_embalagem, valor_outros, margem_lucro, custo_filamento, custo_total, ativo)
            VALUES 
                (:nome, :tipo, :descricao, :preco, :estoque, :peso_gramas, :tempo_producao_segundos, :tem_embalagem, :valor_embalagem, :valor_outros, :margem_lucro, :custo_filamento, :custo_total, :ativo)
        ");
        $stmt->execute($d);
        $novoId = (int) $pdo->lastInsertId();

        // Se tem peças, insere as peças e suas cores
        if (!empty($pecasValidas)) {
            $stmtInsPeca = $pdo->prepare("
                INSERT INTO produto_pecas (produto_id, nome, quantidade, peso_gramas, tempo_producao_segundos, foto)
                VALUES (:produto_id, :nome, :qtd, :peso, :tempo, :foto)
            ");
            $stmtInsCorPeca = $pdo->prepare("
                INSERT INTO produto_pecas_cores (peca_id, cor, estoque, peso_gramas, tempo_producao_segundos, foto)
                VALUES (:peca_id, :cor, :estoque, :peso, :tempo, :foto)
            ");
            foreach ($pecasValidas as $pv) {
                $stmtInsPeca->execute([
                    'produto_id' => $novoId,
                    'nome' => $pv['nome'],
                    'qtd' => $pv['quantidade'],
                    'peso' => $pv['peso_gramas'],
                    'tempo' => $pv['tempo_producao_segundos'],
                    'foto' => $pv['foto'],
                ]);
                $pecaId = (int)$pdo->lastInsertId();
                $coresPeca = $pv['cores'];
                if (empty($coresPeca)) {
                    $coresPeca = [['cor' => null, 'estoque' => 0, 'peso_gramas' => null, 'tempo_producao_segundos' => null]];
                }
                foreach ($coresPeca as $cp) {
                    $stmtInsCorPeca->execute([
                        'peca_id' => $pecaId,
                        'cor' => trim($cp['cor'] ?? '') ?: null,
                        'estoque' => max(0, (int)($cp['estoque'] ?? 0)),
                        'peso' => isset($cp['peso_gramas']) && $cp['peso_gramas'] !== '' ? max(0.0, (float)$cp['peso_gramas']) : null,
                        'tempo' => isset($cp['tempo_producao_segundos']) && $cp['tempo_producao_segundos'] !== '' ? converterParaSegundos($cp['tempo_producao_segundos']) : null,
                        'foto' => trim($cp['foto'] ?? '') ?: null,
                    ]);
                }
            }
        } elseif (!empty($coresValidas)) {
            // Se não tem peças e tem cores diretas de produto (multicor)
            $stmtInsCor = $pdo->prepare("
                INSERT INTO produto_cores (produto_id, cor, peso_gramas, tempo_producao_segundos)
                VALUES (:produto_id, :cor, :peso_gramas, :tempo_producao_segundos)
            ");
            foreach ($coresValidas as $cv) {
                $stmtInsCor->execute([
                    'produto_id' => $novoId,
                    'cor' => $cv['cor'],
                    'peso_gramas' => $cv['peso_gramas'],
                    'tempo_producao_segundos' => $cv['tempo_producao_segundos'],
                ]);
            }
        }

        $pdo->commit();
        jsonResponse(['id' => $novoId], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao cadastrar produto: ' . $e->getMessage(), 500);
    }
}

// ------------------------------------------------------------
// PUT /api/produtos.php?id=N   Body com dados atualizados
// ------------------------------------------------------------
if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('Informe o id do produto.');
    $raw = readJsonBody();
    $d = dadosProduto($raw);

    $existe = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE id = :id");
    $existe->execute(['id' => $id]);
    if (!(int) $existe->fetchColumn()) jsonError('Produto não encontrado.', 404);
    if (nomeDuplicado($pdo, $d['nome'], $id)) jsonError('Já existe outro produto com esse nome.');

    // Verifica se já existem peças cadastradas no banco
    $stmtQtdPecas = $pdo->prepare("SELECT COUNT(*) FROM produto_pecas WHERE produto_id = :id");
    $stmtQtdPecas->execute(['id' => $id]);
    $qtdPecasBanco = (int)$stmtQtdPecas->fetchColumn();

    // Peças enviadas no payload
    $pecasEntrada = is_array($raw['pecas'] ?? null) ? $raw['pecas'] : null;
    $temPecas = false;

    if ($pecasEntrada !== null) {
        // Usuário enviou lista de peças explicitamente
        $pecasValidas = [];
        foreach ($pecasEntrada as $item) {
            $pNome = trim($item['nome'] ?? '');
            if ($pNome === '') continue;
            $pecasValidas[] = [
                'peca_id' => (int)($item['peca_id'] ?? ($item['id'] ?? 0)),
                'nome' => $pNome,
                'quantidade' => max(1, (int)($item['quantidade'] ?? 1)),
                'peso_gramas' => max(0.0, (float)($item['peso_gramas'] ?? 0)),
                'tempo_producao_segundos' => converterParaSegundos($item['tempo_producao_segundos'] ?? ($item['tempo'] ?? 0)),
                'foto' => trim($item['foto'] ?? '') ?: null,
                'cores' => is_array($item['cores'] ?? null) ? $item['cores'] : [],
            ];
        }
        $temPecas = count($pecasValidas) > 0;
        if ($temPecas) {
            $d['tipo'] = 'composto';
            $d['peso_gramas'] = round(array_sum(array_column($pecasValidas, 'peso_gramas')), 2);
            $d['tempo_producao_segundos'] = (int)array_sum(array_column($pecasValidas, 'tempo_producao_segundos'));
        }
    } elseif ($qtdPecasBanco > 0) {
        // Não enviou pecas no payload, mas o produto já tem peças no banco:
        // Mantém a regra estrita: tempo e filamento são a soma das peças existentes!
        $d['tipo'] = 'composto';
        $stmtSoma = $pdo->prepare("
            SELECT COALESCE(SUM(peso_gramas), 0) AS total_peso,
                   COALESCE(SUM(tempo_producao_segundos), 0) AS total_tempo
            FROM produto_pecas WHERE produto_id = :id
        ");
        $stmtSoma->execute(['id' => $id]);
        $soma = $stmtSoma->fetch();
        $d['peso_gramas'] = round((float)$soma['total_peso'], 2);
        $d['tempo_producao_segundos'] = (int)$soma['total_tempo'];
        $temPecas = true;
    }

    $coresValidas = [];
    if (!$temPecas) {
        // Se NÃO tem peças: verifica se foram enviadas cores em produto_cores
        $coresEntrada = is_array($raw['cores'] ?? null) ? $raw['cores'] : [];
        foreach ($coresEntrada as $c) {
            $cNome = trim($c['cor'] ?? '');
            if ($cNome === '') continue;
            $coresValidas[] = [
                'id' => (int)($c['id'] ?? 0),
                'cor' => $cNome,
                'peso_gramas' => max(0.0, (float)($c['peso_gramas'] ?? 0)),
                'tempo_producao_segundos' => converterParaSegundos($c['tempo_producao_segundos'] ?? ($c['tempo'] ?? 0)),
            ];
        }

        if (!empty($coresValidas)) {
            $d['peso_gramas'] = round(array_sum(array_column($coresValidas, 'peso_gramas')), 2);
            $d['tempo_producao_segundos'] = (int)array_sum(array_column($coresValidas, 'tempo_producao_segundos'));
        }
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            UPDATE produtos 
            SET nome = :nome, tipo = :tipo, descricao = :descricao, preco = :preco, estoque = :estoque,
                peso_gramas = :peso_gramas, tempo_producao_segundos = :tempo_producao_segundos,
                tem_embalagem = :tem_embalagem, valor_embalagem = :valor_embalagem, valor_outros = :valor_outros,
                margem_lucro = :margem_lucro, custo_filamento = :custo_filamento, custo_total = :custo_total,
                ativo = :ativo
            WHERE id = :id
        ");
        $stmt->execute($d + ['id' => $id]);

        // Se enviou peças no PUT, atualiza peças e cores de peças
        if ($pecasEntrada !== null) {
            $stmtAtuais = $pdo->prepare("SELECT id FROM produto_pecas WHERE produto_id = :pai_id");
            $stmtAtuais->execute(['pai_id' => $id]);
            $idsAtuais = array_map('intval', $stmtAtuais->fetchAll(PDO::FETCH_COLUMN));

            $stmtUpdPeca = $pdo->prepare("
                UPDATE produto_pecas SET nome = :nome, quantidade = :qtd, peso_gramas = :peso,
                                        tempo_producao_segundos = :tempo, foto = :foto
                WHERE id = :id AND produto_id = :produto_id
            ");
            $stmtInsPeca = $pdo->prepare("
                INSERT INTO produto_pecas (produto_id, nome, quantidade, peso_gramas, tempo_producao_segundos, foto)
                VALUES (:produto_id, :nome, :qtd, :peso, :tempo, :foto)
            ");

            $stmtCoresAtuais = $pdo->prepare("SELECT id FROM produto_pecas_cores WHERE peca_id = :peca_id");
            $stmtUpdCor = $pdo->prepare("
                UPDATE produto_pecas_cores SET cor = :cor, peso_gramas = :peso, tempo_producao_segundos = :tempo, foto = :foto
                WHERE id = :id AND peca_id = :peca_id
            ");
            $stmtInsCor = $pdo->prepare("
                INSERT INTO produto_pecas_cores (peca_id, cor, estoque, peso_gramas, tempo_producao_segundos, foto)
                VALUES (:peca_id, :cor, :estoque, :peso, :tempo, :foto)
            ");

            $pecasMantidas = [];
            foreach ($pecasValidas as $pv) {
                if ($pv['peca_id'] && in_array($pv['peca_id'], $idsAtuais, true)) {
                    $stmtUpdPeca->execute([
                        'nome' => $pv['nome'], 'qtd' => $pv['quantidade'],
                        'peso' => $pv['peso_gramas'], 'tempo' => $pv['tempo_producao_segundos'],
                        'foto' => $pv['foto'], 'id' => $pv['peca_id'], 'produto_id' => $id,
                    ]);
                    $pecaId = $pv['peca_id'];
                } else {
                    $stmtInsPeca->execute([
                        'produto_id' => $id, 'nome' => $pv['nome'], 'qtd' => $pv['quantidade'],
                        'peso' => $pv['peso_gramas'], 'tempo' => $pv['tempo_producao_segundos'],
                        'foto' => $pv['foto'],
                    ]);
                    $pecaId = (int)$pdo->lastInsertId();
                }
                $pecasMantidas[] = $pecaId;

                $stmtCoresAtuais->execute(['peca_id' => $pecaId]);
                $idsCoresAtuais = array_map('intval', $stmtCoresAtuais->fetchAll(PDO::FETCH_COLUMN));
                $coresMantidas = [];
                $coresPeca = $pv['cores'] ?: [['cor' => null, 'estoque' => 0, 'peso_gramas' => null, 'tempo_producao_segundos' => null]];

                foreach ($coresPeca as $cp) {
                    $cId = (int)($cp['cor_id'] ?? ($cp['id'] ?? 0));
                    $pCor = isset($cp['peso_gramas']) && $cp['peso_gramas'] !== '' ? max(0.0, (float)$cp['peso_gramas']) : null;
                    $tCor = isset($cp['tempo_producao_segundos']) && $cp['tempo_producao_segundos'] !== '' ? converterParaSegundos($cp['tempo_producao_segundos']) : null;
                    if ($cId && in_array($cId, $idsCoresAtuais, true)) {
                        $stmtUpdCor->execute([
                            'cor' => trim($cp['cor'] ?? '') ?: null,
                            'peso' => $pCor,
                            'tempo' => $tCor,
                            'foto' => trim($cp['foto'] ?? '') ?: null,
                            'id' => $cId, 'peca_id' => $pecaId
                        ]);
                        $coresMantidas[] = $cId;
                    } else {
                        $stmtInsCor->execute([
                            'peca_id' => $pecaId,
                            'cor' => trim($cp['cor'] ?? '') ?: null,
                            'estoque' => max(0, (int)($cp['estoque'] ?? 0)),
                            'peso' => $pCor,
                            'tempo' => $tCor,
                            'foto' => trim($cp['foto'] ?? '') ?: null
                        ]);
                    }
                }
                $removerCores = array_diff($idsCoresAtuais, $coresMantidas);
                if ($removerCores) {
                    $inC = implode(',', array_fill(0, count($removerCores), '?'));
                    $pdo->prepare("DELETE FROM produto_pecas_cores WHERE peca_id = ? AND id IN ($inC)")
                        ->execute(array_merge([$pecaId], array_values($removerCores)));
                }
            }

            $removerPecas = array_diff($idsAtuais, $pecasMantidas);
            if ($removerPecas) {
                $inP = implode(',', array_fill(0, count($removerPecas), '?'));
                $pdo->prepare("DELETE FROM produto_pecas WHERE produto_id = ? AND id IN ($inP)")
                    ->execute(array_merge([$id], array_values($removerPecas)));
            }
        }

        // Se NÃO tem peças, sincroniza produto_cores
        if (!$temPecas) {
            $pdo->prepare("DELETE FROM produto_cores WHERE produto_id = :id")->execute(['id' => $id]);
            if (!empty($coresValidas)) {
                $stmtInsCor = $pdo->prepare("
                    INSERT INTO produto_cores (produto_id, cor, peso_gramas, tempo_producao_segundos)
                    VALUES (:produto_id, :cor, :peso_gramas, :tempo_producao_segundos)
                ");
                foreach ($coresValidas as $cv) {
                    $stmtInsCor->execute([
                        'produto_id' => $id,
                        'cor' => $cv['cor'],
                        'peso_gramas' => $cv['peso_gramas'],
                        'tempo_producao_segundos' => $cv['tempo_producao_segundos'],
                    ]);
                }
            }
        }

        $pdo->commit();
        jsonResponse(['id' => $id]);
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonError('Erro ao atualizar produto: ' . $e->getMessage(), 500);
    }
}

// ------------------------------------------------------------
// PATCH /api/produtos.php?id=N   Body: { ativo: true|false }
// Produto inativo some da tela de pedido, mas continua nos pedidos antigos.
// ------------------------------------------------------------
if ($method === 'PATCH') {
    $id = (int) ($_GET['id'] ?? 0);
    $b = readJsonBody();
    if (!$id || !array_key_exists('ativo', $b)) jsonError('Informe o id e o campo ativo.');

    $stmt = $pdo->prepare("UPDATE produtos SET ativo = :ativo WHERE id = :id");
    $stmt->execute(['ativo' => (int) (bool) $b['ativo'], 'id' => $id]);
    if ($stmt->rowCount() === 0) {
        $existe = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE id = :id");
        $existe->execute(['id' => $id]);
        if (!(int) $existe->fetchColumn()) jsonError('Produto não encontrado.', 404);
    }
    jsonResponse(['id' => $id, 'ativo' => (bool) $b['ativo']]);
}

// ------------------------------------------------------------
// DELETE /api/produtos.php?id=N
// Só exclui produtos que nunca foram usados em pedidos ou em fichas técnicas;
// os demais devem ser desativados para preservar o histórico.
// ------------------------------------------------------------
if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('Informe o id do produto.');

    $uso = $pdo->prepare("SELECT COUNT(*) FROM pedido_itens WHERE produto_id = :id");
    $uso->execute(['id' => $id]);
    if ((int) $uso->fetchColumn() > 0) {
        jsonError('Este produto já foi usado em pedidos e não pode ser excluído. Desative-o para que não apareça mais nos pedidos.');
    }

    // As peças do produto saem junto (produto_pecas tem ON DELETE CASCADE).
    $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = :id");
    $stmt->execute(['id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Produto não encontrado.', 404);
    jsonResponse(['ok' => true]);
}


jsonError('Método não suportado.', 405);
