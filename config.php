<?php
// ============================================================
// Configuração de conexão com o banco de dados
// Detecção automática de ambiente (Local vs Produção)
// ============================================================

$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = ($host === 'localhost' || $host === '127.0.0.1' || strpos($host, 'localhost:') === 0 || php_sapi_name() === 'cli');

if ($isLocal) {
    // Credenciais para desenvolvimento local
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'pedidos3d');
    define('DB_USER', 'root');
    define('DB_PASS', 'm@P599152');
} else {
    // Credenciais para produção na Hostinger
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'u182367286_farm');
    define('DB_USER', 'u182367286_adminfarm');
    define('DB_PASS', 'm@P599152');
}

define('DB_CHARSET', 'utf8mb4');
