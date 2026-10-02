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
    const tipoFiltro = $('filtroTipo') ? $('filtroTipo').value : '';
    const lista = produtos.filter(p => {
        if (termo && !App.normalizar(`${p.nome} ${p.descricao || ''} ${p.tipo || ''}`).includes(termo)) return false;
        if (tipoFiltro && p.tipo !== tipoFiltro) return false;
        return true;
    });

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
                <span class="sub-linha" style="font-size:11.5px;color:var(--text-3);margin-top:2px;">
                    ${(Number(p.peso_gramas) > 0 || Number(p.tempo_producao_segundos) > 0)
                        ? `⏱️ ${p.tempo_producao_formatado || formatarSegundosHHMMSS(p.tempo_producao_segundos)} · ⚖️ ${p.peso_gramas}g/un · `
                        : ''}
                    <strong style="color:var(--text);font-weight:600;">💰 ${App.moeda(p.preco || 0)}</strong>
                    ${Number(p.custo_total) > 0 ? ` <span style="color:var(--text-3);font-size:10.5px;">(Custo: ${App.moeda(p.custo_total)})</span>` : ''}
                </span>
            </td>
            <td class="num" style="font-weight:600;">${App.fmtInt.format(Number(p.estoque) || 0)}</td>
            <td class="num">${App.fmtInt.format(p.qtd_pedidos)}</td>
            <td class="col-acoes">
                <div class="acoes-icones">
                    ${App.botaoIcone('ficha', 'Ficha Técnica & Montagem (Peças)', `abrirFicha(${p.id})`, 'primario')}
                    ${App.botaoIcone('custos', 'Custos & Formação de Preço de Venda', `abrirCustosProduto(${p.id})`, 'sucesso')}
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

// Variáveis do modal exclusivo de custos e precificação
let custosProdutoAtualId = null;
let custosProdutoAtualDados = null;
let precoManualCadastrado = false;
let custoFilManual = false;
let mapaCustosPorCor = {};

function calcularCustoEstimadoFilamento(tipo, pesoTotal) {
    let custoTotal = 0;
    let temCoresDetalhadas = false;

    if (tipo === 'composto' && modalProdPecas && modalProdPecas.length > 0) {
        modalProdPecas.forEach(pec => {
            const q = Math.max(1, parseInt(pec.quantidade) || 1);
            if (pec.cores && pec.cores.length > 0) {
                pec.cores.forEach(c => {
                    const cPeso = parseFloat(c.peso_gramas) || 0;
                    if (cPeso > 0) {
                        temCoresDetalhadas = true;
                        const corNome = (c.cor || '').trim().toLowerCase();
                        const precoG = mapaCustosPorCor[corNome] || 0.0900;
                        custoTotal += (cPeso * q) * precoG;
                    }
                });
            } else {
                const pPeso = parseFloat(pec.peso_gramas) || 0;
                if (pPeso > 0) {
                    custoTotal += (pPeso * q) * 0.0900;
                }
            }
        });
    } else if (tipo === 'simples' && $('checkProdMulticor') && $('checkProdMulticor').checked && modalProdCores && modalProdCores.length > 0) {
        modalProdCores.forEach(c => {
            const cPeso = parseFloat(c.peso_gramas) || 0;
            if (cPeso > 0) {
                temCoresDetalhadas = true;
                const corNome = (c.cor || '').trim().toLowerCase();
                const precoG = mapaCustosPorCor[corNome] || 0.0900;
                custoTotal += cPeso * precoG;
            }
        });
    }

    if (custoTotal > 0) {
        return round2(custoTotal);
    }
    return round2((pesoTotal || 0) * 0.0900);
}

function recalcularFormacaoPreco(forcarAtualizarPreco = false) {
    const peso = parseFloat($('prodPeso')?.value) || 0;
    const tipo = $('prodTipo')?.value || 'simples';
    const temPecas = tipo === 'composto' && modalProdPecas && modalProdPecas.length > 0;
    const ehMulticor = tipo === 'simples' && $('checkProdMulticor') && $('checkProdMulticor').checked;

    let custoFil = parseFloat($('prodCustoFilamento')?.value);
    
    // Sempre recalcula dinamicamente se for composto ou multicor, ou se não foi forçado manualmente
    if (!custoFilManual || temPecas || ehMulticor || isNaN(custoFil) || custoFil <= 0) {
        custoFil = calcularCustoEstimadoFilamento(tipo, peso);
        if ($('prodCustoFilamento')) $('prodCustoFilamento').value = custoFil.toFixed(2);
    }

    const temEmb = $('prodTemEmbalagem')?.value === '1';
    const valorEmb = temEmb ? (parseFloat($('prodValorEmbalagem')?.value) || 0) : 0;
    const valorOutros = parseFloat($('prodValorOutros')?.value) || 0;
    const margem = parseFloat($('prodMargemLucro')?.value) || 100;

    const custoTotal = round2(custoFil + valorEmb + valorOutros);
    const valorMargem = round2(custoTotal * (margem / 100));
    const precoSugerido = round2(custoTotal + valorMargem);

    if ($('prodResumoRapidoFil')) $('prodResumoRapidoFil').textContent = App.moeda(custoFil);
    if ($('prodResumoRapidoTotal')) $('prodResumoRapidoTotal').textContent = App.moeda(custoTotal);
    if ($('prodResumoRapidoMargem')) $('prodResumoRapidoMargem').textContent = `${margem}%`;

    if (forcarAtualizarPreco || !precoManualCadastrado) {
        if ($('prodPreco')) {
            $('prodPreco').value = precoSugerido > 0 ? precoSugerido.toFixed(2) : '0.00';
        }
    }
}

