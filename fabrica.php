<?php
require_once __DIR__ . '/includes/auth.php';
$usuario = exigirLoginPagina();
$paginaAtual = 'fabrica.php';
$tituloPagina = 'Painel da Fábrica';
$subtituloPagina = 'Acompanhe os pedidos e veja, por produto, tudo o que precisa ser fabricado.';
require __DIR__ . '/includes/header.php';
?>

<main>
    <div id="msg" aria-live="polite"></div>

    <div class="card-titulo" style="margin-bottom:18px;">
        <div class="abas" role="tablist" style="margin:0;">
            <button type="button" role="tab" class="aba" data-aba="pedidos" aria-selected="true" aria-controls="abaPedidos">Pedidos</button>
            <button type="button" role="tab" class="aba" data-aba="fabricar" aria-selected="false" aria-controls="abaFabricar">
                Fabricar <span class="contador" id="contadorFabricar" hidden></span>
            </button>
            <button type="button" role="tab" class="aba" data-aba="pecas" aria-selected="false" aria-controls="abaPecas">
                Bancada <span class="contador" id="contadorPecas" hidden></span>
            </button>
        </div>
        <button type="button" id="btnNovaOrdemEstoque">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Produzir para estoque
        </button>
    </div>

    <!-- ===================== ABA PEDIDOS ===================== -->
    <section id="abaPedidos" role="tabpanel">
        <div class="card">
            <div class="filtros">
                <div>
                    <label for="tipoFiltro">Origem</label>
                    <select id="tipoFiltro">
                        <option value="">Todos</option>
                        <option value="venda">Pedidos de clientes</option>
                        <option value="estoque">Produção para estoque</option>
                    </select>
                </div>
                <div>
                    <label for="statusFiltro">Status</label>
                    <select id="statusFiltro">
                        <option value="">Todos</option>
                        <option value="aberto">Aberto</option>
                        <option value="em_producao">Em produção</option>
                        <option value="pronto">Pronto</option>
                        <option value="entregue">Entregue / concluído</option>
                        <option value="cancelado">Cancelado</option>
                    </select>
                </div>
                <div>
                    <label for="dataDe">Criado de</label>
                    <input type="date" id="dataDe">
                </div>
                <div>
                    <label for="dataAte">até</label>
                    <input type="date" id="dataAte">
                </div>
                <div class="acoes">
                    <button type="button" class="secundario" id="btnLimparPedidos">Limpar</button>
                </div>
            </div>
        </div>

        <div id="listaPedidos"></div>
    </section>

    <!-- ===================== ABA FABRICAR ===================== -->
    <section id="abaFabricar" role="tabpanel" hidden>
        <div class="card">
            <div class="filtros">
                <div>
                    <label for="fabEntregaAte">Entrega até</label>
                    <input type="date" id="fabEntregaAte">
                </div>
                <div>
                    <label for="fabProduto">Produto</label>
                    <select id="fabProduto">
                        <option value="">Todos</option>
                    </select>
                </div>
                <div class="acoes">
                    <button type="button" class="secundario" id="btnFabLimpar">Limpar</button>
                    <button type="button" class="secundario" id="btnFabAtualizar">Atualizar</button>
                </div>
            </div>
            <p class="vazio" style="margin:12px 0 0;">
                Soma, por produto, o que falta produzir nos pedidos de clientes e nas ordens de estoque
                <strong>abertos</strong> ou <strong>em produção</strong>, com a data de entrega de cada um.
            </p>
        </div>

        <div class="resumo-grid" id="fabResumo"></div>
        <div id="listaFabricar"></div>
    </section>

    <!-- ===================== ABA PRODUTOS & PRODUÇÃO ===================== -->
    <section id="abaPecas" role="tabpanel" hidden>
        <div class="card">
            <div class="filtros">
                <div style="flex: 1.5; min-width: 180px;">
                    <label for="pecaBusca">Buscar produto</label>
                    <input type="search" id="pecaBusca" placeholder="Nome do produto...">
                </div>
                <div>
                    <label for="pecaFiltroStatus">Status da Fila</label>
                    <select id="pecaFiltroStatus">
                        <option value="">Todos os produtos</option>
                        <option value="imprimir">Com peças a imprimir</option>
                        <option value="montavel">Prontos para montar</option>
                        <option value="ok">Estoque de peças suficiente</option>
                    </select>
                </div>
                <div class="acoes">
                    <button type="button" class="secundario" id="btnPecasLimpar">Limpar</button>
                    <button type="button" class="secundario" id="btnPecasAtualizar">Atualizar</button>
                </div>
            </div>
            <p class="vazio" style="margin:12px 0 0;">
                Cada vez que imprimir uma peça, use <strong>+</strong> na linha dela: o saldo grava na hora e o
                <strong>Montável agora</strong> do produto se atualiza sozinho. Quando o número cobrir o que você precisa,
                clique em <strong>Montar</strong> — as peças saem do estoque e viram produto pronto.
            </p>
        </div>

        <div class="resumo-grid" id="pecasResumo"></div>
        <div class="lista-produtos-fabrica" id="gridPecasFabrica"></div>
    </section>
