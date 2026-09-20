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

function botaoProducao(item, restante, pedidoId, itemId) {
    const pronto = Number(item.produto_estoque) || 0;
    const montavel = (item.produto_montavel === null || item.produto_montavel === undefined)
        ? 'null' : Number(item.produto_montavel);
    return `<button class="pequeno suave" onclick="abrirModalProducao(${itemId}, ${esc(JSON.stringify(item.produto_nome))}, ${restante}, ${pedidoId}, ${pronto}, ${montavel})">Registrar produção</button>`;
}

// Diz, por item de pedido, de onde as unidades que faltam podem sair:
// do estoque de produto pronto, de uma montagem, ou de nenhum dos dois.
function disponibilidadeItem(i) {
    const restante = Number(i.quantidade) - Number(i.quantidade_produzida);
    const pronto = Number(i.produto_estoque) || 0;
    // NULL = produto sem ficha técnica (impresso direto, sem montagem).
    const montavel = i.produto_montavel === null || i.produto_montavel === undefined
        ? null : Number(i.produto_montavel);

    const doEstoque = Math.min(pronto, restante);
    const faltaApos = restante - doEstoque;
    const podeMontar = montavel === null ? 0 : Math.min(montavel, faltaApos);

    return { restante, pronto, montavel, doEstoque, faltaApos, podeMontar,
             cobreTudo: doEstoque + podeMontar >= restante && restante > 0 };
}

function etiquetaDisponibilidade(i) {
    const d = disponibilidadeItem(i);
    if (d.restante <= 0) return '';
    if (d.doEstoque >= d.restante) {
        return `<span class="tag-disp pronto" title="Há ${d.pronto} un. montadas no estoque">✓ ${fmtInt.format(d.doEstoque)} em estoque</span>`;
    }
    if (d.cobreTudo) {
        return `<span class="tag-disp montar" title="Peças suficientes para montar o que falta">⚒ dá para montar</span>`;
    }
    if (d.doEstoque > 0 || d.podeMontar > 0) {
        return `<span class="tag-disp parcial">${fmtInt.format(d.doEstoque + d.podeMontar)} de ${fmtInt.format(d.restante)} disponíveis</span>`;
    }
    return `<span class="tag-disp falta">sem peças</span>`;
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
            else if (!finalizado) acao = botaoProducao(i, restante, p.id, i.id);
            return `
                <tr>
                    <td><strong>${esc(i.produto_nome)}</strong>${estoque || finalizado ? '' : etiquetaDisponibilidade(i)}</td>
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
                <td style="text-align:right">${botaoProducao(i, Number(i.restante), i.pedido_id, i.item_id)}</td>
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
function abrirModalProducao(pedidoItemId, produtoNome, restante, pedidoId, pronto = 0, montavel = null) {
    itemAtualId = pedidoItemId;
    restanteAtual = restante;
    $('modalItemInfo').textContent = `${produtoNome} · ordem #${pedidoId} · faltam ${restante} unidade(s)`;
    $('modalData').value = App.hoje();
    $('modalQuantidade').value = restante;
    $('modalQuantidade').max = restante;
    $('modalObs').value = '';

    // Mostra de onde as unidades vão sair e, se precisar montar, deixa o
    // atalho "montar e aplicar" pronto para o operador confirmar.
    const temFicha = montavel !== null;
    const doEstoque = Math.min(pronto, restante);
    const faltaApos = restante - doEstoque;
    const podeMontar = temFicha ? Math.min(montavel, faltaApos) : 0;

    const partes = [`<b>${fmtInt.format(pronto)}</b> pronto(s) em estoque`];
    if (temFicha) partes.push(`<b>${fmtInt.format(montavel)}</b> montável(is) com as peças`);
    $('modalDisponibilidade').innerHTML = partes.join(' · ');

    const linhaMontar = $('modalLinhaMontar');
    const check = $('modalMontarSeFaltar');
    const precisaMontar = faltaApos > 0 && podeMontar > 0;
    linhaMontar.hidden = !precisaMontar;
    check.checked = precisaMontar;
    if (precisaMontar) {
        $('modalMontarTexto').innerHTML =
            `Montar <b>${fmtInt.format(podeMontar)}</b> un. agora (baixa as peças do estoque) — o estoque pronto cobre só ${fmtInt.format(doEstoque)}.`;
    }

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
            montar_se_faltar: $('modalMontarSeFaltar').checked,
        });
        const pronto = r.status_pedido === 'pronto' ? ' A ordem ficou PRONTA.' : '';
        const montou = r.montadas_agora > 0 ? ` ${fmtInt.format(r.montadas_agora)} un. montada(s).` : '';
        App.toast(`Produção registrada por ${window.USUARIO.nome}.${montou}${pronto}`);
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

