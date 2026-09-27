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

        <select id="filtroTipo" aria-label="Filtrar por tipo de produto" style="min-width: 220px;">
            <option value="">Todos os tipos</option>
            <option value="simples">🔹 Simples (peça única)</option>
            <option value="composto">🧩 Composto (montagem de peças)</option>
            <option value="componente">⚙️ Peça Avulsa / Componente</option>
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
                        <th class="num">Estoque</th>
                        <th class="num">Pedidos</th>
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
    <div class="card modal-caixa modal-produto-eng" role="dialog" aria-modal="true" aria-labelledby="prodTituloModal">
        <form id="formProduto" autocomplete="off" novalidate>
            <!-- Cabeçalho Fixo -->
            <div class="modal-cabecalho">
                <div class="prod-modal-head-info">
                    <div class="prod-icone-circulo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/></svg>
                    </div>
                    <div>
                        <h2 id="prodTituloModal">Novo produto</h2>
                        <p class="modal-sub" id="prodSubModal">Cadastre especificações técnicas, filamento, cores e precificação dinâmica.</p>
                    </div>
                </div>
                <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <!-- Corpo Rolável com Grid Inteligente -->
            <div class="modal-corpo prod-modal-corpo">
                <!-- Seção 1: Identificação & Parâmetros Base -->
                <div class="prod-form-secao">
                    <div class="prod-secao-titulo">
                        <span>1</span>
                        <h3>Identificação do Produto</h3>
                    </div>

                    <div class="prod-grid-topo">
                        <div class="campo-nome">
                            <label for="prodNome">Nome do Produto <span class="obrigatorio">*</span></label>
                            <input type="text" id="prodNome" maxlength="150" required placeholder="Ex.: Helicóptero, Suporte Articulado, Vaso...">
                        </div>
                        <div class="campo-tipo">
                            <label for="prodTipo">Estrutura do Produto <span class="obrigatorio">*</span></label>
                            <select id="prodTipo">
                                <option value="composto">🧩 Produto Composto (Montagem de Peças / Componentes)</option>
                                <option value="simples">🔹 Produto Simples (Peça única, monocor ou multicor)</option>
                                <option value="componente">⚙️ Peça Avulsa / Reposição</option>
                            </select>
                            <div id="prodTipoExplicacao" class="prod-tipo-dica"></div>
                        </div>
                    </div>

                    <div class="prod-grid-status">
                        <div>
                            <label for="prodEstoque">Estoque Inicial (un)</label>
                            <input type="number" id="prodEstoque" min="0" step="1" placeholder="0" inputmode="numeric">
                        </div>
                        <div>
                            <label for="prodAtivo">Status</label>
                            <select id="prodAtivo">
                                <option value="1">Ativo (visível para pedidos)</option>
                                <option value="0">Inativo</option>
                            </select>
                        </div>
                        <div>
                            <div class="rotulo-com-badge">
                                <label for="prodTempo">Tempo de Impressão (1 un)</label>
                                <span id="tagOrigemTempo" class="badge-origem-soma">🔒 Soma</span>
                            </div>
                            <input type="text" id="prodTempo" placeholder="HH:mm:ss (ex.: 01:30:00)">
                            <small id="dicaProdTempo" class="prod-dica-campo"></small>
                        </div>
                    </div>
                </div>

                <!-- Seção 2A: Cores / Multicor (Simples) -->
                <div id="boxMulticorProduto" class="prod-card-multicor">
                    <div class="prod-multicor-header">
                        <label class="prod-toggle-label">
                            <input type="checkbox" id="checkProdMulticor">
                            <span class="prod-toggle-texto">
                                <strong>🎨 Produto Multicor (troca de cor na impressão / AMS)</strong>
                                <small>Habilite para cadastrar o consumo e tempo por cada cor individualmente.</small>
                            </span>
                        </label>
                        <span id="resumoCoresProdBadge" class="prod-badge-resumo"></span>
                    </div>
                    <div id="conteudoMulticorProd" class="prod-multicor-corpo" style="display:none;">
                        <p class="prod-ajuda-texto">
                            💡 Cadastre cada cor utilizada. O filamento total e o tempo do produto serão calculados automaticamente:
                        </p>
                        <div id="listaCoresProduto" class="prod-lista-cores"></div>
                        <button type="button" id="btnAdicionarCorProd" class="secundario pequeno">+ Adicionar Cor</button>
                    </div>
                </div>

                <!-- Seção 2B: Peças do Produto (Composto) -->
                <div id="boxPecasProduto" class="prod-card-pecas" style="display:none;">
                    <div class="prod-pecas-header">
                        <div>
                            <strong>🧩 Composição de Peças (Ficha Técnica)</strong>
                            <span id="resumoPecasProdBadge" class="prod-badge-resumo">0 peça(s)</span>
                        </div>
                        <button type="button" id="btnAbrirFichaCompletaModalProd" class="secundario pequeno" style="display:none;" title="Abrir Ficha Técnica completa">Abrir Ficha Completa ↗</button>
                    </div>
                    <p class="prod-ajuda-texto">
                        O tempo de impressão e o filamento gasto deste produto são a <b>soma das peças cadastradas</b> abaixo:
                    </p>
                    <div id="listaPecasModalProd" class="prod-lista-pecas"></div>
                    <button type="button" id="btnAdicionarPecaModalProd" class="secundario pequeno">+ Adicionar Peça</button>
                </div>

                <!-- Seção 3: Precificação Inteligente & Custos -->
                <div class="prod-card-precificacao">
                    <div class="prod-prec-topo">
                        <div class="prod-secao-titulo" style="margin: 0;">
                            <span>2</span>
                            <h3>Custos &amp; Formação de Preço de Venda</h3>
                        </div>
                        <span class="prod-tag-peps" id="tagCustoOrigem">Calculadora dinâmica PEPS</span>
                    </div>

                    <div class="prod-prec-layout">
                        <!-- Lado Esquerdo: Parâmetros de Custo -->
                        <div class="prod-custos-grid">
                            <div class="campo-custo">
                                <div class="rotulo-com-badge">
                                    <label for="prodPeso">Filamento Total (g) <span class="obrigatorio">*</span></label>
                                    <span id="tagOrigemPeso" class="badge-origem-soma">🔒 Soma</span>
                                </div>
                                <div class="input-com-unidade">
                                    <input type="number" id="prodPeso" min="0" step="0.1" placeholder="45.0" inputmode="decimal">
                                    <span class="unidade">g</span>
                                </div>
                                <small id="dicaProdPeso" class="prod-dica-campo"></small>
                            </div>

                            <div class="campo-custo">
                                <label for="prodCustoFilamento">Custo Filamento (R$)</label>
                                <div class="input-com-unidade">
                                    <span class="moeda">R$</span>
                                    <input type="number" id="prodCustoFilamento" class="com-moeda" step="0.01" min="0" placeholder="0,00">
                                </div>
                            </div>

                            <div class="campo-custo">
                                <label for="prodTemEmbalagem">Embalagem?</label>
                                <div class="grupo-embalagem">
                                    <select id="prodTemEmbalagem">
                                        <option value="0">Não</option>
                                        <option value="1">Sim</option>
                                    </select>
                                    <div id="boxValorEmbalagem" style="display:none;" class="input-com-unidade">
                                        <span class="moeda">R$</span>
                                        <input type="number" id="prodValorEmbalagem" class="com-moeda" min="0" step="0.01" placeholder="2,50" value="0.00" inputmode="decimal">
                                    </div>
                                </div>
                            </div>

                            <div class="campo-custo">
                                <label for="prodValorOutros" title="Gastos extras: energia, acabamento, fitas...">Outros Custos (R$)</label>
                                <div class="input-com-unidade">
                                    <span class="moeda">R$</span>
                                    <input type="number" id="prodValorOutros" class="com-moeda" min="0" step="0.01" placeholder="0,00" value="0.00" inputmode="decimal">
                                </div>
                            </div>

                            <div class="campo-custo campo-margem-inteira">
                                <div class="rotulo-margem">
                                    <label for="prodMargemLucro">Margem de Lucro (%)</label>
                                    <div class="atalhos-margem">
                                        <button type="button" onclick="$('prodMargemLucro').value=50; recalcularFormacaoPreco(true);">50%</button>
                                        <button type="button" onclick="$('prodMargemLucro').value=100; recalcularFormacaoPreco(true);">100%</button>
                                        <button type="button" onclick="$('prodMargemLucro').value=150; recalcularFormacaoPreco(true);">150%</button>
                                        <button type="button" onclick="$('prodMargemLucro').value=200; recalcularFormacaoPreco(true);">200%</button>
                                    </div>
                                </div>
                                <div class="input-com-unidade">
                                    <input type="number" id="prodMargemLucro" min="0" step="1" placeholder="100" value="100" inputmode="numeric">
                                    <span class="unidade">%</span>
                                </div>
                            </div>
                        </div>

                        <!-- Lado Direito: Painel Financeiro & Preço de Venda -->
                        <div class="prod-painel-venda">
                            <div class="prod-resumo-financeiro" id="resumoFormacaoPreco">
                                <div class="resumo-linha">
                                    <span>🧵 Filamento:</span>
                                    <b id="resumoCustoFilamento">R$ 0,00</b>
                                </div>
                                <div class="resumo-linha">
                                    <span>📦 Embalagem:</span>
                                    <b id="resumoCustoEmbalagem">R$ 0,00</b>
                                </div>
                                <div class="resumo-linha">
                                    <span>⚡ Outros Custos:</span>
                                    <b id="resumoCustoOutros">R$ 0,00</b>
                                </div>
                                <div class="resumo-linha destaque-custo">
                                    <span>💼 Custo Total Base:</span>
                                    <b id="resumoCustoTotal">R$ 0,00</b>
                                </div>
                                <div class="resumo-linha destaque-margem">
                                    <span>📈 Lucro Estimado:</span>
                                    <b id="resumoValorMargem">+ R$ 0,00</b>
                                </div>
                            </div>

                            <div class="prod-bloco-preco-final">
                                <label for="prodPreco">Preço Final de Venda Sugerido</label>
                                <div class="input-preco-destaque">
                                    <span class="moeda-grande">R$</span>
                                    <input type="number" id="prodPreco" min="0" step="0.01" placeholder="0,00" inputmode="decimal">
                                </div>
                                <button type="button" id="btnRecalcularPreco" class="btn-recalcular">
                                    🔄 Recalcular Preço Sugerido
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Seção 4: Descrição Técnica -->
                <div class="prod-form-secao" style="margin-bottom: 0;">
                    <label for="prodDescricao" style="margin-top: 0;">Descrição Técnica &amp; Observações</label>
                    <textarea id="prodDescricao" rows="2" placeholder="Material recomendado, bico 0.4mm, preenchimento, instruções de acabamento..."></textarea>
                </div>
            </div>

            <!-- Rodapé Fixo -->
            <div class="modal-rodape">
                <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
                <button type="submit" id="btnSalvarProduto" class="primario" style="font-weight: 700; padding: 0 20px;">Salvar Produto</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal de Ficha Técnica (BOM) & Diagnóstico de Montagem -->
