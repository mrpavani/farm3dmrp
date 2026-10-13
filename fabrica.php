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
            <button type="button" role="tab" class="aba" data-aba="imprimir" aria-selected="true" aria-controls="abaImprimir">
                🖨️ O Que Imprimir <span class="contador" id="contadorImprimir" hidden></span>
            </button>
            <button type="button" role="tab" class="aba" data-aba="pedidos" aria-selected="false" aria-controls="abaPedidos">
                Pedidos <span class="contador" id="contadorPedidos" hidden></span>
            </button>
            <button type="button" role="tab" class="aba" data-aba="pecas" aria-selected="false" aria-controls="abaPecas">
                Linha de Montagem & Bancada <span class="contador" id="contadorPecas" hidden></span>
            </button>
            <button type="button" role="tab" class="aba" data-aba="fabricar" aria-selected="false" aria-controls="abaFabricar">
                Visão por Produto <span class="contador" id="contadorFabricar" hidden></span>
            </button>
        </div>
        <button type="button" id="btnNovaOrdemEstoque">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
            Produzir para estoque
        </button>
    </div>

    <!-- ===================== ABA O QUE IMPRIMIR (FILA DE IMPRESSÃO 3D) ===================== -->
    <section id="abaImprimir" role="tabpanel">
        <div class="card card-filtros-fabrica nao-imprimir">
            <div class="filtros">
                <div style="flex: 1.6; min-width: 210px;">
                    <label for="imprimirBusca">Buscar peça ou produto</label>
                    <div class="busca-input-wrap">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                        <input type="search" id="imprimirBusca" placeholder="Filtrar por nome de peça, produto ou cor…">
                    </div>
                </div>
                <div style="min-width: 170px;">
                    <label for="imprimirFiltroCor">Filamento / Cor</label>
                    <select id="imprimirFiltroCor">
                        <option value="">Todas as cores</option>
                    </select>
                </div>
                <div style="min-width: 160px;">
                    <label for="imprimirFiltroUrgencia">Prioridade / Prazo</label>
                    <select id="imprimirFiltroUrgencia">
                        <option value="">Todas as prioridades</option>
                        <option value="atrasado">🚨 Atrasados (pedidos vencidos)</option>
                        <option value="hoje">⚡ Urgentes (hoje / amanhã)</option>
                        <option value="proximo">📅 Próximos dias</option>
                        <option value="estoque">📦 Produção para estoque</option>
                    </select>
                </div>
                <div style="min-width: 140px;">
                    <label for="imprimirFiltroTipo">Estrutura</label>
                    <select id="imprimirFiltroTipo">
                        <option value="">Todos os tipos</option>
                        <option value="peca">🧩 Peças de montagem</option>
                        <option value="simples">🔹 Produtos simples</option>
                    </select>
                </div>
                <div class="acoes">
                    <button type="button" class="secundario" id="btnImprimirLimparFiltros">Limpar</button>
                    <button type="button" class="secundario" id="btnImprimirGuiaFila" title="Imprimir ficha de produção para colocar na prancheta da fábrica">
                        🖨️ Imprimir Ficha
                    </button>
                    <button type="button" class="secundario" id="btnImprimirAtualizar">Atualizar</button>
                </div>
            </div>

            <!-- Filtros rápidos de 1 clique em pílulas -->
            <div class="pills-filtros-rapidos" id="pillsFiltrosImprimir">
                <button type="button" class="pill-filtro ativo" data-filtro-fila="">Tudo a Imprimir (<span id="countPillFilaTodas">0</span>)</button>
                <button type="button" class="pill-filtro" data-filtro-fila="urgentes">🚨 Urgentes &amp; Atrasadas (<span id="countPillFilaUrgentes">0</span>)</button>
                <button type="button" class="pill-filtro" data-filtro-fila="pecas">🧩 Peças de Compostos (<span id="countPillFilaPecas">0</span>)</button>
                <button type="button" class="pill-filtro" data-filtro-fila="simples">🔹 Produtos Simples (<span id="countPillFilaSimples">0</span>)</button>
            </div>
        </div>

        <!-- Banner cabeçalho de impressão (só aparece no papel ao imprimir) -->
        <div class="guia-impressao-cabecalho" style="display:none;">
            <h2>🖨️ Guia da Fábrica 3D — Fila de Impressão</h2>
            <p>Gerado em: <?= date('d/m/Y H:i') ?> · Marque os itens conforme forem finalizados na impressora.</p>
        </div>

        <div class="resumo-grid" id="imprimirResumo"></div>

        <!-- Tabela da Fila de Impressão -->
        <section class="card card-lista" id="secaoFilaImpressaoCard">
            <div class="tabela-rolagem">
                <table class="tabela-bancada-fabrica tabela-fila-prod">
                    <thead>
                        <tr>
                            <th style="width: 50px; text-align: center;">Item</th>
                            <th style="min-width: 230px;">O Que Imprimir (Modelo / Peça)</th>
                            <th style="min-width: 160px;">Cor &amp; Filamento</th>
                            <th style="min-width: 160px;">Prazo &amp; Pedidos</th>
                            <th style="width: 120px;" class="num">Tempo Total</th>
                            <th style="width: 130px; text-align: center;">A Imprimir</th>
                            <th style="width: 180px; text-align: right;" class="nao-imprimir">Ação</th>
                        </tr>
                    </thead>
                    <tbody id="gridFilaImpressao"></tbody>
                </table>
            </div>
            <div class="rodape-lista" id="rodapeFilaImpressao"></div>
        </section>
    </section>

    <!-- ===================== ABA PEDIDOS ===================== -->
    <section id="abaPedidos" role="tabpanel" hidden>
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

