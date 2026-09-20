<?php
require_once __DIR__ . '/../includes/auth.php';
$usuario = exigirLoginApi();
$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Método não suportado.', 405);
}

// peca_id/cor_id/produto_id vêm em $_POST no envio multipart (Opção 1); no
// envio por base64 (Opção 2) vêm junto no corpo JSON, que o PHP não põe em
// $_POST. Lê o corpo uma única vez aqui para os dois casos enxergarem os ids.
$corpo = empty($_FILES['foto']['name']) ? readJsonBody() : [];
$pecaId = (int) ($_POST['peca_id'] ?? $corpo['peca_id'] ?? 0);
$corId = (int) ($_POST['cor_id'] ?? $corpo['cor_id'] ?? 0);
$produtoId = (int) ($_POST['produto_id'] ?? $corpo['produto_id'] ?? 0);
$pastaAlvo = ($pecaId || $corId) ? 'uploads/pecas/' : 'uploads/produtos/';
$caminhoAbsolutoPasta = __DIR__ . '/../' . $pastaAlvo;

if (!is_dir($caminhoAbsolutoPasta)) {
    mkdir($caminhoAbsolutoPasta, 0777, true);
}

$extensoesPermitidas = ['jpg', 'jpeg', 'png', 'webp'];
$nomeArquivo = '';

// Opção 1: Upload via $_FILES
if (!empty($_FILES['foto']['name']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    $info = pathinfo($_FILES['foto']['name']);
    $ext = strtolower($info['extension'] ?? '');

    if (!in_array($ext, $extensoesPermitidas, true)) {
        jsonError('Formato de imagem inválido. Use JPG, PNG ou WEBP.');
    }

    // Limite de 8MB
    if ($_FILES['foto']['size'] > 8 * 1024 * 1024) {
        jsonError('A imagem deve ter no máximo 8MB.');
    }

    $prefixo = $corId ? "cor_{$corId}_" : ($pecaId ? "peca_{$pecaId}_" : ($produtoId ? "prod_{$produtoId}_" : "img_"));
    $nomeArquivo = $prefixo . bin2hex(random_bytes(6)) . '.' . $ext;
    $destino = $caminhoAbsolutoPasta . $nomeArquivo;

    if (!move_uploaded_file($_FILES['foto']['tmp_name'], $destino)) {
        jsonError('Falha ao salvar a imagem no servidor.');
    }
} else {
    // Opção 2: Receber Base64 (ex: imagem da webcam ou crop)
    $base64 = $corpo['base64'] ?? '';
    if (!empty($base64)) {
        if (preg_match('/^data:image\/(\w+);base64,/', $base64, $tipo)) {
            $ext = strtolower($tipo[1]);
            if ($ext === 'jpeg') $ext = 'jpg';
            if (!in_array($ext, $extensoesPermitidas, true)) {
                jsonError('Formato de imagem inválido.');
            }
            $base64 = substr($base64, strpos($base64, ',') + 1);
            $dadosBinarios = base64_decode($base64);
            if ($dadosBinarios === false) jsonError('Imagem base64 inválida.');

            $prefixo = $corId ? "cor_{$corId}_" : ($pecaId ? "peca_{$pecaId}_" : "img_");
            $nomeArquivo = $prefixo . bin2hex(random_bytes(6)) . '.' . $ext;
            file_put_contents($caminhoAbsolutoPasta . $nomeArquivo, $dadosBinarios);
        }
    }
}

if (!$nomeArquivo) {
    jsonError('Nenhuma imagem foi enviada.');
}

$urlRelativa = $pastaAlvo . $nomeArquivo;

// Se enviou cor_id, é a foto de uma variante de cor específica da peça.
if ($corId) {
    $stmt = $pdo->prepare("UPDATE produto_pecas_cores SET foto = :foto WHERE id = :id");
    $stmt->execute(['foto' => $urlRelativa, 'id' => $corId]);
}

// Se enviou peca_id, é a foto padrão/de referência da peça.
if ($pecaId) {
    $stmt = $pdo->prepare("UPDATE produto_pecas SET foto = :foto WHERE id = :id");
    $stmt->execute(['foto' => $urlRelativa, 'id' => $pecaId]);
}

// Se enviou produto_id, atualiza no banco
if ($produtoId) {
    $stmt = $pdo->prepare("UPDATE produtos SET foto = :foto WHERE id = :id");
    $stmt->execute(['foto' => $urlRelativa, 'id' => $produtoId]);
}

jsonResponse([
    'ok' => true,
    'foto' => $urlRelativa,
    'peca_id' => $pecaId,
    'cor_id' => $corId,
    'produto_id' => $produtoId
]);
