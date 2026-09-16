// Módulo Clientes: lista + cadastro/edição em modal (FormCliente)
let clientes = [];
const $ = id => document.getElementById(id);

async function carregar() {
    try {
        clientes = await App.api('api/clientes.php');
    } catch (e) {
        App.toast(e.message, 'erro');
        clientes = [];
    }
    renderizar();
}

function renderizar() {
    const termo = App.normalizar($('busca').value.trim());
    const lista = clientes.filter(c => !termo ||
        App.normalizar([c.nome, c.telefone, c.email, c.cidade, c.estado, c.descricao].join(' ')).includes(termo));

    $('rodape').textContent = `${lista.length} de ${clientes.length} cliente${clientes.length === 1 ? '' : 's'} · clientes com pedidos não podem ser excluídos`;

    if (!lista.length) {
        $('tabelaClientes').innerHTML = App.estadoVazio('👥',
            clientes.length ? 'Nenhum cliente encontrado' : 'Nenhum cliente cadastrado',
            clientes.length ? 'Tente outro termo de busca.' : 'Clique em "Novo cliente" para cadastrar o primeiro.', 6);
        return;
    }

    $('tabelaClientes').innerHTML = lista.map(c => {
        const tel = c.telefone ? `<a href="tel:${App.esc(c.telefone.replace(/[^\d+]/g, ''))}">${App.esc(c.telefone)}</a>` : '';
        const email = c.email ? `<a href="mailto:${App.esc(c.email)}">${App.esc(c.email)}</a>` : '';
        const local = c.cidade ? `${c.cidade}${c.estado ? '/' + c.estado : ''}` : (c.estado || '—');
        const usado = Number(c.qtd_pedidos) > 0;
        return `
        <tr>
            <td>
                <strong>${App.esc(c.nome)}</strong>
                ${c.descricao ? `<span class="sub-linha descricao-curta" title="${App.esc(c.descricao)}">${App.esc(c.descricao)}</span>` : ''}
            </td>
            <td class="contato">${tel}${email}${tel || email ? '' : '<span class="vazio">—</span>'}</td>
            <td>${App.esc(local)}</td>
            <td class="num">${c.qtd_pedidos}</td>
            <td>${App.data(c.ultimo_pedido)}</td>
            <td class="col-acoes">
                <div class="acoes-icones">
                    ${App.botaoIcone('pedido', 'Novo pedido para este cliente', `novoPedido(${c.id})`)}
                    ${App.botaoIcone('editar', 'Editar', `editar(${c.id})`, 'primario')}
                    ${usado ? '' : App.botaoIcone('excluir', 'Excluir', `excluir(${c.id})`, 'perigo')}
                </div>
            </td>
        </tr>`;
    }).join('');
}

function novo() {
    FormCliente.abrir({ aoSalvar: carregar });
}

function editar(id) {
    FormCliente.abrir({ cliente: clientes.find(c => c.id === id), aoSalvar: carregar });
}

function novoPedido(id) {
    FormPedido.abrir({ clienteId: id, aoSalvar: carregar });
}

async function excluir(id) {
    const c = clientes.find(x => x.id === id);
    if (!await App.confirmar(`Excluir o cliente "${c.nome}"? Esta ação não pode ser desfeita.`, { titulo: 'Excluir cliente', botao: 'Excluir', perigo: true })) return;
    try {
        await App.api(`api/clientes.php?id=${id}`, 'DELETE');
        App.toast(`Cliente "${c.nome}" excluído.`);
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

$('btnNovo').addEventListener('click', novo);
$('busca').addEventListener('input', renderizar);
carregar();
