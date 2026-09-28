// Painel Gerencial & Tomada de Decisão
let dadosDash = null;
let custosProdutoAtualId = null;

const $ = id => document.getElementById(id);
const esc = App.esc;

async function carregarDashboard() {
    const btn = $('btnAtualizarDash');
    if (btn) btn.disabled = true;

    try {
        dadosDash = await App.api('api/dashboard.php');
        renderizarKPIs();
        renderizarBarraPedidos();
        renderizarProdutosProntos();
        renderizarFilamentos();
        renderizarPedidosCriticos();

        const agora = new Date();
        const horaFmt = agora.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        if ($('dashDataAtualizacao')) $('dashDataAtualizacao').textContent = `Atualizado às ${horaFmt}`;
    } catch (e) {
        App.toast('Erro ao carregar dados do dashboard: ' + e.message, 'erro');
    } finally {
        if (btn) btn.disabled = false;
    }
}

// -------------------------------------------------------------
// 1. Renderização dos Grandes KPI Cards
// -------------------------------------------------------------
function renderizarKPIs() {
    if (!dadosDash || !dadosDash.kpis) return;
    const k = dadosDash.kpis;

    // KPI 1: Produtos Prontos
    $('kpiProdutosUnidades').innerHTML = `${App.fmtInt.format(k.produtos_prontos_unidades)} <small>un.</small>`;
    $('kpiProdutosModelos').textContent = `${k.produtos_prontos_modelos} modelos em pronta-entrega (de ${k.produtos_total_catalogo} no catálogo)`;
    $('kpiProdutosValorVenda').textContent = App.moeda(k.produtos_valor_venda_estoque);
    $('kpiProdutosValorCusto').textContent = App.moeda(k.produtos_valor_custo_estoque);

    // KPI 2: Filamentos
    $('kpiFilamentosPeso').innerHTML = `${Number(k.filamentos_total_kg).toFixed(1).replace('.', ',')} <small>kg</small>`;
    $('kpiFilamentosQtd').textContent = `${k.filamentos_total_cadastrados} filamentos cadastrados (~${Number(k.filamentos_total_rolos).toFixed(1)} rolos)`;
    $('kpiFilamentosCustoMedioKg').textContent = `${App.moeda(k.filamentos_custo_medio_kg)} / kg`;
    $('kpiFilamentosValorTotal').textContent = App.moeda(k.filamentos_valor_total);

    const alertasTag = $('kpiFilamentosAlertasTag');
    if (alertasTag) {
        if (k.filamentos_alertas > 0) {
            alertasTag.textContent = `⚠️ ${k.filamentos_alertas} em alerta`;
            alertasTag.className = 'kpi-tag-status perigo';
        } else {
            alertasTag.textContent = '🟢 Estoque OK';
            alertasTag.className = 'kpi-tag-status sucesso';
        }
    }

    // KPI 3: Pedidos
    $('kpiPedidosTotal').innerHTML = `${App.fmtInt.format(k.pedidos_total)} <small>pedidos</small>`;
    $('kpiPedidosStatusBreakdown').textContent = `${k.pedidos_em_aberto} abertos · ${k.pedidos_em_producao} em produção · ${k.pedidos_prontos} prontos`;
    $('kpiPedidosValorCarteira').textContent = App.moeda(k.pedidos_valor_em_carteira);
    $('kpiPedidosValorEntregue').textContent = App.moeda(k.pedidos_valor_entregue);

    const ativosTag = $('kpiPedidosAbertosProducaoTag');
    if (ativosTag) {
        const totalAtivos = k.pedidos_em_aberto + k.pedidos_em_producao + k.pedidos_prontos;
        ativosTag.textContent = `${totalAtivos} em andamento`;
    }

    // KPI 4: Atenção Imediata & Prazos
    const totalCriticos = (k.pedidos_atrasados || 0) + (k.pedidos_urgentes || 0);
    $('kpiPedidosUrgentes').innerHTML = `${totalCriticos} <small>${totalCriticos === 1 ? 'pedido' : 'pedidos'}</small>`;
    $('kpiPedidosUrgentesTexto').textContent = `${k.pedidos_atrasados} atrasado(s) · ${k.pedidos_urgentes} entrega(s) em 3 dias`;
    $('kpiTicketMedio').textContent = App.moeda(k.pedidos_ticket_medio);
    $('kpiLucroPotencial').textContent = `+ ${App.moeda(k.produtos_lucro_potencial)}`;

    const tagAtrasados = $('kpiPedidosAtrasadosTag');
    if (tagAtrasados) {
        if (k.pedidos_atrasados > 0) {
            tagAtrasados.textContent = `🚨 ${k.pedidos_atrasados} atrasados`;
            tagAtrasados.className = 'kpi-tag-status perigo';
        } else {
            tagAtrasados.textContent = '⏱️ Prazos em dia';
            tagAtrasados.className = 'kpi-tag-status sucesso';
        }
    }
}