<!-- Modal: Ajuste de Inventário Físico de Peça Produzida -->
<div id="modalAjusteInventarioPeca" class="modal" hidden>
    <div class="card modal-caixa modal-pequeno" role="dialog" aria-modal="true" aria-labelledby="modalAjustePecaTitulo">
        <form id="formAjusteInventarioPeca" autocomplete="off" novalidate>
            <input type="hidden" id="ajustePecaId">
            <input type="hidden" id="ajustePecaProdutoId">
            <div class="modal-cabecalho">
                <div>
                    <h2 id="modalAjustePecaTitulo">Ajuste de Inventário de Peça</h2>
                    <p id="modalAjustePecaSub" class="modal-sub"></p>
                </div>
                <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="modal-corpo">
                <div style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px 14px; margin-bottom: 12px; font-size: 13px;">
                    <div>Saldo registrado no sistema: <b id="ajustePecaSaldoAtualTexto">0 un.</b></div>
                </div>

                <div>
                    <label for="ajustePecaNovoSaldo">Novo saldo físico contado (un.) <span class="obrigatorio">*</span></label>
                    <input type="number" id="ajustePecaNovoSaldo" min="0" step="1" required style="font-size: 22px; font-weight: 700; height: 48px; text-align: center;">
                </div>

                <div style="margin-top: 10px;">
                    <label for="ajustePecaDiferenca">Diferença / Variação no Estoque</label>
                    <input type="text" id="ajustePecaDiferenca" readonly style="background: var(--surface-3); font-weight: 700; text-align: center;">
                </div>

                <div style="margin-top: 10px;">
                    <label for="ajustePecaMotivo">Motivo do Ajuste de Inventário</label>
                    <input type="text" id="ajustePecaMotivo" placeholder="Ex.: Conferência física, perda, peça avariada..." list="listaMotivosAjustePeca" value="Conferência de inventário físico">
                    <datalist id="listaMotivosAjustePeca">
                        <option value="Conferência de inventário físico">
                        <option value="Peça danificada / avaria">
                        <option value="Perda de material / defeito">
                        <option value="Sobra de produção / acerto">
                        <option value="Contagem física de bancada">
                    </datalist>
                </div>
            </div>
            <div class="modal-rodape">
                <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
                <button type="submit" id="btnSalvarAjustePeca" class="primario">Gravar Ajuste</button>
            </div>
        </form>
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
            <label for="modalNovaCorNome">Cor do filamento</label>
            <select id="modalNovaCorNome" style="width:100%; font-size:13px;"></select>
            <input type="text" id="modalNovaCorCustom" placeholder="Digitar outra cor..." style="display:none; margin-top:6px; width:100%; font-size:13px;" maxlength="50">
            <label for="modalNovaCorPeso" style="margin-top:10px;">Filamento gasto desta cor por peça (g)</label>
            <div class="input-com-unidade">
                <input type="number" id="modalNovaCorPeso" min="0" step="0.1" placeholder="0.0">
                <span class="unidade">g</span>
            </div>
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

