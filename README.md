# Sistema de Pedidos e Produção — Fábrica de Impressão 3D

Sistema simples em **PHP + MySQL + JS puro (sem framework)** para controlar
pedidos de venda e a produção correspondente numa fábrica de impressão 3D.
Todas as telas e APIs exigem **login**; o sistema registra automaticamente
quem criou cada pedido e quem registrou cada produção.

## Estrutura

```
pedidos3d/
├── schema.sql                  ← criação do banco (instalação nova)
├── migracao_00N_*.sql          ← atualizações para bancos já existentes
├── config.php                  ← credenciais do banco
├── includes/
│   ├── db.php                  ← conexão PDO + helpers (JSON, status, clientes)
│   ├── auth.php                ← sessão, login, validação de usuários
│   ├── header.php / footer.php ← layout: menu lateral + cabeçalho da página
│   ├── modal_pedido.php        ← modal de pedido/ordem de estoque (compartilhado)
│   └── modal_cliente.php       ← modal de cliente (compartilhado)
├── api/                        ← endpoints JSON (todos exigem login)
│   ├── pedidos.php  producoes.php  fabricar.php
│   ├── clientes.php produtos.php   usuarios.php  relatorios.php
├── login.php  logout.php
├── index.php                   ← redireciona para pedidos.php
├── pedidos.php                 ← módulo Pedidos (lista + modal)
├── fabrica.php                 ← painel: abas Pedidos e Fabricar
├── clientes.php  produtos.php  usuarios.php  ← módulos (lista + modal)
├── relatorios.php
└── assets/
    ├── css/style.css           ← design system
    └── js/
        ├── comum.js            ← App: api, ícones, modais, confirmação, toasts
        ├── form-pedido.js      ← FormPedido.abrir()
        ├── form-cliente.js     ← FormCliente.abrir()
        └── pedidos, fabrica, clientes, produtos, usuarios, relatorios .js
```

## Como instalar

1. Suba o banco: `mysql -u root -p < schema.sql`
   (cria o banco `pedidos3d` e 3 produtos de exemplo).
   Se o banco foi criado com uma versão anterior, rode as migrações em ordem:
   ```
   mysql -u root -p pedidos3d < migracao_001_clientes_email_descricao.sql
   mysql -u root -p pedidos3d < migracao_002_usuarios.sql
   mysql -u root -p pedidos3d < migracao_003_admin.sql
   mysql -u root -p pedidos3d < migracao_004_producao_estoque.sql
   ```
2. Edite `config.php` com o usuário/senha do seu MySQL.
3. Rode num servidor PHP 8+ (Apache/Nginx) ou localmente:
   `php -S localhost:8000` dentro da pasta.
4. Acesse `http://localhost:8000/` e entre com o administrador inicial:
   **login `admin@mail.com` / senha `A123456`** — troque a senha no primeiro
   acesso (Usuários → Editar). Os demais usuários são cadastrados pelo admin.

## Interface

Padrão de todos os módulos (Pedidos, Clientes, Produtos, Usuários):

1. Ao entrar, a tela mostra **a lista** — barra com busca (ignora acentos),
   filtros e o botão principal **+ Novo…**.
2. **Cadastro e edição** abrem em **modal** (Esc, clique fora ou × fecham).
3. As ações de cada linha são **botões de ícone** com dica ao passar o mouse:
   editar (lápis), excluir (lixeira), ativar/desativar, cancelar, reabrir,
   entregar/concluir e "novo pedido para este cliente".
4. Ações destrutivas pedem **confirmação** numa janela própria.
5. Mensagens aparecem como **avisos flutuantes** (canto inferior direito; no
   celular, no topo). Clique para fechar.

Outros pontos:

- O **formulário de pedido** é o mesmo em Pedidos, Clientes (ícone de pedido)
  e Painel da Fábrica (editar e **Produzir para estoque**). Ele mostra o resumo
  de itens, unidades e valor estimado em tempo real.
- **+ Novo cliente** dentro do pedido abre o cadastro de cliente **por cima**
  do pedido; ao salvar, o cliente já fica selecionado.
