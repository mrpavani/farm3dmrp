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

            <div class="linha">
                <div style="flex: 2;">
                    <label for="prodNome">Nome <span class="obrigatorio">*</span></label>
                    <input type="text" id="prodNome" maxlength="150" required placeholder="Ex.: Helicóptero ou Hélice">
                </div>
                <div style="flex: 1.2;">
                    <label for="prodTipo">Tipo de item</label>
                    <select id="prodTipo">
                        <option value="simples">Produto Simples</option>
                        <option value="composto">Produto Composto (Final)</option>
                        <option value="componente">Componente / Peça</option>
                    </select>
                </div>
            </div>

            <div class="linha">
                <div>
                    <label for="prodEstoque">Estoque inicial</label>
                    <input type="number" id="prodEstoque" min="0" step="1" placeholder="0" inputmode="numeric">
                </div>
                <div>
                    <label for="prodAtivo">Situação</label>
                    <select id="prodAtivo">
                        <option value="1">Ativo</option>
                        <option value="0">Inativo</option>
                    </select>
                </div>
                <div>
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <label for="prodTempo" style="margin:0;">Tempo de impressão (1 un)</label>
                        <span id="tagOrigemTempo" style="display:none; font-size:10.5px; padding:1px 6px; border-radius:10px; background:rgba(99,102,241,0.12); color:var(--primary); font-weight:600;">Soma</span>
                    </div>
                    <input type="text" id="prodTempo" placeholder="HH:mm:ss (ex.: 01:30:00)" style="margin-top:4px;">
                    <small id="dicaProdTempo" style="font-size:11px; margin-top:2px; display:none;"></small>
                </div>
            </div>

            <!-- Bloco de Cores / Multicor (para produto sem peças) -->
            <div id="boxMulticorProduto" style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 14px; margin: 10px 0;">
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <label style="display:inline-flex; align-items:center; gap:8px; cursor:pointer; font-weight:600; font-size:13px; margin:0; user-select:none;">
                        <input type="checkbox" id="checkProdMulticor" style="width:16px; height:16px; margin:0;">
                        <span>🎨 Produto com mais de uma cor (Multicor)</span>
                    </label>
                    <span id="resumoCoresProdBadge" style="font-size:11.5px; color:var(--text-3); font-weight:600;"></span>
                </div>
                <div id="conteudoMulticorProd" style="display:none; margin-top:10px; border-top:1px dashed var(--border); padding-top:10px;">
                    <p style="font-size:12px; color:var(--text-3); margin-bottom:8px;">
                        Cadastre cada cor utilizada na impressão do produto informando as <b>gramas</b> e o <b>tempo</b> de cada uma. O filamento total e o tempo do produto serão calculados automaticamente pela soma das cores.
                    </p>
                    <div id="listaCoresProduto" style="display:flex; flex-direction:column; gap:6px; margin-bottom:8px;"></div>
                    <button type="button" id="btnAdicionarCorProd" class="secundario pequeno" style="font-size:12px;">+ Adicionar Cor</button>
                </div>
            </div>

            <!-- Bloco de Peças do Produto (quando composto / tem peças) -->
            <div id="boxPecasProduto" style="display:none; background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 14px; margin: 10px 0;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;">
                    <strong style="font-size:13px; color:var(--primary);">🧩 Peças do Produto (Ficha Técnica)</strong>
                    <span id="resumoPecasProdBadge" style="font-size:11.5px; color:var(--text-3); font-weight:600;">0 peça(s)</span>
                </div>
                <p style="font-size:12px; color:var(--text-3); margin-bottom:10px;">
                    O tempo de impressão e o filamento gasto deste produto são a <b>soma das peças cadastradas</b> abaixo.
                </p>
                <div id="listaPecasModalProd" style="display:flex; flex-direction:column; gap:8px; margin-bottom:10px;"></div>
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <button type="button" id="btnAdicionarPecaModalProd" class="secundario pequeno" style="font-size:12px;">+ Adicionar Peça</button>
                    <button type="button" id="btnAbrirFichaCompletaModalProd" class="secundario pequeno" style="display:none; font-size:12px;" title="Abrir Ficha Técnica completa com gestão de estoque e diagnóstico">Abrir Ficha Completa ↗</button>
                </div>
            </div>

            <!-- Bloco de Composição de Custos & Precificação Dinâmica -->
            <div style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 14px; margin: 12px 0;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 10px;">
                    <strong style="font-size: 13.5px; color: var(--text);">💰 Composição de Custos &amp; Precificação</strong>
                    <span style="font-size: 11.5px; color: var(--text-3);" id="tagCustoOrigem">Cálculo dinâmico via PEPS</span>
                </div>

                <div class="linha" style="margin-bottom: 8px;">
                    <div>
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <label for="prodPeso" style="margin:0;">Filamento gasto (g) <span class="obrigatorio">*</span></label>
                            <span id="tagOrigemPeso" style="display:none; font-size:10.5px; padding:1px 6px; border-radius:10px; background:rgba(99,102,241,0.12); color:var(--primary); font-weight:600;">Soma</span>
                        </div>
                        <input type="number" id="prodPeso" min="0" step="0.1" placeholder="Ex.: 45.0 g" inputmode="decimal" style="margin-top:4px;">
                        <small id="dicaProdPeso" style="font-size:11px; margin-top:2px; display:none;"></small>
                    </div>
                    <div>
                        <label for="prodCustoFilamento">Custo Filamento (R$)</label>
                        <input type="number" id="prodCustoFilamento" step="0.01" min="0" placeholder="0,00" style="background: var(--surface-3);">
                    </div>
                </div>

                <div class="linha" style="margin-bottom: 8px;">
                    <div>
                        <label for="prodTemEmbalagem">Tem embalagem?</label>
                        <select id="prodTemEmbalagem">
                            <option value="0">Não</option>
                            <option value="1">Sim</option>
                        </select>
                    </div>
                    <div id="boxValorEmbalagem" style="display:none;">
                        <label for="prodValorEmbalagem">Valor Embalagem (R$)</label>
                        <input type="number" id="prodValorEmbalagem" min="0" step="0.01" placeholder="Ex.: 2,50" value="0.00" inputmode="decimal">
                    </div>
                    <div>
                        <label for="prodValorOutros" title="Gastos extras de entrega, fita, adesivos, luz, depreciação, acabamento...">Outros Gastos (R$)</label>
                        <input type="number" id="prodValorOutros" min="0" step="0.01" placeholder="0,00" value="0.00" inputmode="decimal">
                    </div>
                    <div>
                        <label for="prodMargemLucro">Margem de Lucro (%)</label>
                        <input type="number" id="prodMargemLucro" min="0" step="1" placeholder="100%" value="100" inputmode="numeric">
                    </div>
                </div>

                <!-- Resumo Instantâneo de Formação de Preço -->
                <div id="resumoFormacaoPreco" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 8px; background: var(--surface-3); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 12px; font-size: 12px; margin-top: 10px;">
                    <div><span style="color:var(--text-3);">🧵 Filamento:</span> <b id="resumoCustoFilamento">R$ 0,00</b></div>
                    <div><span style="color:var(--text-3);">📦 Embalagem:</span> <b id="resumoCustoEmbalagem">R$ 0,00</b></div>
                    <div><span style="color:var(--text-3);">🚚 Outros:</span> <b id="resumoCustoOutros">R$ 0,00</b></div>
                    <div><span style="color:var(--text-3);">💼 Custo Total:</span> <b id="resumoCustoTotal" style="color:var(--alerta);">R$ 0,00</b></div>
                    <div><span style="color:var(--text-3);">📈 Margem:</span> <b id="resumoValorMargem" style="color:var(--sucesso);">+ R$ 0,00</b></div>
                </div>

                <div class="linha" style="margin-top: 10px; align-items: flex-end;">
                    <div style="flex: 2;">
                        <label for="prodPreco" style="font-weight: 700; color: var(--primary);">Preço Final Sugerido / Venda (R$)</label>
                        <input type="number" id="prodPreco" min="0" step="0.01" placeholder="0,00" inputmode="decimal" style="font-size: 15px; font-weight: 700; color: var(--primary); background: var(--surface-1);">
                    </div>
                    <div style="flex: 1;">
                        <button type="button" id="btnRecalcularPreco" class="secundario pequeno" style="width: 100%; height: 38px;">Recalcular Preço</button>
                    </div>
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

