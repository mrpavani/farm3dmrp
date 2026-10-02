<?php
require_once __DIR__ . '/includes/auth.php';
$usuario = exigirLoginPagina();
$paginaAtual = 'dashboard.php';
$tituloPagina = 'Painel Gerencial';
$subtituloPagina = 'Visão executiva em tempo real: estoque pronto, custos médios de filamento, carteira de pedidos e decisões estratégicas.';
require __DIR__ . '/includes/header.php';
?>

<main class="dashboard-main">
    <div id="msg" aria-live="polite"></div>

    <!-- Barra de Controle Superior do Dashboard -->
    <div class="dash-topo-acoes nao-imprimir">
        <div class="dash-head-text">
            <h2>Visão Geral da Fábrica</h2>
            <p>Dados consolidados para tomada de decisões operacionais e financeiras.</p>
        </div>
        <div class="dash-botoes-topo">
            <span id="dashDataAtualizacao" class="dash-badge-tempo">Atualizado agora</span>
            <button type="button" id="btnAtualizarDash" class="secundario pequeno" title="Recarregar indicadores">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>
                Atualizar
            </button>
            <a href="produtos.php" class="secundario pequeno">📦 Catálogo</a>
            <a href="filamentos.php" class="secundario pequeno">🧵 Filamentos</a>
            <a href="pedidos.php" class="primario pequeno">+ Pedidos</a>
        </div>
    </div>

    <!-- Grade de 4 Grandes KPI Cards -->
    <div class="dash-kpi-grid">
        <!-- KPI 1: PRODUTOS PRONTOS -->
        <div class="card dash-kpi-card card-kpi-verde">
            <div class="kpi-topo">
                <div class="kpi-icone-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/></svg>
                </div>
                <span class="kpi-tag-status sucesso">Pronta-Entrega</span>
            </div>
            <div class="kpi-corpo">
                <span class="kpi-rotulo">PRODUTOS PRONTOS EM ESTOQUE</span>
                <div class="kpi-numero" id="kpiProdutosUnidades">0 <small>un</small></div>
                <div class="kpi-subinfo" id="kpiProdutosModelos">0 modelos disponíveis</div>
            </div>
            <div class="kpi-rodape-fin">
                <div><span>Valor Venda:</span> <strong id="kpiProdutosValorVenda">R$ 0,00</strong></div>
                <div><span>Custo Base:</span> <b id="kpiProdutosValorCusto">R$ 0,00</b></div>
            </div>
        </div>

        <!-- KPI 2: FILAMENTOS CADASTRADOS & VALOR MÉDIO -->
        <div class="card dash-kpi-card card-kpi-azul">
            <div class="kpi-topo">
                <div class="kpi-icone-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/><path d="M12 3a9 9 0 0 1 9 9"/><path d="M3 12a9 9 0 0 0 9 9"/></svg>
                </div>
                <span class="kpi-tag-status neutro" id="kpiFilamentosAlertasTag">0 alertas</span>
            </div>
            <div class="kpi-corpo">
                <span class="kpi-rotulo">FILAMENTOS CADASTRADOS</span>
                <div class="kpi-numero" id="kpiFilamentosPeso">0,0 <small>kg</small></div>
                <div class="kpi-subinfo" id="kpiFilamentosQtd">0 carretéis / cores cadastradas</div>
            </div>
            <div class="kpi-rodape-fin">
                <div><span>Valor Médio:</span> <strong id="kpiFilamentosCustoMedioKg">R$ 0,00 / kg</strong></div>
                <div><span>Patrimônio:</span> <b id="kpiFilamentosValorTotal">R$ 0,00</b></div>
            </div>
        </div>

        <!-- KPI 3: CARTEIRA DE PEDIDOS -->
        <div class="card dash-kpi-card card-kpi-roxo">
            <div class="kpi-topo">
                <div class="kpi-icone-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M9 12h6M9 16h4"/></svg>
                </div>
                <span class="kpi-tag-status aviso" id="kpiPedidosAbertosProducaoTag">0 ativos</span>
            </div>
            <div class="kpi-corpo">
                <span class="kpi-rotulo">QUANTIDADE DE PEDIDOS</span>
                <div class="kpi-numero" id="kpiPedidosTotal">0 <small>pedidos</small></div>
                <div class="kpi-subinfo" id="kpiPedidosStatusBreakdown">0 abertos · 0 em produção · 0 prontos</div>
            </div>
            <div class="kpi-rodape-fin">
                <div><span>Em Carteira:</span> <strong id="kpiPedidosValorCarteira">R$ 0,00</strong></div>
                <div><span>Faturado:</span> <b id="kpiPedidosValorEntregue">R$ 0,00</b></div>
            </div>
        </div>

        <!-- KPI 4: SAÚDE & URGÊNCIA OPERACIONAL -->
        <div class="card dash-kpi-card card-kpi-alerta">
            <div class="kpi-topo">
                <div class="kpi-icone-box">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                </div>
                <span class="kpi-tag-status perigo" id="kpiPedidosAtrasadosTag">0 atrasados</span>
            </div>
            <div class="kpi-corpo">
                <span class="kpi-rotulo">ATENÇÃO IMEDIATA</span>
                <div class="kpi-numero" id="kpiPedidosUrgentes">0 <small>críticos</small></div>
                <div class="kpi-subinfo" id="kpiPedidosUrgentesTexto">Pedidos atrasados ou com entrega em até 3 dias</div>
            </div>
            <div class="kpi-rodape-fin">
                <div><span>Ticket Médio:</span> <strong id="kpiTicketMedio">R$ 0,00</strong></div>
                <div><span>Lucro Estocado:</span> <b id="kpiLucroPotencial">R$ 0,00</b></div>
            </div>
        </div>
    </div>

    <!-- Linha de Ação Rápida / Resumo da Carteira em Barra Visual -->
    <div class="card dash-barra-status-card" style="margin-bottom: 24px; padding: 16px 20px;">
        <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:10px;">
            <div style="display:flex; align-items:center; gap:8px;">
                <strong>📊 Distribuição da Carteira de Pedidos:</strong>
                <span style="font-size:12px; color:var(--text-3);" id="dashResumoGeralPedidos">0 pedidos cadastrados</span>
            </div>
            <div id="dashLegendaPedidos" style="display:flex; gap:14px; font-size:12px; flex-wrap:wrap;"></div>
        </div>
        <div class="dash-progresso-trilho" id="dashTrilhoPedidos"></div>
    </div>

    <!-- SEÇÃO 1: PRODUTOS PRONTOS (QUAIS E QUANTIDADES) -->
    <section class="card card-lista" style="margin-bottom: 24px;">
        <div class="card-titulo" style="padding: 16px 20px 14px; border-bottom: 1px solid var(--border); margin-bottom: 0; display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap;">
            <div style="flex: 1; min-width: 260px;">
                <h3 style="margin: 0 0 2px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                    <span>📦</span> Produtos Prontos em Estoque &amp; Disponibilidade
                </h3>
                <p style="margin: 0; font-size: 12.5px; color: var(--text-3);">
                    Detalhamento dos produtos finalizados disponíveis para entrega imediata e demanda pendente.
                </p>
            </div>
            <div style="display: flex; align-items: center; gap: 8px; flex-shrink: 0;">
                <div style="position: relative; display: inline-flex; align-items: center; width: 210px;">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="position: absolute; left: 10px; width: 14px; height: 14px; color: var(--muted); pointer-events: none;"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                    <input type="search" id="buscaProdutosProntos" placeholder="Buscar produto…" style="width: 100%; height: 34px; padding-left: 30px; font-size: 12.5px; margin: 0;">
                </div>
                <select id="filtroModoEstoque" style="height: 34px; font-size: 12px; padding: 0 8px; width: 195px; margin: 0;">
                    <option value="apenas_prontos">Estoque Pronto (&gt; 0)</option>
                    <option value="todos">Todos os Produtos</option>
                    <option value="zerados">Sem Estoque (Zerados)</option>
                </select>
            </div>
        </div>

        <div class="tabela-rolagem">
            <table>
                <thead>
                    <tr>
                        <th>Produto</th>
                        <th>Estrutura</th>
                        <th class="num">Estoque Pronto</th>
                        <th class="num">Custo Base</th>
                        <th class="num">Preço Venda</th>
                        <th class="num">Margem Unitária</th>
                        <th class="num">Total em Estoque</th>
                        <th class="num">Demanda Aberta</th>
                        <th class="num">Ação</th>
                    </tr>
                </thead>
                <tbody id="tabelaProdutosProntos"></tbody>
            </table>
        </div>
        <div class="rodape-lista" id="rodapeProdutosProntos"></div>
    </section>

    <!-- SEÇÃO 2: FILAMENTOS CADASTRADOS & GESTÃO DE ESTOQUE/VALOR MÉDIO -->
    <div class="dash-duas-colunas" style="display: grid; grid-template-columns: 1.35fr 1fr; gap: 20px; margin-bottom: 24px;">
        <section class="card card-lista">
            <div class="card-titulo" style="padding: 16px 20px 12px; border-bottom: 1px solid var(--border);">
                <div>
                    <h3 style="margin: 0 0 2px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                        <span>🧵</span> Filamentos Cadastrados &amp; Custo Médio
                    </h3>
                    <p style="margin: 0; font-size: 12.5px; color: var(--text-3);">
                        Estoque por cor, carretéis disponíveis e valor médio ponderado por kg.
                    </p>
                </div>
                <a href="filamentos.php" class="secundario pequeno" style="text-decoration:none;">Gerenciar Filamentos ↗</a>
            </div>

            <div class="tabela-rolagem" style="max-height: 340px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Cor / Material</th>
                            <th>Marca / Tipo</th>
                            <th class="num">Estoque</th>
                            <th class="num">Preço Médio/kg</th>
                            <th class="num">Patrimônio</th>
                            <th>Situação</th>
                        </tr>
                    </thead>
                    <tbody id="tabelaFilamentosDash"></tbody>
                </table>
            </div>
            <div class="rodape-lista" id="rodapeFilamentosDash"></div>
        </section>

        <!-- SEÇÃO 3: PEDIDOS CRÍTICOS & DECISÃO IMEDIATA -->
        <section class="card card-lista">
            <div class="card-titulo" style="padding: 16px 20px 12px; border-bottom: 1px solid var(--border);">
                <div>
                    <h3 style="margin: 0 0 2px 0; font-size: 16px; display: flex; align-items: center; gap: 8px;">
                        <span>⚡</span> Fila Crítica &amp; Decisões Rápidas
                    </h3>
                    <p style="margin: 0; font-size: 12.5px; color: var(--text-3);">
                        Pedidos com prazo vencido ou entrega imediata que demandam atenção da gestão.
                    </p>
                </div>
                <a href="pedidos.php" class="secundario pequeno" style="text-decoration:none;">Ver Pedidos ↗</a>
            </div>

            <div class="tabela-rolagem" style="max-height: 340px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Pedido</th>
                            <th>Cliente</th>
                            <th>Prazo</th>
                            <th class="num">Valor</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody id="tabelaPedidosCriticos"></tbody>
                </table>
            </div>
            <div class="rodape-lista" id="rodapePedidosCriticos"></div>
        </section>
    </div>
