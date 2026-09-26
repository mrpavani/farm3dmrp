// Módulo Produtos: lista + cadastro/edição em modal + Ficha Técnica (BOM)
let produtos = [];
let editandoId = null;
let bomProdutoAtualId = null;
let bomPecasDisponiveis = [];
let bomLinhasEditor = [];

// Estado do modal de cadastro/edição de produto
let modalProdCores = [];
let modalProdPecas = [];

const $ = id => document.getElementById(id);
const esc = App.esc;

async function carregar() {
    try {
        produtos = await App.api('api/produtos.php?todos=1');
    } catch (e) {
        App.toast(e.message, 'erro');
        produtos = [];
    }
    renderizar();
}

function badgeTipo(tipo) {
    if (tipo === 'composto') return '<span class="badge-tipo composto">Composto</span>';
    if (tipo === 'componente') return '<span class="badge-tipo componente">Peça</span>';
    return '<span class="badge-tipo simples">Simples</span>';
}

function renderizar() {
    const termo = App.normalizar($('busca').value.trim());
    const lista = produtos.filter(p =>
        !termo || App.normalizar(`${p.nome} ${p.descricao || ''} ${p.tipo || ''}`).includes(termo));

    const ativos = produtos.filter(p => Number(p.ativo) === 1).length;
    $('rodape').textContent = `${lista.length} de ${produtos.length} produtos · ${ativos} ativos · produtos com pedidos ou peças não podem ser excluídos (desative-os)`;

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
        <tr class="${usado ? '' : 'linha-inativa'}">
            <td>
                <strong>${esc(p.nome)}</strong>
                ${badgeTipo(p.tipo)}
                ${p.descricao ? `<span class="sub-linha descricao-curta" title="${esc(p.descricao)}">${esc(p.descricao)}</span>` : ''}
                ${(Number(p.peso_gramas) > 0 || Number(p.tempo_producao_segundos) > 0)
                    ? `<span class="sub-linha" style="font-size:11.5px;color:var(--text-3);margin-top:2px;">⏱️ ${p.tempo_producao_formatado || formatarSegundosHHMMSS(p.tempo_producao_segundos)} · ⚖️ ${p.peso_gramas}g/un</span>`
                    : ''}
            </td>
            <td class="num" style="font-weight:600;">${App.fmtInt.format(Number(p.estoque) || 0)}</td>
            <td class="num">${App.fmtInt.format(p.qtd_pedidos)}</td>
            <td class="col-acoes">
                <div class="acoes-icones">
                    ${App.botaoIcone('ficha', 'Ficha Técnica & Montagem (Peças)', `abrirFicha(${p.id})`, 'primario')}
                    ${App.botaoIcone('editar', 'Editar', `editar(${p.id})`)}
                    ${ativo
                        ? App.botaoIcone('desativar', 'Desativar', `alternarAtivo(${p.id}, false)`, 'alerta')
                        : App.botaoIcone('ativar', 'Ativar', `alternarAtivo(${p.id}, true)`, 'sucesso')}
                    ${usado ? '' : App.botaoIcone('excluir', 'Excluir', `excluir(${p.id})`, 'perigo')}
                </div>
            </td>
        </tr>`;
    }).join('');
}

function formatarSegundosHHMMSS(segundos) {
    if (!segundos || segundos <= 0) return '00:00:00';
    const s = Math.round(Number(segundos));
    const horas = Math.floor(s / 3600);
    const resto = s % 3600;
    const minutos = Math.floor(resto / 60);
    const segs = resto % 60;
    return `${String(horas).padStart(2, '0')}:${String(minutos).padStart(2, '0')}:${String(segs).padStart(2, '0')}`;
}

function parseTempoParaSegundos(str) {
    if (typeof str === 'number') return Math.max(0, Math.round(str));
    if (!str) return 0;
    const s = String(str).trim();
    if (!s) return 0;
    const partes = s.split(':');
    if (partes.length === 3) {
        return (parseInt(partes[0], 10) || 0) * 3600 + (parseInt(partes[1], 10) || 0) * 60 + (parseInt(partes[2], 10) || 0);
    }
    if (partes.length === 2) {
        return (parseInt(partes[0], 10) || 0) * 3600 + (parseInt(partes[1], 10) || 0) * 60;
    }
    const matchH = s.match(/(\d+)\s*h/i);
    const matchM = s.match(/(\d+)\s*m/i);
    const matchS = s.match(/(\d+)\s*s/i);
    let total = 0;
    if (matchH) total += parseInt(matchH[1], 10) * 3600;
    if (matchM) total += parseInt(matchM[1], 10) * 60;
    if (matchS) total += parseInt(matchS[1], 10);
    if (total > 0) return total;
    return Math.max(0, parseInt(s, 10) || 0);
}

function recalcularFormacaoPreco(forcarAtualizarPreco = true) {
    const peso = parseFloat($('prodPeso')?.value) || 0;
    let custoFil = parseFloat($('prodCustoFilamento')?.value);
    
    // Se custo de filamento estiver zerado ou vazio mas tem peso, estima a R$ 0,09/g (R$ 90/kg)
    if ((isNaN(custoFil) || custoFil === 0) && peso > 0) {
        custoFil = round2(peso * 0.0900);
        $('prodCustoFilamento').value = custoFil.toFixed(2);
    } else if (isNaN(custoFil) || custoFil < 0) {
        custoFil = 0;
    }

    const temEmb = $('prodTemEmbalagem')?.value === '1';
    if ($('boxValorEmbalagem')) {
        $('boxValorEmbalagem').style.display = temEmb ? 'block' : 'none';
    }
    const valorEmb = temEmb ? (parseFloat($('prodValorEmbalagem')?.value) || 0) : 0;
    const valorOutros = parseFloat($('prodValorOutros')?.value) || 0;
    const margem = parseFloat($('prodMargemLucro')?.value) || 0;

    const custoTotal = round2(custoFil + valorEmb + valorOutros);
    const valorMargem = round2(custoTotal * (margem / 100));
    const precoSugerido = round2(custoTotal + valorMargem);

    if ($('resumoCustoFilamento')) $('resumoCustoFilamento').textContent = App.moeda(custoFil);
    if ($('resumoCustoEmbalagem')) $('resumoCustoEmbalagem').textContent = App.moeda(valorEmb);
    if ($('resumoCustoOutros')) $('resumoCustoOutros').textContent = App.moeda(valorOutros);
    if ($('resumoCustoTotal')) $('resumoCustoTotal').textContent = App.moeda(custoTotal);
    if ($('resumoValorMargem')) $('resumoValorMargem').textContent = '+ ' + App.moeda(valorMargem);

    if (forcarAtualizarPreco && $('prodPreco')) {
        $('prodPreco').value = precoSugerido > 0 ? precoSugerido.toFixed(2) : '0.00';
    }
}

function round2(num) {
    return Math.round((Number(num) + Number.EPSILON) * 100) / 100;
}

function novaCorModalProd() {
    return { id: 0, cor: '', peso_gramas: '', tempo_producao_segundos: 0, tempo_formatado: '' };
}

function novaPecaModalProd() {
    return { peca_id: 0, nome: '', quantidade: 1, peso_gramas: '', tempo_producao_segundos: 0, tempo_formatado: '00:00:00' };
}

function atualizarModoModalProduto() {
    const tipo = $('prodTipo') ? $('prodTipo').value : 'simples';
    const temPecas = tipo === 'composto' || (modalProdPecas && modalProdPecas.length > 0);
    const boxPecas = $('boxPecasProduto');
    const boxMulticor = $('boxMulticorProduto');
    const inputTempo = $('prodTempo');
    const inputPeso = $('prodPeso');
    const tagTempo = $('tagOrigemTempo');
    const tagPeso = $('tagOrigemPeso');
    const dicaTempo = $('dicaProdTempo');
    const dicaPeso = $('dicaProdPeso');

    if (temPecas) {
        if (boxPecas) boxPecas.style.display = 'block';
        if (boxMulticor) boxMulticor.style.display = 'none';

        if (inputTempo) { inputTempo.readOnly = true; inputTempo.classList.add('input-bloqueado-soma'); }
        if (inputPeso) { inputPeso.readOnly = true; inputPeso.classList.add('input-bloqueado-soma'); }

        if (tagTempo) { tagTempo.textContent = '🔒 Soma das peças'; tagTempo.style.display = 'inline-block'; }
        if (tagPeso) { tagPeso.textContent = '🔒 Soma das peças'; tagPeso.style.display = 'inline-block'; }
        if (dicaTempo) { dicaTempo.textContent = 'Tempo calculado automaticamente pela soma das peças.'; dicaTempo.style.display = 'block'; }
        if (dicaPeso) { dicaPeso.textContent = 'Filamento calculado automaticamente pela soma das peças.'; dicaPeso.style.display = 'block'; }

        // Recalcula soma das peças para 1 produto
        let somaPeso = 0;
        let somaTempo = 0;
        modalProdPecas.forEach(pec => {
            const q = Math.max(1, parseInt(pec.quantidade) || 1);
            somaPeso += (parseFloat(pec.peso_gramas) || 0) * q;
            somaTempo += (parseInt(pec.tempo_producao_segundos) || 0) * q;
        });

        if (inputPeso) inputPeso.value = somaPeso > 0 ? (Math.round(somaPeso * 10) / 10).toFixed(1) : (modalProdPecas.length ? '0.0' : inputPeso.value);
        if (inputTempo) inputTempo.value = somaTempo > 0 ? formatarSegundosHHMMSS(somaTempo) : (modalProdPecas.length ? '00:00:00' : inputTempo.value);

        if ($('resumoPecasProdBadge')) {
            $('resumoPecasProdBadge').textContent = `${modalProdPecas.length} peça(s) · ${Math.round(somaPeso * 10) / 10}g · ${formatarSegundosHHMMSS(somaTempo)}`;
        }
    } else {
        if (boxPecas) boxPecas.style.display = 'none';
        if (boxMulticor) boxMulticor.style.display = 'block';

        const ehMulticor = $('checkProdMulticor') && $('checkProdMulticor').checked;
        const conteudoMulti = $('conteudoMulticorProd');
        if (conteudoMulti) conteudoMulti.style.display = ehMulticor ? 'block' : 'none';

        if (ehMulticor) {
            if (inputTempo) { inputTempo.readOnly = true; inputTempo.classList.add('input-bloqueado-soma'); }
            if (inputPeso) { inputPeso.readOnly = true; inputPeso.classList.add('input-bloqueado-soma'); }

            if (tagTempo) { tagTempo.textContent = '🔒 Soma das cores'; tagTempo.style.display = 'inline-block'; }
            if (tagPeso) { tagPeso.textContent = '🔒 Soma das cores'; tagPeso.style.display = 'inline-block'; }
            if (dicaTempo) { dicaTempo.textContent = 'Tempo calculado automaticamente pela soma das cores.'; dicaTempo.style.display = 'block'; }
            if (dicaPeso) { dicaPeso.textContent = 'Filamento calculado automaticamente pela soma das cores.'; dicaPeso.style.display = 'block'; }

            // Recalcula soma das cores para 1 produto
            let somaPeso = 0;
            let somaTempo = 0;
            modalProdCores.forEach(c => {
                somaPeso += (parseFloat(c.peso_gramas) || 0);
                somaTempo += (parseInt(c.tempo_producao_segundos) || 0);
            });

            if (inputPeso) inputPeso.value = somaPeso > 0 ? (Math.round(somaPeso * 10) / 10).toFixed(1) : (modalProdCores.length ? '0.0' : inputPeso.value);
            if (inputTempo) inputTempo.value = somaTempo > 0 ? formatarSegundosHHMMSS(somaTempo) : (modalProdCores.length ? '00:00:00' : inputTempo.value);

            if ($('resumoCoresProdBadge')) {
                $('resumoCoresProdBadge').textContent = `${modalProdCores.length} cor(es) · ${Math.round(somaPeso * 10) / 10}g · ${formatarSegundosHHMMSS(somaTempo)}`;
            }
        } else {
            // PRODUTO SEM PEÇAS E MONOCOLOR: CAMPOS ABERTOS PARA DIGITAÇÃO LIVRE!
            if (inputTempo) { inputTempo.readOnly = false; inputTempo.classList.remove('input-bloqueado-soma'); }
            if (inputPeso) { inputPeso.readOnly = false; inputPeso.classList.remove('input-bloqueado-soma'); }

            if (tagTempo) tagTempo.style.display = 'none';
            if (tagPeso) tagPeso.style.display = 'none';
            if (dicaTempo) dicaTempo.style.display = 'none';
            if (dicaPeso) dicaPeso.style.display = 'none';

            if ($('resumoCoresProdBadge')) $('resumoCoresProdBadge').textContent = '';
        }
    }

    recalcularFormacaoPreco(false);
}

function renderizarCoresModalProduto() {
    const container = $('listaCoresProduto');
    if (!container) return;

    if (!modalProdCores.length) {
        container.innerHTML = `<p class="vazio" style="padding:6px 0; font-size:12px;">Nenhuma cor adicionada. Clique em "+ Adicionar Cor".</p>`;
        atualizarModoModalProduto();
        return;
    }

    container.innerHTML = modalProdCores.map((c, idx) => `
        <div class="linha-cor-produto">
            <input type="text" placeholder="Nome da cor (ex: Preto, Branco, Azul...)"
                   value="${esc(c.cor || '')}"
                   oninput="atualizarCorModalProduto(${idx}, 'cor', this.value)"
                   style="font-size:12.5px;">
            <input type="number" min="0" step="0.1" placeholder="Filamento (g)"
                   value="${c.peso_gramas !== '' && c.peso_gramas !== null && c.peso_gramas !== undefined ? c.peso_gramas : ''}"
                   oninput="atualizarCorModalProduto(${idx}, 'peso', this.value)"
                   title="Filamento gasto desta cor (em gramas)"
                   style="font-size:12.5px; text-align:center;">
            <input type="text" placeholder="00:00:00"
                   value="${esc(c.tempo_formatado || '')}"
                   onchange="atualizarCorModalProduto(${idx}, 'tempo', this.value)"
                   title="Tempo de impressão desta cor (HH:mm:ss)"
                   style="font-size:12.5px; text-align:center;">
            <button type="button" class="btn-icone perigo" onclick="removerCorModalProduto(${idx})"
                    title="Remover cor" ${modalProdCores.length <= 1 ? 'disabled' : ''}>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
    `).join('');

    atualizarModoModalProduto();
}

function adicionarCorModalProduto() {
    modalProdCores.push(novaCorModalProd());
    renderizarCoresModalProduto();
    setTimeout(() => {
        const inputs = $('listaCoresProduto')?.querySelectorAll('input[type="text"]');
        if (inputs && inputs.length) inputs[inputs.length - 2].focus();
    }, 30);
}

function removerCorModalProduto(cidx) {
    if (modalProdCores.length <= 1) return;
    modalProdCores.splice(cidx, 1);
    renderizarCoresModalProduto();
}

function atualizarCorModalProduto(cidx, campo, valor) {
    const c = modalProdCores[cidx];
    if (!c) return;
    if (campo === 'cor') {
        c.cor = valor;
    } else if (campo === 'peso') {
        c.peso_gramas = valor === '' ? '' : Math.max(0, parseFloat(valor) || 0);
    } else if (campo === 'tempo') {
        const segs = parseTempoParaSegundos(valor);
        c.tempo_producao_segundos = segs;
        c.tempo_formatado = formatarSegundosHHMMSS(segs);
    }
    atualizarModoModalProduto();
}

function renderizarPecasModalProduto() {
    const container = $('listaPecasModalProd');
    if (!container) return;

    if (!modalProdPecas.length) {
        container.innerHTML = `<p class="vazio" style="padding:6px 0; font-size:12px;">Nenhuma peça cadastrada. Adicione as peças necessárias para montar 1 produto.</p>`;
        atualizarModoModalProduto();
        return;
    }

    container.innerHTML = modalProdPecas.map((pec, idx) => `
        <div class="bloco-peca-modal-prod">
            <div class="linha-peca-modal-prod">
                <input type="text" placeholder="Nome da peça (ex: Base, Hélice...)"
                       value="${esc(pec.nome || '')}"
                       oninput="atualizarPecaModalProduto(${idx}, 'nome', this.value)"
                       style="font-size:12.5px; font-weight:600;">
                <input type="number" min="1" placeholder="Qtd/un"
                       value="${pec.quantidade || 1}"
                       oninput="atualizarPecaModalProduto(${idx}, 'quantidade', this.value)"
                       title="Quantidade desta peça por unidade de produto"
                       style="font-size:12.5px; text-align:center;">
                <input type="text" placeholder="00:00:00"
                       value="${esc(pec.tempo_formatado || '')}"
                       onchange="atualizarPecaModalProduto(${idx}, 'tempo', this.value)"
                       title="Tempo de impressão desta peça (HH:mm:ss)"
                       style="font-size:12.5px; text-align:center;">
                <input type="number" min="0" step="0.1" placeholder="Filamento (g)"
                       value="${pec.peso_gramas !== '' && pec.peso_gramas !== null && pec.peso_gramas !== undefined ? pec.peso_gramas : ''}"
                       oninput="atualizarPecaModalProduto(${idx}, 'peso', this.value)"
                       title="Filamento gasto desta peça (em gramas)"
                       style="font-size:12.5px; text-align:center;">
                <button type="button" class="btn-icone perigo" onclick="removerPecaModalProduto(${idx})"
                        title="Remover peça">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
        </div>
    `).join('');

    atualizarModoModalProduto();
}

function adicionarPecaModalProduto() {
    modalProdPecas.push(novaPecaModalProd());
    renderizarPecasModalProduto();
    setTimeout(() => {
        const inputs = $('listaPecasModalProd')?.querySelectorAll('input[type="text"]');
        if (inputs && inputs.length) inputs[inputs.length - 2].focus();
    }, 30);
}

function removerPecaModalProduto(pidx) {
    modalProdPecas.splice(pidx, 1);
    renderizarPecasModalProduto();
}

function atualizarPecaModalProduto(pidx, campo, valor) {
    const pec = modalProdPecas[pidx];
    if (!pec) return;
    if (campo === 'nome') {
        pec.nome = valor;
    } else if (campo === 'quantidade') {
        pec.quantidade = Math.max(1, parseInt(valor) || 1);
    } else if (campo === 'peso') {
        pec.peso_gramas = valor === '' ? '' : Math.max(0, parseFloat(valor) || 0);
    } else if (campo === 'tempo') {
        const segs = parseTempoParaSegundos(valor);
        pec.tempo_producao_segundos = segs;
        pec.tempo_formatado = formatarSegundosHHMMSS(segs);
    }
    atualizarModoModalProduto();
}

async function abrirModal(p = null) {
    editandoId = p ? p.id : null;
    $('formProduto').reset();
    $('prodNome').value = p ? p.nome : '';
    $('prodTipo').value = p ? (p.tipo || 'simples') : 'simples';
    $('prodPreco').value = p ? Number(p.preco).toFixed(2) : '';
    $('prodEstoque').value = p ? Number(p.estoque) : '0';
    $('prodPeso').value = p && Number(p.peso_gramas) > 0 ? Number(p.peso_gramas) : '';
    $('prodTempo').value = p ? (p.tempo_producao_formatado || (p.tempo_producao_segundos ? formatarSegundosHHMMSS(p.tempo_producao_segundos) : '')) : '';
    $('prodAtivo').value = p ? String(p.ativo) : '1';
    $('prodDescricao').value = p ? (p.descricao || '') : '';

    // Campos de Precificação
    $('prodTemEmbalagem').value = p && Number(p.tem_embalagem) === 1 ? '1' : '0';
    $('prodValorEmbalagem').value = p && p.valor_embalagem ? Number(p.valor_embalagem).toFixed(2) : '0.00';
    $('prodValorOutros').value = p && p.valor_outros ? Number(p.valor_outros).toFixed(2) : '0.00';
    $('prodMargemLucro').value = p && p.margem_lucro !== undefined && p.margem_lucro !== null ? Number(p.margem_lucro) : 100;
    $('prodCustoFilamento').value = p && p.custo_filamento ? Number(p.custo_filamento).toFixed(2) : '';

    $('prodTituloModal').textContent = p ? 'Editar produto' : 'Novo produto';
    $('prodSubModal').textContent = p ? p.nome : 'Cadastre produtos com tempo, filamento por cor ou composição de peças.';
    $('btnSalvarProduto').textContent = p ? 'Salvar alterações' : 'Cadastrar produto';

    modalProdCores = [];
    modalProdPecas = [];

    const btnFichaCompleta = $('btnAbrirFichaCompletaModalProd');
    if (btnFichaCompleta) {
        if (p && p.id) {
            btnFichaCompleta.style.display = 'inline-block';
            btnFichaCompleta.onclick = () => {
                App.modal.fechar('modalProduto');
                abrirFicha(p.id);
            };
        } else {
            btnFichaCompleta.style.display = 'none';
        }
    }

    if ($('checkProdMulticor')) $('checkProdMulticor').checked = false;

    renderizarCoresModalProduto();
    renderizarPecasModalProduto();
    atualizarModoModalProduto();
    recalcularFormacaoPreco(p ? false : true);

    App.modal.abrir('modalProduto', '#prodNome');

    // Se estiver editando, busca os detalhes atualizados e cálculo dinâmico de PEPS
    if (p && p.id) {
        try {
            const detalhe = await App.api(`api/produtos.php?id=${p.id}`);
            if (detalhe) {
                if (detalhe.tem_pecas && detalhe.pecas && detalhe.pecas.length > 0) {
                    modalProdPecas = detalhe.pecas.map(pec => ({
                        peca_id: pec.id,
                        nome: pec.nome,
                        quantidade: pec.quantidade,
                        peso_gramas: Number(pec.peso_gramas) || 0,
                        tempo_producao_segundos: Number(pec.tempo_producao_segundos) || 0,
                        tempo_formatado: pec.tempo_formatado || formatarSegundosHHMMSS(pec.tempo_producao_segundos),
                        cores: pec.cores || []
                    }));
                    $('prodTipo').value = 'composto';
                    modalProdCores = [];
                    if ($('checkProdMulticor')) $('checkProdMulticor').checked = false;
                } else if (detalhe.cores && detalhe.cores.length > 0) {
                    modalProdCores = detalhe.cores.map(c => ({
                        id: c.id,
                        cor: c.cor,
                        peso_gramas: Number(c.peso_gramas) || 0,
                        tempo_producao_segundos: Number(c.tempo_producao_segundos) || 0,
                        tempo_formatado: c.tempo_formatado || formatarSegundosHHMMSS(c.tempo_producao_segundos)
                    }));
                    modalProdPecas = [];
                    if ($('checkProdMulticor')) $('checkProdMulticor').checked = true;
                } else {
                    modalProdPecas = [];
                    modalProdCores = [];
                    if ($('checkProdMulticor')) $('checkProdMulticor').checked = false;
                }

                renderizarCoresModalProduto();
                renderizarPecasModalProduto();
                atualizarModoModalProduto();

                if (detalhe.calculo_custo_filamento) {
                    const cFil = detalhe.calculo_custo_filamento;
                    if (cFil.custo_filamento > 0) {
                        $('prodCustoFilamento').value = Number(cFil.custo_filamento).toFixed(2);
                        if (cFil.peso_total_gramas > 0 && !detalhe.tem_pecas && (!modalProdCores.length)) {
                            $('prodPeso').value = Number(cFil.peso_total_gramas);
                        }
                        $('tagCustoOrigem').textContent = `PEPS ativo (${cFil.cores?.length || 0} cor/cores)`;
                    }
                }
                recalcularFormacaoPreco(false);
            }
        } catch (_) {}
    }
}

const editar = id => abrirModal(produtos.find(p => p.id === id));

async function salvar(ev) {
    ev.preventDefault();
    const temEmb = $('prodTemEmbalagem').value === '1';
    const tipo = $('prodTipo').value;
    const temPecas = tipo === 'composto' || modalProdPecas.length > 0;
    const ehMulticor = !temPecas && $('checkProdMulticor') && $('checkProdMulticor').checked;

    const payload = {
        nome: $('prodNome').value.trim(),
        tipo: temPecas ? 'composto' : tipo,
        preco: parseFloat($('prodPreco').value) || 0,
        estoque: parseInt($('prodEstoque').value) || 0,
        peso_gramas: parseFloat($('prodPeso').value) || 0,
        tempo_producao_segundos: parseTempoParaSegundos($('prodTempo').value),
        tem_embalagem: temEmb ? 1 : 0,
        valor_embalagem: temEmb ? (parseFloat($('prodValorEmbalagem').value) || 0) : 0,
        valor_outros: parseFloat($('prodValorOutros').value) || 0,
        margem_lucro: parseFloat($('prodMargemLucro').value) || 0,
        custo_filamento: parseFloat($('prodCustoFilamento').value) || 0,
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

    if (temPecas) {
        const pecasValidas = modalProdPecas.filter(p => (p.nome || '').trim() !== '');
        if (tipo === 'composto' && !pecasValidas.length && !editandoId) {
            App.toast('Adicione ao menos uma peça ou troque o tipo para Produto Simples.', 'erro');
            return;
        }
        payload.pecas = pecasValidas;
    } else if (ehMulticor) {
        const coresValidas = modalProdCores.filter(c => (c.cor || '').trim() !== '');
        if (!coresValidas.length) {
            App.toast('Adicione ao menos uma cor com nome ou desmarque a opção Multicor.', 'erro');
            return;
        }
        payload.cores = coresValidas;
    } else {
        payload.cores = [];
    }

    const btn = $('btnSalvarProduto');
    btn.disabled = true;
    try {
        if (editandoId) {
            await App.api(`api/produtos.php?id=${editandoId}`, 'PUT', payload);
            App.toast(`Produto "${payload.nome}" atualizado.`);
        } else {
            const res = await App.api('api/produtos.php', 'POST', payload);
            App.toast(`Produto "${payload.nome}" cadastrado com sucesso!`);
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

// ============================================================
// Módulo Ficha Técnica (BOM), Diagnóstico de Gargalos & Montagem
//
// Uma peça (ex.: "Chave de Fenda") pode existir em mais de uma cor, cada
// cor com seu próprio saldo em estoque — ex.: Cinza e Laranja, ambas
// servem para montar o produto. Uma peça cuja cor não importa (ex.:
// "Suporte") fica com uma única linha de cor em branco.
// ============================================================

async function abrirFicha(id) {
    bomProdutoAtualId = id;
    const meta = parseInt($('metaSimulacao').value) || 1;
    try {
        const bomData = await App.api(`api/produtos_composicao.php?produto_pai_id=${id}&meta=${meta}`);

        $('bomTituloModal').textContent = `Ficha Técnica: ${bomData.produto.nome}`;
        $('bomSubModal').textContent = `Cadastre as peças, tempo (HH:mm:ss) e consumo de filamento (g) de 1 produto e simule a capacidade e previsão.`;

        renderizarDiagnosticoBOM(bomData);

        // Inicializa o editor de peças, cada uma com sua lista de cores, peso e tempo.
        bomLinhasEditor = (bomData.pecas || []).map(p => ({
            peca_id: p.peca_id,
            nome: p.nome,
            quantidade: p.por_unidade,
            estoque: Number(p.estoque_atual || p.estoque) || 0,
            tempo_producao_segundos: Number(p.tempo_producao_segundos) || 0,
            tempo_formatado: p.tempo_formatado || formatarSegundosHHMMSS(p.tempo_producao_segundos),
            peso_gramas: Number(p.peso_gramas) || 0,
            foto: p.foto || '',
            cores: (p.cores && p.cores.length ? p.cores : [{ cor_id: 0, cor: null, peso_gramas: null, tempo_producao_segundos: null, foto: null }])
                .map(c => ({
                    cor_id: c.cor_id || 0,
                    cor: c.cor || '',
                    peso_gramas: (c.peso_gramas !== null && c.peso_gramas !== undefined) ? Number(c.peso_gramas) : null,
                    tempo_producao_segundos: (c.tempo_producao_segundos !== null && c.tempo_producao_segundos !== undefined) ? Number(c.tempo_producao_segundos) : null,
                    tempo_formatado: c.tempo_formatado || (c.tempo_producao_segundos ? formatarSegundosHHMMSS(c.tempo_producao_segundos) : '')
                })),
        }));

        if (!bomLinhasEditor.length) {
            bomLinhasEditor.push(novaLinhaPeca());
        }
        renderizarEditorBOM();

        App.modal.abrir('modalComposicao');
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

const novaLinhaPeca = () => ({
    peca_id: 0, nome: '', quantidade: 1, estoque: 0,
    tempo_producao_segundos: 0, tempo_formatado: '00:00:00',
    peso_gramas: 0, foto: '', cores: [novaLinhaCor()]
});
const novaLinhaCor = () => ({ cor_id: 0, cor: '', peso_gramas: null, tempo_producao_segundos: null, tempo_formatado: '' });

function renderizarDiagnosticoBOM(data) {
    const kpiBox = $('kpiCapacidadeBox');
    const cap = data.capacidade_maxima || 0;
    $('kpiCapacidadeQtd').textContent = cap;
    $('kpiEstoquePronto').textContent = data.produto.estoque || 0;

    // Atualiza KPIs de Peso e Tempo
    if ($('kpiPeso1un')) {
        $('kpiPeso1un').textContent = (data.resumo_1un?.peso_total_gramas || 0) + ' g';
    }
    if ($('kpiCores1un')) {
        const coresArr = data.resumo_1un?.cores || [];
        $('kpiCores1un').textContent = coresArr.length
            ? coresArr.map(c => `${c.cor}: ${c.peso_gramas}g`).join(', ')
            : 'Soma das peças';
    }
    if ($('kpiTempo1un')) {
        $('kpiTempo1un').textContent = data.resumo_1un?.tempo_formatado || '00:00:00';
    }

    // Atualiza valor padrão do campo "Montar agora"
    $('qtdMontagemExecutar').value = cap > 0 ? cap : 1;

    if (cap > 0) {
        kpiBox.className = 'card-kpi-bom destaque-verde';
        $('kpiCapacidadeGargalo').textContent = `Disponível para montagem imediata de ${cap} unidade(s).`;
    } else {
        kpiBox.className = 'card-kpi-bom destaque-alerta';
        const gargalosStr = (data.gargalos || []).join(', ');
        $('kpiCapacidadeGargalo').textContent = gargalosStr
            ? `Limitado por falta de: ${gargalosStr}`
            : 'Nenhuma peça disponível para montagem ou peças ainda não cadastradas.';
    }

    // Atualiza Painel de Previsão da Meta
    const painel = $('painelPrevisaoMeta');
    const rm = data.resumo_meta;
    if (painel && rm) {
        const coresFaltantes = (rm.cores_faltante || []).filter(c => c.peso_gramas > 0);
        const chipsCoresFaltantes = coresFaltantes.length
            ? coresFaltantes.map(c => `<span class="chip-cor-diag">${esc(c.cor)}: <strong>${c.peso_gramas}g</strong></span>`).join(' ')
            : '<span style="color:var(--success-solid);font-weight:600;">✓ Peças suficientes em estoque!</span>';

        painel.innerHTML = `
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                <div>
                    <strong>Previsão para Meta de ${rm.meta} un:</strong>
                    <span style="margin-left:10px;">Tempo restante p/ imprimir: <strong style="color:var(--primary);font-size:14px;">${rm.tempo_faltante_formatado}</strong></span>
                    <span style="margin-left:14px;">Filamento faltante: <strong style="color:var(--primary);font-size:14px;">${rm.peso_faltante_gramas} g</strong></span>
                </div>
                <div style="font-size:12px;color:var(--text-3);">
                    Total bruto: <b>${rm.tempo_formatado}</b> · <b>${rm.peso_total_gramas} g</b>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:2px;">
                <span style="font-size:12px;color:var(--text-3);">Filamento por cor a imprimir:</span>
                ${chipsCoresFaltantes}
            </div>
        `;
    }

    const tbody = $('tabelaDiagnosticoBOM');
    if (!data.pecas || !data.pecas.length) {
        tbody.innerHTML = `<tr><td colspan="9" style="text-align: center; color: var(--text-3); padding: 18px;">
            Nenhuma peça cadastrada. Cadastre as peças do produto na seção abaixo "Configurar Peças do Produto".
        </td></tr>`;
        return;
    }

    tbody.innerHTML = data.pecas.map(p => {
        const falta = p.faltam_para_meta > 0;
        const ehGargalo = p.eh_gargalo;
        const fotoThumb = p.foto
            ? `<img src="${esc(p.foto)}" alt="${esc(p.nome)}" style="width:34px;height:34px;border-radius:4px;object-fit:cover;display:block;margin:auto;">`
            : `<span style="font-size:16px;">🧩</span>`;

        // Cores da peça, com as gramas de filamento por cor
        const cores = (p.cores || []);
        const coresHtml = cores.length
            ? cores.map(c => `<span class="chip-cor-diag">${esc(c.cor || 'qualquer cor')}: <strong>${(c.peso_gramas !== null && c.peso_gramas !== undefined && Number(c.peso_gramas) > 0) ? (Number(c.peso_gramas).toFixed(1) + 'g') : '—'}</strong></span>`).join(' ')
            : '<span class="vazio">—</span>';

        return `
        <tr class="${ehGargalo && cap === 0 ? 'linha-gargalo' : ''}">
            <td style="text-align: center; padding: 4px;">${fotoThumb}</td>
            <td>
                <strong>${esc(p.nome)}</strong>
                ${ehGargalo ? '<span class="tag-gargalo">Gargalo</span>' : ''}
            </td>
            <td>${coresHtml}</td>
            <td class="num">${p.por_unidade} un</td>
            <td class="num" style="font-weight:600;">${p.peso_gramas > 0 ? (p.peso_gramas + ' g') : '—'}</td>
            <td class="num" style="font-weight:600;">${p.tempo_formatado || '—'}</td>
            <td class="num" style="font-weight: 600;">${App.fmtInt.format(p.estoque_atual)} un</td>
            <td class="num">${App.fmtInt.format(p.total_necessario_meta)} un</td>
            <td>
                ${falta
                    ? `<span class="tag-situacao-falta">Faltam ${App.fmtInt.format(p.faltam_para_meta)} un (${p.tempo_meta_faltante_formatado})</span>`
                    : `<span class="tag-situacao-ok">Suficiente (sobra ${App.fmtInt.format(p.sobra_apos_meta)})</span>`}
            </td>
        </tr>`;
    }).join('');
}

async function recalcularMeta() {
    if (!bomProdutoAtualId) return;
    const meta = Math.max(1, parseInt($('metaSimulacao').value) || 1);
    try {
        const bomData = await App.api(`api/produtos_composicao.php?produto_pai_id=${bomProdutoAtualId}&meta=${meta}`);
        renderizarDiagnosticoBOM(bomData);
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

async function executarMontagem() {
    if (!bomProdutoAtualId) return;
    const qtd = parseInt($('qtdMontagemExecutar').value) || 0;
    if (qtd <= 0) {
        App.toast('Informe uma quantidade válida para montar.', 'erro');
        $('qtdMontagemExecutar').focus();
        return;
    }

    const confirma = await App.confirmar(
        `Confirmar a montagem de ${qtd} unidade(s)? O estoque das peças será baixado (de qualquer cor disponível) e o produto final será incrementado.`,
        { titulo: 'Executar Montagem', botao: 'Confirmar Montagem' }
    );
    if (!confirma) return;

    const btn = $('btnExecutarMontagem');
    btn.disabled = true;
    try {
        const res = await App.api('api/produtos_composicao.php?acao=montar', 'POST', {
            produto_pai_id: bomProdutoAtualId,
            quantidade: qtd
        });
        App.toast(res.mensagem || 'Montagem realizada com sucesso!');
        await abrirFicha(bomProdutoAtualId);
        carregar(); // Atualiza lista de produtos de fundo
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}

// Atualiza o resumo em tempo real no topo do editor de peças
function atualizarTotaisInstantaneosEditorBOM() {
    let tempoTotal = 0;
    let pesoTotal = 0;
    const coresConsumo = {};

    bomLinhasEditor.forEach(l => {
        if (!l.nome || !l.nome.trim()) return;
        const t = Number(l.tempo_producao_segundos) || 0;
        const p = Number(l.peso_gramas) || 0;
        tempoTotal += t;
        pesoTotal += p;

        const cores = l.cores || [];
        if (cores.length) {
            const qtdC = cores.length;
            cores.forEach(c => {
                const cNome = (c.cor || '').trim() || 'qualquer cor';
                const pCor = (c.peso_gramas !== null && c.peso_gramas !== undefined && c.peso_gramas !== '')
                    ? Number(c.peso_gramas)
                    : (p > 0 ? (p / qtdC) : 0);
                coresConsumo[cNome] = (coresConsumo[cNome] || 0) + pCor;
            });
        } else {
            coresConsumo['qualquer cor'] = (coresConsumo['qualquer cor'] || 0) + p;
        }
    });

    if ($('editorTempoTotal')) $('editorTempoTotal').textContent = formatarSegundosHHMMSS(tempoTotal);
    if ($('editorPesoTotal')) $('editorPesoTotal').textContent = (Math.round(pesoTotal * 10) / 10) + ' g';

    const badgesContainer = $('editorCoresBadges');
    if (badgesContainer) {
        const entries = Object.entries(coresConsumo).filter(([, g]) => g > 0);
        badgesContainer.innerHTML = entries.map(([cor, g]) =>
            `<span class="chip-cor-diag" style="font-size:11.5px;">${esc(cor)}: <b>${Math.round(g * 10) / 10}g</b></span>`
        ).join('');
    }
}

// ============================================================
// Editor de Peças: um bloco por peça (nome, qtd/un, tempo, peso, foto) com uma lista
// de cores aninhada (cada cor com seu saldo e peso opcional).
// ============================================================
function renderizarEditorBOM() {
    const container = $('listaEditorBOM');
    if (!bomLinhasEditor.length) {
        container.innerHTML = `<p class="vazio" style="padding:12px 0;">Nenhuma peça cadastrada. Clique em "+ Adicionar Peça".</p>`;
        atualizarTotaisInstantaneosEditorBOM();
        return;
    }

    container.innerHTML = bomLinhasEditor.map((linha, idx) => {
        const fotoBtn = linha.foto
            ? `<img src="${esc(linha.foto)}" class="foto-peca-editor" onclick="uploadFotoPecaProdutos(${idx})" title="Clique para trocar a foto">`
            : `<button type="button" class="btn-icone foto-peca-editor-vazia" onclick="uploadFotoPecaProdutos(${idx})" title="Adicionar foto">📷</button>`;

        const coresHtml = linha.cores.map((cor, cidx) => `
            <div class="linha-cor-editor">
                <input type="text" placeholder="Nome da cor (ex.: Branco, Cinza...)"
                       value="${esc(cor.cor || '')}"
                       oninput="atualizarCorBOM(${idx}, ${cidx}, 'cor', this.value)">
                <input type="text" placeholder="HH:mm:ss"
                       value="${esc(cor.tempo_formatado || '')}"
                       onchange="atualizarCorBOM(${idx}, ${cidx}, 'tempo', this.value)"
                       title="Tempo específico desta cor (se vazio, usa o da peça)."
                       class="tempo-cor-editor">
                <input type="number" min="0" step="0.1"
                       placeholder="${linha.peso_gramas ? (linha.peso_gramas + 'g') : 'g desta cor'}"
                       value="${cor.peso_gramas !== null && cor.peso_gramas !== undefined ? cor.peso_gramas : ''}"
                       oninput="atualizarCorBOM(${idx}, ${cidx}, 'peso', this.value)"
                       title="Filamento em gramas desta cor para esta peça."
                       class="peso-cor-editor">
                <button type="button" class="btn-icone perigo" onclick="removerCorBOM(${idx}, ${cidx})"
                        title="Remover esta cor" ${linha.cores.length <= 1 ? 'disabled' : ''}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>`).join('');

        return `
        <div class="bloco-peca-editor">
            <div class="cabecalho-peca-editor">
                ${fotoBtn}
                <input type="text" placeholder="Nome da peça (ex.: Hélice, Chave de Fenda...)"
                       value="${esc(linha.nome || '')}"
                       oninput="atualizarLinhaBOM(${idx}, 'nome', this.value)"
                       class="nome-peca-editor">
                <label class="rotulo-inline">Qtd/un
                    <input type="number" min="1" value="${linha.quantidade || 1}"
                           oninput="atualizarLinhaBOM(${idx}, 'quantidade', this.value)"
                           class="qtd-peca-editor" title="Quantidade desta peça necessária para 1 produto">
                </label>
                <label class="rotulo-inline">Tempo/un
                    <input type="text" placeholder="00:00:00"
                           value="${esc(linha.tempo_formatado || '00:00:00')}"
                           onchange="atualizarLinhaBOM(${idx}, 'tempo', this.value)"
                           class="tempo-peca-editor" title="Tempo de impressão desta peça para 1 produto (HH:mm:ss)">
                </label>
                <label class="rotulo-inline">Filamento/un (g)
                    <input type="number" min="0" step="0.1" placeholder="0.0 g"
                           value="${linha.peso_gramas > 0 ? linha.peso_gramas : ''}"
                           oninput="atualizarLinhaBOM(${idx}, 'peso', this.value)"
                           class="peso-peca-editor" title="Consumo em gramas desta peça para 1 produto">
                </label>
                <label class="rotulo-inline">Estoque
                    <input type="number" min="0" value="${linha.estoque || 0}"
                           ${linha.peca_id ? 'readonly tabindex="-1" class="saldo-cor-editor-travado"' : ''}
                           oninput="atualizarLinhaBOM(${idx}, 'estoque', this.value)"
                           class="qtd-peca-editor" title="${linha.peca_id ? 'O saldo desta peça é gerido na Fábrica.' : 'Estoque inicial da peça'}">
                </label>
                <button type="button" class="btn-icone perigo" onclick="removerLinhaBOM(${idx})" title="Remover peça">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="lista-cores-editor">
                <span class="rotulo-cores-editor">Cores desta peça (defina as cores e gramas de filamento por cor):</span>
                ${coresHtml}
                <button type="button" class="secundario pequeno" onclick="adicionarCorBOM(${idx})">+ Adicionar cor</button>
            </div>
        </div>`;
    }).join('');

    atualizarTotaisInstantaneosEditorBOM();
}

let linhaUploadFotoIndex = null;
function uploadFotoPecaProdutos(idx) {
    linhaUploadFotoIndex = idx;
    const input = $('inputFotoPecaProdutos');
    input.value = '';
    input.click();
}

$('inputFotoPecaProdutos')?.addEventListener('change', async function(e) {
    const file = e.target.files && e.target.files[0];
    if (!file || linhaUploadFotoIndex === null) return;

    const formData = new FormData();
    formData.append('foto', file);
    const pecaId = bomLinhasEditor[linhaUploadFotoIndex] ? bomLinhasEditor[linhaUploadFotoIndex].peca_id : 0;
    if (pecaId) formData.append('peca_id', pecaId);

    try {
        App.toast('Enviando foto da peça...');
        const res = await fetch('api/upload_foto.php', { method: 'POST', body: formData });
        const json = await res.json();
        if (!res.ok || !json.ok) throw new Error(json.erro || 'Falha no upload.');
        bomLinhasEditor[linhaUploadFotoIndex].foto = json.foto;
        renderizarEditorBOM();
        App.toast('Foto anexada com sucesso!');
    } catch (err) {
        App.toast(err.message, 'erro');
    }
});

function adicionarLinhaBOM() {
    bomLinhasEditor.push(novaLinhaPeca());
    renderizarEditorBOM();
    // Foca no nome da peça recém-criada
    setTimeout(() => {
        const nomes = $('listaEditorBOM').querySelectorAll('.nome-peca-editor');
        if (nomes.length) nomes[nomes.length - 1].focus();
    }, 40);
}

function removerLinhaBOM(idx) {
    bomLinhasEditor.splice(idx, 1);
    renderizarEditorBOM();
}

function atualizarLinhaBOM(idx, campo, valor) {
    if (!bomLinhasEditor[idx]) return;
    if (campo === 'quantidade') {
        bomLinhasEditor[idx][campo] = Math.max(1, parseInt(valor) || 1);
    } else if (campo === 'estoque') {
        bomLinhasEditor[idx].estoque = Math.max(0, parseInt(valor) || 0);
    } else if (campo === 'tempo') {
        const segs = parseTempoParaSegundos(valor);
        bomLinhasEditor[idx].tempo_producao_segundos = segs;
        bomLinhasEditor[idx].tempo_formatado = formatarSegundosHHMMSS(segs);
    } else if (campo === 'peso') {
        bomLinhasEditor[idx].peso_gramas = Math.max(0, parseFloat(valor) || 0);
    } else {
        bomLinhasEditor[idx][campo] = valor;
    }
    atualizarTotaisInstantaneosEditorBOM();
}

function adicionarCorBOM(idx) {
    if (!bomLinhasEditor[idx]) return;
    bomLinhasEditor[idx].cores.push(novaLinhaCor());
    renderizarEditorBOM();
    setTimeout(() => {
        const blocos = $('listaEditorBOM').querySelectorAll('.bloco-peca-editor');
        const inputs = blocos[idx]?.querySelectorAll('.linha-cor-editor input[type="text"]');
        if (inputs && inputs.length) inputs[inputs.length - 1].focus();
    }, 40);
}

function removerCorBOM(idx, cidx) {
    const linha = bomLinhasEditor[idx];
    if (!linha || linha.cores.length <= 1) return; // toda peça precisa de ao menos 1 cor
    linha.cores.splice(cidx, 1);
    renderizarEditorBOM();
}

function atualizarCorBOM(idx, cidx, campo, valor) {
    const cor = bomLinhasEditor[idx]?.cores[cidx];
    if (!cor) return;
    if (campo === 'peso') {
        cor.peso_gramas = (valor === '' || valor === null) ? null : Math.max(0, parseFloat(valor) || 0);
    } else if (campo === 'tempo') {
        const segs = parseTempoParaSegundos(valor);
        cor.tempo_producao_segundos = valor === '' ? null : segs;
        cor.tempo_formatado = valor === '' ? '' : formatarSegundosHHMMSS(segs);
    } else {
        cor[campo] = valor;
    }
    atualizarTotaisInstantaneosEditorBOM();
}

async function salvarBOM() {
    if (!bomProdutoAtualId) return;

    // Filtra peças com nome preenchido. peca_id e cor_id vão junto para o
    // servidor casar as linhas por id: peça que já existe mantém o
    // saldo impresso (quem manda nele é a Bancada da Fábrica).
    const itensValidos = bomLinhasEditor
        .filter(l => (l.nome || '').trim() !== '')
        .map(l => ({
            peca_id: parseInt(l.peca_id) || 0,
            nome: l.nome.trim(),
            quantidade: Math.max(1, parseInt(l.quantidade) || 1),
            estoque: Math.max(0, parseInt(l.estoque) || 0),
            peso_gramas: Math.max(0, parseFloat(l.peso_gramas) || 0),
            tempo_producao_segundos: parseInt(l.tempo_producao_segundos) || 0,
            foto: (l.foto || '').trim(),
            cores: l.cores.map(c => ({
                cor_id: parseInt(c.cor_id) || 0,
                cor: (c.cor || '').trim(),
                peso_gramas: (c.peso_gramas !== null && c.peso_gramas !== undefined && c.peso_gramas !== '')
                    ? Math.max(0, parseFloat(c.peso_gramas) || 0)
                    : null,
                tempo_producao_segundos: (c.tempo_producao_segundos !== null && c.tempo_producao_segundos !== undefined && c.tempo_producao_segundos !== '')
                    ? parseInt(c.tempo_producao_segundos) || 0
                    : null,
            })),
        }));

    if (!itensValidos.length) {
        App.toast('Cadastre ao menos uma peça com nome antes de salvar.', 'erro');
        return;
    }

    const btn = $('btnSalvarBOM');
    btn.disabled = true;
    try {
        await App.api(`api/produtos_composicao.php?produto_pai_id=${bomProdutoAtualId}`, 'POST', {
            itens: itensValidos
        });
        App.toast('Peças do produto salvas com sucesso!');
        await abrirFicha(bomProdutoAtualId);
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}


// Event Listeners
$('btnNovo')?.addEventListener('click', () => abrirModal());
$('formProduto')?.addEventListener('submit', salvar);
$('busca')?.addEventListener('input', renderizar);

$('prodTipo')?.addEventListener('change', () => atualizarModoModalProduto());
$('checkProdMulticor')?.addEventListener('change', () => {
    if ($('checkProdMulticor').checked && (!modalProdCores || !modalProdCores.length)) {
        modalProdCores = [novaCorModalProd(), novaCorModalProd()];
        renderizarCoresModalProduto();
    } else {
        atualizarModoModalProduto();
    }
});
$('btnAdicionarCorProd')?.addEventListener('click', adicionarCorModalProduto);
$('btnAdicionarPecaModalProd')?.addEventListener('click', adicionarPecaModalProduto);

$('btnRecalcularMeta')?.addEventListener('click', recalcularMeta);
$('metaSimulacao')?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); recalcularMeta(); } });
$('btnExecutarMontagem')?.addEventListener('click', executarMontagem);
$('btnAdicionarLinhaBOM')?.addEventListener('click', adicionarLinhaBOM);
$('btnSalvarBOM')?.addEventListener('click', salvarBOM);

// Listeners de precificação dinâmica
$('prodPeso')?.addEventListener('input', () => recalcularFormacaoPreco(true));
$('prodCustoFilamento')?.addEventListener('input', () => recalcularFormacaoPreco(true));
$('prodTemEmbalagem')?.addEventListener('change', () => recalcularFormacaoPreco(true));
$('prodValorEmbalagem')?.addEventListener('input', () => recalcularFormacaoPreco(true));
$('prodValorOutros')?.addEventListener('input', () => recalcularFormacaoPreco(true));
$('prodMargemLucro')?.addEventListener('input', () => recalcularFormacaoPreco(true));
$('btnRecalcularPreco')?.addEventListener('click', () => recalcularFormacaoPreco(true));

// Torna abrirFicha e manipuladores globais para onclick inline
window.abrirFicha = abrirFicha;
window.removerLinhaBOM = removerLinhaBOM;
window.atualizarLinhaBOM = atualizarLinhaBOM;
window.adicionarCorBOM = adicionarCorBOM;
window.removerCorBOM = removerCorBOM;
window.atualizarCorBOM = atualizarCorBOM;
window.uploadFotoPecaProdutos = uploadFotoPecaProdutos;

window.adicionarCorModalProduto = adicionarCorModalProduto;
window.removerCorModalProduto = removerCorModalProduto;
window.atualizarCorModalProduto = atualizarCorModalProduto;
window.adicionarPecaModalProduto = adicionarPecaModalProduto;
window.removerPecaModalProduto = removerPecaModalProduto;
window.atualizarPecaModalProduto = atualizarPecaModalProduto;

carregar();
