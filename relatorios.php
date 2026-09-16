<?php
require_once __DIR__ . '/includes/auth.php';
$usuario = exigirLoginPagina();
$paginaAtual = 'relatorios.php';
$tituloPagina = 'Relatórios de Produção';
$subtituloPagina = 'Produção realizada por período, produto e usuário.';
require __DIR__ . '/includes/header.php';
?>

<main>
    <div id="msg" aria-live="polite"></div>

    <div class="card nao-imprimir">
        <div class="filtros">
            <div>
                <label for="relDe">De</label>
                <input type="date" id="relDe">
            </div>
            <div>
                <label for="relAte">Até</label>
                <input type="date" id="relAte">
            </div>
            <div>
                <label for="relProduto">Produto</label>
                <select id="relProduto">
                    <option value="">Todos</option>
                </select>
            </div>
            <div>
                <label for="relUsuario">Produzido por</label>
                <select id="relUsuario">
                    <option value="">Todos</option>
                </select>
            </div>
            <div class="acoes">
                <button type="button" id="btnGerar">Gerar relatório</button>
            </div>
        </div>
        <div class="atalhos" role="group" aria-label="Períodos rápidos">
            <button type="button" class="pequeno secundario" data-periodo="hoje">Hoje</button>
            <button type="button" class="pequeno secundario" data-periodo="7">Últimos 7 dias</button>
            <button type="button" class="pequeno secundario" data-periodo="mes">Mês atual</button>
            <button type="button" class="pequeno secundario" data-periodo="mes-anterior">Mês anterior</button>
            <button type="button" class="pequeno secundario" data-periodo="ano">Ano atual</button>
        </div>
    </div>

    <div id="resultado" hidden>
        <div class="card-titulo">
            <div>
                <h2 id="tituloPeriodo">Período</h2>
                <p>Valores calculados pelo preço de tabela atual de cada produto.</p>
            </div>
            <div class="nao-imprimir" style="display:flex; gap:8px;">
                <button type="button" class="secundario pequeno" id="btnCsv">Exportar CSV</button>
                <button type="button" class="secundario pequeno" id="btnImprimir">Imprimir</button>
            </div>
        </div>

        <div class="resumo-grid" id="resumo"></div>

        <section class="card">
            <h2>Por produto</h2>
            <div class="tabela-rolagem" id="tabProduto"></div>
        </section>

        <div class="grade-2">
            <section class="card">
                <h2>Por dia</h2>
                <div class="tabela-rolagem" id="tabDia"></div>
            </section>
            <section class="card">
                <h2>Por usuário</h2>
                <div class="tabela-rolagem" id="tabUsuario"></div>
            </section>
        </div>

        <section class="card">
            <h2>Lançamentos de produção</h2>
            <div class="tabela-rolagem" id="tabDetalhes"></div>
        </section>
    </div>
</main>

<script src="assets/js/relatorios.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