// -------------------------------------------------------------
// 2. Barra de Distribuição Visual dos Pedidos
// -------------------------------------------------------------
function renderizarBarraPedidos() {
    if (!dadosDash || !dadosDash.kpis) return;
    const k = dadosDash.kpis;
    const total = k.pedidos_total || 1;

    const pAberto = Math.round((k.pedidos_em_aberto / total) * 100);
    const pProd = Math.round((k.pedidos_em_producao / total) * 100);
    const pPronto = Math.round((k.pedidos_prontos / total) * 100);
    const pEntregue = Math.round((k.pedidos_entregues / total) * 100);
    const pCancelado = Math.round((k.pedidos_cancelados / total) * 100);

    $('dashResumoGeralPedidos').textContent = `${k.pedidos_total} pedidos registrados na história do sistema.`;

    $('dashLegendaPedidos').innerHTML = `
        <span style="display:flex;align-items:center;gap:4px;"><span class="dash-dot" style="background:#f59e0b;"></span> Abertos: <b>${k.pedidos_em_aberto}</b></span>
        <span style="display:flex;align-items:center;gap:4px;"><span class="dash-dot" style="background:#3b82f6;"></span> Em Produção: <b>${k.pedidos_em_producao}</b></span>
        <span style="display:flex;align-items:center;gap:4px;"><span class="dash-dot" style="background:#10b981;"></span> Prontos: <b>${k.pedidos_prontos}</b></span>
        <span style="display:flex;align-items:center;gap:4px;"><span class="dash-dot" style="background:#64748b;"></span> Entregues: <b>${k.pedidos_entregues}</b></span>
        ${k.pedidos_cancelados > 0 ? `<span style="display:flex;align-items:center;gap:4px;"><span class="dash-dot" style="background:#ef4444;"></span> Cancelados: <b>${k.pedidos_cancelados}</b></span>` : ''}
    `;

    $('dashTrilhoPedidos').innerHTML = `
        <div class="dash-segmento" style="width:${pAberto}%;background:#f59e0b;" title="Abertos: ${k.pedidos_em_aberto} (${pAberto}%)"></div>
        <div class="dash-segmento" style="width:${pProd}%;background:#3b82f6;" title="Em Produção: ${k.pedidos_em_producao} (${pProd}%)"></div>
        <div class="dash-segmento" style="width:${pPronto}%;background:#10b981;" title="Prontos: ${k.pedidos_prontos} (${pPronto}%)"></div>
        <div class="dash-segmento" style="width:${pEntregue}%;background:#64748b;" title="Entregues: ${k.pedidos_entregues} (${pEntregue}%)"></div>
        <div class="dash-segmento" style="width:${pCancelado}%;background:#ef4444;" title="Cancelados: ${k.pedidos_cancelados} (${pCancelado}%)"></div>
    `;
}