<!-- Modal de Ficha Técnica (BOM) & Diagnóstico de Montagem -->
<div id="modalComposicao" class="modal" hidden>
    <div class="card modal-caixa modal-largo" role="dialog" aria-modal="true" aria-labelledby="bomTituloModal">
        <div class="modal-cabecalho">
            <div>
                <h2 id="bomTituloModal">Ficha Técnica & Montagem</h2>
                <p class="modal-sub" id="bomSubModal">Receita de componentes e cálculo de capacidade imediata.</p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <!-- KPI e Diagnóstico Rápido -->
        <div class="bloco-kpi-bom" style="margin-top: 10px; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px;">
            <div class="card-kpi-bom" id="kpiCapacidadeBox">
                <span class="kpi-rotulo">Capacidade Imediata</span>
                <div class="kpi-valor" id="kpiCapacidadeQtd">0</div>
                <span class="kpi-sub" id="kpiCapacidadeGargalo">Estoque de peças</span>
            </div>
            <div class="card-kpi-bom">
                <span class="kpi-rotulo">Estoque Pronto</span>
                <div class="kpi-valor" id="kpiEstoquePronto">0</div>
                <span class="kpi-sub">Unidades finalizadas</span>
            </div>
            <div class="card-kpi-bom">
                <span class="kpi-rotulo">Filamento / un</span>
                <div class="kpi-valor" id="kpiPeso1un">0 g</div>
                <span class="kpi-sub" id="kpiCores1un">Soma das peças</span>
            </div>
            <div class="card-kpi-bom">
                <span class="kpi-rotulo">Tempo / un</span>
                <div class="kpi-valor" id="kpiTempo1un">00:00:00</div>
                <span class="kpi-sub">Produção de 1 produto</span>
            </div>
        </div>

        <!-- Seção de Ação de Montagem -->
        <div style="background: var(--surface-3); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 16px; margin-bottom: 14px; margin-top: 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <label for="qtdMontagemExecutar" style="margin: 0; font-weight: 600; white-space: nowrap;">Montar agora:</label>
                <input type="number" id="qtdMontagemExecutar" min="1" value="1" style="width: 80px; margin: 0; text-align: center;">
                <span style="color: var(--text-3); font-size: 13px;">unidade(s)</span>
            </div>
            <button type="button" id="btnExecutarMontagem" class="primario" style="margin: 0;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width: 16px; height: 16px;"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
                Realizar Montagem (Baixar Peças)
            </button>
        </div>

        <!-- Simulação de Meta de Produção -->
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
            <strong style="font-size: 14px;">Diagnóstico de Peças & Gargalos</strong>
            <div style="display: flex; align-items: center; gap: 8px;">
                <label for="metaSimulacao" style="margin: 0; font-size: 13px; color: var(--text-2);">Simular Meta:</label>
                <input type="number" id="metaSimulacao" min="1" value="1" style="width: 70px; margin: 0; padding: 4px 8px; height: 32px; text-align: center;">
                <button type="button" id="btnRecalcularMeta" class="secundario" style="min-height: 32px; padding: 0 10px; font-size: 13px;">Recalcular</button>
            </div>
        </div>

        <!-- Painel de Previsão Futura da Meta (Tempo HH:mm:ss e Filamento por Cor) -->
        <div id="painelPrevisaoMeta" style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 14px; margin-bottom: 12px; font-size: 13px; display: flex; flex-direction: column; gap: 6px;"></div>

        <!-- Tabela de Diagnóstico / Peças -->
        <div class="tabela-rolagem" style="margin-bottom: 18px; max-height: 240px; overflow-y: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 44px; text-align: center;">Foto</th>
                        <th>Peça / Componente</th>
                        <th>Cores (estoque)</th>
                        <th class="num">Qtd / un</th>
                        <th class="num">Filamento (1 un)</th>
                        <th class="num">Tempo (1 un)</th>
                        <th class="num">Estoque total</th>
                        <th class="num">Para a Meta</th>
                        <th>Situação / Balanço</th>
                    </tr>
                </thead>
                <tbody id="tabelaDiagnosticoBOM"></tbody>
            </table>
        </div>

        <!-- Editor da Ficha Técnica: cada peça pode ter uma ou mais cores, -->
        <!-- cada cor com seu próprio saldo (ex.: Chave de Fenda em Cinza e Laranja). -->
        <details style="border-top: 1px solid var(--border); padding-top: 14px; margin-top: 8px;" open>
            <summary style="font-weight: 600; cursor: pointer; color: var(--primary); padding: 4px 0;">
                ⚙️ Configurar Peças do Produto (Nome, Quantidade, Cores, Gramas e Tempo)
            </summary>
            <div style="margin-top: 12px;">
                <p style="font-size: 13px; color: var(--text-3); margin-bottom: 8px;">
                    Cadastre cada peça necessária para produzir 1 produto. Insira a <b>quantidade</b>, o <b>tempo de produção</b> (HH:mm:ss) e o <b>peso em gramas</b> para 1 produto pronto. Se a peça tiver cores diferentes, adicione as cores e ajuste as gramas por cor se variar.
                </p>

                <!-- Barra com o Totalizador em Tempo Real da Produção de 1 Produto -->
                <div id="editorBOMResumoInstantaneo" style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;background:var(--surface-3);border:1px solid var(--border);padding:8px 14px;border-radius:var(--radius-sm);margin-bottom:10px;font-size:13px;">
                    <div>⏱️ <b>Tempo Total (1 produto):</b> <span id="editorTempoTotal" style="font-weight:600;color:var(--primary);">00:00:00</span></div>
                    <div>⚖️ <b>Filamento Total (1 produto):</b> <span id="editorPesoTotal" style="font-weight:600;color:var(--primary);">0 g</span></div>
                    <div id="editorCoresBadges" style="display:flex;gap:4px;flex-wrap:wrap;"></div>
                </div>

                <div class="lista-editor-bom" id="listaEditorBOM"></div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                    <button type="button" id="btnAdicionarLinhaBOM" class="secundario" style="font-size: 13px;">
                        + Adicionar Peça
                    </button>
                    <button type="button" id="btnSalvarBOM" class="primario" style="font-size: 13px;">
                        Salvar Peças
                    </button>
                </div>
            </div>
        </details>


        <div class="modal-rodape" style="margin-top: 18px;">
            <button type="button" class="secundario" data-fechar-modal>Fechar</button>
        </div>
    </div>
</div>

<input type="file" id="inputFotoPecaProdutos" accept="image/png,image/jpeg,image/webp" style="display:none;">



<script src="assets/js/produtos.js?v=<?= filemtime(__DIR__ . '/assets/js/produtos.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
