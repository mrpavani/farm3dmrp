// Formulário de pedido em modal (pedido de cliente ou ordem de estoque).
// FormPedido.abrir({ id, tipo, clienteId, aoSalvar(resultado) })
//   id        -> editar esse pedido (o tipo vem do banco)
//   tipo      -> 'venda' (padrão) ou 'estoque' para um novo
//   clienteId -> cliente já selecionado num pedido novo
window.FormPedido = (() => {
    const $ = id => document.getElementById(id);
    let produtos = [];
    let clientes = [];
    let carregado = false;
    let editandoId = null;
    let tipo = 'venda';
    let callback = null;

    async function carregarListas() {
        [produtos, clientes] = await Promise.all([App.api('api/produtos.php'), App.api('api/clientes.php')]);
        carregado = true;
        preencherClientes();
    }

    function preencherClientes(selecionar = null) {
        const sel = $('pedCliente');
        const atual = selecionar ?? sel.value;
        sel.length = 1;
        clientes.forEach(c => {
            const opt = document.createElement('option');
            opt.value = c.id;
            opt.textContent = c.nome + (c.cidade ? ` — ${c.cidade}${c.estado ? '/' + c.estado : ''}` : '');
            sel.appendChild(opt);
        });
        sel.value = atual || '';
        mostrarInfoCliente();
    }

    function mostrarInfoCliente() {
        const el = $('pedClienteInfo');
        const c = clientes.find(x => String(x.id) === $('pedCliente').value);
        const local = c && c.cidade ? c.cidade + (c.estado ? '/' + c.estado : '') : '';
        const partes = c ? [['Tel.', c.telefone], ['E-mail', c.email], ['Cidade', local], ['Obs.', c.descricao]].filter(([, v]) => v) : [];
        el.innerHTML = partes.map(([r, v]) => `<span><span class="vazio">${r}</span> ${App.esc(v)}</span>`).join('');
        el.hidden = partes.length === 0;
    }

    // ---------- Itens ----------
    function adicionarItem(item = null) {
        const produzido = item ? Number(item.quantidade_produzida) || 0 : 0;
        const qtdEstoque = item ? Number(item.quantidade_estoque) || 0 : 0;
        const prodFabrica = produzido - qtdEstoque;
        
        const div = document.createElement('div');
        div.className = 'item-linha';
        if (item && item.id) div.dataset.itemId = item.id;

        const lista = [...produtos];
        if (item && item.produto_id && !lista.some(p => Number(p.id) === Number(item.produto_id))) {
            lista.push({ id: item.produto_id, nome: item.produto_nome + ' (inativo)', preco: 0, estoque: 0 });
        }
        const opcoes = '<option value="">— selecione o produto —</option>' + lista.map(p => {
            const extra = (tipo === 'venda' && Number(p.estoque) > 0) ? ` (${p.estoque} em estoque)` : '';
            const selecionado = item && Number(p.id) === Number(item.produto_id) ? 'selected' : '';
            return `<option value="${p.id}" ${selecionado}>${App.esc(p.nome)}${extra}</option>`;
        }).join('');
        
        let badges = [];
        if (qtdEstoque > 0) badges.push(`📦 ${qtdEstoque} do estoque`);
        if (prodFabrica > 0) badges.push(`🔨 ${prodFabrica} feito${prodFabrica === 1 ? '' : 's'}`);

        div.innerHTML = `
            <select data-role="produto" aria-label="Produto" ${produzido > 0 ? 'disabled' : ''}>${opcoes}</select>
            <span class="preco-item" data-role="preco">—</span>
            <input type="number" data-role="quantidade" aria-label="Quantidade" inputmode="numeric"
                   min="${Math.max(1, produzido)}" value="${item ? item.quantidade : 1}">
            ${produzido > 0
                ? `<span class="info-produzido" title="Já atendido">${badges.join(' e ')}</span>`
                : App.botaoIcone('excluir', 'Remover produto', '', 'perigo')}`;

        const sel = div.querySelector('[data-role="produto"]');
        const atualizar = () => {
            const p = produtos.find(x => String(x.id) === sel.value);
            div.querySelector('[data-role="preco"]').textContent = p ? App.fmtMoeda.format(p.preco) : '—';
            atualizarResumo();
        };
        sel.addEventListener('change', atualizar);
        div.querySelector('[data-role="quantidade"]').addEventListener('input', atualizarResumo);
        const remover = div.querySelector('.btn-icone');
        if (remover) {
            remover.removeAttribute('onclick');
            remover.addEventListener('click', () => {
                div.remove();
                if (!$('pedItens').children.length) adicionarItem();
                atualizarResumo();
            });
        }
        $('pedItens').appendChild(div);
        atualizar();
        return div;
    }

    function atualizarResumo() {
        let itens = 0, unidades = 0, valor = 0;
        $('pedItens').querySelectorAll('.item-linha').forEach(div => {
            const p = produtos.find(x => String(x.id) === div.querySelector('[data-role="produto"]').value);
            const q = parseInt(div.querySelector('[data-role="quantidade"]').value, 10) || 0;
            if (!p) return;
            itens++;
            unidades += q;
            valor += q * Number(p.preco);
        });
        $('pedResumoItens').textContent = itens;
        $('pedResumoItensRot').textContent = itens === 1 ? 'item' : 'itens';
        $('pedResumoUnidadesRot').textContent = unidades === 1 ? 'unidade' : 'unidades';
        $('pedResumoUnidades').textContent = App.fmtInt.format(unidades);
        $('pedResumoValor').textContent = App.fmtMoeda.format(valor);
    }

    // ---------- Abrir ----------
    function configurarTipo(t) {
        tipo = t;
        const estoque = t === 'estoque';
        $('pedSecaoCliente').hidden = estoque;
        $('pedTituloEntrega').textContent = estoque ? 'Prazo' : 'Entrega';
        $('pedRotuloData').textContent = estoque ? 'Criada em' : 'Data do pedido';
        $('pedRotuloEntrega').textContent = estoque ? 'Concluir até' : 'Entrega prometida';
        $('pedObs').placeholder = estoque ? 'Ex.: reposição para feira, cor azul...' : 'Cores, acabamento, forma de entrega...';
    }

    async function abrir({ id = null, tipo: tipoNovo = 'venda', clienteId = null, aoSalvar = null } = {}) {
        try {
            if (!carregado) await carregarListas();
        } catch (e) {
            App.toast(e.message, 'erro');
            return;
        }
        editandoId = id;
        callback = aoSalvar;
        $('formPedido').reset();
        $('pedItens').innerHTML = '';
        $('pedAvisoBloqueado').hidden = true;
        $('pedSalvar').disabled = false;
        $('pedAddItem').disabled = false;
        $('pedNotaItens').hidden = true;
        $('pedUsuario').textContent = window.USUARIO.nome;
        $('pedRotuloUsuario').textContent = 'Registrado por';

        if (!id) {
            configurarTipo(tipoNovo);
            const estoque = tipoNovo === 'estoque';
            $('pedTitulo').textContent = estoque ? 'Produzir para estoque' : 'Novo pedido';
            $('pedSub').textContent = estoque
                ? 'Ordem de fabricação sem pedido de cliente. Ela aparece na visão Fabricar junto com os pedidos.'
                : 'Selecione o cliente, os produtos e a data prometida de entrega.';
            $('pedSalvar').textContent = estoque ? 'Criar ordem' : 'Salvar pedido';
            $('pedData').value = App.hoje();
            $('pedEntrega').value = estoque ? App.isoEmDias(7) : '';
            preencherClientes(clienteId ? String(clienteId) : '');
            const semProdutos = produtos.length === 0;
            $('pedAvisoSemProdutos').hidden = !semProdutos;
            $('pedSalvar').disabled = semProdutos;
            $('pedAddItem').disabled = semProdutos;
            adicionarItem();
            App.modal.abrir('modalPedido', estoque || clienteId ? '#pedItens select' : '#pedCliente');
            return;
        }

        let p;
        try {
            p = await App.api(`api/pedidos.php?id=${id}`);
        } catch (e) {
            App.toast(e.message, 'erro');
            return;
        }
        configurarTipo(p.tipo);
        const estoque = p.tipo === 'estoque';
        $('pedTitulo').textContent = estoque ? `Editar ordem de estoque #${p.id}` : `Editar pedido #${p.id}`;
        $('pedSub').textContent = estoque ? 'Produção para estoque' : (p.cliente_nome || '');
        $('pedSalvar').textContent = 'Salvar alterações';
        $('pedAvisoSemProdutos').hidden = true;
        $('pedNotaItens').hidden = !p.itens.some(i => Number(i.quantidade_produzida) > 0);
        preencherClientes(p.cliente_id ? String(p.cliente_id) : '');
        $('pedData').value = p.data_pedido;
        $('pedEntrega').value = p.data_entrega_prometida;
        $('pedObs').value = p.observacoes || '';
        $('pedRotuloUsuario').textContent = 'Criado por';
        $('pedUsuario').textContent = p.usuario_nome || '—';
        p.itens.forEach(i => adicionarItem(i));

        if (p.status === 'entregue' || p.status === 'cancelado') {
            const situacao = p.status === 'entregue' ? (estoque ? 'concluída' : 'entregue') : (estoque ? 'cancelada' : 'cancelado');
            $('pedAvisoBloqueado').textContent = `Est${estoque ? 'a ordem está' : 'e pedido está'} ${situacao} e não pode ser alterad${estoque ? 'a' : 'o'}. Reabra para editar.`;
            $('pedAvisoBloqueado').hidden = false;
            $('pedSalvar').disabled = true;
            $('pedAddItem').disabled = true;
        }
        App.modal.abrir('modalPedido', estoque ? '#pedItens input' : '#pedCliente');
    }

    // ---------- Salvar ----------
    async function salvar(ev) {
        ev.preventDefault();
        const linhas = [...$('pedItens').querySelectorAll('.item-linha')];
        const itens = linhas.map(div => {
            const item = {
                produto_id: parseInt(div.querySelector('[data-role="produto"]').value, 10),
                quantidade: parseInt(div.querySelector('[data-role="quantidade"]').value, 10),
            };
            if (div.dataset.itemId) item.id = parseInt(div.dataset.itemId, 10);
            return item;
        });

        const erro = (msg, foco) => { App.toast(msg, 'erro'); if (foco) foco.focus(); };
        if (tipo !== 'estoque' && !$('pedCliente').value) {
            return erro('Selecione o cliente (ou cadastre um novo).', $('pedCliente'));
        }
        const semProduto = linhas.find(d => !d.querySelector('[data-role="produto"]').value);
        if (semProduto) return erro('Selecione o produto em todas as linhas.', semProduto.querySelector('select'));
        const qtdInvalida = linhas.find(d => !(parseInt(d.querySelector('[data-role="quantidade"]').value, 10) > 0));
        if (qtdInvalida) return erro('As quantidades devem ser maiores que zero.', qtdInvalida.querySelector('input'));
        if (!$('pedEntrega').value) {
            return erro(tipo === 'estoque' ? 'Informe até quando a produção deve ser concluída.' : 'Informe a data prometida de entrega.', $('pedEntrega'));
        }

        const payload = {
            tipo,
            data_pedido: $('pedData').value || App.hoje(),
            data_entrega_prometida: $('pedEntrega').value,
            observacoes: $('pedObs').value.trim(),
            itens,
        };
        if (tipo !== 'estoque') payload.cliente_id = parseInt($('pedCliente').value, 10);

        const btn = $('pedSalvar');
        btn.disabled = true;
        try {
            const estoque = tipo === 'estoque';
            const nome = estoque ? 'Ordem de estoque' : 'Pedido';
            const a = estoque ? 'a' : 'o'; // concordância: criada/criado
            let r;
            if (editandoId) {
                r = await App.api(`api/pedidos.php?id=${editandoId}`, 'PUT', payload);
                App.toast(`${nome} #${editandoId} atualizad${a}.`);
            } else {
                r = await App.api('api/pedidos.php', 'POST', payload);
                const un = itens.reduce((s, i) => s + i.quantidade, 0);
                App.toast(`${nome} #${r.id} criad${a} — ${App.fmtInt.format(un)} unidade${un === 1 ? '' : 's'} para fabricar.`);
            }
            App.modal.fechar('modalPedido');
            if (callback) callback({ id: r.id || editandoId, tipo, novo: !editandoId });
        } catch (e) {
            App.toast(e.message, 'erro');
        } finally {
            btn.disabled = false;
        }
    }

    // ---------- Eventos ----------
    $('formPedido').addEventListener('submit', salvar);
    $('pedAddItem').addEventListener('click', () => adicionarItem().querySelector('select').focus());
    $('pedCliente').addEventListener('change', mostrarInfoCliente);
    $('pedNovoCliente').addEventListener('click', () => {
        FormCliente.abrir({
            aoSalvar: async c => {
                clientes = await App.api('api/clientes.php');
                preencherClientes(String(c.id));
                $('pedItens').querySelector('select:not([disabled])')?.focus();
            },
        });
    });

    return {
        abrir,
        // força recarregar produtos/clientes na próxima abertura (ex.: após cadastrar produto)
        invalidar: () => { carregado = false; },
    };
})();