// -------------------------------------------------------------
// 3. Tabela de Produtos Prontos
// -------------------------------------------------------------
function renderizarProdutosProntos() {
    if (!dadosDash) return;
    const termo = App.normalizar($('buscaProdutosProntos')?.value || '').trim();
    const modo = $('filtroModoEstoque')?.value || 'apenas_prontos';

    let lista = (dadosDash.todos_produtos || []).slice();

    if (modo === 'apenas_prontos') {
        lista = lista.filter(p => Number(p.estoque) > 0);
    } else if (modo === 'zerados') {
        lista = lista.filter(p => Number(p.estoque) <= 0);
    }

    if (termo) {
        lista = lista.filter(p => App.normalizar(`${p.nome} ${p.descricao || ''} ${p.tipo || ''}`).includes(termo));
    }

    const totalUnidades = lista.reduce((sum, p) => sum + (Number(p.estoque) || 0), 0);
    const totalValorVenda = lista.reduce((sum, p) => sum + ((Number(p.estoque) || 0) * (Number(p.preco) || 0)), 0);
    const totalValorCusto = lista.reduce((sum, p) => sum + ((Number(p.estoque) || 0) * (Number(p.custo_total) || 0)), 0);

    $('rodapeProdutosProntos').textContent = `${lista.length} produtos listados · Total de ${App.fmtInt.format(totalUnidades)} unidades prontas · Patrimônio em estoque: ${App.moeda(totalValorVenda)} a preço de venda (${App.moeda(totalValorCusto)} custo base)`;

    if (!lista.length) {
        $('tabelaProdutosProntos').innerHTML = App.estadoVazio('📦',
            modo === 'apenas_prontos' ? 'Nenhum produto com estoque pronto no momento' : 'Nenhum produto encontrado',
            'Alterne os filtros acima ou cadastre produção para abastecer o estoque.', 9);
        return;
    }

    $('tabelaProdutosProntos').innerHTML = lista.map(p => {
        const est = Number(p.estoque) || 0;
        const preco = Number(p.preco) || 0;
        const custo = Number(p.custo_total) || 0;
        const demanda = Number(p.demanda_aberta) || 0;
        const margemPct = custo > 0 ? Math.round(((preco - custo) / custo) * 100) : (preco > 0 ? 100 : 0);
        const tipoBadge = p.tipo === 'composto' ? '<span class="badge-tipo composto">Composto</span>' : (p.tipo === 'componente' ? '<span class="badge-tipo componente">Peça</span>' : '<span class="badge-tipo simples">Simples</span>');

        return `
        <tr>
            <td>
                <strong>${esc(p.nome)}</strong>
                ${p.descricao ? `<span class="sub-linha descricao-curta" title="${esc(p.descricao)}">${esc(p.descricao)}</span>` : ''}
            </td>
            <td>${tipoBadge}</td>
            <td class="num">
                <span class="dash-badge-estoque ${est > 0 ? 'pronto' : 'zerado'}">
                    ${App.fmtInt.format(est)} un
                </span>
            </td>
            <td class="num">${App.moeda(custo)}</td>
            <td class="num" style="font-weight:700;">${App.moeda(preco)}</td>
            <td class="num">
                <span style="color:${margemPct >= 100 ? 'var(--success)' : 'var(--text)'}; font-weight:600;">
                    ${margemPct}% (+ ${App.moeda(preco - custo)})
                </span>
            </td>
            <td class="num" style="font-weight:700; color:var(--primary);">
                ${App.moeda(est * preco)}
            </td>
            <td class="num">
                ${demanda > 0
                    ? `<span class="dash-badge-demanda alerta" title="Pedidos em aberto aguardando este produto">${demanda} un pedidas</span>`
                    : '<span style="color:var(--text-3); font-size:11px;">Sem fila</span>'}
            </td>
            <td class="num col-acoes">
                <div class="acoes-icones">
                    ${App.botaoIcone('custos', 'Visualizar e recalcular custos deste produto', `abrirCustosProduto(${p.id})`, 'sucesso')}
                </div>
            </td>
        </tr>`;
    }).join('');
}

