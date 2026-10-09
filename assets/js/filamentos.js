// Módulo de Filamentos: Controle de Estoque, Lotes PEPS e Custo Médio
let filamentos = [];
let coresFilamentos = [];
let filamentoEditandoId = null;
let filamentoSelecionado = null;

const $ = id => document.getElementById(id);
const esc = App.esc;

async function carregar() {
    try {
        const res = await App.api('api/filamentos.php?resumo=1');
        filamentos = res.filamentos || [];
        coresFilamentos = res.cores || [];
        renderizarKpis(res.resumo || {});
        renderizarCores(coresFilamentos);
        renderizar();
    } catch (e) {
        App.toast(e.message, 'erro');
        filamentos = [];
        coresFilamentos = [];
        renderizar();
    }
}

function renderizarKpis(kpi) {
    if ($('kpiTotalKg')) $('kpiTotalKg').textContent = `${(kpi.total_kg || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} kg`;
    if ($('kpiTotalGramas')) $('kpiTotalGramas').textContent = `${(kpi.total_gramas || 0).toLocaleString('pt-BR')} g em estoque`;
    if ($('kpiTotalRolos')) $('kpiTotalRolos').textContent = `${(kpi.total_rolos || 0).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} rolos`;
    if ($('kpiTotalValor')) $('kpiTotalValor').textContent = App.moeda(kpi.valor_total_estoque || 0);
    if ($('kpiCustoMedioKg')) $('kpiCustoMedioKg').textContent = `${App.moeda(kpi.custo_medio_geral_kg || 0)} / kg`;
    if ($('kpiCustoMedioG')) $('kpiCustoMedioG').textContent = `R$ ${((kpi.custo_medio_geral_kg || 0) / 1000).toFixed(4)} / g`;
    if ($('kpiAlertasQtd')) {
        const qtd = kpi.qtd_alertas || 0;
        $('kpiAlertasQtd').textContent = qtd;
        if (qtd > 0) {
            $('kpiAlertasQtd').style.color = 'var(--perigo)';
        } else {
            $('kpiAlertasQtd').style.color = 'var(--sucesso)';
        }
    }
}

function renderizarCores(cores) {
    const elGrade = $('gradeCoresEstoque');
    const elTexto = $('resumoCoresTexto');
    if (!elGrade) return;

    if (!cores || !cores.length) {
        elGrade.innerHTML = '<span style="font-size:12px; color:var(--text-3);">Nenhuma cor ativa cadastrada.</span>';
        if (elTexto) elTexto.textContent = '';
        return;
    }

    const totalCores = cores.length;
    const emAlerta = cores.filter(c => c.status !== 'ok').length;
    if (elTexto) {
        elTexto.textContent = emAlerta > 0
            ? `${emAlerta} de ${totalCores} cores com alerta de reposição`
            : `Todas as ${totalCores} cores com estoque em dia`;
    }

    const termoAtual = App.normalizar($('buscaFilamento')?.value || '').trim();

    elGrade.innerHTML = cores.map(c => {
        const hex = c.cor_hex || '#6366f1';
        const gramas = Number(c.estoque_gramas) || 0;
        const normCor = App.normalizar(c.cor).trim();
        const estaAtiva = termoAtual !== '' && (termoAtual === normCor || termoAtual.includes(normCor));

        let badgeCor = '';
        if (c.status === 'zerado') {
            badgeCor = '<span class="pill-cor-badge zerado">ZERADO</span>';
        } else if (c.status === 'baixo') {
            badgeCor = '<span class="pill-cor-badge baixo">BAIXO</span>';
        } else {
            badgeCor = '<span class="pill-cor-badge ok">OK</span>';
        }

        return `
        <button type="button" class="pill-cor-estoque ${estaAtiva ? 'ativa' : ''}" onclick="filtrarPorCor('${esc(c.cor)}')" title="Filtrar filamentos da cor ${esc(c.cor)} (${esc(c.tipos || 'Todos')})">
            <span class="pill-cor-swatch" style="background-color:${esc(hex)};"></span>
            <span class="pill-cor-nome">${esc(c.cor)}</span>
            <span class="pill-cor-peso">${App.fmtInt.format(gramas)}g</span>
            ${badgeCor}
        </button>
        `;
    }).join('');
}

