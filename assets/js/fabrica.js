// Painel da Fábrica: aba Pedidos (cartões com itens) e aba Fabricar (visão por produto).
// Cadastro/edição de pedidos e ordens de estoque usam o modal compartilhado (FormPedido).
let itemAtualId = null;
let restanteAtual = 0;
let abaAtual = 'pedidos';
let pedidosCarregados = [];
const $ = id => document.getElementById(id);
const { esc, fmtInt } = App;

const STATUS = { aberto: 'Aberto', em_producao: 'Em produção', pronto: 'Pronto', entregue: 'Entregue', cancelado: 'Cancelado' };
const STATUS_ESTOQUE = { ...STATUS, aberto: 'Aberta', pronto: 'Pronta', entregue: 'Concluída', cancelado: 'Cancelada' };

const ehEstoque = p => p.tipo === 'estoque';
const rotuloStatus = p => (ehEstoque(p) ? STATUS_ESTOQUE : STATUS)[p.status] || p.status;
const destino = p => ehEstoque(p) ? '<span class="destino-estoque">Estoque</span>' : esc(p.cliente_nome || '—');

// ============================================================
// Abas
// ============================================================
function mostrarAba(aba) {
    if (aba === 'pecas') abaAtual = 'pecas';
    else if (aba === 'fabricar') abaAtual = 'fabricar';
    else abaAtual = 'pedidos';

    document.querySelectorAll('.aba').forEach(b => b.setAttribute('aria-selected', String(b.dataset.aba === abaAtual)));
    $('abaPedidos').hidden = abaAtual !== 'pedidos';
    $('abaFabricar').hidden = abaAtual !== 'fabricar';
    $('abaPecas').hidden = abaAtual !== 'pecas';
    history.replaceState(null, '', '#' + abaAtual);
    recarregar();
}

function recarregar() {
    atualizarContador();
    if (abaAtual === 'pecas') return carregarPecasFabrica();
    return abaAtual === 'fabricar' ? carregarFabricar() : carregarPedidos();
}


// ============================================================
// Aba PEDIDOS
// ============================================================
async function carregarPedidos() {
    const params = new URLSearchParams();
    const filtros = { data_de: 'dataDe', data_ate: 'dataAte', status: 'statusFiltro', tipo: 'tipoFiltro' };
    Object.entries(filtros).forEach(([k, id]) => { if ($(id).value) params.set(k, $(id).value); });
    try {
        pedidosCarregados = await App.api('api/pedidos.php?' + params.toString());
    } catch (e) {
        App.toast(e.message, 'erro');
        pedidosCarregados = [];
    }
    renderizarPedidos(pedidosCarregados);
}

function acoesPedido(p) {
    const estoque = ehEstoque(p);
    const finalizado = p.status === 'entregue' || p.status === 'cancelado';
    const temProducao = p.itens.some(i => Number(i.quantidade_produzida) > 0);
    const b = [];
    if (p.status === 'pronto') {
        b.push(App.botaoIcone(estoque ? 'concluir' : 'entregar', estoque ? 'Concluir (enviar ao estoque)' : 'Marcar como entregue', `mudarStatus(${p.id}, 'entregar')`, 'sucesso'));
    }
    if (finalizado) {
        b.push(App.botaoIcone('reabrir', 'Reabrir', `mudarStatus(${p.id}, 'reabrir')`));
    } else {
        b.push(App.botaoIcone('editar', 'Editar', `editarPedido(${p.id})`, 'primario'));
        b.push(App.botaoIcone('cancelar', 'Cancelar', `mudarStatus(${p.id}, 'cancelar')`, 'alerta'));
    }
    if (!temProducao) b.push(App.botaoIcone('excluir', 'Excluir', `excluirPedido(${p.id})`, 'perigo'));
    return `<div class="acoes-icones">${b.join('')}</div>`;
}

function botaoProducao(itemId, produtoNome, restante, pedidoId) {
    return `<button class="pequeno suave" onclick="abrirModalProducao(${itemId}, ${esc(JSON.stringify(produtoNome))}, ${restante}, ${pedidoId})">Registrar produção</button>`;
}

