// Relatórios: parte do livro de pedidos (tudo o que foi pedido, produzido ou
// não) e não do log de produção. Cada número traz pedido / produzido / falta.
let ultimo = null;
let abaAtual = 'entregas';

const $ = id => document.getElementById(id);
const fmtInt = new Intl.NumberFormat('pt-BR');
const fmtMoeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

const STATUS = {
    aberto: 'Aberto', em_producao: 'Em produção', pronto: 'Pronto',
    entregue: 'Entregue', cancelado: 'Cancelado',
};

const esc = v => String(v ?? '').replace(/[&<>"']/g, c =>
    ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

const iso = d => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const data = s => s ? s.split('-').reverse().join('/') : '—';
const num = (v, n = 0) => (Number(v) || 0).toFixed(n);

// ============================================================
// Filtros
// ============================================================
function definirPeriodo(tipo) {
    const hoje = new Date();
    let de = new Date(hoje), ate = new Date(hoje);
    if (tipo === '7') de.setDate(hoje.getDate() - 6);
    else if (tipo === 'mes') { de = new Date(hoje.getFullYear(), hoje.getMonth(), 1); ate = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0); }
    else if (tipo === 'mes-anterior') { de = new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1); ate = new Date(hoje.getFullYear(), hoje.getMonth(), 0); }
    else if (tipo === 'ano') { de = new Date(hoje.getFullYear(), 0, 1); ate = new Date(hoje.getFullYear(), 11, 31); }
    else if (tipo === 'tudo') { de = new Date(2000, 0, 1); ate = new Date(hoje.getFullYear() + 5, 11, 31); }
    $('relDe').value = iso(de);
    $('relAte').value = iso(ate);
}

async function popular(url, sel, mapear) {
    try {
        const res = await fetch(url);
        const dados = await res.json();
        (Array.isArray(dados) ? dados : dados.usuarios || []).forEach(x => {
            const opt = document.createElement('option');
            const [valor, texto] = mapear(x);
            opt.value = valor;
            opt.textContent = texto;
            sel.appendChild(opt);
        });
    } catch (e) { /* filtro é opcional */ }
}

