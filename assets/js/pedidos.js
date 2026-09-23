// Módulo Pedidos: lista + formulário em modal (FormPedido)
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
    if (p.status === 'pronto') {
        b.push(App.botaoIcone(estoque ? 'concluir' : 'entregar', estoque ? 'Concluir (enviar ao estoque)' : 'Marcar como entregue', `mudarStatus(${p.id}, 'entregar')`, 'sucesso'));
    }
    if (finalizado) {
        b.push(App.botaoIcone('reabrir', 'Reabrir', `mudarStatus(${p.id}, 'reabrir')`));
    } else {
        b.push(App.botaoIcone('editar', 'Editar', `editar(${p.id})`, 'primario'));
        b.push(App.botaoIcone('cancelar', 'Cancelar', `mudarStatus(${p.id}, 'cancelar')`, 'alerta'));
    }
    if (!temProducao) b.push(App.botaoIcone('excluir', 'Excluir', `excluir(${p.id})`, 'perigo'));
    return `<div class="acoes-icones">${b.join('')}</div>`;
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
            <span class="chip-item-pedido" title="${App.esc(i.produto_nome)}: ${i.quantidade_produzida || 0} de ${i.quantidade} produzidos">
                <strong class="chip-qtd">${i.quantidade}×</strong>
                <span class="chip-nome">${App.esc(i.produto_nome)}</span>
            </span>
        `).join('');

        return `
        <tr class="linha-pedido-tabela">
            <td class="col-pedido-id">
                <div class="id-e-tipo">
                    <span class="pedido-id-destaque">#${p.id}</span>
                    <span class="tag-origem ${estoque ? 'origem-estoque' : 'origem-venda'}">${estoque ? 'Estoque' : 'Cliente'}</span>
                </div>
            </td>
            <td class="col-cliente">
                <div class="cliente-perfil-bloco">
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
        App.toast(`Registro #${id} excluído.`);
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
    // Atalhos por URL: ?novo=1[&cliente=ID]  |  ?editar=ID
    const q = new URLSearchParams(location.search);
    if (q.get('editar')) editar(parseInt(q.get('editar'), 10));
    else if (q.get('novo')) novo(q.get('cliente') ? parseInt(q.get('cliente'), 10) : null);
    if (q.toString()) history.replaceState(null, '', 'pedidos.php');
})();
