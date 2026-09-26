// Formulário de pedido em modal (pedido de cliente ou ordem de estoque).
// FormPedido.abrir({ id, tipo, clienteId, aoSalvar(resultado) })
//   id        -> editar esse pedido (o tipo vem do banco)
//   tipo      -> 'venda' (padrão) ou 'estoque' para um novo
//   clienteId -> cliente já selecionado num pedido novo
window.FormPedido = (() => {
    const $ = id => document.getElementById(id);
    let produtos = [];
    let clientes = [];
    let filamentos = [];
    let carregado = false;
    let editandoId = null;
    let tipo = 'venda';
    let callback = null;

    async function carregarListas() {
        [produtos, clientes, filamentos] = await Promise.all([
            App.api('api/produtos.php'),
            App.api('api/clientes.php'),
            App.api('api/filamentos.php').catch(() => [])
        ]);
        carregado = true;
        preencherClientes();
    }

    function obterCoresDisponiveis() {
        const cores = [];
        const nomes = new Set();
        (filamentos || []).forEach(f => {
            const c = (f.cor || '').trim();
            if (c && !nomes.has(c.toLowerCase())) {
                nomes.add(c.toLowerCase());
                cores.push({ nome: c, hex: f.cor_hex || '#6366f1' });
            }
        });
        if (!cores.length) {
            ['Azul', 'Branco', 'Verde', 'Vermelho', 'Preto', 'Amarelo', 'Cinza', 'Laranja'].forEach(c => {
                cores.push({ nome: c, hex: '#6366f1' });
            });
        }
        return cores;
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
        div.className = 'item-linha item-linha-com-variacao';
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
            <div class="item-linha-topo">
                <select data-role="produto" aria-label="Produto" ${produzido > 0 ? 'disabled' : ''}>${opcoes}</select>
                <span class="preco-item" data-role="preco" style="text-align:right;font-weight:600;">—</span>
                <input type="number" data-role="quantidade" aria-label="Quantidade" inputmode="numeric"
                       min="${Math.max(1, prodFabrica)}" value="${item ? item.quantidade : 1}">
                <div style="display:flex;align-items:center;gap:6px;">
                    ${badges.length ? `<span class="info-produzido" title="Já atendido">${badges.join(' e ')}</span>` : ''}
                    ${prodFabrica === 0 ? App.botaoIcone('excluir', 'Remover produto', '', 'perigo') : ''}
                </div>
            </div>
            <div class="item-variacao-box" data-role="variacao-box"></div>`;

        const sel = div.querySelector('[data-role="produto"]');
        const atualizar = () => {
            const p = produtos.find(x => String(x.id) === sel.value);
            div.querySelector('[data-role="preco"]').textContent = p ? App.fmtMoeda.format(p.preco) : '—';
            renderizarVariacaoItem(div, p, item);
            atualizarResumo();
        };

        sel.addEventListener('change', () => {
            // Se trocou de produto, limpa a referência do item anterior para recarregar limpo
            item = null;
            atualizar();
        });

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

    function renderizarVariacaoItem(div, p, itemSalvo = null) {
        const box = div.querySelector('[data-role="variacao-box"]');
        if (!box) return;
        if (!p) {
            box.innerHTML = '';
            box.style.display = 'none';
            return;
        }

        const coresList = obterCoresDisponiveis();
        box.style.display = 'block';

        // 1. Produto Composto com Peças
        if (p.tipo === 'composto' && p.pecas && p.pecas.length > 0) {
            let salvas = {};
            if (itemSalvo && itemSalvo.variacoes_json) {
                try {
                    const parsed = typeof itemSalvo.variacoes_json === 'string' ? JSON.parse(itemSalvo.variacoes_json) : itemSalvo.variacoes_json;
                    if (parsed && Array.isArray(parsed.pecas)) {
                        parsed.pecas.forEach(pc => {
                            if (pc.peca_id) salvas[pc.peca_id] = pc.cor;
                            if (pc.peca_nome) salvas[pc.peca_nome] = pc.cor;
                        });
                    }
                } catch(e) {}
            }

            const pecasHtml = p.pecas.map(peca => {
                const corAtual = salvas[peca.id] || salvas[peca.nome] || '';
                const corExiste = coresList.some(c => c.nome.toLowerCase() === corAtual.toLowerCase());
                const isCustom = corAtual && !corExiste;
                const opts = `<option value="">— Cor padrão —</option>` + coresList.map(c => {
                    const sel = (c.nome.toLowerCase() === corAtual.toLowerCase()) ? 'selected' : '';
                    return `<option value="${App.esc(c.nome)}" ${sel}>${App.esc(c.nome)}</option>`;
                }).join('') + `<option value="_custom_" ${isCustom ? 'selected' : ''}>+ Outra cor...</option>`;

                return `
                <div class="campo-peca-cor" data-peca-id="${peca.id}" data-peca-nome="${App.esc(peca.nome)}" data-peca-peso="${peca.peso_gramas || 0}" data-peca-qtd="${peca.quantidade || 1}">
                    <label>
                        🧩 ${App.esc(peca.nome)}
                        <span style="font-weight:normal;color:var(--text-3);font-size:11px;">(${peca.quantidade} un · ${peca.peso_gramas}g)</span>
                    </label>
                    <select data-role="cor-peca" style="width:100%;font-size:12px;padding:4px 6px;">
                        ${opts}
                    </select>
                    <input type="text" data-role="cor-peca-custom" placeholder="Digitar cor" style="width:100%;font-size:12px;padding:3px 6px;margin-top:3px;${isCustom ? '' : 'display:none;'}" value="${isCustom ? App.esc(corAtual) : ''}">
                </div>`;
            }).join('');

            box.innerHTML = `
                <div class="bloco-pecas-cores">
                    <div style="font-size:12px;font-weight:600;color:var(--text-1);margin-bottom:6px;display:flex;align-items:center;gap:6px;">
                        <span>🧩 Variação de Cores das Peças:</span>
                        <span style="font-weight:normal;font-size:11.5px;color:var(--text-3);">O estoque de filamento será baixado por cor.</span>
                    </div>
                    <div class="grade-pecas-cores">${pecasHtml}</div>
                </div>`;

            box.querySelectorAll('.campo-peca-cor').forEach(cp => {
                const s = cp.querySelector('[data-role="cor-peca"]');
                const inp = cp.querySelector('[data-role="cor-peca-custom"]');
                s.addEventListener('change', () => {
                    inp.style.display = (s.value === '_custom_') ? 'block' : 'none';
                    if (s.value === '_custom_') inp.focus();
                    atualizarResumo();
                });
                inp.addEventListener('input', atualizarResumo);
            });
            return;
        }

        // 2. Produto Simples
        // Verifica se é multicor AMS (mais de 1 cor fixa configurada no cadastro)
        const multicorAMS = p.consumo_cores && p.consumo_cores.length > 1;
        if (multicorAMS) {
            box.innerHTML = `
                <div style="font-size:12px;color:var(--text-2);margin-top:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <span>🎨 Multicor (AMS):</span>
                    ${p.consumo_cores.map(c => `<span class="chip-cor-diag" style="font-size:11px;">${App.esc(c.cor)}: <b>${c.gramas_1un}g</b></span>`).join(' ')}
                </div>`;
            return;
        }

        // Produto Simples Monocor (permite selecionar a cor do filamento)
        const corSalva = (itemSalvo && itemSalvo.cor_variacao) ? itemSalvo.cor_variacao.trim() : '';
        const corExiste = coresList.some(c => c.nome.toLowerCase() === corSalva.toLowerCase());
        const isCustom = corSalva && !corExiste;

        const opts = `<option value="">— Cor padrão / qualquer —</option>` + coresList.map(c => {
            const sel = (c.nome.toLowerCase() === corSalva.toLowerCase()) ? 'selected' : '';
            return `<option value="${App.esc(c.nome)}" ${sel}>${App.esc(c.nome)}</option>`;
        }).join('') + `<option value="_custom_" ${isCustom ? 'selected' : ''}>+ Digitar outra cor...</option>`;

        box.innerHTML = `
            <div style="display:flex;align-items:center;gap:8px;margin-top:4px;font-size:12px;flex-wrap:wrap;">
                <span style="font-weight:600;color:var(--text-2);">🎨 Cor da Peça:</span>
                <select data-role="cor-simples" style="font-size:12px;padding:4px 8px;max-width:220px;">
                    ${opts}
                </select>
                <input type="text" data-role="cor-simples-custom" placeholder="Nome da cor" style="font-size:12px;padding:4px 8px;max-width:140px;${isCustom ? '' : 'display:none;'}" value="${isCustom ? App.esc(corSalva) : ''}">
                <span style="color:var(--text-3);font-size:11.5px;">(baixa do carretel da cor selecionada)</span>
            </div>`;

        const s = box.querySelector('[data-role="cor-simples"]');
        const inp = box.querySelector('[data-role="cor-simples-custom"]');
        s.addEventListener('change', () => {
            inp.style.display = (s.value === '_custom_') ? 'block' : 'none';
            if (s.value === '_custom_') inp.focus();
            atualizarResumo();
        });
        inp.addEventListener('input', atualizarResumo);
    }

    function atualizarResumo() {
        let itens = 0, unidades = 0, valor = 0;
        let tempoSegundos = 0, pesoGramas = 0;
        const coresTotais = {};

        $('pedItens').querySelectorAll('.item-linha').forEach(div => {
            const p = produtos.find(x => String(x.id) === div.querySelector('[data-role="produto"]').value);
            const q = parseInt(div.querySelector('[data-role="quantidade"]').value, 10) || 0;
            if (!p || q <= 0) return;
            itens++;
            unidades += q;
            valor += q * Number(p.preco);

            const t1 = Number(p.tempo_producao_segundos) || 0;
            const w1 = Number(p.peso_gramas) || 0;
            tempoSegundos += t1 * q;
            pesoGramas += w1 * q;

            // Se for composto com peças:
            if (p.tipo === 'composto' && p.pecas && p.pecas.length > 0) {
                div.querySelectorAll('.campo-peca-cor').forEach(cp => {
                    const selCor = cp.querySelector('[data-role="cor-peca"]');
                    const inpCustom = cp.querySelector('[data-role="cor-peca-custom"]');
                    let cor = selCor ? selCor.value : '';
                    if (cor === '_custom_') cor = inpCustom ? inpCustom.value.trim() : '';
                    if (!cor) cor = 'Cor Padrão';
                    const pesoPeca = Number(cp.dataset.pecaPeso) || 0;
                    const g = pesoPeca * q;
                    if (g > 0) coresTotais[cor] = (coresTotais[cor] || 0) + g;
                });
            } else {
                // Se for simples:
                const selCor = div.querySelector('[data-role="cor-simples"]');
                const inpCustom = div.querySelector('[data-role="cor-simples-custom"]');
                let cor = selCor ? selCor.value : '';
                if (cor === '_custom_') cor = inpCustom ? inpCustom.value.trim() : '';

                if (cor) {
                    coresTotais[cor] = (coresTotais[cor] || 0) + (w1 * q);
                } else {
                    const coresArr = p.consumo_cores || [];
                    if (coresArr.length) {
                        coresArr.forEach(c => {
                            const cNome = c.cor || 'qualquer cor';
                            const g = (Number(c.gramas_1un) || 0) * q;
                            coresTotais[cNome] = (coresTotais[cNome] || 0) + g;
                        });
                    } else if (w1 > 0) {
                        coresTotais['qualquer cor'] = (coresTotais['qualquer cor'] || 0) + (w1 * q);
                    }
                }
            }
        });

        $('pedResumoItens').textContent = itens;
        $('pedResumoItensRot').textContent = itens === 1 ? 'item' : 'itens';
        $('pedResumoUnidadesRot').textContent = unidades === 1 ? 'unidade' : 'unidades';
        $('pedResumoUnidades').textContent = App.fmtInt.format(unidades);
        $('pedResumoValor').textContent = App.fmtMoeda.format(valor);

        // Tempo futuro em HH:mm:ss
        if ($('pedResumoTempo')) {
            const s = Math.round(tempoSegundos);
            const horas = Math.floor(s / 3600);
            const resto = s % 3600;
            const minutos = Math.floor(resto / 60);
            const segs = resto % 60;
            $('pedResumoTempo').textContent = `${String(horas).padStart(2, '0')}:${String(minutos).padStart(2, '0')}:${String(segs).padStart(2, '0')}`;
        }
        if ($('pedResumoFilamento')) {
            $('pedResumoFilamento').textContent = `${Math.round(pesoGramas * 10) / 10} g`;
        }
        if ($('pedCoresDistribuicao')) {
            const entries = Object.entries(coresTotais).filter(([, g]) => g > 0);
            if (entries.length) {
                $('pedCoresDistribuicao').innerHTML = '<span>🎨 Previsão por Cor:</span>' + entries.map(([c, g]) =>
                    `<span class="chip-cor-diag" style="font-size:11px;background:#e0e7ff;color:#3730a3;border-color:#c7d2fe;">${App.esc(c)}: <b>${Math.round(g * 10) / 10}g</b></span>`
                ).join(' ');
                $('pedCoresDistribuicao').hidden = false;
            } else {
                $('pedCoresDistribuicao').innerHTML = '';
                $('pedCoresDistribuicao').hidden = true;
            }
        }
    }

    // ---------- Abrir ----------
    function configurarTipo(t) {
        tipo = t;
        const estoque = t === 'estoque';
        $('pedSecaoCliente').hidden = estoque;
        $('pedTituloEntrega').textContent = estoque ? 'Prazo' : 'Entrega';
        $('pedRotuloData').textContent = estoque ? 'Criada em' : 'Data do pedido';
        $('pedRotuloEntrega').textContent = estoque ? 'Concluir até' : 'Entrega prometida';
        $('pedObs').placeholder = estoque ? 'Ex.: reposição para estoque, lote especial...' : 'Cores, acabamento, forma de entrega...';
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
            const p = produtos.find(x => String(x.id) === div.querySelector('[data-role="produto"]').value);
            const item = {
                produto_id: parseInt(div.querySelector('[data-role="produto"]').value, 10),
                quantidade: parseInt(div.querySelector('[data-role="quantidade"]').value, 10),
            };
            if (div.dataset.itemId) item.id = parseInt(div.dataset.itemId, 10);

            if (p && p.tipo === 'composto' && p.pecas && p.pecas.length > 0) {
                const pecasCores = [];
                div.querySelectorAll('.campo-peca-cor').forEach(cp => {
                    const selCor = cp.querySelector('[data-role="cor-peca"]');
                    const inpCustom = cp.querySelector('[data-role="cor-peca-custom"]');
                    let cor = selCor ? selCor.value : '';
                    if (cor === '_custom_') cor = inpCustom ? inpCustom.value.trim() : '';
                    pecasCores.push({
                        peca_id: parseInt(cp.dataset.pecaId, 10),
                        peca_nome: cp.dataset.pecaNome,
                        peso_gramas: parseFloat(cp.dataset.pecaPeso) || 0,
                        quantidade: parseInt(cp.dataset.pecaQtd, 10) || 1,
                        cor: cor || 'Padrão'
                    });
                });
                item.cor_variacao = pecasCores.map(pc => `${pc.peca_nome}: ${pc.cor}`).join(' | ');
                item.variacoes_json = { tipo: 'composto', pecas: pecasCores };
            } else if (p) {
                const selCor = div.querySelector('[data-role="cor-simples"]');
                const inpCustom = div.querySelector('[data-role="cor-simples-custom"]');
                let cor = selCor ? selCor.value : '';
                if (cor === '_custom_') cor = inpCustom ? inpCustom.value.trim() : '';
                item.cor_variacao = cor || null;
                item.variacoes_json = cor ? { tipo: 'simples', cor } : null;
            }

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
        invalidar: () => { carregado = false; },
    };
})();
