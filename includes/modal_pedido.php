<?php
// Modal de pedido (novo/edição), para pedidos de cliente e ordens de estoque.
// Incluir com require_once. Script: assets/js/form-pedido.js (FormPedido.abrir()).
/** @var array $usuario */
require_once __DIR__ . '/modal_cliente.php';
?>
<div id="modalPedido" class="modal" hidden>
    <div class="card modal-caixa modal-largo" role="dialog" aria-modal="true" aria-labelledby="pedTitulo">
        <form id="formPedido" autocomplete="off" novalidate>
            <div class="modal-cabecalho">
                <div>
                    <h2 id="pedTitulo">Novo pedido</h2>
                    <p class="modal-sub" id="pedSub"></p>
                </div>
                <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="aviso" id="pedAvisoBloqueado" hidden></div>
            <div class="aviso" id="pedAvisoSemProdutos" hidden>
                Nenhum produto ativo no catálogo. Cadastre os produtos em <a href="produtos.php">Produtos</a>.
            </div>

            <section class="secao-form" id="pedSecaoCliente">
                <h3>Cliente</h3>
                <div class="cliente-escolha">
                    <select id="pedCliente" aria-label="Cliente">
                        <option value="">— selecione o cliente —</option>
                    </select>
                    <button type="button" class="secundario" id="pedNovoCliente">+ Novo cliente</button>
                </div>
                <div class="cliente-info" id="pedClienteInfo" hidden></div>
            </section>

            <section class="secao-form">
                <h3>
                    <span>Produtos</span>
                    <button type="button" class="fantasma pequeno" id="pedAddItem">+ Adicionar produto</button>
                </h3>
                <p class="vazio" id="pedNotaItens" hidden style="margin:0 0 8px;">
                    Itens com produção registrada não podem ser removidos nem trocar de produto, e a quantidade não pode ficar abaixo do já produzido.
                </p>
                <div class="cabecalho-itens" aria-hidden="true">
                    <span>Produto</span><span style="text-align:right">Preço</span><span>Quantidade</span><span></span>
                </div>
                <div id="pedItens"></div>
            </section>

            <section class="secao-form">
                <h3 id="pedTituloEntrega">Entrega</h3>
                <div class="linha">
                    <div>
                        <label for="pedData" id="pedRotuloData">Data do pedido</label>
                        <input type="date" id="pedData">
                    </div>
                    <div>
                        <label for="pedEntrega" id="pedRotuloEntrega">Entrega prometida</label>
                        <input type="date" id="pedEntrega">
                    </div>
                    <div>
                        <label id="pedRotuloUsuario">Registrado por</label>
                        <div class="usuario-pedido" id="pedUsuario"><?= e($usuario['nome']) ?></div>
                    </div>
                </div>
                <label for="pedObs">Observações</label>
                <textarea id="pedObs" rows="2" placeholder="Cores, acabamento, forma de entrega..."></textarea>
            </section>

            <div class="resumo-inline" aria-live="polite">
                <span><strong id="pedResumoItens">0</strong> <span id="pedResumoItensRot">itens</span></span>
                <span><strong id="pedResumoUnidades">0</strong> <span id="pedResumoUnidadesRot">unidades</span></span>
                <span>Valor estimado <span class="total" id="pedResumoValor">R$ 0,00</span></span>
            </div>

            <div class="modal-rodape">
                <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
                <button type="submit" id="pedSalvar">Salvar pedido</button>
            </div>
        </form>
    </div>
</div>
<script src="assets/js/form-pedido.js"></script>
