// Módulo Usuários (somente administradores): lista + cadastro/edição em modal
let usuarios = [];
let meuId = null;
let editandoId = null;
const $ = id => document.getElementById(id);

async function carregar() {
    try {
        const data = await App.api('api/usuarios.php');
        usuarios = data.usuarios;
        meuId = data.eu;
    } catch (e) {
        App.toast(e.message, 'erro');
        usuarios = [];
    }
    renderizar();
}

function renderizar() {
    const termo = App.normalizar($('busca').value.trim());
    const situacao = $('filtroSituacao').value;
    const lista = usuarios.filter(u =>
        (situacao === '' || String(u.ativo) === situacao) &&
        (!termo || App.normalizar(`${u.nome} ${u.login}`).includes(termo)));

    if (!lista.length) {
        $('tabelaUsuarios').innerHTML = App.estadoVazio('🔒', 'Nenhum usuário encontrado', 'Ajuste a busca ou o filtro.', 8);
        return;
    }

    $('tabelaUsuarios').innerHTML = lista.map(u => {
        const ativo = Number(u.ativo) === 1;
        const souEu = u.id === meuId;
        return `
        <tr class="${ativo ? '' : 'linha-inativa'}">
            <td><strong>${App.esc(u.nome)}</strong>${souEu ? ' <span class="vazio">(você)</span>' : ''}</td>
            <td>${App.esc(u.login)}</td>
            <td>${Number(u.admin) ? '<span class="tag-admin">Administrador</span>' : 'Usuário'}</td>
            <td><span class="badge ${ativo ? 'pronto' : 'aberto'}">${ativo ? 'Ativo' : 'Inativo'}</span></td>
            <td class="num">${u.qtd_pedidos}</td>
            <td class="num">${u.qtd_producoes}</td>
            <td>${App.dataHora(u.ultimo_acesso)}</td>
            <td class="col-acoes">
                <div class="acoes-icones">
                    ${App.botaoIcone('editar', 'Editar', `editar(${u.id})`, 'primario')}
                    ${souEu ? '' : (ativo
                        ? App.botaoIcone('desativar', 'Desativar acesso', `alternarAtivo(${u.id}, false)`, 'perigo')
                        : App.botaoIcone('ativar', 'Reativar acesso', `alternarAtivo(${u.id}, true)`, 'sucesso'))}
                </div>
            </td>
        </tr>`;
    }).join('');
}

function abrirModal(u = null) {
    editandoId = u ? u.id : null;
    $('formUsuario').reset();
    $('usuNome').value = u ? u.nome : '';
    $('usuLogin').value = u ? u.login : '';
    const chk = $('usuAdmin');
    chk.checked = u ? Number(u.admin) === 1 : false;
    // o próprio administrador não pode tirar o seu acesso de admin
    chk.disabled = !!u && u.id === meuId && chk.checked;
    chk.parentElement.title = chk.disabled ? 'Você não pode remover o seu próprio acesso de administrador' : '';
    $('rotuloSenha').innerHTML = u ? 'Nova senha <span class="vazio">(deixe em branco para manter)</span>' : 'Senha <span class="obrigatorio">*</span>';
    $('usuSenha').placeholder = u ? '••••••' : 'Mínimo 6 caracteres';
    $('usuTituloModal').textContent = u ? 'Editar usuário' : 'Novo usuário';
    $('usuSubModal').textContent = u ? u.nome : 'O nome aparece nos pedidos e produções registrados por ele.';
    $('btnSalvarUsuario').textContent = u ? 'Salvar alterações' : 'Cadastrar usuário';
    App.modal.abrir('modalUsuario', '#usuNome');
}

const editar = id => abrirModal(usuarios.find(u => u.id === id));

async function salvar(ev) {
    ev.preventDefault();
    const payload = {
        nome: $('usuNome').value.trim(),
        login: $('usuLogin').value.trim().toLowerCase(),
        senha: $('usuSenha').value,
        admin: $('usuAdmin').checked,
    };
    const erro = (msg, id) => { App.toast(msg, 'erro'); $(id).focus(); };
    if (!payload.nome) return erro('Informe o nome.', 'usuNome');
    if (!/^[a-z0-9._@+-]{3,150}$/.test(payload.login)) return erro('Login inválido: use de 3 a 150 caracteres, sem espaços.', 'usuLogin');
    if ((!editandoId || payload.senha) && payload.senha.length < 6) return erro('A senha deve ter pelo menos 6 caracteres.', 'usuSenha');

    const btn = $('btnSalvarUsuario');
    btn.disabled = true;
    try {
        if (editandoId) {
            await App.api(`api/usuarios.php?id=${editandoId}`, 'PUT', payload);
            App.toast(`Usuário "${payload.nome}" atualizado.`);
        } else {
            await App.api('api/usuarios.php', 'POST', payload);
            App.toast(`Usuário "${payload.nome}" cadastrado.`);
        }
        App.modal.fechar('modalUsuario');
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    } finally {
        btn.disabled = false;
    }
}

async function alternarAtivo(id, ativar) {
    const u = usuarios.find(x => x.id === id);
    if (!ativar && !await App.confirmar(`Desativar "${u.nome}"? Ele perde o acesso ao sistema imediatamente.`, { titulo: 'Desativar usuário', botao: 'Desativar', perigo: true })) return;
    try {
        await App.api(`api/usuarios.php?id=${id}`, 'PATCH', { ativo: ativar });
        App.toast(`Usuário "${u.nome}" ${ativar ? 'reativado' : 'desativado'}.`);
        carregar();
    } catch (e) {
        App.toast(e.message, 'erro');
    }
}

$('btnNovo').addEventListener('click', () => abrirModal());
$('formUsuario').addEventListener('submit', salvar);
$('busca').addEventListener('input', renderizar);
$('filtroSituacao').addEventListener('change', renderizar);
// login sempre em minúsculas e sem espaços
$('usuLogin').addEventListener('input', e => {
    const pos = e.target.selectionStart;
    e.target.value = e.target.value.toLowerCase().replace(/\s/g, '');
    e.target.setSelectionRange(pos, pos);
});
carregar();
