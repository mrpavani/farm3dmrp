<?php
require_once __DIR__ . '/../config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function jsonResponse($data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonError(string $message, int $status = 400): never {
    jsonResponse(['erro' => $message], $status);
}

// Recalcula o status "de produção" do pedido a partir dos itens:
// aberto (nada produzido), em_producao (parcial) ou pronto (tudo produzido).
// Não mexe em pedidos entregues/cancelados, a menos que $forcar = true (reabertura).
function recalcularStatusPedido(PDO $pdo, int $pedidoId, bool $forcar = false): string {
    $stmt = $pdo->prepare("
        SELECT p.status,
               COALESCE(SUM(pi.quantidade), 0) AS total,
               COALESCE(SUM(pi.quantidade_produzida), 0) AS produzido
        FROM pedidos p
        LEFT JOIN pedido_itens pi ON pi.pedido_id = p.id
        WHERE p.id = :id
        GROUP BY p.id, p.status
    ");
    $stmt->execute(['id' => $pedidoId]);
    $r = $stmt->fetch();
    if (!$r) throw new Exception('Pedido não encontrado.');

    if (!$forcar && in_array($r['status'], ['entregue', 'cancelado'], true)) {
        return $r['status'];
    }

    $total = (int) $r['total'];
    $produzido = (int) $r['produzido'];
    if ($produzido === 0) {
        $novo = 'aberto';
    } elseif ($produzido >= $total) {
        $novo = 'pronto';
    } else {
        $novo = 'em_producao';
    }

    $pdo->prepare("UPDATE pedidos SET status = :s WHERE id = :id")
        ->execute(['s' => $novo, 'id' => $pedidoId]);
    return $novo;
}

// Valida e normaliza os dados de um cliente (usado no cadastro de clientes e
// no cadastro rápido dentro do pedido). Lança Exception com a mensagem de erro.
function dadosCliente(array $b): array {
    $texto = function (string $campo, int $max) use ($b): ?string {
        $v = trim((string) ($b[$campo] ?? ''));
        if (mb_strlen($v) > $max) throw new Exception("O campo $campo deve ter no máximo $max caracteres.");
        return $v === '' ? null : $v;
    };

    $nome = $texto('nome', 150);
    if ($nome === null) throw new Exception('Nome do cliente é obrigatório.');

    $email = $texto('email', 150);
    if ($email !== null) {
        $email = mb_strtolower($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new Exception('E-mail inválido.');
    }

    $estado = $texto('estado', 2);
    return [
        'nome' => $nome,
        'telefone' => $texto('telefone', 30),
        'email' => $email,
        'cidade' => $texto('cidade', 100),
        'estado' => $estado === null ? null : mb_strtoupper($estado),
        'descricao' => $texto('descricao', 65535),
    ];
}

function emailDuplicado(PDO $pdo, ?string $email, int $ignorarId = 0): bool {
    if ($email === null) return false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM clientes WHERE email = :email AND id <> :id");
    $stmt->execute(['email' => $email, 'id' => $ignorarId]);
    return (int) $stmt->fetchColumn() > 0;
}

function inserirCliente(PDO $pdo, array $dados): int {
    if (emailDuplicado($pdo, $dados['email'])) {
        throw new Exception('Já existe um cliente com esse e-mail.');
    }
    $stmt = $pdo->prepare("
        INSERT INTO clientes (nome, telefone, email, cidade, estado, descricao)
        VALUES (:nome, :telefone, :email, :cidade, :estado, :descricao)
    ");
    $stmt->execute($dados);
    return (int) $pdo->lastInsertId();
}

function readJsonBody(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
