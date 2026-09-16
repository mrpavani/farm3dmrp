<?php
// Layout comum das telas (menu lateral + cabeçalho da página).
// Antes de incluir, defina:
//   $usuario         -> retorno de exigirLoginPagina()
//   $paginaAtual     -> nome do arquivo (ex.: 'index.php') para destacar o menu
//   $tituloPagina    -> título da página (<title> e cabeçalho)
//   $subtituloPagina -> (opcional) descrição curta abaixo do título
// Feche a página com: require __DIR__ . '/includes/footer.php';
/** @var array $usuario */
/** @var string $paginaAtual */
/** @var string $tituloPagina */
$subtituloPagina = $subtituloPagina ?? '';

// Ícones (traço 2px, 24x24)
$icones = [
    'pedido'    => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/>',
    'fabrica'   => '<path d="M3 21V9l6 4V9l6 4V5h6v16z"/><path d="M7 17h2M13 17h2"/>',
    'clientes'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'produtos'  => '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
    'relatorio' => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
    'usuarios'  => '<rect x="3" y="11" width="18" height="10" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    'sair'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
];
function icone(string $nome, array $icones): string {
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($icones[$nome] ?? '') . '</svg>';
}

$menu = [
    'Operação' => [
        'pedidos.php' => ['Pedidos', 'pedido'],
        'fabrica.php' => ['Painel da Fábrica', 'fabrica'],
    ],
    'Cadastros' => [
        'clientes.php' => ['Clientes', 'clientes'],
        'produtos.php' => ['Produtos', 'produtos'],
    ],
    'Análise' => [
        'relatorios.php' => ['Relatórios', 'relatorio'],
    ],
    'Minha Conta' => [
        'perfil.php' => ['Trocar Senha', 'usuarios'],
    ],
];
if ($usuario['admin']) {
    $menu['Administração'] = ['usuarios.php' => ['Usuários', 'usuarios']];
}

// Iniciais para o avatar
$partes = preg_split('/\s+/', trim($usuario['nome']));
$iniciais = mb_strtoupper(mb_substr($partes[0], 0, 1) . (count($partes) > 1 ? mb_substr(end($partes), 0, 1) : ''));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($tituloPagina) ?> · Fábrica 3D</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<aside class="sidebar" id="sidebar" aria-label="Menu principal">
    <div class="brand">
        <span class="brand-logo">3D</span>
        <div><strong>Fábrica 3D</strong><small>Pedidos &amp; Produção</small></div>
    </div>
    <nav class="nav">
        <?php foreach ($menu as $secao => $itens): ?>
            <div class="nav-secao"><?= e($secao) ?></div>
            <?php foreach ($itens as $arquivo => [$rotulo, $icone]): ?>
                <a href="<?= $arquivo ?>"<?= $arquivo === $paginaAtual ? ' class="ativo" aria-current="page"' : '' ?>>
                    <?= icone($icone, $icones) ?><span><?= e($rotulo) ?></span>
                </a>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-usuario usuario-topo">
        <span class="avatar" aria-hidden="true"><?= e($iniciais) ?></span>
        <div class="quem">
            <strong title="<?= e($usuario['nome']) ?>"><?= e($usuario['nome']) ?></strong>
            <span><?= $usuario['admin'] ? '<span class="tag-admin">admin</span>' : 'Usuário' ?></span>
        </div>
        <a class="botao-sair" href="logout.php" title="Sair" aria-label="Sair"><?= icone('sair', $icones) ?></a>
    </div>
</aside>
<div class="sidebar-fundo" id="sidebarFundo"></div>

<div class="app-main">
    <div class="topbar">
        <button type="button" class="botao-menu" id="btnMenu" aria-label="Abrir menu" aria-controls="sidebar" aria-expanded="false"><?= icone('menu', $icones) ?></button>
        <strong>Fábrica 3D</strong>
    </div>

    <div class="cabecalho-pagina">
        <div>
            <h1 id="tituloPagina"><?= e($tituloPagina) ?></h1>
            <?php if ($subtituloPagina): ?><p id="subtituloPagina"><?= e($subtituloPagina) ?></p><?php endif; ?>
        </div>
    </div>

<script>window.USUARIO = <?= json_encode(['id' => (int) $usuario['id'], 'nome' => $usuario['nome'], 'admin' => $usuario['admin']], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<script src="assets/js/comum.js"></script>
