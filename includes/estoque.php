<?php
// ============================================================
// Regras de estoque da fábrica — ponto único de verdade.
//
// A cadeia é sempre a mesma, e só estas funções a movimentam:
//
//   imprimir peça  ->  + produto_pecas_cores.estoque (de uma cor)
//   montar         ->  - produto_pecas_cores.estoque  + produtos.estoque
//   atender pedido ->  - produtos.estoque             + pedido_itens.quantidade_produzida
//
// Uma peça (produto_pecas) pode ter mais de uma cor cadastrada
// (produto_pecas_cores), cada uma com seu próprio saldo — ex.: Chave de
// fenda em Cinza e em Laranja, ou Suporte com uma única linha "qualquer
// cor". Qualquer cor da peça serve para montar: o estoque da peça, para
// fins de montagem, é a SOMA das suas cores.
//
// Todas as funções assumem que quem chama abriu a transação.
// ============================================================
require_once __DIR__ . '/db.php';

// Grava uma linha no livro de movimentos. Ver migracao_008_movimentos.sql.
// peca_id aqui é sempre o id da PEÇA (produto_pecas), não da variante de
// cor — o livro rastreia por peça; para saber a cor, ver observacoes.
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

// Fragmento SQL correlato: quantas unidades de um produto dá para montar
// agora, olhando o saldo das peças. Uma peça pode ter várias cores — o
// que conta é a SOMA do estoque de todas — então soma por peça antes de
// tirar o mínimo entre as peças (o gargalo). Resultado 0 quando o produto
// não tem ficha técnica (nenhuma peça cadastrada).
// $colunaProdutoId é a coluna, na query externa, que identifica o produto
// (ex.: "pi.produto_id" ou "prod.id").
function sqlProdutoMontavel(string $colunaProdutoId): string {
    return "(
        SELECT MIN(FLOOR(soma.total / GREATEST(pp.quantidade, 1)))
        FROM produto_pecas pp
        JOIN (
            SELECT peca_id, COALESCE(SUM(estoque), 0) AS total
            FROM produto_pecas_cores GROUP BY peca_id
        ) soma ON soma.peca_id = pp.id
        WHERE pp.produto_id = $colunaProdutoId
    )";
}

// Rótulo legível de uma peça ("Hélice (Azul)") ou, sem cor ("Suporte").
function rotuloPeca(array $peca, ?string $cor = null): string {
    $cor = trim((string) ($cor ?? $peca['cor'] ?? ''));
    return $peca['nome'] . ($cor !== '' ? " ($cor)" : '');
}

// Rótulo de uma variante de cor específica, para mensagens de erro.
function rotuloCor(?string $cor): string {
    $cor = trim((string) $cor);
    return $cor !== '' ? $cor : 'qualquer cor';
}

// Quantas unidades do produto dá para montar agora com as peças em estoque.
// Cada peça pode ter várias cores; o estoque que conta para a montagem é a
// soma de todas. Retorna capacidade, gargalo (peças) e, por peça, a lista
// de variantes de cor com seus saldos (para a UI decidir de qual descontar).
// Use $lock = true dentro de transação que vá alterar os saldos.
function capacidadeMontagem(PDO $pdo, int $produtoId, bool $lock = false): array {
    $sqlPecas = "SELECT id, nome, quantidade, foto FROM produto_pecas WHERE produto_id = :id ORDER BY id";
    if ($lock) $sqlPecas .= " FOR UPDATE";
    $stmt = $pdo->prepare($sqlPecas);
    $stmt->execute(['id' => $produtoId]);
    $pecas = $stmt->fetchAll();

    if (!$pecas) {
        return ['capacidade' => 0, 'gargalos' => [], 'pecas' => [], 'tem_ficha' => false];
    }

    $pecaIds = array_column($pecas, 'id');
    $in = implode(',', array_fill(0, count($pecaIds), '?'));
    $sqlCores = "SELECT id, peca_id, cor, estoque, foto FROM produto_pecas_cores WHERE peca_id IN ($in) ORDER BY id";
    if ($lock) $sqlCores .= " FOR UPDATE";
    $stmtC = $pdo->prepare($sqlCores);
    $stmtC->execute($pecaIds);
    $coresPorPeca = [];
    foreach ($stmtC->fetchAll() as $c) {
        $coresPorPeca[(int) $c['peca_id']][] = $c;
    }

    $capacidade = PHP_INT_MAX;
    foreach ($pecas as &$p) {
        $porUnidade = max(1, (int) $p['quantidade']);
        $cores = $coresPorPeca[(int) $p['id']] ?? [];
        $estoqueTotal = array_sum(array_map(fn($c) => (int) $c['estoque'], $cores));
        $p['cores'] = $cores;
        $p['estoque'] = $estoqueTotal;
        $capacidade = min($capacidade, intdiv(max(0, $estoqueTotal), $porUnidade));
    }
    unset($p);
    if ($capacidade === PHP_INT_MAX) $capacidade = 0;

    $gargalos = [];
    foreach ($pecas as $p) {
        $porUnidade = max(1, (int) $p['quantidade']);
        if (intdiv(max(0, (int) $p['estoque']), $porUnidade) === $capacidade) {
            $gargalos[] = rotuloPeca($p);
        }
    }

    return ['capacidade' => $capacidade, 'gargalos' => $gargalos, 'pecas' => $pecas, 'tem_ficha' => true];
}

// Decide de quais variantes de cor tirar $consumo unidades de uma peça,
// consumindo primeiro a cor com mais saldo (minimiza quantas variantes
// mexe; como as cores são intercambiáveis para montagem, a ordem não
// afeta o resultado). Retorna [[cor_id, estoque_antes, tirado], ...].
function planoDeConsumo(array $cores, int $consumo): array {
    usort($cores, fn($a, $b) => (int) $b['estoque'] <=> (int) $a['estoque']);
    $plano = [];
    foreach ($cores as $c) {
        if ($consumo <= 0) break;
        $disponivel = (int) $c['estoque'];
        if ($disponivel <= 0) continue;
        $tirar = min($disponivel, $consumo);
        $plano[] = ['cor_id' => (int) $c['id'], 'cor' => $c['cor'], 'estoque_antes' => $disponivel, 'tirado' => $tirar];
        $consumo -= $tirar;
    }
    return $plano;
}

// Monta $qtd unidades: baixa as peças da ficha técnica (de qualquer cor
// disponível) e soma no produto pronto. Lança Exception nomeando o
// gargalo se não houver peças suficientes. Retorna o novo saldo de
// produto pronto.
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

    $baixa = $pdo->prepare("UPDATE produto_pecas_cores SET estoque = estoque - :c WHERE id = :id");
    foreach ($info['pecas'] as $p) {
        $consumo = max(1, (int) $p['quantidade']) * $qtd;
        foreach (planoDeConsumo($p['cores'], $consumo) as $mov) {
            $baixa->execute(['c' => $mov['tirado'], 'id' => $mov['cor_id']]);
            registrarMovimento($pdo, 'peca_consumida', [
                'produto_id'   => $produtoId,
                'peca_id'      => (int) $p['id'],
                'quantidade'   => -$mov['tirado'],
                'saldo_depois' => $mov['estoque_antes'] - $mov['tirado'],
                'usuario_id'   => $usuarioId,
                'observacoes'  => ($obs ?? "Montagem de $qtd un.") . ' · cor: ' . rotuloCor($mov['cor']),
            ]);
        }
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
