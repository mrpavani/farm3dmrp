<?php
// ============================================================
// Regras de estoque da fábrica — ponto único de verdade.
//
// A cadeia é sempre a mesma, e só estas funções a movimentam:
//
//   imprimir peça  ->  + produto_pecas.estoque
//   montar         ->  - produto_pecas.estoque   + produtos.estoque
//   atender pedido ->  - produtos.estoque        + pedido_itens.quantidade_produzida
//
// Todas as funções assumem que quem chama abriu a transação.
// ============================================================
require_once __DIR__ . '/db.php';

// Grava uma linha no livro de movimentos. Ver migracao_008_movimentos.sql.
function registrarMovimento(PDO $pdo, string $tipo, array $d): void {
    $stmt = $pdo->prepare("
        INSERT INTO movimentos_estoque
            (tipo, produto_id, peca_id, pedido_item_id, quantidade, saldo_depois, usuario_id, observacoes)
        VALUES
            (:tipo, :produto_id, :peca_id, :pedido_item_id, :quantidade, :saldo_depois, :usuario_id, :observacoes)
    ");
    $stmt->execute([
        'tipo'           => $tipo,
        'produto_id'     => $d['produto_id'] ?? null,
        'peca_id'        => $d['peca_id'] ?? null,
        'pedido_item_id' => $d['pedido_item_id'] ?? null,
        'quantidade'     => (int) ($d['quantidade'] ?? 0),
        'saldo_depois'   => isset($d['saldo_depois']) ? (int) $d['saldo_depois'] : null,
        'usuario_id'     => $d['usuario_id'] ?? null,
        'observacoes'    => $d['observacoes'] ?? null,
    ]);
}

// Rótulo legível de uma peça ("Hélice (Azul)").
function rotuloPeca(array $peca): string {
    $cor = trim((string) ($peca['cor'] ?? ''));
    return $peca['nome'] . ($cor !== '' ? " ($cor)" : '');
}

// Quantas unidades do produto dá para montar agora com as peças em estoque.
// Retorna capacidade, quais peças estão nesse limite (gargalo) e as peças.
// Use $lock = true dentro de transação que vá alterar os saldos.
function capacidadeMontagem(PDO $pdo, int $produtoId, bool $lock = false): array {
    $sql = "SELECT id, nome, cor, quantidade, estoque FROM produto_pecas WHERE produto_id = :id ORDER BY id";
    if ($lock) $sql .= " FOR UPDATE";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['id' => $produtoId]);
    $pecas = $stmt->fetchAll();

    if (!$pecas) {
        return ['capacidade' => 0, 'gargalos' => [], 'pecas' => [], 'tem_ficha' => false];
    }

    $capacidade = PHP_INT_MAX;
    foreach ($pecas as $p) {
        $porUnidade = max(1, (int) $p['quantidade']);
        $capacidade = min($capacidade, intdiv(max(0, (int) $p['estoque']), $porUnidade));
    }

    $gargalos = [];
    foreach ($pecas as $p) {
        $porUnidade = max(1, (int) $p['quantidade']);
        if (intdiv(max(0, (int) $p['estoque']), $porUnidade) === $capacidade) {
            $gargalos[] = rotuloPeca($p);
        }
    }

    return ['capacidade' => $capacidade, 'gargalos' => $gargalos, 'pecas' => $pecas, 'tem_ficha' => true];
}

