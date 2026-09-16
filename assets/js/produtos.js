// Módulo Produtos: lista + cadastro/edição em modal
let produtos = [];
let editandoId = null;
const $ = id => document.getElementById(id);

async function carregar() {
    try {
        produtos = await App.api('api/produtos.php?todos=1');
    } catch (e) {
        App.toast(e.message, 'erro');
        produtos = [];
    }
    renderizar();
}

function renderizar() {
    const termo = App.normalizar($('busca').value.trim());
    const situacao = $('filtroSituacao').value;
    const lista = produtos.filter(p =>
        (situacao === '' || String(p.ativo) === situacao) &&
        (!termo || App.normalizar(`${p.nome} ${p.descricao || ''}`).includes(termo)));

    const ativos = produtos.filter(p => Number(p.ativo) === 1).length;
    $('rodape').textContent = `${lista.length} de ${produtos.length} produtos · ${ativos} ativos · produtos já usados em pedidos não podem ser excluídos (desative-os)`;

    if (!lista.length) {
        $('tabelaProdutos').innerHTML = App.estadoVazio('📦',
            produtos.length ? 'Nenhum produto encontrado' : 'Nenhum produto cadastrado',
            produtos.length ? 'Ajuste a busca ou o filtro.' : 'Clique em "Novo produto" para cadastrar o primeiro.', 6);
        return;
    }

    $('tabelaProdutos').innerHTML = lista.map(p => {
        const ativo = Number(p.ativo) === 1;
        const usado = Number(p.qtd_pedidos) > 0;
        return `
        <tr class="${ativo ? '' : 'linha-inativa'}">
            <td>
                <strong>${App.esc(p.nome)}</strong>
                ${p.descricao ? `<span class="sub-linha descricao-curta" title="${App.esc(p.descricao)}">${App.esc(p.descricao)}</span>` : ''}
            </td>
            <td class="num">${App.fmtMoeda.format(p.preco)}</td>
            <td class="num" style="font-weight:600;">${App.fmtInt.format(p.estoque)}</td>
            <td><span class="badge ${ativo ? 'pronto' : 'aberto'}">${ativo ? 'Ativo' : 'Inativo'}</span></td>
            <td class="num">${App.fmtInt.format(p.qtd_pedidos)}</td>
            <td class="num">${App.fmtInt.format(p.qtd_produzida)}</td>
            <td class="col-acoes">
                <div class="acoes-icones">
                    ${App.botaoIcone('editar', 'Editar', `editar(${p.id})`, 'primario')}
                    ${ativo
                        ? App.botaoIcone('desativar', 'Desativar', `alternarAtivo(${p.id}, false)`, 'alerta')
                        : App.botaoIcone('ativar', 'Ativar', `alternarAtivo(${p.id}, true)`, 'sucesso')}
                    ${usado ? '' : App.botaoIcone('excluir', 'Excluir', `excluir(${p.id})`, 'perigo')}
                </div>
            </td>
        </tr>`;
    }).join('');
}

function abrirModal(p = null) {
    editandoId = p ? p.id : null;
    $('formProduto').reset();
    $('prodNome').value = p ? p.nome : '';
    $('prodPreco').value = p ? Number(p.preco).toFixed(2) : '';
    $('prodEstoque').value = p ? Number(p.estoque) : '0';
    $('prodAtivo').value = p ? String(p.ativo) : '1';
    $('prodDescricao').value = p ? (p.descricao || '') : '';
    $('prodTituloModal').textContent = p ? 'Editar produto' : 'Novo produto';
    $('prodSubModal').textContent = p ? p.nome : 'Só produtos ativos aparecem nos pedidos e nas ordens de estoque.';
    $('btnSalvarProduto').textContent = p ? 'Salvar alterações' : 'Cadastrar produto';
    App.modal.abrir('modalProduto', '#prodNome');
}

const editar = id => abrirModal(produtos.find(p => p.id === id));

async function salvar(ev) {
    ev.preventDefault();
    const payload = {
        nome: $('prodNome').value.trim(),
        preco: $('prodPreco').value || 0,
        estoque: parseInt($('prodEstoque').value) || 0,
        ativo: $('prodAtivo').value === '1',
        descricao: $('prodDescricao').value.trim(),
    };
    if (!payload.nome) {
        App.toast('Informe o nome do produto.', 'erro');
        $('prodNome').focus();
        return;
    }
    if (Number(payload.preco) < 0) {
        App.toast('O preço não pode ser negativo.', 'erro');
        $('prodPreco').focus();
        return;
    }

    const btn = $('btnSalvarProduto');
    btn.disabled = true;
    try {
        if (editandoId) {
            await App.api(`api/produtos.php?id=${editandoId}`, 'PUT', payload);
            App.toast(`Produto "${payload.nome}" atualizado.`);
        } else {
            await App.api('api/produtos.php', 'POST', payload);
            App.toast(`Produto "${payload.nome}" cadastrado.`);
        }
        App.modal.fechar('modalProduto');
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}

async function alternarAtivo(id, ativar) {
    const p = produtos.find(x => x.id === id);
    if (!ativar && !await App.confirmar(`Desativar "${p.nome}"? Ele deixa de aparecer nos pedidos e ordens de estoque.`, { titulo: 'Desativar produto', botao: 'Desativar' })) return;
    try {
        await App.api(`api/produtos.php?id=${id}`, 'PATCH', { ativo: ativar });
        App.toast(`Produto "${p.nome}" ${ativar ? 'ativado' : 'desativado'}.`);
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

async function excluir(id) {
    const p = produtos.find(x => x.id === id);
    if (!await App.confirmar(`Excluir o produto "${p.nome}"? Esta ação não pode ser desfeita.`, { titulo: 'Excluir produto', botao: 'Excluir', perigo: true })) return;
    try {
        await App.api(`api/produtos.php?id=${id}`, 'DELETE');
        App.toast(`Produto "${p.nome}" excluído.`);
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

$('btnNovo').addEventListener('click', () => abrirModal());
$('formProduto').addEventListener('submit', salvar);
$('busca').addEventListener('input', renderizar);
$('filtroSituacao').addEventListener('change', renderizar);
carregar();
