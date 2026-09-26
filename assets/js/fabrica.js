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
                        ${(p.tempo_futuro_formatado || (p.peso_futuro_gramas > 0)) && pendente ? `
                            <span title="Estimativa futura de tempo e filamento para produzir o que falta">⏱️ Restam <b>${p.tempo_futuro_formatado || '00:00:00'}</b> · ⚖️ <b>${(App.num ? App.num(p.peso_futuro_gramas || 0, 1) : Number(p.peso_futuro_gramas || 0).toFixed(1))}g</b></span>
                        ` : ''}
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

// Contadores das abas e Hero KPIs da Linha de Produção
let dadosFabricarCarregados = null;

async function atualizarHeroKpis() {
    try {
        const [dFab, dPecas, pedidosAbertos] = await Promise.all([
            App.api('api/fabricar.php'),
            App.api('api/fabrica_pecas.php'),
            App.api('api/pedidos.php?status=aberto,em_producao')
        ]);

        const prods = dPecas.produtos || [];
        const totalMontavel = prods.reduce((s, p) => s + (p.montavel && p.montavel !== Infinity ? p.montavel : 0), 0);
        const prodsMontaveis = prods.filter(p => p.montavel > 0).length;
        const totalAImprimir = dPecas.resumo ? dPecas.resumo.total_a_imprimir : 0;
        const totalPedidos = Array.isArray(pedidosAbertos) ? pedidosAbertos.length : 0;
        const todosItens = (dFab.produtos || []).flatMap(g => g.itens || []);
        const atrasadas = todosItens.filter(i => App.diasAte(i.data_entrega_prometida) < 0)
            .reduce((s, i) => s + Number(i.restante), 0);
        const unidadesFabricar = dFab.resumo ? dFab.resumo.unidades : 0;

        const hero = $('fabricaHeroKpis');
        if (hero) {
            hero.innerHTML = `
                <div class="kpi kpi-hero kpi-montavel card-kpi-clicavel" onclick="mostrarAba('pecas'); filtrarBancada('montavel');" role="button" tabindex="0" title="Clique para ver produtos que já podem ser montados">
                    <div class="kpi-topo">
                        <span class="rotulo">Montável Agora</span>
                        <span class="kpi-icone">🚀</span>
                    </div>
                    <div class="valor">${fmtInt.format(totalMontavel)}</div>
                    <div class="sub">unidades prontas · em ${fmtInt.format(prodsMontaveis)} produto(s)</div>
                </div>
                <div class="kpi kpi-hero ${totalAImprimir > 0 ? 'kpi-producao' : ''} card-kpi-clicavel" onclick="mostrarAba('pecas'); filtrarBancada('imprimir');" role="button" tabindex="0" title="Clique para ver peças pendentes de impressão">
                    <div class="kpi-topo">
                        <span class="rotulo">Peças a Imprimir</span>
                        <span class="kpi-icone">🖨️</span>
                    </div>
                    <div class="valor">${fmtInt.format(totalAImprimir)}</div>
                    <div class="sub">peças na fila de impressão 3D</div>
                </div>
                <div class="kpi kpi-hero card-kpi-clicavel" onclick="mostrarAba('pedidos');" role="button" tabindex="0" title="Clique para ver a lista de pedidos">
                    <div class="kpi-topo">
                        <span class="rotulo">Ordens na Fábrica</span>
                        <span class="kpi-icone">📋</span>
                    </div>
                    <div class="valor">${fmtInt.format(totalPedidos)}</div>
                    <div class="sub">${fmtInt.format(unidadesFabricar)} produtos a produzir</div>
                </div>
                <div class="kpi kpi-hero ${atrasadas > 0 ? 'kpi-alerta' : ''} card-kpi-clicavel" onclick="mostrarAba('fabricar');" role="button" tabindex="0" title="Clique para priorizar produtos na visão por entrega">
                    <div class="kpi-topo">
                        <span class="rotulo">Atrasadas / Urgentes</span>
                        <span class="kpi-icone">🚨</span>
                    </div>
                    <div class="valor">${fmtInt.format(atrasadas)}</div>
                    <div class="sub">${atrasadas > 0 ? 'prioridade urgente na produção' : 'nenhum pedido atrasado'}</div>
                </div>
            `;
        }

        if ($('contadorPedidos')) {
            $('contadorPedidos').textContent = fmtInt.format(totalPedidos);
            $('contadorPedidos').hidden = !totalPedidos;
        }
        if ($('contadorFabricar')) {
            $('contadorFabricar').textContent = fmtInt.format(unidadesFabricar);
            $('contadorFabricar').hidden = !unidadesFabricar;
        }
        if ($('contadorPecas')) {
            $('contadorPecas').textContent = fmtInt.format(totalAImprimir);
            $('contadorPecas').hidden = !totalAImprimir;
        }
    } catch (e) {
        console.warn('Erro ao atualizar hero KPIs:', e);
    }
}

async function atualizarContador() {
    await atualizarHeroKpis();
}

function renderizarFabricar(d) {
    dadosFabricarCarregados = d;
    aplicarFiltroFabricar();
}

