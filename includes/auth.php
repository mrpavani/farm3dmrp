<?php
require_once __DIR__ . '/db.php';

// Sessão com cookie protegido (não acessível por JS, não enviado por outros sites em POST)
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_name('pedidos3d');
    session_start();
}

// Retorna ['id', 'nome', 'login', 'admin' (bool)] ou null.
// Revalida no banco para que um usuário desativado perca o acesso na hora.
function usuarioLogado(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;

    $cache = null;
    if (!empty($_SESSION['usuario_id'])) {
        $stmt = getDB()->prepare("SELECT id, nome, login, admin FROM usuarios WHERE id = :id AND ativo = 1");
        $stmt->execute(['id' => $_SESSION['usuario_id']]);
        $cache = $stmt->fetch() ?: null;
        if ($cache) {
            $cache['admin'] = (bool) $cache['admin'];
        } else {
            unset($_SESSION['usuario_id']);
        }
    }
    return $cache;
}

// Para páginas: sem login, manda para a tela de login e volta depois.
function exigirLoginPagina(): array {
    $u = usuarioLogado();
    if (!$u) {
        $volta = basename($_SERVER['SCRIPT_NAME']) . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']);
        header('Location: login.php?volta=' . urlencode($volta));
        exit;
    }
    return $u;
}

// Para APIs: sem login, responde 401 em JSON.
function exigirLoginApi(): array {
    $u = usuarioLogado();
    if (!$u) jsonError('Sessão expirada. Faça login novamente.', 401);
    return $u;
}

// Para páginas só de administrador: quem não é admin vê uma mensagem de acesso negado.
function exigirAdminPagina(): array {
    $u = exigirLoginPagina();
    if (!$u['admin']) {
        http_response_code(403);
        $usuario = $u;
        $paginaAtual = '';
        $tituloPagina = 'Acesso negado';
        require __DIR__ . '/header.php';
        echo '<main><div class="card acesso-negado"><h2>Acesso negado</h2>'
           . '<p class="vazio">Esta tela é exclusiva de administradores.</p>'
           . '<a class="botao" href="index.php">Voltar ao início</a></div></main>';
        require __DIR__ . '/footer.php';
        exit;
    }
    return $u;
}

// Para APIs só de administrador: 403 em JSON.
function exigirAdminApi(): array {
    $u = exigirLoginApi();
    if (!$u['admin']) jsonError('Apenas administradores podem fazer isso.', 403);
    return $u;
}

function fazerLogin(array $usuario, bool $lembrar = false): void {
    if ($lembrar) {
        ini_set('session.gc_maxlifetime', 2592000);
    }
    session_regenerate_id(true);
    if ($lembrar) {
        $p = session_get_cookie_params();
        setcookie(session_name(), session_id(), time() + 2592000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    $_SESSION['usuario_id'] = (int) $usuario['id'];
    getDB()->prepare("UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = :id")
        ->execute(['id' => $usuario['id']]);
}

function fazerLogout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// Valida dados de usuário. $exigirSenha = true no cadastro; na edição a senha é opcional.
function dadosUsuario(array $b, bool $exigirSenha): array {
    $nome = trim((string) ($b['nome'] ?? ''));
    $login = mb_strtolower(trim((string) ($b['login'] ?? '')));
    $senha = (string) ($b['senha'] ?? '');

    if ($nome === '') throw new Exception('Informe o nome do usuário.');
    if (mb_strlen($nome) > 100) throw new Exception('O nome deve ter no máximo 100 caracteres.');
    // login pode ser um nome de usuário (joao.silva) ou um e-mail (joao@empresa.com)
    if (!preg_match('/^[a-z0-9._@+-]{3,150}$/', $login)) {
        throw new Exception('O login deve ter de 3 a 150 caracteres, sem espaços: letras minúsculas, números, ponto, hífen, sublinhado ou @.');
    }
    if ($senha !== '' || $exigirSenha) {
        if (mb_strlen($senha) < 6) throw new Exception('A senha deve ter pelo menos 6 caracteres.');
    }

    $dados = ['nome' => $nome, 'login' => $login, 'admin' => empty($b['admin']) ? 0 : 1];
    if ($senha !== '') $dados['senha_hash'] = password_hash($senha, PASSWORD_DEFAULT);
    return $dados;
}

function loginEmUso(PDO $pdo, string $login, int $ignorarId = 0): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE login = :login AND id <> :id");
    $stmt->execute(['login' => $login, 'id' => $ignorarId]);
    return (int) $stmt->fetchColumn() > 0;
}

function inserirUsuario(PDO $pdo, array $dados): int {
    if (loginEmUso($pdo, $dados['login'])) throw new Exception('Esse login já está em uso.');
    $stmt = $pdo->prepare("INSERT INTO usuarios (nome, login, senha_hash, admin) VALUES (:nome, :login, :senha_hash, :admin)");
    $stmt->execute($dados);
    return (int) $pdo->lastInsertId();
}

function e(?string $v): string {
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}