// -------------------------------------------------------------
// 4. Tabela de Filamentos Cadastrados & Custo Médio
// -------------------------------------------------------------
function renderizarFilamentos() {
    if (!dadosDash || !dadosDash.filamentos) return;
    const lista = dadosDash.filamentos;

    const totalKg = lista.reduce((acc, f) => acc + (Number(f.estoque_gramas) || 0), 0) / 1000;
    const totalVal = lista.reduce((acc, f) => acc + (Number(f.valor_total_estoque) || 0), 0);
    const custoMedioGeral = totalKg > 0 ? (totalVal / totalKg) : 0;

    $('rodapeFilamentosDash').textContent = `${lista.length} filamentos cadastrados · ${totalKg.toFixed(1)} kg em estoque · Custo Médio Ponderado: ${App.moeda(custoMedioGeral)}/kg`;

    if (!lista.length) {
        $('tabelaFilamentosDash').innerHTML = App.estadoVazio('🧵', 'Nenhum filamento cadastrado', 'Cadastre filamentos para rastrear estoques e custos.', 6);
        return;
    }

    $('tabelaFilamentosDash').innerHTML = lista.map(f => {
        const estG = Number(f.estoque_gramas) || 0;
        const rolos = Number(f.estoque_rolos) || 0;
        const hex = f.cor_hex || '#94a3b8';
        const precoKg = Number(f.custo_medio_kg || f.custo_peps_kg || 0);

        let statusHtml = '<span class="tag-status sucesso">Normal</span>';
        if (f.status === 'zerado' || estG <= 0) {
            statusHtml = '<span class="tag-status perigo">Zerado</span>';
        } else if (f.status === 'baixo') {
            statusHtml = '<span class="tag-status alerta">Baixo</span>';
        }

        return `
        <tr>
            <td>
                <div style="display:flex; align-items:center; gap:8px;">
                    <span style="display:inline-block; width:13px; height:13px; border-radius:50%; background:${hex}; border:1px solid rgba(0,0,0,0.18); flex-shrink:0;"></span>
                    <strong>${esc(f.cor || f.nome)}</strong>
                </div>
            </td>
            <td>
                <span>${esc(f.marca || 'Padrão')}</span>
                <span class="sub-linha" style="font-size:11px;">${esc(f.tipo || 'PLA')}</span>
            </td>
            <td class="num">
                <strong>${App.fmtInt.format(estG)} g</strong>
                <span class="sub-linha" style="font-size:11px;">~${rolos.toFixed(1)} rolos</span>
            </td>
            <td class="num" style="font-weight:600;">${App.moeda(precoKg)}</td>
            <td class="num" style="font-weight:700; color:var(--text);">${App.moeda(f.valor_total_estoque || 0)}</td>
            <td>${statusHtml}</td>
        </tr>`;
    }).join('');
}