<div id="modalComposicao" class="modal" hidden>
    <div class="card modal-caixa modal-extra-largo" role="dialog" aria-modal="true" aria-labelledby="bomTituloModal">
        <div class="modal-cabecalho">
            <div>
                <h2 id="bomTituloModal">Ficha Técnica & Montagem</h2>
                <p class="modal-sub" id="bomSubModal">Receita de componentes e cálculo de capacidade imediata.</p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- Faixa de KPIs em Linha Compacta -->
        <div class="bom-kpi-strip" id="bomKpiStrip">
            <div class="bom-kpi-item destaque-verde" id="kpiCapacidadeBox">
                <span class="lbl">Capacidade Imediata</span>
                <div class="val" id="kpiCapacidadeQtd">0</div>
                <span id="kpiCapacidadeGargalo" style="font-size:11px;color:var(--text-3);">Estoque de peças</span>
            </div>
            <div class="bom-kpi-item">
                <span class="lbl">Estoque Pronto</span>
                <div class="val" id="kpiEstoquePronto">0</div>
                <span style="font-size:11px;color:var(--text-3);">Unidades finalizadas</span>
            </div>
            <div class="bom-kpi-item">
                <span class="lbl">Filamento / un</span>
                <div class="val" id="kpiPeso1un">0 g</div>
                <span id="kpiCores1un" style="font-size:11px;color:var(--text-3);">Soma das peças</span>
            </div>
            <div class="bom-kpi-item">
                <span class="lbl">Tempo / un</span>
                <div class="val" id="kpiTempo1un">00:00:00</div>
                <span style="font-size:11px;color:var(--text-3);">Produção de 1 un</span>
            </div>
        </div>

        <div class="modal-corpo">
            <!-- Ações Rápidas: Montagem e Simulação em Linha Única -->
            <div class="bom-acoes-linha">
                <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                    <strong>Montar agora:</strong>
                    <input type="number" id="qtdMontagemExecutar" min="1" value="1" style="width: 70px; height: 30px; text-align: center; margin: 0;">
                    <span style="color: var(--text-3); font-size: 12px;">un.</span>
                    <button type="button" id="btnExecutarMontagem" class="sucesso pequeno" style="min-height: 30px; padding: 0 12px; font-size: 12px;">
                        ⚒️ Montar e Baixar Peças
                    </button>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <span style="color: var(--text-2); font-size: 12.5px;">Simular meta:</span>
                    <input type="number" id="metaSimulacao" min="1" value="1" style="width: 64px; height: 30px; padding: 0 6px; text-align: center; margin: 0;">
                    <button type="button" id="btnRecalcularMeta" class="secundario pequeno" style="min-height: 30px; padding: 0 10px; font-size: 12px;">Simular</button>
                </div>
            </div>

            <!-- Painel de Previsão Futura da Meta -->
            <div id="painelPrevisaoMeta" style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 12px; margin-bottom: 10px; font-size: 12.5px; display: flex; flex-direction: column; gap: 4px;"></div>

            <!-- Tabela de Diagnóstico / Peças -->
            <div class="tabela-rolagem" style="margin-bottom: 14px; max-height: 200px; overflow-y: auto; border: 1px solid var(--border); border-radius: var(--radius-sm);">
                <table>
                    <thead style="position: sticky; top: 0; z-index: 2;">
                        <tr>
                            <th style="width: 36px; text-align: center;">Foto</th>
                            <th>Peça / Componente</th>
                            <th>Cores e Filamento</th>
                            <th class="num">Qtd / un</th>
                            <th class="num">Peso (1 un)</th>
                            <th class="num">Tempo (1 un)</th>
                            <th class="num">Estoque</th>
                            <th class="num">Meta</th>
                            <th>Situação</th>
                        </tr>
                    </thead>
                    <tbody id="tabelaDiagnosticoBOM"></tbody>
                </table>
            </div>

            <!-- Editor da Ficha Técnica -->
            <details style="border-top: 1px solid var(--border); padding-top: 10px; margin-top: 6px;" open>
                <summary style="font-weight: 700; cursor: pointer; color: var(--primary); padding: 4px 0; font-size: 13px;">
                    ⚙️ Configurar Peças e Ficha Técnica
                </summary>
                <div style="margin-top: 8px;">
                    <!-- Barra com o Totalizador em Tempo Real -->
                    <div id="editorBOMResumoInstantaneo" style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;background:var(--surface-3);border:1px solid var(--border);padding:6px 12px;border-radius:var(--radius-sm);margin-bottom:8px;font-size:12px;">
                        <div>⏱️ <b>Tempo Total (1 un):</b> <span id="editorTempoTotal" style="font-weight:700;color:var(--primary);">00:00:00</span></div>
                        <div>⚖️ <b>Filamento Total (1 un):</b> <span id="editorPesoTotal" style="font-weight:700;color:var(--primary);">0 g</span></div>
                        <div id="editorCoresBadges" style="display:flex;gap:4px;flex-wrap:wrap;"></div>
                    </div>

                    <div class="lista-editor-bom" id="listaEditorBOM"></div>

                    <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                        <button type="button" id="btnAdicionarLinhaBOM" class="secundario pequeno">
                            + Adicionar Peça
                        </button>
                        <button type="button" id="btnSalvarBOM" class="primario pequeno">
                            Salvar Ficha Técnica
                        </button>
                    </div>
                </div>
            </details>
        </div>

        <div class="modal-rodape">
            <button type="button" class="secundario" data-fechar-modal>Fechar</button>
        </div>
    </div>
</div>

<input type="file" id="inputFotoPecaProdutos" accept="image/png,image/jpeg,image/webp" style="display:none;">
<datalist id="listaCoresFilamentosSugestoes"></datalist>

<script src="assets/js/produtos.js?v=<?= filemtime(__DIR__ . '/assets/js/produtos.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