function filtrarPorCor(nomeCor) {
    const input = $('buscaFilamento');
    if (!input) return;
    const normInput = App.normalizar(input.value || '').trim();
    const normCor = App.normalizar(nomeCor || '').trim();
    if (normInput === normCor) {
        input.value = '';
    } else {
        input.value = nomeCor;
    }
    renderizarCores(coresFilamentos);
    renderizar();
}

function badgeStatus(f) {
    const status = f.status;
    const estG = Number(f.estoque_gramas) || 0;
    const saldoCor = Number(f.saldo_cor_total || estG);
    const minCor = Number(f.estoque_minimo_cor || f.estoque_minimo_gramas || 500);

    if (status === 'zerado') {
        return `<span class="tag-status atrasado" title="Sem estoque da cor ${esc(f.cor)} em nenhum tipo">Zerado (Cor)</span>`;
    }
    if (status === 'baixo') {
        return `<span class="tag-status parcial" title="Estoque total da cor ${esc(f.cor)} está baixo: ${App.fmtInt.format(saldoCor)}g (mínimo: ${App.fmtInt.format(minCor)}g)">Estoque Baixo (${App.fmtInt.format(saldoCor)}g)</span>`;
    }
    if (estG <= 0) {
        return `<span class="tag-status pronto" style="background:rgba(16,185,129,0.12); color:#059669; border:1px solid rgba(16,185,129,0.25);" title="Este carretel específico está zerado, mas a cor ${esc(f.cor)} possui saldo total de ${App.fmtInt.format(saldoCor)}g em outros carretéis">Em Estoque (${App.fmtInt.format(saldoCor)}g na cor)</span>`;
    }
    return `<span class="tag-status pronto" title="Cor com estoque suficiente (Total da cor: ${App.fmtInt.format(saldoCor)}g)">Em Estoque</span>`;
}

