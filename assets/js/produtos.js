// Módulo Produtos: lista + cadastro/edição em modal + Ficha Técnica (BOM)
let produtos = [];
let editandoId = null;
let bomProdutoAtualId = null;
let bomPecasDisponiveis = [];
let bomLinhasEditor = [];

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
        $('bomSubModal').textContent = `Cadastre as peças que compõem este produto (uma ou mais cores por peça) e simule a capacidade de montagem.`;

        renderizarDiagnosticoBOM(bomData);

        // Inicializa o editor de peças, cada uma com sua lista de cores.
        bomLinhasEditor = (bomData.pecas || []).map(p => ({
            peca_id: p.peca_id,
            nome: p.nome,
            quantidade: p.por_unidade,
            foto: p.foto || '',
            cores: (p.cores && p.cores.length ? p.cores : [{ cor_id: 0, cor: null, estoque: 0, foto: null }])
                .map(c => ({ cor_id: c.cor_id || 0, cor: c.cor || '', estoque: c.estoque || 0 })),
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

const novaLinhaPeca = () => ({ peca_id: 0, nome: '', quantidade: 1, foto: '', cores: [novaLinhaCor()] });
const novaLinhaCor = () => ({ cor_id: 0, cor: '', estoque: 0 });

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

        // Cores da peça, com o saldo de cada uma — "qualquer cor" quando não tem nome.
        const cores = (p.cores || []);
        const coresHtml = cores.length
            ? cores.map(c => `<span class="chip-cor-diag">${esc(c.cor || 'qualquer cor')}: <strong>${App.fmtInt.format(c.estoque)}</strong></span>`).join(' ')
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

// ============================================================
// Editor de Peças: um bloco por peça (nome, qtd/un, foto) com uma lista
// de cores aninhada (cada cor com seu próprio saldo). O saldo de uma cor
// JÁ CADASTRADA (tem cor_id) é somente leitura aqui — quem manda nele é a
// Bancada da Fábrica; só o saldo inicial de uma cor NOVA é editável.
// ============================================================
function renderizarEditorBOM() {
    const container = $('listaEditorBOM');
    if (!bomLinhasEditor.length) {
        container.innerHTML = `<p class="vazio" style="padding:12px 0;">Nenhuma peça cadastrada. Clique em "+ Adicionar Peça".</p>`;
        return;
    }

    container.innerHTML = bomLinhasEditor.map((linha, idx) => {
        const fotoBtn = linha.foto
            ? `<img src="${esc(linha.foto)}" class="foto-peca-editor" onclick="uploadFotoPecaProdutos(${idx})" title="Clique para trocar a foto">`
            : `<button type="button" class="btn-icone foto-peca-editor-vazia" onclick="uploadFotoPecaProdutos(${idx})" title="Adicionar foto">📷</button>`;

        const coresHtml = linha.cores.map((cor, cidx) => `
            <div class="linha-cor-editor">
                <input type="text" placeholder="Nome da cor (deixe em branco se não importar)"
                       value="${esc(cor.cor || '')}"
                       oninput="atualizarCorBOM(${idx}, ${cidx}, 'cor', this.value)">
                ${cor.cor_id
                    ? `<input type="number" value="${cor.estoque || 0}" readonly tabindex="-1"
                              title="O saldo é atualizado na Bancada da Fábrica, a cada peça impressa."
                              class="saldo-cor-editor saldo-cor-editor-travado">`
                    : `<input type="number" min="0" value="${cor.estoque || 0}"
                              oninput="atualizarCorBOM(${idx}, ${cidx}, 'estoque', this.value)"
                              title="Saldo inicial desta cor nova."
                              class="saldo-cor-editor">`}
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
                           class="qtd-peca-editor">
                </label>
                <button type="button" class="btn-icone perigo" onclick="removerLinhaBOM(${idx})" title="Remover peça">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="lista-cores-editor">
                <span class="rotulo-cores-editor">Cores desta peça — qualquer uma serve para montar:</span>
                ${coresHtml}
                <button type="button" class="secundario pequeno" onclick="adicionarCorBOM(${idx})">+ Adicionar cor</button>
            </div>
        </div>`;
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
    } else {
        bomLinhasEditor[idx][campo] = valor;
    }
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
    if (campo === 'estoque') {
        cor[campo] = Math.max(0, parseInt(valor) || 0);
    } else {
        cor[campo] = valor;
    }
}

async function salvarBOM() {
    if (!bomProdutoAtualId) return;

    // Filtra peças com nome preenchido. peca_id e cor_id vão junto para o
    // servidor casar as linhas por id: peça/cor que já existe mantém o
    // saldo impresso (quem manda nele é a Bancada da Fábrica).
    const itensValidos = bomLinhasEditor
        .filter(l => (l.nome || '').trim() !== '')
        .map(l => ({
            peca_id: parseInt(l.peca_id) || 0,
            nome: l.nome.trim(),
            quantidade: Math.max(1, parseInt(l.quantidade) || 1),
            foto: (l.foto || '').trim(),
            cores: l.cores.map(c => ({
                cor_id: parseInt(c.cor_id) || 0,
                cor: (c.cor || '').trim(),
                estoque: Math.max(0, parseInt(c.estoque) || 0), // só usado em cor nova
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

$('btnRecalcularMeta')?.addEventListener('click', recalcularMeta);
$('metaSimulacao')?.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); recalcularMeta(); } });
$('btnExecutarMontagem')?.addEventListener('click', executarMontagem);
$('btnAdicionarLinhaBOM')?.addEventListener('click', adicionarLinhaBOM);
$('btnSalvarBOM')?.addEventListener('click', salvarBOM);

// Torna abrirFicha e manipuladores globais para onclick inline
window.abrirFicha = abrirFicha;
window.removerLinhaBOM = removerLinhaBOM;
window.atualizarLinhaBOM = atualizarLinhaBOM;
window.adicionarCorBOM = adicionarCorBOM;
window.removerCorBOM = removerCorBOM;
window.atualizarCorBOM = atualizarCorBOM;
window.uploadFotoPecaProdutos = uploadFotoPecaProdutos;

carregar();