- Menu lateral (recolhível no celular); no celular as listas viram cartões.
- Design system em `assets/css/style.css` (cores, tipografia Inter, botões,
  etiquetas de status e prazo, tabelas, modais), com suporte a impressão.

## Usuários e login

- Login com senha (armazenada com `password_hash`), sessão em cookie
  `HttpOnly` / `SameSite=Lax`.
- **Administrador**: único que vê a tela **Usuários** e pode cadastrar,
  editar, promover/rebaixar e ativar/desativar usuários. Sempre existe pelo
  menos um administrador ativo, e um admin não pode tirar o próprio acesso
  de administrador nem se desativar.
- **Usuário comum**: acessa todas as outras telas (pedidos, fábrica,
  clientes, produtos, relatórios). A tela e a API de usuários respondem
  "acesso negado" (403).
- O login pode ser um nome de usuário ou um e-mail (não diferencia maiúsculas).
- **Pedido**: gravado com o usuário logado (`pedidos.usuario_id`). Na edição,
  o criador original é mantido e exibido como "Pedido criado por".
- **Produção**: gravada com o usuário que clicou em "Registrar produção"
  (`producoes.usuario_id`). O painel mostra "Produzido por" em cada item e os
  relatórios agrupam por usuário.
- Usuários não são excluídos (ficam no histórico); **desativar** bloqueia o
  acesso na hora, inclusive de quem já está logado.
- Se a sessão expirar, qualquer tela volta para o login e, depois de entrar,
  retorna para onde estava.

## Como o modelo de dados funciona

- **usuarios**: quem usa o sistema (nome, login, senha, admin, ativo).
- **clientes**: quem compra (nome, telefone, e-mail, cidade/UF, descrição).
- **produtos**: catálogo do que a fábrica imprime.
- **pedidos**: cabeçalho — tipo, cliente, usuário que criou, data de criação,
  data prometida de entrega, status e observações.
  - `tipo = venda`: pedido de um cliente (cliente obrigatório).
  - `tipo = estoque`: **ordem de produção para estoque**, criada pela fábrica
    sem pedido de cliente. Passa pelo mesmo fluxo (itens, produção, status,
    visão Fabricar, relatórios). Ao concluir, as peças são consideradas
    enviadas ao estoque (status `entregue`, exibido como "Concluída").
- **pedido_itens**: as linhas do pedido (produto + quantidade + já produzido).
- **producoes**: cada lote produzido para um item (data, quantidade, usuário).
  Permite produção parcial. O sistema soma `quantidade_produzida` no item e
  recalcula o status do pedido:
  - `aberto` → nada produzido ainda
  - `em_producao` → produção parcial
  - `pronto` → todos os itens completos
  - `entregue` → marcado no painel (só quando o pedido está `pronto`)
  - `cancelado` → marcado no painel (qualquer pedido não entregue)
  - pedidos entregues/cancelados podem ser **reabertos**; enquanto
    finalizados, não aceitam edição nem produção.

## Regras de edição e exclusão

- **Editar** (ícone de lápis, abre o modal): altera cliente, datas, observações e
  itens. Itens com produção registrada não podem ser removidos nem trocar de
  produto, e a quantidade não pode ficar abaixo do já produzido.
- **Excluir**: só pedidos **sem nenhuma produção** (para não apagar histórico
  dos relatórios). Pedidos com produção devem ser cancelados.

## Telas

- **Pedidos (`pedidos.php`)**: lista de pedidos de clientes e ordens de
  estoque (filtros por origem e status — padrão "em andamento"), com itens,
  entrega/prazo, progresso, status e quem criou. **Novo pedido** e **Editar**
  abrem o modal; ícones para entregar/concluir, cancelar, reabrir e excluir.
  Atalhos: `pedidos.php?novo=1&cliente=ID` e `pedidos.php?editar=ID`.
