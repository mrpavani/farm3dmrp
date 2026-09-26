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

    $stmtP = $pdo->prepare("SELECT nome, peso_gramas FROM produtos WHERE id = :id");
    $stmtP->execute(['id' => $produtoId]);
    $prodSimples = $stmtP->fetch();

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

    if ($prodSimples && (float)$prodSimples['peso_gramas'] > 0) {
        $gastas = round((float)$prodSimples['peso_gramas'] * $qtd, 2);
        darBaixaFilamentoConsumo($pdo, 'Padrão / Única', $gastas, [
            'produto_id' => $produtoId,
            'usuario_id' => $usuarioId,
            'observacoes' => "Produção de {$qtd} un. do produto {$prodSimples['nome']}",
        ]);
    }

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

// ============================================================
// Funções de Tempo (HH:mm:ss) e Consumo de Filamento (g)
// ============================================================

/**
 * Formata um total de segundos no formato HH:mm:ss.
 * Suporta durações superiores a 24 horas (ex.: 36:40:00).
 */
function formatarTempoHHMMSS(int $segundos): string {
    if ($segundos <= 0) return '00:00:00';
    $horas = intdiv($segundos, 3600);
    $resto = $segundos % 3600;
    $minutos = intdiv($resto, 60);
    $segs = $resto % 60;
    return sprintf('%02d:%02d:%02d', $horas, $minutos, $segs);
}

/**
 * Converte string de tempo flexível (ex.: "01:30:00", "01:30", "90m", "5400") em segundos.
 */
function converterParaSegundos($valor): int {
    if (is_numeric($valor)) {
        return max(0, (int) $valor);
    }
    $str = trim((string) $valor);
    if ($str === '') return 0;

    // Formato HH:MM:SS ou H:M:S
    if (preg_match('/^(\d+):(\d{1,2}):(\d{1,2})$/', $str, $m)) {
        return ((int)$m[1] * 3600) + ((int)$m[2] * 60) + (int)$m[3];
    }
    // Formato HH:MM
    if (preg_match('/^(\d+):(\d{1,2})$/', $str, $m)) {
        return ((int)$m[1] * 3600) + ((int)$m[2] * 60);
    }
    // Formato com "h", "m", "s" (ex.: 1h30m, 45m)
    $segundos = 0;
    if (preg_match('/(\d+)\s*h/i', $str, $m)) $segundos += (int)$m[1] * 3600;
    if (preg_match('/(\d+)\s*m/i', $str, $m)) $segundos += (int)$m[1] * 60;
    if (preg_match('/(\d+)\s*s/i', $str, $m)) $segundos += (int)$m[1];
    if ($segundos > 0) return $segundos;

    return max(0, (int) $str);
}

/**
 * Calcula o consumo de filamento (gramas total e por cor) e tempo de produção
 * para 1 produto e para a quantidade solicitada.
 * Se o produto tem peças cadastradas em produto_pecas, soma as peças.
 * Se for produto simples sem peças, usa os campos diretos de produtos.
 */
