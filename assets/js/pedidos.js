// Módulo Pedidos: lista + formulário em modal (FormPedido) + modal de detalhes
let pedidos = [];

const STATUS = { aberto: 'Aberto', em_producao: 'Em produção', pronto: 'Pronto', entregue: 'Entregue', cancelado: 'Cancelado' };
const STATUS_ESTOQUE = { ...STATUS, aberto: 'Aberta', pronto: 'Pronta', entregue: 'Concluída', cancelado: 'Cancelada' };
const $ = id => document.getElementById(id);

async function carregar() {
    const params = new URLSearchParams();
    if ($('filtroTipo').value) params.set('tipo', $('filtroTipo').value);
    const st = $('filtroStatus').value;
    if (st && st !== 'pendentes') params.set('status', st);
    try {
        pedidos = await App.api('api/pedidos.php?' + params.toString());
    } catch (e) {
        App.toast(e.message, 'erro');
        pedidos = [];
    }
    if (st === 'pendentes') pedidos = pedidos.filter(p => !['entregue', 'cancelado'].includes(p.status));
    renderizar();
}

function acoes(p) {
    const estoque = p.tipo === 'estoque';
    const finalizado = p.status === 'entregue' || p.status === 'cancelado';
    const temProducao = p.itens.some(i => Number(i.quantidade_produzida) > 0);
    const b = [];

    // 1. Botão Ver Detalhes (sempre presente)
    b.push(`
        <button type="button" class="btn-acao detalhe" data-tip="Ver detalhes completos do pedido" aria-label="Detalhes do pedido" onclick="verDetalhes(${p.id})">
            ${App.icone('detalhe')}
        </button>
    `);

    // 2. Ações de Mudança de Status Rápido
    if (p.status === 'pronto') {
        const dica = estoque ? 'Concluir (enviar ao estoque)' : 'Marcar como entregue';
        b.push(`
            <button type="button" class="btn-acao ${estoque ? 'concluir' : 'entregar'}" data-tip="${dica}" aria-label="${dica}" onclick="mudarStatus(${p.id}, 'entregar')">
                ${App.icone(estoque ? 'concluir' : 'entregar')}
            </button>
        `);
    }

    if (finalizado) {
        b.push(`
            <button type="button" class="btn-acao reabrir" data-tip="Reabrir pedido" aria-label="Reabrir pedido" onclick="mudarStatus(${p.id}, 'reabrir')">
                ${App.icone('reabrir')}
            </button>
        `);
    } else {
        // 3. Botão Editar Pedido
        b.push(`
            <button type="button" class="btn-acao editar" data-tip="Editar pedido" aria-label="Editar pedido" onclick="editar(${p.id})">
                ${App.icone('editar')}
            </button>
        `);

        // 4. Botão Cancelar Pedido
        b.push(`
            <button type="button" class="btn-acao cancelar" data-tip="Cancelar pedido" aria-label="Cancelar pedido" onclick="mudarStatus(${p.id}, 'cancelar')">
                ${App.icone('cancelar')}
            </button>
        `);
    }

    // 5. Botão Excluir (apenas se não houver produção registrada)
    if (!temProducao) {
        b.push(`
            <button type="button" class="btn-acao excluir" data-tip="Excluir definitivamente" aria-label="Excluir pedido" onclick="excluir(${p.id})">
                ${App.icone('excluir')}
            </button>
        `);
    }

    return `<div class="acoes-pedidos">${b.join('')}</div>`;
}

