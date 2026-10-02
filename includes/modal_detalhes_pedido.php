<?php
// Modal de Detalhes do Pedido / Ordem de Produção
// Incluído em pedidos.php. Controlado por assets/js/pedidos.js (verDetalhes(id)).
?>
<div id="modalDetalhesPedido" class="modal" hidden>
    <div class="card modal-caixa modal-largo modal-detalhes-estilo" role="dialog" aria-modal="true" aria-labelledby="detTitulo">
        <div class="modal-cabecalho">
            <div>
                <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                    <h2 id="detTitulo" style="margin:0;font-size:20px;">Detalhes do Pedido</h2>
                    <span id="detTipoTag" class="tag-origem"></span>
                    <span id="detStatusBadge" class="badge badge-moderno"></span>
                </div>
                <p class="modal-sub" id="detSub" style="margin-top:4px;"></p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="modal-corpo" style="display:flex;flex-direction:column;gap:16px;">
            <!-- Grid Topo: Cliente / Prazos / Progresso -->
            <div class="detalhes-cards-grid">
                <!-- Card 1: Cliente / Origem -->
                <div class="card-det-bloco" id="detBlocoCliente">
                    <div class="det-bloco-titulo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span id="detRotuloClienteOrigem">Cliente</span>
                    </div>
                    <div class="det-bloco-conteudo" id="detConteudoCliente"></div>
                </div>

                <!-- Card 2: Datas & Registro -->
                <div class="card-det-bloco">
                    <div class="det-bloco-titulo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        <span>Cronograma</span>
                    </div>
                    <div class="det-bloco-conteudo" id="detConteudoDatas"></div>
                </div>

                <!-- Card 3: Progresso & Produção -->
                <div class="card-det-bloco">
                    <div class="det-bloco-titulo">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        <span>Progresso & Produção</span>
                    </div>
                    <div class="det-bloco-conteudo" id="detConteudoProgresso"></div>
                </div>
            </div>

            <!-- Observações (se houver) -->
            <div class="card-det-bloco" id="detBlocoObs" hidden style="background:var(--surface-2);border-left:4px solid var(--primary);">
                <div class="det-bloco-titulo" style="color:var(--text-1);">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    <span>Observações do Pedido</span>
                </div>
                <div id="detObsTexto" style="font-size:13.5px;color:var(--text);white-space:pre-wrap;margin-top:6px;"></div>
            </div>

            <!-- Tabela de Itens Solicitados com Peças e Cores -->
            <div class="secao-form" style="margin-top:4px;padding-top:12px;">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                    <h3 style="margin:0;font-size:14px;color:var(--text);font-weight:700;">
                        📦 Itens Solicitados & Cores das Peças
                    </h3>
                    <span id="detTotalItensBadge" style="font-size:12px;color:var(--text-3);"></span>
                </div>

                <div class="tabela-rolagem" style="border:1px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;">
                    <table class="tabela-detalhes-itens" style="width:100%;margin:0;">
                        <thead>
                            <tr style="background:var(--surface-2);font-size:12px;">
                                <th style="text-align:left;padding:10px 14px;">Produto & Cores / Peças</th>
                                <th style="text-align:center;width:100px;padding:10px;">Qtd</th>
                                <th style="text-align:center;width:110px;padding:10px;">Produzido</th>
                                <th style="text-align:right;width:110px;padding:10px;">Preço Unit.</th>
                                <th style="text-align:right;width:120px;padding:10px 14px;">Subtotal</th>
                            </tr>
                        </thead>
                        <tbody id="detTabelaItens"></tbody>
                    </table>
                </div>
            </div>

            <!-- Resumo Financeiro & Distribuição de Filamento por Cor -->
            <div class="detalhes-resumo-rodape">
                <div id="detCoresDistribuicaoBloco" style="display:flex;flex-direction:column;gap:6px;">
                    <span style="font-size:12px;font-weight:600;color:var(--text-2);">🎨 Necessidade de Filamento por Cor:</span>
                    <div id="detCoresChips" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
                </div>
                <div class="det-totais-box">
                    <div class="det-total-linha">
                        <span>Total de Peças / Unidades:</span>
                        <strong id="detTotalUnidades">0 un</strong>
                    </div>
                    <div class="det-total-linha" id="detLinhaTempoFuturo">
                        <span>Tempo restante:</span>
                        <strong id="detTempoFuturo">00:00:00</strong>
                    </div>
                    <div class="det-total-linha" id="detLinhaPesoFuturo">
                        <span>Filamento restante:</span>
                        <strong id="detPesoFuturo">0 g</strong>
                    </div>
                    <div class="det-total-linha total-destaque">
                        <span>Valor Total:</span>
                        <strong id="detValorTotal">R$ 0,00</strong>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal-rodape" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
            <div style="display:flex;gap:8px;">
                <button type="button" class="secundario" data-fechar-modal>Fechar</button>
                <button type="button" class="secundario" id="detBtnImprimir" title="Imprimir detalhes do pedido">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:5px;"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14"/></svg>
                    Imprimir
                </button>
            </div>
            <div style="display:flex;gap:8px;" id="detAcoesAvançadas">
                <button type="button" class="perigo" id="detBtnExcluir" hidden>Excluir pedido</button>
                <button type="button" class="primario" id="detBtnEditar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:16px;height:16px;margin-right:5px;"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                    Editar pedido
                </button>
            </div>
        </div>
    </div>
</div>
