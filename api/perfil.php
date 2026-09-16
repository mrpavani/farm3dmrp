<?php
require_once __DIR__ . '/../includes/auth.php';
$usuario = exigirLoginApi();
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'PUT' || $_SERVER['REQUEST_METHOD'] === 'POST') {
    $b = readJsonBody();
    
    if (empty($b['senha_atual']) || empty($b['nova_senha'])) {
        jsonError('Preencha a senha atual e a nova senha.');
    }
    
    $stmt = $pdo->prepare("SELECT senha_hash FROM usuarios WHERE id = :id");
    $stmt->execute(['id' => $usuario['id']]);
    $hash = $stmt->fetchColumn();
    
    if (!password_verify($b['senha_atual'], $hash)) {
        jsonError('A senha atual está incorreta.');
    }
    
    if (mb_strlen($b['nova_senha']) < 6) {
        jsonError('A nova senha deve ter pelo menos 6 caracteres.');
    }
    
    $novoHash = password_hash($b['nova_senha'], PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE usuarios SET senha_hash = :h WHERE id = :id")->execute([
        'h' => $novoHash,
        'id' => $usuario['id']
    ]);
    
    jsonResponse(['sucesso' => true]);
}

jsonError('Método não suportado.', 405);