- **Painel da Fábrica (`fabrica.php`)** — botão **Produzir para estoque**
  (cria uma ordem com um ou mais produtos, quantidades e data "concluir até")
  e duas abas:
  - **Pedidos**: lista de pedidos de clientes e ordens de estoque, filtrável
    por origem, status e data. Mostra
    o usuário que criou, o prazo de entrega, os itens (pedido / produzido /
    restante / produzido por), o botão **Registrar produção** por item e os
    ícones de entregar/concluir, editar, cancelar, reabrir e excluir.
  - **Fabricar** (`fabrica.php#fabricar`): visão **por produto** do que falta
    produzir nos pedidos e ordens de estoque abertos/em produção. Ex.: 3 pedidos de Trem → um
    card "Trem — 3 a fabricar", com as quantidades por data de entrega e a
    lista dos pedidos (entrega, cliente, falta) com botão de registrar
    produção. Ordena pelo produto com entrega mais urgente, sinaliza
    atrasados / entrega hoje / próximos dias, e filtra por "entrega até" e
    produto. O resumo mostra unidades a fabricar (para clientes e
    para estoque), atrasadas e para os próximos 7 dias. A aba mostra um
    contador com o total pendente.
- **Clientes (`clientes.php`)**: lista com busca; cadastro/edição em modal;
  ícones de novo pedido, editar e excluir (só sem pedidos). E-mail validado e único.
- **Produtos (`produtos.php`)**: lista com busca e filtro de situação;
  cadastro/edição em modal; ícones de editar, ativar/desativar e excluir
  (só nunca usados). Só produtos **ativos** aparecem no pedido.
- **Relatórios (`relatorios.php`)**: produção por período, com filtros por
  produto e usuário; totais, tabelas por produto, por dia e por usuário, e
  lançamentos com "Produzido por". Exporta CSV ou imprime. O valor usa o
  preço de tabela **atual** do produto.
- **Usuários (`usuarios.php`)** — só administradores: lista com busca;
  cadastro/edição em modal (senha em branco mantém a atual, marcar como
  administrador); ícones de editar e ativar/desativar.

## API

Todas as rotas exigem sessão ativa (sem login → `401`).

| Método | Endpoint | Função |
|---|---|---|
| GET | `api/pedidos.php[?id=&data_de=&data_ate=&status=&tipo=]` | listar / buscar pedido (com `usuario_nome` e `produzido_por` nos itens) |
| POST | `api/pedidos.php` | criar pedido (usuário = logado); `tipo: "estoque"` cria ordem sem cliente |
| PUT | `api/pedidos.php?id=N` | editar pedido (itens com `id` = existentes) |
| PATCH | `api/pedidos.php?id=N` body `{acao: entregar\|cancelar\|reabrir}` | mudar status |
| DELETE | `api/pedidos.php?id=N` | excluir pedido sem produção |
| GET | `api/fabricar.php[?entrega_ate=&produto_id=]` | pendências agrupadas por produto |
| GET | `api/producoes.php[?data_de=&data_ate=]` | listar produções |
| POST | `api/producoes.php` | registrar produção (usuário = logado) |
| GET | `api/clientes.php[?busca=\|?id=N]` | listar / buscar / um cliente |
| POST / PUT / DELETE | `api/clientes.php[?id=N]` | cadastrar / editar / excluir cliente |
| GET | `api/produtos.php[?todos=1\|?id=N]` | produtos ativos / todos / um |
| POST / PUT / PATCH / DELETE | `api/produtos.php[?id=N]` | cadastrar / editar / ativar-desativar / excluir |
| GET | `api/usuarios.php` | listar usuários (admin: completo; demais: só id/nome) |
| POST | `api/usuarios.php` | **admin** — cadastrar `{nome, login, senha, admin}` |
| PUT / PATCH | `api/usuarios.php?id=N` | **admin** — editar / ativar-desativar |
| GET | `api/relatorios.php?data_de=&data_ate=[&produto_id=&usuario_id=]` | relatório de produção |

## Possíveis próximos passos (não incluídos nesta versão)

- Perfis de acesso mais detalhados (ex.: vendas x fábrica)
- Tela "minha senha" para o próprio usuário trocar a senha
- Registrar quem marcou o pedido como entregue/cancelado
- Guardar o preço unitário no item do pedido (valor histórico nos relatórios)
- Cadastro de produtos com foto e tempo médio de impressão