function renderizarKpis() {
    const totalAbertos = pedidos.filter(p => p.status === 'aberto').length;
    const emProducao = pedidos.filter(p => p.status === 'em_producao').length;
    const prontos = pedidos.filter(p => p.status === 'pronto').length;
    const entregues = pedidos.filter(p => p.status === 'entregue').length;
    const atrasados = pedidos.filter(p => ['aberto', 'em_producao'].includes(p.status) && App.diasAte(p.data_entrega_prometida) < 0).length;

    const filtroAtual = $('filtroStatus').value;

    const cards = [
        { rotulo: 'Em Aberto', valor: totalAbertos, status: 'aberto', cls: 'kpi-aberto', icone: '⏳' },
        { rotulo: 'Em Produção', valor: emProducao, status: 'em_producao', cls: 'kpi-producao', icone: '⚡' },
        { rotulo: 'Prontos para Entrega', valor: prontos, status: 'pronto', cls: 'kpi-pronto', icone: '✨' },
        { rotulo: 'Atrasados', valor: atrasados, status: 'atrasados', cls: atrasados > 0 ? 'kpi-alerta' : '', icone: '🚨' },
        { rotulo: 'Entregues / Concluídos', valor: entregues, status: 'entregue', cls: 'kpi-entregue', icone: '📦' },
    ];

    $('pedidosResumo').innerHTML = cards.map(c => `
        <div class="kpi card-kpi-clicavel ${c.cls} ${filtroAtual === c.status ? 'kpi-ativo' : ''}" onclick="filtrarPorKpi('${c.status}')" role="button" tabindex="0" title="Clique para filtrar por ${c.rotulo}">
            <div class="kpi-topo">
                <span class="rotulo">${c.rotulo}</span>
                <span class="kpi-icone">${c.icone}</span>
            </div>
            <div class="valor">${c.valor}</div>
            <div class="kpi-sub">pedidos</div>
        </div>
    `).join('');
}

function filtrarPorKpi(status) {
    if (status === 'atrasados') {
        $('filtroStatus').value = 'pendentes';
        $('busca').value = '';
    } else {
        $('filtroStatus').value = $('filtroStatus').value === status ? '' : status;
    }
    carregar();
}

