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
    let itemEmEdicaoCores = null;

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
        const partes = c ? [['Tel.', App.formatarTelefone(c.telefone)], ['E-mail', c.email], ['Cidade', local], ['Obs.', c.descricao]].filter(([, v]) => v) : [];
        el.innerHTML = partes.map(([r, v]) => `<span><span class="vazio">${r}</span> ${App.esc(v)}</span>`).join('');
        el.hidden = partes.length === 0;
    }

    // ---------- Cores e Variações ----------

    function inicializarCoresItem(div, p, itemSalvo = null) {
        if (!p) {
            div._configCores = null;
            return;
        }
        const coresList = obterCoresDisponiveis();

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
            } else if (itemSalvo && itemSalvo.cor_variacao) {
                // Tenta extrair pares "Nome: Cor" da string antiga se houver
                const partes = itemSalvo.cor_variacao.split('|');
                partes.forEach(pt => {
                    const idx = pt.indexOf(':');
                    if (idx > -1) {
                        const k = pt.slice(0, idx).trim();
                        const v = pt.slice(idx + 1).trim();
                        if (k && v) salvas[k] = v;
                    }
                });
            }

            const pecas = p.pecas.map(peca => {
                const pecaId = peca.peca_id || peca.id;
                const coresCadastradas = (peca.cores || [])
                    .map(c => typeof c === 'string' ? c.trim() : (c && c.cor ? String(c.cor).trim() : ''))
                    .filter(c => c && c.toLowerCase() !== 'null' && c !== 'Padrão / Única' && c !== 'Padrão');

                const corPadrao = coresCadastradas.length > 0 ? coresCadastradas[0] : (coresList[0]?.nome || 'Branco');
                const corSalva = salvas[pecaId] || salvas[peca.nome] || '';
                const corAtual = corSalva || corPadrao;

                return {
                    peca_id: pecaId,
                    peca_nome: peca.nome,
                    peso_gramas: Number(peca.peso_gramas) || 0,
                    quantidade: Number(peca.quantidade) || 1,
                    corPadrao: corPadrao,
                    cor: corAtual,
                    coresCadastradas: coresCadastradas
                };
            });

            div._configCores = { tipo: 'composto', pecas };
            return;
        }

        // 2. Multicor AMS
        const multicorAMS = p.consumo_cores && p.consumo_cores.length > 1;
        if (multicorAMS) {
            div._configCores = { tipo: 'multicor_ams', consumo_cores: p.consumo_cores };
            return;
        }

        // 3. Produto Simples Monocor
        const coresProd = (p.consumo_cores || [])
            .map(c => typeof c === 'string' ? c.trim() : (c && c.cor ? String(c.cor).trim() : ''))
            .filter(c => c && c !== 'Padrão / Única' && c !== 'Padrão');

        const corPadrao = coresProd.length > 0 ? coresProd[0] : (coresList[0]?.nome || 'Branco');
        const corSalva = (itemSalvo && itemSalvo.cor_variacao) ? itemSalvo.cor_variacao.trim() : '';
        const corAtual = corSalva || corPadrao;

        div._configCores = {
            tipo: 'simples',
            corPadrao: corPadrao,
            cor: corAtual,
            coresCadastradas: coresProd
        };
    }

    function renderizarBarraCoresItem(div, p) {
        const box = div.querySelector('[data-role="variacao-box"]');
        if (!box) return;
        if (!p || !div._configCores) {
            box.innerHTML = '';
            box.style.display = 'none';
            return;
        }

        box.style.display = 'block';
        const cfg = div._configCores;

        if (cfg.tipo === 'composto') {
            const pecas = cfg.pecas || [];
            const todasPadrao = pecas.every(pc => pc.cor.toLowerCase() === pc.corPadrao.toLowerCase());
            const resumoPills = pecas.map(pc => `
                <span class="chip-peca-cor-resumo" title="${App.esc(pc.peca_nome)}: ${App.esc(pc.cor)}">
                    <span class="chip-p-nome">${App.esc(pc.peca_nome)}:</span>
                    <strong class="chip-p-cor">${App.esc(pc.cor)}</strong>
                </span>
            `).join('');

            box.innerHTML = `
                <div class="item-variacao-resumo-bar">
                    <div class="resumo-cores-info">
                        <span class="tag-status-cor ${todasPadrao ? 'tag-padrao' : 'tag-customizada'}">
                            ${todasPadrao ? '⭐ Padrão' : '✨ Personalizado'}
                        </span>
                        <div class="grade-pills-resumo">${resumoPills}</div>
                    </div>
                    <button type="button" class="btn-editar-cores-item" data-role="btn-editar-cores" title="Ver ou alterar cores das peças">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.563-2.512 5.563-5.563C22 6.5 17.5 2 12 2Z"/></svg>
                        Editar cores
                    </button>
                </div>
            `;
            box.querySelector('[data-role="btn-editar-cores"]').addEventListener('click', () => abrirModalCoresItem(div, p));
            return;
        }

        if (cfg.tipo === 'multicor_ams') {
            const lista = (cfg.consumo_cores || []).map(c => `<span class="chip-cor-diag" style="font-size:11px;">${App.esc(c.cor)}: <b>${c.gramas_1un}g</b></span>`).join(' ');
            box.innerHTML = `
                <div class="item-variacao-resumo-bar">
                    <div class="resumo-cores-info">
                        <span class="tag-status-cor tag-padrao">🎨 Multicor AMS</span>
                        <div class="grade-pills-resumo">${lista}</div>
                    </div>
                </div>
            `;
            return;
        }

        // Simples Monocor
        const ehPadrao = cfg.cor.toLowerCase() === cfg.corPadrao.toLowerCase();
        box.innerHTML = `
            <div class="item-variacao-resumo-bar">
                <div class="resumo-cores-info">
                    <span class="tag-status-cor ${ehPadrao ? 'tag-padrao' : 'tag-customizada'}">
                        ${ehPadrao ? '⭐ Padrão' : '✨ Personalizada'}
                    </span>
                    <span class="chip-peca-cor-resumo">
                        <span class="chip-p-nome">Cor do produto:</span>
                        <strong class="chip-p-cor">${App.esc(cfg.cor)}</strong>
                    </span>
                </div>
                <button type="button" class="btn-editar-cores-item" data-role="btn-editar-cores" title="Alterar a cor deste produto">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:14px;height:14px;"><circle cx="13.5" cy="6.5" r=".5" fill="currentColor"/><circle cx="17.5" cy="10.5" r=".5" fill="currentColor"/><circle cx="8.5" cy="7.5" r=".5" fill="currentColor"/><circle cx="6.5" cy="12.5" r=".5" fill="currentColor"/><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.926 0 1.648-.746 1.648-1.688 0-.437-.18-.835-.437-1.125-.29-.289-.438-.652-.438-1.125a1.64 1.64 0 0 1 1.668-1.668h1.996c3.051 0 5.563-2.512 5.563-5.563C22 6.5 17.5 2 12 2Z"/></svg>
                    Editar cor
                </button>
            </div>
        `;
        box.querySelector('[data-role="btn-editar-cores"]').addEventListener('click', () => abrirModalCoresItem(div, p));
    }

    function abrirModalCoresItem(div, p) {
        if (!div || !p || !div._configCores) return;
        itemEmEdicaoCores = { div, p };

        const tit = $('titEditarCoresItem');
        const sub = $('subEditarCoresItem');
        const corpo = $('modalCoresListaPecas');
        const aviso = $('modalCoresAviso');
        aviso.hidden = true;

        tit.textContent = `Personalizar Cores: ${p.nome}`;
        sub.textContent = `As cores padrão cadastradas já vêm selecionadas. Ajuste somente se o cliente desejar uma combinação específica.`;

        const coresList = obterCoresDisponiveis();
        const cfg = div._configCores;

        if (cfg.tipo === 'composto') {
            corpo.innerHTML = cfg.pecas.map((peca, idx) => {
                const coresCadastradas = peca.coresCadastradas || [];
                const coresAdicionadas = new Set();
                let opts = '';

                // 1. Cores cadastradas na peça (Destaques)
                coresCadastradas.forEach((cNome, i) => {
                    coresAdicionadas.add(cNome.toLowerCase());
                    const isSel = (cNome.toLowerCase() === peca.cor.toLowerCase());
                    const tag = (cNome.toLowerCase() === peca.corPadrao.toLowerCase()) ? ' (Padrão do produto)' : ' (Opção cadastrada)';
                    opts += `<option value="${App.esc(cNome)}" ${isSel ? 'selected' : ''}>⭐ ${App.esc(cNome)}${tag}</option>`;
                });

                if (peca.corPadrao && !coresAdicionadas.has(peca.corPadrao.toLowerCase())) {
                    coresAdicionadas.add(peca.corPadrao.toLowerCase());
                    const isSel = (peca.corPadrao.toLowerCase() === peca.cor.toLowerCase());
                    opts += `<option value="${App.esc(peca.corPadrao)}" ${isSel ? 'selected' : ''}>⭐ ${App.esc(peca.corPadrao)} (Padrão)</option>`;
                }

                // 2. Cores do catálogo de filamentos
                coresList.forEach(c => {
                    if (!coresAdicionadas.has(c.nome.toLowerCase())) {
                        coresAdicionadas.add(c.nome.toLowerCase());
                        const isSel = (c.nome.toLowerCase() === peca.cor.toLowerCase());
                        opts += `<option value="${App.esc(c.nome)}" ${isSel ? 'selected' : ''}>${App.esc(c.nome)}</option>`;
                    }
                });

                // 3. Digitar outra cor
                const isCustom = peca.cor && !coresAdicionadas.has(peca.cor.toLowerCase());
                opts += `<option value="_custom_" ${isCustom ? 'selected' : ''}>+ Digitar outra cor...</option>`;

                return `
                    <div class="campo-modal-peca-cor" data-peca-idx="${idx}" style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:11px 13px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;flex-wrap:wrap;gap:4px;">
                            <span style="font-weight:600;font-size:13px;color:var(--text-1);">
                                🧩 ${App.esc(peca.peca_nome)}
                                <small style="color:var(--text-3);font-weight:normal;">(${peca.quantidade} un · ${peca.peso_gramas}g)</small>
                            </span>
                            <span style="font-size:11.5px;color:var(--text-3);">
                                Padrão: <b style="color:var(--text-2);">${App.esc(peca.corPadrao)}</b>
                            </span>
                        </div>
                        <select data-role="submodal-cor" style="width:100%;font-size:12.5px;padding:6px 10px;border-radius:var(--radius-sm);">
                            ${opts}
                        </select>
                        <input type="text" data-role="submodal-cor-custom" placeholder="Digitar nome da cor..." style="width:100%;font-size:12.5px;padding:6px 10px;margin-top:6px;border-radius:var(--radius-sm);${isCustom ? '' : 'display:none;'}" value="${isCustom ? App.esc(peca.cor) : ''}">
                    </div>
                `;
            }).join('');
        } else if (cfg.tipo === 'simples') {
            const coresCadastradas = cfg.coresCadastradas || [];
            const coresAdicionadas = new Set();
            let opts = '';

            coresCadastradas.forEach((cNome, i) => {
                coresAdicionadas.add(cNome.toLowerCase());
                const isSel = (cNome.toLowerCase() === cfg.cor.toLowerCase());
                const tag = (cNome.toLowerCase() === cfg.corPadrao.toLowerCase()) ? ' (Padrão do produto)' : ' (Opção cadastrada)';
                opts += `<option value="${App.esc(cNome)}" ${isSel ? 'selected' : ''}>⭐ ${App.esc(cNome)}${tag}</option>`;
            });

            if (cfg.corPadrao && !coresAdicionadas.has(cfg.corPadrao.toLowerCase())) {
                coresAdicionadas.add(cfg.corPadrao.toLowerCase());
                const isSel = (cfg.corPadrao.toLowerCase() === cfg.cor.toLowerCase());
                opts += `<option value="${App.esc(cfg.corPadrao)}" ${isSel ? 'selected' : ''}>⭐ ${App.esc(cfg.corPadrao)} (Padrão)</option>`;
            }

            coresList.forEach(c => {
                if (!coresAdicionadas.has(c.nome.toLowerCase())) {
                    coresAdicionadas.add(c.nome.toLowerCase());
                    const isSel = (c.nome.toLowerCase() === cfg.cor.toLowerCase());
                    opts += `<option value="${App.esc(c.nome)}" ${isSel ? 'selected' : ''}>${App.esc(c.nome)}</option>`;
                }
            });

            const isCustom = cfg.cor && !coresAdicionadas.has(cfg.cor.toLowerCase());
            opts += `<option value="_custom_" ${isCustom ? 'selected' : ''}>+ Digitar outra cor...</option>`;

            corpo.innerHTML = `
                <div class="campo-modal-peca-cor" style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:13px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <span style="font-weight:600;font-size:13px;color:var(--text-1);">🎨 Cor do Produto:</span>
                        <span style="font-size:11.5px;color:var(--text-3);">Padrão: <b style="color:var(--text-2);">${App.esc(cfg.corPadrao)}</b></span>
                    </div>
                    <select data-role="submodal-cor" style="width:100%;font-size:12.5px;padding:6px 10px;border-radius:var(--radius-sm);">
                        ${opts}
                    </select>
                    <input type="text" data-role="submodal-cor-custom" placeholder="Digitar nome da cor..." style="width:100%;font-size:12.5px;padding:6px 10px;margin-top:6px;border-radius:var(--radius-sm);${isCustom ? '' : 'display:none;'}" value="${isCustom ? App.esc(cfg.cor) : ''}">
                </div>
            `;
        }

        corpo.querySelectorAll('.campo-modal-peca-cor').forEach(bloco => {
            const s = bloco.querySelector('[data-role="submodal-cor"]');
            const inp = bloco.querySelector('[data-role="submodal-cor-custom"]');
            s.addEventListener('change', () => {
                if (s.value === '_custom_') {
                    inp.style.display = 'block';
                    inp.focus();
                } else {
                    inp.style.display = 'none';
                }
            });
        });

        App.modal.abrir('modalEditarCoresItem');
    }

    // ---------- Linha de Item do Formulário ----------
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
                       min="1" value="${item ? item.quantidade : 1}">
                <div style="display:flex;align-items:center;gap:4px;">
                    ${badges.length ? `<span class="info-produzido" title="Já atendido">${badges.join(' e ')}</span>` : ''}
                    <button type="button" class="btn-icone secundario btn-duplicar-item" data-tip="Duplicar este item (mesmo produto)" aria-label="Duplicar item">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="13" height="13" x="9" y="9" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                    </button>
                    <button type="button" class="btn-icone perigo btn-remover-item" data-tip="Remover produto do pedido" aria-label="Remover produto">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/></svg>
                    </button>
                </div>
            </div>
            <div class="item-variacao-box" data-role="variacao-box"></div>`;

        const sel = div.querySelector('[data-role="produto"]');
        const atualizar = () => {
            const p = produtos.find(x => String(x.id) === sel.value);
            div.querySelector('[data-role="preco"]').textContent = p ? App.fmtMoeda.format(p.preco) : '—';
            inicializarCoresItem(div, p, item);
            renderizarBarraCoresItem(div, p);
            atualizarResumo();
        };

        sel.addEventListener('change', () => {
            item = null;
            atualizar();
        });

        div.querySelector('[data-role="quantidade"]').addEventListener('input', atualizarResumo);

        const btnDuplicar = div.querySelector('.btn-duplicar-item');
        if (btnDuplicar) {
            btnDuplicar.addEventListener('click', () => {
                const prodId = sel.value;
                if (!prodId) {
                    App.toast('Selecione primeiro um produto antes de duplicar.', 'alerta');
                    return;
                }
                const qtd = parseInt(div.querySelector('[data-role="quantidade"]').value, 10) || 1;
                const copiaItem = {
                    produto_id: parseInt(prodId, 10),
                    quantidade: qtd
                };
                if (div._configCores) {
                    if (div._configCores.tipo === 'composto') {
                        copiaItem.variacoes_json = {
                            tipo: 'composto',
                            pecas: JSON.parse(JSON.stringify(div._configCores.pecas))
                        };
                        copiaItem.cor_variacao = div._configCores.pecas.map(pc => `${pc.peca_nome}: ${pc.cor}`).join(' | ');
                    } else if (div._configCores.tipo === 'simples') {
                        copiaItem.cor_variacao = div._configCores.cor;
                    }
                }
                const novaLinha = adicionarItem(copiaItem);
                novaLinha.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                App.toast('Item duplicado! Se desejar outra cor, basta clicar em "Editar cores".', 'info');
            });
        }

        const remover = div.querySelector('.btn-remover-item');
        if (remover) {
            remover.addEventListener('click', async () => {
                if (produzido > 0) {
                    const selProd = div.querySelector('[data-role="produto"]');
                    const p = produtos.find(x => String(x.id) === selProd.value);
                    const nomeProd = p ? p.nome : 'este produto';
                    const ok = await App.confirmar(
                        `Este item possui ${produzido} unidade(s) já atendida(s)/produzida(s). Ao remover, essas unidades serão devolvidas ao estoque de produtos acabados.\n\nDeseja remover "${nomeProd}" deste pedido?`,
                        { titulo: 'Remover produto do pedido', botao: 'Sim, remover e devolver ao estoque', perigo: true }
                    );
                    if (!ok) return;
                }
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

            const cfg = div._configCores;
            if (p.tipo === 'composto' && cfg && cfg.pecas) {
                cfg.pecas.forEach(pc => {
                    const cor = pc.cor || pc.corPadrao || 'Cor Padrão';
                    const pesoPeca = Number(pc.peso_gramas) || 0;
                    const qtdPecaPorProd = Number(pc.quantidade) || 1;
                    const g = pesoPeca * qtdPecaPorProd * q;
                    if (g > 0) coresTotais[cor] = (coresTotais[cor] || 0) + g;
                });
            } else if (cfg && cfg.tipo === 'simples') {
                const cor = cfg.cor || cfg.corPadrao || 'Cor Padrão';
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

    // ---------- Abrir Modal Principal ----------
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
        if ($('pedAddItemAbaixo')) $('pedAddItemAbaixo').disabled = false;
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
            if ($('pedAddItemAbaixo')) $('pedAddItemAbaixo').disabled = semProdutos;
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
            if ($('pedAddItemAbaixo')) $('pedAddItemAbaixo').disabled = true;
        }
        App.modal.abrir('modalPedido', estoque ? '#pedItens input' : '#pedCliente');
    }

    async function salvar(ev) {
        ev.preventDefault();
        const erro = (msg, foco) => { App.toast(msg, 'erro'); if (foco) foco.focus(); };
        if (tipo !== 'estoque' && !$('pedCliente').value) {
            return erro('Selecione o cliente (ou cadastre um novo).', $('pedCliente'));
        }

        const linhas = [...$('pedItens').querySelectorAll('.item-linha')];
        const semProduto = linhas.find(d => !d.querySelector('[data-role="produto"]').value);
        if (semProduto) return erro('Selecione o produto em todas as linhas.', semProduto.querySelector('select'));
        const qtdInvalida = linhas.find(d => !(parseInt(d.querySelector('[data-role="quantidade"]').value, 10) > 0));
        if (qtdInvalida) return erro('As quantidades devem ser maiores que zero.', qtdInvalida.querySelector('input'));
        if (!$('pedEntrega').value) {
            return erro(tipo === 'estoque' ? 'Informe até quando a produção deve ser concluída.' : 'Informe a data prometida de entrega.', $('pedEntrega'));
        }

        const itens = linhas.map(div => {
            const p = produtos.find(x => String(x.id) === div.querySelector('[data-role="produto"]').value);
            const item = {
                produto_id: parseInt(div.querySelector('[data-role="produto"]').value, 10),
                quantidade: parseInt(div.querySelector('[data-role="quantidade"]').value, 10),
            };
            if (div.dataset.itemId) item.id = parseInt(div.dataset.itemId, 10);

            const cfg = div._configCores;
            if (p && cfg) {
                if (cfg.tipo === 'composto' && cfg.pecas) {
                    item.cor_variacao = cfg.pecas.map(pc => `${pc.peca_nome}: ${pc.cor}`).join(' | ');
                    item.variacoes_json = {
                        tipo: 'composto',
                        pecas: cfg.pecas.map(pc => ({
                            peca_id: pc.peca_id,
                            peca_nome: pc.peca_nome,
                            peso_gramas: pc.peso_gramas,
                            quantidade: pc.quantidade,
                            cor: pc.cor
                        }))
                    };
                } else if (cfg.tipo === 'multicor_ams') {
                    item.cor_variacao = (p.consumo_cores || []).map(c => c.cor).join(' + ');
                    item.variacoes_json = { tipo: 'multicor_ams', cores: p.consumo_cores };
                } else if (cfg.tipo === 'simples') {
                    item.cor_variacao = cfg.cor || null;
                    item.variacoes_json = cfg.cor ? { tipo: 'simples', cor: cfg.cor } : null;
                }
            }

            return item;
        });

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
            const a = estoque ? 'a' : 'o';
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
    $('pedAddItem').addEventListener('click', () => {
        const row = adicionarItem();
        row.querySelector('select')?.focus();
    });

    const btnAbaixo = $('pedAddItemAbaixo');
    if (btnAbaixo) {
        btnAbaixo.addEventListener('click', () => {
            const row = adicionarItem();
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            row.querySelector('select')?.focus();
        });
    }

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

    // Eventos do Sub-Modal de Cores
    const btnSalvarCores = $('btnSalvarCoresItem');
    if (btnSalvarCores) {
        btnSalvarCores.addEventListener('click', () => {
            if (!itemEmEdicaoCores || !itemEmEdicaoCores.div) return;
            const { div, p } = itemEmEdicaoCores;
            const cfg = div._configCores;
            const blocos = $('modalCoresListaPecas').querySelectorAll('.campo-modal-peca-cor');

            if (cfg.tipo === 'composto') {
                blocos.forEach(bloco => {
                    const idx = parseInt(bloco.dataset.pecaIdx, 10);
                    const sel = bloco.querySelector('[data-role="submodal-cor"]');
                    const inp = bloco.querySelector('[data-role="submodal-cor-custom"]');
                    let cor = sel.value;
                    if (cor === '_custom_') cor = inp.value.trim();
                    if (!cor) cor = cfg.pecas[idx].corPadrao || 'Branco';
                    cfg.pecas[idx].cor = cor;
                });
            } else if (cfg.tipo === 'simples') {
                const bloco = blocos[0];
                if (bloco) {
                    const sel = bloco.querySelector('[data-role="submodal-cor"]');
                    const inp = bloco.querySelector('[data-role="submodal-cor-custom"]');
                    let cor = sel.value;
                    if (cor === '_custom_') cor = inp.value.trim();
                    if (!cor) cor = cfg.corPadrao || 'Branco';
                    cfg.cor = cor;
                }
            }

            renderizarBarraCoresItem(div, p);
            atualizarResumo();
            App.modal.fechar('modalEditarCoresItem');
            App.toast('Cores atualizadas para este item.', 'sucesso');
        });
    }

    const btnRestaurarCores = $('btnRestaurarCoresItem');
    if (btnRestaurarCores) {
        btnRestaurarCores.addEventListener('click', () => {
            if (!itemEmEdicaoCores || !itemEmEdicaoCores.div) return;
            const cfg = itemEmEdicaoCores.div._configCores;
            const blocos = $('modalCoresListaPecas').querySelectorAll('.campo-modal-peca-cor');

            if (cfg.tipo === 'composto') {
                blocos.forEach(bloco => {
                    const idx = parseInt(bloco.dataset.pecaIdx, 10);
                    const corPadrao = cfg.pecas[idx].corPadrao;
                    const sel = bloco.querySelector('[data-role="submodal-cor"]');
                    const inp = bloco.querySelector('[data-role="submodal-cor-custom"]');
                    let achou = false;
                    for (let i = 0; i < sel.options.length; i++) {
                        if (sel.options[i].value.toLowerCase() === corPadrao.toLowerCase()) {
                            sel.selectedIndex = i;
                            achou = true;
                            break;
                        }
                    }
                    if (!achou) sel.selectedIndex = 0;
                    inp.style.display = 'none';
                });
            } else if (cfg.tipo === 'simples') {
                const bloco = blocos[0];
                if (bloco) {
                    const sel = bloco.querySelector('[data-role="submodal-cor"]');
                    const inp = bloco.querySelector('[data-role="submodal-cor-custom"]');
                    let achou = false;
                    for (let i = 0; i < sel.options.length; i++) {
                        if (sel.options[i].value.toLowerCase() === cfg.corPadrao.toLowerCase()) {
                            sel.selectedIndex = i;
                            achou = true;
                            break;
                        }
                    }
                    if (!achou) sel.selectedIndex = 0;
                    inp.style.display = 'none';
                }
            }
        });
    }

    document.querySelectorAll('[data-fechar-modal-cores]').forEach(btn => {
        btn.addEventListener('click', () => App.modal.fechar('modalEditarCoresItem'));
    });

    return {
        abrir,
        invalidar: () => { carregado = false; },
    };
})();