async function abrirCustosProduto(id) {
    custosProdutoAtualId = id;
    try {
        const detalhe = await App.api(`api/produtos.php?id=${id}`);
        if (!detalhe) return;
        custosProdutoAtualDados = detalhe;

        if ($('custosProdNomeDestaque')) {
            const rotuloTipo = detalhe.tipo === 'composto' ? '🧩 Composto' : (detalhe.tipo === 'componente' ? '⚙️ Peça Avulsa' : '🔹 Simples');
            $('custosProdNomeDestaque').textContent = `${detalhe.nome} · ${rotuloTipo}`;
        }
        if ($('custosSubModal')) {
            $('custosSubModal').textContent = `Precificação, parâmetros de custo e formação de preço de venda de ${detalhe.nome}.`;
        }

        const peso = Number(detalhe.peso_gramas) || 0;
        if ($('custosProdPeso')) $('custosProdPeso').value = peso > 0 ? peso : '';
        
        // Bloqueia edição de peso se for composto com peças ou multicor
        const bloqueadoSoma = (detalhe.tem_pecas && detalhe.pecas && detalhe.pecas.length > 0) || (detalhe.cores && detalhe.cores.length > 0);
        if ($('custosProdPeso')) {
            $('custosProdPeso').readOnly = bloqueadoSoma;
            if (bloqueadoSoma) {
                $('custosProdPeso').classList.add('input-bloqueado-soma');
            } else {
                $('custosProdPeso').classList.remove('input-bloqueado-soma');
            }
        }
        if ($('custosTagOrigemPeso')) {
            if (bloqueadoSoma) {
                $('custosTagOrigemPeso').textContent = detalhe.tem_pecas ? '🔒 Soma das peças' : '🔒 Soma das cores';
                $('custosTagOrigemPeso').style.display = 'inline-block';
            } else {
                $('custosTagOrigemPeso').style.display = 'none';
            }
        }
        if ($('custosDicaProdPeso')) {
            $('custosDicaProdPeso').textContent = bloqueadoSoma ? 'Calculado automaticamente pela soma das peças/cores.' : 'Filamento total estimado para 1 unidade deste produto.';
        }

        if ($('custosProdCustoFilamento')) $('custosProdCustoFilamento').value = detalhe.custo_filamento ? Number(detalhe.custo_filamento).toFixed(2) : '';
        if ($('custosProdTemEmbalagem')) $('custosProdTemEmbalagem').value = Number(detalhe.tem_embalagem) === 1 ? '1' : '0';
        if ($('custosProdValorEmbalagem')) $('custosProdValorEmbalagem').value = detalhe.valor_embalagem ? Number(detalhe.valor_embalagem).toFixed(2) : '0.00';
        if ($('custosBoxValorEmbalagem')) $('custosBoxValorEmbalagem').style.display = Number(detalhe.tem_embalagem) === 1 ? 'flex' : 'none';
        if ($('custosProdValorOutros')) $('custosProdValorOutros').value = detalhe.valor_outros ? Number(detalhe.valor_outros).toFixed(2) : '0.00';
        if ($('custosProdMargemLucro')) $('custosProdMargemLucro').value = (detalhe.margem_lucro !== undefined && detalhe.margem_lucro !== null) ? Number(detalhe.margem_lucro) : 100;
        if ($('custosProdPreco')) $('custosProdPreco').value = detalhe.preco ? Number(detalhe.preco).toFixed(2) : '';

        // Detalhe de cálculo PEPS por cor
        const cFil = detalhe.calculo_custo_filamento;
        if (cFil && cFil.cores && cFil.cores.length > 0) {
            if ($('custosTagOrigem')) $('custosTagOrigem').textContent = `PEPS ativo (${cFil.cores.length} cor/cores)`;
            if ($('custosTabelaCorPeps')) {
                $('custosTabelaCorPeps').innerHTML = cFil.cores.map(c => `
                    <tr>
                        <td><strong>${esc(c.cor || 'Padrão')}</strong></td>
                        <td class="num">${Number(c.gramas).toFixed(1)}g</td>
                        <td>${esc(c.filamento_nome || 'Estimado')}</td>
                        <td class="num">${App.moeda(c.preco_por_kg || 0)}/kg</td>
                        <td class="num" style="font-weight:700;">${App.moeda(c.custo_total || 0)}</td>
                    </tr>
                `).join('');
            }
            if ($('custosBoxDetalhamentoPeps')) $('custosBoxDetalhamentoPeps').style.display = 'block';

            const custoAtual = Number(detalhe.custo_filamento) || 0;
            if (custoAtual <= 0 || (peso > 50 && custoAtual < 1.0)) {
                if ($('custosProdCustoFilamento')) $('custosProdCustoFilamento').value = Number(cFil.custo_filamento).toFixed(2);
            }
        } else {
            if ($('custosTagOrigem')) $('custosTagOrigem').textContent = 'Calculadora dinâmica';
            if ($('custosBoxDetalhamentoPeps')) $('custosBoxDetalhamentoPeps').style.display = 'none';
        }

        recalcularCustosModal(false);
        App.modal.abrir('modalCustosProduto', '#custosProdPreco');
    } catch (e) {
        App.toast('Erro ao abrir custos do produto: ' + e.message, 'erro');
    }
}

function recalcularCustosModal(forcarAtualizarPreco = true) {
    const peso = parseFloat($('custosProdPeso')?.value) || 0;
    let custoFil = parseFloat($('custosProdCustoFilamento')?.value);
    const cFil = custosProdutoAtualDados?.calculo_custo_filamento;

    if (forcarAtualizarPreco && cFil && Number(cFil.custo_filamento) > 0) {
        custoFil = Number(cFil.custo_filamento);
        if ($('custosProdCustoFilamento')) $('custosProdCustoFilamento').value = custoFil.toFixed(2);
    } else if ((isNaN(custoFil) || custoFil === 0) && peso > 0) {
        if (cFil && Number(cFil.custo_filamento) > 0) {
            custoFil = Number(cFil.custo_filamento);
        } else {
            custoFil = round2(peso * 0.0900);
        }
        if ($('custosProdCustoFilamento')) $('custosProdCustoFilamento').value = custoFil.toFixed(2);
    } else if (isNaN(custoFil) || custoFil < 0) {
        custoFil = 0;
    }

    const temEmb = $('custosProdTemEmbalagem')?.value === '1';
    if ($('custosBoxValorEmbalagem')) {
        $('custosBoxValorEmbalagem').style.display = temEmb ? 'flex' : 'none';
    }
    const valorEmb = temEmb ? (parseFloat($('custosProdValorEmbalagem')?.value) || 0) : 0;
    const valorOutros = parseFloat($('custosProdValorOutros')?.value) || 0;
    const margem = parseFloat($('custosProdMargemLucro')?.value) || 0;

    const custoTotal = round2(custoFil + valorEmb + valorOutros);
    const valorMargem = round2(custoTotal * (margem / 100));
    const precoSugerido = round2(custoTotal + valorMargem);

    if ($('custosResumoFilamento')) $('custosResumoFilamento').textContent = App.moeda(custoFil);
    if ($('custosResumoEmbalagem')) $('custosResumoEmbalagem').textContent = App.moeda(valorEmb);
    if ($('custosResumoOutros')) $('custosResumoOutros').textContent = App.moeda(valorOutros);
    if ($('custosResumoCustoTotal')) $('custosResumoCustoTotal').textContent = App.moeda(custoTotal);
    if ($('custosResumoValorMargem')) $('custosResumoValorMargem').textContent = '+ ' + App.moeda(valorMargem);

    if (forcarAtualizarPreco && $('custosProdPreco')) {
        $('custosProdPreco').value = precoSugerido > 0 ? precoSugerido.toFixed(2) : '0.00';
    }
}

