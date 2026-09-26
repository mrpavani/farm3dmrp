-- ============================================================
-- SCRIPT DE LIMPEZA GERAL DO BANCO DE DADOS (PRODUÇÃO)
-- Sistema: Fábrica 3D (MRP / Gestão de Impressão 3D)
--
-- OBJETIVO:
-- Apagar todos os dados de pedidos, produções, movimentações,
-- filamentos, lotes, clientes, produtos, peças e cores, zerando os contadores.
--
-- PRESERVAÇÃO:
-- A tabela `usuarios` NÃO é apagada. Todos os usuários,
-- administradores, logins e senhas permanecem intactos.
--
-- COMO EXECUTAR NO phpMyAdmin (Hostinger / cPanel):
-- 1. Abra o phpMyAdmin e selecione o banco de produção
-- 2. Clique na aba "SQL"
-- 3. Cole todo o conteúdo deste arquivo e clique em "Executar"
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Limpar movimentações de estoque e filamentos
DELETE FROM `filamento_movimentacoes`;
ALTER TABLE `filamento_movimentacoes` AUTO_INCREMENT = 1;

DELETE FROM `filamento_lotes`;
ALTER TABLE `filamento_lotes` AUTO_INCREMENT = 1;

DELETE FROM `filamentos`;
ALTER TABLE `filamentos` AUTO_INCREMENT = 1;

DELETE FROM `movimentos_estoque`;
ALTER TABLE `movimentos_estoque` AUTO_INCREMENT = 1;

DELETE FROM `producoes`;
ALTER TABLE `producoes` AUTO_INCREMENT = 1;

-- 2. Limpar itens de pedidos e pedidos
DELETE FROM `pedido_itens`;
ALTER TABLE `pedido_itens` AUTO_INCREMENT = 1;

DELETE FROM `pedidos`;
ALTER TABLE `pedidos` AUTO_INCREMENT = 1;

-- 3. Limpar clientes
DELETE FROM `clientes`;
ALTER TABLE `clientes` AUTO_INCREMENT = 1;

-- 4. Limpar catálogo de produtos, fichas técnicas, peças e cores
DELETE FROM `produto_cores`;
ALTER TABLE `produto_cores` AUTO_INCREMENT = 1;

DELETE FROM `produto_pecas_cores`;
ALTER TABLE `produto_pecas_cores` AUTO_INCREMENT = 1;

DELETE FROM `produto_pecas`;
ALTER TABLE `produto_pecas` AUTO_INCREMENT = 1;

DELETE FROM `produto_composicao`;
ALTER TABLE `produto_composicao` AUTO_INCREMENT = 1;

DELETE FROM `produtos`;
ALTER TABLE `produtos` AUTO_INCREMENT = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- Verificação final do status da limpeza
SELECT 'usuarios' AS `tabela`, COUNT(*) AS `registros_restantes` FROM `usuarios`
UNION ALL
SELECT 'clientes', COUNT(*) FROM `clientes`
UNION ALL
SELECT 'produtos', COUNT(*) FROM `produtos`
UNION ALL
SELECT 'produto_pecas', COUNT(*) FROM `produto_pecas`
UNION ALL
SELECT 'produto_pecas_cores', COUNT(*) FROM `produto_pecas_cores`
UNION ALL
SELECT 'produto_cores', COUNT(*) FROM `produto_cores`
UNION ALL
SELECT 'produto_composicao', COUNT(*) FROM `produto_composicao`
UNION ALL
SELECT 'filamentos', COUNT(*) FROM `filamentos`
UNION ALL
SELECT 'filamento_lotes', COUNT(*) FROM `filamento_lotes`
UNION ALL
SELECT 'filamento_movimentacoes', COUNT(*) FROM `filamento_movimentacoes`
UNION ALL
SELECT 'pedidos', COUNT(*) FROM `pedidos`
UNION ALL
SELECT 'pedido_itens', COUNT(*) FROM `pedido_itens`
UNION ALL
SELECT 'producoes', COUNT(*) FROM `producoes`
UNION ALL
SELECT 'movimentos_estoque', COUNT(*) FROM `movimentos_estoque`;
