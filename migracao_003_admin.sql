-- ============================================================
-- Migração 003 — perfil de administrador.
-- Só administradores cadastram/alteram usuários.
-- Cria o administrador inicial:
--   login: admin@mail.com   senha: A123456   (troque após o primeiro acesso)
--
--   mysql -u root -p pedidos3d < migracao_003_admin.sql
-- ============================================================

ALTER TABLE usuarios
    MODIFY login VARCHAR(150) NOT NULL,
    ADD COLUMN admin TINYINT(1) NOT NULL DEFAULT 0 AFTER senha_hash;

-- Se o login já existir, apenas garante que ele é administrador e está ativo
-- (a senha existente não é alterada).
INSERT INTO usuarios (nome, login, senha_hash, admin, ativo)
VALUES ('Administrador', 'admin@mail.com', '$2y$12$c9jgI75ewT6lyFbhBP0z6O0MVaa2sO6A3wPjO8Ml3mMY1P.CwH8fG', 1, 1)
ON DUPLICATE KEY UPDATE admin = 1, ativo = 1;
