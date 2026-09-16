-- ============================================================
-- Migração 002 — usuários (login) e registro automático de quem
-- criou o pedido e de quem registrou cada produção.
--   mysql -u root -p pedidos3d < migracao_002_usuarios.sql
--
-- As colunas de texto antigas (pedidos.vendedor e producoes.responsavel)
-- são removidas; o conteúdo delas é copiado para "observacoes" antes,
-- para não perder o histórico.
-- ============================================================

CREATE TABLE IF NOT EXISTS usuarios (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    login VARCHAR(50) NOT NULL UNIQUE,
    senha_hash VARCHAR(255) NOT NULL,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    ultimo_acesso DATETIME DEFAULT NULL,
    criado_em DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

UPDATE pedidos
   SET observacoes = CONCAT_WS('\n', NULLIF(observacoes, ''), CONCAT('Vendedor (antigo): ', vendedor))
 WHERE vendedor IS NOT NULL AND vendedor <> '';

ALTER TABLE pedidos
    ADD COLUMN usuario_id INT NULL AFTER cliente_id,
    ADD CONSTRAINT fk_pedidos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    DROP COLUMN vendedor;

UPDATE producoes
   SET observacoes = CONCAT_WS('\n', NULLIF(observacoes, ''), CONCAT('Responsável (antigo): ', responsavel))
 WHERE responsavel IS NOT NULL AND responsavel <> '';

ALTER TABLE producoes
    ADD COLUMN usuario_id INT NULL AFTER quantidade,
    ADD CONSTRAINT fk_producoes_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id),
    DROP COLUMN responsavel;
