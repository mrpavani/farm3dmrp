<?php
require_once __DIR__ . '/includes/auth.php';
$usuario = exigirAdminPagina();
$paginaAtual = 'usuarios.php';
$tituloPagina = 'Usuários';
$subtituloPagina = 'Gerencie quem acessa o sistema e quem é administrador.';
require __DIR__ . '/includes/header.php';
?>

<main>
    <div id="msg" aria-live="polite"></div>

    <div class="barra-lista">
        <label class="busca">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" id="busca" placeholder="Buscar por nome ou login…" aria-label="Buscar usuários">
        </label>
        <select id="filtroSituacao" aria-label="Situação">
            <option value="">Todas as situações</option>
            <option value="1">Ativos</option>
            <option value="0">Inativos</option>
        </select>
        <button type="button" id="btnNovo">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Novo usuário
        </button>
    </div>

    <section class="card card-lista">
        <div class="tabela-rolagem">
            <table>
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Login</th>
                        <th>Perfil</th>
                        <th>Situação</th>
                        <th class="num">Pedidos</th>
                        <th class="num">Produções</th>
                        <th>Último acesso</th>
                        <th class="num">Ações</th>
                    </tr>
                </thead>
                <tbody id="tabelaUsuarios"></tbody>
            </table>
        </div>
        <div class="rodape-lista">Usuários não são excluídos, pois ficam registrados nos pedidos e produções — desative para bloquear o acesso. Sempre deve existir pelo menos um administrador ativo.</div>
    </section>
</main>

<div id="modalUsuario" class="modal" hidden>
    <div class="card modal-caixa modal-pequeno" role="dialog" aria-modal="true" aria-labelledby="usuTituloModal">
        <form id="formUsuario" autocomplete="off" novalidate>
            <div class="modal-cabecalho">
                <div>
                    <h2 id="usuTituloModal">Novo usuário</h2>
                    <p class="modal-sub" id="usuSubModal">O nome aparece nos pedidos e produções registrados por ele.</p>
                </div>
                <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="modal-corpo">
                <label for="usuNome">Nome <span class="obrigatorio">*</span></label>
                <input type="text" id="usuNome" maxlength="100" required placeholder="Nome completo">

                <label for="usuLogin">Login (usuário ou e-mail) <span class="obrigatorio">*</span></label>
                <input type="text" id="usuLogin" maxlength="150" required pattern="[a-z0-9._@+\-]{3,150}" autocomplete="off"
                       title="3 a 150 caracteres, sem espaços: letras minúsculas, números, ponto, hífen, sublinhado ou @" placeholder="ex.: joao@empresa.com">

                <label for="usuSenha" id="rotuloSenha">Senha <span class="obrigatorio">*</span></label>
                <input type="password" id="usuSenha" minlength="6" autocomplete="new-password" placeholder="Mínimo 6 caracteres">

                <label class="check">
                    <input type="checkbox" id="usuAdmin">
                    <span>Administrador <span class="vazio">— pode gerenciar usuários</span></span>
                </label>
            </div>

            <div class="modal-rodape">
                <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
                <button type="submit" id="btnSalvarUsuario">Cadastrar usuário</button>
            </div>
        </form>
    </div>
</div>

<script src="assets/js/usuarios.js?v=<?= @filemtime(__DIR__ . '/assets/js/usuarios.js') ?>"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