// -------------------------------------------------------------
// 5. Tabela de Pedidos Críticos & Prazos
// -------------------------------------------------------------
function renderizarPedidosCriticos() {
    if (!dadosDash) return;
    const lista = dadosDash.pedidos_criticos || [];

    $('rodapePedidosCriticos').textContent = lista.length
        ? `${lista.length} pedidos requerem atenção imediata da gestão`
        : 'Todos os pedidos estão dentro do prazo normal';

    if (!lista.length) {
        $('tabelaPedidosCriticos').innerHTML = `
            <tr>
                <td colspan="5" style="text-align:center; padding: 30px; color: var(--text-3);">
                    🎉 <strong>Nenhum pedido atrasado ou em alerta!</strong><br>
                    <small>Todas as entregas estão confortavelmente dentro do prazo.</small>
                </td>
            </tr>
        `;
        return;
    }

    $('tabelaPedidosCriticos').innerHTML = lista.map(p => {
        const atrasado = p.alerta_prazo === 'atrasado';
        const dias = p.dias_prazo;
        let prazoTxt = '';
        if (dias < 0) {
            prazoTxt = `<span style="color:var(--danger); font-weight:700;">Atrasado (${Math.abs(dias)}d)</span>`;
        } else if (dias === 0) {
            prazoTxt = `<span style="color:var(--warning); font-weight:700;">Entrega HOJE</span>`;
        } else {
            prazoTxt = `<span style="color:var(--warning); font-weight:600;">Em ${dias} dias</span>`;
        }

        return `
        <tr>
            <td>
                <a href="pedidos.php?editar=${p.id}" style="font-weight:700; color:var(--primary); text-decoration:none;">
                    #${p.id}
                </a>
            </td>
            <td>${esc(p.cliente_nome || 'Sem cliente')}</td>
            <td>${prazoTxt}</td>
            <td class="num" style="font-weight:700;">${App.moeda(p.valor_total || 0)}</td>
            <td>
                <span class="tag-status ${atrasado ? 'perigo' : 'alerta'}">
                    ${esc(p.status)}
                </span>
            </td>
        </tr>`;
    }).join('');
}

