<?php
require_once __DIR__ . '/../includes/auth.php';
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

    $preco = $b['preco'] ?? 0;
    if ($preco === '' || $preco === null) $preco = 0;
    if (!is_numeric($preco) || $preco < 0) jsonError('Preço inválido.');

    $descricao = trim($b['descricao'] ?? '');
    return [
        'nome' => $nome,
        'tipo' => $tipo,
        'descricao' => $descricao === '' ? null : $descricao,
        'preco' => round((float) $preco, 2),
        'estoque' => (int) ($b['estoque'] ?? 0),
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
        jsonResponse($produto);
    }

    if (!empty($_GET['todos'])) {
        $stmt = $pdo->query("
            SELECT p.*,
                   COUNT(DISTINCT pi.pedido_id) AS qtd_pedidos,
                   COALESCE(SUM(pi.quantidade_produzida), 0) AS qtd_produzida,
                   COUNT(DISTINCT pp.id) AS qtd_pecas
            FROM produtos p
            LEFT JOIN pedido_itens pi ON pi.produto_id = p.id
            LEFT JOIN produto_pecas pp ON pp.produto_id = p.id
            GROUP BY p.id
            ORDER BY p.ativo DESC, p.nome

        ");
        jsonResponse($stmt->fetchAll());
    }

    if (!empty($_GET['tipo'])) {
        $tipo = $_GET['tipo'];
        $ignorarId = (int) ($_GET['ignorar_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT id, nome, tipo, descricao, preco, estoque FROM produtos WHERE ativo = 1 AND tipo = :tipo AND id <> :ignorar ORDER BY nome");
        $stmt->execute(['tipo' => $tipo, 'ignorar' => $ignorarId]);
        jsonResponse($stmt->fetchAll());
    }

    // Listar para componentes de BOM (peças ou simples, exceto o próprio produto pai)
    if (!empty($_GET['para_composicao'])) {
        $ignorarId = (int) ($_GET['ignorar_id'] ?? 0);
        $stmt = $pdo->prepare("
            SELECT id, nome, tipo, descricao, preco, estoque 
            FROM produtos 
            WHERE ativo = 1 AND id <> :ignorar AND tipo IN ('componente', 'simples')
            ORDER BY tipo DESC, nome ASC
        ");
        $stmt->execute(['ignorar' => $ignorarId]);
        jsonResponse($stmt->fetchAll());
    }

    $stmt = $pdo->query("SELECT id, nome, tipo, descricao, preco, estoque FROM produtos WHERE ativo = 1 ORDER BY nome");
    jsonResponse($stmt->fetchAll());
}

// ------------------------------------------------------------
// POST /api/produtos.php   Body: { nome, tipo, descricao, preco, estoque, ativo }
// ------------------------------------------------------------
if ($method === 'POST') {
    $d = dadosProduto(readJsonBody());
    if (nomeDuplicado($pdo, $d['nome'])) jsonError('Já existe um produto com esse nome.');

    $stmt = $pdo->prepare("INSERT INTO produtos (nome, tipo, descricao, preco, estoque, ativo) VALUES (:nome, :tipo, :descricao, :preco, :estoque, :ativo)");
    $stmt->execute($d);
    jsonResponse(['id' => (int) $pdo->lastInsertId()], 201);
}

// ------------------------------------------------------------
// PUT /api/produtos.php?id=N   Body: { nome, tipo, descricao, preco, estoque, ativo }
// ------------------------------------------------------------
if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (!$id) jsonError('Informe o id do produto.');
    $d = dadosProduto(readJsonBody());

    $existe = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE id = :id");
    $existe->execute(['id' => $id]);
    if (!(int) $existe->fetchColumn()) jsonError('Produto não encontrado.', 404);
    if (nomeDuplicado($pdo, $d['nome'], $id)) jsonError('Já existe outro produto com esse nome.');

    $stmt = $pdo->prepare("UPDATE produtos SET nome = :nome, tipo = :tipo, descricao = :descricao, preco = :preco, estoque = :estoque, ativo = :ativo WHERE id = :id");
    $stmt->execute($d + ['id' => $id]);
    jsonResponse(['id' => $id]);
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

    $usoBOM = $pdo->prepare("SELECT COUNT(*) FROM produto_composicao WHERE componente_id = :id");
    $usoBOM->execute(['id' => $id]);
    if ((int) $usoBOM->fetchColumn() > 0) {
        jsonError('Este item é componente de um produto composto e não pode ser excluído. Remova-o da ficha técnica primeiro ou desative-o.');
    }

    $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = :id");
    $stmt->execute(['id' => $id]);
    if ($stmt->rowCount() === 0) jsonError('Produto não encontrado.', 404);
    jsonResponse(['ok' => true]);
}


jsonError('Método não suportado.', 405);
