// ============================================================
// Carregado em todas as telas logadas (antes do conteúdo da página).
// Expõe window.App com utilitários, ícones, modais, confirmação e toasts.
// ============================================================

// Sessão expirada (401): volta ao login e retorna para a tela atual depois.
(function () {
    const fetchOriginal = window.fetch.bind(window);
    window.fetch = async (...args) => {
        const res = await fetchOriginal(...args);
        if (res.status === 401) {
            const volta = location.pathname.split('/').pop() + location.search;
            location.href = 'login.php?volta=' + encodeURIComponent(volta);
            return new Promise(() => {}); // não resolve: evita erro na tela enquanto redireciona
        }
        return res;
    };
})();

window.App = (() => {
    // ---------- Formatação ----------
    const fmtInt = new Intl.NumberFormat('pt-BR');
    const fmtMoeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
    const num = (n, casas = 1) => new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: casas }).format(Number(n) || 0);

    const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    function isoEmDias(n = 0) {
        const d = new Date();
        d.setDate(d.getDate() + n);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    }

    function data(iso) {
        if (!iso) return '—';
        const [y, m, d] = String(iso).slice(0, 10).split('-');
        return `${d}/${m}/${y}`;
    }

    function dataHora(s) {
        if (!s) return '—';
        const [d, h] = s.split(' ');
        return `${data(d)} ${(h || '').slice(0, 5)}`;
    }

    // Dias entre hoje e a data (negativo = atrasado)
    function diasAte(iso) {
        const [y, m, d] = iso.split('-').map(Number);
        const hoje = new Date();
        hoje.setHours(0, 0, 0, 0);
        return Math.round((new Date(y, m - 1, d) - hoje) / 86400000);
    }

    function etiquetaPrazo(iso) {
        const n = diasAte(iso);
        if (n < 0) return `<span class="prazo atrasado">atrasado ${-n} dia${n === -1 ? '' : 's'}</span>`;
        if (n === 0) return '<span class="prazo urgente">hoje</span>';
        if (n === 1) return '<span class="prazo urgente">amanhã</span>';
        if (n <= 3) return `<span class="prazo proximo">em ${n} dias</span>`;
        return `<span class="prazo">em ${n} dias</span>`;
    }

    // ---------- API ----------
    async function api(url, method = 'GET', body = null) {
        const opts = { method, headers: {} };
        if (body !== null) {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(url, opts);
        let dados = null;
        try { dados = await res.json(); } catch (e) { /* resposta sem JSON */ }
        if (!res.ok) throw new Error((dados && dados.erro) || `Erro ${res.status} na operação.`);
        return dados;
    }

    // ---------- Ícones (traço 2px, 24x24) ----------
    const ICONES = {
        editar:    '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        excluir:   '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/>',
        ativar:    '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
        desativar: '<circle cx="12" cy="12" r="9"/><path d="M5.7 5.7l12.6 12.6"/>',
        pedido:    '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><path d="M12 11v6M9 14h6"/>',
        entregar:  '<path d="M1 3h15v13H1zM16 8h4l3 3v5h-7z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
        concluir:  '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m9 12 2 2 4-4"/>',
        cancelar:  '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
        reabrir:   '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
        mais:      '<path d="M12 5v14M5 12h14"/>',
        fechar:    '<path d="M18 6 6 18M6 6l12 12"/>',
        busca:     '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        alerta:    '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        ficha:     '<path d="m12.83 2.18a2 2 0 0 0-1.66 0L2.6 6.08a1 1 0 0 0 0 1.83l8.58 3.9a2 2 0 0 0 1.66 0l8.58-3.9a1 1 0 0 0 0-1.83Z"/><path d="m22 12.5-9.17 4.16a2 2 0 0 1-1.66 0L2 12.5"/><path d="m22 17.5-9.17 4.16a2 2 0 0 1-1.66 0L2 17.5"/>',
        montagem:  '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
        pecas:     '<path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="M3.3 7 12 12l8.7-5M12 22V12"/>',
    };
    const icone = nome => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONES[nome] || ''}</svg>`;

    // Botão de ícone para listas. variante: '', 'perigo', 'sucesso', 'alerta'
    const botaoIcone = (nome, dica, onclick, variante = '') =>
        `<button type="button" class="btn-icone ${variante}" data-tip="${esc(dica)}" aria-label="${esc(dica)}" onclick="${onclick}">${icone(nome)}</button>`;

    // ---------- Modais (empilháveis) ----------
    const pilha = [];
    const modal = {
        abrir(id, focar = null) {
            const el = document.getElementById(id);
            if (!el) return;
            if (!pilha.includes(id)) pilha.push(id);
            el.style.zIndex = String(50 + pilha.length * 2);
            el.hidden = false;
            document.body.classList.add('com-modal');
            const alvo = focar ? el.querySelector(focar) : el.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea, button');
            setTimeout(() => alvo && alvo.focus(), 40);
        },
        fechar(id) {
            const el = document.getElementById(id);
            if (!el) return;
            el.hidden = true;
            const i = pilha.indexOf(id);
            if (i >= 0) pilha.splice(i, 1);
            if (!pilha.length) document.body.classList.remove('com-modal');
            el.dispatchEvent(new CustomEvent('fechado'));
        },
        topo: () => pilha[pilha.length - 1] || null,
    };

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal.topo()) {
            e.preventDefault();
            modal.fechar(modal.topo());
        }
    });
    document.addEventListener('mousedown', e => {
        if (e.target.classList && e.target.classList.contains('modal')) modal.fechar(e.target.id);
    });
    document.addEventListener('click', e => {
        const btn = e.target.closest('[data-fechar-modal]');
        if (btn) modal.fechar(btn.closest('.modal').id);
    });

    // ---------- Confirmação estilizada ----------
    function confirmar(texto, { titulo = 'Confirmar', botao = 'Confirmar', perigo = false } = {}) {
        let el = document.getElementById('modalConfirmar');
        if (!el) {
            el = document.createElement('div');
            el.id = 'modalConfirmar';
            el.className = 'modal';
            el.hidden = true;
            el.innerHTML = `
                <div class="card modal-caixa modal-pequeno" role="alertdialog" aria-modal="true" aria-labelledby="confTitulo" aria-describedby="confTexto">
                    <div class="confirmar-corpo">
                        <span class="confirmar-icone">${icone('alerta')}</span>
                        <div><h2 id="confTitulo"></h2><p id="confTexto" class="modal-sub"></p></div>
                    </div>
                    <div class="modal-rodape">
                        <button type="button" class="secundario" id="confNao">Voltar</button>
                        <button type="button" id="confSim"></button>
                    </div>
                </div>`;
            document.body.appendChild(el);
        }
        el.querySelector('#confTitulo').textContent = titulo;
        el.querySelector('#confTexto').textContent = texto;
        const sim = el.querySelector('#confSim');
        sim.textContent = botao;
        sim.className = perigo ? 'perigo' : '';
        el.classList.toggle('confirmar-perigo', perigo);

        return new Promise(resolve => {
            let resposta = false;
            const fim = () => {
                el.removeEventListener('fechado', fim);
                sim.onclick = null;
                el.querySelector('#confNao').onclick = null;
                resolve(resposta);
            };
            sim.onclick = () => { resposta = true; modal.fechar('modalConfirmar'); };
            el.querySelector('#confNao').onclick = () => modal.fechar('modalConfirmar');
            el.addEventListener('fechado', fim);
            modal.abrir('modalConfirmar', '#confSim');
        });
    }

    // ---------- Toasts ----------
    function toast(texto, tipo = 'ok') {
        let area = document.getElementById('msg');
        if (!area) {
            area = document.createElement('div');
            area.id = 'msg';
            document.body.appendChild(area);
        }
        const el = document.createElement('div');
        el.className = `msg ${tipo}`;
        el.setAttribute('role', tipo === 'erro' ? 'alert' : 'status');
        el.textContent = texto;
        el.title = 'Clique para fechar';
        el.addEventListener('click', () => el.remove());
        area.appendChild(el);
        while (area.children.length > 3) area.firstElementChild.remove();
        setTimeout(() => el.remove(), tipo === 'erro' ? 8000 : 4500);
    }

    function estadoVazio(emoji, titulo, texto, colspan = 0) {
        const html = `<div class="estado-vazio"><div class="icone">${emoji}</div><strong>${esc(titulo)}</strong>${esc(texto)}</div>`;
        return colspan ? `<tr><td colspan="${colspan}" class="sem-borda">${html}</td></tr>` : html;
    }

    // Normaliza texto para busca (sem acento, minúsculo)
    const normalizar = s => String(s ?? '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

    return {
        fmtInt, fmtMoeda, moeda: v => fmtMoeda.format(Number(v) || 0), num, esc, isoEmDias, hoje: () => isoEmDias(0), data, dataHora, diasAte, etiquetaPrazo,
        api, icone, botaoIcone, modal, confirmar, toast, estadoVazio, normalizar,
    };
})();

window.toast = window.App.toast;

// Listas no celular: cada célula recebe o nome da coluna (data-label),
// usado pelo CSS para mostrar as linhas como cartões empilhados.
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.card-lista table').forEach(tabela => {
        const rotulos = [...tabela.querySelectorAll('thead th')].map(th => th.textContent.trim());
        const tbody = tabela.querySelector('tbody');
        const rotular = () => tbody.querySelectorAll('tr').forEach(tr =>
            [...tr.children].forEach((td, i) => { if (rotulos[i] && td.colSpan === 1) td.dataset.label = rotulos[i]; }));
        new MutationObserver(rotular).observe(tbody, { childList: true });
        rotular();
    });
});

// Menu lateral no celular (abre/fecha)
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('btnMenu');
    const fundo = document.getElementById('sidebarFundo');
    if (!btn) return;
    const alternar = abrir => {
        document.body.classList.toggle('menu-aberto', abrir);
        btn.setAttribute('aria-expanded', String(abrir));
    };
    btn.addEventListener('click', () => alternar(!document.body.classList.contains('menu-aberto')));
    fundo.addEventListener('click', () => alternar(false));
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && !window.App.modal.topo()) alternar(false); });
});