</main>

<!-- Modal de Custos do Produto (reaproveitado para consulta rápida direto no Dashboard!) -->
<div id="modalCustosProduto" class="modal" hidden>
    <div class="card modal-caixa modal-custos-eng" role="dialog" aria-modal="true" aria-labelledby="custosTituloModal">
        <div class="modal-cabecalho">
            <div class="prod-modal-head-info">
                <div class="prod-icone-circulo" style="background: rgba(16, 185, 129, 0.1); color: #059669; border-color: rgba(16, 185, 129, 0.25);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/><path d="M12 18V6"/></svg>
                </div>
                <div>
                    <h2 id="custosTituloModal">Custos &amp; Formação de Preço de Venda</h2>
                    <p class="modal-sub" id="custosSubModal">Simule despesas, filamento via PEPS, embalagem e defina a margem de lucro.</p>
                </div>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="modal-corpo" style="padding: 16px 20px;">
            <div class="prod-card-precificacao" style="margin: 0; box-shadow: none;">
                <div class="prod-prec-topo">
                    <div class="prod-secao-titulo" style="margin: 0;">
                        <span style="background:var(--primary);color:#fff;">$</span>
                        <h3 id="custosProdNomeDestaque">Precificação do Produto</h3>
                    </div>
                    <span class="prod-tag-peps" id="custosTagOrigem">Calculadora dinâmica PEPS</span>
                </div>

                <div class="prod-prec-layout">
                    <div class="prod-custos-grid">
                        <div class="campo-custo">
                            <div class="rotulo-com-badge">
                                <label for="custosProdPeso">Filamento Total (g) <span class="obrigatorio">*</span></label>
                                <span id="custosTagOrigemPeso" class="badge-origem-soma">🔒 Calculado</span>
                            </div>
                            <div class="input-com-unidade">
                                <input type="number" id="custosProdPeso" min="0" step="0.1" placeholder="0.0" inputmode="decimal">
                                <span class="unidade">g</span>
                            </div>
                            <small id="custosDicaProdPeso" class="prod-dica-campo" style="display:block;font-size:11px;color:var(--text-3);margin-top:3px;"></small>
                        </div>

                        <div class="campo-custo">
                            <label for="custosProdCustoFilamento">Custo Filamento (R$)</label>
                            <div class="input-com-unidade">
                                <span class="moeda">R$</span>
                                <input type="number" id="custosProdCustoFilamento" class="com-moeda" step="0.01" min="0" placeholder="0,00">
                            </div>
                        </div>

                        <div class="campo-custo">
                            <label for="custosProdTemEmbalagem">Embalagem?</label>
                            <div class="grupo-embalagem">
                                <select id="custosProdTemEmbalagem">
                                    <option value="0">Não</option>
                                    <option value="1">Sim</option>
                                </select>
                                <div id="custosBoxValorEmbalagem" style="display:none;" class="input-com-unidade">
                                    <span class="moeda">R$</span>
                                    <input type="number" id="custosProdValorEmbalagem" class="com-moeda" min="0" step="0.01" placeholder="2,50" value="0.00" inputmode="decimal">
                                </div>
                            </div>
                        </div>

                        <div class="campo-custo">
                            <label for="custosProdValorOutros" title="Gastos extras: energia, acabamento, fitas...">Outros Custos (R$)</label>
                            <div class="input-com-unidade">
                                <span class="moeda">R$</span>
                                <input type="number" id="custosProdValorOutros" class="com-moeda" min="0" step="0.01" placeholder="0,00" value="0.00" inputmode="decimal">
                            </div>
                        </div>

                        <div class="campo-custo campo-margem-inteira">
                            <div class="rotulo-margem">
                                <label for="custosProdMargemLucro">Margem de Lucro (%)</label>
                                <div class="atalhos-margem">
                                    <button type="button" onclick="definirMargemCustos(50)">50%</button>
                                    <button type="button" onclick="definirMargemCustos(100)">100%</button>
                                    <button type="button" onclick="definirMargemCustos(150)">150%</button>
                                    <button type="button" onclick="definirMargemCustos(200)">200%</button>
                                </div>
                            </div>
                            <div class="input-com-unidade">
                                <input type="number" id="custosProdMargemLucro" min="0" step="1" placeholder="100" value="100" inputmode="numeric">
                                <span class="unidade">%</span>
                            </div>
                        </div>
                    </div>

                    <div class="prod-painel-venda">
                        <div class="prod-resumo-financeiro">
                            <div class="resumo-linha">
                                <span>🧵 Filamento:</span>
                                <b id="custosResumoFilamento">R$ 0,00</b>
                            </div>
                            <div class="resumo-linha">
                                <span>📦 Embalagem:</span>
                                <b id="custosResumoEmbalagem">R$ 0,00</b>
                            </div>
                            <div class="resumo-linha">
                                <span>⚡ Outros Custos:</span>
                                <b id="custosResumoOutros">R$ 0,00</b>
                            </div>
                            <div class="resumo-linha destaque-custo">
                                <span>💼 Custo Total Base:</span>
                                <b id="custosResumoCustoTotal">R$ 0,00</b>
                            </div>
                            <div class="resumo-linha destaque-margem">
                                <span>📈 Lucro Estimado:</span>
                                <b id="custosResumoValorMargem">+ R$ 0,00</b>
                            </div>
                        </div>

                        <div class="prod-bloco-preco-final">
                            <label for="custosProdPreco">PREÇO FINAL DE VENDA SUGERIDO</label>
                            <div class="input-preco-destaque">
                                <span class="moeda-grande">R$</span>
                                <input type="number" id="custosProdPreco" min="0" step="0.01" placeholder="0,00" inputmode="decimal">
                            </div>
                            <button type="button" id="btnCustosRecalcular" class="btn-recalcular">
                                🔄 Recalcular Preço Sugerido
                            </button>
                        </div>
                    </div>
                </div>

                <div id="custosBoxDetalhamentoPeps" style="margin-top:14px;border-top:1px solid var(--border);padding-top:10px;display:none;">
                    <details>
                        <summary style="font-size:12px;font-weight:700;color:var(--primary);cursor:pointer;user-select:none;">
                            🔍 Ver Memória de Cálculo por Cor / Lote PEPS
                        </summary>
                        <div class="tabela-rolagem" style="margin-top:8px;max-height:160px;overflow-y:auto;">
                            <table style="font-size:11.5px;">
                                <thead>
                                    <tr>
                                        <th>Cor</th>
                                        <th class="num">Consumo (g)</th>
                                        <th>Filamento Vinculado</th>
                                        <th class="num">Preço/kg</th>
                                        <th class="num">Custo Parcial</th>
                                    </tr>
                                </thead>
                                <tbody id="custosTabelaCorPeps"></tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </div>
        </div>

        <div class="modal-rodape">
            <button type="button" class="secundario" data-fechar-modal>Fechar</button>
            <button type="button" id="btnSalvarCustosModal" class="primario" style="font-weight:700;padding:0 22px;">
                💾 Salvar Precificação
            </button>
        </div>
    </div>
</div>

<script src="assets/js/dashboard.js?v=<?= filemtime(__DIR__ . '/assets/js/dashboard.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