function definirMargemCustos(valor) {
    if ($('custosProdMargemLucro')) {
        $('custosProdMargemLucro').value = valor;
        recalcularCustosModal(true);
    }
}

async function salvarCustosModal() {
    if (!custosProdutoAtualId) return;
    const preco = parseFloat($('custosProdPreco')?.value);
    if (isNaN(preco) || preco < 0) {
        App.toast('Informe um preço de venda válido.', 'erro');
        $('custosProdPreco')?.focus();
        return;
    }

    const temEmb = $('custosProdTemEmbalagem')?.value === '1';
    const payload = {
        custo_filamento: parseFloat($('custosProdCustoFilamento')?.value) || 0,
        tem_embalagem: temEmb ? 1 : 0,
        valor_embalagem: temEmb ? (parseFloat($('custosProdValorEmbalagem')?.value) || 0) : 0,
        valor_outros: parseFloat($('custosProdValorOutros')?.value) || 0,
        margem_lucro: parseFloat($('custosProdMargemLucro')?.value) || 0,
        preco: preco,
        peso_gramas: parseFloat($('custosProdPeso')?.value) || 0
    };

    const btn = $('btnSalvarCustosModal');
    if (btn) btn.disabled = true;

    try {
        await App.api(`api/produtos.php?id=${custosProdutoAtualId}&apenas_custos=1`, 'PUT', payload);
        App.toast('Precificação atualizada com sucesso!', 'sucesso');
        App.modal.fechar('modalCustosProduto');
        carregar();

        // Se o modal de produto estiver aberto, sincroniza os campos
        if (editandoId === custosProdutoAtualId) {
            $('prodPreco').value = payload.preco.toFixed(2);
            $('prodCustoFilamento').value = payload.custo_filamento.toFixed(2);
            $('prodTemEmbalagem').value = payload.tem_embalagem ? '1' : '0';
            $('prodValorEmbalagem').value = payload.valor_embalagem.toFixed(2);
            $('prodValorOutros').value = payload.valor_outros.toFixed(2);
            $('prodMargemLucro').value = payload.margem_lucro;
            recalcularFormacaoPreco(false);
        }
    } catch (e) {
        App.toast('Erro ao salvar precificação: ' + e.message, 'erro');
    } finally {
        if (btn) btn.disabled = false;
    }
}

function round2(num) {
    return Math.round((Number(num) + Number.EPSILON) * 100) / 100;
}

let sugestoesCoresCache = [];
async function carregarSugestoesCores() {
    try {
        const lista = await App.api('api/filamentos.php');
        const coresSet = new Set();
        mapaCustosPorCor = {};
        (lista || []).forEach(f => {
            if (f.cor && f.cor.trim()) {
                const corTrim = f.cor.trim();
                coresSet.add(corTrim);
                const precoG = Number(f.custo_peps_g) || Number(f.custo_medio_g) || ((Number(f.preco_kg) || 90) / 1000);
                if (precoG > 0) {
                    mapaCustosPorCor[corTrim.toLowerCase()] = precoG;
                }
            }
        });
        ['Branco', 'Preto', 'Cinza', 'Vermelho', 'Azul', 'Amarelo', 'Verde', 'Laranja', 'Roxo', 'Rosa', 'Marrom', 'Dourado', 'Prata', 'Transparente', 'Bege'].forEach(c => coresSet.add(c));
        sugestoesCoresCache = Array.from(coresSet).sort((a, b) => a.localeCompare(b, 'pt-BR'));
        const dl = $('listaCoresFilamentosSugestoes');
        if (dl) {
            dl.innerHTML = sugestoesCoresCache.map(c => `<option value="${esc(c)}">`).join('');
        }
    } catch (_) {}
}

function obterHexCor(corNome) {
    if (!corNome) return '#94a3b8';
    const c = corNome.trim().toLowerCase();
    if (c.includes('bran') || c.includes('white')) return '#ffffff';
    if (c.includes('pret') || c.includes('black')) return '#1e293b';
    if (c.includes('cinz') || c.includes('gray') || c.includes('grey') || c.includes('chumb')) return '#64748b';
    if (c.includes('verm') || c.includes('red')) return '#ef4444';
    if (c.includes('azul') || c.includes('blue')) return '#3b82f6';
    if (c.includes('amar') || c.includes('yellow')) return '#eab308';
    if (c.includes('verd') || c.includes('green')) return '#22c55e';
    if (c.includes('laran') || c.includes('orange')) return '#f97316';
    if (c.includes('rox') || c.includes('purpl') || c.includes('violet')) return '#a855f7';
    if (c.includes('ros') || c.includes('pink')) return '#ec4899';
    if (c.includes('marr') || c.includes('brown')) return '#78350f';
    if (c.includes('dour') || c.includes('gold')) return '#d97706';
    if (c.includes('prat') || c.includes('silver')) return '#cbd5e1';
    if (c.includes('transp') || c.includes('clear')) return '#e2e8f0';
    if (c.includes('bege') || c.includes('skin')) return '#fde68a';
    return '#6366f1';
}

function novaCorModalProd() {
    return { id: 0, cor: '', peso_gramas: '', tempo_producao_segundos: 0, tempo_formatado: '' };
}

function novaLinhaCorPeca(cor = '', peso = '') {
    return {
        cor_id: 0,
        cor: cor,
        peso_gramas: (peso !== '' && peso !== null && peso !== undefined) ? Math.max(0, parseFloat(peso) || 0) : ''
    };
}

function novaPecaModalProd(nome = '', qtd = 1, tempo = '00:00:00', peso = '') {
    return {
        peca_id: 0,
        nome: nome,
        quantidade: qtd,
        peso_gramas: peso,
        tempo_producao_segundos: parseTempoParaSegundos(tempo),
        tempo_formatado: tempo,
        cores: []
    };
}

