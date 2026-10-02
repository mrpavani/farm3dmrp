<?php
// Sub-modal para visualização e personalização de cores de peças de um item do pedido.
// Carregado em conjunto com o modal_pedido.php.
?>
<div id="modalEditarCoresItem" class="modal" hidden>
    <div class="card modal-caixa modal-medio" role="dialog" aria-modal="true" aria-labelledby="titEditarCoresItem">
        <div class="modal-cabecalho">
            <div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:20px;">🎨</span>
                    <h2 id="titEditarCoresItem" style="margin:0;font-size:18px;">Personalizar Cores</h2>
                </div>
                <p class="modal-sub" id="subEditarCoresItem" style="margin-top:3px;">
                    As cores padrão já vêm selecionadas. Ajuste somente se o cliente desejar uma combinação diferente.
                </p>
            </div>
            <button type="button" class="btn-icone" data-fechar-modal-cores aria-label="Fechar">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="modal-corpo" style="max-height:65vh;overflow-y:auto;padding-right:4px;">
            <div id="modalCoresAviso" class="aviso" hidden style="margin-bottom:12px;font-size:12.5px;"></div>
            <div id="modalCoresListaPecas" style="display:flex;flex-direction:column;gap:12px;"></div>
        </div>

        <div class="modal-rodape" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
            <button type="button" class="fantasma pequeno" id="btnRestaurarCoresItem" style="color:var(--text-2);">
                ↺ Restaurar padrão do produto
            </button>
            <div style="display:flex;gap:8px;">
                <button type="button" class="secundario" data-fechar-modal-cores>Cancelar</button>
                <button type="button" class="primario" id="btnSalvarCoresItem">Salvar cores</button>
            </div>
        </div>
    </div>
</div>
