<?php
require_once __DIR__ . '/includes/auth.php';
$usuario = exigirLoginPagina();
$paginaAtual = 'filamentos.php';
$tituloPagina = 'Estoque de Filamentos';
$subtituloPagina = 'Controle de carretéis, lotes PEPS (FIFO), custo médio e baixa automática de estoque.';
require __DIR__ . '/includes/header.php';
?>

<main>
    <div id="msg" aria-live="polite"></div>

    <!-- Cards de KPI Consolidado -->
    <div class="resumo-grid" id="kpisFilamentos" style="margin-bottom: 18px;">
        <div class="card card-kpi">
            <span class="kpi-rotulo">Estoque Total</span>
            <div class="kpi-valor" id="kpiTotalKg">0,00 kg</div>
            <span class="kpi-sub" id="kpiTotalGramas">0 g em estoque</span>
        </div>
        <div class="card card-kpi">
            <span class="kpi-rotulo">Rolos Equivalentes</span>
            <div class="kpi-valor" id="kpiTotalRolos">0 un</div>
            <span class="kpi-sub">Base 1 kg / rolo</span>
        </div>
        <div class="card card-kpi">
            <span class="kpi-rotulo">Patrimônio em Filamento</span>
            <div class="kpi-valor" id="kpiTotalValor">R$ 0,00</div>
            <span class="kpi-sub">Valor de compra em estoque</span>
        </div>
        <div class="card card-kpi">
            <span class="kpi-rotulo">Custo Médio Ponderado</span>
            <div class="kpi-valor" id="kpiCustoMedioKg">R$ 0,00 / kg</div>
            <span class="kpi-sub" id="kpiCustoMedioG">R$ 0,000 / g</span>
        </div>
        <div class="card card-kpi" id="cardKpiAlertas">
            <span class="kpi-rotulo">Alertas de Reposição</span>
            <div class="kpi-valor" id="kpiAlertasQtd" style="color: var(--alerta);">0</div>
            <span class="kpi-sub">Cores baixas ou zeradas</span>
        </div>
    </div>

    <!-- Barra de Ações & Filtros -->
    <div class="barra-lista" style="gap: 10px; flex-wrap: wrap;">
        <label class="busca" style="flex: 1; min-width: 240px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" id="buscaFilamento" placeholder="Buscar por cor, marca ou tipo…" aria-label="Buscar filamentos">
        </label>

        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <select id="filtroTipo" style="height: 38px; min-width: 110px;">
                <option value="">Todos os tipos</option>
                <option value="PLA">PLA</option>
                <option value="PETG">PETG</option>
                <option value="ABS">ABS</option>
                <option value="TPU">TPU</option>
                <option value="ASA">ASA</option>
            </select>

            <select id="filtroStatus" style="height: 38px; min-width: 120px;">
                <option value="">Todas situações</option>
                <option value="ok">Em estoque</option>
                <option value="baixo">Estoque baixo</option>
                <option value="zerado">Zerado</option>
            </select>

            <button type="button" class="primario" id="btnEntradaLote" style="display: inline-flex; align-items: center; gap: 6px;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" style="width:16px;height:16px;"><path d="M12 5v14M5 12h14"/></svg>
                + Entrada de Rolos
            </button>

            <button type="button" class="secundario" id="btnNovoFilamento" style="display: inline-flex; align-items: center; gap: 6px;">
                + Novo Filamento
            </button>
        </div>
    </div>

    <!-- Tabela de Filamentos -->
    <section class="card card-lista">
        <div class="tabela-rolagem">
            <table>
                <thead>
                    <tr>
                        <th style="width: 44px; text-align: center;">Cor</th>
                        <th>Filamento / Especificação</th>
                        <th>Tipo / Marca</th>
                        <th class="num">Saldo (Gramas)</th>
                        <th class="num">Rolos (1kg)</th>
                        <th class="num" title="Média ponderada do valor de compra dos lotes ativos em estoque">Custo Médio</th>
                        <th class="num" title="Custo do lote mais antigo ativo usado na precificação de produtos (PEPS/FIFO)">Custo PEPS Ativo</th>
                        <th class="num">Valor Total</th>
                        <th>Situação</th>
                        <th class="num">Ações</th>
                    </tr>
                </thead>
                <tbody id="tabelaFilamentos"></tbody>
            </table>
        </div>
        <div class="rodape-lista" id="rodapeFilamentos"></div>
    </section>