function aplicarFiltroFabricar() {
    if (!dadosFabricarCarregados) return;
    const d = dadosFabricarCarregados;
    const busca = App.normalizar(($('fabProdutoBusca')?.value || '').trim());

    const todos = d.produtos.flatMap(g => g.itens);
    const soma = lista => lista.reduce((s, i) => s + Number(i.restante), 0);
    const atrasadas = soma(todos.filter(i => App.diasAte(i.data_entrega_prometida) < 0));
    const prox7 = soma(todos.filter(i => { const n = App.diasAte(i.data_entrega_prometida); return n >= 0 && n <= 7; }));

    const kpis = [
        ['Total a Fabricar', d.resumo.unidades, ''],
        ['Para Clientes', d.resumo.unidades - d.resumo.unidades_estoque, ''],
        ['Para Estoque', d.resumo.unidades_estoque, 'kpi-estoque'],
        ['Atrasadas', atrasadas, atrasadas ? 'kpi-alerta' : ''],
        ['Vencem em 7 dias', prox7, ''],
    ];
    $('fabResumo').innerHTML = kpis.map(([rot, val, cls]) =>
        `<div class="kpi ${cls}"><div class="rotulo">${rot}</div><div class="valor">${fmtInt.format(val)}</div></div>`).join('');

    const produtosFiltrados = d.produtos.filter(g => !busca || App.normalizar(g.produto_nome).includes(busca));

    if (!produtosFiltrados.length) {
        $('listaFabricar').innerHTML = App.estadoVazio('🎉', 'Nenhum produto pendente encontrado', busca ? 'Tente outro termo na busca.' : 'Não há pedidos aguardando produção para esse filtro.');
        return;
    }

    $('listaFabricar').innerHTML = produtosFiltrados.map(g => {
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
// Aba PEÇAS PARA IMPRIMIR / BANCADA (visão com fotos, estoque e fila)
//
// Uma peça pode ter mais de uma cor cadastrada (ex.: Chave de Fenda em
// Cinza e em Laranja), cada cor com seu próprio saldo — qualquer uma
// serve para montar. Uma peça cuja cor não importa (ex.: Suporte) tem
// uma única linha "Qualquer cor".
// ============================================================
let dadosPecasCarregados = null;
let corProduzirAtualId = null;
let corUploadFotoAtualId = null;
let pecaNovaCorAtualId = null;

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
let produtoAtualGerenciarPecasId = null;
let produtoAtualMontarId = null;

async function carregarPecasFabrica() {
    try {
        dadosPecasCarregados = await App.api('api/fabrica_pecas.php');
        renderizarPecasFabrica();
        if (produtoAtualGerenciarPecasId) {
            const p = (dadosPecasCarregados.produtos || []).find(x => x.id === produtoAtualGerenciarPecasId);
            if (p) {
                recalcularProduto(p);
                renderizarModalGerenciarPecas(p);
            }
        }
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

// Recalcula no cliente tudo o que depende do saldo das peças, para a tela
// responder na hora ao [+] sem precisar recarregar o servidor.
//   estoque  = saldo da própria peça física (produto_pecas.estoque)
//   rende    = quantos produtos esta peça sozinha permite montar
//   montavel = o menor rendimento entre as peças (o gargalo manda)
function recalcularProduto(p) {
    let montavel = Infinity;
    p.pecas.forEach(peca => {
        peca.estoque = Math.max(0, Number(peca.estoque) || 0);
        peca.rende = Math.floor(peca.estoque / Math.max(1, peca.por_unidade));
        peca.necessario_pedidos = p.demanda_liquida * peca.por_unidade;
        peca.a_imprimir = Math.max(0, peca.necessario_pedidos - peca.estoque);
        montavel = Math.min(montavel, peca.rende);
    });
    p.montavel = montavel === Infinity ? 0 : montavel;
    p.gargalos = p.pecas.filter(x => x.rende === p.montavel).map(x => x.nome);
    p.total_a_imprimir = p.pecas.reduce((s, x) => s + x.a_imprimir, 0);
    return p;
}

function filtrarBancada(status) {
    $('pecaFiltroStatus').value = status;
    document.querySelectorAll('#pillsFiltrosFabrica .pill-filtro').forEach(btn => {
        btn.classList.toggle('ativo', btn.dataset.filtro === status);
    });
    renderizarPecasFabrica();
}

// ---------- Linha da Tabela de Linha de Produção / Bancada ----------
function linhaTabelaBancada(p) {
    const fotoProd = p.foto
        ? `<img src="${esc(p.foto)}" alt="${esc(p.nome)}" loading="lazy">`
        : `<div class="bancada-thumb-placeholder">Sem foto</div>`;

    const todosGargalos = p.gargalos && p.gargalos.length ? p.gargalos.map(esc).join(', ') : '';

    let tagStatus = '';
    if (p.demanda_liquida > 0 && p.montavel >= p.demanda_liquida) {
        tagStatus = `<span class="badge-status-montagem pronto" title="Peças suficientes para montar toda a demanda aberta (${fmtInt.format(p.demanda_liquida)} un)">✓ Pronto</span>`;
    } else if (p.gargalos && p.gargalos.length) {
        const extra = p.gargalos.length > 1 ? `<small>+${p.gargalos.length - 1}</small>` : '';
        tagStatus = `<span class="badge-status-montagem gargalo" title="Peça(s) limitando a montagem: ${todosGargalos}">⚠️ Gargalo ${extra}</span>`;
    } else if (!p.pecas || !p.pecas.length) {
        tagStatus = `<span class="badge-status-montagem vazio" title="Sem ficha técnica cadastrada">—</span>`;
    } else {
        tagStatus = `<span class="badge-status-montagem ok" title="Estoque de peças balanceado">✓ OK</span>`;
    }

    const prazo = p.proxima_entrega
        ? `<span class="chip-prazo-produto"><span class="prazo-data">${App.data(p.proxima_entrega)}</span>${App.etiquetaPrazo(p.proxima_entrega)}</span>`
        : '<span class="vazio">Sem pedidos</span>';

    const pedidosQtd = p.qtd_pedidos
        ? `<span class="tag-pedidos-count">📋 ${p.qtd_pedidos} pedido${p.qtd_pedidos > 1 ? 's' : ''}</span>`
        : '';

    const filaDetalhe = (p.tempo_fila_segundos > 0)
        ? `<span class="tag-fila-detalhe" title="Fila de impressão pendente: ${p.tempo_fila_formatado} (${(App.num ? App.num(p.peso_fila_gramas || 0, 1) : Number(p.peso_fila_gramas || 0).toFixed(1))}g)">⏱️ ${p.tempo_fila_formatado}</span>`
        : '';

    const badgeMontavel = p.montavel > 0
        ? `<span class="badge-bancada-montavel pronto" title="${p.montavel} unidades que já podem ser montadas agora">🚀 ${fmtInt.format(p.montavel)}</span>`
        : `<span class="badge-bancada-montavel zero" title="Saldo insuficiente de peças">0</span>`;

    const aFabricar = `<span class="bancada-num-normal" title="${p.em_producao} unidades em pedidos pendentes">${fmtInt.format(p.em_producao)}</span>`;
    const estoque = `<span class="bancada-num-normal" title="${p.estoque} unidades montadas em estoque">${fmtInt.format(p.estoque)}</span>`;

    const btnPecas = App.botaoIcone('pecas', 'Peças: atualizar fabricação e saldo', `abrirModalGerenciarPecas(${p.id})`, 'primario');
    const btnMontar = p.montavel > 0
        ? App.botaoIcone('montagem', 'Montar unidades acabadas', `abrirModalMontarProduto(${p.id})`, 'sucesso')
        : `<button type="button" class="btn-icone desativado" disabled data-tip="Sem peças suficientes para montagem" aria-label="Montar (indisponível)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></button>`;

    return `
        <tr class="linha-bancada-produto ${p.montavel > 0 ? 'produto-pode-montar' : ''}" data-produto-id="${p.id}">
            <td>
                <div class="bancada-produto-celula">
                    <div class="bancada-thumb">${fotoProd}</div>
                    <div class="bancada-prod-info">
                        <span class="bancada-prod-nome">${esc(p.nome)}</span>
                        <span class="bancada-prod-sub">${p.pecas.length} peças · ${fmtInt.format(p.total_pecas_por_unidade)} un por montagem${p.tempo_un_formatado ? ` · ⏱️ ${p.tempo_un_formatado}/un` : ''}${p.peso_un_gramas > 0 ? ` · ⚖️ ${(App.num ? App.num(p.peso_un_gramas, 1) : Number(p.peso_un_gramas).toFixed(1))}g` : ''}</span>
                    </div>
                </div>
            </td>
            <td>
                <div class="bancada-prazo-wrap">
                    ${prazo}
                    ${pedidosQtd || filaDetalhe ? `
                        <div class="bancada-detalhe-pedidos">
                            ${pedidosQtd}
                            ${pedidosQtd && filaDetalhe ? '<span class="ponto-sep">·</span>' : ''}
                            ${filaDetalhe}
                        </div>
                    ` : ''}
                </div>
            </td>
            <td style="text-align: center;">${tagStatus}</td>
            <td class="num">${badgeMontavel}</td>
            <td class="num">${aFabricar}</td>
            <td class="num">${estoque}</td>
            <td class="num">
                <div class="acoes-icones" style="justify-content: flex-end;">
                    ${btnPecas}
                    ${btnMontar}
                </div>
            </td>
        </tr>
    `;
}

// Redesenha só a linha do produto mexido, sem perder a posição do scroll.
function atualizarLinhaProduto(p) {
    const tr = document.querySelector(`tr.linha-bancada-produto[data-produto-id="${p.id}"]`);
    if (tr) {
        tr.outerHTML = linhaTabelaBancada(p);
    }
    if (produtoAtualGerenciarPecasId === p.id) {
        atualizarMetricasModalPecas(p);
    }
}
function atualizarCardProduto(p) {
    atualizarLinhaProduto(p);
}

// ---------- Modal: Gerenciar / Atualizar Peças Fabricadas ----------
function abrirModalGerenciarPecas(prodId) {
    produtoAtualGerenciarPecasId = prodId;
    const p = (dadosPecasCarregados.produtos || []).find(x => x.id === prodId);
    if (!p) return;
    recalcularProduto(p);
    renderizarModalGerenciarPecas(p);
    App.modal.abrir('modalGerenciarPecas');
}

function renderizarModalGerenciarPecas(p) {
    if (!p) return;

    const fotoProd = p.foto
        ? `<img src="${esc(p.foto)}" alt="${esc(p.nome)}">`
        : `<div class="bancada-thumb-placeholder">Sem foto</div>`;

    $('modalGerenciarPecasFoto').innerHTML = fotoProd;
    $('modalGerenciarPecasTitulo').textContent = `Peças: ${p.nome}`;
    $('modalGerenciarPecasSub').textContent = `${p.pecas.length} tipos de peça cadastrados · Total ${fmtInt.format(p.total_pecas_por_unidade)} un por montagem`;

    atualizarMetricasModalPecas(p);

    if (!p.pecas.length) {
        $('modalGerenciarPecasLista').innerHTML = `<p class="vazio" style="padding: 24px; text-align: center;">Este produto ainda não possui peças cadastradas na ficha técnica.</p>`;
        return;
    }

    $('modalGerenciarPecasLista').innerHTML = p.pecas.map(peca => {
        const situacao = peca.a_imprimir > 0
            ? `<span class="tag-peca-situacao alerta">Faltam ${fmtInt.format(peca.a_imprimir)} un</span>`
            : `<span class="tag-peca-situacao ok">✓ Estoque OK</span>`;

        const fotoPeca = peca.foto
            ? `<img src="${esc(peca.foto)}" alt="${esc(peca.nome)}" loading="lazy">`
            : `<span style="font-size: 15px;">🧩</span>`;

        const chipsCores = peca.cores && peca.cores.length
            ? peca.cores.map(cor => `
                <div class="chip-cor-peca" data-cor="${cor.cor_id}">
                    <span class="badge-cor-min" style="background:${corHex(cor.cor)};"></span>
                    <span class="nome-cor-chip">${esc(cor.cor || 'Padrão')}</span>
                    <span class="peso-cor-chip">${cor.peso_gramas > 0 ? (App.num ? App.num(cor.peso_gramas, 1) : Number(cor.peso_gramas).toFixed(1)) + 'g' : '—'}</span>
                    ${cor.tempo_formatado ? `<span class="tempo-cor-chip">⏱️ ${cor.tempo_formatado}</span>` : ''}
                    <button type="button" class="btn-excluir-cor-peca" onclick="removerCorDaPeca(${cor.cor_id}, ${peca.peca_id}, ${p.id})" title="Remover cor">×</button>
                </div>
            `).join('')
            : `<span style="color:var(--text-3);font-size:11.5px;font-style:italic;">Sem cores cadastradas. Clique em <b>+ Cor</b>.</span>`;

        return `
        <div class="modal-peca-card" data-peca="${peca.peca_id}">
            <div class="peca-card-topo">
                <div class="peca-card-info">
                    <div class="peca-card-foto" onclick="abrirUploadFotoPecaDirect(${peca.peca_id})" title="Trocar foto desta peça">${fotoPeca}</div>
                    <div class="peca-card-textos">
                        <div class="peca-card-nome-linha">
                            <strong>${esc(peca.nome)}</strong>
                            ${situacao}
                        </div>
                        <div class="peca-card-meta">
                            <span><b>${peca.por_unidade}</b> un/prod</span>
                            <span class="sep">·</span>
                            <span class="destaque-rende">rende <b>${fmtInt.format(peca.rende)}</b> prod</span>
                            ${peca.tempo_producao_formatado ? `<span class="sep">·</span><span>⏱️ ${peca.tempo_producao_formatado}</span>` : ''}
                            ${peca.peso_gramas > 0 ? `<span class="sep">·</span><span>⚖️ ${(App.num ? App.num(peca.peso_gramas, 1) : Number(peca.peso_gramas).toFixed(1))}g total</span>` : ''}
                        </div>
                    </div>
                </div>
                <div class="peca-card-acoes">
                    <div class="stepper-peca-compacto">
                        <button type="button" class="btn-step" onclick="ajustarPeca(${peca.peca_id}, -1)" title="Tirar 1">−</button>
                        <input type="number" class="saldo-peca" min="0" value="${peca.estoque}"
                               onchange="definirSaldoPeca(${peca.peca_id}, this.value)"
                               title="Estoque físico da peça">
                        <button type="button" class="btn-step mais" onclick="ajustarPeca(${peca.peca_id}, 1)" title="Adicionar 1">+</button>
                    </div>
                    <button type="button" class="btn-peca-acao" onclick="abrirModalProduzirPeca(${peca.peca_id}, ${p.id})" title="Registrar lote maior">+ Lote</button>
                    <button type="button" class="btn-peca-acao" onclick="abrirModalNovaCor(${peca.peca_id}, ${p.id})" title="Adicionar cor/filamento">+ Cor</button>
                </div>
            </div>
            <div class="peca-card-cores-linha">
                <span class="rotulo-cores-inline">Cores:</span>
                <div class="modal-peca-cores-chips">
                    ${chipsCores}
                </div>
            </div>
        </div>`;
    }).join('');
}

function atualizarMetricasModalPecas(p) {
    const resumoEl = $('modalGerenciarPecasResumo');
    if (!resumoEl) return;

    const nomesGargalo = p.gargalos.slice(0, 2).map(esc).join(' e ')
        + (p.gargalos.length > 2 ? ` +${p.gargalos.length - 2}` : '');

    resumoEl.innerHTML = `
        <div class="modal-kpi-pill ${p.montavel > 0 ? 'pill-sucesso' : ''}" title="Unidades que podem ser montadas agora">
            <span class="kpi-pill-rotulo">🚀 Montável:</span>
            <span class="kpi-pill-valor">${fmtInt.format(p.montavel)}</span>
        </div>
        <div class="modal-kpi-pill" title="Demanda total em ordens abertas">
            <span class="kpi-pill-rotulo">📋 Pedidos:</span>
            <span class="kpi-pill-valor">${fmtInt.format(p.em_producao)}</span>
        </div>
        <div class="modal-kpi-pill" title="Unidades acabadas em estoque">
            <span class="kpi-pill-rotulo">📦 Estoque:</span>
            <span class="kpi-pill-valor">${fmtInt.format(p.estoque)}</span>
        </div>
        <div class="modal-kpi-pill ${p.gargalos.length ? 'pill-alerta' : 'pill-sucesso'}" title="${nomesGargalo || 'Equilibrado'}">
            <span class="kpi-pill-rotulo">⚠️ Gargalo:</span>
            <span class="kpi-pill-valor">${nomesGargalo || 'Equilibrado'}</span>
        </div>
    `;

    p.pecas.forEach(peca => {
        const cardPeca = document.querySelector(`.modal-peca-card[data-peca="${peca.peca_id}"]`);
        if (cardPeca) {
            const metaEl = cardPeca.querySelector('.peca-card-meta');
            if (metaEl) {
                metaEl.innerHTML = `<span><b>${peca.por_unidade}</b> un/prod</span><span class="sep">·</span><span class="destaque-rende">rende <b>${fmtInt.format(peca.rende)}</b> prod</span>${peca.tempo_producao_formatado ? `<span class="sep">·</span><span>⏱️ ${peca.tempo_producao_formatado}</span>` : ''}${peca.peso_gramas > 0 ? `<span class="sep">·</span><span>⚖️ ${(App.num ? App.num(peca.peso_gramas, 1) : Number(peca.peso_gramas).toFixed(1))}g total</span>` : ''}`;
            }
            const inputSaldo = cardPeca.querySelector('.saldo-peca');
            if (inputSaldo) inputSaldo.value = peca.estoque;

            const badge = cardPeca.querySelector('.tag-peca-situacao');
            if (badge) {
                if (peca.a_imprimir > 0) {
                    badge.className = 'tag-peca-situacao alerta';
                    badge.textContent = `Faltam ${fmtInt.format(peca.a_imprimir)} un`;
                } else {
                    badge.className = 'tag-peca-situacao ok';
                    badge.textContent = '✓ Estoque OK';
                }
            }
        }
    });
}

// ---------- Modal: Montagem de Produto Acabado ----------
function abrirModalMontarProduto(prodId) {
    produtoAtualMontarId = prodId;
    const p = (dadosPecasCarregados.produtos || []).find(x => x.id === prodId);
    if (!p) return;
    recalcularProduto(p);
    if (p.montavel <= 0) {
        App.toast('Não há peças suficientes para montar este produto.', 'aviso');
        return;
    }

    $('modalMontarTitulo').textContent = `Montar: ${p.nome}`;
    $('modalMontarSub').textContent = `Concluir montagem de produtos utilizando peças do estoque`;

    $('modalMontarResumo').innerHTML = `
        <div class="modal-resumo-card destaque-montavel">
            <span class="lbl">Montável Agora</span>
            <span class="val">${fmtInt.format(p.montavel)}</span>
        </div>
        <div class="modal-resumo-card">
            <span class="lbl">Demanda Aberta</span>
            <span class="val">${fmtInt.format(p.em_producao)}</span>
        </div>
        <div class="modal-resumo-card">
            <span class="lbl">Estoque Atual</span>
            <span class="val">${fmtInt.format(p.estoque)}</span>
        </div>
    `;

    const sugestao = p.demanda_liquida > 0 ? Math.min(p.montavel, p.demanda_liquida) : p.montavel;
    const inputQtd = $('modalMontarQtd');
    inputQtd.max = p.montavel;
    inputQtd.value = sugestao > 0 ? sugestao : 1;

    const atalhos = [];
    if (p.montavel >= 1) atalhos.push(1);
    if (p.montavel >= 5) atalhos.push(5);
    if (p.montavel >= 10) atalhos.push(10);
    if (p.demanda_liquida > 0 && p.demanda_liquida <= p.montavel && !atalhos.includes(p.demanda_liquida)) {
        atalhos.push(p.demanda_liquida);
    }
    atalhos.sort((a,b) => a - b);

    let atalhosHtml = atalhos.map(qtd => `
        <button type="button" class="secundario" style="padding: 2px 10px; font-size: 12px; min-height: 28px;" onclick="$('modalMontarQtd').value = ${qtd}">
            ${qtd === p.demanda_liquida ? `Atender Pedidos (${qtd})` : `+${qtd}`}
        </button>
    `).join('');

    atalhosHtml += `
        <button type="button" class="secundario" style="padding: 2px 10px; font-size: 12px; min-height: 28px; font-weight: 700;" onclick="$('modalMontarQtd').value = ${p.montavel}">
            Máximo (${p.montavel})
        </button>
    `;

    $('atalhosMontarQtd').innerHTML = atalhosHtml;
    App.modal.abrir('modalMontarProduto', '#modalMontarQtd');
}

async function confirmarMontarModal() {
    if (!produtoAtualMontarId) return;
    const p = (dadosPecasCarregados.produtos || []).find(x => x.id === produtoAtualMontarId);
    if (!p) return;

    const qtd = parseInt($('modalMontarQtd').value, 10) || 0;
    if (qtd <= 0) return App.toast('Informe quantas unidades montar.', 'erro');
    if (qtd > p.montavel) return App.toast(`Só é possível montar até ${p.montavel} unidades agora.`, 'erro');

    const btn = $('btnConfirmarMontarModal');
    btn.disabled = true;
    try {
        const r = await App.api('api/fabrica_pecas.php?acao=montar', 'POST', {
            produto_id: produtoAtualMontarId,
            quantidade: qtd
        });
        App.toast(r.mensagem || `Montagem de ${qtd} un. concluída com sucesso!`);
        App.modal.fechar('modalMontarProduto');
        await carregarPecasFabrica();
        atualizarContador();
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}

function atualizarResumoPecas() {
    const totalAImprimir = produtosVisiveis.reduce((s, p) => s + p.total_a_imprimir, 0);
    const totalEstoque = produtosVisiveis.reduce((s, p) => s + p.pecas.reduce((a, x) => a + x.estoque, 0), 0);
    const comFila = produtosVisiveis.reduce((s, p) => s + p.pecas.filter(x => x.a_imprimir > 0).length, 0);
    const totalMontavel = produtosVisiveis.reduce((s, p) => s + p.montavel, 0);
    const produtosMontaveis = produtosVisiveis.filter(p => p.montavel > 0).length;

    const pillMontavel = $('countPillMontavel');
    if (pillMontavel) pillMontavel.textContent = fmtInt.format(produtosMontaveis);
    const pillImprimir = $('countPillImprimir');
    if (pillImprimir) pillImprimir.textContent = fmtInt.format(comFila);

    $('pecasResumo').innerHTML = `
        <div class="kpi kpi-montavel card-kpi-clicavel" onclick="filtrarBancada('montavel')" role="button" tabindex="0" title="Clique para filtrar produtos montáveis">
            <div class="kpi-topo">
                <span class="rotulo">Montável Agora</span>
                <span class="kpi-icone">🚀</span>
            </div>
            <div class="valor">${fmtInt.format(totalMontavel)}</div>
            <div class="sub">em ${fmtInt.format(produtosMontaveis)} produto(s) prontos</div>
        </div>
        <div class="kpi ${totalAImprimir > 0 ? 'kpi-producao' : ''} card-kpi-clicavel" onclick="filtrarBancada('imprimir')" role="button" tabindex="0" title="Clique para filtrar produtos precisando de impressão">
            <div class="kpi-topo">
                <span class="rotulo">Total a Imprimir</span>
                <span class="kpi-icone">🖨️</span>
            </div>
            <div class="valor">${fmtInt.format(totalAImprimir)}</div>
            <div class="sub">peças pendentes na fila 3D</div>
        </div>
        <div class="kpi">
            <div class="kpi-topo">
                <span class="rotulo">Peças com Fila</span>
                <span class="kpi-icone">⚡</span>
            </div>
            <div class="valor">${fmtInt.format(comFila)}</div>
            <div class="sub">modelos aguardando produção</div>
        </div>
        <div class="kpi">
            <div class="kpi-topo">
                <span class="rotulo">Estoque de Peças</span>
                <span class="kpi-icone">📦</span>
            </div>
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
    if (!container) return;

    if (!produtosVisiveis.length) {
        container.innerHTML = `<tr><td colspan="7">${App.estadoVazio('📦', 'Nenhum produto encontrado', 'Ajuste os termos de busca ou filtros rápidos.')}</td></tr>`;
        if ($('rodapePecas')) $('rodapePecas').innerHTML = '';
        return;
    }

    container.innerHTML = produtosVisiveis.map(linhaTabelaBancada).join('');
    if ($('rodapePecas')) {
        $('rodapePecas').innerHTML = `Mostrando <strong>${produtosVisiveis.length}</strong> produto(s) na bancada de montagem.`;
    }
}

// ---------- Ações de saldo (o saldo vive na peça física; as cores definem o filamento consumido) ----------
function pecaPorId(pecaId) {
    for (const p of (dadosPecasCarregados.produtos || [])) {
        const peca = p.pecas.find(x => x.peca_id === pecaId);
        if (peca) return { produto: p, peca };
    }
    return null;
}

function aplicarNovoSaldoPeca(pecaId, novoEstoque) {
    if (pendentesPeca.has(pecaId)) return;
    const info = pecaPorId(pecaId);
    if (!info) return;
    info.peca.estoque = Number(novoEstoque);
    recalcularProduto(info.produto);
    atualizarLinhaProduto(info.produto);
    atualizarResumoPecas();
    agendarContador();
    const inputSaldo = document.querySelector(`.modal-peca-card[data-peca="${pecaId}"] .saldo-peca`);
    if (inputSaldo) inputSaldo.value = novoEstoque;
}

// Contadores das abas: agrupa requisições
let timerContador = null;
function agendarContador() {
    clearTimeout(timerContador);
    timerContador = setTimeout(atualizarContador, 700);
}

// Cliques rápidos no [+] somam na tela na hora e vão ao servidor agrupados
const pendentesPeca = new Map(); // peca_id -> { delta, timer }

function ajustarPeca(pecaId, delta) {
    const info = pecaPorId(pecaId);
    if (!info) return;
    if (info.peca.estoque + delta < 0) return; // não deixa negativar

    info.peca.estoque += delta;
    recalcularProduto(info.produto);
    atualizarLinhaProduto(info.produto);
    atualizarResumoPecas();

    const inputSaldo = document.querySelector(`.modal-peca-card[data-peca="${pecaId}"] .saldo-peca`);
    if (inputSaldo) inputSaldo.value = info.peca.estoque;

    const pend = pendentesPeca.get(pecaId) || { delta: 0, timer: null };
    pend.delta += delta;
    clearTimeout(pend.timer);
    pend.timer = setTimeout(() => enviarPendentePeca(pecaId), 500);
    pendentesPeca.set(pecaId, pend);
}

// [+] entra no histórico como "peça produzida"; [−] como ajuste de saldo
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
        aplicarNovoSaldoPeca(pecaId, r.novo_estoque);
    } catch (e) {
        App.toast(e.message, 'erro');
        carregarPecasFabrica();
    }
}

// Garante que nada pendente se perca ao trocar de aba ou sair da página
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
    pendentesPeca.delete(pecaId);
    try {
        const r = await App.api('api/fabrica_pecas.php?acao=ajustar_saldo_peca', 'POST',
            { peca_id: pecaId, estoque: novo });
        aplicarNovoSaldoPeca(pecaId, r.novo_estoque);
        App.toast('Saldo corrigido.');
    } catch (e) {
        App.toast(e.message, 'erro');
        carregarPecasFabrica();
    }
}

// Montagem: baixa as peças da ficha técnica e gera produto pronto no estoque
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
        await carregarPecasFabrica();
        atualizarContador();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

// Upload de fotos
let pecaUploadFotoAtualId = null;

function abrirUploadFotoCor(corId) {
    corUploadFotoAtualId = corId;
    pecaUploadFotoAtualId = null;
    const input = $('inputUploadFotoPeca');
    input.value = '';
    input.click();
}

function abrirUploadFotoPecaDirect(pecaId) {
    pecaUploadFotoAtualId = pecaId;
    corUploadFotoAtualId = null;
    const input = $('inputUploadFotoPeca');
    input.value = '';
    input.click();
}

$('inputUploadFotoPeca').addEventListener('change', async function(e) {
    const file = e.target.files && e.target.files[0];
    if (!file || (!corUploadFotoAtualId && !pecaUploadFotoAtualId)) return;

    const formData = new FormData();
    formData.append('foto', file);
    if (corUploadFotoAtualId) formData.append('cor_id', corUploadFotoAtualId);
    if (pecaUploadFotoAtualId) formData.append('peca_id', pecaUploadFotoAtualId);

    try {
        App.toast('Enviando foto...');
        const res = await fetch('api/upload_foto.php', {
            method: 'POST',
            body: formData
        });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.erro || 'Falha ao salvar foto.');
        App.toast('Foto atualizada com sucesso!');
        carregarPecasFabrica();
    } catch (err) {
        App.toast(err.message, 'erro');
    }
});

// Modal de Produção Rápida de Peças (lote maior da peça física)
let pecaProduzirAtualId = null;

function abrirModalProduzirPeca(pecaId, prodId) {
    pecaProduzirAtualId = pecaId;
    const p = (dadosPecasCarregados.produtos || []).find(x => x.id === prodId);
    if (!p) return;
    const peca = p.pecas.find(x => x.peca_id === pecaId);
    if (!peca) return;

    $('modalPecaTitulo').textContent = `Registrar Produção: ${peca.nome}`;
    const descCores = peca.cores && peca.cores.length
        ? `Cores: ${peca.cores.map(c => esc(c.cor) + (c.peso_gramas > 0 ? ' (' + (App.num ? App.num(c.peso_gramas, 1) : Number(c.peso_gramas).toFixed(1)) + 'g)' : '')).join(', ')}`
        : 'Sem cores cadastradas';
    $('modalPecaSub').textContent = `${descCores} · Produto: ${p.nome} · Faltam imprimir: ${peca.a_imprimir} un`;
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

// Modal: adicionar cor à peça (com gramas de filamento por cor)
function abrirModalNovaCor(pecaId, prodId) {
    pecaNovaCorAtualId = pecaId;
    const p = (dadosPecasCarregados.produtos || []).find(x => x.id === prodId);
    const peca = p ? p.pecas.find(x => x.peca_id === pecaId) : null;
    $('modalNovaCorSub').textContent = peca ? `Peça: ${peca.nome}` : '';
    $('modalNovaCorNome').value = '';
    if ($('modalNovaCorPeso')) $('modalNovaCorPeso').value = '';
    App.modal.abrir('modalNovaCor', '#modalNovaCorNome');
}

async function confirmarNovaCor() {
    if (!pecaNovaCorAtualId) return;
    const cor = $('modalNovaCorNome').value.trim();
    if (!cor) {
        App.toast('Informe o nome da cor.', 'erro');
        $('modalNovaCorNome').focus();
        return;
    }
    const peso = parseFloat($('modalNovaCorPeso')?.value) || 0;

    const btn = $('btnConfirmarNovaCor');
    btn.disabled = true;
    try {
        const r = await App.api('api/fabrica_pecas.php?acao=adicionar_cor', 'POST', {
            peca_id: pecaNovaCorAtualId,
            cor,
            peso_gramas: peso,
        });
        App.toast(r.mensagem);
        App.modal.fechar('modalNovaCor');
        await carregarPecasFabrica();
        atualizarContador();
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}

async function removerCorDaPeca(corId, pecaId, prodId) {
    if (!await App.confirmar('Remover esta cor/filamento desta peça?', { titulo: 'Remover cor', botao: 'Remover', perigo: true })) return;
    try {
        const r = await App.api('api/fabrica_pecas.php?acao=remover_cor', 'POST', {
            cor_id: corId,
            peca_id: pecaId
        });
        App.toast(r.mensagem || 'Cor removida com sucesso.');
        await carregarPecasFabrica();
        atualizarContador();
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
if ($('fabProdutoBusca')) $('fabProdutoBusca').addEventListener('input', aplicarFiltroFabricar);
$('btnFabAtualizar').addEventListener('click', carregarFabricar);
$('btnFabLimpar').addEventListener('click', () => {
    $('fabEntregaAte').value = '';
    $('fabProduto').value = '';
    if ($('fabProdutoBusca')) $('fabProdutoBusca').value = '';
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
$('pecaFiltroStatus').addEventListener('change', () => {
    const v = $('pecaFiltroStatus').value;
    document.querySelectorAll('#pillsFiltrosFabrica .pill-filtro').forEach(btn => {
        btn.classList.toggle('ativo', btn.dataset.filtro === v);
    });
    renderizarPecasFabrica();
});

document.querySelectorAll('#pillsFiltrosFabrica .pill-filtro').forEach(btn => {
    btn.addEventListener('click', () => filtrarBancada(btn.dataset.filtro));
});

$('btnPecasAtualizar').addEventListener('click', carregarPecasFabrica);
$('btnPecasLimpar').addEventListener('click', () => {
    $('pecaBusca').value = '';
    filtrarBancada('');
});
$('btnConfirmarProducaoPeca').addEventListener('click', confirmarProducaoPeca);
$('modalPecaQtd').addEventListener('keydown', e => { if (e.key === 'Enter') confirmarProducaoPeca(); });
$('btnConfirmarNovaCor').addEventListener('click', confirmarNovaCor);
$('modalNovaCorNome').addEventListener('keydown', e => { if (e.key === 'Enter') confirmarNovaCor(); });
if ($('modalNovaCorPeso')) {
    $('modalNovaCorPeso').addEventListener('keydown', e => { if (e.key === 'Enter') confirmarNovaCor(); });
}

if ($('btnConfirmarMontarModal')) {
    $('btnConfirmarMontarModal').addEventListener('click', confirmarMontarModal);
}
if ($('modalMontarQtd')) {
    $('modalMontarQtd').addEventListener('keydown', e => { if (e.key === 'Enter') confirmarMontarModal(); });
}
if ($('modalGerenciarPecas')) {
    $('modalGerenciarPecas').addEventListener('fechado', () => {
        produtoAtualGerenciarPecasId = null;
    });
}
if ($('modalMontarProduto')) {
    $('modalMontarProduto').addEventListener('fechado', () => {
        produtoAtualMontarId = null;
    });
}

// Tornar funções globais para onclick nos botões da tabela e modais
window.abrirUploadFotoCor = abrirUploadFotoCor;
window.abrirUploadFotoPecaDirect = abrirUploadFotoPecaDirect;
window.abrirModalProduzirPeca = abrirModalProduzirPeca;
window.abrirModalNovaCor = abrirModalNovaCor;
window.removerCorDaPeca = removerCorDaPeca;
window.abrirModalGerenciarPecas = abrirModalGerenciarPecas;
window.abrirModalMontarProduto = abrirModalMontarProduto;
window.confirmarMontarModal = confirmarMontarModal;
window.ajustarPeca = ajustarPeca;
window.definirSaldoPeca = definirSaldoPeca;
window.montarAgora = montarAgora;
window.filtrarBancada = filtrarBancada;

carregarProdutosFiltro();
const abaInicial = location.hash === '#pecas' ? 'pecas' : (location.hash === '#fabricar' ? 'fabricar' : 'pedidos');
mostrarAba(abaInicial);
atualizarHeroKpis();