let produtosVisiveis = [];

async function carregarPecasFabrica() {
    try {
        dadosPecasCarregados = await App.api('api/fabrica_pecas.php');
        renderizarPecasFabrica();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

// Recalcula no cliente tudo o que depende do saldo das peças, para o card
// responder na hora ao [+] sem precisar recarregar a bancada inteira.
//   rende    = quantos produtos esta peça sozinha permite montar
//   montavel = o menor rendimento entre as peças (o gargalo manda)
function recalcularProduto(p) {
    let montavel = Infinity;
    p.pecas.forEach(peca => {
        peca.rende = Math.floor(Math.max(0, peca.estoque) / Math.max(1, peca.por_unidade));
        peca.necessario_pedidos = p.demanda_liquida * peca.por_unidade;
        peca.a_imprimir = Math.max(0, peca.necessario_pedidos - peca.estoque);
        montavel = Math.min(montavel, peca.rende);
    });
    p.montavel = montavel === Infinity ? 0 : montavel;
    p.gargalos = p.pecas.filter(x => x.rende === p.montavel).map(nomeComCor);
    p.total_a_imprimir = p.pecas.reduce((s, x) => s + x.a_imprimir, 0);
    return p;
}

const nomeComCor = peca => peca.nome + (peca.cor && peca.cor !== 'Padrão' ? ` (${peca.cor})` : '');

// ---------- Cabeçalho do produto: os números da decisão ----------
function cabecalhoProduto(p) {
    const classe = p.montavel === 0
        ? 'vazia'
        : (p.demanda_liquida > 0 && p.montavel >= p.demanda_liquida ? 'completa' : 'parcial');

    const nomes = p.gargalos.slice(0, 2).map(esc).join(' e ')
        + (p.gargalos.length > 2 ? ` +${p.gargalos.length - 2}` : '');
    const legenda = (p.demanda_liquida > 0 && p.montavel >= p.demanda_liquida)
        ? 'dá para fechar os pedidos'
        : (nomes ? `gargalo: ${nomes}` : 'sem peças cadastradas');

    const prazo = p.proxima_entrega
        ? `<span class="prazo-produto">entrega ${App.data(p.proxima_entrega)}${App.etiquetaPrazo(p.proxima_entrega)}</span>`
        : '';

    // Sugestão de quanto montar: o que os pedidos pedem, limitado ao possível.
    const sugestao = p.demanda_liquida > 0 ? Math.min(p.montavel, p.demanda_liquida) : p.montavel;

    const fotoProd = p.foto
        ? `<img src="${esc(p.foto)}" alt="${esc(p.nome)}" loading="lazy">`
        : `<div class="peca-foto-placeholder">Sem foto</div>`;

    return `
        <div class="produto-header">
            <div class="produto-foto">${fotoProd}</div>
            <div class="produto-info">
                <h3>${esc(p.nome)}${prazo}</h3>
                <div class="produto-metricas">
                    <span class="metrica-montavel ${classe}" title="Unidades que dá para montar agora com as peças já impressas.">
                        <span class="mm-topo">Montável agora <strong>${fmtInt.format(p.montavel)}</strong></span>
                        <small>${legenda}</small>
                    </span>
                    <span>A fabricar <strong>${fmtInt.format(p.em_producao)}</strong></span>
                    <span>Estoque pronto <strong>${fmtInt.format(p.estoque)}</strong></span>
                </div>
                <div class="bancada-montar">
                    <label for="qtdMontar-${p.id}">Montar</label>
                    <input type="number" id="qtdMontar-${p.id}" class="meta-input" min="1"
                           max="${p.montavel}" value="${sugestao > 0 ? sugestao : 1}" ${p.montavel ? '' : 'disabled'}>
                    <button type="button" class="pequeno" onclick="montarAgora(${p.id})" ${p.montavel ? '' : 'disabled'}>
                        Montar e mandar ao estoque
                    </button>
                    ${p.montavel ? '' : '<span class="dica-meta">imprima as peças do gargalo para liberar</span>'}
                </div>
            </div>
        </div>`;
}

// ---------- Linha de peça: saldo editável na própria linha ----------
function linhaPeca(p, peca) {
    const foto = peca.foto
        ? `<img src="${esc(peca.foto)}" alt="${esc(peca.nome)}" loading="lazy">`
        : `<div class="peca-foto-placeholder min">Sem foto</div>`;

    const situacao = peca.a_imprimir > 0
        ? `<span class="alerta">Faltam ${fmtInt.format(peca.a_imprimir)}</span>`
        : `<span class="ok">Suficiente</span>`;

    return `
        <div class="linha-peca-produto" data-peca="${peca.peca_id}">
            <div class="foto-miniatura" onclick="abrirUploadFotoPeca(${peca.peca_id})" title="Clique para trocar a foto">${foto}</div>
            <div class="info-peca">
                <strong>${esc(peca.nome)}</strong>
                <span class="badge-cor-min" style="background:${corHex(peca.cor)};"></span> ${esc(peca.cor)}
                <br><span class="qtd-un">${peca.por_unidade} un/produto · rende ${fmtInt.format(peca.rende)} produto(s)</span>
            </div>
            <div class="produzir-peca">${situacao}</div>
            <div class="stepper-peca">
                <button type="button" class="btn-step" onclick="ajustarPeca(${peca.peca_id}, -1)" title="Tirar 1 do saldo">−</button>
                <input type="number" class="saldo-peca" min="0" value="${peca.estoque}"
                       onchange="definirSaldoPeca(${peca.peca_id}, this.value)"
                       title="Saldo em estoque — edite para corrigir a contagem">
                <button type="button" class="btn-step mais" onclick="ajustarPeca(${peca.peca_id}, 1)" title="Imprimiu mais 1">+</button>
            </div>
            <div class="acoes-peca">
                <button type="button" class="pequeno secundario" onclick="abrirModalProduzirPeca(${peca.peca_id}, ${p.id})" title="Registrar um lote maior">+ Lote</button>
            </div>
        </div>`;
}

function conteudoCardProduto(p) {
    return cabecalhoProduto(p) + `
        <div class="produto-pecas">
            <h4>Peças (${p.pecas.length}) · ${fmtInt.format(p.total_pecas_por_unidade)} por produto montado</h4>
            <div class="lista-pecas">${p.pecas.map(peca => linhaPeca(p, peca)).join('')}</div>
        </div>`;
}

// Redesenha só o card mexido, preservando o scroll e o resto da tela.
function atualizarCardProduto(p) {
    const card = document.querySelector(`.card-produto-fabrica[data-produto="${p.id}"]`);
    if (card) card.innerHTML = conteudoCardProduto(p);
}

function atualizarResumoPecas() {
    const totalAImprimir = produtosVisiveis.reduce((s, p) => s + p.total_a_imprimir, 0);
    const totalEstoque = produtosVisiveis.reduce((s, p) => s + p.pecas.reduce((a, x) => a + x.estoque, 0), 0);
    const comFila = produtosVisiveis.reduce((s, p) => s + p.pecas.filter(x => x.a_imprimir > 0).length, 0);
    const totalMontavel = produtosVisiveis.reduce((s, p) => s + p.montavel, 0);
    const produtosMontaveis = produtosVisiveis.filter(p => p.montavel > 0).length;

    $('pecasResumo').innerHTML = `
        <div class="kpi kpi-montavel">
            <div class="rotulo">Montável agora</div>
            <div class="valor">${fmtInt.format(totalMontavel)}</div>
            <div class="sub">unidades prontas para montar, em ${fmtInt.format(produtosMontaveis)} produto(s)</div>
        </div>
        <div class="kpi ${totalAImprimir > 0 ? 'kpi-alerta' : ''}">
            <div class="rotulo">Total a Imprimir</div>
            <div class="valor">${fmtInt.format(totalAImprimir)}</div>
            <div class="sub">peças pendentes para os pedidos abertos</div>
        </div>
        <div class="kpi">
            <div class="rotulo">Peças com Fila</div>
            <div class="valor">${fmtInt.format(comFila)}</div>
            <div class="sub">modelos precisando de impressão</div>
        </div>
        <div class="kpi">
            <div class="rotulo">Estoque de Peças</div>
            <div class="valor">${fmtInt.format(totalEstoque)}</div>
            <div class="sub">peças soltas prontas na bancada</div>
        </div>
    `;
}

function renderizarPecasFabrica() {
    if (!dadosPecasCarregados) return;

    const termo = App.normalizar($('pecaBusca').value.trim());
    const statusFiltro = $('pecaFiltroStatus').value;

    produtosVisiveis = (dadosPecasCarregados.produtos || [])
        .map(recalcularProduto)
        .filter(p => {
            if (termo && !App.normalizar(p.nome).includes(termo)) return false;
            if (statusFiltro === 'imprimir' && p.total_a_imprimir <= 0) return false;
            if (statusFiltro === 'ok' && p.total_a_imprimir > 0) return false;
            if (statusFiltro === 'montavel' && p.montavel <= 0) return false;
            return true;
        });

    atualizarResumoPecas();

    const container = $('gridPecasFabrica');
    if (!produtosVisiveis.length) {
        container.innerHTML = `<div style="grid-column: 1 / -1;">${App.estadoVazio('📦', 'Nenhum produto encontrado', 'Ajuste os filtros ou cadastre as peças do produto em Produtos → Ficha Técnica.')}</div>`;
        return;
    }

    container.innerHTML = produtosVisiveis.map(p =>
        `<article class="card-produto-fabrica" data-produto="${p.id}">${conteudoCardProduto(p)}</article>`
    ).join('');
}

// ---------- Ações de saldo ----------
function produtoDaPeca(pecaId) {
    return (dadosPecasCarregados.produtos || []).find(p => p.pecas.some(x => x.peca_id === pecaId));
}

function aplicarNovoSaldo(pecaId, novoEstoque) {
    // Se ainda há cliques não enviados, o valor local já está à frente do
    // servidor: deixa como está, o próximo envio reconcilia.
    if (pendentesPeca.has(pecaId)) return;
    const p = produtoDaPeca(pecaId);
    if (!p) return;
    p.pecas.find(x => x.peca_id === pecaId).estoque = Number(novoEstoque);
    recalcularProduto(p);
    atualizarCardProduto(p);
    atualizarResumoPecas();
    agendarContador();
}

// Contadores das abas: agrupa, para clicar [+] dez vezes não virar 20 requisições.
let timerContador = null;
function agendarContador() {
    clearTimeout(timerContador);
    timerContador = setTimeout(atualizarContador, 700);
}

// Cliques rápidos no [+] somam na tela na hora e vão ao servidor agrupados,
// para nenhum clique ser perdido enquanto uma gravação está em andamento.
const pendentesPeca = new Map();   // peca_id -> { delta, timer }

function ajustarPeca(pecaId, delta) {
    const p = produtoDaPeca(pecaId);
    if (!p) return;
    const peca = p.pecas.find(x => x.peca_id === pecaId);
    if (peca.estoque + delta < 0) return;      // não deixa negativar

    peca.estoque += delta;                      // resposta imediata na tela
    recalcularProduto(p);
    atualizarCardProduto(p);
    atualizarResumoPecas();

    const pend = pendentesPeca.get(pecaId) || { delta: 0, timer: null };
    pend.delta += delta;
    clearTimeout(pend.timer);
    pend.timer = setTimeout(() => enviarPendentePeca(pecaId), 500);
    pendentesPeca.set(pecaId, pend);
}

// [+] entra no histórico como "peça produzida"; [−] como ajuste de contagem.
async function enviarPendentePeca(pecaId) {
    const pend = pendentesPeca.get(pecaId);
    if (!pend || !pend.delta) { pendentesPeca.delete(pecaId); return; }

    const delta = pend.delta;
    pendentesPeca.delete(pecaId);
    try {
        const r = delta > 0
            ? await App.api('api/fabrica_pecas.php?acao=registrar_producao_peca', 'POST',
                { peca_id: pecaId, quantidade: delta })
            : await App.api('api/fabrica_pecas.php?acao=ajustar_saldo_peca', 'POST',
                { peca_id: pecaId, delta });
        aplicarNovoSaldo(pecaId, r.novo_estoque);
    } catch (e) {
        App.toast(e.message, 'erro');
        carregarPecasFabrica();
    }
}

// Garante que nada pendente se perca ao trocar de aba ou sair da página.
function enviarTodosPendentes() {
    [...pendentesPeca.keys()].forEach(id => {
        clearTimeout(pendentesPeca.get(id).timer);
        enviarPendentePeca(id);
    });
}
window.addEventListener('beforeunload', enviarTodosPendentes);
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') enviarTodosPendentes();
});