</main>

<!-- Modal: Novo / Editar Filamento -->
<div id="modalFilamento" class="modal" hidden>
    <div class="card modal-caixa modal-medio" role="dialog" aria-modal="true" aria-labelledby="filTituloModal">
        <form id="formFilamento" autocomplete="off" novalidate>
            <div class="modal-cabecalho">
                <div>
                    <h2 id="filTituloModal">Novo filamento</h2>
                    <p class="modal-sub">Cadastre o tipo e a cor para controlar compras e consumo automático.</p>
                </div>
                <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="modal-corpo">
                <div class="linha">
                    <div style="flex: 1.5;">
                        <label for="filCor">Cor do Filamento <span class="obrigatorio">*</span></label>
                        <input type="text" id="filCor" required placeholder="Ex.: Branco, Vermelho, Azul, Preto">
                    </div>
                    <div style="flex: 0.8;">
                        <label for="filCorHex">Cor Visual</label>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <input type="color" id="filCorHex" value="#6366f1" style="width: 44px; height: 38px; padding: 2px; cursor: pointer; border-radius: var(--radius-sm);">
                            <input type="text" id="filCorHexTexto" value="#6366f1" style="font-family: monospace; text-transform: uppercase;" maxlength="7">
                        </div>
                    </div>
                </div>

                <div class="linha">
                    <div>
                        <label for="filTipo">Tipo de Material <span class="obrigatorio">*</span></label>
                        <input type="text" id="filTipo" required placeholder="Ex.: PLA, PETG, ABS, TPU" list="sugestoesTipos" value="PLA">
                        <datalist id="sugestoesTipos">
                            <option value="PLA">
                            <option value="PETG">
                            <option value="ABS">
                            <option value="TPU">
                            <option value="ASA">
                        </datalist>
                    </div>
                    <div>
                        <label for="filMarca">Marca / Fabricante <span class="obrigatorio">*</span></label>
                        <input type="text" id="filMarca" required placeholder="Ex.: Voolt3D, Creality, Sunlu, 3DFila" list="sugestoesMarcas">
                        <datalist id="sugestoesMarcas">
                            <option value="Voolt3D">
                            <option value="Creality">
                            <option value="Sunlu">
                            <option value="eSUN">
                            <option value="3D Fila">
                            <option value="Printalot">
                        </datalist>
                    </div>
                </div>

                <div class="linha">
                    <div>
                        <label for="filEstoqueMinimo">Estoque Mínimo de Alerta (g)</label>
                        <input type="number" id="filEstoqueMinimo" min="0" step="50" value="500" placeholder="500g">
                    </div>
                    <div>
                        <label for="filAtivo">Situação</label>
                        <select id="filAtivo">
                            <option value="1">Ativo</option>
                            <option value="0">Inativo</option>
                        </select>
                    </div>
                </div>

                <!-- Entrada de lote inicial (só aparece em novo filamento) -->
                <div id="secaoLoteInicial" style="background: var(--surface-3); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px; margin-top: 10px;">
                    <div style="font-weight: 600; font-size: 13px; margin-bottom: 6px;">📦 Já tem rolos em estoque? (Opcional)</div>
                    <div class="linha">
                        <div>
                            <label for="filQtdRolosInicial">Qtd Rolos (1kg)</label>
                            <input type="number" id="filQtdRolosInicial" min="0" step="1" placeholder="0">
                        </div>
                        <div>
                            <label for="filPrecoRoloInicial">Preço por Rolo (R$)</label>
                            <input type="number" id="filPrecoRoloInicial" min="0" step="0.01" placeholder="Ex.: 89.90">
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-rodape">
                <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
                <button type="submit" id="btnSalvarFilamento">Salvar Filamento</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Entrada de Rolos / Lote de Compra -->