function renderizar() {
    renderizarKpis();

    const termo = App.normalizar($('busca').value.trim());
    const lista = pedidos.filter(p => !termo || App.normalizar(
        `#${p.id} ${p.id} ${p.cliente_nome || 'estoque'} ${p.cliente_cidade || ''} ${p.itens.map(i => i.produto_nome).join(' ')}`
    ).includes(termo));

    $('rodape').textContent = `${lista.length} de ${pedidos.length} registro${pedidos.length === 1 ? '' : 's'}`;

    if (!lista.length) {
        $('tabelaPedidos').innerHTML = App.estadoVazio('📋',
            pedidos.length ? 'Nenhum pedido encontrado' : 'Nenhum pedido por aqui',
            pedidos.length ? 'Tente outro termo de busca ou limpe os filtros.' : 'Clique em "Novo pedido" para registrar o primeiro.', 7);
        return;
    }

    $('tabelaPedidos').innerHTML = lista.map(p => {
        const estoque = p.tipo === 'estoque';
        const total = p.itens.reduce((s, i) => s + Number(i.quantidade), 0);
        const feito = p.itens.reduce((s, i) => s + Number(i.quantidade_produzida), 0);
        const pct = total ? Math.round(feito / total * 100) : 0;
        const pendente = p.status === 'aberto' || p.status === 'em_producao';
        const rotulo = (estoque ? STATUS_ESTOQUE : STATUS)[p.status] || p.status;
        const local = !estoque && p.cliente_cidade ? `${p.cliente_cidade}${p.cliente_estado ? '/' + p.cliente_estado : ''}` : '';
        
        // Avatar com iniciais
        const nomeCliente = estoque ? 'Estoque' : (p.cliente_nome || 'Cliente');
        const partesNome = nomeCliente.trim().split(/\s+/);
        const iniciais = (partesNome[0][0] + (partesNome.length > 1 ? partesNome[partesNome.length - 1][0] : '')).toUpperCase();

        const chipsItens = p.itens.map(i => `
            <span class="chip-item-pedido" onclick="verDetalhes(${p.id})" style="cursor:pointer;" title="${App.esc(i.produto_nome)}${i.cor_variacao ? ' (' + App.esc(i.cor_variacao) + ')' : ''}: ${i.quantidade_produzida || 0} de ${i.quantidade} produzidos (Clique para ver detalhes)">
                <strong class="chip-qtd">${i.quantidade}×</strong>
                <span class="chip-nome">${App.esc(i.produto_nome)}</span>
                ${i.cor_variacao ? `<span class="chip-cor-var" style="font-size:10.5px;padding:1px 5px;background:#eef2ff;color:#4f46e5;border-radius:4px;border:1px solid #c7d2fe;margin-left:4px;" title="Variação de Cor">${App.esc(i.cor_variacao)}</span>` : ''}
            </span>
        `).join('');

        return `
        <tr class="linha-pedido-tabela">
            <td class="col-pedido-id">
                <div class="id-e-tipo" onclick="verDetalhes(${p.id})" role="button" tabindex="0" title="Ver detalhes do pedido #${p.id}" style="cursor:pointer;">
                    <span class="pedido-id-destaque">#${p.id}</span>
                    <span class="tag-origem ${estoque ? 'origem-estoque' : 'origem-venda'}">${estoque ? 'Estoque' : 'Cliente'}</span>
                </div>
            </td>
            <td class="col-cliente">
                <div class="cliente-perfil-bloco" onclick="verDetalhes(${p.id})" style="cursor:pointer;" title="Clique para ver detalhes">
                    <div class="avatar-cliente ${estoque ? 'avatar-estoque' : ''}">${iniciais}</div>
                    <div class="cliente-textos">
                        <strong class="cliente-nome-principal">${estoque ? 'Produção para Estoque' : App.esc(p.cliente_nome)}</strong>
                        <span class="sub-linha">${local ? App.esc(local) + ' · ' : ''}criado em ${App.data(p.data_pedido)}</span>
                    </div>
                </div>
            </td>
            <td class="col-itens">
                <div class="grade-chips-itens">${chipsItens}</div>
            </td>
            <td class="col-entrega">
                <div class="entrega-info">
                    <span class="data-entrega-txt">${App.data(p.data_entrega_prometida)}</span>
                    ${pendente ? App.etiquetaPrazo(p.data_entrega_prometida) : ''}
                </div>
            </td>
            <td class="col-progresso">
                <div class="progresso-box-moderno" title="${feito} de ${total} un. produzidas (${pct}%)">
                    <div class="progresso-meta">
                        <span class="progresso-contagem"><strong>${feito}</strong>/${total} un</span>
                        <span class="progresso-porcento">${pct}%</span>
                    </div>
                    <div class="progresso-trilha">
                        <div class="progresso-barra-fill ${pct === 100 ? 'completa' : ''}" style="width:${pct}%"></div>
                    </div>
                    ${(p.tempo_futuro_formatado || p.peso_futuro_gramas > 0) && pendente ? `
                        <div class="progresso-estimativa" style="display:flex; justify-content:space-between; font-size:11px; color:var(--cor-texto-mutado, #64748b); margin-top:4px;" title="Estimativa futura de tempo e filamento para concluir o que falta">
                            <span>⏱️ ${p.tempo_futuro_formatado || '00:00:00'}</span>
                            <span>⚖️ ${(App.num ? App.num(p.peso_futuro_gramas || 0, 1) : Number(p.peso_futuro_gramas || 0).toFixed(1))}g</span>
                        </div>
                    ` : ''}
                </div>
            </td>
            <td class="col-status">
                <span class="badge badge-moderno ${App.esc(p.status)}">
                    <span class="badge-ponto"></span>
                    ${App.esc(rotulo)}
                </span>
            </td>
            <td class="col-acoes num">${acoes(p)}</td>
        </tr>`;
    }).join('');
}

