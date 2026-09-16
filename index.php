<?php
// Página inicial: redireciona para o módulo de Pedidos.
// Mantém os links antigos: index.php?editar=ID e index.php?cliente=ID.
require_once __DIR__ . '/includes/auth.php';
exigirLoginPagina();

$destino = 'pedidos.php';
if (!empty($_GET['editar'])) {
    $destino .= '?editar=' . (int) $_GET['editar'];
} elseif (!empty($_GET['cliente'])) {
    $destino .= '?novo=1&cliente=' . (int) $_GET['cliente'];
}
header('Location: ' . $destino);
exit;
