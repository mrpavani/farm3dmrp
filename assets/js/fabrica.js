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
    abaAtual = aba === 'fabricar' ? 'fabricar' : 'pedidos';
    document.querySelectorAll('.aba').forEach(b => b.setAttribute('aria-selected', String(b.dataset.aba === abaAtual)));
    $('abaPedidos').hidden = abaAtual !== 'pedidos';
    $('abaFabricar').hidden = abaAtual !== 'fabricar';
    history.replaceState(null, '', abaAtual === 'fabricar' ? '#fabricar' : location.pathname + location.search);
    recarregar();
}

function recarregar() {
    atualizarContador();
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

// Contador da aba Fabricar (total pendente, sem filtros)
async function atualizarContador() {
    try {
        const d = await App.api('api/fabricar.php');
        $('contadorFabricar').textContent = fmtInt.format(d.resumo.unidades);
        $('contadorFabricar').hidden = !d.resumo.unidades;
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

carregarProdutosFiltro();
mostrarAba(location.hash === '#fabricar' ? 'fabricar' : 'pedidos');
