<?php
require_once __DIR__ . '/../includes/auth.php';
$usuario = exigirLoginApi();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// Leitura da lista: qualquer usuário logado (usada no filtro dos relatórios).
// Cadastro e alterações: só administradores.
if ($method !== 'GET') {
    $usuario = exigirAdminApi();
}

function buscarUsuario(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare("SELECT id, nome, login, admin, ativo FROM usuarios WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $u = $stmt->fetch();
    if (!$u) jsonError('Usuário não encontrado.', 404);
    return $u;
}

function contarAdminsAtivos(PDO $pdo): int {
    return (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo = 1 AND admin = 1")->fetchColumn();
}

// ------------------------------------------------------------
// GET /api/usuarios.php
//   admin     -> lista completa (sem senha), com contagem de uso
//   não admin -> só id, nome e ativo
// ------------------------------------------------------------
if ($method === 'GET') {
    if (!$usuario['admin']) {
        $stmt = $pdo->query("SELECT id, nome, ativo FROM usuarios ORDER BY ativo DESC, nome");
        jsonResponse(['usuarios' => $stmt->fetchAll(), 'eu' => (int) $usuario['id']]);
    }
    $stmt = $pdo->query("
        SELECT u.id, u.nome, u.login, u.admin, u.ativo, u.ultimo_acesso, u.criado_em,
               (SELECT COUNT(*) FROM pedidos p WHERE p.usuario_id = u.id) AS qtd_pedidos,
               (SELECT COUNT(*) FROM producoes pr WHERE pr.usuario_id = u.id) AS qtd_producoes
        FROM usuarios u
        ORDER BY u.ativo DESC, u.nome
    ");
    jsonResponse(['usuarios' => $stmt->fetchAll(), 'eu' => (int) $usuario['id']]);
}

// ------------------------------------------------------------
// POST /api/usuarios.php   Body: { nome, login, senha, admin }
// ------------------------------------------------------------
if ($method === 'POST') {
    try {
        $id = inserirUsuario($pdo, dadosUsuario(readJsonBody(), true));
    } catch (Exception $e) {
        jsonError($e->getMessage());
    }
    jsonResponse(['id' => $id], 201);
}

// ------------------------------------------------------------
// PUT /api/usuarios.php?id=N   Body: { nome, login, senha?, admin }
// Senha em branco mantém a atual.
// ------------------------------------------------------------
if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    $alvo = buscarUsuario($pdo, $id);
    try {
        $d = dadosUsuario(readJsonBody(), false);
        if (loginEmUso($pdo, $d['login'], $id)) throw new Exception('Esse login já está em uso.');
        if ((int) $alvo['admin'] && !$d['admin']) {
            if ($id === (int) $usuario['id']) throw new Exception('Você não pode remover o seu próprio acesso de administrador.');
            if ((int) $alvo['ativo'] && contarAdminsAtivos($pdo) <= 1) throw new Exception('É preciso manter pelo menos um administrador ativo.');
        }
    } catch (Exception $e) {
        jsonError($e->getMessage());
    }

    $sets = ['nome = :nome', 'login = :login', 'admin = :admin'];
    if (isset($d['senha_hash'])) $sets[] = 'senha_hash = :senha_hash';
    $stmt = $pdo->prepare('UPDATE usuarios SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute($d + ['id' => $id]);
    jsonResponse(['id' => $id]);
}

// ------------------------------------------------------------
// PATCH /api/usuarios.php?id=N   Body: { ativo: true|false }
// Usuários não são excluídos (ficam no histórico de pedidos e produção);
// desativar bloqueia o login imediatamente.
// ------------------------------------------------------------
if ($method === 'PATCH') {
    $id = (int) ($_GET['id'] ?? 0);
    $alvo = buscarUsuario($pdo, $id);
    $b = readJsonBody();
    if (!array_key_exists('ativo', $b)) jsonError('Informe o campo ativo.');
    $ativo = (bool) $b['ativo'];

    if (!$ativo) {
        if ($id === (int) $usuario['id']) jsonError('Você não pode desativar o seu próprio usuário.');
        if ((int) $alvo['admin'] && (int) $alvo['ativo'] && contarAdminsAtivos($pdo) <= 1) {
            jsonError('É preciso manter pelo menos um administrador ativo.');
        }
    }

    $pdo->prepare("UPDATE usuarios SET ativo = :a WHERE id = :id")->execute(['a' => (int) $ativo, 'id' => $id]);
    jsonResponse(['id' => $id, 'ativo' => $ativo]);
}

jsonError('Método não suportado.', 405);