<div id="modalEntradaLote" class="modal" hidden>
    <div class="card modal-caixa modal-medio" role="dialog" aria-modal="true" aria-labelledby="loteTituloModal">
        <form id="formEntradaLote" autocomplete="off" novalidate>
            <div class="modal-cabecalho">
                <div>
                    <h2 id="loteTituloModal">Entrada de Rolos / Lote</h2>
                    <p class="modal-sub">Adiciona novos carretéis ao estoque. O sistema aplica custo PEPS e média ponderada.</p>
                </div>
                <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="modal-corpo">
                <label for="loteFilamentoId">Selecione o Filamento <span class="obrigatorio">*</span></label>
                <select id="loteFilamentoId" required></select>

                <div class="linha" style="margin-top: 8px;">
                    <div>
                        <label for="loteQtdRolos">Quantidade de Rolos <span class="obrigatorio">*</span></label>
                        <input type="number" id="loteQtdRolos" min="1" step="1" value="1" required>
                    </div>
                    <div>
                        <label for="lotePesoRolo">Peso de cada rolo (g)</label>
                        <input type="number" id="lotePesoRolo" min="100" step="50" value="1000" placeholder="1000g (1kg)">
                    </div>
                </div>

                <div class="linha">
                    <div>
                        <label for="lotePrecoRolo">Preço por Rolo (R$) <span class="obrigatorio">*</span></label>
                        <input type="number" id="lotePrecoRolo" min="0" step="0.01" placeholder="Ex.: 79.90" required>
                    </div>
                    <div>
                        <label for="lotePrecoTotal">Preço Total da Compra (R$)</label>
                        <input type="number" id="lotePrecoTotal" min="0" step="0.01" placeholder="0,00" style="background: var(--surface-3);">
                    </div>
                </div>

                <!-- Resumo da Entrada -->
                <div id="resumoEntradaLote" style="background: var(--surface-2); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 8px 12px; font-size: 12.5px; margin-bottom: 10px;">
                    <div>⚖️ <b>Total em Gramas:</b> <span id="loteResumoGramas">1.000 g</span></div>
                    <div>🏷️ <b>Custo por Grama:</b> <span id="loteResumoPrecoGrama">R$ 0,0000 / g</span></div>
                </div>

                <div class="linha">
                    <div>
                        <label for="loteDataCompra">Data da Compra</label>
                        <input type="date" id="loteDataCompra" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div>
                        <label for="loteObs">Observações / Fornecedor</label>
                        <input type="text" id="loteObs" placeholder="Ex.: Shopee, Mercado Livre, Máquina X...">
                    </div>
                </div>
            </div>

            <div class="modal-rodape">
                <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
                <button type="submit" id="btnSalvarLote">Confirmar Entrada</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Ajuste Manual de Estoque (Pesagem na Balança) -->
<div id="modalAjusteFilamento" class="modal" hidden>
    <div class="card modal-caixa modal-pequeno" role="dialog" aria-modal="true" aria-labelledby="ajusteTituloModal">
        <form id="formAjusteFilamento" autocomplete="off" novalidate>
            <input type="hidden" id="ajusteFilamentoId">
            <div class="modal-cabecalho">
                <div>
                    <h2 id="ajusteTituloModal">Ajuste de Estoque / Balança</h2>
                    <p class="modal-sub" id="ajusteSubModal">Conferência física do peso dos carretéis.</p>
                </div>
                <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="modal-corpo">
                <div style="background: var(--surface-3); border: 1px solid var(--border); border-radius: var(--radius); padding: 10px 14px; margin-bottom: 12px; font-size: 13px;">
                    <div>Saldo registrado no sistema: <b id="ajusteSaldoAtualTexto">0 g</b></div>
                </div>

                <div class="linha">
                    <div>
                        <label for="ajusteNovoSaldo">Novo saldo real pesado (g) <span class="obrigatorio">*</span></label>
                        <input type="number" id="ajusteNovoSaldo" min="0" step="1" required placeholder="Ex.: 1450">
                    </div>
                    <div>
                        <label for="ajusteDiferenca">Diferença / Variação</label>
                        <input type="text" id="ajusteDiferenca" readonly style="background: var(--surface-3); font-weight: 600;">
                    </div>
                </div>

                <label for="ajusteMotivo">Motivo do Ajuste</label>
                <input type="text" id="ajusteMotivo" placeholder="Ex.: Pesagem na balança de precisão, perda de material..." value="Conferência física na balança">
            </div>

            <div class="modal-rodape">
                <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
                <button type="submit" id="btnSalvarAjuste">Gravar Ajuste</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Detalhes, Lotes PEPS & Histórico -->