async function definirSaldoPeca(pecaId, valor) {
    const novo = Math.max(0, parseInt(valor, 10) || 0);
    clearTimeout(pendentesPeca.get(pecaId)?.timer);
    pendentesPeca.delete(pecaId);   // digitar o saldo exato manda nos cliques pendentes
    try {
        const r = await App.api('api/fabrica_pecas.php?acao=ajustar_saldo_peca', 'POST',
            { peca_id: pecaId, estoque: novo });
        aplicarNovoSaldo(pecaId, r.novo_estoque);
        App.toast('Saldo corrigido.');
    } catch (e) {
        App.toast(e.message, 'erro');
        carregarPecasFabrica();
    }
}

// Montagem: baixa as peças da ficha técnica e gera produto pronto no estoque.
async function montarAgora(prodId) {
    const p = produtosVisiveis.find(x => x.id === prodId);
    if (!p) return;
    const qtd = parseInt($(`qtdMontar-${prodId}`)?.value, 10) || 0;
    if (qtd <= 0) return App.toast('Informe quantas unidades montar.', 'erro');
    if (qtd > p.montavel) return App.toast(`Só dá para montar ${p.montavel} agora.`, 'erro');

    const ok = await App.confirmar(
        `Montar ${qtd} un. de "${p.nome}"? As peças saem do estoque e viram produto pronto.`,
        { titulo: 'Montar produto', botao: `Montar ${qtd}` });
    if (!ok) return;

    try {
        const r = await App.api('api/fabrica_pecas.php?acao=montar', 'POST',
            { produto_id: prodId, quantidade: qtd });
        App.toast(r.mensagem);
        await carregarPecasFabrica();   // a montagem mexe em várias peças de uma vez
        atualizarContador();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
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
function abrirModalProduzirPeca(pecaId, prodId) {
    pecaProduzirAtualId = pecaId;
    const p = (dadosPecasCarregados.produtos || []).find(x => x.id === prodId);
    if (!p) return;
    const peca = p.pecas.find(x => x.peca_id === pecaId);
    if (!peca) return;

    $('modalPecaTitulo').textContent = `Registrar Impressão: ${peca.nome}`;
    $('modalPecaSub').textContent = `Cor: ${peca.cor} · Produto: ${p.nome} · Faltam imprimir: ${peca.a_imprimir} un`;
    $('modalPecaQtd').value = peca.a_imprimir > 0 ? peca.a_imprimir : 1;

    // Atalhos rápidos (+1, +2, +4, +8, e o total que falta)
    const atalhos = [1, 2, 4, 8];
    if (peca.a_imprimir > 0 && !atalhos.includes(peca.a_imprimir)) {
        atalhos.push(peca.a_imprimir);
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
$('pecaFiltroStatus').addEventListener('change', renderizarPecasFabrica);
$('btnPecasAtualizar').addEventListener('click', carregarPecasFabrica);
$('btnPecasLimpar').addEventListener('click', () => {
    $('pecaBusca').value = '';
    $('pecaFiltroStatus').value = '';
    renderizarPecasFabrica();
});
$('btnConfirmarProducaoPeca').addEventListener('click', confirmarProducaoPeca);
$('modalPecaQtd').addEventListener('keydown', e => { if (e.key === 'Enter') confirmarProducaoPeca(); });

// Tornar funções globais para onclick nos cards
window.abrirUploadFotoPeca = abrirUploadFotoPeca;
window.abrirModalProduzirPeca = abrirModalProduzirPeca;
window.ajustarPeca = ajustarPeca;
window.definirSaldoPeca = definirSaldoPeca;
window.montarAgora = montarAgora;

carregarProdutosFiltro();
const abaInicial = location.hash === '#pecas' ? 'pecas' : (location.hash === '#fabricar' ? 'fabricar' : 'pedidos');
mostrarAba(abaInicial);