function renderizarPedidos(pedidos) {
    const container = $('listaPedidos');
    if (!pedidos.length) {
        container.innerHTML = App.estadoVazio('📭', 'Nenhum pedido encontrado', 'Ajuste os filtros ou crie um novo pedido.');
        return;
    }

    container.innerHTML = pedidos.map(p => {
        const total = p.itens.reduce((s, i) => s + Number(i.quantidade), 0);
        const feito = p.itens.reduce((s, i) => s + Number(i.quantidade_produzida), 0);
        const pct = total ? Math.round((feito / total) * 100) : 0;
        const finalizado = p.status === 'entregue' || p.status === 'cancelado';
        const pendente = p.status === 'aberto' || p.status === 'em_producao';
        const estoque = ehEstoque(p);

        const linhas = p.itens.map(i => {
            const restante = i.quantidade - i.quantidade_produzida;
            let acao = '';
            if (restante <= 0) acao = '<span class="concluido">✓ Concluído</span>';
            else if (!finalizado) acao = botaoProducao(i.id, i.produto_nome, restante, p.id);
            return `
                <tr>
                    <td><strong>${esc(i.produto_nome)}</strong></td>
                    <td class="num">${i.quantidade}</td>
                    <td class="num">${i.quantidade_produzida}</td>
                    <td class="num">${restante}</td>
                    <td>${i.produzido_por ? esc(i.produzido_por) : '<span class="vazio">—</span>'}</td>
                    <td style="text-align:right">${acao}</td>
                </tr>`;
        }).join('');

        const local = !estoque && p.cliente_cidade
            ? `<span>${esc(p.cliente_cidade)}${p.cliente_estado ? '/' + esc(p.cliente_estado) : ''}</span>` : '';

        return `
        <article class="pedido-bloco${estoque ? ' tipo-estoque' : ''}">
            <div class="pedido-cabecalho">
                <div>
                    <div class="pedido-titulo">
                        <span class="pedido-id">#${p.id}</span>
                        <strong>${estoque ? 'Produção para estoque' : esc(p.cliente_nome)}</strong>
                        ${estoque ? '<span class="badge estoque">Estoque</span>' : ''}
                    </div>
                    <div class="pedido-meta">
                        ${local}
                        <span>Criado em <b>${App.data(p.data_pedido)}</b></span>
                        <span>${estoque ? 'Concluir até' : 'Entrega'} <b>${App.data(p.data_entrega_prometida)}</b>${pendente ? App.etiquetaPrazo(p.data_entrega_prometida) : ''}</span>
                        <span>por <b>${p.usuario_nome ? esc(p.usuario_nome) : '—'}</b></span>
                    </div>
                </div>
                <div class="pedido-status">
                    <div class="progresso-box" title="${feito} de ${total} unidades">
                        <div class="progresso"><div style="width:${pct}%"></div></div>${pct}%
                    </div>
                    <span class="badge ${esc(p.status)}">${esc(rotuloStatus(p))}</span>
                    ${acoesPedido(p)}
                </div>
            </div>
            <div class="pedido-corpo">
                <div class="tabela-rolagem">
                    <table>
                        <thead>
                            <tr><th>Produto</th><th class="num">Qtd.</th><th class="num">Produzido</th><th class="num">Falta</th><th>Produzido por</th><th></th></tr>
                        </thead>
                        <tbody>${linhas}</tbody>
                    </table>
                </div>
                ${p.observacoes ? `<p class="pedido-obs">${esc(p.observacoes)}</p>` : ''}
            </div>
        </article>`;
    }).join('');
}

// ============================================================
// Aba FABRICAR (visão por produto)
// ============================================================
async function carregarProdutosFiltro() {
    try {
        const produtos = await App.api('api/produtos.php?todos=1');
        const sel = $('fabProduto');
        produtos.forEach(p => {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.nome + (Number(p.ativo) ? '' : ' (inativo)');
            sel.appendChild(opt);
        });
    } catch (e) { /* filtro opcional */ }
}

