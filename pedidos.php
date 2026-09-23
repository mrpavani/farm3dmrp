<?php
require_once __DIR__ . '/includes/auth.php';
$usuario = exigirLoginPagina();
$paginaAtual = 'pedidos.php';
$tituloPagina = 'Pedidos';
$subtituloPagina = 'Todos os pedidos de clientes e ordens de produção para estoque.';
require __DIR__ . '/includes/header.php';
?>

<main>
    <div id="msg" aria-live="polite"></div>

    <!-- Cards Numéricos de Resumo dos Pedidos -->
    <div class="resumo-grid kpis-pedidos" id="pedidosResumo"></div>

    <div class="barra-lista">
        <label class="busca">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" id="busca" placeholder="Buscar por nº, cliente ou produto…" aria-label="Buscar pedidos">
        </label>
        <select id="filtroTipo" aria-label="Origem">
            <option value="">Todas as origens</option>
            <option value="venda">Clientes</option>
            <option value="estoque">Estoque</option>
        </select>
        <select id="filtroStatus" aria-label="Status">
            <option value="pendentes">Em andamento</option>
            <option value="">Todos os status</option>
            <option value="aberto">Aberto</option>
            <option value="em_producao">Em produção</option>
            <option value="pronto">Pronto</option>
            <option value="entregue">Entregue / concluído</option>
            <option value="cancelado">Cancelado</option>
        </select>
        <button type="button" id="btnNovo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Novo pedido
        </button>
    </div>

    <section class="card card-lista">
        <div class="tabela-rolagem">
            <table>
                <thead>
                    <tr>
                        <th style="width:110px;">Pedido</th>
                        <th style="min-width:200px;">Cliente / Destino</th>
                        <th style="min-width:230px;">Itens Solicitados</th>
                        <th style="min-width:140px;">Previsão Entrega</th>
                        <th style="min-width:150px;">Progresso</th>
                        <th style="min-width:120px;">Status</th>
                        <th class="num" style="width:130px;">Ações</th>
                    </tr>
                </thead>
                <tbody id="tabelaPedidos"></tbody>
            </table>
        </div>
        <div class="rodape-lista" id="rodape"></div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/modal_pedido.php'; ?>
<script src="assets/js/pedidos.js?v=<?= @filemtime(__DIR__ . '/assets/js/pedidos.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
