<?php
require_once __DIR__ . '/includes/auth.php';
$usuario = exigirLoginPagina();
$paginaAtual = 'produtos.php';
$tituloPagina = 'Produtos';
$subtituloPagina = 'Catálogo de produtos disponíveis para pedidos e fabricação.';
require __DIR__ . '/includes/header.php';
?>

<main>
    <div id="msg" aria-live="polite"></div>

    <div class="barra-lista">
        <label class="busca">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" id="busca" placeholder="Buscar por nome ou descrição…" aria-label="Buscar produtos">
        </label>
        <select id="filtroSituacao" aria-label="Situação">
            <option value="">Todas as situações</option>
            <option value="1">Ativos</option>
            <option value="0">Inativos</option>
        </select>
        <button type="button" id="btnNovo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Novo produto
        </button>
    </div>

    <section class="card card-lista">
        <div class="tabela-rolagem">
            <table>
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th class="num">Preço</th>
                        <th class="num">Estoque</th>
                        <th>Situação</th>
                        <th class="num">Pedidos</th>
                        <th class="num">Produzido</th>
                        <th class="num">Ações</th>
                    </tr>
                </thead>
                <tbody id="tabelaProdutos"></tbody>
            </table>
        </div>
        <div class="rodape-lista" id="rodape"></div>
    </section>
</main>

<div id="modalProduto" class="modal" hidden>
    <div class="card modal-caixa" role="dialog" aria-modal="true" aria-labelledby="prodTituloModal">
        <form id="formProduto" autocomplete="off" novalidate>
            <div class="modal-cabecalho">
                <div>
                    <h2 id="prodTituloModal">Novo produto</h2>
                    <p class="modal-sub" id="prodSubModal">Só produtos ativos aparecem nos pedidos e nas ordens de estoque.</p>
                </div>
                <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <label for="prodNome">Nome <span class="obrigatorio">*</span></label>
            <input type="text" id="prodNome" maxlength="150" required placeholder="Ex.: Vaso Geométrico P">

            <div class="linha">
                <div>
                    <label for="prodPreco">Preço (R$)</label>
                    <input type="number" id="prodPreco" min="0" step="0.01" placeholder="0,00" inputmode="decimal">
                </div>
                <div>
                    <label for="prodEstoque">Estoque</label>
                    <input type="number" id="prodEstoque" min="0" step="1" placeholder="0" inputmode="numeric">
                </div>
                <div>
                    <label for="prodAtivo">Situação</label>
                    <select id="prodAtivo">
                        <option value="1">Ativo</option>
                        <option value="0">Inativo</option>
                    </select>
                </div>
            </div>
            <label for="prodDescricao">Descrição</label>
            <textarea id="prodDescricao" rows="3" placeholder="Material, cor, dimensões, acabamento..."></textarea>

            <div class="modal-rodape">
                <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
                <button type="submit" id="btnSalvarProduto">Cadastrar produto</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/produtos.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
