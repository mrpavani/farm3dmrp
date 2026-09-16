let ultimoRelatorio = null;

const statusLabel = {
    aberto: 'Aberto',
    em_producao: 'Em produção',
    pronto: 'Pronto',
    entregue: 'Entregue',
    cancelado: 'Cancelado',
};

const fmtInt = new Intl.NumberFormat('pt-BR');
const fmtMoeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

function esc(v) {
    return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function iso(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

function formatarData(s) {
    if (!s) return '—';
    const [y, m, d] = s.split('-');
    return `${d}/${m}/${y}`;
}

function mostrarMsg(texto, tipo) {
    window.toast(texto, tipo);
}

function definirPeriodo(tipo) {
    const hoje = new Date();
    let de = new Date(hoje), ate = new Date(hoje);
    if (tipo === '7') {
        de.setDate(hoje.getDate() - 6);
    } else if (tipo === 'mes') {
        de = new Date(hoje.getFullYear(), hoje.getMonth(), 1);
        ate = new Date(hoje.getFullYear(), hoje.getMonth() + 1, 0);
    } else if (tipo === 'mes-anterior') {
        de = new Date(hoje.getFullYear(), hoje.getMonth() - 1, 1);
        ate = new Date(hoje.getFullYear(), hoje.getMonth(), 0);
    } else if (tipo === 'ano') {
        de = new Date(hoje.getFullYear(), 0, 1);
        ate = new Date(hoje.getFullYear(), 11, 31);
    }
    document.getElementById('relDe').value = iso(de);
    document.getElementById('relAte').value = iso(ate);
}

async function carregarProdutos() {
    const res = await fetch('api/produtos.php?todos=1');
    const produtos = await res.json();
    const sel = document.getElementById('relProduto');
    produtos.forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = p.nome;
        sel.appendChild(opt);
    });
}

async function carregarUsuarios() {
    const res = await fetch('api/usuarios.php');
    const data = await res.json();
    const sel = document.getElementById('relUsuario');
    data.usuarios.forEach(u => {
        const opt = document.createElement('option');
        opt.value = u.id;
        opt.textContent = u.nome + (Number(u.ativo) ? '' : ' (inativo)');
        sel.appendChild(opt);
    });
}

async function gerarRelatorio() {
    const de = document.getElementById('relDe').value;
    const ate = document.getElementById('relAte').value;
    if (!de || !ate) {
        mostrarMsg('Informe as datas inicial e final.', 'erro');
        return;
    }

    const params = new URLSearchParams({ data_de: de, data_ate: ate });
    const produto = document.getElementById('relProduto').value;
    const usuarioId = document.getElementById('relUsuario').value;
    if (produto) params.set('produto_id', produto);
    if (usuarioId) params.set('usuario_id', usuarioId);

    const res = await fetch('api/relatorios.php?' + params.toString());
    const data = await res.json();
    if (!res.ok) {
        mostrarMsg(data.erro || 'Erro ao gerar relatório.', 'erro');
        return;
    }
    ultimoRelatorio = data;
    renderizar(data);
}

function tabela(cabecalhos, linhas, rodape = null) {
    if (linhas.length === 0) return '<p class="vazio">Nenhuma produção no período.</p>';
    const th = cabecalhos.map(([t, num]) => `<th class="${num ? 'num' : ''}">${t}</th>`).join('');
    const tr = linhas.map(l => `<tr>${l.map(([v, num]) => `<td class="${num ? 'num' : ''}">${v}</td>`).join('')}</tr>`).join('');
    const tf = rodape ? `<tfoot><tr>${rodape.map(([v, num]) => `<td class="${num ? 'num' : ''}">${v}</td>`).join('')}</tr></tfoot>` : '';
    return `<table><thead><tr>${th}</tr></thead><tbody>${tr}</tbody>${tf}</table>`;
}

