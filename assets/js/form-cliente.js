// Formulário de cliente em modal.
// FormCliente.abrir({ cliente, aoSalvar(cliente) })
//   cliente   -> objeto para editar (omitido = novo)
//   aoSalvar  -> chamado com { id, nome, ... } depois de salvar
window.FormCliente = (() => {
    const campos = {
        nome: 'cliNome',
        telefone: 'cliTelefone',
        email: 'cliEmail',
        cidade: 'cliCidade',
        estado: 'cliEstado',
        descricao: 'cliDescricao',
    };
    const $ = id => document.getElementById(id);
    let editandoId = null;
    let callback = null;

    function abrir({ cliente = null, aoSalvar = null } = {}) {
        editandoId = cliente ? cliente.id : null;
        callback = aoSalvar;
        $('formCliente').reset();
        Object.entries(campos).forEach(([k, id]) => { $(id).value = cliente ? (cliente[k] || '') : ''; });
        $('cliTituloModal').textContent = cliente ? 'Editar cliente' : 'Novo cliente';
        $('cliSubModal').textContent = cliente ? cliente.nome : 'Preencha os dados do cliente.';
        $('btnSalvarCliente').textContent = cliente ? 'Salvar alterações' : 'Cadastrar cliente';
        App.modal.abrir('modalCliente', '#cliNome');
    }

    async function salvar(ev) {
        ev.preventDefault();
        const payload = {};
        Object.entries(campos).forEach(([k, id]) => { payload[k] = $(id).value.trim(); });
        if (!payload.nome) {
            App.toast('Informe o nome do cliente.', 'erro');
            $('cliNome').focus();
            return;
        }
        if (payload.email && !$('cliEmail').checkValidity()) {
            App.toast('E-mail inválido.', 'erro');
            $('cliEmail').focus();
            return;
        }

        const btn = $('btnSalvarCliente');
        btn.disabled = true;
        try {
            let id = editandoId;
            if (editandoId) {
                await App.api(`api/clientes.php?id=${editandoId}`, 'PUT', payload);
                App.toast(`Cliente "${payload.nome}" atualizado.`);
            } else {
                id = (await App.api('api/clientes.php', 'POST', payload)).id;
                App.toast(`Cliente "${payload.nome}" cadastrado.`);
            }
            App.modal.fechar('modalCliente');
            if (callback) callback({ id, ...payload });
        } catch (e) {
            App.toast(e.message, 'erro');
        } finally {
            btn.disabled = false;
        }
    }

    $('formCliente').addEventListener('submit', salvar);
    $('cliEstado').addEventListener('input', e => { e.target.value = e.target.value.toUpperCase(); });

    return { abrir };
})();