// -------------------------------------------------------------
// 6. Modal de Custos Integrado no Dashboard
// -------------------------------------------------------------
async function abrirCustosProduto(id) {
    custosProdutoAtualId = id;
    try {
        const detalhe = await App.api(`api/produtos.php?id=${id}`);
        if (!detalhe) return;

        if ($('custosProdNomeDestaque')) {
            const rotuloTipo = detalhe.tipo === 'composto' ? '🧩 Composto' : (detalhe.tipo === 'componente' ? '⚙️ Peça Avulsa' : '🔹 Simples');
            $('custosProdNomeDestaque').textContent = `${detalhe.nome} · ${rotuloTipo}`;
        }
        if ($('custosSubModal')) {
            $('custosSubModal').textContent = `Precificação, parâmetros de custo e formação de preço de venda de ${detalhe.nome}.`;
        }

        const peso = Number(detalhe.peso_gramas) || 0;
        if ($('custosProdPeso')) $('custosProdPeso').value = peso > 0 ? peso : '';
        
        const bloqueadoSoma = (detalhe.tem_pecas && detalhe.pecas && detalhe.pecas.length > 0) || (detalhe.cores && detalhe.cores.length > 0);
        if ($('custosProdPeso')) {
            $('custosProdPeso').readOnly = bloqueadoSoma;
            if (bloqueadoSoma) {
                $('custosProdPeso').classList.add('input-bloqueado-soma');
            } else {
                $('custosProdPeso').classList.remove('input-bloqueado-soma');
            }
        }
        if ($('custosTagOrigemPeso')) {
            if (bloqueadoSoma) {
                $('custosTagOrigemPeso').textContent = detalhe.tem_pecas ? '🔒 Soma das peças' : '🔒 Soma das cores';
                $('custosTagOrigemPeso').style.display = 'inline-block';
            } else {
                $('custosTagOrigemPeso').style.display = 'none';
            }
        }
        if ($('custosDicaProdPeso')) {
            $('custosDicaProdPeso').textContent = bloqueadoSoma ? 'Calculado automaticamente pela soma das peças/cores.' : 'Filamento total estimado para 1 unidade deste produto.';
        }

        if ($('custosProdCustoFilamento')) $('custosProdCustoFilamento').value = detalhe.custo_filamento ? Number(detalhe.custo_filamento).toFixed(2) : '';
        if ($('custosProdTemEmbalagem')) $('custosProdTemEmbalagem').value = Number(detalhe.tem_embalagem) === 1 ? '1' : '0';
        if ($('custosProdValorEmbalagem')) $('custosProdValorEmbalagem').value = detalhe.valor_embalagem ? Number(detalhe.valor_embalagem).toFixed(2) : '0.00';
        if ($('custosBoxValorEmbalagem')) $('custosBoxValorEmbalagem').style.display = Number(detalhe.tem_embalagem) === 1 ? 'block' : 'none';
        if ($('custosProdValorOutros')) $('custosProdValorOutros').value = detalhe.valor_outros ? Number(detalhe.valor_outros).toFixed(2) : '0.00';
        if ($('custosProdMargemLucro')) $('custosProdMargemLucro').value = (detalhe.margem_lucro !== undefined && detalhe.margem_lucro !== null) ? Number(detalhe.margem_lucro) : 100;
        if ($('custosProdPreco')) $('custosProdPreco').value = detalhe.preco ? Number(detalhe.preco).toFixed(2) : '';

        const cFil = detalhe.calculo_custo_filamento;
        if (cFil && cFil.cores && cFil.cores.length > 0) {
            if ($('custosTagOrigem')) $('custosTagOrigem').textContent = `PEPS ativo (${cFil.cores.length} cor/cores)`;
            if ($('custosTabelaCorPeps')) {
                $('custosTabelaCorPeps').innerHTML = cFil.cores.map(c => `
                    <tr>
                        <td><strong>${esc(c.cor || 'Padrão')}</strong></td>
                        <td class="num">${Number(c.gramas).toFixed(1)}g</td>
                        <td>${esc(c.filamento_nome || 'Estimado')}</td>
                        <td class="num">${App.moeda(c.preco_por_kg || 0)}/kg</td>
                        <td class="num" style="font-weight:700;">${App.moeda(c.custo_total || 0)}</td>
                    </tr>
                `).join('');
            }
            if ($('custosBoxDetalhamentoPeps')) $('custosBoxDetalhamentoPeps').style.display = 'block';

            if (!detalhe.custo_filamento || Number(detalhe.custo_filamento) === 0) {
                if ($('custosProdCustoFilamento')) $('custosProdCustoFilamento').value = Number(cFil.custo_filamento).toFixed(2);
            }
        } else {
            if ($('custosTagOrigem')) $('custosTagOrigem').textContent = 'Calculadora dinâmica';
            if ($('custosBoxDetalhamentoPeps')) $('custosBoxDetalhamentoPeps').style.display = 'none';
        }

        recalcularCustosModal(false);
        App.modal.abrir('modalCustosProduto', '#custosProdPreco');
    } catch (e) {
        App.toast('Erro ao abrir custos do produto: ' + e.message, 'erro');
    }
}

function recalcularCustosModal(forcarAtualizarPreco = true) {
    const peso = parseFloat($('custosProdPeso')?.value) || 0;
    let custoFil = parseFloat($('custosProdCustoFilamento')?.value);

    if ((isNaN(custoFil) || custoFil === 0) && peso > 0) {
        custoFil = round2(peso * 0.0900);
        if ($('custosProdCustoFilamento')) $('custosProdCustoFilamento').value = custoFil.toFixed(2);
    } else if (isNaN(custoFil) || custoFil < 0) {
        custoFil = 0;
    }

    const temEmb = $('custosProdTemEmbalagem')?.value === '1';
    if ($('custosBoxValorEmbalagem')) {
        $('custosBoxValorEmbalagem').style.display = temEmb ? 'block' : 'none';
    }
    const valorEmb = temEmb ? (parseFloat($('custosProdValorEmbalagem')?.value) || 0) : 0;
    const valorOutros = parseFloat($('custosProdValorOutros')?.value) || 0;
    const margem = parseFloat($('custosProdMargemLucro')?.value) || 0;

    const custoTotal = round2(custoFil + valorEmb + valorOutros);
    const valorMargem = round2(custoTotal * (margem / 100));
    const precoSugerido = round2(custoTotal + valorMargem);

    if ($('custosResumoFilamento')) $('custosResumoFilamento').textContent = App.moeda(custoFil);
    if ($('custosResumoEmbalagem')) $('custosResumoEmbalagem').textContent = App.moeda(valorEmb);
    if ($('custosResumoOutros')) $('custosResumoOutros').textContent = App.moeda(valorOutros);
    if ($('custosResumoCustoTotal')) $('custosResumoCustoTotal').textContent = App.moeda(custoTotal);
    if ($('custosResumoValorMargem')) $('custosResumoValorMargem').textContent = '+ ' + App.moeda(valorMargem);

    if (forcarAtualizarPreco && $('custosProdPreco')) {
        $('custosProdPreco').value = precoSugerido > 0 ? precoSugerido.toFixed(2) : '0.00';
    }
}

