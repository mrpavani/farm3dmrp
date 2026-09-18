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
        <div class="bloco-kpi-bom" style="margin-top: 10px;">
            <div class="card-kpi-bom" id="kpiCapacidadeBox">
                <span class="kpi-rotulo">Capacidade de Montagem Imediata</span>
                <div class="kpi-valor" id="kpiCapacidadeQtd">0</div>
                <span class="kpi-sub" id="kpiCapacidadeGargalo">Calculando com base no estoque de peças...</span>
            </div>
            <div class="card-kpi-bom">
                <span class="kpi-rotulo">Estoque Pronto Montado</span>
                <div class="kpi-valor" id="kpiEstoquePronto">0</div>
                <span class="kpi-sub">Unidades já finalizadas no estoque</span>
            </div>
        </div>

        <!-- Seção de Ação de Montagem -->
        <div style="background: var(--surface-3); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 16px; margin-bottom: 16px; display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;">
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

        <!-- Tabela de Diagnóstico / Peças -->
        <div class="tabela-rolagem" style="margin-bottom: 18px; max-height: 220px; overflow-y: auto;">
            <table>
                <thead>
                    <tr>
                        <th style="width: 44px; text-align: center;">Foto</th>
                        <th>Peça / Componente</th>
                        <th>Cor</th>
                        <th class="num">Qtd / un</th>
                        <th class="num">Estoque</th>
                        <th class="num">Para a Meta</th>
                        <th>Situação / Balanço</th>
                    </tr>
                </thead>
                <tbody id="tabelaDiagnosticoBOM"></tbody>
            </table>
        </div>

        <!-- Editor da Ficha Técnica (Cadastro de Peças: Nome, Cor, Quantidade e Estoque) -->
        <details style="border-top: 1px solid var(--border); padding-top: 14px; margin-top: 8px;" open>
            <summary style="font-weight: 600; cursor: pointer; color: var(--primary); padding: 4px 0;">
                ⚙️ Configurar Peças do Produto (Nome, Cor, Quantidade e Saldo de Peças)
            </summary>
            <div style="margin-top: 12px;">
                <p style="font-size: 13px; color: var(--text-3); margin-bottom: 10px;">Cadastre cada peça necessária para montar 1 unidade do produto final (ex: Hélice, Rotator, Pés) com a cor e saldo atual.</p>
                <div class="tabela-rolagem" style="max-height: 240px; overflow-y: auto;">
                    <table class="tabela-bom-editor">
                        <thead>
                            <tr>
                                <th style="width: 44px; text-align: center;">Foto</th>
                                <th>Nome da Peça</th>
                                <th style="width: 130px;">Cor</th>
                                <th style="width: 90px;">Qtd / un</th>
                                <th style="width: 110px;">Estoque Peças</th>
                                <th style="width: 44px; text-align: center;">Ação</th>
                            </tr>
                        </thead>
                        <tbody id="tabelaEditorBOM"></tbody>
                    </table>
                </div>

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



<script src="assets/js/produtos.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