function calcularConsumoProduto(PDO $pdo, int $produtoId, int $quantidade = 1): array {
    $quantidade = max(1, $quantidade);

    $stmtProd = $pdo->prepare("SELECT id, nome, tipo, peso_gramas, tempo_producao_segundos FROM produtos WHERE id = :id");
    $stmtProd->execute(['id' => $produtoId]);
    $prod = $stmtProd->fetch();
    if (!$prod) {
        return [
            'produto_id' => $produtoId,
            'tempo_segundos_1un' => 0,
            'tempo_formatado_1un' => '00:00:00',
            'peso_gramas_1un' => 0.0,
            'tempo_segundos_total' => 0,
            'tempo_formatado_total' => '00:00:00',
            'peso_gramas_total' => 0.0,
            'cores' => [],
            'pecas' => [],
        ];
    }

    $stmtPecas = $pdo->prepare("
        SELECT id, nome, quantidade, peso_gramas, tempo_producao_segundos
        FROM produto_pecas
        WHERE produto_id = :id
        ORDER BY id ASC
    ");
    $stmtPecas->execute(['id' => $produtoId]);
    $pecas = $stmtPecas->fetchAll();

    $tempo1un = 0;
    $peso1un = 0.0;
    $coresConsumo1un = [];
    $pecasDetalhe = [];

    if ($pecas) {
        $pecaIds = array_column($pecas, 'id');
        $in = implode(',', array_fill(0, count($pecaIds), '?'));
        $stmtC = $pdo->prepare("SELECT id, peca_id, cor, estoque, peso_gramas, tempo_producao_segundos FROM produto_pecas_cores WHERE peca_id IN ($in) ORDER BY id ASC");
        $stmtC->execute($pecaIds);
        $coresPorPeca = [];
        foreach ($stmtC->fetchAll() as $c) {
            $coresPorPeca[(int) $c['peca_id']][] = $c;
        }

        foreach ($pecas as $p) {
            $pid = (int) $p['id'];
            $qtdNaPeca = max(1, (int) $p['quantidade']);
            $tempoPeca = (int) $p['tempo_producao_segundos']; // tempo da peça para 1 produto
            $pesoPeca = (float) $p['peso_gramas'];             // peso da peça para 1 produto

            $tempo1un += $tempoPeca;
            $peso1un += $pesoPeca;

            $cores = $coresPorPeca[$pid] ?? [];
            if ($cores) {
                // Se tem cores cadastradas, distribui ou aplica o peso especificado
                $qtdCores = count($cores);
                foreach ($cores as $c) {
                    $corNome = trim((string) $c['cor']);
                    if ($corNome === '') $corNome = 'Padrão / Única';
                    // Se a cor tem peso_gramas especificado na variante, usa ele; senão usa da peça dividida ou integral
                    $pesoCor = $c['peso_gramas'] !== null ? (float) $c['peso_gramas'] : ($pesoPeca > 0 ? ($pesoPeca / $qtdCores) : 0.0);
                    $coresConsumo1un[$corNome] = ($coresConsumo1un[$corNome] ?? 0.0) + $pesoCor;
                }
            } else {
                $coresConsumo1un['Padrão / Única'] = ($coresConsumo1un['Padrão / Única'] ?? 0.0) + $pesoPeca;
            }

            $pecasDetalhe[] = [
                'peca_id' => $pid,
                'nome' => $p['nome'],
                'quantidade' => $qtdNaPeca,
                'peso_gramas' => $pesoPeca,
                'tempo_producao_segundos' => $tempoPeca,
                'tempo_formatado' => formatarTempoHHMMSS($tempoPeca),
                'cores' => $cores,
            ];
        }
    } else {
        // Produto sem peças na BOM: verifica se possui variantes de cores cadastradas em produto_cores
        $stmtProdCores = $pdo->prepare("SELECT id, cor, peso_gramas, tempo_producao_segundos FROM produto_cores WHERE produto_id = :id ORDER BY id ASC");
        $stmtProdCores->execute(['id' => $produtoId]);
        $prodCores = $stmtProdCores->fetchAll();

        if ($prodCores && count($prodCores) > 0) {
            $tempo1un = 0;
            $peso1un = 0.0;
            foreach ($prodCores as $pc) {
                $cNome = trim((string) $pc['cor']) ?: 'Padrão / Única';
                $pGramas = (float) $pc['peso_gramas'];
                $tSegs = (int) $pc['tempo_producao_segundos'];
                $tempo1un += $tSegs;
                $peso1un += $pGramas;
                $coresConsumo1un[$cNome] = ($coresConsumo1un[$cNome] ?? 0.0) + $pGramas;
            }
        } else {
            $tempo1un = (int) $prod['tempo_producao_segundos'];
            $peso1un = (float) $prod['peso_gramas'];
            if ($peso1un > 0) {
                $coresConsumo1un['Padrão / Única'] = $peso1un;
            }
        }
    }

    $tempoTotal = $tempo1un * $quantidade;
    $pesoTotal = $peso1un * $quantidade;

    $coresTotal = [];
    foreach ($coresConsumo1un as $cor => $g1un) {
        $coresTotal[] = [
            'cor' => $cor,
            'gramas_1un' => round($g1un, 2),
            'gramas_total' => round($g1un * $quantidade, 2),
        ];
    }

    return [
        'produto_id' => $produtoId,
        'produto_nome' => $prod['nome'],
        'quantidade' => $quantidade,
        'tempo_segundos_1un' => $tempo1un,
        'tempo_formatado_1un' => formatarTempoHHMMSS($tempo1un),
        'peso_gramas_1un' => round($peso1un, 2),
        'tempo_segundos_total' => $tempoTotal,
        'tempo_formatado_total' => formatarTempoHHMMSS($tempoTotal),
        'peso_gramas_total' => round($pesoTotal, 2),
        'cores' => $coresTotal,
        'pecas' => $pecasDetalhe,
    ];
}

/**
 * Enriquece um pedido (ou seus itens) com o cálculo de tempo de produção futuro em HH:mm:ss
 * e consumo de filamento em gramas por cor e total geral, considerando as quantidades
 * do pedido e o que ainda falta produzir.
 */
function enriquecerPedidoComConsumo(PDO $pdo, array &$pedido): void {
    $itens = $pedido['itens'] ?? [];
    $tempoTotalPedido = 0;
    $tempoFuturoPedido = 0;
    $pesoTotalPedido = 0.0;
    $pesoFuturoPedido = 0.0;
    $coresTotalPedido = [];
    $coresFuturoPedido = [];

    static $cacheConsumo = [];

    foreach ($itens as &$it) {
        $pid = (int) $it['produto_id'];
        $qtd = max(0, (int) $it['quantidade']);
        $produzido = max(0, (int) ($it['quantidade_produzida'] ?? 0));
        $restante = max(0, $qtd - $produzido);

        if (!isset($cacheConsumo[$pid])) {
            $cacheConsumo[$pid] = calcularConsumoProduto($pdo, $pid, 1);
        }
        $info1 = $cacheConsumo[$pid];

        $t1un = $info1['tempo_segundos_1un'];
        $p1un = $info1['peso_gramas_1un'];

        $tTotalItem = $t1un * $qtd;
        $tFuturoItem = $t1un * $restante;
        $pTotalItem = $p1un * $qtd;
        $pFuturoItem = $p1un * $restante;

        $it['tempo_unitario_segundos'] = $t1un;
        $it['tempo_unitario_formatado'] = $info1['tempo_formatado_1un'];
        $it['tempo_total_segundos'] = $tTotalItem;
        $it['tempo_total_formatado'] = formatarTempoHHMMSS($tTotalItem);
        $it['tempo_futuro_segundos'] = $tFuturoItem;
        $it['tempo_futuro_formatado'] = formatarTempoHHMMSS($tFuturoItem);
        $it['peso_unitario_gramas'] = $p1un;
        $it['peso_total_gramas'] = round($pTotalItem, 2);
        $it['peso_futuro_gramas'] = round($pFuturoItem, 2);

        $coresItemTotal = [];
        $coresItemFuturo = [];
        foreach ($info1['cores'] as $c) {
            $corNome = $c['cor'];
            $g1 = (float) $c['gramas_1un'];
            $gTot = round($g1 * $qtd, 2);
            $gFut = round($g1 * $restante, 2);

            $coresItemTotal[] = ['cor' => $corNome, 'gramas' => $gTot];
            $coresItemFuturo[] = ['cor' => $corNome, 'gramas' => $gFut];

            $coresTotalPedido[$corNome] = ($coresTotalPedido[$corNome] ?? 0.0) + $gTot;
            $coresFuturoPedido[$corNome] = ($coresFuturoPedido[$corNome] ?? 0.0) + $gFut;
        }
        $it['consumo_cores'] = $coresItemTotal;
        $it['consumo_cores_futuro'] = $coresItemFuturo;

        $tempoTotalPedido += $tTotalItem;
        $tempoFuturoPedido += $tFuturoItem;
        $pesoTotalPedido += $pTotalItem;
        $pesoFuturoPedido += $pFuturoItem;
    }
    unset($it);

    $pedido['itens'] = $itens;
    $pedido['tempo_total_segundos'] = $tempoTotalPedido;
    $pedido['tempo_total_formatado'] = formatarTempoHHMMSS($tempoTotalPedido);
    $pedido['tempo_futuro_segundos'] = $tempoFuturoPedido;
    $pedido['tempo_futuro_formatado'] = formatarTempoHHMMSS($tempoFuturoPedido);
    $pedido['peso_total_gramas'] = round($pesoTotalPedido, 2);
    $pedido['peso_futuro_gramas'] = round($pesoFuturoPedido, 2);

    $consumoCoresPedido = [];
    foreach ($coresTotalPedido as $cor => $g) {
        $consumoCoresPedido[] = ['cor' => $cor, 'gramas' => round($g, 2)];
    }
    $pedido['consumo_cores'] = $consumoCoresPedido;

    $consumoCoresFuturo = [];
    foreach ($coresFuturoPedido as $cor => $g) {
        $consumoCoresFuturo[] = ['cor' => $cor, 'gramas' => round($g, 2)];
    }
    $pedido['consumo_cores_futuro'] = $consumoCoresFuturo;
}

// ============================================================
// Módulo de Filamentos: PEPS (FIFO), Custo Médio e Baixa
// ============================================================

/**
 * Normaliza e busca filamento por cor e opcionalmente por tipo/marca.
 */
function buscarFilamentoPorCor(PDO $pdo, string $cor, ?string $tipo = null): ?array {
    $corLimpa = trim($cor);
    if ($corLimpa === '' || $corLimpa === 'Padrão / Única') {
        // Se cor genérica, tenta achar qualquer filamento ativo
        $stmt = $pdo->query("SELECT * FROM filamentos WHERE ativo = 1 ORDER BY estoque_gramas DESC, id ASC LIMIT 1");
        return $stmt->fetch() ?: null;
    }

    $params = ['cor' => $corLimpa];
    $sql = "SELECT * FROM filamentos WHERE ativo = 1 AND (LOWER(TRIM(cor)) = LOWER(:cor) OR cor LIKE :corLike)";
    $params['corLike'] = '%' . $corLimpa . '%';

    if ($tipo) {
        $sql .= " AND LOWER(tipo) = LOWER(:tipo)";
        $params['tipo'] = trim($tipo);
    }
    $sql .= " ORDER BY estoque_gramas DESC, id ASC LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $fil = $stmt->fetch();
    return $fil ?: null;
}

/**
 * Retorna os dados consolidados do filamento com:
 * - Saldo em gramas e rolos
 * - Custo Médio Ponderado (R$/kg e R$/g)
 * - Custo PEPS / Lote Atual Ativo (R$/kg e R$/g)
 * - Valor total em estoque
 * - Lotes ativos
 */
function obterResumoFilamento(PDO $pdo, int $filamentoId): array {
    $stmt = $pdo->prepare("SELECT * FROM filamentos WHERE id = :id");
    $stmt->execute(['id' => $filamentoId]);
    $fil = $stmt->fetch();
    if (!$fil) {
        return [];
    }

    $stmtLotes = $pdo->prepare("
        SELECT * FROM filamento_lotes
        WHERE filamento_id = :id
        ORDER BY data_compra ASC, id ASC
    ");
    $stmtLotes->execute(['id' => $filamentoId]);
    $todosLotes = $stmtLotes->fetchAll();

    $lotesAtivos = [];
    $gramasSaldoTotal = 0.0;
    $valorTotalEstoque = 0.0;
    $lotePepsAtivo = null;

    foreach ($todosLotes as $lote) {
        $saldo = (float) $lote['gramas_saldo'];
        if ($saldo > 0) {
            $lotesAtivos[] = $lote;
            $gramasSaldoTotal += $saldo;
            $valorTotalEstoque += ($saldo * (float) $lote['preco_por_grama']);
            if ($lotePepsAtivo === null) {
                $lotePepsAtivo = $lote;
            }
        }
    }

    // Se houver discrepância com filamentos.estoque_gramas, ajusta para o saldo real dos lotes
    $estoqueGramas = max((float)$fil['estoque_gramas'], $gramasSaldoTotal);

    $custoMedioGramas = $gramasSaldoTotal > 0 ? ($valorTotalEstoque / $gramasSaldoTotal) : 0.0;
    $custoMedioKg = $custoMedioGramas * 1000.0;

    // Se tem lote PEPS ativo, usa o preco_por_grama do primeiro lote com saldo;
    // se não tem saldo em nenhum lote, usa o último lote comprado ou padrão 0.0900 (R$90/kg)
    if ($lotePepsAtivo !== null) {
        $custoPepsG = (float) $lotePepsAtivo['preco_por_grama'];
    } elseif (!empty($todosLotes)) {
        $ultimoLote = end($todosLotes);
        $custoPepsG = (float) $ultimoLote['preco_por_grama'];
    } else {
        $custoPepsG = 0.0900; // fallback padrão R$ 90,00 por rolo de 1kg
    }
    $custoPepsKg = $custoPepsG * 1000.0;

    $status = 'ok';
    if ($estoqueGramas <= 0) {
        $status = 'zerado';
    } elseif ($estoqueGramas <= (float) $fil['estoque_minimo_gramas']) {
        $status = 'baixo';
    }

    return [
        'id' => (int) $fil['id'],
        'nome' => $fil['nome'],
        'marca' => $fil['marca'],
        'tipo' => $fil['tipo'],
        'cor' => $fil['cor'],
        'cor_hex' => $fil['cor_hex'] ?: '#6366f1',
        'estoque_gramas' => round($estoqueGramas, 2),
        'estoque_rolos' => round($estoqueGramas / 1000.0, 2),
        'estoque_minimo_gramas' => (float) $fil['estoque_minimo_gramas'],
        'status' => $status,
        'valor_total_estoque' => round($valorTotalEstoque, 2),
        'custo_medio_g' => round($custoMedioGramas, 4),
        'custo_medio_kg' => round($custoMedioKg, 2),
        'custo_peps_g' => round($custoPepsG, 4),
        'custo_peps_kg' => round($custoPepsKg, 2),
        'lote_ativo' => $lotePepsAtivo,
        'total_lotes_ativos' => count($lotesAtivos),
        'lotes_ativos' => $lotesAtivos,
        'todos_lotes' => $todosLotes,
    ];
}

/**
 * Cadastra uma nova entrada de lote / rolos de filamento.
 * Converte kg/rolo para gramas e calcula o custo por grama.
 */
function adicionarLoteFilamento(PDO $pdo, array $dados): int {
    $filamentoId = (int) ($dados['filamento_id'] ?? 0);
    $qtdRolos = max(1, (int) ($dados['quantidade_rolos'] ?? 1));
    $pesoRoloGramas = (float) ($dados['peso_rolo_gramas'] ?? 1000.0);
    if ($pesoRoloGramas <= 0) $pesoRoloGramas = 1000.0;

    $precoRolo = (float) ($dados['preco_rolo'] ?? 0.0);
    $precoTotal = (float) ($dados['preco_total'] ?? ($precoRolo * $qtdRolos));
    if ($precoRolo <= 0 && $precoTotal > 0 && $qtdRolos > 0) {
        $precoRolo = $precoTotal / $qtdRolos;
    }

    $pesoTotalGramas = $qtdRolos * $pesoRoloGramas;
    $precoPorGrama = $pesoTotalGramas > 0 ? ($precoTotal / $pesoTotalGramas) : 0.0;
    $dataCompra = !empty($dados['data_compra']) ? $dados['data_compra'] : date('Y-m-d');
    $obs = trim($dados['observacoes'] ?? '');

    $stmtFil = $pdo->prepare("SELECT estoque_gramas FROM filamentos WHERE id = :id FOR UPDATE");
    $stmtFil->execute(['id' => $filamentoId]);
    $fil = $stmtFil->fetch();
    if (!$fil) {
        throw new Exception('Filamento não encontrado.');
    }

    $saldoAnterior = (float) $fil['estoque_gramas'];
    $saldoPosterior = $saldoAnterior + $pesoTotalGramas;

    $stmtLote = $pdo->prepare("
        INSERT INTO filamento_lotes
            (filamento_id, quantidade_rolos, peso_rolo_gramas, peso_total_gramas, preco_rolo, preco_total, preco_por_grama, gramas_saldo, data_compra, observacoes)
        VALUES
            (:filamento_id, :quantidade_rolos, :peso_rolo_gramas, :peso_total_gramas, :preco_rolo, :preco_total, :preco_por_grama, :gramas_saldo, :data_compra, :observacoes)
    ");
    $stmtLote->execute([
        'filamento_id' => $filamentoId,
        'quantidade_rolos' => $qtdRolos,
        'peso_rolo_gramas' => $pesoRoloGramas,
        'peso_total_gramas' => $pesoTotalGramas,
        'preco_rolo' => $precoRolo,
        'preco_total' => $precoTotal,
        'preco_por_grama' => round($precoPorGrama, 4),
        'gramas_saldo' => $pesoTotalGramas,
        'data_compra' => $dataCompra,
        'observacoes' => $obs ?: null,
    ]);
    $loteId = (int) $pdo->lastInsertId();

    $pdo->prepare("UPDATE filamentos SET estoque_gramas = :novo WHERE id = :id")
        ->execute(['novo' => $saldoPosterior, 'id' => $filamentoId]);

    $pdo->prepare("
        INSERT INTO filamento_movimentacoes
            (filamento_id, lote_id, tipo, gramas, saldo_anterior_gramas, saldo_posterior_gramas, preco_por_grama, custo_total, usuario_id, observacoes)
        VALUES
            (:filamento_id, :lote_id, 'compra', :gramas, :saldo_anterior, :saldo_posterior, :preco_por_grama, :custo_total, :usuario_id, :observacoes)
    ")->execute([
        'filamento_id' => $filamentoId,
        'lote_id' => $loteId,
        'gramas' => $pesoTotalGramas,
        'saldo_anterior' => $saldoAnterior,
        'saldo_posterior' => $saldoPosterior,
        'preco_por_grama' => round($precoPorGrama, 4),
        'custo_total' => $precoTotal,
        'usuario_id' => $dados['usuario_id'] ?? null,
        'observacoes' => "Entrada de {$qtdRolos} rolo(s) de " . ($pesoRoloGramas >= 1000 ? ($pesoRoloGramas/1000).'kg' : "{$pesoRoloGramas}g") . ($obs ? " · $obs" : ''),
    ]);

    return $loteId;
}

/**
 * Dá baixa de consumo de filamento pela regra PEPS (FIFO).
 * - Identifica a cor do filamento
 * - Consome primeiro do lote com saldo mais antigo
 * - Se esgotar o lote mais antigo, passa automaticamente para o próximo
 * - Registra o custo consumido e a movimentação
 */
function darBaixaFilamentoConsumo(PDO $pdo, string $cor, float $gramas, array $origem = []): array {
    if ($gramas <= 0) {
        return ['ok' => true, 'gramas_baixadas' => 0.0, 'custo_total' => 0.0];
    }

    $fil = buscarFilamentoPorCor($pdo, $cor, $origem['tipo'] ?? null);
    if (!$fil) {
        // Se ainda não cadastrou o filamento para esta cor, cria um registro inicial
        $nomeAuto = trim(($origem['tipo'] ?? 'PLA') . ' ' . ($cor ?: 'Padrão'));
        $stmtCria = $pdo->prepare("
            INSERT INTO filamentos (nome, marca, tipo, cor, estoque_gramas, ativo)
            VALUES (:nome, 'Genérica', :tipo, :cor, 0.00, 1)
        ");
        $stmtCria->execute([
            'nome' => $nomeAuto,
            'tipo' => $origem['tipo'] ?? 'PLA',
            'cor' => $cor ?: 'Padrão',
        ]);
        $filId = (int) $pdo->lastInsertId();
        $fil = ['id' => $filId, 'estoque_gramas' => 0.0, 'nome' => $nomeAuto, 'cor' => $cor ?: 'Padrão'];
    }

    $filId = (int) $fil['id'];

    // Busca os lotes com saldo > 0 em ordem cronológica (PEPS / FIFO)
    $stmtLotes = $pdo->prepare("
        SELECT * FROM filamento_lotes
        WHERE filamento_id = :id AND gramas_saldo > 0
        ORDER BY data_compra ASC, id ASC
        FOR UPDATE
    ");
    $stmtLotes->execute(['id' => $filId]);
    $lotes = $stmtLotes->fetchAll();

    $stmtFil = $pdo->prepare("SELECT estoque_gramas FROM filamentos WHERE id = :id FOR UPDATE");
    $stmtFil->execute(['id' => $filId]);
    $saldoEstoqueAtual = (float) $stmtFil->fetchColumn();

    $restante = $gramas;
    $custoTotalConsumido = 0.0;
    $lotesAfetados = [];

    $stmtUpdLote = $pdo->prepare("UPDATE filamento_lotes SET gramas_saldo = :saldo WHERE id = :id");
    $stmtInsMov = $pdo->prepare("
        INSERT INTO filamento_movimentacoes
            (filamento_id, lote_id, tipo, gramas, saldo_anterior_gramas, saldo_posterior_gramas, preco_por_grama, custo_total, peca_id, produto_id, pedido_item_id, usuario_id, observacoes)
        VALUES
            (:filamento_id, :lote_id, 'consumo_producao', :gramas, :saldo_ant, :saldo_post, :preco_por_grama, :custo_total, :peca_id, :produto_id, :pedido_item_id, :usuario_id, :observacoes)
    ");

    $saldoLoteCorrente = $saldoEstoqueAtual;

    foreach ($lotes as $lote) {
        if ($restante <= 0) break;
        $saldoLote = (float) $lote['gramas_saldo'];
        if ($saldoLote <= 0) continue;

        $consumir = min($saldoLote, $restante);
        $novoSaldoLote = $saldoLote - $consumir;
        $precoGrama = (float) $lote['preco_por_grama'];
        $custoFatia = $consumir * $precoGrama;
        $custoTotalConsumido += $custoFatia;

        $stmtUpdLote->execute(['saldo' => $novoSaldoLote, 'id' => $lote['id']]);

        $saldoPost = max(0.0, $saldoLoteCorrente - $consumir);
        $stmtInsMov->execute([
            'filamento_id' => $filId,
            'lote_id' => $lote['id'],
            'gramas' => -$consumir,
            'saldo_ant' => $saldoLoteCorrente,
            'saldo_post' => $saldoPost,
            'preco_por_grama' => $precoGrama,
            'custo_total' => round($custoFatia, 2),
            'peca_id' => $origem['peca_id'] ?? null,
            'produto_id' => $origem['produto_id'] ?? null,
            'pedido_item_id' => $origem['pedido_item_id'] ?? null,
            'usuario_id' => $origem['usuario_id'] ?? null,
            'observacoes' => ($origem['observacoes'] ?? 'Consumo em produção') . " · Lote #{$lote['id']} (" . ($novoSaldoLote <= 0 ? 'esgotado' : "restam {$novoSaldoLote}g") . ")",
        ]);

        $saldoLoteCorrente = $saldoPost;
        $restante -= $consumir;
        $lotesAfetados[] = ['lote_id' => $lote['id'], 'consumido' => $consumir, 'novo_saldo' => $novoSaldoLote];
    }

    // Se ainda restou gramas além do que havia nos lotes (estoque insuficiente de lotes)
    if ($restante > 0) {
        // Custo fallback
        $precoFallback = !empty($lotes) ? (float)end($lotes)['preco_por_grama'] : 0.0900;
        $custoFatia = $restante * $precoFallback;
        $custoTotalConsumido += $custoFatia;
        $saldoPost = $saldoLoteCorrente - $restante; // pode ficar negativo no registro indicando falta

        $stmtInsMov->execute([
            'filamento_id' => $filId,
            'lote_id' => null,
            'tipo' => 'consumo_producao',
            'gramas' => -$restante,
            'saldo_ant' => $saldoLoteCorrente,
            'saldo_post' => $saldoPost,
            'preco_por_grama' => $precoFallback,
            'custo_total' => round($custoFatia, 2),
            'peca_id' => $origem['peca_id'] ?? null,
            'produto_id' => $origem['produto_id'] ?? null,
            'pedido_item_id' => $origem['pedido_item_id'] ?? null,
            'usuario_id' => $origem['usuario_id'] ?? null,
            'observacoes' => ($origem['observacoes'] ?? 'Consumo em produção') . " (excedente além do estoque registrado de lotes)",
        ]);
        $saldoLoteCorrente = $saldoPost;
    }

    // Atualiza o saldo consolidado do filamento
    $novoSaldoFilamento = max(0.0, $saldoEstoqueAtual - $gramas);
    $pdo->prepare("UPDATE filamentos SET estoque_gramas = :saldo WHERE id = :id")
        ->execute(['saldo' => $novoSaldoFilamento, 'id' => $filId]);

    return [
        'ok' => true,
        'filamento_id' => $filId,
        'filamento_nome' => $fil['nome'],
        'gramas_consumidas' => $gramas,
        'custo_total' => round($custoTotalConsumido, 2),
        'novo_estoque' => $novoSaldoFilamento,
        'lotes_afetados' => $lotesAfetados,
    ];
}

/**
 * Calcula a composição de custos e o custo do filamento para 1 unidade do produto
 * usando o custo do lote ativo PEPS (ou fallback médio) para cada cor consumida.
 */
function calcularCustoFilamentoProduto(PDO $pdo, int $produtoId): array {
    $consumo = calcularConsumoProduto($pdo, $produtoId, 1);
    $cores = $consumo['cores'] ?? [];

    $custoFilamentoTotal = 0.0;
    $detalheCores = [];

    foreach ($cores as $c) {
        $corNome = $c['cor'];
        $gramas = (float) $c['gramas_1un'];

        $fil = buscarFilamentoPorCor($pdo, $corNome);
        $precoPorGrama = 0.0900; // default R$ 90,00 / 1000g = 0,09/g
        $filNome = 'Padrão / Estimado (R$ 90/kg)';
        $filId = null;

        if ($fil) {
            $filId = (int) $fil['id'];
            $resumo = obterResumoFilamento($pdo, $filId);
            $filNome = $resumo['nome'] ?? $fil['nome'];
            $precoPorGrama = (float) ($resumo['custo_peps_g'] ?: ($resumo['custo_medio_g'] ?: 0.0900));
        }

        $custoCor = round($gramas * $precoPorGrama, 2);
        $custoFilamentoTotal += $custoCor;

        $detalheCores[] = [
            'cor' => $corNome,
            'gramas' => $gramas,
            'filamento_id' => $filId,
            'filamento_nome' => $filNome,
            'preco_por_grama' => $precoPorGrama,
            'preco_por_kg' => round($precoPorGrama * 1000, 2),
            'custo_total' => $custoCor,
        ];
    }

    // Se o produto não tiver cores na BOM mas tiver peso_gramas direto
    if (empty($cores) && !empty($consumo['peso_gramas_1un']) && $consumo['peso_gramas_1un'] > 0) {
        $gramas = (float) $consumo['peso_gramas_1un'];
        $fil = buscarFilamentoPorCor($pdo, 'Padrão / Única');
        $precoPorGrama = 0.0900;
        $filNome = 'Padrão / Estimado (R$ 90/kg)';
        $filId = null;
        if ($fil) {
            $filId = (int) $fil['id'];
            $resumo = obterResumoFilamento($pdo, $filId);
            $filNome = $resumo['nome'] ?? $fil['nome'];
            $precoPorGrama = (float) ($resumo['custo_peps_g'] ?: 0.0900);
        }
        $custoFilamentoTotal = round($gramas * $precoPorGrama, 2);
        $detalheCores[] = [
            'cor' => 'Padrão / Única',
            'gramas' => $gramas,
            'filamento_id' => $filId,
            'filamento_nome' => $filNome,
            'preco_por_grama' => $precoPorGrama,
            'preco_por_kg' => round($precoPorGrama * 1000, 2),
            'custo_total' => $custoFilamentoTotal,
        ];
    }

    return [
        'produto_id' => $produtoId,
        'peso_total_gramas' => (float) ($consumo['peso_gramas_1un'] ?? 0.0),
        'custo_filamento' => round($custoFilamentoTotal, 2),
        'cores' => $detalheCores,
    ];
}

/**
 * Realiza o planejamento e diagnóstico de estoque de filamento para um conjunto
 * de itens (pedidos grandes, carteira de produção ou simulação de evento).
 *
 * Calcula:
 * - Gramas e rolos necessários por cor
 * - Custo financeiro estimado (via PEPS dos lotes em estoque + custo de compra do que faltar)
 * - Alerta explícito caso a quantidade de filamento não dê por cor
 */
function planejarConsumoFilamento(PDO $pdo, array $itensDemanda): array {
    $demandaPorCor = [];
    $tempoTotalSegundos = 0;
    $pesoTotalGramas = 0.0;
    $produtosDetalhados = [];

    foreach ($itensDemanda as $item) {
        $pid = (int) ($item['produto_id'] ?? 0);
        $qtd = max(0, (int) ($item['quantidade'] ?? 0));
        if ($pid <= 0 || $qtd <= 0) continue;

        $infoProd = calcularConsumoProduto($pdo, $pid, $qtd);
        $tempoTotalSegundos += (int) ($infoProd['tempo_segundos_total'] ?? 0);
        $pesoTotalGramas += (float) ($infoProd['peso_gramas_total'] ?? 0.0);

        $produtosDetalhados[] = [
            'produto_id' => $pid,
            'nome' => $infoProd['produto_nome'] ?? "Produto #$pid",
            'quantidade' => $qtd,
            'peso_total_gramas' => (float) ($infoProd['peso_gramas_total'] ?? 0.0),
            'tempo_segundos' => (int) ($infoProd['tempo_segundos_total'] ?? 0),
            'tempo_formatado' => $infoProd['tempo_formatado_total'] ?? '00:00:00',
            'cores' => $infoProd['cores'] ?? [],
        ];

        foreach ($infoProd['cores'] as $c) {
            $corNome = trim($c['cor']);
            if ($corNome === '') $corNome = 'Padrão / Única';
            $gTot = (float) $c['gramas_total'];
            $demandaPorCor[$corNome] = ($demandaPorCor[$corNome] ?? 0.0) + $gTot;
        }

        // Se produto simples sem cores detalhadas
        if (empty($infoProd['cores']) && !empty($infoProd['peso_gramas_total'])) {
            $gTot = (float) $infoProd['peso_gramas_total'];
            $demandaPorCor['Padrão / Única'] = ($demandaPorCor['Padrão / Única'] ?? 0.0) + $gTot;
        }
    }

    $diagnosticoCores = [];
    $custoTotalGeral = 0.0;
    $totalFaltas = 0;
    $rolosParaComprarTotal = 0;

    foreach ($demandaPorCor as $cor => $gNecessaria) {
        $fil = buscarFilamentoPorCor($pdo, $cor);
        $filId = $fil ? (int) $fil['id'] : null;
        $estoqueAtual = 0.0;
        $corHex = '#6366f1';
        $lotes = [];
        $precoMedioG = 0.0900;
        $tipo = 'PLA';
        $marca = 'Genérica';

        if ($fil) {
            $resumoFil = obterResumoFilamento($pdo, $filId);
            $estoqueAtual = (float) $resumoFil['estoque_gramas'];
            $corHex = $resumoFil['cor_hex'] ?: '#6366f1';
            $lotes = $resumoFil['lotes_ativos'] ?? [];
            $precoMedioG = (float) ($resumoFil['custo_medio_g'] ?: ($resumoFil['custo_peps_g'] ?: 0.0900));
            $tipo = $resumoFil['tipo'];
            $marca = $resumoFil['marca'];
        }

        // Simula o consumo PEPS dos lotes para apurar o custo
        $restanteDemanda = $gNecessaria;
        $custoCor = 0.0;

        foreach ($lotes as $lt) {
            if ($restanteDemanda <= 0) break;
            $saldoLt = (float) $lt['gramas_saldo'];
            if ($saldoLt <= 0) continue;
            $pegar = min($saldoLt, $restanteDemanda);
            $custoCor += ($pegar * (float)$lt['preco_por_grama']);
            $restanteDemanda -= $pegar;
        }

        // Se faltar filamento no estoque, calcula o custo do que terá que comprar
        $faltaGramas = max(0.0, $gNecessaria - $estoqueAtual);
        if ($restanteDemanda > 0) {
            $custoCor += ($restanteDemanda * $precoMedioG);
        }

        $saldoProjetado = $estoqueAtual - $gNecessaria;
        $rolosComprar = $faltaGramas > 0 ? (int) ceil($faltaGramas / 1000.0) : 0;
        if ($faltaGramas > 0) {
            $totalFaltas++;
            $rolosParaComprarTotal += $rolosComprar;
        }

        $custoTotalGeral += $custoCor;

        $diagnosticoCores[] = [
            'cor' => $cor,
            'cor_hex' => $corHex,
            'tipo' => $tipo,
            'marca' => $marca,
            'filamento_id' => $filId,
            'filamento_cadastrado' => $fil !== null,
            'gramas_necessarias' => round($gNecessaria, 2),
            'rolos_necessarios' => round($gNecessaria / 1000.0, 2),
            'estoque_atual_gramas' => round($estoqueAtual, 2),
            'estoque_atual_rolos' => round($estoqueAtual / 1000.0, 2),
            'saldo_projetado_gramas' => round($saldoProjetado, 2),
            'falta_gramas' => round($faltaGramas, 2),
            'rolos_comprar' => $rolosComprar,
            'custo_estimado' => round($custoCor, 2),
            'preco_medio_kg' => round($precoMedioG * 1000.0, 2),
            'status' => $faltaGramas > 0 ? 'falta' : ($saldoProjetado <= 300 ? 'limite' : 'ok'),
            'mensagem_alerta' => $faltaGramas > 0
                ? sprintf('🚨 Faltam %.0fg! Comprar %d rolo(s) de 1kg da cor %s', $faltaGramas, $rolosComprar, $cor)
                : sprintf('✅ Estoque suficiente (sobrará %.0fg)', $saldoProjetado),
        ];
    }

    // Ordena colocando primeiro as cores em falta
    usort($diagnosticoCores, function($a, $b) {
        if ($a['falta_gramas'] > 0 && $b['falta_gramas'] <= 0) return -1;
        if ($b['falta_gramas'] > 0 && $a['falta_gramas'] <= 0) return 1;
        return $b['gramas_necessarias'] <=> $a['gramas_necessarias'];
    });

    return [
        'tempo_total_segundos' => $tempoTotalSegundos,
        'tempo_total_formatado' => formatarTempoHHMMSS($tempoTotalSegundos),
        'peso_total_gramas' => round($pesoTotalGramas, 2),
        'peso_total_kg' => round($pesoTotalGramas / 1000.0, 2),
        'custo_total_estimado' => round($custoTotalGeral, 2),
        'total_cores_com_falta' => $totalFaltas,
        'rolos_para_comprar_total' => $rolosParaComprarTotal,
        'tem_alerta_geral' => $totalFaltas > 0,
        'cores' => $diagnosticoCores,
        'produtos' => $produtosDetalhados,
    ];
}

