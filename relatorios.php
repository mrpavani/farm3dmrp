<?php
require_once __DIR__ . '/includes/auth.php';
$usuario = exigirLoginPagina();
$paginaAtual = 'relatorios.php';
$tituloPagina = 'Relatórios';
$subtituloPagina = 'Tudo o que foi pedido — produzido ou não — com valores, prazos e o que falta fabricar.';
require __DIR__ . '/includes/header.php';
?>

<main>
    <div id="msg" aria-live="polite"></div>

    <div class="card nao-imprimir">
        <div class="filtros">
            <div>
                <label for="relBase">Período por</label>
                <select id="relBase">
                    <option value="pedido">Data do pedido</option>
                    <option value="entrega">Data de entrega</option>
                </select>
            </div>
            <div>
                <label for="relDe">De</label>
                <input type="date" id="relDe">
            </div>
            <div>
                <label for="relAte">Até</label>
                <input type="date" id="relAte">
            </div>
            <div>
                <label for="relStatus">Situação</label>
                <select id="relStatus">
                    <option value="">Todas</option>
                    <option value="aberto">Aberto</option>
                    <option value="em_producao">Em produção</option>
                    <option value="pronto">Pronto</option>
                    <option value="entregue">Entregue</option>
                    <option value="cancelado">Cancelado</option>
                </select>
            </div>
            <div>
                <label for="relTipo">Origem</label>
                <select id="relTipo">
                    <option value="">Todas</option>
                    <option value="venda">Clientes</option>
                    <option value="estoque">Estoque</option>
                </select>
            </div>
            <div>
                <label for="relProduto">Produto</label>
                <select id="relProduto"><option value="">Todos</option></select>
            </div>
            <div>
                <label for="relCliente">Cliente</label>
                <select id="relCliente"><option value="">Todos</option></select>
            </div>
            <div class="acoes">
                <button type="button" id="btnGerar">Gerar</button>
            </div>
        </div>
        <div class="atalhos" role="group" aria-label="Períodos rápidos">
            <button type="button" class="pequeno secundario" data-periodo="hoje">Hoje</button>
            <button type="button" class="pequeno secundario" data-periodo="7">Últimos 7 dias</button>
            <button type="button" class="pequeno secundario" data-periodo="mes">Mês atual</button>
            <button type="button" class="pequeno secundario" data-periodo="mes-anterior">Mês anterior</button>
            <button type="button" class="pequeno secundario" data-periodo="ano">Ano atual</button>
            <button type="button" class="pequeno secundario" data-periodo="tudo">Tudo</button>
        </div>
    </div>

    <div id="resultado" hidden>
        <div class="card-titulo">
            <div>
                <h2 id="tituloPeriodo">Período</h2>
                <p id="avisoPreco">Valores calculados pelo preço de tabela atual de cada produto.</p>
            </div>
            <div class="nao-imprimir" style="display:flex; gap:8px;">
                <button type="button" class="secundario pequeno" id="btnCsv">Exportar CSV</button>
                <button type="button" class="secundario pequeno" id="btnImprimir">Imprimir</button>
            </div>
        </div>

        <div class="resumo-grid" id="resumo"></div>

        <div class="card-titulo nao-imprimir" style="margin-bottom:14px;">
            <div class="abas" role="tablist" style="margin:0;">
                <button type="button" role="tab" class="aba" data-aba="entregas" aria-selected="true">
                    Entregas <span class="contador" id="contaEntregas" hidden></span>
                </button>
                <button type="button" role="tab" class="aba" data-aba="fabricar" aria-selected="false">
                    A fabricar <span class="contador" id="contaFabricar" hidden></span>
                </button>
                <button type="button" role="tab" class="aba" data-aba="pedidos" aria-selected="false">Por pedido</button>
                <button type="button" role="tab" class="aba" data-aba="produtos" aria-selected="false">Por produto</button>
                <button type="button" role="tab" class="aba" data-aba="clientes" aria-selected="false">Por cliente</button>
                <button type="button" role="tab" class="aba" data-aba="producao" aria-selected="false">Produção</button>
            </div>
        </div>

        <section class="card secao-rel" data-painel="entregas">
            <h2>Agenda de entregas</h2>
            <p class="vazio">Pedidos ainda em aberto, pelo que falta produzir. Atrasados primeiro.</p>
            <div id="painelEntregas"></div>
        </section>

        <section class="card secao-rel" data-painel="fabricar" hidden>
            <h2>O que falta fabricar</h2>
            <p class="vazio">Por produto, o que os pedidos em aberto ainda esperam — com o quanto dá para montar agora.</p>
            <div class="tabela-rolagem" id="painelFabricar"></div>
        </section>

        <section class="card secao-rel" data-painel="pedidos" hidden>
            <h2>Por pedido</h2>
            <p class="vazio">Clique num pedido para ver o valor de cada item.</p>
            <div class="tabela-rolagem" id="painelPedidos"></div>
        </section>

        <section class="card secao-rel" data-painel="produtos" hidden>
            <h2>Por produto</h2>
            <div class="tabela-rolagem" id="painelProdutos"></div>
        </section>

        <section class="card secao-rel" data-painel="clientes" hidden>
            <h2>Por cliente</h2>
            <div class="tabela-rolagem" id="painelClientes"></div>
        </section>

        <section class="card secao-rel" data-painel="producao" hidden>
            <h2>Produção registrada</h2>
            <p class="vazio">Lotes lançados pela fábrica para os pedidos deste período.</p>
            <div class="grade-2">
                <div>
                    <h3 class="sub-secao">Por dia</h3>
                    <div class="tabela-rolagem" id="painelProducaoDia"></div>
                </div>
                <div>
                    <h3 class="sub-secao">Por usuário</h3>
                    <div class="tabela-rolagem" id="painelProducaoUsuario"></div>
                </div>
            </div>
        </section>
    </div>
</main>

<script src="assets/js/relatorios.js?v=<?= @filemtime(__DIR__ . '/assets/js/relatorios.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