function renderizar() {
    const termo = App.normalizar($('buscaFilamento')?.value.trim() || '');
    const filtroTipo = $('filtroTipo')?.value || '';
    const filtroStatus = $('filtroStatus')?.value || '';

    const lista = filamentos.filter(f => {
        if (termo) {
            const texto = App.normalizar(`${f.nome} ${f.cor} ${f.marca} ${f.tipo}`);
            if (!texto.includes(termo)) return false;
        }
        if (filtroTipo && f.tipo !== filtroTipo) return false;
        if (filtroStatus && f.status !== filtroStatus) return false;
        return true;
    });

    if ($('rodapeFilamentos')) {
        $('rodapeFilamentos').textContent = `${lista.length} de ${filamentos.length} filamentos cadastrados · controle de consumo PEPS por lote`;
    }

    const tbody = $('tabelaFilamentos');
    if (!tbody) return;

    if (!lista.length) {
        tbody.innerHTML = App.estadoVazio('🧵',
            filamentos.length ? 'Nenhum filamento encontrado com esses filtros' : 'Nenhum filamento cadastrado no estoque',
            filamentos.length ? 'Ajuste os filtros ou o termo de busca.' : 'Clique em "+ Novo Filamento" ou "+ Entrada de Rolos" para começar.', 10);
        return;
    }

    tbody.innerHTML = lista.map(f => {
        const hex = f.cor_hex || '#6366f1';
        const gramas = Number(f.estoque_gramas) || 0;
        const rolos = Number(f.estoque_rolos) || 0;
        const cMedioKg = Number(f.custo_medio_kg) || 0;
        const cPepsKg = Number(f.custo_peps_kg) || 0;
        const valTotal = Number(f.valor_total_estoque) || 0;

        return `
        <tr>
            <td style="text-align: center;">
                <span style="display:inline-block; width:22px; height:22px; border-radius:50%; background-color:${esc(hex)}; border: 2px solid var(--border); box-shadow: 0 1px 3px rgba(0,0,0,0.15);" title="${esc(f.cor)} (${esc(hex)})"></span>
            </td>
            <td>
                <strong>${esc(f.cor)}</strong>
                <span class="sub-linha">${esc(f.nome)}</span>
            </td>
            <td>
                <span class="badge-tipo componente" style="font-size:11px;">${esc(f.tipo)}</span>
                <span class="sub-linha">${esc(f.marca)}</span>
            </td>
            <td class="num" style="font-weight: 700; font-size: 14px;">
                ${gramas.toLocaleString('pt-BR')} g
            </td>
            <td class="num" style="color: var(--text-2);">
                ${rolos.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} un
            </td>
            <td class="num" style="font-weight: 600;">
                ${App.moeda(cMedioKg)}
                <span class="sub-linha" style="font-size:11px;">${App.moeda(cMedioKg / 1000)}/g</span>
            </td>
            <td class="num" style="color: var(--primary); font-weight: 700;" title="Custo do lote ativo mais antigo">
                ${App.moeda(cPepsKg)}
                <span class="sub-linha" style="font-size:11px; color:var(--text-3);">${f.lote_ativo ? `Lote #${f.lote_ativo.id}` : 'Estimado'}</span>
            </td>
            <td class="num" style="font-weight: 600;">
                ${App.moeda(valTotal)}
            </td>
            <td>
                ${badgeStatus(f)}
            </td>
            <td class="col-acoes">
                <div class="acoes-icones">
                    <button type="button" class="btn-icone primario" onclick="abrirEntradaLotePara(${f.id})" title="Adicionar Entrada de Rolos / Compra">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                    </button>
                    <button type="button" class="btn-icone" onclick="abrirAjusteBalanca(${f.id})" title="Ajuste / Conferência na Balança">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m16 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="m2 16 3-8 3 8c-.87.65-1.92 1-3 1s-2.13-.35-3-1Z"/><path d="M7 21h10"/><path d="M12 3v18"/><path d="M3 7h2c2 0 5-1 7-2 2 1 5 2 7 2h2"/></svg>
                    </button>
                    <button type="button" class="btn-icone" onclick="abrirDetalhesLotes(${f.id})" title="Ver Lotes PEPS e Extrato">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M2 12h20"/><path d="M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-6"/><path d="M4 12V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v6"/><circle cx="12" cy="12" r="2"/></svg>
                    </button>
                    <button type="button" class="btn-icone" onclick="editarFilamento(${f.id})" title="Editar Cadastro">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                </div>
            </td>
        </tr>`;
    }).join('');
}

// -------------------------------------------------------------------------
// Modal de Novo / Editar Filamento
// -------------------------------------------------------------------------
function abrirModalFilamento(f = null) {
    filamentoEditandoId = f ? f.id : null;
    $('formFilamento').reset();

    $('filCor').value = f ? f.cor : '';
    $('filCorHex').value = f && f.cor_hex ? f.cor_hex : '#6366f1';
    $('filCorHexTexto').value = f && f.cor_hex ? f.cor_hex : '#6366f1';
    $('filTipo').value = f ? f.tipo : 'PLA';
    $('filMarca').value = f ? f.marca : '';
    $('filEstoqueMinimo').value = f ? (f.estoque_minimo_gramas || 500) : 500;
    $('filAtivo').value = f ? String(f.ativo ?? 1) : '1';

    $('filTituloModal').textContent = f ? 'Editar filamento' : 'Novo filamento';
    $('secaoLoteInicial').style.display = f ? 'none' : 'block';

    App.modal.abrir('modalFilamento', '#filCor');
}

$('filCorHex')?.addEventListener('input', e => {
    $('filCorHexTexto').value = e.target.value.toUpperCase();
});
$('filCorHexTexto')?.addEventListener('input', e => {
    let val = e.target.value;
    if (!val.startsWith('#')) val = '#' + val;
    if (val.length === 7) $('filCorHex').value = val;
});

async function salvarFilamento(ev) {
    ev.preventDefault();
    const payload = {
        cor: $('filCor').value.trim(),
        cor_hex: $('filCorHex').value,
        tipo: $('filTipo').value.trim(),
        marca: $('filMarca').value.trim(),
        estoque_minimo_gramas: parseFloat($('filEstoqueMinimo').value) || 500,
        ativo: $('filAtivo').value === '1',
    };

    if (!payload.cor) {
        App.toast('Informe a cor do filamento.', 'erro');
        $('filCor').focus();
        return;
    }
    if (!payload.marca) {
        App.toast('Informe a marca do filamento.', 'erro');
        $('filMarca').focus();
        return;
    }

    if (!filamentoEditandoId) {
        const rolosIniciais = parseInt($('filQtdRolosInicial').value) || 0;
        const precoRoloInicial = parseFloat($('filPrecoRoloInicial').value) || 0;
        if (rolosIniciais > 0 && precoRoloInicial > 0) {
            payload.quantidade_rolos = rolosIniciais;
            payload.preco_rolo = precoRoloInicial;
        }
    }

    const btn = $('btnSalvarFilamento');
    btn.disabled = true;
    try {
        if (filamentoEditandoId) {
            await App.api(`api/filamentos.php?id=${filamentoEditandoId}`, 'PUT', payload);
            App.toast('Filamento atualizado com sucesso!');
        } else {
            await App.api('api/filamentos.php', 'POST', payload);
            App.toast('Filamento cadastrado com sucesso!');
        }
        App.modal.fechar('modalFilamento');
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}

// -------------------------------------------------------------------------
// Modal Entrada de Rolos / Lote
// -------------------------------------------------------------------------
function popularSelectFilamentos(selecionadoId = 0) {
    const sel = $('loteFilamentoId');
    if (!sel) return;
    sel.innerHTML = '<option value="">Selecione um filamento...</option>' + filamentos.map(f => `
        <option value="${f.id}" ${f.id === selecionadoId ? 'selected' : ''}>
            ${esc(f.cor)} (${esc(f.tipo)} · ${esc(f.marca)}) — saldo: ${Number(f.estoque_gramas).toLocaleString('pt-BR')}g
        </option>
    `).join('');
}

function abrirEntradaLotePara(filamentoId = 0) {
    $('formEntradaLote').reset();
    popularSelectFilamentos(filamentoId);
    $('loteDataCompra').value = new Date().toISOString().split('T')[0];
    $('loteQtdRolos').value = 1;
    $('lotePesoRolo').value = 1000;
    recalcularResumoEntrada();
    App.modal.abrir('modalEntradaLote', filamentoId ? '#loteQtdRolos' : '#loteFilamentoId');
}

function recalcularResumoEntrada(origem = 'rolo') {
    const qtd = Math.max(1, parseInt($('loteQtdRolos').value) || 1);
    const pesoRolo = Math.max(1, parseFloat($('lotePesoRolo').value) || 1000);
    const totalGramas = qtd * pesoRolo;

    let precoRolo = parseFloat($('lotePrecoRolo').value) || 0;
    let precoTotal = parseFloat($('lotePrecoTotal').value) || 0;

    if (origem === 'rolo') {
        precoTotal = precoRolo * qtd;
        $('lotePrecoTotal').value = precoTotal > 0 ? precoTotal.toFixed(2) : '';
    } else if (origem === 'total' && qtd > 0) {
        precoRolo = precoTotal / qtd;
        $('lotePrecoRolo').value = precoRolo > 0 ? precoRolo.toFixed(2) : '';
    }

    const precoPorGrama = totalGramas > 0 ? (precoTotal / totalGramas) : 0;

    if ($('loteResumoGramas')) $('loteResumoGramas').textContent = `${totalGramas.toLocaleString('pt-BR')} g (${(totalGramas/1000).toFixed(2)} kg)`;
    if ($('loteResumoPrecoGrama')) $('loteResumoPrecoGrama').textContent = `R$ ${precoPorGrama.toFixed(4)} / g (${App.moeda(precoPorGrama * 1000)} / kg)`;
}

$('loteQtdRolos')?.addEventListener('input', () => recalcularResumoEntrada('rolo'));
$('lotePesoRolo')?.addEventListener('input', () => recalcularResumoEntrada('rolo'));
$('lotePrecoRolo')?.addEventListener('input', () => recalcularResumoEntrada('rolo'));
$('lotePrecoTotal')?.addEventListener('input', () => recalcularResumoEntrada('total'));

async function salvarEntradaLote(ev) {
    ev.preventDefault();
    const filId = parseInt($('loteFilamentoId').value) || 0;
    if (!filId) {
        App.toast('Selecione o filamento.', 'erro');
        $('loteFilamentoId').focus();
        return;
    }

    const payload = {
        filamento_id: filId,
        quantidade_rolos: parseInt($('loteQtdRolos').value) || 1,
        peso_rolo_gramas: parseFloat($('lotePesoRolo').value) || 1000,
        preco_rolo: parseFloat($('lotePrecoRolo').value) || 0,
        preco_total: parseFloat($('lotePrecoTotal').value) || 0,
        data_compra: $('loteDataCompra').value,
        observacoes: $('loteObs').value.trim(),
    };

    if (payload.preco_rolo <= 0 && payload.preco_total <= 0) {
        App.toast('Informe o preço pago pelo rolo.', 'erro');
        $('lotePrecoRolo').focus();
        return;
    }

    const btn = $('btnSalvarLote');
    btn.disabled = true;
    try {
        const res = await App.api('api/filamentos.php?acao=entrada_lote', 'POST', payload);
        App.toast(res.mensagem || 'Lote registrado com sucesso!');
        App.modal.fechar('modalEntradaLote');
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}

// -------------------------------------------------------------------------
// Modal Ajuste Manual de Balança
// -------------------------------------------------------------------------
function abrirAjusteBalanca(filId) {
    const f = filamentos.find(x => x.id === filId);
    if (!f) return;
    $('ajusteFilamentoId').value = f.id;
    $('ajusteTituloModal').textContent = `Ajuste / Balança: ${f.cor} (${f.tipo})`;
    $('ajusteSaldoAtualTexto').textContent = `${Number(f.estoque_gramas).toLocaleString('pt-BR')} g`;
    $('ajusteNovoSaldo').value = Math.round(Number(f.estoque_gramas));
    atualizarDiferencaAjuste(Number(f.estoque_gramas));
    App.modal.abrir('modalAjusteFilamento', '#ajusteNovoSaldo');
}

function atualizarDiferencaAjuste(saldoAtual) {
    const novo = parseFloat($('ajusteNovoSaldo').value) || 0;
    const diff = novo - saldoAtual;
    const inputDiff = $('ajusteDiferenca');
    if (inputDiff) {
        inputDiff.value = (diff >= 0 ? `+${diff.toLocaleString('pt-BR')} g` : `${diff.toLocaleString('pt-BR')} g`);
        inputDiff.style.color = diff < 0 ? 'var(--perigo)' : 'var(--sucesso)';
    }
}

$('ajusteNovoSaldo')?.addEventListener('input', () => {
    const filId = parseInt($('ajusteFilamentoId').value) || 0;
    const f = filamentos.find(x => x.id === filId);
    if (f) atualizarDiferencaAjuste(Number(f.estoque_gramas));
});

async function salvarAjuste(ev) {
    ev.preventDefault();
    const filId = parseInt($('ajusteFilamentoId').value) || 0;
    const payload = {
        filamento_id: filId,
        novo_estoque_gramas: parseFloat($('ajusteNovoSaldo').value) || 0,
        motivo: $('ajusteMotivo').value.trim(),
    };

    const btn = $('btnSalvarAjuste');
    btn.disabled = true;
    try {
        await App.api('api/filamentos.php?acao=ajuste_estoque', 'POST', payload);
        App.toast('Ajuste de estoque gravado com sucesso!');
        App.modal.fechar('modalAjusteFilamento');
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}

// -------------------------------------------------------------------------
// Modal Detalhes: Lotes PEPS & Histórico de Movimentações
// -------------------------------------------------------------------------
async function abrirDetalhesLotes(filId) {
    try {
        const info = await App.api(`api/filamentos.php?id=${filId}`);
        filamentoSelecionado = info;

        $('detTituloModal').textContent = `${info.cor} (${info.tipo} · ${info.marca})`;
        $('detSubModal').textContent = `Lotes ordenados por data de compra (regra PEPS / FIFO)`;

        $('detSaldoGramas').textContent = `${Number(info.estoque_gramas || 0).toLocaleString('pt-BR')} g`;
        $('detSaldoRolos').textContent = `${Number(info.estoque_rolos || 0).toFixed(2)} rolos`;
        $('detCustoMedio').textContent = `${App.moeda(info.custo_medio_kg || 0)} / kg`;
        $('detCustoPeps').textContent = `${App.moeda(info.custo_peps_kg || 0)} / kg`;
        $('detValorTotal').textContent = App.moeda(info.valor_total_estoque || 0);

        // Lotes
        const tbodyLotes = $('tabelaLotesDetalhes');
        const lotes = info.todos_lotes || [];
        if (!lotes.length) {
            tbodyLotes.innerHTML = '<tr><td colspan="9" class="vazio">Nenhum lote registrado. Registre a primeira entrada de rolos.</td></tr>';
        } else {
            tbodyLotes.innerHTML = lotes.map(lt => {
                const saldo = Number(lt.gramas_saldo) || 0;
                const esgotado = saldo <= 0;
                const ehAtivoPeps = info.lote_ativo && info.lote_ativo.id === lt.id;

                return `
                <tr style="${esgotado ? 'opacity: 0.55;' : ''}">
                    <td>
                        <b>#${lt.id}</b>
                        ${ehAtivoPeps ? '<span class="tag-status pronto" style="font-size:10px; margin-left:4px;">PEPS Atual</span>' : ''}
                    </td>
                    <td>${App.data(lt.data_compra)}</td>
                    <td class="num">${lt.quantidade_rolos} un</td>
                    <td class="num">${App.moeda(lt.preco_rolo)}</td>
                    <td class="num">R$ ${Number(lt.preco_por_grama || 0).toFixed(4)}/g</td>
                    <td class="num">${Number(lt.peso_total_gramas || 0).toLocaleString('pt-BR')} g</td>
                    <td class="num" style="font-weight: 700; color: ${esgotado ? 'var(--text-3)' : 'var(--sucesso)'};">
                        ${saldo.toLocaleString('pt-BR')} g
                    </td>
                    <td>
                        ${esgotado ? '<span class="tag-status atrasado" style="font-size:10.5px;">Esgotado</span>' : '<span class="tag-status pronto" style="font-size:10.5px;">Ativo</span>'}
                    </td>
                    <td style="font-size: 12px; color: var(--text-2);">${esc(lt.observacoes || '—')}</td>
                </tr>`;
            }).join('');
        }

        // Movimentações
        const tbodyMov = $('tabelaMovimentacoesDetalhes');
        const movs = info.movimentacoes_recentes || [];
        if (!movs.length) {
            tbodyMov.innerHTML = '<tr><td colspan="7" class="vazio">Nenhuma movimentação registrada.</td></tr>';
        } else {
            tbodyMov.innerHTML = movs.map(m => {
                const g = Number(m.gramas) || 0;
                const sinal = g > 0 ? `+${g.toLocaleString('pt-BR')}` : `${g.toLocaleString('pt-BR')}`;
                const corNum = g > 0 ? 'var(--sucesso)' : 'var(--perigo)';

                return `
                <tr>
                    <td style="font-size:12px;">${App.dataHora(m.criado_em)}</td>
                    <td><span class="badge-tipo ${m.tipo === 'compra' ? 'simples' : (m.tipo === 'ajuste' ? 'componente' : 'composto')}" style="font-size:10.5px;">${esc(m.tipo)}</span></td>
                    <td class="num" style="font-weight: 700; color: ${corNum};">${sinal} g</td>
                    <td class="num">${Number(m.saldo_posterior_gramas || 0).toLocaleString('pt-BR')} g</td>
                    <td class="num">${App.moeda(m.custo_total)}</td>
                    <td style="font-size:12px;">${esc(m.observacoes || '—')}</td>
                    <td style="font-size:12px; color:var(--text-3);">${esc(m.usuario_nome || '—')}</td>
                </tr>`;
            }).join('');
        }

        App.modal.abrir('modalDetalhesFilamento');
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

const editarFilamento = id => abrirModalFilamento(filamentos.find(f => f.id === id));

// Event Listeners
$('btnNovoFilamento')?.addEventListener('click', () => abrirModalFilamento());
$('btnEntradaLote')?.addEventListener('click', () => abrirEntradaLotePara(0));
$('formFilamento')?.addEventListener('submit', salvarFilamento);
$('formEntradaLote')?.addEventListener('submit', salvarEntradaLote);
$('formAjusteFilamento')?.addEventListener('submit', salvarAjuste);

$('buscaFilamento')?.addEventListener('input', () => {
    renderizarCores(coresFilamentos);
    renderizar();
});
$('filtroTipo')?.addEventListener('change', renderizar);
$('filtroStatus')?.addEventListener('change', renderizar);

// Globais para onclick inline
window.abrirEntradaLotePara = abrirEntradaLotePara;
window.abrirAjusteBalanca = abrirAjusteBalanca;
window.abrirAjusteBalança = abrirAjusteBalanca;
window.abrirDetalhesLotes = abrirDetalhesLotes;
window.editarFilamento = editarFilamento;
window.filtrarPorCor = filtrarPorCor;

carregar();