function renderizar(d) {
    const r = d.resumo;
    document.getElementById('resultado').hidden = false;
    document.getElementById('tituloPeriodo').textContent =
        `Produção de ${formatarData(d.periodo.de)} a ${formatarData(d.periodo.ate)}`;

    const kpis = [
        ['Peças produzidas', fmtInt.format(r.pecas)],
        ['Lotes lançados', fmtInt.format(r.lotes)],
        ['Ordens atendidas', fmtInt.format(r.pedidos)],
        ['Produtos diferentes', fmtInt.format(r.produtos)],
        ['Valor produzido', fmtMoeda.format(r.valor)],
    ];
    document.getElementById('resumo').innerHTML = kpis.map(([rot, val]) =>
        `<div class="kpi"><div class="rotulo">${rot}</div><div class="valor">${val}</div></div>`
    ).join('');

    const totalPecas = Number(r.pecas) || 0;

    document.getElementById('tabProduto').innerHTML = tabela(
        [['Produto'], ['Peças', 1], ['% do total', 1], ['Lotes', 1], ['Pedidos', 1], ['Preço unit.', 1], ['Valor', 1]],
        d.por_produto.map(p => [
            [esc(p.nome)],
            [fmtInt.format(p.pecas), 1],
            [totalPecas ? ((p.pecas / totalPecas) * 100).toFixed(1).replace('.', ',') + '%' : '—', 1],
            [fmtInt.format(p.lotes), 1],
            [fmtInt.format(p.pedidos), 1],
            [fmtMoeda.format(p.preco), 1],
            [fmtMoeda.format(p.valor), 1],
        ]),
        [['Total'], [fmtInt.format(r.pecas), 1], ['100%', 1], [fmtInt.format(r.lotes), 1], [fmtInt.format(r.pedidos), 1], ['', 1], [fmtMoeda.format(r.valor), 1]]
    );

    const maxDia = Math.max(1, ...d.por_dia.map(x => Number(x.pecas)));
    document.getElementById('tabDia').innerHTML = tabela(
        [['Data'], ['Peças', 1], ['Lotes', 1], ['']],
        d.por_dia.map(x => [
            [formatarData(x.data)],
            [fmtInt.format(x.pecas), 1],
            [fmtInt.format(x.lotes), 1],
            [`<div class="barra-dia" style="width:${Math.round((x.pecas / maxDia) * 100)}%"></div>`],
        ])
    );

    document.getElementById('tabUsuario').innerHTML = tabela(
        [['Usuário'], ['Peças', 1], ['Lotes', 1]],
        d.por_usuario.map(x => [[esc(x.usuario)], [fmtInt.format(x.pecas), 1], [fmtInt.format(x.lotes), 1]])
    );

    document.getElementById('tabDetalhes').innerHTML = tabela(
        [['Data'], ['Ordem'], ['Destino'], ['Produto'], ['Qtd', 1], ['Produzido por'], ['Status do pedido'], ['Obs.']],
        d.detalhes.map(x => [
            [formatarData(x.data_producao)],
            [`<span class="pedido-id">#${x.pedido_id}</span>`],
            [x.pedido_tipo === 'estoque' ? '<span class="destino-estoque">Estoque</span>' : esc(x.cliente_nome)],
            [esc(x.produto_nome)],
            [fmtInt.format(x.quantidade), 1],
            [esc(x.usuario_nome || '—')],
            [`<span class="badge ${esc(x.pedido_status)}">${statusLabel[x.pedido_status] || esc(x.pedido_status)}</span>`],
            [esc(x.observacoes || '')],
        ])
    );
}

function csvCampo(v) {
    const s = String(v ?? '');
    return /[";\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

function numBR(v) {
    return String(v ?? '').replace('.', ',');
}

// CSV com ";" e vírgula decimal para abrir direto no Excel em português
function exportarCsv() {
    if (!ultimoRelatorio) return;
    const d = ultimoRelatorio;
    const linhas = [];
    linhas.push(['Relatório de produção', `${formatarData(d.periodo.de)} a ${formatarData(d.periodo.ate)}`]);
    linhas.push([]);
    linhas.push(['RESUMO']);
    linhas.push(['Peças', 'Lotes', 'Pedidos', 'Produtos', 'Valor (R$)']);
    linhas.push([d.resumo.pecas, d.resumo.lotes, d.resumo.pedidos, d.resumo.produtos, numBR(d.resumo.valor)]);
    linhas.push([]);
    linhas.push(['POR PRODUTO']);
    linhas.push(['Produto', 'Peças', 'Lotes', 'Pedidos', 'Preço unit. (R$)', 'Valor (R$)']);
    d.por_produto.forEach(p => linhas.push([p.nome, p.pecas, p.lotes, p.pedidos, numBR(p.preco), numBR(p.valor)]));
    linhas.push([]);
    linhas.push(['POR DIA']);
    linhas.push(['Data', 'Peças', 'Lotes']);
    d.por_dia.forEach(x => linhas.push([formatarData(x.data), x.pecas, x.lotes]));
    linhas.push([]);
    linhas.push(['POR USUÁRIO']);
    linhas.push(['Usuário', 'Peças', 'Lotes']);
    d.por_usuario.forEach(x => linhas.push([x.usuario, x.pecas, x.lotes]));
    linhas.push([]);
    linhas.push(['LANÇAMENTOS']);
    linhas.push(['Data', 'Ordem', 'Destino', 'Produto', 'Quantidade', 'Produzido por', 'Status do pedido', 'Observações']);
    d.detalhes.forEach(x => linhas.push([
        formatarData(x.data_producao), x.pedido_id, x.pedido_tipo === 'estoque' ? 'Estoque' : x.cliente_nome, x.produto_nome, x.quantidade,
        x.usuario_nome || '', statusLabel[x.pedido_status] || x.pedido_status, x.observacoes || '',
    ]));

    const csv = '﻿' + linhas.map(l => l.map(csvCampo).join(';')).join('\r\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `producao_${d.periodo.de}_a_${d.periodo.ate}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

document.getElementById('btnGerar').addEventListener('click', gerarRelatorio);
document.getElementById('btnCsv').addEventListener('click', exportarCsv);
document.getElementById('btnImprimir').addEventListener('click', () => window.print());
document.querySelectorAll('[data-periodo]').forEach(btn =>
    btn.addEventListener('click', () => { definirPeriodo(btn.dataset.periodo); gerarRelatorio(); })
);

(async function init() {
    await Promise.all([carregarProdutos(), carregarUsuarios()]);
    definirPeriodo('mes');
    gerarRelatorio();
})();
