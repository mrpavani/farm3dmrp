// Módulo Produtos: lista + cadastro/edição em modal + Ficha Técnica (BOM)
let produtos = [];
let editandoId = null;
let bomProdutoAtualId = null;
let bomPecasDisponiveis = [];
let bomLinhasEditor = [];

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
        const ehComposto = p.tipo === 'composto' || Number(p.qtd_pecas) > 0;
        return `
        <tr class="${usado ? '' : 'linha-inativa'}">
            <td>
                <strong>${App.esc(p.nome)}</strong>
                ${badgeTipo(p.tipo)}
                ${p.descricao ? `<span class="sub-linha descricao-curta" title="${App.esc(p.descricao)}">${App.esc(p.descricao)}</span>` : ''}
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

function abrirModal(p = null) {
    editandoId = p ? p.id : null;
    $('formProduto').reset();
    $('prodNome').value = p ? p.nome : '';
    $('prodTipo').value = p ? (p.tipo || 'simples') : 'simples';
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
        tipo: $('prodTipo').value,
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
            const res = await App.api('api/produtos.php', 'POST', payload);
            App.toast(`Produto "${payload.nome}" cadastrado.`);
            if (payload.tipo === 'composto' && res.id) {
                setTimeout(() => abrirFicha(res.id), 300);
            }
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
// ============================================================

async function abrirFicha(id) {
    bomProdutoAtualId = id;
    const meta = parseInt($('metaSimulacao').value) || 1;
    try {
        const bomData = await App.api(`api/produtos_composicao.php?produto_pai_id=${id}&meta=${meta}`);

        $('bomTituloModal').textContent = `Ficha Técnica: ${bomData.produto.nome}`;
        $('bomSubModal').textContent = `Cadastre as peças que compõem este produto (quantidade e cor) e simule a capacidade de montagem.`;
        
        renderizarDiagnosticoBOM(bomData);
        
        // Inicializa o editor de peças
        bomLinhasEditor = (bomData.pecas || []).map(p => ({
            peca_id: p.peca_id,
            nome: p.nome,
            cor: p.cor || '',
            quantidade: p.por_unidade,
            estoque: p.estoque_atual,
            foto: p.foto || ''
        }));
        
        if (!bomLinhasEditor.length) {
            bomLinhasEditor.push({ peca_id: 0, nome: '', cor: '', quantidade: 1, estoque: 0, foto: '' });
        }
        renderizarEditorBOM();

        App.modal.abrir('modalComposicao');
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

function renderizarDiagnosticoBOM(data) {
    const kpiBox = $('kpiCapacidadeBox');
    const cap = data.capacidade_maxima || 0;
    $('kpiCapacidadeQtd').textContent = cap;
    $('kpiEstoquePronto').textContent = data.produto.estoque || 0;

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

    const tbody = $('tabelaDiagnosticoBOM');
    if (!data.pecas || !data.pecas.length) {
        tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-3); padding: 18px;">
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

        return `
        <tr class="${ehGargalo && cap === 0 ? 'linha-gargalo' : ''}">
            <td style="text-align: center; padding: 4px;">${fotoThumb}</td>
            <td>
                <strong>${App.esc(p.nome)}</strong>
                ${ehGargalo ? '<span class="tag-gargalo">Gargalo</span>' : ''}
            </td>
            <td>${App.esc(p.cor || '—')}</td>
            <td class="num">${p.por_unidade} un</td>
            <td class="num" style="font-weight: 600;">${App.fmtInt.format(p.estoque_atual)} un</td>
            <td class="num">${App.fmtInt.format(p.total_necessario_meta)} un</td>
            <td>
                ${falta 
                    ? `<span class="tag-situacao-falta">Faltam ${App.fmtInt.format(p.faltam_para_meta)} un p/ imprimir</span>` 
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
        `Confirmar a montagem de ${qtd} unidade(s)? O estoque das peças será baixado e o produto final será incrementado.`,
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

// Editor de Peças do Produto (adicionar/remover linhas com nome, cor, quantidade e estoque)
function renderizarEditorBOM() {
    const tbody = $('tabelaEditorBOM');
    if (!bomLinhasEditor.length) {
        tbody.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--text-3); padding: 12px;">
            Nenhuma peça cadastrada. Clique em "+ Adicionar Peça".
        </td></tr>`;
        return;
    }

    tbody.innerHTML = bomLinhasEditor.map((linha, idx) => {
        const fotoBtn = linha.foto
            ? `<img src="${esc(linha.foto)}" style="width:34px;height:34px;border-radius:4px;object-fit:cover;cursor:pointer;display:block;margin:auto;" onclick="uploadFotoPecaProdutos(${idx})" title="Clique para trocar foto">`
            : `<button type="button" class="btn-icone" onclick="uploadFotoPecaProdutos(${idx})" title="Adicionar foto" style="margin:auto;">📷</button>`;

        return `
        <tr>
            <td style="text-align: center; vertical-align: middle; padding: 4px;">
                ${fotoBtn}
            </td>
            <td>
                <input type="text" placeholder="Ex: Hélice, Rotator, Pés..." 
                       value="${App.esc(linha.nome || '')}" 
                       oninput="atualizarLinhaBOM(${idx}, 'nome', this.value)" 
                       style="width: 100%;">
            </td>
            <td>
                <input type="text" placeholder="Ex: Preto, Azul..." 
                       value="${App.esc(linha.cor || '')}" 
                       oninput="atualizarLinhaBOM(${idx}, 'cor', this.value)" 
                       style="width: 100%;">
            </td>
            <td>
                <input type="number" min="1" value="${linha.quantidade || 1}" 
                       oninput="atualizarLinhaBOM(${idx}, 'quantidade', this.value)" 
                       style="width: 100%; text-align: center;">
            </td>
            <td>
                <input type="number" min="0" value="${linha.estoque || 0}" 
                       oninput="atualizarLinhaBOM(${idx}, 'estoque', this.value)" 
                       style="width: 100%; text-align: center;">
            </td>
            <td style="text-align: center;">
                <button type="button" class="btn-icone perigo" onclick="removerLinhaBOM(${idx})" data-tip="Remover peça">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </td>
        </tr>`;
    }).join('');
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
    bomLinhasEditor.push({ peca_id: 0, nome: '', cor: '', quantidade: 1, estoque: 0, foto: '' });
    renderizarEditorBOM();
    // Foca no primeiro input da nova linha
    setTimeout(() => {
        const inputs = $('tabelaEditorBOM').querySelectorAll('input[type="text"]');
        if (inputs.length) inputs[inputs.length - 2].focus();
    }, 40);
}

function removerLinhaBOM(idx) {
    bomLinhasEditor.splice(idx, 1);
    renderizarEditorBOM();
}

function atualizarLinhaBOM(idx, campo, valor) {
    if (bomLinhasEditor[idx]) {
        if (campo === 'quantidade') {
            bomLinhasEditor[idx][campo] = Math.max(1, parseInt(valor) || 1);
        } else if (campo === 'estoque') {
            bomLinhasEditor[idx][campo] = Math.max(0, parseInt(valor) || 0);
        } else {
            bomLinhasEditor[idx][campo] = valor;
        }
    }
}

async function salvarBOM() {
    if (!bomProdutoAtualId) return;

    // Filtrar linhas com nome preenchido
    const itensValidos = bomLinhasEditor
        .filter(l => (l.nome || '').trim() !== '')
        .map(l => ({
            nome: l.nome.trim(),
            cor: (l.cor || '').trim(),
            quantidade: Math.max(1, parseInt(l.quantidade) || 1),
            estoque: Math.max(0, parseInt(l.estoque) || 0),
            foto: (l.foto || '').trim()
        }));

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

$('btnRecalcularMeta')?.addEventListener('click', recalcularMeta);
$('metaSimulacao')?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); recalcularMeta(); } });
$('btnExecutarMontagem')?.addEventListener('click', executarMontagem);
$('btnAdicionarLinhaBOM')?.addEventListener('click', adicionarLinhaBOM);
$('btnSalvarBOM')?.addEventListener('click', salvarBOM);

// Torna abrirFicha e manipuladores globais para onclick inline
window.abrirFicha = abrirFicha;
window.removerLinhaBOM = removerLinhaBOM;
window.atualizarLinhaBOM = atualizarLinhaBOM;
window.uploadFotoPecaProdutos = uploadFotoPecaProdutos;

carregar();



