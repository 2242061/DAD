# Começar aqui — trabalhar no projeto

## 1. Que aplicações abrir

Precisas de abrir apenas:

1. **Visual Studio Code**: editar o código e executar os servidores nos terminais integrados.
2. **Safari ou Chrome**: utilizar e testar a plataforma.

PHP, Composer, Node.js e npm já estão instalados. Não precisas de abrir uma aplicação para cada ferramenta. O SQLite funciona através de um ficheiro da base de dados; não precisas de instalar um servidor de base de dados.

## 2. Abrir a pasta certa

No VS Code, escolhe **File → Open Folder…** / **Ficheiro → Abrir Pasta…**, seleciona `/Users/joaoduarte/Desktop/DAD_Projeto` e carrega em **Open** / **Abrir**.

Na barra lateral, deves ver `backend`, `frontend`, `realtime`, `docs`, os enunciados e o `README.md`. Abre a pasta principal, para os atalhos funcionarem.

## 3. Iniciar a plataforma

Se a aplicação já abrir em http://127.0.0.1:5173, os servidores estão ligados. Não inicies outra cópia nas mesmas portas.

### Opção A — atalhos preparados no VS Code

1. Carrega em **⌘⇧P** (`Command + Shift + P`).
2. Escreve **Tasks: Run Task** / **Tarefas: Executar Tarefa**.
3. Seleciona **DAD: Iniciar tudo**.
4. Os três servidores arrancam em terminais separados.

Também podes executar individualmente **DAD: API Laravel**, **DAD: Tempo real** e **DAD: Interface Vue**.

### Opção B — perceber os comandos

No menu **Terminal → New Terminal** / **Terminal → Novo Terminal**, abre três terminais. Usa o botão **+** do painel Terminal para criar os seguintes.

No primeiro terminal, executa a API. É o servidor que valida o login, aplica permissões e consulta a base de dados:

```sh
cd /Users/joaoduarte/Desktop/DAD_Projeto/backend
php artisan serve --host=127.0.0.1 --port=8000
```

No segundo terminal, executa o servidor de tempo real. Será usado pelas partidas:

```sh
cd /Users/joaoduarte/Desktop/DAD_Projeto/realtime
npm run dev
```

No terceiro terminal, executa a interface Vue:

```sh
cd /Users/joaoduarte/Desktop/DAD_Projeto/frontend
npm run dev
```

Mantém os terminais abertos enquanto trabalhas. Não escrevas novos comandos num terminal que está a executar um servidor; abre outro. Para parar um servidor, usa **Control + C** no respetivo terminal. Para parar tarefas, usa **Tasks: Terminate Task** na paleta de comandos.

## 4. Abrir e experimentar a plataforma

No Safari ou Chrome, abre **http://127.0.0.1:5173**. Usa sempre este endereço para não alternares entre sessões de `localhost` e `127.0.0.1`.

### Como jogador

1. Carrega em **Criar conta**.
2. Preenche o nome, email, palavra-passe e confirmação. A palavra-passe deve ter pelo menos 8 caracteres, com letras e números.
3. Depois do registo, entras automaticamente em **A minha conta**.
4. Atualiza a página: a sessão deve continuar ativa.
5. Carrega em **Sair** para terminar a sessão.
6. Em **Entrar**, usa o mesmo email e palavra-passe para voltar.

O registo público cria sempre um **jogador**. Não existe uma opção para escolher administrador no formulário.

### Como administrador

Foi criada uma conta local para experimentares o painel. Abre o ficheiro **`.local/ADMIN.md`** no VS Code para consultar o email e a palavra-passe gerada. Está excluído do Git e não é enviado com o código.

Entra pelo mesmo formulário e escolhe **Administração** no menu lateral. O painel permite pesquisar e filtrar membros, editar nome/email/tipo de conta, bloquear, desbloquear, eliminar e recuperar contas. As alterações exigem a tua palavra-passe de administrador. Antes de gerir outras contas, confirma o teu email seguindo as instruções abaixo.

Não podes bloquear, eliminar ou alterar o teu próprio tipo de conta. Usa **A minha conta** para editar o teu perfil. O botão **Histórico de ações** mostra quem fez cada alteração e quando.

Para criar outro administrador, abre um novo terminal e executa:

```sh
cd /Users/joaoduarte/Desktop/DAD_Projeto/backend
php artisan app:create-admin novo.admin@example.test --name="Administrador"
```

Substitui o email e o nome pelos que pretendes. O terminal pede a palavra-passe e a confirmação. Os caracteres não aparecem enquanto escreves; é normal. O comando cria uma conta nova e recusa emails que já existem.

Também existe uma conta fictícia de jogador usada na verificação dos formulários. Podes criar a tua própria conta para trabalhar.

### Editar perfil e palavra-passe

Em **A minha conta → O meu perfil**, altera o nome e carrega em **Guardar alterações**. Para mudar o email, indica também a palavra-passe atual; o novo endereço fica por confirmar e as outras sessões são invalidadas.

