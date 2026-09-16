<?php
require_once __DIR__ . '/includes/auth.php';
$usuario = exigirLoginPagina();
$paginaAtual = 'clientes.php';
$tituloPagina = 'Clientes';
$subtituloPagina = 'Cadastre e consulte os clientes que fazem pedidos.';
require __DIR__ . '/includes/header.php';
?>

<main>
    <div id="msg" aria-live="polite"></div>

    <div class="barra-lista">
        <label class="busca">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" id="busca" placeholder="Buscar por nome, telefone, e-mail, cidade…" aria-label="Buscar clientes">
        </label>
        <button type="button" id="btnNovo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Novo cliente
        </button>
    </div>

    <section class="card card-lista">
        <div class="tabela-rolagem">
            <table>
                <thead>
                    <tr>
                        <th>Cliente</th>
                        <th>Contato</th>
                        <th>Cidade/UF</th>
                        <th class="num">Pedidos</th>
                        <th>Último pedido</th>
                        <th class="num">Ações</th>
                    </tr>
                </thead>
                <tbody id="tabelaClientes"></tbody>
            </table>
        </div>
        <div class="rodape-lista" id="rodape"></div>
    </section>
</main>

<?php require_once __DIR__ . '/includes/modal_pedido.php'; ?>
<script src="assets/js/clientes.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