// Monta $qtd unidades: baixa as peças da ficha técnica e soma no produto pronto.
// Lança Exception nomeando o gargalo se não houver peças suficientes.
// Retorna o novo saldo de produto pronto.
function montarProduto(PDO $pdo, int $produtoId, int $qtd, ?int $usuarioId, ?string $obs = null): int {
    if ($qtd <= 0) throw new Exception('A quantidade a montar deve ser no mínimo 1.');

    $info = capacidadeMontagem($pdo, $produtoId, true);
    if (!$info['tem_ficha']) {
        throw new Exception('Este produto não tem peças cadastradas na ficha técnica, então não dá para montar.');
    }
    if ($info['capacidade'] < $qtd) {
        // Aponta a peça que trava e quantas faltam, para a mensagem ser acionável.
        $faltas = [];
        foreach ($info['pecas'] as $p) {
            $necessario = max(1, (int) $p['quantidade']) * $qtd;
            $falta = $necessario - (int) $p['estoque'];
            if ($falta > 0) $faltas[] = rotuloPeca($p) . ": faltam $falta";
        }
        throw new Exception(sprintf(
            'Dá para montar no máximo %d de %d. %s',
            $info['capacidade'], $qtd, implode('; ', $faltas)
        ));
    }

    $baixa = $pdo->prepare("UPDATE produto_pecas SET estoque = estoque - :c WHERE id = :id");
    foreach ($info['pecas'] as $p) {
        $consumo = max(1, (int) $p['quantidade']) * $qtd;
        $baixa->execute(['c' => $consumo, 'id' => $p['id']]);
        registrarMovimento($pdo, 'peca_consumida', [
            'produto_id'   => $produtoId,
            'peca_id'      => (int) $p['id'],
            'quantidade'   => -$consumo,
            'saldo_depois' => (int) $p['estoque'] - $consumo,
            'usuario_id'   => $usuarioId,
            'observacoes'  => $obs ?? "Montagem de $qtd un.",
        ]);
    }

    $pdo->prepare("UPDATE produtos SET estoque = estoque + :q WHERE id = :id")
        ->execute(['q' => $qtd, 'id' => $produtoId]);

    $novoEstoque = (int) $pdo->query("SELECT estoque FROM produtos WHERE id = " . (int) $produtoId)->fetchColumn();

    registrarMovimento($pdo, 'produto_montado', [
        'produto_id'   => $produtoId,
        'quantidade'   => $qtd,
        'saldo_depois' => $novoEstoque,
        'usuario_id'   => $usuarioId,
        'observacoes'  => $obs,
    ]);

    return $novoEstoque;
}

// Gera $qtd de produto pronto. Se o produto tem ficha técnica, monta a partir
// das peças; se não tem (produto simples, impresso inteiro), entra direto no
// estoque. Retorna o novo saldo de produto pronto.
function produzirProdutoPronto(PDO $pdo, int $produtoId, int $qtd, ?int $usuarioId, ?string $obs = null): int {
    if ($qtd <= 0) throw new Exception('A quantidade deve ser no mínimo 1.');

    $info = capacidadeMontagem($pdo, $produtoId, true);
    if ($info['tem_ficha']) {
        return montarProduto($pdo, $produtoId, $qtd, $usuarioId, $obs);
    }

    $pdo->prepare("UPDATE produtos SET estoque = estoque + :q WHERE id = :id")
        ->execute(['q' => $qtd, 'id' => $produtoId]);
    $novoEstoque = (int) $pdo->query("SELECT estoque FROM produtos WHERE id = " . (int) $produtoId)->fetchColumn();

    registrarMovimento($pdo, 'produto_montado', [
        'produto_id'   => $produtoId,
        'quantidade'   => $qtd,
        'saldo_depois' => $novoEstoque,
        'usuario_id'   => $usuarioId,
        'observacoes'  => $obs ?? 'Produto simples, sem ficha técnica de peças.',
    ]);

    return $novoEstoque;
}

// Consome $qtd do estoque de produto pronto para atender um item de pedido.
// Lança Exception se não houver saldo. Retorna o novo saldo.
function consumirProdutoPronto(PDO $pdo, int $produtoId, int $qtd, ?int $usuarioId, ?int $pedidoItemId = null): int {
    $stmt = $pdo->prepare("SELECT nome, estoque FROM produtos WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $produtoId]);
    $produto = $stmt->fetch();
    if (!$produto) throw new Exception('Produto não encontrado.');

    $disponivel = (int) $produto['estoque'];
    if ($disponivel < $qtd) {
        throw new Exception(sprintf(
            'Estoque pronto insuficiente de "%s": há %d, precisa de %d.',
            $produto['nome'], $disponivel, $qtd
        ));
    }

    $pdo->prepare("UPDATE produtos SET estoque = estoque - :q WHERE id = :id")
        ->execute(['q' => $qtd, 'id' => $produtoId]);

    registrarMovimento($pdo, 'pedido_atendido', [
        'produto_id'     => $produtoId,
        'pedido_item_id' => $pedidoItemId,
        'quantidade'     => -$qtd,
        'saldo_depois'   => $disponivel - $qtd,
        'usuario_id'     => $usuarioId,
    ]);

    return $disponivel - $qtd;
}