<div id="modalDetalhesFilamento" class="modal" hidden>
    <div class="card modal-caixa modal-largo" role="dialog" aria-modal="true" aria-labelledby="detTituloModal">
        <div class="modal-cabecalho">
            <div>
                <h2 id="detTituloModal">Lotes & Histórico de Filamento</h2>
                <p class="modal-sub" id="detSubModal">Detalhamento dos lotes PEPS (FIFO) e movimentações.</p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="modal-corpo">
            <!-- Indicadores do Filamento -->
            <div class="bloco-kpi-bom" style="margin-bottom: 14px;">
                <div class="card-kpi-bom">
                    <span class="kpi-rotulo">Saldo Atual</span>
                    <div class="kpi-valor" id="detSaldoGramas">0 g</div>
                    <span class="kpi-sub" id="detSaldoRolos">0 rolos</span>
                </div>
                <div class="card-kpi-bom">
                    <span class="kpi-rotulo">Custo Médio Ponderado</span>
                    <div class="kpi-valor" id="detCustoMedio">R$ 0,00 / kg</div>
                    <span class="kpi-sub">Média dos lotes</span>
                </div>
                <div class="card-kpi-bom">
                    <span class="kpi-rotulo">Custo PEPS Ativo</span>
                    <div class="kpi-valor" id="detCustoPeps" style="color: var(--primary);">R$ 0,00 / kg</div>
                    <span class="kpi-sub">Lote sendo consumido agora</span>
                </div>
                <div class="card-kpi-bom">
                    <span class="kpi-rotulo">Valor em Estoque</span>
                    <div class="kpi-valor" id="detValorTotal">R$ 0,00</div>
                    <span class="kpi-sub">Patrimônio restante</span>
                </div>
            </div>

            <h3 style="font-size: 14px; margin: 14px 0 6px 0;">📦 Lotes de Compra (Ordem Cronológica PEPS)</h3>
            <div class="tabela-rolagem" style="max-height: 180px; overflow-y: auto; margin-bottom: 16px;">
                <table>
                    <thead>
                        <tr>
                            <th>Lote #</th>
                            <th>Data</th>
                            <th class="num">Rolos</th>
                            <th class="num">Preço / Rolo</th>
                            <th class="num">Preço / g</th>
                            <th class="num">Total Comprado</th>
                            <th class="num">Saldo Restante</th>
                            <th>Situação</th>
                            <th>Observações</th>
                        </tr>
                    </thead>
                    <tbody id="tabelaLotesDetalhes"></tbody>
                </table>
            </div>

            <h3 style="font-size: 14px; margin: 14px 0 6px 0;">📜 Extrato Recente de Movimentações</h3>
            <div class="tabela-rolagem" style="max-height: 200px; overflow-y: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>Tipo</th>
                            <th class="num">Variação (g)</th>
                            <th class="num">Saldo Após</th>
                            <th class="num">Custo Total</th>
                            <th>Referência / Detalhes</th>
                            <th>Usuário</th>
                        </tr>
                    </thead>
                    <tbody id="tabelaMovimentacoesDetalhes"></tbody>
                </table>
            </div>
        </div>

        <div class="modal-rodape">
            <button type="button" class="secundario" data-fechar-modal>Fechar</button>
        </div>
    </div>
</div>
</div>

<script src="assets/js/filamentos.js?v=<?= @filemtime(__DIR__ . '/assets/js/filamentos.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