</main>

<!-- Modal: registrar produção de pedido tradicional -->
<div id="modalProducao" class="modal" hidden>
    <div class="card modal-caixa" role="dialog" aria-modal="true" aria-labelledby="modalTitulo">
        <div class="modal-cabecalho">
            <div>
                <h2 id="modalTitulo">Registrar produção</h2>
                <p id="modalItemInfo" class="modal-sub"></p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <p class="disponibilidade-modal" id="modalDisponibilidade"></p>
        <div class="linha">
            <div>
                <label for="modalQuantidade">Quantidade produzida</label>
                <input type="number" id="modalQuantidade" min="1" inputmode="numeric">
            </div>
            <div>
                <label for="modalData">Data da produção</label>
                <input type="date" id="modalData">
            </div>
        </div>
        <label class="linha-montar" id="modalLinhaMontar" hidden>
            <input type="checkbox" id="modalMontarSeFaltar">
            <span id="modalMontarTexto"></span>
        </label>
        <label for="modalObs">Observações</label>
        <textarea id="modalObs" rows="2" placeholder="Opcional"></textarea>
        <p class="vazio" style="margin:12px 0 0;">Será registrado por <strong><?= e($usuario['nome']) ?></strong>.</p>
        <div class="modal-rodape">
            <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
            <button type="button" id="btnConfirmarProducao">Registrar</button>
        </div>
    </div>
</div>

<!-- Modal: registrar produção rápida de peça solta impressa -->
<div id="modalProduzirPeca" class="modal" hidden>
    <div class="card modal-caixa" role="dialog" aria-modal="true" aria-labelledby="modalPecaTitulo">
        <div class="modal-cabecalho">
            <div>
                <h2 id="modalPecaTitulo">Registrar Impressão de Peça</h2>
                <p id="modalPecaSub" class="modal-sub"></p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div style="margin-top: 10px;">
            <label for="modalPecaQtd">Quantidade de peças impressas</label>
            <input type="number" id="modalPecaQtd" min="1" value="1" style="font-size: 18px; font-weight: 700; text-align: center;">
            <div class="atalhos" id="atalhosQtdPeca" style="margin-top: 8px; justify-content: center;"></div>
        </div>
        <div class="modal-rodape">
            <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
            <button type="button" id="btnConfirmarProducaoPeca">Adicionar ao Estoque</button>
        </div>
    </div>
</div>

<!-- Modal: cadastrar uma nova cor de uma peça já existente, direto da bancada -->
<div id="modalNovaCor" class="modal" hidden>
    <div class="card modal-caixa" role="dialog" aria-modal="true" aria-labelledby="modalNovaCorTitulo">
        <div class="modal-cabecalho">
            <div>
                <h2 id="modalNovaCorTitulo">Nova cor da peça</h2>
                <p id="modalNovaCorSub" class="modal-sub"></p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <label for="modalNovaCorNome">Nome da cor</label>
        <input type="text" id="modalNovaCorNome" placeholder="Ex.: Laranja" maxlength="50">
        <label for="modalNovaCorQtd">Quantidade já impressa (opcional)</label>
        <input type="number" id="modalNovaCorQtd" min="0" value="0" inputmode="numeric">
        <div class="modal-rodape">
            <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
            <button type="button" id="btnConfirmarNovaCor">Adicionar cor</button>
        </div>
    </div>
</div>

<!-- Input oculto para envio de foto de peça com 1 clique -->
<input type="file" id="inputUploadFotoPeca" accept="image/png,image/jpeg,image/webp" style="display:none;">


<?php require_once __DIR__ . '/includes/modal_pedido.php'; ?>
<script src="assets/js/fabrica.js?v=<?= @filemtime(__DIR__ . '/assets/js/fabrica.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