Em **A minha conta → Segurança**, podes alterar a palavra-passe. A alteração termina todas as sessões, incluindo a atual, e voltas ao ecrã de entrada. Tens de entrar com a nova palavra-passe.

Os jogadores também têm a opção **Eliminar a minha conta**, nesta secção. Exige a palavra-passe e escrever **ELIMINAR**. A conta fica desativada e pode ser recuperada por um administrador. O email continua reservado para permitir essa recuperação.

### Confirmar email e recuperar a palavra-passe no computador

Nesta fase, os emails são gerados **apenas localmente**; não chegam a caixas de correio reais. Não precisas de instalar outra aplicação para os testar.

1. Para confirmar o email, entra na conta e carrega em **Reenviar confirmação**.
2. Para recuperar a palavra-passe, usa **Esqueci-me da palavra-passe** no ecrã de entrada e indica o email da conta.
3. Abre um terminal adicional no VS Code e executa:

```sh
cd /Users/joaoduarte/Desktop/DAD_Projeto/backend
php artisan app:mail-links
```

4. O comando mostra os links mais recentes. Copia o link adequado para o navegador ou carrega nele com **⌘ + clique**, se o terminal o permitir.
5. Na confirmação, deves estar autenticado na conta que recebeu a mensagem e carregar em **Confirmar o meu email**. Se for pedido login, entra nessa conta: a plataforma preserva o destino do link.
6. Na recuperação, escolhe e confirma uma nova palavra-passe. O link expira ao fim de 60 minutos e só funciona uma vez. Depois, entra com a nova palavra-passe.

Podes usar `php artisan app:mail-links --limit=1` para mostrar só o link mais recente. Os emails completos estão em `backend/storage/logs/mail.log`. Este comando é exclusivo do ambiente local e não existe um endpoint público para consultar mensagens ou tokens.

### Bloquear, eliminar e recuperar um membro

Em **Administração**, escolhe a ação na linha do membro. Confirma a tua palavra-passe e indica um motivo breve. O bloqueio impede o login e invalida as sessões; o desbloqueio exige um novo login. A eliminação é recuperável: seleciona **Estado → Eliminadas** e carrega em **Recuperar**. Os registos anteriores continuam disponíveis no histórico.

Alterar o tipo de conta ou o email de outro membro também invalida os seus acessos anteriores. As palavras-passe dos membros nunca são mostradas ao administrador.

## 5. Onde mexer no código

| Quero alterar… | Ficheiro ou pasta |
| --- | --- |
| Cores, tamanhos, espaços e adaptação ao telemóvel | `frontend/src/assets/main.css` |
| Menu lateral, cabeçalho e rodapé | `frontend/src/App.vue` |
| Formulários e textos de entrar/registar | `frontend/src/views/AuthView.vue` |
| A página inicial do clube | `frontend/src/views/ExploreView.vue` |
| Perfil, alteração de palavra-passe e eliminação da própria conta | `frontend/src/views/AccountView.vue` |
| O painel de administração | `frontend/src/views/AdminView.vue` |
| Histórico das alterações | `frontend/src/views/AuditView.vue` |
| Recuperação da palavra-passe | `frontend/src/views/RecoveryView.vue` |
| Confirmação do email | `frontend/src/views/VerifyEmailView.vue` |
| Lógica das alterações ao perfil e à palavra-passe | `backend/app/Http/Controllers/AccountController.php` |
| Bloqueio, edição e recuperação de utilizadores | `backend/app/Http/Controllers/AdminUserController.php` |
| Os endereços das páginas e as restrições de navegação | `frontend/src/router.ts` |
| Estado da conta e chamadas de login/logout | `frontend/src/composables/useAuth.ts` |
| Pedidos HTTP e tratamento dos erros da API | `frontend/src/lib/api.ts` |
| Lógica de registar, entrar, consultar conta e sair | `backend/app/Http/Controllers/AuthController.php` |
| Validação dos dados recebidos | `backend/app/Http/Requests/` |
| Dados do painel administrativo | `backend/app/Http/Controllers/AdminOverviewController.php` |
| Endereços disponíveis na API | `backend/routes/api.php` |
| Proteção da administração no servidor | `backend/app/Http/Middleware/EnsureUserIsAdmin.php` |
| Tipos de conta | `backend/app/UserRole.php` |
| Modelo dos utilizadores | `backend/app/Models/User.php` |
| Alterações à estrutura da base de dados | `backend/database/migrations/` |
| Criação de administradores pelo terminal | `backend/app/Console/Commands/CreateAdmin.php` |
| Testes da autenticação e permissões | `backend/tests/Feature/` |
| Comunicação em tempo real | `realtime/src/server.js` |

No VS Code, usa **⌘P** (`Command + P`) e escreve o nome do ficheiro para o abrir rapidamente. Guarda com **⌘S**. O Vue atualiza a página automaticamente quando guardas os ficheiros da interface.

Uma primeira experiência: abre `frontend/src/views/AuthView.vue`, procura **Bom ter-te de volta.**, altera esse texto, guarda e vê o resultado no navegador.

O nome visual **Dama** é provisório e pode ser alterado. O tabuleiro desta etapa é uma ilustração; ainda não se pode jogar.