async function gerar() {
    const de = $('relDe').value, ate = $('relAte').value;
    if (!de || !ate) return App.toast('Informe as datas inicial e final.', 'erro');

    const params = new URLSearchParams({ data_de: de, data_ate: ate, base: $('relBase').value });
    [['relProduto', 'produto_id'], ['relCliente', 'cliente_id'],
     ['relStatus', 'status'], ['relTipo', 'tipo']].forEach(([id, chave]) => {
        if ($(id).value) params.set(chave, $(id).value);
    });

    try {
        ultimo = await App.api('api/relatorios.php?' + params.toString());
        renderizar(ultimo);
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

// ============================================================
// Tabelas
// ============================================================
function tabela(cabecalhos, linhas, rodape, vazio = 'Nada no período.') {
    if (!linhas.length) return `<p class="vazio">${vazio}</p>`;
    const th = cabecalhos.map(([t, n]) => `<th class="${n ? 'num' : ''}">${t}</th>`).join('');
    const tr = linhas.map(l => `<tr>${l.map(([v, n]) => `<td class="${n ? 'num' : ''}">${v}</td>`).join('')}</tr>`).join('');
    const tf = rodape ? `<tfoot><tr>${rodape.map(([v, n]) => `<td class="${n ? 'num' : ''}">${v}</td>`).join('')}</tr></tfoot>` : '';
    return `<table><thead><tr>${th}</tr></thead><tbody>${tr}</tbody>${tf}</table>`;
}

// Barra de progresso produzido/pedido.
function progresso(produzida, pedida) {
    const pct = pedida > 0 ? Math.round((produzida / pedida) * 100) : 0;
    return `<div class="progresso-box"><div class="progresso" style="width:70px"><div style="width:${pct}%"></div></div>${pct}%</div>`;
}

const etiquetaDias = d => {
    const n = Number(d);
    if (n < 0) return `<span class="prazo atrasado">${Math.abs(n)}d atrasado</span>`;
    if (n === 0) return `<span class="prazo urgente">hoje</span>`;
    if (n <= 7) return `<span class="prazo proximo">${n}d</span>`;
    return `<span class="prazo">${n}d</span>`;
};

// ============================================================
// Render
// ============================================================
function renderizar(d) {
    const r = d.resumo;
    $('resultado').hidden = false;
    $('tituloPeriodo').textContent =
        `${d.periodo.base === 'entrega' ? 'Entregas previstas' : 'Pedidos feitos'} de ${data(d.periodo.de)} a ${data(d.periodo.ate)}`;

    // ---------- KPIs: o que foi pedido x o que saiu x o que falta ----------
    const kpis = [
        ['Itens pedidos', fmtInt.format(r.itens_pedidos), `em ${fmtInt.format(r.pedidos)} pedido(s)`, ''],
        ['Valor pedido', fmtMoeda.format(r.valor_pedido), `${fmtInt.format(r.produtos)} produto(s) diferentes`, ''],
        ['Já produzido', fmtInt.format(r.itens_produzidos), fmtMoeda.format(r.valor_produzido), 'kpi-montavel'],
        ['Falta produzir', fmtInt.format(r.itens_falta), fmtMoeda.format(r.valor_falta), Number(r.itens_falta) ? 'kpi-estoque' : ''],
        ['Atrasados', fmtInt.format(r.itens_atrasados), `${fmtInt.format(r.pedidos_atrasados)} pedido(s) vencido(s)`, Number(r.itens_atrasados) ? 'kpi-alerta' : ''],
        ['Entregar em 7 dias', fmtInt.format(r.itens_7dias), 'unidades a produzir', ''],
    ];
    $('resumo').innerHTML = kpis.map(([rot, val, sub, cls]) =>
        `<div class="kpi ${cls}"><div class="rotulo">${rot}</div><div class="valor">${val}</div><div class="sub">${sub}</div></div>`).join('');

    $('contaEntregas').textContent = d.agenda.length;
    $('contaEntregas').hidden = !d.agenda.length;
    $('contaFabricar').textContent = d.a_fabricar.length;
    $('contaFabricar').hidden = !d.a_fabricar.length;

    // Alerta de filamentos da carteira
    if (d.planejamento_filamento) {
        const fFalta = d.planejamento_filamento.total_cores_com_falta || 0;
        const badgeFil = $('contaAlertaFilamento');
        if (badgeFil) {
            badgeFil.textContent = fFalta > 0 ? `${fFalta} em falta` : 'ok';
            badgeFil.className = `contador ${fFalta > 0 ? 'atrasado' : ''}`;
            badgeFil.hidden = fFalta === 0;
        }
    }

    renderEntregas(d.agenda);
    renderFabricar(d.a_fabricar);
    renderFilamento(d);
    renderPedidos(d.por_pedido);
    renderProdutos(d.por_produto, r);
    renderClientes(d.por_cliente);
    renderProducao(d);
}

// ---------- Agenda de entregas, agrupada por urgência ----------
function renderEntregas(agenda) {
    if (!agenda.length) {
        $('painelEntregas').innerHTML = '<p class="vazio">Nenhuma entrega pendente no período. 🎉</p>';
        return;
    }
    const janelas = [
        ['atrasado', 'Atrasados', 'alerta'],
        ['hoje', 'Para hoje', 'urgente'],
        ['semana', 'Próximos 7 dias', 'proximo'],
        ['depois', 'Mais adiante', ''],
    ];
    $('painelEntregas').innerHTML = janelas.map(([chave, titulo, cls]) => {
        const itens = agenda.filter(a => a.janela === chave);
        if (!itens.length) return '';
        const unidades = itens.reduce((s, a) => s + Number(a.itens_falta), 0);
        const valor = itens.reduce((s, a) => s + Number(a.valor_falta), 0);
        return `
        <div class="grupo-entrega ${cls}">
            <div class="grupo-entrega-topo">
                <strong>${titulo}</strong>
                <span>${fmtInt.format(itens.length)} pedido(s) · ${fmtInt.format(unidades)} un. a produzir · ${fmtMoeda.format(valor)}</span>
            </div>
            <div class="tabela-rolagem">
                ${tabela(
                    [['Entrega'], ['Prazo'], ['Pedido'], ['Cliente'], ['Situação'], ['Falta', 1], ['Valor a faturar', 1]],
                    itens.map(a => [
                        [data(a.data_entrega_prometida)],
                        [etiquetaDias(a.dias)],
                        [`<a class="pedido-id" href="pedidos.php?editar=${a.id}">#${a.id}</a>`],
                        [a.tipo === 'estoque' ? '<span class="destino-estoque">Estoque</span>' : esc(a.cliente)],
                        [`<span class="badge ${esc(a.status)}">${STATUS[a.status] || esc(a.status)}</span>`],
                        [fmtInt.format(a.itens_falta), 1],
                        [fmtMoeda.format(a.valor_falta), 1],
                    ])
                )}
            </div>
        </div>`;
    }).join('');
}

// ---------- O que falta fabricar ----------
function renderFabricar(lista) {
    $('painelFabricar').innerHTML = tabela(
        [['Produto'], ['Entrega mais próxima'], ['Prazo'], ['Pedidos', 1], ['Falta produzir', 1],
         ['Estoque pronto', 1], ['Montável agora', 1], ['Valor a faturar', 1]],
        lista.map(f => {
            const montavel = f.montavel === null ? '—' : fmtInt.format(f.montavel);
            const cobre = f.montavel !== null && Number(f.montavel) >= Number(f.falta_produzir);
            return [
                [`<strong>${esc(f.nome)}</strong>`],
                [data(f.entrega_mais_proxima)],
                [etiquetaDias(f.dias)],
                [fmtInt.format(f.pedidos), 1],
                [`<strong>${fmtInt.format(f.falta_produzir)}</strong>`, 1],
                [fmtInt.format(f.estoque_pronto), 1],
                [`<span class="${cobre ? 'tag-disp pronto' : 'tag-disp parcial'}">${montavel}</span>`, 1],
                [fmtMoeda.format(f.valor_falta), 1],
            ];
        }),
        null,
        'Nada pendente de fabricação neste período. 🎉'
    );
}

// ---------- Controle de Filamentos & Demanda da Carteira ----------
function renderFilamento(d) {
    const pf = d.planejamento_filamento;
    const est = d.estoque_filamentos;

    // Alerta em destaque no topo da aba de filamentos
    const boxAlerta = $('alertaGeralFilamentoCarteira');
    if (boxAlerta) {
        if (pf && pf.tem_alerta_geral) {
            boxAlerta.style.display = 'block';
            boxAlerta.className = 'alerta-erro';
            boxAlerta.innerHTML = `
                <div style="display:flex; align-items:flex-start; gap:10px; background: rgba(239, 68, 68, 0.12); border: 1px solid var(--perigo); border-radius: var(--radius); padding: 12px 16px;">
                    <span style="font-size:22px;">🚨</span>
                    <div>
                        <strong style="color: var(--perigo);">ALERTA CRÍTICO: Falta filamento para atender os pedidos em aberto!</strong>
                        <div style="font-size:13px; margin-top:3px; color: var(--text);">
                            Há <b>${pf.total_cores_com_falta} cor(es)</b> com estoque insuficiente. É necessário comprar ao menos <b>${pf.rolos_para_comprar_total} rolo(s) de 1kg</b> para honrar as entregas prometidas.
                        </div>
                    </div>
                </div>
            `;
        } else {
            boxAlerta.style.display = 'block';
            boxAlerta.className = 'alerta-sucesso';
            boxAlerta.innerHTML = `
                <div style="display:flex; align-items:center; gap:8px; background: rgba(34, 197, 94, 0.12); border: 1px solid var(--sucesso); border-radius: var(--radius); padding: 10px 16px;">
                    <span style="font-size:18px;">✅</span>
                    <strong style="color: var(--sucesso);">Estoque de filamento suficiente para atender todos os pedidos pendentes da carteira!</strong>
                </div>
            `;
        }
    }

    // Tabela 1: Demanda da Carteira de Pedidos
    const coresDemanda = pf?.cores || [];
    if ($('painelDemandaFilamento')) {
        if (!coresDemanda.length) {
            $('painelDemandaFilamento').innerHTML = '<p class="vazio">Nenhum pedido pendente demandando filamento no momento.</p>';
        } else {
            $('painelDemandaFilamento').innerHTML = tabela(
                [['Cor'], ['Necessidade (g)', 1], ['Rolos Nec.', 1], ['Estoque Atual (g)', 1], ['Saldo Projetado (g)', 1], ['Falta Comprar', 1], ['Custo Est. (R$)', 1], ['Diagnóstico / Alerta']],
                coresDemanda.map(c => {
                    const falta = c.falta_gramas > 0;
                    const hex = c.cor_hex || '#6366f1';
                    const alertaHtml = falta
                        ? `<span class="tag-status atrasado" style="font-weight:700;">🚨 Faltam ${fmtInt.format(c.falta_gramas)}g (${c.rolos_comprar} rolo(s))</span>`
                        : `<span class="tag-status pronto">✅ Atende (sobra ${fmtInt.format(c.saldo_projetado_gramas)}g)</span>`;

                    return [
                        [`<div style="display:flex;align-items:center;gap:6px;"><span style="width:14px;height:14px;border-radius:50%;background-color:${esc(hex)};border:1px solid var(--border);display:inline-block;"></span><b>${esc(c.cor)}</b></div>`],
                        [`<strong>${fmtInt.format(c.gramas_necessarias)} g</strong>`, 1],
                        [`${c.rolos_necessarios} un`, 1],
                        [`${fmtInt.format(c.estoque_atual_gramas)} g`, 1],
                        [`<span style="font-weight:600; color:${c.saldo_projetado_gramas < 0 ? 'var(--perigo)' : 'var(--sucesso)'};">${fmtInt.format(c.saldo_projetado_gramas)} g</span>`, 1],
                        [`<b style="color:${falta ? 'var(--perigo)' : 'var(--text-3)'};">${falta ? `${c.rolos_comprar} rolo(s)` : '0'}</b>`, 1],
                        [`${fmtMoeda.format(c.custo_estimado)}`, 1],
                        [alertaHtml],
                    ];
                })
            );
        }
    }

    // Tabela 2: Inventário de Filamentos
    const itensEst = est?.itens || [];
    if ($('painelInventarioFilamento')) {
        if (!itensEst.length) {
            $('painelInventarioFilamento').innerHTML = '<p class="vazio">Nenhum filamento cadastrado no estoque ainda. <a href="filamentos.php">Cadastre aqui</a>.</p>';
        } else {
            $('painelInventarioFilamento').innerHTML = tabela(
                [['Cor'], ['Tipo / Marca'], ['Saldo em Estoque', 1], ['Rolos (1kg)', 1], ['Custo Médio Ponderado', 1], ['Custo Lote PEPS Ativo', 1], ['Valor Total Estoque', 1], ['Situação']],
                itensEst.map(f => {
                    const hex = f.cor_hex || '#6366f1';
                    return [
                        [`<div style="display:flex;align-items:center;gap:6px;"><span style="width:14px;height:14px;border-radius:50%;background-color:${esc(hex)};border:1px solid var(--border);display:inline-block;"></span><b>${esc(f.cor)}</b></div>`],
                        [`${esc(f.tipo)} · ${esc(f.marca)}`],
                        [`<strong>${fmtInt.format(f.estoque_gramas)} g</strong>`, 1],
                        [`${Number(f.estoque_rolos).toFixed(2)} un`, 1],
                        [`${fmtMoeda.format(f.custo_medio_kg)} / kg`, 1],
                        [`<b style="color:var(--primary);">${fmtMoeda.format(f.custo_peps_kg)} / kg</b>`, 1],
                        [`${fmtMoeda.format(f.valor_total_estoque)}`, 1],
                        [`<span class="tag-status ${f.status === 'ok' ? 'pronto' : (f.status === 'baixo' ? 'parcial' : 'atrasado')}">${f.status === 'ok' ? 'Em estoque' : (f.status === 'baixo' ? 'Estoque baixo' : 'Zerado')}</span>`],
                    ];
                })
            );
        }
    }
}

// ============================================================
// Simulador / Planejador de Eventos Grandes
// ============================================================
let itensEventoSimulador = [
    { produto_id: 0, quantidade: 100 }
];
let listaProdutosGeral = [];

function renderLinhasEventoSimulador() {
    const container = $('listaItensSimuladorEvento');
    if (!container) return;

    if (!itensEventoSimulador.length) {
        itensEventoSimulador = [{ produto_id: 0, quantidade: 100 }];
    }

    container.innerHTML = itensEventoSimulador.map((item, idx) => `
        <div style="display: flex; gap: 10px; align-items: center; background: var(--surface-1); padding: 8px 12px; border-radius: var(--radius-sm); border: 1px solid var(--border);">
            <div style="flex: 2;">
                <label style="font-size:11px; margin-bottom:2px; display:block;">Produto para o Evento</label>
                <select class="sel-prod-evento" onchange="atualizarItemEvento(${idx}, 'produto_id', this.value)" style="margin:0; width:100%;">
                    <option value="">Selecione o produto...</option>
                    ${listaProdutosGeral.map(p => `
                        <option value="${p.id}" ${Number(p.id) === Number(item.produto_id) ? 'selected' : ''}>
                            ${esc(p.nome)} (${p.tipo || 'simples'}) — ${Number(p.peso_gramas) > 0 ? p.peso_gramas + 'g' : 'peso n/d'}
                        </option>
                    `).join('')}
                </select>
            </div>
            <div style="flex: 1; max-width: 150px;">
                <label style="font-size:11px; margin-bottom:2px; display:block;">Quantidade (un)</label>
                <input type="number" min="1" step="1" value="${item.quantidade || 100}" oninput="atualizarItemEvento(${idx}, 'quantidade', this.value)" style="margin:0; width:100%; text-align:center;">
            </div>
            <div style="padding-top: 14px;">
                <button type="button" class="btn-icone perigo" onclick="removerLinhaEvento(${idx})" title="Remover item">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
    `).join('');
}

function atualizarItemEvento(idx, campo, valor) {
    if (!itensEventoSimulador[idx]) return;
    itensEventoSimulador[idx][campo] = campo === 'quantidade' ? Math.max(1, parseInt(valor) || 1) : parseInt(valor) || 0;
}

function adicionarLinhaEvento() {
    itensEventoSimulador.push({ produto_id: 0, quantidade: 100 });
    renderLinhasEventoSimulador();
}

function removerLinhaEvento(idx) {
    if (itensEventoSimulador.length > 1) {
        itensEventoSimulador.splice(idx, 1);
    } else {
        itensEventoSimulador = [{ produto_id: 0, quantidade: 100 }];
    }
    renderLinhasEventoSimulador();
}

async function calcularPlanejamentoEvento() {
    const itensValidos = itensEventoSimulador.filter(x => x.produto_id > 0 && x.quantidade > 0);
    if (!itensValidos.length) {
        App.toast('Selecione ao menos um produto e quantidade para o evento.', 'erro');
        return;
    }

    const btn = $('btnCalcularEvento');
    btn.disabled = true;
    try {
        const res = await App.api('api/relatorios.php', 'POST', { itens: itensValidos });
        renderResultadoEvento(res);
        $('resultadoPlanejamentoEvento').style.display = 'block';
        $('resultadoPlanejamentoEvento').scrollIntoView({ behavior: 'smooth' });
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}

function renderResultadoEvento(res) {
    // KPIs do Evento
    const kpis = [
        ['Tempo de Impressão Total', res.tempo_total_formatado || '00:00:00', 'Horas estimadas de máquina', ''],
        ['Filamento Total Necessário', `${(res.peso_total_kg || 0).toLocaleString('pt-BR', {minimumFractionDigits:2})} kg`, `${fmtInt.format(res.peso_total_gramas)} gramas`, ''],
        ['Custo Financeiro Estimado', fmtMoeda.format(res.custo_total_estimado || 0), 'Custo de material consumido (PEPS)', 'kpi-montavel'],
        ['Rolos a Comprar', `${res.rolos_para_comprar_total || 0} rolos`, res.tem_alerta_geral ? '🚨 Falta material no estoque!' : '✅ Estoque atende 100%', res.tem_alerta_geral ? 'kpi-alerta' : ''],
    ];
    $('kpisEvento').innerHTML = kpis.map(([rot, val, sub, cls]) =>
        `<div class="kpi ${cls}"><div class="rotulo">${rot}</div><div class="valor">${val}</div><div class="sub">${sub}</div></div>`).join('');

    // Alerta do Evento
    const alertaDiv = $('alertaGeralEvento');
    if (res.tem_alerta_geral) {
        alertaDiv.className = 'alerta-erro';
        alertaDiv.innerHTML = `
            <div style="display:flex; align-items:flex-start; gap:10px; background: rgba(239, 68, 68, 0.12); border: 1px solid var(--perigo); border-radius: var(--radius); padding: 12px 16px;">
                <span style="font-size:24px;">🚨</span>
                <div>
                    <strong style="color: var(--perigo);">ESTOQUE INSUFICIENTE PARA O EVENTO: ${res.total_cores_com_falta} cor(es) em falta!</strong>
                    <p style="margin:4px 0 0 0; font-size:13px; color: var(--text);">
                        Para atender esse lote de produção, você precisará providenciar a compra de <b>${res.rolos_para_comprar_total} carretel(is) de 1kg</b> conforme a lista de cores abaixo.
                    </p>
                </div>
            </div>
        `;
    } else {
        alertaDiv.className = 'alerta-sucesso';
        alertaDiv.innerHTML = `
            <div style="display:flex; align-items:center; gap:8px; background: rgba(34, 197, 94, 0.12); border: 1px solid var(--sucesso); border-radius: var(--radius); padding: 10px 16px;">
                <span style="font-size:20px;">🎉</span>
                <strong style="color: var(--sucesso);">ESTOQUE 100% SUFICIENTE! Todas as cores necessárias estão disponíveis nos carretéis em estoque.</strong>
            </div>
        `;
    }

    // Tabela por Cor
    $('tabelaCoresEvento').innerHTML = tabela(
        [['Cor do Filamento'], ['Consumo do Evento', 1], ['Rolos (1kg)', 1], ['Estoque Atual (g)', 1], ['Saldo Após Evento (g)', 1], ['Falta Comprar', 1], ['Custo Est. (R$)', 1], ['Diagnóstico do Evento']],
        (res.cores || []).map(c => {
            const falta = c.falta_gramas > 0;
            const hex = c.cor_hex || '#6366f1';
            const diag = falta
                ? `<span class="tag-status atrasado" style="font-weight:700;">🚨 Comprar ${c.rolos_comprar} rolo(s) (faltam ${fmtInt.format(c.falta_gramas)}g)</span>`
                : `<span class="tag-status pronto">✅ Atende (sobrará ${fmtInt.format(c.saldo_projetado_gramas)}g)</span>`;

            return [
                [`<div style="display:flex;align-items:center;gap:6px;"><span style="width:14px;height:14px;border-radius:50%;background-color:${esc(hex)};border:1px solid var(--border);display:inline-block;"></span><b>${esc(c.cor)}</b></div>`],
                [`<strong>${fmtInt.format(c.gramas_necessarias)} g</strong>`, 1],
                [`${c.rolos_necessarios} un`, 1],
                [`${fmtInt.format(c.estoque_atual_gramas)} g`, 1],
                [`<span style="font-weight:600; color:${c.saldo_projetado_gramas < 0 ? 'var(--perigo)' : 'var(--sucesso)'};">${fmtInt.format(c.saldo_projetado_gramas)} g</span>`, 1],
                [`<b style="color:${falta ? 'var(--perigo)' : 'var(--text-3)'};">${falta ? `${c.rolos_comprar} rolo(s)` : '0'}</b>`, 1],
                [`${fmtMoeda.format(c.custo_estimado)}`, 1],
                [diag],
            ];
        })
    );
}

// ---------- Por pedido, expansível até o valor de cada item ----------
function renderPedidos(pedidos) {
    if (!pedidos.length) {
        $('painelPedidos').innerHTML = '<p class="vazio">Nenhum pedido no período.</p>';
        return;
    }
    const linhas = pedidos.map(p => {
        const cancelado = p.status === 'cancelado';
        const itens = (p.itens || []).map(i => `
            <tr class="linha-item-pedido">
                <td></td>
                <td colspan="2">↳ ${esc(i.produto_nome)}</td>
                <td class="num">${fmtInt.format(i.qtd_pedida)} × ${fmtMoeda.format(i.preco)}</td>
                <td class="num">${fmtInt.format(i.qtd_produzida)}</td>
                <td class="num">${Number(i.qtd_falta) ? `<strong>${fmtInt.format(i.qtd_falta)}</strong>` : '✓'}</td>
                <td class="num"><strong>${fmtMoeda.format(i.valor_item)}</strong></td>
            </tr>`).join('');

        return `
            <tr class="linha-pedido${cancelado ? ' cancelado' : ''}" onclick="alternarPedido(${p.id})">
                <td><span class="seta" id="seta-${p.id}">▸</span> <a class="pedido-id" href="pedidos.php?editar=${p.id}" onclick="event.stopPropagation()">#${p.id}</a></td>
                <td>${p.tipo === 'estoque' ? '<span class="destino-estoque">Estoque</span>' : esc(p.cliente_nome || '—')}
                    ${cancelado ? '<span class="badge cancelado">Cancelado</span>' : ''}</td>
                <td>${data(p.data_entrega_prometida)} ${cancelado ? '' : etiquetaDias(p.dias_para_entrega)}</td>
                <td class="num">${fmtInt.format(p.itens_pedidos)}</td>
                <td class="num">${fmtInt.format(p.itens_produzidos)}</td>
                <td class="num">${Number(p.itens_falta) && !cancelado ? `<strong>${fmtInt.format(p.itens_falta)}</strong>` : '✓'}</td>
                <td class="num"><strong>${fmtMoeda.format(p.valor_pedido)}</strong></td>
            </tr>
            <tbody class="itens-pedido" id="itens-${p.id}" hidden>${itens}</tbody>`;
    }).join('');

    // O total ignora cancelados, para bater com os KPIs do topo.
    const vivos = pedidos.filter(p => p.status !== 'cancelado');
    const cancelados = pedidos.length - vivos.length;
    const tot = vivos.reduce((a, p) => ({
        pedidos: a.pedidos + Number(p.itens_pedidos),
        produzidos: a.produzidos + Number(p.itens_produzidos),
        falta: a.falta + Number(p.itens_falta),
        valor: a.valor + Number(p.valor_pedido),
    }), { pedidos: 0, produzidos: 0, falta: 0, valor: 0 });

    $('painelPedidos').innerHTML = `
        <table class="tabela-pedidos-rel">
            <thead>
                <tr>
                    <th>Pedido</th><th>Cliente</th><th>Entrega</th>
                    <th class="num">Pedido</th><th class="num">Produzido</th><th class="num">Falta</th><th class="num">Valor</th>
                </tr>
            </thead>
            ${linhas}
            <tfoot>
                <tr>
                    <td colspan="3"><strong>Total · ${fmtInt.format(vivos.length)} pedido(s)</strong>${
                        cancelados ? ` <span class="vazio">(${fmtInt.format(cancelados)} cancelado(s) fora do total)</span>` : ''}</td>
                    <td class="num"><strong>${fmtInt.format(tot.pedidos)}</strong></td>
                    <td class="num">${fmtInt.format(tot.produzidos)}</td>
                    <td class="num">${fmtInt.format(tot.falta)}</td>
                    <td class="num"><strong>${fmtMoeda.format(tot.valor)}</strong></td>
                </tr>
            </tfoot>
        </table>`;
}

window.alternarPedido = function (id) {
    const corpo = $(`itens-${id}`);
    const seta = $(`seta-${id}`);
    if (!corpo) return;
    corpo.hidden = !corpo.hidden;
    seta.textContent = corpo.hidden ? '▸' : '▾';
};

// Preço médio efetivamente praticado nos pedidos. Se o catálogo foi reajustado
// depois, avisa — o relatório mostra o histórico, não o preço de hoje.
function precoPraticado(p) {
    const praticado = Number(p.preco) || 0;
    const tabela = Number(p.preco_tabela) || 0;
    if (Math.abs(praticado - tabela) < 0.005) return fmtMoeda.format(praticado);
    return `${fmtMoeda.format(praticado)}
        <span class="prazo" title="Preço atual no catálogo: ${fmtMoeda.format(tabela)}">tabela hoje ${fmtMoeda.format(tabela)}</span>`;
}

// ---------- Por produto ----------
function renderProdutos(lista, r) {
    $('painelProdutos').innerHTML = tabela(
        [['Produto'], ['Preço praticado', 1], ['Qtd. pedida', 1], ['Produzida', 1], ['Falta', 1],
         ['Andamento', 1], ['Pedidos', 1], ['Valor pedido', 1], ['Valor a faturar', 1]],
        lista.map(p => [
            [`<strong>${esc(p.nome)}</strong>`],
            [precoPraticado(p), 1],
            [`<strong>${fmtInt.format(p.qtd_pedida)}</strong>`, 1],
            [fmtInt.format(p.qtd_produzida), 1],
            [Number(p.qtd_falta) ? fmtInt.format(p.qtd_falta) : '✓', 1],
            [progresso(Number(p.qtd_produzida), Number(p.qtd_pedida)), 1],
            [fmtInt.format(p.pedidos), 1],
            [fmtMoeda.format(p.valor_pedido), 1],
            [fmtMoeda.format(p.valor_falta), 1],
        ]),
        [['Total'], ['', 1], [fmtInt.format(r.itens_pedidos), 1], [fmtInt.format(r.itens_produzidos), 1],
         [fmtInt.format(r.itens_falta), 1], ['', 1], ['', 1],
         [fmtMoeda.format(r.valor_pedido), 1], [fmtMoeda.format(r.valor_falta), 1]]
    );
}

// ---------- Por cliente ----------
function renderClientes(lista) {
    $('painelClientes').innerHTML = tabela(
        [['Cliente'], ['Cidade/UF'], ['Pedidos', 1], ['Qtd. pedida', 1], ['Falta', 1], ['Valor pedido', 1]],
        lista.map(c => [
            [`<strong>${esc(c.cliente)}</strong>`],
            [c.cidade ? esc(c.cidade) + (c.estado ? '/' + esc(c.estado) : '') : '—'],
            [fmtInt.format(c.pedidos), 1],
            [fmtInt.format(c.qtd_pedida), 1],
            [Number(c.qtd_falta) ? fmtInt.format(c.qtd_falta) : '✓', 1],
            [fmtMoeda.format(c.valor_pedido), 1],
        ])
    );
}

// ---------- Produção registrada ----------
function renderProducao(d) {
    const maxDia = Math.max(1, ...d.producao_por_dia.map(x => Number(x.pecas)));
    $('painelProducaoDia').innerHTML = tabela(
        [['Data'], ['Unidades', 1], ['Lotes', 1], ['']],
        d.producao_por_dia.map(x => [
            [data(x.data)], [fmtInt.format(x.pecas), 1], [fmtInt.format(x.lotes), 1],
            [`<div class="barra-dia" style="width:${Math.round((x.pecas / maxDia) * 100)}%"></div>`],
        ]),
        null, 'Nenhum lote lançado para estes pedidos.'
    );
    $('painelProducaoUsuario').innerHTML = tabela(
        [['Usuário'], ['Unidades', 1], ['Lotes', 1]],
        d.producao_por_usuario.map(x => [[esc(x.usuario)], [fmtInt.format(x.pecas), 1], [fmtInt.format(x.lotes), 1]]),
        null, 'Nenhum lote lançado para estes pedidos.'
    );
}

// ============================================================
// Abas
// ============================================================
function mostrarAba(aba) {
    abaAtual = aba;
    document.querySelectorAll('.aba').forEach(b => b.setAttribute('aria-selected', String(b.dataset.aba === aba)));
    document.querySelectorAll('.secao-rel').forEach(s => { s.hidden = s.dataset.painel !== aba; });
}

// ============================================================
// CSV — uma linha por item de pedido, que é o grão que o Excel precisa
// ============================================================
const csvCampo = v => {
    const s = String(v ?? '');
    return /[";\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
};
const numBR = v => String(v ?? '').replace('.', ',');

function exportarCsv() {
    if (!ultimo) return;
    const d = ultimo;
    const L = [];

    L.push(['Relatório de pedidos', `${data(d.periodo.de)} a ${data(d.periodo.ate)}`,
            `período por ${d.periodo.base === 'entrega' ? 'data de entrega' : 'data do pedido'}`]);
    L.push([]);
    L.push(['ITENS DE PEDIDO']);
    L.push(['Pedido', 'Origem', 'Cliente', 'Cidade', 'UF', 'Data pedido', 'Entrega', 'Dias', 'Situação',
            'Produto', 'Qtd pedida', 'Qtd produzida', 'Qtd do estoque', 'Falta',
            'Preço unit. (R$)', 'Valor do item (R$)', 'Valor a faturar (R$)']);
    d.por_pedido.forEach(p => (p.itens || []).forEach(i => L.push([
        p.id,
        p.tipo === 'estoque' ? 'Estoque' : 'Cliente',
        p.tipo === 'estoque' ? '' : (p.cliente_nome || ''),
        p.cliente_cidade || '', p.cliente_estado || '',
        data(p.data_pedido), data(p.data_entrega_prometida), p.dias_para_entrega,
        STATUS[p.status] || p.status,
        i.produto_nome, i.qtd_pedida, i.qtd_produzida, i.qtd_do_estoque, i.qtd_falta,
        numBR(num(i.preco, 2)), numBR(num(i.valor_item, 2)), numBR(num(i.valor_falta, 2)),
    ])));

    L.push([]);
    L.push(['POR PRODUTO']);
    L.push(['Produto', 'Preço praticado (R$)', 'Preço tabela hoje (R$)', 'Qtd pedida', 'Qtd produzida', 'Falta', 'Pedidos',
            'Estoque pronto', 'Montável agora', 'Valor pedido (R$)', 'Valor a faturar (R$)']);
    d.por_produto.forEach(p => L.push([
        p.nome, numBR(num(p.preco, 2)), numBR(num(p.preco_tabela, 2)), p.qtd_pedida, p.qtd_produzida, p.qtd_falta, p.pedidos,
        p.estoque_pronto, p.montavel ?? '', numBR(num(p.valor_pedido, 2)), numBR(num(p.valor_falta, 2)),
    ]));

    L.push([]);
    L.push(['AGENDA DE ENTREGAS']);
    L.push(['Janela', 'Entrega', 'Dias', 'Pedido', 'Cliente', 'Situação', 'Falta', 'Valor a faturar (R$)']);
    d.agenda.forEach(a => L.push([
        a.janela, data(a.data_entrega_prometida), a.dias, a.id, a.cliente,
        STATUS[a.status] || a.status, a.itens_falta, numBR(num(a.valor_falta, 2)),
    ]));

    L.push([]);
    L.push(['POR CLIENTE']);
    L.push(['Cliente', 'Cidade', 'UF', 'Pedidos', 'Qtd pedida', 'Falta', 'Valor pedido (R$)']);
    d.por_cliente.forEach(c => L.push([
        c.cliente, c.cidade || '', c.estado || '', c.pedidos, c.qtd_pedida, c.qtd_falta, numBR(num(c.valor_pedido, 2)),
    ]));

    const csv = '﻿' + L.map(l => l.map(csvCampo).join(';')).join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `relatorio_${d.periodo.de}_a_${d.periodo.ate}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

// ============================================================
// Eventos
// ============================================================
$('btnGerar').addEventListener('click', gerar);
$('btnCsv').addEventListener('click', exportarCsv);
$('btnImprimir').addEventListener('click', () => window.print());
['relBase', 'relStatus', 'relTipo', 'relProduto', 'relCliente'].forEach(id =>
    $(id).addEventListener('change', gerar));
document.querySelectorAll('[data-periodo]').forEach(b =>
    b.addEventListener('click', () => { definirPeriodo(b.dataset.periodo); gerar(); }));
document.querySelectorAll('.aba').forEach(b =>
    b.addEventListener('click', () => mostrarAba(b.dataset.aba)));

// Eventos do Planejador de Eventos
$('btnAdicionarItemEvento')?.addEventListener('click', adicionarLinhaEvento);
$('btnCalcularEvento')?.addEventListener('click', calcularPlanejamentoEvento);
window.atualizarItemEvento = atualizarItemEvento;
window.removerLinhaEvento = removerLinhaEvento;

(async function init() {
    try {
        const prods = await App.api('api/produtos.php?todos=1');
        listaProdutosGeral = (prods || []).filter(p => Number(p.ativo) === 1);
        renderLinhasEventoSimulador();
    } catch (_) {}

    await Promise.all([
        popular('api/produtos.php?todos=1', $('relProduto'), p => [p.id, p.nome + (Number(p.ativo) ? '' : ' (inativo)')]),
        popular('api/clientes.php', $('relCliente'), c => [c.id, c.nome]),
    ]);
    definirPeriodo('mes');
    mostrarAba('entregas');
    gerar();
})();