async function carregarFabricar() {
    const params = new URLSearchParams();
    if ($('fabEntregaAte').value) params.set('entrega_ate', $('fabEntregaAte').value);
    if ($('fabProduto').value) params.set('produto_id', $('fabProduto').value);
    try {
        renderizarFabricar(await App.api('api/fabricar.php?' + params.toString()));
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

// Contadores das abas Fabricar e Peças
async function atualizarContador() {
    try {
        const [dFab, dPecas] = await Promise.all([
            App.api('api/fabricar.php'),
            App.api('api/fabrica_pecas.php')
        ]);
        $('contadorFabricar').textContent = fmtInt.format(dFab.resumo.unidades);
        $('contadorFabricar').hidden = !dFab.resumo.unidades;

        $('contadorPecas').textContent = fmtInt.format(dPecas.resumo.total_a_imprimir);
        $('contadorPecas').hidden = !dPecas.resumo.total_a_imprimir;
    } catch (e) { /* ignorado */ }
}


function renderizarFabricar(d) {
    const todos = d.produtos.flatMap(g => g.itens);
    const soma = lista => lista.reduce((s, i) => s + Number(i.restante), 0);
    const atrasadas = soma(todos.filter(i => App.diasAte(i.data_entrega_prometida) < 0));
    const prox7 = soma(todos.filter(i => { const n = App.diasAte(i.data_entrega_prometida); return n >= 0 && n <= 7; }));

    const kpis = [
        ['Unidades a fabricar', d.resumo.unidades, ''],
        ['Para clientes', d.resumo.unidades - d.resumo.unidades_estoque, ''],
        ['Para estoque', d.resumo.unidades_estoque, 'kpi-estoque'],
        ['Atrasadas', atrasadas, atrasadas ? 'kpi-alerta' : ''],
        ['Vencem em 7 dias', prox7, ''],
    ];
    $('fabResumo').innerHTML = kpis.map(([rot, val, cls]) =>
        `<div class="kpi ${cls}"><div class="rotulo">${rot}</div><div class="valor">${fmtInt.format(val)}</div></div>`).join('');

    if (!d.produtos.length) {
        $('listaFabricar').innerHTML = App.estadoVazio('🎉', 'Nada pendente de fabricação', 'Não há pedidos nem ordens de estoque aguardando produção para esse filtro.');
        return;
    }

    $('listaFabricar').innerHTML = d.produtos.map(g => {
        const chips = g.por_data.map(x => {
            const n = App.diasAte(x.data);
            const cls = n < 0 ? 'atrasado' : (n <= 1 ? 'urgente' : (n <= 3 ? 'proximo' : ''));
            return `<span class="chip-data ${cls}"><strong>${App.data(x.data)}</strong> · ${fmtInt.format(x.quantidade)} un.</span>`;
        }).join('');

        const linhas = g.itens.map(i => `
            <tr>
                <td style="white-space:nowrap">${App.data(i.data_entrega_prometida)} ${App.etiquetaPrazo(i.data_entrega_prometida)}</td>
                <td><span class="pedido-id">#${i.pedido_id}</span></td>
                <td>${destino(i)}</td>
                <td class="num">${i.quantidade}</td>
                <td class="num">${i.quantidade_produzida}</td>
                <td class="num"><strong>${i.restante}</strong></td>
                <td>${esc(i.usuario_nome || '—')}</td>
                <td style="text-align:right">${botaoProducao(i.item_id, i.produto_nome, Number(i.restante), i.pedido_id)}</td>
            </tr>`).join('');

        const qtdEstoque = Number(g.restante_estoque);
        return `
        <article class="pedido-bloco">
            <div class="pedido-cabecalho">
                <div>
                    <div class="fabricar-produto">${esc(g.produto_nome)}</div>
                    <div class="pedido-meta">
                        <span><b>${g.qtd_pedidos}</b> ${g.qtd_pedidos === 1 ? 'ordem' : 'ordens'}</span>
                        ${qtdEstoque ? `<span><b class="destino-estoque">${fmtInt.format(qtdEstoque)}</b> para estoque</span>` : ''}
                        <span>próxima entrega <b>${App.data(g.proxima_entrega)}</b>${App.etiquetaPrazo(g.proxima_entrega)}</span>
                    </div>
                </div>
                <div class="fabricar-total">
                    <span class="numero">${fmtInt.format(g.total_restante)}</span>
                    <span class="rotulo">unidades a fabricar</span>
                </div>
            </div>
            <div class="pedido-corpo">
                <div class="chips-datas" aria-label="Quantidade por data de entrega">${chips}</div>
                <div class="tabela-rolagem">
                    <table>
                        <thead>
                            <tr><th>Entrega</th><th>Ordem</th><th>Destino</th><th class="num">Qtd.</th><th class="num">Produzido</th><th class="num">Falta</th><th>Criado por</th><th></th></tr>
                        </thead>
                        <tbody>${linhas}</tbody>
                    </table>
                </div>
            </div>
        </article>`;
    }).join('');
}

// ============================================================
// Registrar produção
// ============================================================
function abrirModalProducao(pedidoItemId, produtoNome, restante, pedidoId) {
    itemAtualId = pedidoItemId;
    restanteAtual = restante;
    $('modalItemInfo').textContent = `${produtoNome} · ordem #${pedidoId} · faltam ${restante} unidade(s)`;
    $('modalData').value = App.hoje();
    $('modalQuantidade').value = restante;
    $('modalQuantidade').max = restante;
    $('modalObs').value = '';
    App.modal.abrir('modalProducao', '#modalQuantidade');
    setTimeout(() => $('modalQuantidade').select(), 60);
}

async function confirmarProducao() {
    const quantidade = parseInt($('modalQuantidade').value, 10);
    if (!quantidade || quantidade <= 0) return App.toast('Informe uma quantidade válida.', 'erro');
    if (quantidade > restanteAtual) return App.toast(`A quantidade não pode passar do que falta (${restanteAtual}).`, 'erro');

    const btn = $('btnConfirmarProducao');
    btn.disabled = true;
    try {
        const r = await App.api('api/producoes.php', 'POST', {
            pedido_item_id: itemAtualId,
            data_producao: $('modalData').value,
            quantidade,
            observacoes: $('modalObs').value,
        });
        const pronto = r.status_pedido === 'pronto' ? ' A ordem ficou PRONTA.' : '';
        App.toast(`Produção registrada por ${window.USUARIO.nome}.${pronto}`);
        App.modal.fechar('modalProducao');
        recarregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}

// ============================================================
// Pedidos: editar, status e exclusão
// ============================================================
const editarPedido = id => FormPedido.abrir({ id, aoSalvar: recarregar });

function nomeRegistro(id) {
    const p = pedidosCarregados.find(x => x.id === id);
    const estoque = !!p && ehEstoque(p);
    return { estoque, nome: estoque ? `a ordem de estoque #${id}` : `o pedido #${id}` };
}

async function mudarStatus(id, acao) {
    const { estoque, nome } = nomeRegistro(id);
    const t = {
        entregar: estoque
            ? ['Concluir ordem', `Concluir ${nome}? As peças são consideradas enviadas ao estoque.`, 'Concluir', false]
            : ['Marcar como entregue', `Confirmar a entrega do pedido #${id}?`, 'Confirmar entrega', false],
        cancelar: ['Cancelar', `Cancelar ${nome}? A produção já registrada continua nos relatórios.`, estoque ? 'Cancelar ordem' : 'Cancelar pedido', true],
        reabrir: ['Reabrir', `Reabrir ${nome}? O status volta a refletir a produção.`, 'Reabrir', false],
    }[acao];
    if (!await App.confirmar(t[1], { titulo: t[0], botao: t[2], perigo: t[3] })) return;
    try {
        const r = await App.api(`api/pedidos.php?id=${id}`, 'PATCH', { acao });
        App.toast(`${estoque ? 'Ordem' : 'Pedido'} #${id}: ${(estoque ? STATUS_ESTOQUE : STATUS)[r.status]}.`);
        recarregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

async function excluirPedido(id) {
    const { estoque, nome } = nomeRegistro(id);
    if (!await App.confirmar(`Excluir definitivamente ${nome}? Esta ação não pode ser desfeita.`, { titulo: 'Excluir', botao: 'Excluir', perigo: true })) return;
    try {
        await App.api(`api/pedidos.php?id=${id}`, 'DELETE');
        App.toast(`${estoque ? 'Ordem' : 'Pedido'} #${id} excluído.`);
        recarregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

// ============================================================
// Aba PEÇAS PARA IMPRIMIR (visão com fotos, estoque e fila)
// ============================================================
let dadosPecasCarregados = null;
let pecaProduzirAtualId = null;
let pecaUploadFotoAtualId = null;

function corHex(cor) {
    const c = String(cor || '').toLowerCase().trim();
    const map = {
        'preto': '#1e293b', 'black': '#1e293b',
        'branco': '#f8fafc', 'white': '#f8fafc',
        'cinza': '#94a3b8', 'gray': '#94a3b8', 'grey': '#94a3b8',
        'vermelho': '#ef4444', 'red': '#ef4444',
        'azul': '#3b82f6', 'blue': '#3b82f6',
        'amarelo': '#eab308', 'yellow': '#eab308',
        'verde': '#22c55e', 'green': '#22c55e',
        'laranja': '#f97316', 'orange': '#f97316',
        'roxo': '#a855f7', 'purple': '#a855f7',
        'rosa': '#ec4899', 'pink': '#ec4899',
        'marrom': '#78350f', 'brown': '#78350f',
        'ouro': '#d97706', 'gold': '#d97706',
        'prata': '#cbd5e1', 'silver': '#cbd5e1',
        'cobre': '#b45309', 'copper': '#b45309'
    };
    return map[c] || '#64748b';
}

async function carregarPecasFabrica() {
    try {
        dadosPecasCarregados = await App.api('api/fabrica_pecas.php');
        popularFiltrosPecas(dadosPecasCarregados);
        renderizarPecasFabrica();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

function popularFiltrosPecas(d) {
    const selProd = $('pecaFiltroProduto');
    const valorAtualProd = selProd.value;
    selProd.innerHTML = '<option value="">Todos os produtos</option>';
    Object.entries(d.produtos_filtro || {}).forEach(([id, nome]) => {
        const opt = document.createElement('option');
        opt.value = id;
        opt.textContent = nome;
        if (id === valorAtualProd) opt.selected = true;
        selProd.appendChild(opt);
    });

    const selCor = $('pecaFiltroCor');
    const valorAtualCor = selCor.value;
    selCor.innerHTML = '<option value="">Todas as cores</option>';
    (d.cores_filtro || []).forEach(cor => {
        const opt = document.createElement('option');
        opt.value = cor;
        opt.textContent = cor;
        if (cor === valorAtualCor) opt.selected = true;
        selCor.appendChild(opt);
    });
}

function renderizarPecasFabrica() {
    if (!dadosPecasCarregados) return;

    const termo = App.normalizar($('pecaBusca').value.trim());
    const prodFiltro = $('pecaFiltroProduto').value;
    const corFiltro = $('pecaFiltroCor').value;
    const statusFiltro = $('pecaFiltroStatus').value;

    const filtradas = (dadosPecasCarregados.pecas || []).filter(p => {
        if (termo && !App.normalizar(`${p.peca_nome} ${p.produto_nome} ${p.cor}`).includes(termo)) return false;
        if (prodFiltro && String(p.produto_id) !== String(prodFiltro)) return false;
        if (corFiltro && p.cor !== corFiltro) return false;
        if (statusFiltro === 'imprimir' && p.a_imprimir <= 0) return false;
        if (statusFiltro === 'ok' && p.a_imprimir > 0) return false;
        return true;
    });

    // Renderizar KPIs
    const totalAImprimir = filtradas.reduce((s, p) => s + p.a_imprimir, 0);
    const totalEstoque = filtradas.reduce((s, p) => s + p.estoque_pecas, 0);
    const comFila = filtradas.filter(p => p.a_imprimir > 0).length;

    $('pecasResumo').innerHTML = `
        <div class="kpi ${totalAImprimir > 0 ? 'atrasado' : 'sucesso'}">
            <span class="rotulo">Total a Imprimir</span>
            <span class="valor">${fmtInt.format(totalAImprimir)}</span>
            <span class="sub">unidades de peças pendentes</span>
        </div>
        <div class="kpi ${comFila > 0 ? 'proximo' : ''}">
            <span class="rotulo">Peças com Fila</span>
            <span class="valor">${fmtInt.format(comFila)}</span>
            <span class="sub">modelos precisando de produção</span>
        </div>
        <div class="kpi">
            <span class="rotulo">Estoque de Peças</span>
            <span class="valor">${fmtInt.format(totalEstoque)}</span>
            <span class="sub">peças soltas prontas no estoque</span>
        </div>
        <div class="kpi">
            <span class="rotulo">Modelos Cadastrados</span>
            <span class="valor">${fmtInt.format(filtradas.length)}</span>
            <span class="sub">peças catalogadas no sistema</span>
        </div>
    `;

    const container = $('gridPecasFabrica');
    if (!filtradas.length) {
        container.innerHTML = `<div style="grid-column: 1 / -1;">${App.estadoVazio('🧩', 'Nenhuma peça encontrada', 'Ajuste os filtros ou cadastre peças na Ficha Técnica dos produtos.')}</div>`;
        return;
    }

    container.innerHTML = filtradas.map(p => {
        const precisaImprimir = p.a_imprimir > 0;
        const fotoHtml = p.foto
            ? `<img src="${esc(p.foto)}" alt="${esc(p.peca_nome)}" loading="lazy">`
            : `<div class="peca-foto-placeholder">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
                <span>Sem foto</span>
               </div>`;

        return `
        <article class="card-peca-fabrica ${p.status === 'urgente' ? 'status-urgente' : ''}">
            <div class="peca-foto-box">
                ${fotoHtml}
                <button type="button" class="btn-upload-foto" onclick="abrirUploadFotoPeca(${p.peca_id})" title="Enviar ou alterar foto">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
                    ${p.foto ? 'Trocar foto' : 'Adicionar foto'}
                </button>
            </div>

            <div class="peca-corpo">
                <div class="peca-cabecalho">
                    <div class="peca-titulo">${esc(p.peca_nome)}</div>
                    <span class="badge-cor">
                        <span class="ponto-cor" style="background:${corHex(p.cor)};"></span>
                        ${esc(p.cor)}
                    </span>
                </div>
                <div class="peca-produto-pai">
                    Produto: <strong>${esc(p.produto_nome)}</strong> (${p.por_unidade} un/produto)
                </div>

                <div class="peca-metricas">
                    <div class="metrica-col">
                        <span class="metrica-rotulo">Em Estoque</span>
                        <span class="metrica-valor">${fmtInt.format(p.estoque_pecas)}</span>
                    </div>
                    <div class="metrica-col">
                        <span class="metrica-rotulo">A Imprimir</span>
                        <span class="metrica-valor ${precisaImprimir ? 'alerta' : 'ok'}">
                            ${precisaImprimir ? fmtInt.format(p.a_imprimir) + ' un' : '0 (OK)'}
                        </span>
                    </div>
                </div>

                ${p.necessario_pedidos > 0 
                    ? `<div style="font-size:12px; color:var(--text-3); margin-bottom:12px;">Demanda de pedidos: <b>${p.necessario_pedidos} un</b> necessárias</div>` 
                    : '<div style="font-size:12px; color:var(--text-3); margin-bottom:12px;">Sem pedidos ativos demandando esta peça</div>'}

                <div class="peca-rodape-acoes">
                    <button type="button" class="primario" onclick="abrirModalProduzirPeca(${p.peca_id})">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><path d="M12 5v14M5 12h14"/></svg>
                        Registrar Impressão
                    </button>
                </div>
            </div>
        </article>`;
    }).join('');
}

// Upload de Foto de Peça
function abrirUploadFotoPeca(pecaId) {
    pecaUploadFotoAtualId = pecaId;
    const input = $('inputUploadFotoPeca');
    input.value = '';
    input.click();
}

$('inputUploadFotoPeca').addEventListener('change', async function(e) {
    const file = e.target.files && e.target.files[0];
    if (!file || !pecaUploadFotoAtualId) return;

    const formData = new FormData();
    formData.append('foto', file);
    formData.append('peca_id', pecaUploadFotoAtualId);

    try {
        App.toast('Enviando foto da peça...');
        const res = await fetch('api/upload_foto.php', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.erro || 'Falha ao salvar foto.');
        App.toast('Foto da peça atualizada com sucesso!');
        carregarPecasFabrica();
    } catch (err) {
        App.toast(err.message, 'erro');
    }
});

// Modal de Produção Rápida de Peças
function abrirModalProduzirPeca(pecaId) {
    pecaProduzirAtualId = pecaId;
    const p = (dadosPecasCarregados.pecas || []).find(x => x.peca_id === pecaId);
    if (!p) return;

    $('modalPecaTitulo').textContent = `Registrar Impressão: ${p.peca_nome}`;
    $('modalPecaSub').textContent = `Cor: ${p.cor} · Produto: ${p.produto_nome} · Faltam imprimir: ${p.a_imprimir} un`;
    $('modalPecaQtd').value = p.a_imprimir > 0 ? p.a_imprimir : 1;

    // Atalhos rápidos (+1, +2, +4, +8, e o total que falta)
    const atalhos = [1, 2, 4, 8];
    if (p.a_imprimir > 0 && !atalhos.includes(p.a_imprimir)) {
        atalhos.push(p.a_imprimir);
    }
    atalhos.sort((a,b) => a - b);

    $('atalhosQtdPeca').innerHTML = atalhos.map(qtd => `
        <button type="button" class="secundario" style="padding: 2px 10px; font-size: 12px; min-height: 28px;" onclick="$('modalPecaQtd').value = ${qtd}">
            +${qtd}
        </button>
    `).join('');

    App.modal.abrir('modalProduzirPeca', '#modalPecaQtd');
}

async function confirmarProducaoPeca() {
    if (!pecaProduzirAtualId) return;
    const qtd = parseInt($('modalPecaQtd').value) || 0;
    if (qtd <= 0) {
        App.toast('Informe uma quantidade válida maior que zero.', 'erro');
        $('modalPecaQtd').focus();
        return;
    }

    const btn = $('btnConfirmarProducaoPeca');
    btn.disabled = true;
    try {
        const res = await App.api('api/fabrica_pecas.php?acao=registrar_producao_peca', 'POST', {
            peca_id: pecaProduzirAtualId,
            quantidade: qtd
        });
        App.toast(res.mensagem || `+${qtd} peças adicionadas ao estoque!`);
        App.modal.fechar('modalProduzirPeca');
        carregarPecasFabrica();
        atualizarContador();
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}

// ============================================================
// Eventos
// ============================================================
document.querySelectorAll('.aba').forEach(b => b.addEventListener('click', () => mostrarAba(b.dataset.aba)));
['tipoFiltro', 'statusFiltro', 'dataDe', 'dataAte'].forEach(id => $(id).addEventListener('change', carregarPedidos));
$('btnLimparPedidos').addEventListener('click', () => {
    ['tipoFiltro', 'statusFiltro', 'dataDe', 'dataAte'].forEach(id => { $(id).value = ''; });
    carregarPedidos();
});
$('fabProduto').addEventListener('change', carregarFabricar);
$('fabEntregaAte').addEventListener('change', carregarFabricar);
$('btnFabAtualizar').addEventListener('click', carregarFabricar);
$('btnFabLimpar').addEventListener('click', () => {
    $('fabEntregaAte').value = '';
    $('fabProduto').value = '';
    carregarFabricar();
});
$('btnConfirmarProducao').addEventListener('click', confirmarProducao);
$('modalQuantidade').addEventListener('keydown', e => { if (e.key === 'Enter') confirmarProducao(); });
$('btnNovaOrdemEstoque').addEventListener('click', () => FormPedido.abrir({
    tipo: 'estoque',
    aoSalvar: () => mostrarAba('fabricar'),
}));

// Eventos da aba de Peças
$('pecaBusca').addEventListener('input', renderizarPecasFabrica);
$('pecaFiltroProduto').addEventListener('change', renderizarPecasFabrica);
$('pecaFiltroCor').addEventListener('change', renderizarPecasFabrica);
$('pecaFiltroStatus').addEventListener('change', renderizarPecasFabrica);
$('btnPecasAtualizar').addEventListener('click', carregarPecasFabrica);
$('btnPecasLimpar').addEventListener('click', () => {
    $('pecaBusca').value = '';
    $('pecaFiltroProduto').value = '';
    $('pecaFiltroCor').value = '';
    $('pecaFiltroStatus').value = '';
    renderizarPecasFabrica();
});
$('btnConfirmarProducaoPeca').addEventListener('click', confirmarProducaoPeca);
$('modalPecaQtd').addEventListener('keydown', e => { if (e.key === 'Enter') confirmarProducaoPeca(); });

// Tornar funções globais para onclick nos cards
window.abrirUploadFotoPeca = abrirUploadFotoPeca;
window.abrirModalProduzirPeca = abrirModalProduzirPeca;

carregarProdutosFiltro();
const abaInicial = location.hash === '#pecas' ? 'pecas' : (location.hash === '#fabricar' ? 'fabricar' : 'pedidos');
mostrarAba(abaInicial);