function definirMargemCustos(valor) {
    if ($('custosProdMargemLucro')) {
        $('custosProdMargemLucro').value = valor;
        recalcularCustosModal(true);
    }
}

async function salvarCustosModal() {
    if (!custosProdutoAtualId) return;
    const preco = parseFloat($('custosProdPreco')?.value);
    if (isNaN(preco) || preco < 0) {
        App.toast('Informe um preço de venda válido.', 'erro');
        $('custosProdPreco')?.focus();
        return;
    }

    const temEmb = $('custosProdTemEmbalagem')?.value === '1';
    const payload = {
        custo_filamento: parseFloat($('custosProdCustoFilamento')?.value) || 0,
        tem_embalagem: temEmb ? 1 : 0,
        valor_embalagem: temEmb ? (parseFloat($('custosProdValorEmbalagem')?.value) || 0) : 0,
        valor_outros: parseFloat($('custosProdValorOutros')?.value) || 0,
        margem_lucro: parseFloat($('custosProdMargemLucro')?.value) || 0,
        preco: preco,
        peso_gramas: parseFloat($('custosProdPeso')?.value) || 0
    };

    const btn = $('btnSalvarCustosModal');
    if (btn) btn.disabled = true;

    try {
        await App.api(`api/produtos.php?id=${custosProdutoAtualId}&apenas_custos=1`, 'PUT', payload);
        App.toast('Precificação atualizada com sucesso!', 'sucesso');
        App.modal.fechar('modalCustosProduto');
        carregarDashboard();
    } catch (e) {
        App.toast('Erro ao salvar precificação: ' + e.message, 'erro');
    } finally {
        if (btn) btn.disabled = false;
    }
}

function round2(num) {
    return Math.round((Number(num) + Number.EPSILON) * 100) / 100;
}

// -------------------------------------------------------------
// Inicialização de Listeners e Carga
// -------------------------------------------------------------
$('btnAtualizarDash')?.addEventListener('click', carregarDashboard);
$('buscaProdutosProntos')?.addEventListener('input', renderizarProdutosProntos);
$('filtroModoEstoque')?.addEventListener('change', renderizarProdutosProntos);

$('custosProdPeso')?.addEventListener('input', () => recalcularCustosModal(true));
$('custosProdCustoFilamento')?.addEventListener('input', () => recalcularCustosModal(true));
$('custosProdTemEmbalagem')?.addEventListener('change', () => recalcularCustosModal(true));
$('custosProdValorEmbalagem')?.addEventListener('input', () => recalcularCustosModal(true));
$('custosProdValorOutros')?.addEventListener('input', () => recalcularCustosModal(true));
$('custosProdMargemLucro')?.addEventListener('input', () => recalcularCustosModal(true));
$('btnCustosRecalcular')?.addEventListener('click', () => recalcularCustosModal(true));
$('btnSalvarCustosModal')?.addEventListener('click', salvarCustosModal);

window.abrirCustosProduto = abrirCustosProduto;
window.definirMargemCustos = definirMargemCustos;

carregarDashboard();
