<?php
// Modal de cadastro/edição de cliente (usado em Clientes e dentro do formulário de pedido).
// Incluir com require_once. Script: assets/js/form-cliente.js (FormCliente.abrir()).
?>
<div id="modalCliente" class="modal" hidden>
    <div class="card modal-caixa modal-medio" role="dialog" aria-modal="true" aria-labelledby="cliTituloModal">
        <form id="formCliente" autocomplete="off" novalidate>
            <div class="modal-cabecalho">
                <div>
                    <h2 id="cliTituloModal">Novo cliente</h2>
                    <p class="modal-sub" id="cliSubModal">Preencha os dados do cliente.</p>
                </div>
                <button type="button" class="btn-icone" data-fechar-modal aria-label="Fechar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="modal-corpo">
                <label for="cliNome">Nome <span class="obrigatorio">*</span></label>
                <input type="text" id="cliNome" maxlength="150" required placeholder="Nome ou razão social">

                <div class="linha">
                    <div>
                        <label for="cliTelefone">Telefone</label>
                        <input type="tel" id="cliTelefone" maxlength="30" placeholder="(11) 90000-0000">
                    </div>
                    <div>
                        <label for="cliEmail">E-mail</label>
                        <input type="email" id="cliEmail" maxlength="150" placeholder="cliente@exemplo.com">
                    </div>
                </div>
                <div class="linha">
                    <div style="flex:3">
                        <label for="cliCidade">Cidade</label>
                        <input type="text" id="cliCidade" maxlength="100" placeholder="Ex.: Campinas">
                    </div>
                    <div style="flex:1; min-width:80px">
                        <label for="cliEstado">UF</label>
                        <input type="text" id="cliEstado" maxlength="2" placeholder="SP">
                    </div>
                </div>
                <label for="cliDescricao">Descrição</label>
                <textarea id="cliDescricao" rows="2" placeholder="Tipo de cliente, preferências, condições combinadas..."></textarea>
            </div>

            <div class="modal-rodape">
                <button type="button" class="secundario" data-fechar-modal>Cancelar</button>
                <button type="submit" id="btnSalvarCliente">Salvar cliente</button>
            </div>
        </form>
    </div>
</div>
<script src="assets/js/form-cliente.js?v=<?= @filemtime(__DIR__ . '/../assets/js/form-cliente.js') ?>"></script>