<!-- Modal: Detalhe da Situação do Produto & O Que Falta -->
<div id="modalDetalheSituacao" class="modal" hidden>
    <div class="card modal-caixa modal-largo modal-situacao-dialog" role="dialog" aria-modal="true" aria-labelledby="modalSituacaoTitulo">
        <div class="modal-cabecalho">
            <div style="display:flex;align-items:center;gap:12px;">
                <div id="modalSituacaoFoto" style="width:48px;height:48px;border-radius:8px;background:var(--surface-3);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;overflow:hidden;flex-shrink:0;"></div>
                <div>
                    <h2 id="modalSituacaoTitulo" style="margin:0;font-size:18px;">Situação do Produto</h2>
                    <p id="modalSituacaoSub" class="modal-sub" style="margin-top:2px;"></p>
                </div>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <div class="modal-corpo" style="display:flex;flex-direction:column;gap:14px;">
            <!-- KPIs da Situação -->
            <div id="modalSituacaoKpis" class="resumo-grid" style="grid-template-columns:repeat(auto-fit, minmax(130px, 1fr));gap:10px;"></div>

            <!-- Alerta / Diagnóstico de Gargalo -->
            <div id="modalSituacaoDiagnosticoAlerta" style="padding:10px 14px;border-radius:var(--radius-sm);font-size:13px;font-weight:600;display:flex;align-items:center;gap:10px;"></div>

            <!-- Tabela Detalhada de Peças/Componentes -->
            <div style="border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;">
                <div class="tabela-rolagem" style="max-height:360px;overflow-y:auto;">
                    <table style="margin:0;width:100%;font-size:12.5px;">
                        <thead style="position:sticky;top:0;background:var(--surface-2);z-index:2;">
                            <tr>
                                <th style="width:42px;text-align:center;">Foto</th>
                                <th>Peça / Componente</th>
                                <th>Cores / Filamento</th>
                                <th class="num">Qtd / un</th>
                                <th class="num">Estoque Bancada</th>
                                <th class="num">Necessário</th>
                                <th class="num" style="min-width:110px;">Falta Imprimir</th>
                                <th style="text-align:center;">Situação</th>
                                <th style="text-align:right;width:100px;">Ação</th>
                            </tr>
                        </thead>
                        <tbody id="modalSituacaoTabelaCorpo"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="modal-rodape" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div id="modalSituacaoRodapeTexto" style="font-size:12px;color:var(--text-3);"></div>
            <div style="display:flex;gap:8px;">
                <button type="button" class="secundario" data-fechar-modal>Fechar</button>
                <button type="button" class="primario" id="modalSituacaoBtnIrFila" onclick="App.modal.fechar('modalDetalheSituacao'); mostrarAba('imprimir');">
                    Ver na Fila de Impressão 🖨️
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Inputs ocultos para envio de foto de peça e de produto com 1 clique -->
<input type="file" id="inputUploadFotoPeca" accept="image/png,image/jpeg,image/webp" style="display:none;">
<input type="file" id="inputUploadFotoProduto" accept="image/png,image/jpeg,image/webp" style="display:none;">


<?php require_once __DIR__ . '/includes/modal_pedido.php'; ?>
<script src="assets/js/fabrica.js?v=<?= @filemtime(__DIR__ . '/assets/js/fabrica.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
