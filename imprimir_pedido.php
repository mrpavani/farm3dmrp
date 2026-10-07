<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/estoque.php';

$usuario = exigirLoginPagina();
$pdo = getDB();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    header('Location: pedidos.php');
    exit;
}

// 1. Carrega dados do pedido
$stmt = $pdo->prepare("
    SELECT p.*, c.nome AS cliente_nome, c.telefone AS cliente_telefone, c.email AS cliente_email,
           c.descricao AS cliente_descricao, c.cidade AS cliente_cidade, c.estado AS cliente_estado,
           u.nome AS usuario_nome
    FROM pedidos p
    LEFT JOIN clientes c ON c.id = p.cliente_id
    LEFT JOIN usuarios u ON u.id = p.usuario_id
    WHERE p.id = :id
");
$stmt->execute(['id' => $id]);
$pedido = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$pedido) {
    http_response_code(404);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Pedido não encontrado</title><style>body{font-family:sans-serif;text-align:center;padding:50px;}</style></head><body><h2>Pedido #' . htmlspecialchars((string)$id) . ' não encontrado</h2><p><a href="pedidos.php">Voltar para a lista de pedidos</a></p></body></html>';
    exit;
}

// 2. Carrega itens do pedido
$itensStmt = $pdo->prepare("
    SELECT pi.*, pr.nome AS produto_nome, pr.foto AS produto_foto
    FROM pedido_itens pi
    JOIN produtos pr ON pr.id = pi.produto_id
    WHERE pi.pedido_id = :id
    ORDER BY pi.id
");
$itensStmt->execute(['id' => $id]);
$pedido['itens'] = $itensStmt->fetchAll(PDO::FETCH_ASSOC);

// 3. Enriquece com dados técnicos de tempo e filamento
enriquecerPedidoComConsumo($pdo, $pedido);

$estoque = ($pedido['tipo'] === 'estoque');
$statusRotulos = [
    'aberto' => 'Aberto',
    'em_producao' => 'Em Produção',
    'pronto' => $estoque ? 'Pronto p/ Estoque' : 'Pronto',
    'entregue' => $estoque ? 'Concluído (Estoque)' : 'Entregue / Concluído',
    'cancelado' => 'Cancelado',
];
$statusTxt = $statusRotulos[$pedido['status']] ?? ucfirst($pedido['status']);

// Formatações auxiliares
function fmtData(?string $iso): string {
    if (!$iso) return '—';
    $partes = explode('-', substr($iso, 0, 10));
    return count($partes) === 3 ? "{$partes[2]}/{$partes[1]}/{$partes[0]}" : $iso;
}
function fmtMoeda($val): string {
    return 'R$ ' . number_format((float)$val, 2, ',', '.');
}

$telCliente = !empty($pedido['cliente_telefone']) ? formatarTelefone($pedido['cliente_telefone']) : '';
$localCliente = trim(($pedido['cliente_cidade'] ?? '') . ($pedido['cliente_estado'] ? '/' . $pedido['cliente_estado'] : ''));

$totalQtdGeral = 0;
$totalValorGeral = 0.0;
$totalProduzidoGeral = 0;
foreach ($pedido['itens'] as $it) {
    $totalQtdGeral += (int)$it['quantidade'];
    $totalProduzidoGeral += (int)($it['quantidade_produzida'] ?? 0);
    $totalValorGeral += ((float)$it['quantidade'] * (float)($it['preco_unitario'] ?? 0));
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Conferência - <?= $estoque ? 'Ordem #' : 'Pedido #' ?><?= $pedido['id'] ?> · Fábrica 3D</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --cor-primaria: #4f46e5;
            --cor-primaria-clara: #eef2ff;
            --cor-texto: #0f172a;
            --cor-sub: #475569;
            --cor-borda: #cbd5e1;
            --cor-borda-clara: #e2e8f0;
            --cor-fundo-tabela: #f8fafc;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--cor-texto);
            background: #f1f5f9;
            font-size: 13px;
            line-height: 1.45;
            -webkit-font-smoothing: antialiased;
        }

        /* Barra de Controle (fixa no topo ao visualizar no navegador) */
        .barra-controle {
            background: #1e293b;
            color: #fff;
            padding: 12px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 1000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            gap: 16px;
            flex-wrap: wrap;
        }
        .barra-controle .info {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 600;
            font-size: 14px;
        }
        .barra-controle .acoes-barra {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-imprimir-acao {
            background: #4f46e5;
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 9px 20px;
            font-weight: 700;
            font-size: 13.5px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s ease;
            box-shadow: 0 2px 6px rgba(79, 70, 229, 0.4);
        }
        .btn-imprimir-acao:hover {
            background: #4338ca;
            transform: translateY(-1px);
        }
        .btn-voltar-acao {
            background: #334155;
            color: #f1f5f9;
            border: 1px solid #475569;
            border-radius: 6px;
            padding: 9px 16px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background 0.15s;
        }
        .btn-voltar-acao:hover {
            background: #475569;
            color: #fff;
        }
        .opcoes-visualizacao {
            display: flex;
            align-items: center;
            gap: 16px;
            font-size: 12.5px;
            color: #cbd5e1;
        }
        .opcoes-visualizacao label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
            user-select: none;
        }
        .opcoes-visualizacao input[type="checkbox"] {
            cursor: pointer;
            accent-color: #4f46e5;
            width: 15px;
            height: 15px;
        }

        /* Folha A4 centralizada */
        .folha-documento {
            background: #ffffff;
            width: 210mm;
            min-height: 297mm;
            margin: 24px auto;
            padding: 18mm 18mm 15mm 18mm;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            border-radius: 4px;
            position: relative;
        }

        /* Cabeçalho */
        .cabecalho-doc {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #0f172a;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .cabecalho-doc .marca-bloco {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .logo-simbolo {
            width: 44px;
            height: 44px;
            background: #0f172a;
            color: #ffffff;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 19px;
            font-weight: 900;
            letter-spacing: -0.5px;
        }
        .marca-texto h1 {
            font-size: 18px;
            font-weight: 800;
            color: #0f172a;
            letter-spacing: -0.3px;
        }
        .marca-texto p {
            font-size: 11px;
            color: var(--cor-sub);
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .cabecalho-doc .pedido-bloco-id {
            text-align: right;
        }
        .pedido-numero-titulo {
            font-size: 22px;
            font-weight: 900;
            color: #0f172a;
            letter-spacing: -0.5px;
        }
        .tag-status-impressao {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            padding: 3px 8px;
            border-radius: 4px;
            margin-top: 4px;
            border: 1px solid #cbd5e1;
            background: #f8fafc;
            color: #334155;
        }
        .data-emissao {
            font-size: 10.5px;
            color: #64748b;
            margin-top: 3px;
        }

        /* Grid de Informações Cliente e Pedido */
        .grid-info-doc {
            display: grid;
            grid-template-columns: 1.25fr 1fr;
            gap: 14px;
            margin-bottom: 16px;
        }
        .card-info-secao {
            border: 1px solid var(--cor-borda);
            border-radius: 6px;
            padding: 10px 14px;
            background: #ffffff;
        }
        .card-info-secao h2 {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 700;
            color: #475569;
            margin-bottom: 8px;
            border-bottom: 1px solid var(--cor-borda-clara);
            padding-bottom: 4px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .linha-dado {
            display: flex;
            margin-bottom: 4px;
            font-size: 12.5px;
        }
        .linha-dado:last-child {
            margin-bottom: 0;
        }
        .linha-dado .rotulo {
            width: 105px;
            font-weight: 600;
            color: #475569;
            flex-shrink: 0;
        }
        .linha-dado .valor {
            color: #0f172a;
            font-weight: 500;
            flex: 1;
            word-break: break-word;
        }
        .linha-dado .valor.destaque {
            font-weight: 700;
            color: #0f172a;
        }

        /* Bloco de Observações */
        .bloco-obs-doc {
            border: 1px dashed #cbd5e1;
            background: #f8fafc;
            border-radius: 6px;
            padding: 9px 14px;
            margin-bottom: 16px;
            font-size: 12px;
        }
        .bloco-obs-doc strong {
            color: #334155;
            display: inline-block;
            margin-right: 6px;
        }

        /* Tabela de Itens para Conferência */
        .secao-itens h2 {
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .tabela-itens-doc {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-bottom: 16px;
        }
        .tabela-itens-doc th {
            background: #f1f5f9;
            color: #1e293b;
            font-weight: 700;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            border: 1px solid var(--cor-borda);
            padding: 8px 10px;
            text-align: left;
        }
        .tabela-itens-doc td {
            border: 1px solid var(--cor-borda);
            padding: 8px 10px;
            vertical-align: middle;
        }
        .tabela-itens-doc tbody tr:nth-child(even) {
            background: #fafafa;
        }

        /* Checkbox de conferência */
        .col-check {
            width: 44px;
            text-align: center !important;
        }
        .check-quadrado {
            width: 20px;
            height: 20px;
            border: 2px solid #0f172a;
            border-radius: 4px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 13px;
            cursor: pointer;
            user-select: none;
            background: #fff;
        }
        .check-quadrado.marcado {
            background: #0f172a;
            color: #fff;
        }
        .check-quadrado.marcado::after {
            content: '✓';
        }

        /* Coluna Foto Miniatura */
        .col-foto {
            width: 42px;
            text-align: center;
            padding: 4px !important;
        }
        .foto-thumb-doc {
            width: 34px;
            height: 34px;
            border-radius: 4px;
            object-fit: cover;
            border: 1px solid #cbd5e1;
            display: block;
            margin: 0 auto;
        }

        /* Especificações e Cores */
        .chip-cor-doc {
            display: inline-block;
            font-size: 10.5px;
            padding: 2px 6px;
            background: #eef2ff;
            border: 1px solid #c7d2fe;
            color: #3730a3;
            border-radius: 4px;
            margin: 2px 2px 0 0;
            font-weight: 600;
        }

        .col-qtd {
            width: 75px;
            text-align: center !important;
            font-size: 13px;
            font-weight: 700;
        }
        .col-visto {
            width: 85px;
            text-align: center;
        }
        .linha-visto {
            border-bottom: 1px solid #94a3b8;
            height: 18px;
            width: 80%;
            margin: 0 auto;
        }

        .col-preco {
            width: 90px;
            text-align: right !important;
        }

        /* Bloco de Totais e Resumos */
        .grid-resumos-doc {
            display: grid;
            grid-template-columns: 1.4fr 1fr;
            gap: 14px;
            margin-bottom: 16px;
            align-items: start;
        }
        .card-tecnico {
            border: 1px solid var(--cor-borda);
            border-radius: 6px;
            padding: 10px 12px;
            background: #f8fafc;
            font-size: 11.5px;
        }
        .card-tecnico h3 {
            font-size: 11px;
            text-transform: uppercase;
            color: #334155;
            margin-bottom: 6px;
            font-weight: 700;
        }
        .cores-filamento-lista {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 4px;
        }
        .cor-filamento-item {
            background: #ffffff;
            border: 1px solid #cbd5e1;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
        }

        .tabela-totais-doc {
            width: 100%;
            border-collapse: collapse;
            font-size: 12.5px;
        }
        .tabela-totais-doc td {
            padding: 5px 8px;
            border-bottom: 1px solid #e2e8f0;
        }
        .tabela-totais-doc tr.linha-total-final td {
            font-size: 15px;
            font-weight: 800;
            border-top: 2px solid #0f172a;
            border-bottom: 2px solid #0f172a;
            padding: 8px 8px;
            background: #f8fafc;
        }

        /* Área de Assinatura e Conferência */
        .secao-conferencia-final {
            border: 1px solid #0f172a;
            border-radius: 6px;
            padding: 12px 14px;
            margin-top: 14px;
            background: #ffffff;
            page-break-inside: avoid;
        }
        .secao-conferencia-final h3 {
            font-size: 11.5px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
            color: #0f172a;
        }
        .checklist-operacional {
            display: flex;
            gap: 20px;
            margin-bottom: 16px;
            font-size: 11.5px;
            flex-wrap: wrap;
        }
        .check-item-final {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .caixa-check-peq {
            width: 16px;
            height: 16px;
            border: 1.5px solid #0f172a;
            border-radius: 3px;
        }
        .grid-assinaturas {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 10px;
        }
        .campo-assinatura {
            text-align: center;
        }
        .linha-assinatura {
            border-bottom: 1px solid #0f172a;
            margin-bottom: 5px;
            height: 26px;
        }
        .legenda-assinatura {
            font-size: 11px;
            font-weight: 600;
            color: #334155;
        }

        /* Rodapé Discreto */
        .rodape-doc {
            margin-top: 20px;
            border-top: 1px solid #cbd5e1;
            padding-top: 8px;
            display: flex;
            justify-content: space-between;
            font-size: 10px;
            color: #64748b;
        }

        /* Ocultamento dinâmico via controles */
        body.sem-precos .col-preco,
        body.sem-precos .linha-preco-doc {
            display: none !important;
        }
        body.sem-checklist .col-check,
        body.sem-checklist .col-visto,
        body.sem-checklist .secao-conferencia-final {
            display: none !important;
        }
        body.sem-tecnico .card-tecnico {
            display: none !important;
        }

        /* REGRAS DE IMPRESSÃO (A4 / Impressora) */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .barra-controle {
                display: none !important;
            }
            .folha-documento {
                width: 100% !important;
                min-height: auto !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
            }
            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .tabela-itens-doc th {
                background: #f1f5f9 !important;
            }
            .check-quadrado {
                border-color: #000000 !important;
            }
            .secao-conferencia-final {
                page-break-inside: avoid !important;
            }
            @page {
                size: A4 portrait;
                margin: 12mm 12mm 12mm 12mm;
            }
        }
    </style>
</head>
<body>

    <!-- Barra Superior de Controle (Oculta na Impressão) -->
    <header class="barra-controle nao-imprimir" aria-label="Controles de impressão">
        <div class="info">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
            <span><?= $estoque ? 'Ordem de Produção #' : 'Pedido #' ?><?= $pedido['id'] ?> · Folha de Conferência</span>
        </div>

        <div class="opcoes-visualizacao">
            <label title="Mostrar caixas de marcação para conferir cada produto fisicamente">
                <input type="checkbox" id="chkChecklist" checked onchange="document.body.classList.toggle('sem-checklist', !this.checked)">
                Checklist / Conferência
            </label>
            <label title="Mostrar valores e subtotais no impresso">
                <input type="checkbox" id="chkPrecos" checked onchange="document.body.classList.toggle('sem-precos', !this.checked)">
                Exibir Preços
            </label>
            <label title="Mostrar consumo estimado de filamento e tempo de máquina">
                <input type="checkbox" id="chkTecnico" checked onchange="document.body.classList.toggle('sem-tecnico', !this.checked)">
                Resumo Técnico de Filamento
            </label>
        </div>

        <div class="acoes-barra">
            <a href="pedidos.php" class="btn-voltar-acao" id="btnVoltar" title="Voltar à tela de pedidos">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
                Voltar
            </a>
            <button type="button" class="btn-imprimir-acao" id="btnImprimirDoc" onclick="window.print()">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                Imprimir Pedido (Ctrl+P)
            </button>
        </div>
    </header>

    <!-- Folha A4 Formatada -->
    <div class="folha-documento" id="folhaPedido">

        <!-- 1. Cabeçalho -->
        <header class="cabecalho-doc">
            <div class="marca-bloco">
                <div class="logo-simbolo">3D</div>
                <div class="marca-texto">
                    <h1>Fábrica 3D</h1>
                    <p>Impressão 3D &amp; Manufatura</p>
                </div>
            </div>
            <div class="pedido-bloco-id">
                <div class="pedido-numero-titulo"><?= $estoque ? 'ORDEM #' : 'PEDIDO #' ?><?= $pedido['id'] ?></div>
                <div class="tag-status-impressao"><?= htmlspecialchars($statusTxt) ?></div>
                <div class="data-emissao">Emitido em: <?= date('d/m/Y H:i') ?></div>
            </div>
        </header>

        <!-- 2. Grid com Cliente e Prazos do Pedido -->
        <div class="grid-info-doc">
            <!-- Dados do Cliente -->
            <section class="card-info-secao">
                <h2><?= $estoque ? 'Origem / Finalidade' : 'Dados do Cliente' ?></h2>
                <?php if ($estoque): ?>
                    <div class="linha-dado">
                        <span class="rotulo">Destino:</span>
                        <span class="valor destaque">Estoque Interno</span>
                    </div>
                    <div class="linha-dado">
                        <span class="rotulo">Finalidade:</span>
                        <span class="valor">Reposição e Pronta-Entrega</span>
                    </div>
                <?php else: ?>
                    <div class="linha-dado">
                        <span class="rotulo">Cliente:</span>
                        <span class="valor destaque"><?= htmlspecialchars($pedido['cliente_nome'] ?: 'Não informado') ?></span>
                    </div>
                    <?php if ($telCliente): ?>
                    <div class="linha-dado">
                        <span class="rotulo">Telefone:</span>
                        <span class="valor"><?= htmlspecialchars($telCliente) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($pedido['cliente_email'])): ?>
                    <div class="linha-dado">
                        <span class="rotulo">E-mail:</span>
                        <span class="valor"><?= htmlspecialchars($pedido['cliente_email']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($localCliente): ?>
                    <div class="linha-dado">
                        <span class="rotulo">Localização:</span>
                        <span class="valor"><?= htmlspecialchars($localCliente) ?></span>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>

            <!-- Prazos e Responsável -->
            <section class="card-info-secao">
                <h2>Prazos &amp; Registro</h2>
                <div class="linha-dado">
                    <span class="rotulo">Data do Pedido:</span>
                    <span class="valor"><?= fmtData($pedido['data_pedido']) ?></span>
                </div>
                <div class="linha-dado">
                    <span class="rotulo">Entrega Prometida:</span>
                    <span class="valor destaque" style="color:#1e3a8a;"><?= fmtData($pedido['data_entrega_prometida']) ?></span>
                </div>
                <div class="linha-dado">
                    <span class="rotulo">Registrado por:</span>
                    <span class="valor"><?= htmlspecialchars($pedido['usuario_nome'] ?: 'Sistema') ?></span>
                </div>
                <div class="linha-dado">
                    <span class="rotulo">Tipo:</span>
                    <span class="valor"><?= $estoque ? 'Ordem Interna' : 'Pedido de Venda' ?></span>
                </div>
            </section>
        </div>

        <!-- 3. Observações do Pedido (se houver) -->
        <?php if (!empty($pedido['observacoes'])): ?>
        <div class="bloco-obs-doc">
            <strong>Observações do Pedido:</strong>
            <?= nl2br(htmlspecialchars($pedido['observacoes'])) ?>
        </div>
        <?php endif; ?>

        <!-- 4. Tabela de Itens para Conferência -->
        <section class="secao-itens">
            <h2>
                <span>Itens Solicitados para Conferência</span>
                <span style="font-size:11px;color:#64748b;font-weight:600;"><?= count($pedido['itens']) ?> produto(s) · <?= $totalQtdGeral ?> unidade(s)</span>
            </h2>

            <table class="tabela-itens-doc">
                <thead>
                    <tr>
                        <th class="col-check" title="Marque na conferência">Conf.</th>
                        <th style="width:28px;text-align:center;">#</th>
                        <th class="col-foto">Foto</th>
                        <th>Produto / Peças</th>
                        <th>Cores &amp; Variações</th>
                        <th class="col-qtd">Qtd</th>
                        <th class="col-preco">Unitário</th>
                        <th class="col-preco">Subtotal</th>
                        <th class="col-visto">Visto</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $numItem = 1;
                    foreach ($pedido['itens'] as $it): 
                        $sub = (float)$it['quantidade'] * (float)($it['preco_unitario'] ?? 0);
                        
                        // Decodificação de variações
                        $variacoesTxt = [];
                        if (!empty($it['variacoes_json'])) {
                            $varData = json_decode($it['variacoes_json'], true);
                            if (is_array($varData) && ($varData['tipo'] ?? '') === 'composto' && !empty($varData['pecas'])) {
                                foreach ($varData['pecas'] as $pc) {
                                    $pNome = $pc['peca_nome'] ?? 'Peça';
                                    $pCor = $pc['cor'] ?? 'Padrão';
                                    $variacoesTxt[] = "{$pNome}: <b>{$pCor}</b>";
                                }
                            } elseif (is_array($varData) && ($varData['tipo'] ?? '') === 'multicor_ams' && !empty($varData['cores'])) {
                                foreach ($varData['cores'] as $c) {
                                    $variacoesTxt[] = htmlspecialchars($c['cor'] ?? '');
                                }
                            }
                        }
                        if (empty($variacoesTxt) && !empty($it['cor_variacao'])) {
                            $variacoesTxt[] = "Cor: <b>" . htmlspecialchars($it['cor_variacao']) . "</b>";
                        }
                    ?>
                    <tr>
                        <td class="col-check">
                            <div class="check-quadrado" onclick="this.classList.toggle('marcado')" title="Clique para marcar/desmarcar"></div>
                        </td>
                        <td style="text-align:center;font-weight:600;color:#64748b;"><?= $numItem++ ?></td>
                        <td class="col-foto">
                            <?php if (!empty($it['produto_foto'])): ?>
                                <img src="<?= htmlspecialchars($it['produto_foto']) ?>" class="foto-thumb-doc" alt="" loading="lazy">
                            <?php else: ?>
                                <div style="width:34px;height:34px;background:#f1f5f9;border:1px solid #cbd5e1;border-radius:4px;display:flex;align-items:center;justify-content:center;margin:auto;font-size:14px;">📦</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div style="font-weight:700;font-size:13px;color:#0f172a;"><?= htmlspecialchars($it['produto_nome']) ?></div>
                        </td>
                        <td>
                            <?php if (!empty($variacoesTxt)): ?>
                                <?php foreach ($variacoesTxt as $vt): ?>
                                    <span class="chip-cor-doc"><?= $vt ?></span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="color:#94a3b8;font-size:11px;">Padrão</span>
                            <?php endif; ?>
                        </td>
                        <td class="col-qtd">
                            <?= (int)$it['quantidade'] ?> un
                        </td>
                        <td class="col-preco">
                            <?= fmtMoeda($it['preco_unitario']) ?>
                        </td>
                        <td class="col-preco" style="font-weight:700;">
                            <?= fmtMoeda($sub) ?>
                        </td>
                        <td class="col-visto">
                            <div class="linha-visto"></div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <!-- 5. Resumos e Totais -->
        <div class="grid-resumos-doc">
            <!-- Consumo Técnico de Filamento e Máquina -->
            <div class="card-tecnico">
                <h3>Estimativa de Filamento e Produção</h3>
                <div>
                    Tempo total estimado: <b><?= htmlspecialchars($pedido['tempo_total_formatado'] ?: '00:00:00') ?></b>
                    &nbsp;·&nbsp;
                    Peso total: <b><?= number_format((float)($pedido['peso_total_gramas'] ?? 0), 1, ',', '.') ?> g</b>
                </div>
                <?php 
                $coresDist = $pedido['cores_total'] ?? [];
                if (!empty($coresDist)): 
                ?>
                <div style="margin-top:6px;font-size:11px;color:#475569;">Distribuição por cor de filamento:</div>
                <div class="cores-filamento-lista">
                    <?php foreach ($coresDist as $cor => $gramas): ?>
                        <span class="cor-filamento-item">🎨 <b><?= htmlspecialchars($cor) ?>:</b> <?= number_format((float)$gramas, 1, ',', '.') ?>g</span>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Totais Gerais -->
            <div>
                <table class="tabela-totais-doc">
                    <tr>
                        <td style="color:#475569;">Total de produtos:</td>
                        <td style="text-align:right;font-weight:600;"><?= count($pedido['itens']) ?> itens</td>
                    </tr>
                    <tr>
                        <td style="color:#475569;">Total de unidades físicas:</td>
                        <td style="text-align:right;font-weight:700;"><?= $totalQtdGeral ?> unidades</td>
                    </tr>
                    <tr class="linha-preco-doc linha-total-final">
                        <td>VALOR TOTAL:</td>
                        <td style="text-align:right;color:#0f172a;"><?= fmtMoeda($totalValorGeral) ?></td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- 6. Checklist de Expedição & Assinatura de Conferência -->
        <section class="secao-conferencia-final">
            <h3>Conferência Final &amp; Expedição</h3>
            <div class="checklist-operacional">
                <div class="check-item-final">
                    <div class="caixa-check-peq"></div>
                    <span>Quantidades e peças completas</span>
                </div>
                <div class="check-item-final">
                    <div class="caixa-check-peq"></div>
                    <span>Cores e montagem inspecionadas</span>
                </div>
                <div class="check-item-final">
                    <div class="caixa-check-peq"></div>
                    <span>Acabamento / Pós-processamento OK</span>
                </div>
                <div class="check-item-final">
                    <div class="caixa-check-peq"></div>
                    <span>Embalado para entrega</span>
                </div>
            </div>

            <div class="grid-assinaturas">
                <div class="campo-assinatura">
                    <div class="linha-assinatura"></div>
                    <div class="legenda-assinatura">Conferido por (Operador/Expedição) &nbsp;·&nbsp; Data: ____/____/________</div>
                </div>
                <div class="campo-assinatura">
                    <div class="linha-assinatura"></div>
                    <div class="legenda-assinatura">Recebido por (Cliente/Destinatário) &nbsp;·&nbsp; Assinatura</div>
                </div>
            </div>
        </section>

        <!-- 7. Rodapé -->
        <footer class="rodape-doc">
            <span>Fábrica 3D · Documento oficial de conferência interna e expedição</span>
            <span>Pedido #<?= $pedido['id'] ?> · Página 1 de 1</span>
        </footer>

    </div>

    <script>
        // Fechar janela ao clicar em Voltar se foi aberta em nova aba
        document.getElementById('btnVoltar').addEventListener('click', function(e) {
            if (window.opener || window.history.length <= 1) {
                e.preventDefault();
                window.close();
            }
        });

        // Impressão automática se requisitada via query param (?auto=1)
        const params = new URLSearchParams(window.location.search);
        if (params.get('auto') === '1') {
            window.addEventListener('load', () => {
                setTimeout(() => {
                    window.print();
                }, 350);
            });
        }
    </script>
</body>
</html>
