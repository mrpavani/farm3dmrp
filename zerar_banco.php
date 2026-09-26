<?php
// ============================================================
// Script Web para Zerar Banco e Produtos
// Sistema: Fábrica 3D (MRP / Gestão de Impressão 3D)
// Acesse pelo navegador: http://localhost:5001/zerar_banco.php
// ============================================================

require_once __DIR__ . '/includes/db.php';
$pdo = getDB();

$executado = false;
$modoExecutado = '';
$resultado = [];
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $modo = $_POST['modo'] ?? '';
    $confirmado = !empty($_POST['confirmar']);

    if (!$confirmado) {
        $erro = 'Você precisa marcar a caixa de confirmação para autorizar a limpeza.';
    } elseif (!in_array($modo, ['produtos', 'tudo'], true)) {
        $erro = 'Selecione uma opção válida de limpeza.';
    } else {
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

            // Limpeza comum: produções, pedidos, produtos e peças
            $pdo->exec('DELETE FROM `producoes`');
            $pdo->exec('ALTER TABLE `producoes` AUTO_INCREMENT = 1');

            $pdo->exec('DELETE FROM `movimentos_estoque`');
            $pdo->exec('ALTER TABLE `movimentos_estoque` AUTO_INCREMENT = 1');

            $pdo->exec('DELETE FROM `pedido_itens`');
            $pdo->exec('ALTER TABLE `pedido_itens` AUTO_INCREMENT = 1');

            $pdo->exec('DELETE FROM `pedidos`');
            $pdo->exec('ALTER TABLE `pedidos` AUTO_INCREMENT = 1');

            $pdo->exec('DELETE FROM `produto_cores`');
            $pdo->exec('ALTER TABLE `produto_cores` AUTO_INCREMENT = 1');

            $pdo->exec('DELETE FROM `produto_pecas_cores`');
            $pdo->exec('ALTER TABLE `produto_pecas_cores` AUTO_INCREMENT = 1');

            $pdo->exec('DELETE FROM `produto_pecas`');
            $pdo->exec('ALTER TABLE `produto_pecas` AUTO_INCREMENT = 1');

            $pdo->exec('DELETE FROM `produto_composicao`');
            $pdo->exec('ALTER TABLE `produto_composicao` AUTO_INCREMENT = 1');

            $pdo->exec('DELETE FROM `produtos`');
            $pdo->exec('ALTER TABLE `produtos` AUTO_INCREMENT = 1');

            if ($modo === 'tudo') {
                // Modo completo: também limpa clientes e estoque de filamentos
                $pdo->exec('DELETE FROM `clientes`');
                $pdo->exec('ALTER TABLE `clientes` AUTO_INCREMENT = 1');

                $pdo->exec('DELETE FROM `filamento_movimentacoes`');
                $pdo->exec('ALTER TABLE `filamento_movimentacoes` AUTO_INCREMENT = 1');

                $pdo->exec('DELETE FROM `filamento_lotes`');
                $pdo->exec('ALTER TABLE `filamento_lotes` AUTO_INCREMENT = 1');

                $pdo->exec('DELETE FROM `filamentos`');
                $pdo->exec('ALTER TABLE `filamentos` AUTO_INCREMENT = 1');
            } else {
                // Modo produtos: remove movimentações de filamento ligadas a produtos excluídos
                $pdo->exec('DELETE FROM `filamento_movimentacoes` WHERE `produto_id` IS NOT NULL OR `peca_id` IS NOT NULL OR `pedido_item_id` IS NOT NULL');
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

            $executado = true;
            $modoExecutado = $modo;
        } catch (Exception $e) {
            $erro = 'Erro durante a limpeza: ' . $e->getMessage();
        }
    }
}

// Contagens atuais das tabelas
$tabelasVerificar = [
    'usuarios' => 'Usuários do Sistema (Preservados)',
    'produtos' => 'Produtos',
    'produto_pecas' => 'Peças de Produtos',
    'produto_pecas_cores' => 'Cores das Peças',
    'produto_cores' => 'Cores de Produtos (Multicor)',
    'produto_composicao' => 'Composição / Ficha Técnica (BOM)',
    'pedidos' => 'Pedidos e Ordens',
    'pedido_itens' => 'Itens dos Pedidos',
    'producoes' => 'Histórico de Produções',
    'movimentos_estoque' => 'Movimentações de Estoque',
    'clientes' => 'Clientes',
    'filamentos' => 'Filamentos (Cores cadastradas)',
    'filamento_lotes' => 'Lotes de Filamento',
    'filamento_movimentacoes' => 'Movimentações de Filamento',
];

