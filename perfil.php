<?php
require_once __DIR__ . '/includes/auth.php';
$usuario = exigirLoginPagina();
$paginaAtual = 'perfil.php';
$tituloPagina = 'Meu Perfil';
$subtituloPagina = 'Altere sua senha de acesso.';
require __DIR__ . '/includes/header.php';
?>

<main>
    <div id="msg" aria-live="polite"></div>

    <section class="card" style="max-width: 400px; margin: 0 auto; padding: 2rem;">
        <form id="formPerfil" autocomplete="off">
            <div style="margin-bottom: 1.5rem;">
                <label for="senhaAtual" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Senha atual <span class="obrigatorio">*</span></label>
                <input type="password" id="senhaAtual" required style="width: 100%; padding: 0.75rem; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            
            <div style="margin-bottom: 1.5rem;">
                <label for="novaSenha" style="display: block; margin-bottom: 0.5rem; font-weight: 500;">Nova senha <span class="obrigatorio">*</span></label>
                <input type="password" id="novaSenha" required minlength="6" autocomplete="new-password" placeholder="Mínimo 6 caracteres" style="width: 100%; padding: 0.75rem; border: 1px solid #ccc; border-radius: 4px;">
            </div>
            
            <div style="text-align: right;">
                <button type="submit" class="botao" style="padding: 0.75rem 1.5rem; background: var(--cor-primaria); color: white; border: none; border-radius: 4px; font-weight: 600; cursor: pointer;">Salvar nova senha</button>
            </div>
        </form>
    </section>
</main>

<script>
document.getElementById('formPerfil').addEventListener('submit', async e => {
    e.preventDefault();
    const btn = e.target.querySelector('button');
    btn.disabled = true;
    try {
        const body = {
            senha_atual: document.getElementById('senhaAtual').value,
            nova_senha: document.getElementById('novaSenha').value
        };
        await App.api('api/perfil.php', 'PUT', body);
        App.toast('Senha alterada com sucesso.', 'sucesso');
        e.target.reset();
    } catch (err) {
        App.toast(err.message, 'erro');
    }
    btn.disabled = false;
});
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