## 6. Verificar alterações

Num terminal adicional, executa os testes do servidor:

```sh
cd /Users/joaoduarte/Desktop/DAD_Projeto/backend
php artisan test --compact
```

Para verificar o frontend e gerar a compilação:

```sh
cd /Users/joaoduarte/Desktop/DAD_Projeto/frontend
npm run build
```

## Se alguma coisa não abrir

- **O navegador não liga à página**: confirma que o terminal do Vue mostra a porta 5173.
- **Os formulários mostram erro de ligação**: confirma que o Laravel está a executar na porta 8000.
- **Port already in use / Address already in use**: já existe um servidor nessa porta. Usa a cópia existente ou para-a antes de iniciar outra.
- **Table ou coluna inexistente**: num terminal adicional dentro de `backend`, executa `php artisan migrate`.
- **PHP ou npm não encontrados no terminal do VS Code**: fecha esse terminal e abre um novo; se necessário, reabre o VS Code para atualizar o ambiente.

## Como funciona esta etapa

O Vue envia os dados para o Laravel. O Laravel valida-os, guarda a palavra-passe como hash e mantém o login com uma sessão num cookie HttpOnly, com proteção contra pedidos forjados. O perfil é carregado novamente ao atualizar a página. As permissões são verificadas no servidor em cada pedido à administração.

Não há passwords nem tokens de login guardados no `localStorage`. As novas contas são jogadores. O primeiro administrador é criado no terminal; administradores com email confirmado podem atribuir esse papel a outros membros, com confirmação da palavra-passe e registo no histórico.

## Para a publicação na ESTG

O módulo funciona no ambiente local. Antes da publicação, configurar no `.env` da infraestrutura:

- `APP_ENV=production` e `APP_DEBUG=false`.
- `APP_URL` e `FRONTEND_URL` com os endereços corretos, em HTTPS.
- `SANCTUM_STATEFUL_DOMAINS` com o domínio da interface, e `SESSION_SECURE_COOKIE=true`.
- `MAIL_MAILER=smtp`, servidor, porta, credenciais e remetente autorizados pela infraestrutura/docente. Os emails deixarão então de ficar apenas no ficheiro local.

Os testes usam SQLite em memória e não apagam as tuas contas locais. Foi também guardada uma cópia da base de dados anterior a este módulo em `.local/backups/auth-before-management.sqlite`.

## Experimentar o tabuleiro

Com os serviços ligados, abre http://127.0.0.1:5173/#/jogar e clica em **Começar partida local**. Cada pessoa joga uma cor no mesmo computador. Clica numa peça assinalada e num destino com ponto; completa todos os saltos nas capturas múltiplas. As brancas começam.

Podes rodar o tabuleiro, consultar os lances, desistir ou começar outra partida. Atualizar a página recupera a partida da sessão. Para editar esta funcionalidade, usa `frontend/src/views/PlayView.vue`; as regras estão em `backend/app/Services/CheckersEngine.php`. Para ligar dois navegadores, usa agora o separador Jogar online.

## Jogar entre dois navegadores

1. Mantém os três servidores ligados e abre **Jogar → Jogar online**.
2. Clica em **Criar sala** e depois **Copiar convite**. Ficas com as brancas.
3. Abre uma janela privada ou outro navegador no mesmo computador e cola o convite.
4. Clica em **Entrar na sala**. Esta sessão fica com as pretas; as brancas começam.
5. Cada pessoa só consegue jogar na sua vez. Os lances e capturas aparecem nos dois tabuleiros.

Duas abas normais do mesmo navegador partilham a identidade e não representam dois jogadores. Mantém a sessão e o convite para voltares à partida. Uma falha temporária de rede não atribui uma derrota; ao reconectar, o tabuleiro recupera o estado do servidor.

Já configurei a ligação privada Laravel/Node neste computador e apliquei a migração da tabela `online_games`. Numa cópia nova, executa `node scripts/configure-realtime.mjs` na raiz e `php artisan migrate` no backend, antes de iniciares os servidores. O código das salas está em `backend/app/Http/Controllers/OnlineGameController.php`; as notificações estão em `realtime/src/game-server.js`; o tabuleiro usa `frontend/src/views/PlayView.vue`.

## Relógios e os teus resultados

- Ao entrar o segundo jogador, começam os relógios. Cada cor dispõe de **60 segundos na sua vez**. Se chegar a zero, passa a vez ao adversário e recomeça a contagem dessa nova vez.
- O contador **Tempo de jogo** mostra a duração total e fica parado quando a partida termina.
- Em **A minha conta**, vês os totais. Em **Os meus jogos**, vês as partidas e podes abrir **Ver lances**.
- Para guardar os resultados no perfil, entra com a tua conta antes de criar ou aceitar uma sala. A outra pessoa usa outra conta ou joga como visitante. Partidas locais e anónimas não entram no perfil.
- Esta passagem de vez por tempo é uma variante solicitada nesta fase. A regra de derrota por tempo do enunciado ainda terá de ser alinhada antes da entrega.