function atualizarModoModalProduto() {
    const tipo = $('prodTipo') ? $('prodTipo').value : 'simples';
    const temPecas = tipo === 'composto';
    const boxPecas = $('boxPecasProduto');
    const boxMulticor = $('boxMulticorProduto');
    const inputTempo = $('prodTempo');
    const inputPeso = $('prodPeso');
    const tagTempo = $('tagOrigemTempo');
    const tagPeso = $('tagOrigemPeso');
    const dicaTempo = $('dicaProdTempo');
    const dicaPeso = $('dicaProdPeso');
    const dicaTipo = $('prodTipoExplicacao');

    if (dicaTipo) {
        if (tipo === 'composto') {
            dicaTipo.innerHTML = `🧩 <b>Produto com Peças:</b> O tempo e filamento total são <b>calculados automaticamente</b> pela soma das peças abaixo.`;
            dicaTipo.className = 'prod-tipo-dica destaque-composto';
        } else if (tipo === 'componente') {
            dicaTipo.innerHTML = `⚙️ <b>Peça Avulsa / Reposição:</b> Item unitário avulso. Se for um produto montado com mais peças, selecione <b>Produto Composto</b>.`;
            dicaTipo.className = 'prod-tipo-dica';
        } else {
            dicaTipo.innerHTML = `🔹 <b>Produto Simples:</b> Impresso em 1 peça só (sem montagem). Se tiver várias peças, selecione <b>Produto Composto</b>.`;
            dicaTipo.className = 'prod-tipo-dica';
        }
    }

    if (temPecas) {
        if (boxPecas) boxPecas.style.display = 'block';
        if (boxMulticor) boxMulticor.style.display = 'none';

        if (inputTempo) { inputTempo.readOnly = true; inputTempo.classList.add('input-bloqueado-soma'); }
        if (inputPeso) { inputPeso.readOnly = true; inputPeso.classList.add('input-bloqueado-soma'); }

        if (tagTempo) { tagTempo.textContent = '🔒 Calculado pelas peças'; tagTempo.style.display = 'inline-block'; }
        if (tagPeso) { tagPeso.textContent = '🔒 Calculado pelas peças'; tagPeso.style.display = 'inline-block'; }
        if (dicaTempo) { dicaTempo.textContent = 'Tempo total somado das peças necessárias para 1 produto.'; dicaTempo.style.display = 'block'; }
        if (dicaPeso) { dicaPeso.textContent = 'Filamento total somado das peças necessárias para 1 produto.'; dicaPeso.style.display = 'block'; }

        // Recalcula soma das peças para 1 produto
        let somaPeso = 0;
        let somaTempo = 0;
        let totalPecasQtd = 0;
        const resumoCoresGerais = {};

        modalProdPecas.forEach(pec => {
            const q = Math.max(1, parseInt(pec.quantidade) || 1);
            totalPecasQtd += q;
            // Se a peça tem cores com peso, atualiza peso da peça
            if (pec.cores && pec.cores.length > 0) {
                let somaCores = 0;
                pec.cores.forEach(c => {
                    const cPeso = parseFloat(c.peso_gramas) || 0;
                    somaCores += cPeso;
                    const cNome = (c.cor || '').trim() || 'Padrão';
                    resumoCoresGerais[cNome] = (resumoCoresGerais[cNome] || 0) + (cPeso * q);
                });
                pec.peso_gramas = somaCores > 0 ? (Math.round(somaCores * 100) / 100) : (pec.cores.some(c => c.peso_gramas !== '') ? 0 : '');
            } else if (parseFloat(pec.peso_gramas) > 0) {
                resumoCoresGerais['Monocor'] = (resumoCoresGerais['Monocor'] || 0) + (parseFloat(pec.peso_gramas) * q);
            }
            somaPeso += (parseFloat(pec.peso_gramas) || 0) * q;
            somaTempo += (parseInt(pec.tempo_producao_segundos) || 0) * q;
        });

        const pesoFormatado = somaPeso > 0 ? (Math.round(somaPeso * 10) / 10).toFixed(1) : (modalProdPecas.length ? '0.0' : inputPeso.value);
        const tempoFormatado = somaTempo > 0 ? formatarSegundosHHMMSS(somaTempo) : (modalProdPecas.length ? '00:00:00' : inputTempo.value);

        if (inputPeso) inputPeso.value = pesoFormatado;
        if (inputTempo) inputTempo.value = tempoFormatado;

        if ($('resumoPecasProdBadge')) {
            const coresEntries = Object.entries(resumoCoresGerais).filter(([, g]) => g > 0);
            let coresTxt = '';
            if (coresEntries.length > 1) {
                coresTxt = ' · ' + coresEntries.map(([nome, g]) => `${nome}: ${(Math.round(g * 10) / 10).toFixed(1)}g`).join(', ');
            }
            $('resumoPecasProdBadge').textContent = `${modalProdPecas.length} tipos (${totalPecasQtd} un) · ${pesoFormatado}g · ${tempoFormatado}${coresTxt}`;
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

    recalcularFormacaoPreco(temPecas ? true : false);
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

function adicionarCoresIniciaisPeca(pecaIdx) {
    const pec = modalProdPecas[pecaIdx];
    if (!pec) return;
    if (!pec.cores) pec.cores = [];
    const pesoAtual = parseFloat(pec.peso_gramas) || 0;
    if (pesoAtual > 0) {
        pec.cores.push(novaLinhaCorPeca('Branco', (pesoAtual * 0.7).toFixed(2)));
        pec.cores.push(novaLinhaCorPeca('Preto', (pesoAtual * 0.3).toFixed(2)));
    } else {
        pec.cores.push(novaLinhaCorPeca('Branco', ''));
        pec.cores.push(novaLinhaCorPeca('Preto', ''));
    }
    renderizarPecasModalProduto();
    setTimeout(() => {
        const bloco = $('listaPecasModalProd')?.querySelectorAll('.bloco-peca-modal-prod')[pecaIdx];
        const inputCor = bloco?.querySelector('.grade-cores-peca input[type="text"]');
        if (inputCor) {
            inputCor.focus();
            inputCor.select();
        }
    }, 40);
}

function adicionarCorPecaModal(pecaIdx, corNome = '', peso = '') {
    const pec = modalProdPecas[pecaIdx];
    if (!pec) return;
    if (!pec.cores) pec.cores = [];
    pec.cores.push(novaLinhaCorPeca(corNome, peso));
    renderizarPecasModalProduto();
    setTimeout(() => {
        const bloco = $('listaPecasModalProd')?.querySelectorAll('.bloco-peca-modal-prod')[pecaIdx];
        const inputs = bloco?.querySelectorAll('.grade-cores-peca .input-cor-nome');
        if (inputs && inputs.length) {
            inputs[inputs.length - 1].focus();
        }
    }, 40);
}

function removerCorPecaModal(pecaIdx, corIdx) {
    const pec = modalProdPecas[pecaIdx];
    if (!pec || !pec.cores) return;
    pec.cores.splice(corIdx, 1);
    renderizarPecasModalProduto();
}

function atualizarCorPecaModal(pecaIdx, corIdx, campo, valor) {
    const pec = modalProdPecas[pecaIdx];
    if (!pec || !pec.cores || !pec.cores[corIdx]) return;
    const c = pec.cores[corIdx];
    if (campo === 'cor') {
        c.cor = valor;
        const bloco = $('listaPecasModalProd')?.querySelectorAll('.bloco-peca-modal-prod')[pecaIdx];
        const itemCor = bloco?.querySelectorAll('.item-cor-peca')[corIdx];
        const ponto = itemCor?.querySelector('.ponto-cor-indicador');
        if (ponto) ponto.style.backgroundColor = obterHexCor(valor);
    } else if (campo === 'peso') {
        c.peso_gramas = valor === '' ? '' : Math.max(0, parseFloat(valor) || 0);
    }

    let somaCores = 0;
    pec.cores.forEach(corItem => {
        somaCores += (parseFloat(corItem.peso_gramas) || 0);
    });
    pec.peso_gramas = somaCores > 0 ? (Math.round(somaCores * 100) / 100) : (pec.cores.some(ci => ci.peso_gramas !== '') ? 0 : '');

    const bloco = $('listaPecasModalProd')?.querySelectorAll('.bloco-peca-modal-prod')[pecaIdx];
    if (bloco) {
        const inputPesoPeca = bloco.querySelector('.col-peso-peca-input');
        if (inputPesoPeca) inputPesoPeca.value = pec.peso_gramas !== '' ? pec.peso_gramas : '';
        const q = Math.max(1, parseInt(pec.quantidade) || 1);
        const subtotalEl = bloco.querySelector('.peca-subtotal-info strong');
        if (subtotalEl) subtotalEl.textContent = ((parseFloat(pec.peso_gramas) || 0) * q).toFixed(1) + 'g';
    }

    atualizarModoModalProduto();
}

function renderizarPecasModalProduto() {
    const container = $('listaPecasModalProd');
    if (!container) return;

    if (!modalProdPecas.length) {
        container.innerHTML = `
            <div class="pecas-vazio-alerta">
                <span style="font-size:24px;">🧩</span>
                <div>
                    <strong>Nenhuma peça adicionada ainda</strong>
                    <p>Adicione as peças que compõem este produto (ex: Base, Golfinho, Peças do Empilhar). O tempo e gramas do produto são calculados automaticamente por elas.</p>
                </div>
            </div>`;
        atualizarModoModalProduto();
        return;
    }

    container.innerHTML = `
        <div class="tabela-pecas-cabecalho">
            <span style="flex:2;">Peça / Componente</span>
            <span style="width:75px; text-align:center;">Qtd/Prod</span>
            <span style="width:115px; text-align:center;">Tempo (HH:mm:ss)</span>
            <span style="width:105px; text-align:center;">Filamento (g)</span>
            <span style="width:130px; text-align:right;">Subtotal da Peça</span>
            <span style="width:34px;"></span>
        </div>
        ${modalProdPecas.map((pec, idx) => {
            const q = Math.max(1, parseInt(pec.quantidade) || 1);
            const temCores = pec.cores && pec.cores.length > 0;
            const p = parseFloat(pec.peso_gramas) || 0;
            const subtotalPeso = (p * q).toFixed(1);
            const t = parseInt(pec.tempo_producao_segundos) || 0;
            const subtotalTempo = formatarSegundosHHMMSS(t * q);

            let coresHtml = '';
            if (temCores) {
                const linhasCores = pec.cores.map((c, cidx) => {
                    const hex = obterHexCor(c.cor);
                    return `
                    <div class="item-cor-peca" style="display:flex; align-items:center; gap:6px; margin-bottom:6px;">
                        <span class="ponto-cor-indicador" style="background:${hex}; width:12px; height:12px; border-radius:50%; border:1px solid rgba(0,0,0,0.15); flex-shrink:0;"></span>
                        <input type="text" list="listaCoresFilamentosSugestoes" class="input-cor-nome" placeholder="Cor (ex: Branco, Preto...)"
                               value="${esc(c.cor || '')}"
                               oninput="atualizarCorPecaModal(${idx}, ${cidx}, 'cor', this.value)"
                               style="font-size:12px; flex:2; padding:4px 8px;">
                        <div style="display:flex; align-items:center; gap:3px; flex:1;">
                            <input type="number" min="0" step="0.01" class="input-cor-gramas" placeholder="0.00"
                                   value="${c.peso_gramas !== '' && c.peso_gramas !== null && c.peso_gramas !== undefined ? c.peso_gramas : ''}"
                                   oninput="atualizarCorPecaModal(${idx}, ${cidx}, 'peso', this.value)"
                                   title="Gramas desta cor gastas nesta peça"
                                   style="font-size:12px; text-align:center; padding:4px 6px; width:100%;">
                            <span style="font-size:11px; color:var(--texto-secundario);">g</span>
                        </div>
                        <button type="button" class="btn-icone perigo btn-remover-cor-peca" onclick="removerCorPecaModal(${idx}, ${cidx})"
                                title="Remover esta cor" style="padding:2px 4px;">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" style="width:14px; height:14px;"><path d="M18 6 6 18M6 6l12 12"/></svg>
                        </button>
                    </div>`;
                }).join('');

                coresHtml = `
                <div class="caixa-cores-peca" style="margin-top:8px; padding:10px 12px; background:var(--fundo-secundario, #f8fafc); border-radius:8px; border:1px dashed var(--borda, #cbd5e1);">
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                        <span style="font-size:12px; font-weight:600; color:var(--texto);">🎨 Cores desta peça (gramas por cor; tempo é único da peça):</span>
                        <button type="button" class="secundario pequeno btn-adicionar-cor-peca" onclick="adicionarCorPecaModal(${idx})" style="font-size:11px; padding:2px 8px;">+ Adicionar cor</button>
                    </div>
                    <div class="grade-cores-peca">
                        ${linhasCores}
                    </div>
                </div>`;
            } else {
                coresHtml = `
                <div style="margin-top:6px; display:flex; align-items:center; justify-content:flex-end;">
                    <button type="button" class="link-sublinhado btn-ativar-multicor-peca" onclick="adicionarCoresIniciaisPeca(${idx})" style="font-size:11.5px; background:none; border:none; color:var(--primaria, #3b82f6); cursor:pointer;">
                        🎨 + Adicionar cores / filamento desta peça (ex: Branco e Preto)
                    </button>
                </div>`;
            }

            return `
            <div class="bloco-peca-modal-prod">
                <div class="linha-peca-modal-prod" style="display:flex; align-items:center; gap:8px;">
                    <input type="text" placeholder="Ex: Golfinho, Base, Pino..."
                           value="${esc(pec.nome || '')}"
                           oninput="atualizarPecaModalProduto(${idx}, 'nome', this.value)"
                           style="font-size:13px; font-weight:600; flex:2;">
                    <div style="width:75px;">
                        <input type="number" min="1" placeholder="1"
                               value="${pec.quantidade || 1}"
                               oninput="atualizarPecaModalProduto(${idx}, 'quantidade', this.value)"
                               title="Quantidade desta peça necessária para 1 produto"
                               style="font-size:13px; text-align:center; font-weight:700;">
                    </div>
                    <div style="width:115px;">
                        <input type="text" placeholder="00:00:00"
                               value="${esc(pec.tempo_formatado || '')}"
                               oninput="atualizarPecaModalProduto(${idx}, 'tempo', this.value)"
                               title="Tempo único de impressão desta peça (o tempo é um só)"
                               style="font-size:12.5px; text-align:center;">
                    </div>
                    <div style="width:105px;" class="input-com-unidade">
                        <input type="number" min="0" step="0.01" placeholder="0.00"
                               value="${pec.peso_gramas !== '' && pec.peso_gramas !== null && pec.peso_gramas !== undefined ? pec.peso_gramas : ''}"
                               ${temCores ? 'readonly title="Filamento calculado automaticamente pela soma das cores abaixo"' : 'title="Filamento gasto por unidade desta peça (em gramas)"'}
                               oninput="atualizarPecaModalProduto(${idx}, 'peso', this.value)"
                               class="col-peso-peca-input ${temCores ? 'input-bloqueado-soma' : ''}"
                               style="font-size:12.5px; text-align:center; padding-right:20px !important;">
                        <span class="unidade" style="right:6px; font-size:11px;">g</span>
                    </div>
                    <div class="peca-subtotal-info" style="width:130px; text-align:right;">
                        <strong>${subtotalPeso}g</strong>
                        <small>⏱️ ${subtotalTempo}</small>
                    </div>
                    <button type="button" class="btn-icone perigo" onclick="removerPecaModalProduto(${idx})"
                            title="Remover peça">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                    </button>
                </div>
                ${coresHtml}
            </div>`;
        }).join('')}
    `;

    atualizarModoModalProduto();
}

function adicionarPecaModalProduto() {
    modalProdPecas.push(novaPecaModalProd());
    renderizarPecasModalProduto();
    setTimeout(() => {
        const inputs = $('listaPecasModalProd')?.querySelectorAll('.bloco-peca-modal-prod input[type="text"]');
        if (inputs && inputs.length) inputs[inputs.length - 1].focus();
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
    precoManualCadastrado = (p && p.preco !== null && Number(p.preco) > 0);
    custoFilManual = (p && p.custo_filamento !== null && Number(p.custo_filamento) > 0 && !(Number(p.peso_gramas) > 50 && Number(p.custo_filamento) < 1.0));

    $('formProduto').reset();
    $('prodNome').value = p ? p.nome : '';
    $('prodTipo').value = p ? (p.tipo || 'simples') : 'simples';
    $('prodPreco').value = p ? Number(p.preco).toFixed(2) : '';
    $('prodEstoque').value = p ? Number(p.estoque) : '0';
    if ($('prodPeso')) $('prodPeso').value = p && Number(p.peso_gramas) > 0 ? Number(p.peso_gramas) : '';
    if ($('prodTempo')) $('prodTempo').value = p ? (p.tempo_producao_formatado || (p.tempo_producao_segundos ? formatarSegundosHHMMSS(p.tempo_producao_segundos) : '')) : '';
    $('prodAtivo').value = p ? String(p.ativo) : '1';
    $('prodDescricao').value = p ? (p.descricao || '') : '';

    // Campos de Precificação
    if ($('prodTemEmbalagem')) $('prodTemEmbalagem').value = p && Number(p.tem_embalagem) === 1 ? '1' : '0';
    if ($('prodValorEmbalagem')) $('prodValorEmbalagem').value = p && p.valor_embalagem ? Number(p.valor_embalagem).toFixed(2) : '0.00';
    if ($('prodValorOutros')) $('prodValorOutros').value = p && p.valor_outros ? Number(p.valor_outros).toFixed(2) : '0.00';
    if ($('prodMargemLucro')) $('prodMargemLucro').value = p && p.margem_lucro !== undefined && p.margem_lucro !== null ? Number(p.margem_lucro) : 100;
    if ($('prodCustoFilamento')) $('prodCustoFilamento').value = p && p.custo_filamento ? Number(p.custo_filamento).toFixed(2) : '';

    $('prodTituloModal').textContent = p ? 'Editar produto' : 'Novo produto';
    $('prodSubModal').textContent = p ? p.nome : 'Cadastre produtos com tempo, filamento por cor ou composição de peças.';
    $('btnSalvarProduto').textContent = p ? 'Salvar alterações' : 'Cadastrar produto';

    modalProdCores = [];
    modalProdPecas = [];

    // Se for produto composto, cria 2 linhas de peças por padrão para facilidade do usuário
    if (!p && $('prodTipo').value === 'composto') {
        modalProdPecas = [novaPecaModalProd(), novaPecaModalProd()];
    }

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

    carregarSugestoesCores();
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
                        cores: (pec.cores || [])
                            .filter(c => c.cor && c.cor.trim())
                            .map(c => ({
                                cor_id: c.cor_id || c.id || 0,
                                cor: c.cor,
                                peso_gramas: (c.peso_gramas !== null && c.peso_gramas !== undefined && c.peso_gramas !== '') ? Number(c.peso_gramas) : ''
                            }))
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
                        if ($('prodCustoFilamento')) $('prodCustoFilamento').value = Number(cFil.custo_filamento).toFixed(2);
                        if (cFil.peso_total_gramas > 0 && !detalhe.tem_pecas && (!modalProdCores.length)) {
                            if ($('prodPeso')) $('prodPeso').value = Number(cFil.peso_total_gramas);
                        }
                        if ($('tagCustoOrigem')) $('tagCustoOrigem').textContent = `PEPS ativo (${cFil.cores?.length || 0} cor/cores)`;
                        if ($('custosTagOrigem')) $('custosTagOrigem').textContent = `PEPS ativo (${cFil.cores?.length || 0} cor/cores)`;
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
    const temEmb = $('prodTemEmbalagem')?.value === '1';
    const tipo = $('prodTipo') ? $('prodTipo').value : 'simples';
    const temPecas = tipo === 'composto';
    const ehMulticor = !temPecas && $('checkProdMulticor') && $('checkProdMulticor').checked;

    const payload = {
        nome: $('prodNome').value.trim(),
        tipo: tipo,
        preco: parseFloat($('prodPreco').value) || 0,
        preco_manual: precoManualCadastrado ? 1 : 0,
        custo_manual: custoFilManual ? 1 : 0,
        estoque: parseInt($('prodEstoque').value) || 0,
        peso_gramas: parseFloat($('prodPeso')?.value) || 0,
        tempo_producao_segundos: parseTempoParaSegundos($('prodTempo')?.value),
        tem_embalagem: temEmb ? 1 : 0,
        valor_embalagem: temEmb ? (parseFloat($('prodValorEmbalagem')?.value) || 0) : 0,
        valor_outros: parseFloat($('prodValorOutros')?.value) || 0,
        margem_lucro: parseFloat($('prodMargemLucro')?.value) || 0,
        custo_filamento: parseFloat($('prodCustoFilamento')?.value) || 0,
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
        const pecasValidas = modalProdPecas
            .filter(p => (p.nome || '').trim() !== '')
            .map(p => {
                const coresValidas = (p.cores || [])
                    .filter(c => (c.cor || '').trim() !== '')
                    .map(c => ({
                        cor_id: parseInt(c.cor_id) || 0,
                        cor: c.cor.trim(),
                        peso_gramas: (c.peso_gramas !== '' && c.peso_gramas !== null && c.peso_gramas !== undefined)
                            ? Math.max(0, parseFloat(c.peso_gramas) || 0)
                            : null
                    }));

                let pesoPeca = parseFloat(p.peso_gramas) || 0;
                if (coresValidas.length > 0) {
                    const somaCores = coresValidas.reduce((acc, c) => acc + (c.peso_gramas || 0), 0);
                    if (somaCores > 0) pesoPeca = Math.round(somaCores * 100) / 100;
                }

                return {
                    peca_id: parseInt(p.peca_id) || 0,
                    nome: p.nome.trim(),
                    quantidade: Math.max(1, parseInt(p.quantidade) || 1),
                    tempo_producao_segundos: parseTempoParaSegundos(p.tempo_formatado || p.tempo_producao_segundos),
                    peso_gramas: pesoPeca,
                    cores: coresValidas
                };
            });

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
        const qtd = Number(l.quantidade) || 1;
        const t = (Number(l.tempo_producao_segundos) || 0) * qtd;
        const p = (Number(l.peso_gramas) || 0) * qtd;
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
                coresConsumo[cNome] = (coresConsumo[cNome] || 0) + (pCor * qtd);
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
        badgesContainer.innerHTML = entries.map(([cor, g]) => {
            const hex = obterHexCor(cor);
            const dot = hex ? `<span class="ponto-cor-indicador" style="background:${hex}; width:8px; height:8px; display:inline-block; border-radius:50%; margin-right:4px;"></span>` : '';
            return `<span class="chip-cor-diag" style="font-size:11.5px; display:inline-flex; align-items:center;">${dot}${esc(cor)}: <b>${Math.round(g * 10) / 10}g</b></span>`;
        }).join('');
    }
}

// ============================================================
// Editor de Peças: um bloco por peça (nome, qtd/un, tempo, peso, foto) com uma lista
// de cores aninhada (cada cor com sua gramatura). O tempo é único da peça.
// ============================================================
function renderizarEditorBOM() {
    const container = $('listaEditorBOM');
    if (!container) return;
    if (!bomLinhasEditor.length) {
        container.innerHTML = `<p class="vazio" style="padding:12px 0;">Nenhuma peça cadastrada. Clique em "+ Adicionar Peça".</p>`;
        atualizarTotaisInstantaneosEditorBOM();
        return;
    }

    container.innerHTML = bomLinhasEditor.map((linha, idx) => {
        const fotoBtn = linha.foto
            ? `<img src="${esc(linha.foto)}" class="foto-peca-editor" onclick="uploadFotoPecaProdutos(${idx})" title="Clique para trocar a foto">`
            : `<button type="button" class="btn-icone foto-peca-editor-vazia" onclick="uploadFotoPecaProdutos(${idx})" title="Adicionar foto">📷</button>`;

        const coresHtml = (linha.cores || []).map((cor, cidx) => {
            const hex = obterHexCor(cor.cor);
            const badgeCor = hex ? `<span class="ponto-cor-indicador" style="background:${hex}; width:14px; height:14px; border-radius:50%; border:1px solid rgba(0,0,0,0.15); flex-shrink:0;"></span>` : '';
            return `
            <div class="linha-cor-editor" style="display:flex; align-items:center; gap:8px;">
                ${badgeCor}
                <input type="text" list="listaCoresFilamentosSugestoes" placeholder="Nome da cor (ex.: Branco, Preto...)"
                       value="${esc(cor.cor || '')}"
                       oninput="atualizarCorBOM(${idx}, ${cidx}, 'cor', this.value)"
                       style="flex:2;">
                <div style="display:flex; align-items:center; gap:4px; flex:1;">
                    <input type="number" min="0" step="0.01"
                           placeholder="${linha.peso_gramas ? (linha.peso_gramas + 'g') : 'g desta cor'}"
                           value="${cor.peso_gramas !== null && cor.peso_gramas !== undefined ? cor.peso_gramas : ''}"
                           oninput="atualizarCorBOM(${idx}, ${cidx}, 'peso', this.value)"
                           title="Filamento em gramas desta cor para esta peça (tempo de impressão é único na peça)."
                           class="peso-cor-editor" style="width:100%;">
                    <span style="font-size:12px; color:var(--texto-secundario);">g</span>
                </div>
                <button type="button" class="btn-icone perigo" onclick="removerCorBOM(${idx}, ${cidx})"
                        title="Remover esta cor" ${(linha.cores || []).length <= 1 ? 'disabled' : ''}>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>`;
        }).join('');

        return `
        <div class="bloco-peca-editor">
            <div class="cabecalho-peca-editor">
                ${fotoBtn}
                <input type="text" placeholder="Nome da peça (ex.: Golfinho, Base...)"
                       value="${esc(linha.nome || '')}"
                       oninput="atualizarLinhaBOM(${idx}, 'nome', this.value)"
                       class="nome-peca-editor">
                <label class="rotulo-inline">Qtd/un
                    <input type="number" min="1" value="${linha.quantidade || 1}"
                           oninput="atualizarLinhaBOM(${idx}, 'quantidade', this.value)"
                           class="qtd-peca-editor" title="Quantidade desta peça necessária para 1 produto">
                </label>
                <label class="rotulo-inline">Tempo Único (1 un)
                    <input type="text" placeholder="00:00:00"
                           value="${esc(linha.tempo_formatado || '00:00:00')}"
                           onchange="atualizarLinhaBOM(${idx}, 'tempo', this.value)"
                           class="tempo-peca-editor" title="Tempo total de impressão desta peça (o tempo é um só para todas as cores)">
                </label>
                <label class="rotulo-inline">Filamento Total (g)
                    <input type="number" min="0" step="0.01" placeholder="0.00 g"
                           value="${linha.peso_gramas > 0 ? linha.peso_gramas : ''}"
                           oninput="atualizarLinhaBOM(${idx}, 'peso', this.value)"
                           class="peso-peca-editor" title="Consumo total em gramas desta peça">
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
                <span class="rotulo-cores-editor">🎨 Cores desta peça (defina as cores e gramas de filamento por cor; o tempo de impressão é único na peça acima):</span>
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
    const linha = bomLinhasEditor[idx];
    const cor = linha?.cores[cidx];
    if (!cor) return;
    if (campo === 'peso') {
        cor.peso_gramas = (valor === '' || valor === null) ? null : Math.max(0, parseFloat(valor) || 0);
        const somaCores = linha.cores.reduce((acc, c) => acc + (parseFloat(c.peso_gramas) || 0), 0);
        if (somaCores > 0) {
            linha.peso_gramas = parseFloat(somaCores.toFixed(2));
            const bloco = $('listaEditorBOM')?.querySelectorAll('.bloco-peca-editor')[idx];
            const pesoInput = bloco?.querySelector('.peso-peca-editor');
            if (pesoInput) pesoInput.value = linha.peso_gramas;
        }
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
        .map(l => {
            const somaPesosCores = (l.cores || []).reduce((acc, c) => acc + (parseFloat(c.peso_gramas) || 0), 0);
            const pesoFinal = somaPesosCores > 0 ? parseFloat(somaPesosCores.toFixed(2)) : Math.max(0, parseFloat(l.peso_gramas) || 0);
            return {
                peca_id: parseInt(l.peca_id) || 0,
                nome: l.nome.trim(),
                quantidade: Math.max(1, parseInt(l.quantidade) || 1),
                estoque: Math.max(0, parseInt(l.estoque) || 0),
                peso_gramas: pesoFinal,
                tempo_producao_segundos: parseInt(l.tempo_producao_segundos) || 0,
                foto: (l.foto || '').trim(),
                cores: (l.cores || []).map(c => ({
                    cor_id: parseInt(c.cor_id) || 0,
                    cor: (c.cor || '').trim(),
                    peso_gramas: (c.peso_gramas !== null && c.peso_gramas !== undefined && c.peso_gramas !== '')
                        ? Math.max(0, parseFloat(c.peso_gramas) || 0)
                        : null,
                    tempo_producao_segundos: null
                })),
            };
        });

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
$('filtroTipo')?.addEventListener('change', renderizar);

$('prodTipo')?.addEventListener('change', () => {
    const tipo = $('prodTipo').value;
    if (tipo === 'composto') {
        if (!modalProdPecas || !modalProdPecas.length) {
            modalProdPecas = [novaPecaModalProd(), novaPecaModalProd()];
        }
        renderizarPecasModalProduto();
    } else {
        if (modalProdPecas) {
            modalProdPecas = modalProdPecas.filter(p => (p.nome || '').trim() !== '');
        }
        atualizarModoModalProduto();
    }
});
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
$('prodPreco')?.addEventListener('input', () => { precoManualCadastrado = true; });
$('prodCustoFilamento')?.addEventListener('input', () => { custoFilManual = true; recalcularFormacaoPreco(true); });
$('prodTemEmbalagem')?.addEventListener('change', () => recalcularFormacaoPreco(true));
$('prodValorEmbalagem')?.addEventListener('input', () => recalcularFormacaoPreco(true));
$('prodValorOutros')?.addEventListener('input', () => recalcularFormacaoPreco(true));
$('prodMargemLucro')?.addEventListener('input', () => recalcularFormacaoPreco(true));
$('btnRecalcularPreco')?.addEventListener('click', () => {
    precoManualCadastrado = false;
    custoFilManual = false;
    recalcularFormacaoPreco(true);
});

// Atalho do modal de produto para abrir calculadora de custos
$('btnAbrirCustosDoProduto')?.addEventListener('click', () => {
    if (editandoId) {
        abrirCustosProduto(editandoId);
    } else {
        App.toast('Cadastre o produto primeiro para abrir a precificação PEPS.', 'alerta');
    }
});

// Listeners do modal exclusivo de custos
$('custosProdPeso')?.addEventListener('input', () => recalcularCustosModal(true));
$('custosProdCustoFilamento')?.addEventListener('input', () => recalcularCustosModal(true));
$('custosProdTemEmbalagem')?.addEventListener('change', () => recalcularCustosModal(true));
$('custosProdValorEmbalagem')?.addEventListener('input', () => recalcularCustosModal(true));
$('custosProdValorOutros')?.addEventListener('input', () => recalcularCustosModal(true));
$('custosProdMargemLucro')?.addEventListener('input', () => recalcularCustosModal(true));
$('btnCustosRecalcular')?.addEventListener('click', () => recalcularCustosModal(true));
$('btnSalvarCustosModal')?.addEventListener('click', salvarCustosModal);

// Torna abrirFicha e manipuladores globais para onclick inline
window.abrirFicha = abrirFicha;
window.abrirCustosProduto = abrirCustosProduto;
window.recalcularCustosModal = recalcularCustosModal;
window.definirMargemCustos = definirMargemCustos;
window.salvarCustosModal = salvarCustosModal;

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

window.adicionarCoresIniciaisPeca = adicionarCoresIniciaisPeca;
window.adicionarCorPecaModal = adicionarCorPecaModal;
window.removerCorPecaModal = removerCorPecaModal;
window.atualizarCorPecaModal = atualizarCorPecaModal;

carregar();
