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

function renderizar() {
    const termo = App.normalizar($('busca').value.trim());
    const lista = pedidos.filter(p => !termo || App.normalizar(
        `#${p.id} ${p.id} ${p.cliente_nome || 'estoque'} ${p.cliente_cidade || ''} ${p.itens.map(i => i.produto_nome).join(' ')}`
    ).includes(termo));

    $('rodape').textContent = `${lista.length} de ${pedidos.length} registro${pedidos.length === 1 ? '' : 's'}`;

    if (!lista.length) {
        $('tabelaPedidos').innerHTML = App.estadoVazio('📋',
            pedidos.length ? 'Nenhum pedido encontrado' : 'Nenhum pedido por aqui',
            pedidos.length ? 'Tente outro termo de busca.' : 'Clique em "Novo pedido" para registrar o primeiro.', 7);
        return;
    }

    $('tabelaPedidos').innerHTML = lista.map(p => {
        const estoque = p.tipo === 'estoque';
        const total = p.itens.reduce((s, i) => s + Number(i.quantidade), 0);
        const feito = p.itens.reduce((s, i) => s + Number(i.quantidade_produzida), 0);
        const pct = total ? Math.round(feito / total * 100) : 0;
        const pendente = p.status === 'aberto' || p.status === 'em_producao';
        const itens = p.itens.map(i => `${i.quantidade}× ${i.produto_nome}`).join(', ');
        const rotulo = (estoque ? STATUS_ESTOQUE : STATUS)[p.status] || p.status;
        const local = !estoque && p.cliente_cidade ? `${p.cliente_cidade}${p.cliente_estado ? '/' + p.cliente_estado : ''}` : '';
        return `
        <tr>
            <td class="celula-destaque">
                <div class="pedido-titulo" style="font-size:14px">
                    <span class="pedido-id">#${p.id}</span>
                    <strong style="display:inline">${estoque ? 'Estoque' : App.esc(p.cliente_nome)}</strong>
                    ${estoque ? '<span class="badge estoque">Estoque</span>' : ''}
                </div>
                <span class="sub-linha">${local ? App.esc(local) + ' · ' : ''}criado em ${App.data(p.data_pedido)}</span>
            </td>
            <td><div class="itens-resumo descricao-curta" title="${App.esc(itens)}">${App.esc(itens)}</div></td>
            <td class="col-entrega">${App.data(p.data_entrega_prometida)}${pendente ? App.etiquetaPrazo(p.data_entrega_prometida) : ''}</td>
            <td>
                <div class="progresso-box" title="${feito} de ${total} unidades">
                    <div class="progresso" style="width:80px"><div style="width:${pct}%"></div></div>${pct}%
                </div>
            </td>
            <td><span class="badge ${App.esc(p.status)}">${App.esc(rotulo)}</span></td>
            <td>${App.esc(p.usuario_nome || '—')}</td>
            <td class="col-acoes">${acoes(p)}</td>
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