async function verDetalhes(id) {
    let p;
    try {
        p = await App.api(`api/pedidos.php?id=${id}`);
    } catch(e) {
        App.toast(e.message, 'erro');
        return;
    }

    const estoque = p.tipo === 'estoque';
    const totalQtd = p.itens.reduce((s, i) => s + Number(i.quantidade), 0);
    const totalFeito = p.itens.reduce((s, i) => s + Number(i.quantidade_produzida || 0), 0);
    const pct = totalQtd ? Math.round(totalFeito / totalQtd * 100) : 0;
    const pendente = p.status === 'aberto' || p.status === 'em_producao';
    const finalizado = p.status === 'entregue' || p.status === 'cancelado';
    const temProducao = p.itens.some(i => Number(i.quantidade_produzida) > 0);

    // Cabeçalho
    $('detTitulo').textContent = estoque ? `Ordem de Estoque #${p.id}` : `Pedido #${p.id}`;
    $('detSub').textContent = estoque ? 'Ordem interna de produção para estoque' : (p.cliente_nome ? `Cliente: ${p.cliente_nome}` : '');

    $('detTipoTag').textContent = estoque ? 'Estoque' : 'Cliente';
    $('detTipoTag').className = `tag-origem ${estoque ? 'origem-estoque' : 'origem-venda'}`;

    const rotuloStatus = (estoque ? STATUS_ESTOQUE : STATUS)[p.status] || p.status;
    $('detStatusBadge').textContent = rotuloStatus;
    $('detStatusBadge').className = `badge badge-moderno ${p.status}`;

    // Bloco 1: Cliente / Origem
    if (estoque) {
        $('detRotuloClienteOrigem').textContent = 'Destino / Finalidade';
        $('detConteudoCliente').innerHTML = `
            <div style="font-size:14px;font-weight:600;color:var(--text);">Produção para Estoque Interno</div>
            <div style="font-size:12px;color:var(--text-3);margin-top:3px;">As peças fabricadas entrarão no saldo do estoque da oficina.</div>
        `;
    } else {
        $('detRotuloClienteOrigem').textContent = 'Cliente';
        const cidadeUf = p.cliente_cidade ? `${p.cliente_cidade}${p.cliente_estado ? '/' + p.cliente_estado : ''}` : '';
        $('detConteudoCliente').innerHTML = `
            <div style="font-size:15px;font-weight:700;color:var(--text);">${App.esc(p.cliente_nome || '—')}</div>
            <div style="display:flex;flex-direction:column;gap:3px;margin-top:6px;font-size:12.5px;color:var(--text-2);">
                ${p.cliente_telefone ? `<div>📞 <b>Telefone:</b> <a href="tel:${App.esc(p.cliente_telefone)}" style="color:var(--primary);text-decoration:none;">${App.esc(p.cliente_telefone)}</a></div>` : ''}
                ${p.cliente_email ? `<div>✉️ <b>E-mail:</b> <a href="mailto:${App.esc(p.cliente_email)}" style="color:var(--primary);text-decoration:none;">${App.esc(p.cliente_email)}</a></div>` : ''}
                ${cidadeUf ? `<div>📍 <b>Cidade:</b> ${App.esc(cidadeUf)}</div>` : ''}
                ${p.cliente_descricao ? `<div style="font-size:11.5px;color:var(--text-3);margin-top:2px;">Obs.: ${App.esc(p.cliente_descricao)}</div>` : ''}
            </div>
        `;
    }

    // Bloco 2: Cronograma & Registro
    $('detConteudoDatas').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:5px;font-size:12.5px;">
            <div>📅 <b>Criado em:</b> ${App.data(p.data_pedido)}</div>
            <div style="display:flex;align-items:center;gap:6px;">
                <span>🎯 <b>Entrega:</b> ${App.data(p.data_entrega_prometida)}</span>
                ${pendente ? App.etiquetaPrazo(p.data_entrega_prometida) : ''}
            </div>
            <div>👤 <b>Registrado por:</b> ${App.esc(p.usuario_nome || 'Sistema')}</div>
        </div>
    `;

    // Bloco 3: Progresso & Produção
    $('detConteudoProgresso').innerHTML = `
        <div style="display:flex;flex-direction:column;gap:6px;">
            <div style="display:flex;justify-content:space-between;font-size:12px;">
                <span>Progresso: <b>${totalFeito} / ${totalQtd} un</b></span>
                <strong style="color:var(--primary);">${pct}%</strong>
            </div>
            <div class="progresso-trilha" style="height:8px;">
                <div class="progresso-barra-fill ${pct === 100 ? 'completa' : ''}" style="width:${pct}%"></div>
            </div>
            <div style="display:flex;justify-content:space-between;font-size:11.5px;color:var(--text-3);margin-top:2px;">
                <span>⏱️ Restante: <b>${p.tempo_futuro_formatado || '00:00:00'}</b></span>
                <span>⚖️ Filamento: <b>${(Number(p.peso_futuro_gramas || 0)).toFixed(1)}g</b></span>
            </div>
        </div>
    `;

    // Observações
    if (p.observacoes && p.observacoes.trim()) {
        $('detBlocoObs').hidden = false;
        $('detObsTexto').textContent = p.observacoes.trim();
    } else {
        $('detBlocoObs').hidden = true;
    }

    // Tabela de Itens
    let valorTotal = 0;
    $('detTotalItensBadge').textContent = `${p.itens.length} produto${p.itens.length === 1 ? '' : 's'} no pedido`;

    $('detTabelaItens').innerHTML = p.itens.map(it => {
        const subtotal = Number(it.quantidade) * Number(it.preco_unitario || 0);
        valorTotal += subtotal;
        const qProd = Number(it.quantidade_produzida || 0);
        const qTotal = Number(it.quantidade);
        const itemPct = qTotal ? Math.round(qProd / qTotal * 100) : 0;

        let variacoesHtml = '';
        if (it.variacoes_json) {
            try {
                const varData = typeof it.variacoes_json === 'string' ? JSON.parse(it.variacoes_json) : it.variacoes_json;
                if (varData && varData.tipo === 'composto' && Array.isArray(varData.pecas)) {
                    variacoesHtml = `<div class="det-pecas-cores-grid">` + varData.pecas.map(pc => `
                        <span class="chip-peca-detalhe">
                            <span class="chip-peca-nome">🧩 ${App.esc(pc.peca_nome)}:</span>
                            <span class="chip-peca-cor-val">${App.esc(pc.cor || 'Padrão')}</span>
                        </span>
                    `).join('') + `</div>`;
                } else if (varData && varData.tipo === 'multicor_ams' && Array.isArray(varData.cores)) {
                    variacoesHtml = `<div style="font-size:11px;color:var(--text-3);margin-top:4px;">Multicor AMS: ` +
                        varData.cores.map(c => `${App.esc(c.cor)} (${c.gramas_1un}g)`).join(' + ') + `</div>`;
                }
            } catch(e) {}
        }
        if (!variacoesHtml && it.cor_variacao) {
            variacoesHtml = `<div class="chip-peca-detalhe" style="margin-top:4px;display:inline-flex;">
                <span class="chip-peca-nome">🎨 Cor:</span>
                <span class="chip-peca-cor-val">${App.esc(it.cor_variacao)}</span>
            </div>`;
        }

        return `
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:10px 14px;vertical-align:middle;">
                    <div style="font-weight:700;font-size:13.5px;color:var(--text);">${App.esc(it.produto_nome)}</div>
                    ${variacoesHtml}
                </td>
                <td style="text-align:center;padding:10px;vertical-align:middle;font-size:14px;font-weight:700;">
                    ${it.quantidade} un
                </td>
                <td style="text-align:center;padding:10px;vertical-align:middle;">
                    <span style="font-size:12px;font-weight:600;color:${itemPct === 100 ? 'var(--success, #16a34a)' : 'var(--text-2)'};">
                        ${qProd} / ${qTotal}
                    </span>
                    <div class="progresso-trilha" style="height:5px;width:70px;margin:3px auto 0;">
                        <div class="progresso-barra-fill ${itemPct === 100 ? 'completa' : ''}" style="width:${itemPct}%"></div>
                    </div>
                </td>
                <td style="text-align:right;padding:10px;vertical-align:middle;font-size:13px;color:var(--text-2);">
                    ${App.fmtMoeda.format(it.preco_unitario || 0)}
                </td>
                <td style="text-align:right;padding:10px 14px;vertical-align:middle;font-size:14px;font-weight:700;color:var(--text);">
                    ${App.fmtMoeda.format(subtotal)}
                </td>
            </tr>
        `;
    }).join('');

    // Resumo no rodapé do modal
    $('detTotalUnidades').textContent = `${App.fmtInt.format(totalQtd)} un`;
    $('detTempoFuturo').textContent = p.tempo_futuro_formatado || '00:00:00';
    $('detPesoFuturo').textContent = `${(Number(p.peso_futuro_gramas || 0)).toFixed(1)} g`;
    $('detValorTotal').textContent = App.fmtMoeda.format(valorTotal);

    // Distribuição de filamento por cor
    const coresDist = p.cores_futuro || p.cores_total || {};
    const entries = Object.entries(coresDist).filter(([, g]) => Number(g) > 0);
    if (entries.length) {
        $('detCoresDistribuicaoBloco').hidden = false;
        $('detCoresChips').innerHTML = entries.map(([c, g]) => `
            <span class="chip-cor-diag" style="font-size:11.5px;padding:3px 8px;background:#eef2ff;color:#3730a3;border:1px solid #c7d2fe;border-radius:6px;">
                🎨 ${App.esc(c)}: <b>${(Number(g)).toFixed(1)}g</b>
            </span>
        `).join('');
    } else {
        $('detCoresDistribuicaoBloco').hidden = true;
    }

    // Ações do rodapé
    $('detBtnEditar').onclick = () => {
        App.modal.fechar('modalDetalhesPedido');
        editar(p.id);
    };
    $('detBtnEditar').disabled = finalizado;

    if (!temProducao) {
        $('detBtnExcluir').hidden = false;
        $('detBtnExcluir').onclick = () => {
            App.modal.fechar('modalDetalhesPedido');
            excluir(p.id);
        };
    } else {
        $('detBtnExcluir').hidden = true;
    }

    $('detBtnImprimir').onclick = () => window.print();

    App.modal.abrir('modalDetalhesPedido');
}

function novo(clienteId = null) {
    FormPedido.abrir({ clienteId, aoSalvar: carregar });
}

function editar(id) {
    FormPedido.abrir({ id, aoSalvar: carregar });
}

async function mudarStatus(id, acao) {
    const p = pedidos.find(x => x.id === id);
    const estoque = p && p.tipo === 'estoque';
    const nome = estoque ? `a ordem de estoque #${id}` : `o pedido #${id}`;
    const textos = {
        entregar: [estoque ? 'Concluir ordem' : 'Marcar como entregue', estoque ? `Concluir ${nome}? As peças são consideradas enviadas ao estoque.` : `Confirmar a entrega do pedido #${id}?`, estoque ? 'Concluir' : 'Confirmar entrega', false],
        cancelar: ['Cancelar', `Cancelar ${nome}? A produção já registrada continua nos relatórios.`, 'Cancelar ' + (estoque ? 'ordem' : 'pedido'), true],
        reabrir: ['Reabrir', `Reabrir ${nome}? O status volta a refletir a produção.`, 'Reabrir', false],
    }[acao];
    if (!await App.confirmar(textos[1], { titulo: textos[0], botao: textos[2], perigo: textos[3] })) return;
    try {
        const r = await App.api(`api/pedidos.php?id=${id}`, 'PATCH', { acao });
        App.toast(`${estoque ? 'Ordem' : 'Pedido'} #${id}: ${(estoque ? STATUS_ESTOQUE : STATUS)[r.status]}.`);
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

async function excluir(id) {
    const p = pedidos.find(x => x.id === id);
    const nome = p && p.tipo === 'estoque' ? `a ordem de estoque #${id}` : `o pedido #${id}`;
    if (!await App.confirmar(`Excluir definitivamente ${nome}? Esta ação não pode ser desfeita.`, { titulo: 'Excluir', botao: 'Excluir', perigo: true })) return;
    try {
        await App.api(`api/pedidos.php?id=${id}`, 'DELETE');
        App.toast(`Registro #${id} excluído com sucesso.`);
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

$('btnNovo').addEventListener('click', () => novo());
$('busca').addEventListener('input', renderizar);
$('filtroTipo').addEventListener('change', carregar);
$('filtroStatus').addEventListener('change', carregar);

(async function init() {
    await carregar();
    // Atalhos por URL: ?novo=1[&cliente=ID]  |  ?editar=ID  |  ?detalhe=ID
    const q = new URLSearchParams(location.search);
    if (q.get('detalhe')) verDetalhes(parseInt(q.get('detalhe'), 10));
    else if (q.get('editar')) editar(parseInt(q.get('editar'), 10));
    else if (q.get('novo')) novo(q.get('cliente') ? parseInt(q.get('cliente'), 10) : null);
    if (q.toString()) history.replaceState(null, '', 'pedidos.php');
})();
