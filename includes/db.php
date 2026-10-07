<?php
require_once __DIR__ . '/../config.php';

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        if (!defined('DB_HOST')) {
            die("Erro: Arquivo config.php não encontrado ou incompleto.");
        }
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            die("Falha ao conectar no banco de dados: " . $e->getMessage() . "<br><br>Verifique as credenciais no arquivo <b>config.php</b> do servidor.");
        }
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
    $telefone = formatarTelefone($texto('telefone', 30));
    return [
        'nome' => $nome,
        'telefone' => $telefone,
        'email' => $email,
        'cidade' => $texto('cidade', 100),
        'estado' => $estado === null ? null : mb_strtoupper($estado),
        'descricao' => $texto('descricao', 65535),
    ];
}

function formatarTelefone(?string $tel): ?string {
    if ($tel === null) return null;
    $d = preg_replace('/\D/', '', $tel);
    if ($d === '') return null;
    if (strlen($d) > 11) $d = substr($d, 0, 11);

    if (strlen($d) === 11) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7, 4));
    }
    if (strlen($d) === 10) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6, 4));
    }
    if (strlen($d) > 6) {
        return sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6));
    }
    if (strlen($d) > 2) {
        return sprintf('(%s) %s', substr($d, 0, 2), substr($d, 2));
    }
    return "($d";
}

function emailDuplicado(PDO $pdo, ?string $email, int $ignorarId = 0): bool {
    // Permitido mais de um cliente com o mesmo e-mail
    return false;
}

function inserirCliente(PDO $pdo, array $dados): int {
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
