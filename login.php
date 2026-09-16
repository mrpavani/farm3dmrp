<?php
require_once __DIR__ . '/includes/auth.php';

// Só permite voltar para uma tela local do sistema (evita redirecionar para outro site)
function destinoSeguro(string $volta): string {
    return preg_match('/^(?!login\.php|logout\.php)[a-z_]+\.php(\?[\w=&%.\-]*)?$/', $volta) ? $volta : 'index.php';
}

$volta = destinoSeguro($_POST['volta'] ?? $_GET['volta'] ?? '');
if (usuarioLogado()) {
    header('Location: ' . $volta);
    exit;
}

$erro = '';
$loginDigitado = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginDigitado = mb_strtolower(trim($_POST['login'] ?? ''));
    $senha = $_POST['senha'] ?? '';

    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT id, senha_hash, ativo FROM usuarios WHERE login = :login");
    $stmt->execute(['login' => $loginDigitado]);
    $u = $stmt->fetch();

    if ($u && password_verify($senha, $u['senha_hash'])) {
        if (!(int) $u['ativo']) {
            $erro = 'Este usuário está desativado. Procure um administrador.';
        } else {
            if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
                $pdo->prepare("UPDATE usuarios SET senha_hash = :h WHERE id = :id")
                    ->execute(['h' => password_hash($senha, PASSWORD_DEFAULT), 'id' => $u['id']]);
            }
            fazerLogin($u, !empty($_POST['lembrar']));
            header('Location: ' . $volta);
            exit;
        }
    } else {
        usleep(400000); // atrasa tentativas de força bruta
        $erro = 'Usuário ou senha inválidos.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Entrar · Fábrica 3D</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="pagina-login">

<main>
    <div class="card caixa-login">
        <div class="brand">
            <span class="brand-logo">3D</span>
            <div><strong>Fábrica 3D</strong><small>Pedidos &amp; Produção</small></div>
        </div>
        <h2>Bem-vindo de volta</h2>
        <p class="sub">Entre com seu usuário para continuar.</p>

        <?php if ($erro): ?>
            <div class="msg erro" role="alert"><?= e($erro) ?></div>
        <?php endif; ?>

        <form method="post" action="login.php">
            <input type="hidden" name="volta" value="<?= e($volta) ?>">

            <label for="login">Usuário ou e-mail</label>
            <input type="text" id="login" name="login" maxlength="150" required autocomplete="username"
                   value="<?= e($loginDigitado) ?>" autofocus>

            <label for="senha">Senha</label>
            <div style="position: relative; display: flex; align-items: center; margin-bottom: 12px;">
                <input type="password" id="senha" name="senha" required autocomplete="current-password" style="width: 100%; padding-right: 40px; margin-bottom: 0;">
                <button type="button" id="btnToggleSenha" aria-label="Mostrar senha" title="Mostrar senha" style="position: absolute; right: 10px; background: none; border: none; cursor: pointer; color: #666; padding: 0; display: flex;">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                        <circle cx="12" cy="12" r="3"></circle>
                    </svg>
                </button>
            </div>
            
            <label class="check" style="margin-bottom: 24px;">
                <input type="checkbox" name="lembrar" value="1">
                <span>Lembrar de mim</span>
            </label>

            <button type="submit">Entrar</button>
        </form>

        <p class="vazio" style="margin:14px 0 0;">Não tem acesso? Peça a um administrador para cadastrar seu usuário.</p>
    </div>
</main>

<script>
document.getElementById('btnToggleSenha').addEventListener('click', function() {
    const input = document.getElementById('senha');
    const svg = this.querySelector('svg');
    if (input.type === 'password') {
        input.type = 'text';
        this.setAttribute('aria-label', 'Ocultar senha');
        this.setAttribute('title', 'Ocultar senha');
        svg.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
    } else {
        input.type = 'password';
        this.setAttribute('aria-label', 'Mostrar senha');
        this.setAttribute('title', 'Mostrar senha');
        svg.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
    }
});
</script>
</body>
</html>
