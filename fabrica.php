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

    <!-- Painel de Cards Numéricos da Linha de Produção -->
    <div class="resumo-grid kpis-fabrica-topo" id="fabricaHeroKpis"></div>

    <div class="card-titulo" style="margin-bottom:18px;">
        <div class="abas" role="tablist" style="margin:0;">
            <button type="button" role="tab" class="aba" data-aba="pedidos" aria-selected="true" aria-controls="abaPedidos">
                Pedidos <span class="contador" id="contadorPedidos" hidden></span>
            </button>
            <button type="button" role="tab" class="aba" data-aba="fabricar" aria-selected="false" aria-controls="abaFabricar">
                Visão por Produto <span class="contador" id="contadorFabricar" hidden></span>
            </button>
            <button type="button" role="tab" class="aba" data-aba="pecas" aria-selected="false" aria-controls="abaPecas">
                Linha de Produção / Bancada <span class="contador" id="contadorPecas" hidden></span>
            </button>
        </div>
        <button type="button" id="btnNovaOrdemEstoque">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Produzir para estoque
        </button>
    </div>

    <!-- ===================== ABA PEDIDOS ===================== -->
    <section id="abaPedidos" role="tabpanel">
        <div class="card card-filtros-fabrica">
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
        <div class="card card-filtros-fabrica">
            <div class="filtros">
                <div style="flex: 1.5; min-width: 200px;">
                    <label for="fabProdutoBusca">Buscar por produto</label>
                    <input type="search" id="fabProdutoBusca" placeholder="Filtrar por nome do produto…">
                </div>
                <div>
                    <label for="fabEntregaAte">Entrega até</label>
                    <input type="date" id="fabEntregaAte">
                </div>
                <div>
                    <label for="fabProduto">Selecionar</label>
                    <select id="fabProduto">
                        <option value="">Todos os produtos</option>
                    </select>
                </div>
                <div class="acoes">
                    <button type="button" class="secundario" id="btnFabLimpar">Limpar</button>
                    <button type="button" class="secundario" id="btnFabAtualizar">Atualizar</button>
                </div>
            </div>
        </div>

        <div class="resumo-grid" id="fabResumo"></div>
        <div id="listaFabricar"></div>
    </section>

    <!-- ===================== ABA PRODUTOS & PRODUÇÃO ===================== -->
    <section id="abaPecas" role="tabpanel" hidden>
        <div class="card card-filtros-fabrica">
            <div class="filtros">
                <div style="flex: 1.6; min-width: 200px;">
                    <label for="pecaBusca">Buscar produto ou peça</label>
                    <div class="busca-input-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input type="search" id="pecaBusca" placeholder="Digite o nome do produto (ex.: Suporte, Helicóptero)...">
                    </div>
                </div>
                <div>
                    <label for="pecaFiltroStatus">Status da Fila</label>
                    <select id="pecaFiltroStatus">
                        <option value="">Todos os produtos</option>
                        <option value="montavel">🚀 Prontos para montar</option>
                        <option value="imprimir">🖨️ Com peças a imprimir</option>
                        <option value="ok">✅ Estoque suficiente</option>
                    </select>
                </div>
                <div class="acoes">
                    <button type="button" class="secundario" id="btnPecasLimpar">Limpar</button>
                    <button type="button" class="secundario" id="btnPecasAtualizar">Atualizar</button>
                </div>
            </div>

            <!-- Filtros rápidos de 1 clique em pílulas -->
            <div class="pills-filtros-rapidos" id="pillsFiltrosFabrica">
                <button type="button" class="pill-filtro ativo" data-filtro="">Todos os produtos</button>
                <button type="button" class="pill-filtro" data-filtro="montavel">🚀 Prontos para montar (<span id="countPillMontavel">0</span>)</button>
                <button type="button" class="pill-filtro" data-filtro="imprimir">🖨️ A imprimir (<span id="countPillImprimir">0</span>)</button>
                <button type="button" class="pill-filtro" data-filtro="ok">✅ Peças OK</button>
            </div>
        </div>

        <div class="resumo-grid" id="pecasResumo"></div>

        <!-- Listagem de Produtos / Linha de Produção -->
        <section class="card card-lista">
            <div class="tabela-rolagem">
                <table class="tabela-bancada-fabrica">
                    <thead>
                        <tr>
                            <th style="min-width: 220px;">Produto</th>
                            <th style="min-width: 170px;">Prazo</th>
                            <th style="width: 130px; text-align: center;">Situação</th>
                            <th style="width: 130px;" class="num">Montável Agora</th>
                            <th style="width: 110px;" class="num">A Fabricar</th>
                            <th style="width: 110px;" class="num">Estoque</th>
                            <th style="width: 130px;" class="num">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="gridPecasFabrica"></tbody>
                </table>
            </div>
            <div class="rodape-lista" id="rodapePecas"></div>
        </section>
    </section>
</main>

<!-- Modal: registrar produção de pedido tradicional -->
<div id="modalProducao" class="modal" hidden>
    <div class="card modal-caixa modal-medio" role="dialog" aria-modal="true" aria-labelledby="modalTitulo">
        <div class="modal-cabecalho">
            <div>
                <h2 id="modalTitulo">Registrar produção</h2>
                <p id="modalItemInfo" class="modal-sub"></p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-corpo">
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
            <p class="vazio" style="margin:10px 0 0; font-size:12px;">Será registrado por <strong><?= e($usuario['nome']) ?></strong>.</p>
        </div>
        <div class="modal-rodape">
            <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
            <button type="button" id="btnConfirmarProducao">Registrar</button>
        </div>
    </div>