$contagens = [];
foreach ($tabelasVerificar as $tbl => $rotulo) {
    try {
        $qtd = (int) $pdo->query("SELECT COUNT(*) FROM `$tbl`")->fetchColumn();
        $contagens[$tbl] = ['rotulo' => $rotulo, 'qtd' => $qtd];
    } catch (Exception $e) {
        $contagens[$tbl] = ['rotulo' => $rotulo, 'qtd' => '—'];
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zerar Banco de Dados e Produtos · Fábrica 3D</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: #0f172a;
            color: #f8fafc;
            padding: 30px 16px;
            line-height: 1.5;
        }
        .container {
            max-width: 820px;
            margin: 0 auto;
            background: #1e293b;
            padding: 32px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            border: 1px solid #334155;
        }
        h1 { color: #f87171; font-size: 22px; display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
        p.sub { color: #94a3b8; font-size: 14px; margin-bottom: 24px; }
        .alerta-aviso {
            background: #451a03;
            border-left: 4px solid #f59e0b;
            color: #fde68a;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 13.5px;
            margin-bottom: 24px;
        }
        .alerta-sucesso {
            background: #064e3b;
            border-left: 4px solid #10b981;
            color: #a7f3d0;
            padding: 14px 18px;
            border-radius: 6px;
            font-size: 14px;
            margin-bottom: 24px;
        }
        .alerta-erro {
            background: #7f1d1d;
            border-left: 4px solid #ef4444;
            color: #fecaca;
            padding: 12px 16px;
            border-radius: 6px;
            font-size: 13.5px;
            margin-bottom: 24px;
        }
        .opcoes-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }
        @media (max-width: 640px) {
            .opcoes-grid { grid-template-columns: 1fr; }
        }
        .cartao-opcao {
            background: #0f172a;
            border: 2px solid #334155;
            border-radius: 8px;
            padding: 18px;
            cursor: pointer;
            transition: all .2s;
            position: relative;
        }
        .cartao-opcao:hover {
            border-color: #64748b;
        }
        .cartao-opcao input[type="radio"] {
            margin-right: 8px;
        }
        .cartao-opcao h3 {
            font-size: 16px;
            color: #e2e8f0;
            margin-bottom: 6px;
            display: inline-block;
        }
        .cartao-opcao p {
            font-size: 12.5px;
            color: #94a3b8;
            line-height: 1.4;
        }
        .cartao-opcao.selecionado {
            border-color: #f87171;
            background: rgba(239, 68, 68, 0.08);
        }
        .bloco-confirmacao {
            background: #0f172a;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 24px;
        }
        .bloco-confirmacao label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13.5px;
            font-weight: 600;
            color: #f87171;
            cursor: pointer;
        }
        .btn-zerar {
            background: #ef4444;
            color: #ffffff;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: background .2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .btn-zerar:hover {
            background: #dc2626;
        }
        .tabela-status {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 24px;
            background: #0f172a;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid #334155;
        }
        .tabela-status th, .tabela-status td {
            padding: 8px 14px;
            text-align: left;
            border-bottom: 1px solid #1e293b;
        }
        .tabela-status th {
            background: #1e293b;
            color: #94a3b8;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
        }
        .badge-zero {
            display: inline-block;
            background: #064e3b;
            color: #a7f3d0;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 11.5px;
        }
        .badge-preservado {
            display: inline-block;
            background: #1e3a8a;
            color: #bfdbfe;
            padding: 2px 8px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 11.5px;
        }
        .rodape-links {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid #334155;
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }
        .rodape-links a {
            color: #38bdf8;
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
        }
        .rodape-links a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
<div class="container">
    <h1>🧹 Limpeza e Reset do Banco de Dados</h1>
    <p class="sub">Ferramenta segura para zerar catálogo de produtos, pedidos ou o banco completo em ambiente de homologação ou recomeço.</p>

    <?php if ($executado): ?>
        <div class="alerta-sucesso">
            ✅ <strong>Limpeza executada com sucesso!</strong>
            Os contadores AUTO_INCREMENT foram reiniciados em 1.
            <?= $modoExecutado === 'tudo' ? 'O banco completo foi zerado (usuários preservados).' : 'Os produtos, peças e pedidos foram zerados (clientes e filamentos preservados).' ?>
        </div>
    <?php endif; ?>

    <?php if ($erro): ?>
        <div class="alerta-erro">
            ❌ <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <div class="alerta-aviso">
        ⚠️ <strong>Atenção:</strong> Esta ação é irreversível. O usuário administrador padrão (<code>admin@mail.com</code>) <strong>nunca é apagado</strong> para garantir que você continue conseguindo acessar o sistema normalmente.
    </div>

    <form method="POST">
        <div class="opcoes-grid">
            <label class="cartao-opcao" id="optProdutos">
                <input type="radio" name="modo" value="produtos" checked onclick="atualizarEstiloOpcao()">
                <h3>📦 Zerar Apenas Produtos e Pedidos</h3>
                <p>Apaga todos os produtos (simples e compostos), peças, cores de peças, fichas técnicas (BOM), pedidos e produções.<br>
                <strong style="color:#a7f3d0;">Preserva:</strong> Clientes e Carretéis de Filamento.</p>
            </label>

            <label class="cartao-opcao" id="optTudo">
                <input type="radio" name="modo" value="tudo" onclick="atualizarEstiloOpcao()">
                <h3>🔥 Zerar Banco Completo</h3>
                <p>Apaga produtos, peças, pedidos, produções, movimentações, clientes e o estoque de filamentos.<br>
                <strong style="color:#bfdbfe;">Preserva:</strong> Apenas os Usuários e Logins de acesso.</p>
            </label>
        </div>

        <div class="bloco-confirmacao">
            <label>
                <input type="checkbox" name="confirmar" value="1">
                Tenho certeza e desejo prosseguir com a exclusão dos dados selecionados
            </label>
        </div>

        <button type="submit" class="btn-zerar" onclick="return confirm('Deseja realmente zerar os dados selecionados? Esta ação não pode ser desfeita.')">
            🗑️ Executar Limpeza Agora
        </button>
    </form>

    <h2 style="font-size:16px;color:#e2e8f0;margin-top:32px;">📊 Registros Atuais no Banco de Dados:</h2>
    <table class="tabela-status">
        <thead>
            <tr>
                <th>Tabela</th>
                <th>Descrição</th>
                <th style="text-align:right;">Total de Linhas</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($contagens as $tbl => $info): ?>
                <tr>
                    <td><code><?= htmlspecialchars($tbl) ?></code></td>
                    <td><?= htmlspecialchars($info['rotulo']) ?></td>
                    <td style="text-align:right;">
                        <?php if ($info['qtd'] === 0): ?>
                            <span class="badge-zero">0 (Vazio)</span>
                        <?php elseif ($tbl === 'usuarios'): ?>
                            <span class="badge-preservado"><?= $info['qtd'] ?> usuário(s)</span>
                        <?php else: ?>
                            <strong style="color:#f8fafc;"><?= $info['qtd'] ?></strong>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="rodape-links">
        <a href="produtos.php">← Voltar para Produtos</a>
        <a href="pedidos.php">← Voltar para Pedidos</a>
        <a href="migrate.php">🔄 Rodar Migração / Atualização (migrate.php)</a>
        <a href="database/atualizar_banco_producao.sql" download>📥 Baixar atualizar_banco_producao.sql</a>
    </div>
</div>

<script>
function atualizarEstiloOpcao() {
    const rProd = document.querySelector('input[name="modo"][value="produtos"]');
    document.getElementById('optProdutos').classList.toggle('selecionado', rProd.checked);
    document.getElementById('optTudo').classList.toggle('selecionado', !rProd.checked);
}
atualizarEstiloOpcao();
</script>
</body>
</html>