</div>

<!-- Modal: registrar produção rápida de peça solta impressa -->
<div id="modalProduzirPeca" class="modal" hidden>
    <div class="card modal-caixa modal-pequeno" role="dialog" aria-modal="true" aria-labelledby="modalPecaTitulo">
        <div class="modal-cabecalho">
            <div>
                <h2 id="modalPecaTitulo">Registrar Impressão de Peça</h2>
                <p id="modalPecaSub" class="modal-sub"></p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-corpo">
            <div>
                <label for="modalPecaQtd" style="text-align: center; margin-top: 4px;">Quantidade de peças impressas</label>
                <input type="number" id="modalPecaQtd" min="1" value="1" style="font-size: 22px; font-weight: 700; text-align: center; height: 48px;">
                <div class="atalhos" id="atalhosQtdPeca" style="margin-top: 10px; justify-content: center;"></div>
            </div>
        </div>
        <div class="modal-rodape">
            <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
            <button type="button" id="btnConfirmarProducaoPeca">Adicionar ao Estoque</button>
        </div>
    </div>
</div>

<!-- Modal: cadastrar uma nova cor de uma peça já existente, direto da bancada -->
<div id="modalNovaCor" class="modal" hidden>
    <div class="card modal-caixa modal-pequeno" role="dialog" aria-modal="true" aria-labelledby="modalNovaCorTitulo">
        <div class="modal-cabecalho">
            <div>
                <h2 id="modalNovaCorTitulo">Adicionar Cor à Peça</h2>
                <p id="modalNovaCorSub" class="modal-sub"></p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-corpo">
            <label for="modalNovaCorNome">Nome da cor / filamento</label>
            <input type="text" id="modalNovaCorNome" placeholder="Ex.: Branco, Cinza..." maxlength="50">
            <label for="modalNovaCorPeso">Filamento gasto desta cor por peça (g)</label>
            <input type="number" id="modalNovaCorPeso" min="0" step="0.1" placeholder="0.0 g">
        </div>
        <div class="modal-rodape">
            <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
            <button type="button" id="btnConfirmarNovaCor">Adicionar cor</button>
        </div>
    </div>
</div>

<!-- Modal: Gerenciar peças e atualizar peças fabricadas de um produto -->
<div id="modalGerenciarPecas" class="modal" hidden>
    <div class="card modal-caixa modal-pecas-gerenciar" role="dialog" aria-modal="true" aria-labelledby="modalGerenciarPecasTitulo">
        <div class="modal-cabecalho">
            <div class="modal-titulo-com-foto">
                <div id="modalGerenciarPecasFoto" class="modal-prod-foto"></div>
                <div>
                    <h2 id="modalGerenciarPecasTitulo">Peças do Produto</h2>
                    <p id="modalGerenciarPecasSub" class="modal-sub"></p>
                </div>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        
        <!-- Resumo / Métricas Rápidas do Produto no Modal -->
        <div class="modal-resumo-pecas" id="modalGerenciarPecasResumo"></div>

        <!-- Lista de Peças e Variações de Cores com Steppers -->
        <div class="modal-lista-pecas-corpo" id="modalGerenciarPecasLista"></div>

        <div class="modal-rodape">
            <p class="modal-dica-rodape">💡 O saldo das peças é salvo automaticamente.</p>
            <button type="button" class="primario" data-fechar-modal>Concluir</button>
        </div>
    </div>
</div>

<!-- Modal: Montar unidades de um produto -->
<div id="modalMontarProduto" class="modal" hidden>
    <div class="card modal-caixa modal-medio" role="dialog" aria-modal="true" aria-labelledby="modalMontarTitulo">
        <div class="modal-cabecalho">
            <div>
                <h2 id="modalMontarTitulo">Montar Produto</h2>
                <p id="modalMontarSub" class="modal-sub"></p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-corpo">
            <div class="modal-montar-resumo" id="modalMontarResumo"></div>
            <div style="margin-top: 12px;">
                <label for="modalMontarQtd" style="text-align: center;">Quantidade a montar</label>
                <input type="number" id="modalMontarQtd" min="1" value="1" style="font-size: 22px; font-weight: 700; text-align: center; height: 48px;">
                <div class="atalhos" id="atalhosMontarQtd" style="margin-top: 8px; justify-content: center;"></div>
            </div>
            <p class="vazio" style="margin: 12px 0 0; font-size: 12px; line-height: 1.4;">
                ℹ️ Ao confirmar, as peças necessárias serão deduzidas do estoque e as unidades montadas serão adicionadas ao estoque do produto acabado.
            </p>
        </div>
        <div class="modal-rodape">
            <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
            <button type="button" id="btnConfirmarMontarModal" class="sucesso">Confirmar Montagem</button>
        </div>
    </div>
</div>

<!-- Input oculto para envio de foto de peça com 1 clique -->
<input type="file" id="inputUploadFotoPeca" accept="image/png,image/jpeg,image/webp" style="display:none;">


<?php require_once __DIR__ . '/includes/modal_pedido.php'; ?>
<script src="assets/js/fabrica.js?v=<?= @filemtime(__DIR__ . '/assets/js/fabrica.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
