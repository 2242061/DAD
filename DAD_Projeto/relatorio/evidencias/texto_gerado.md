# Plataforma de damas portuguesas

Relatório de desenvolvimento

Arquitetura jogo online competição e administração

Unidade curricular Desenvolvimento de Aplicações Distribuídas

Curso Engenharia Informática · Ano letivo 2026/27

Instituição [A preencher]

Grupo [A preencher] · Turma [A preencher]

Elementos [Nomes e números de aluno a preencher]

Docentes [A preencher]

Versão 0.10 · 5 de novembro de 2026

### Resumo

A versão atual da plataforma Dama implementa o registo, a autenticação e a gestão de utilizadores. Inclui edição de perfil, alteração e recuperação da palavra-passe, confirmação de email, permissões de jogador e administrador, moderação de contas e histórico de ações. A arquitetura local integra uma SPA em Vue, uma API REST em Laravel, uma base de dados SQLite e um servidor Node.js com Socket.IO.

A validação desta versão terminou com **168 testes aprovados e 1692 asserções**. A plataforma permite jogar localmente, treinar contra o bot, procurar salas públicas, jogar por convite e assistir a partidas competitivas e de torneio. A administração reúne gestão de contas e torneios, métricas, configuração e auditoria financeira. O competitivo cobra entrada em Damas e atribui um prémio ao vencedor. Os torneios distribuem pontos de rank e moeda virtual pelo pódio. A validação externa dos pagamentos e a publicação na ESTG estão por concluir.

<!-- page -->

## 1 Objetivo e estado do projeto

O projeto da unidade curricular de Desenvolvimento de Aplicações Distribuídas consiste numa plataforma online multijogador de damas portuguesas. O enunciado exige uma base de dados relacional, uma API REST, comunicação por WebSocket, uma interface SPA e integração com o serviço de pagamentos simulado fornecido pela unidade curricular [1]. As regras desportivas de referência são as da Federação Portuguesa de Damas [2].

Este relatório documenta a implementação existente a 5 de novembro de 2026. Abrange a arquitetura, as contas, o motor de jogo, o treino, as partidas online, a competição e a administração. A tabela resume o estado local e identifica os requisitos ainda por validar.

### Correspondência com os requisitos

| Área | Situação | Implementação ou trabalho seguinte |
| --- | --- | --- |
| Arquitetura base | Preparada localmente | Laravel, SQLite, Vue e servidor Socket.IO com diagnóstico de ligação. |
| Registo e autenticação | Implementados | Email e palavra-passe, sessão, logout e perfil. |
| Gestão de utilizadores | Implementada | Pesquisa, filtros, edição, papéis, bloqueio e eliminação recuperável. |
| Segurança das contas | Implementada nesta fase | CSRF, limitação de tentativas, recuperação, confirmação de email e revogação de acessos. |
| Emails reais e publicação | Por configurar | SMTP, HTTPS e endereços da infraestrutura da ESTG. |
| Motor e partida local | Implementados nesta etapa | Regras no Laravel, resultados, relógios e histórico na sessão. |
| Partidas casuais online | Implementadas localmente | Salas por convite ou pesquisa, identidade por cor e reconexão. |
| Treino e espectadores | Implementados | Bot sem conta e partidas públicas competitivas e de torneio. |
| Ranking competitivo | Implementado localmente | Dez escalões e classificação pública. Entrada de 50 Damas por jogador e prémio de 100 Damas. |
| Torneios em liga | Implementados localmente | Inscrições, rondas e pódio com pontos e moeda virtual. |
| Administração | Implementada | Gestão de contas e torneios, métricas, configuração e auditoria. |
| Carteira e pagamentos | Implementados localmente | Damas, comissão, histórico e auditoria. Validação externa pendente. |

O ambiente local usa emails registados em ficheiro. A secção 14 enumera os pontos por fechar.

<!-- page -->

## 2 Arquitetura e tecnologias

O frontend apresenta os ecrãs e envia pedidos JSON à API. O Laravel valida os dados, consulta o SQLite e aplica as regras de acesso. A informação persistente fica em `backend/database/database.sqlite`. O Socket.IO comunica alterações das partidas aos jogadores. As contas e as jogadas usam a API REST; o Laravel grava o estado antes de notificar o Node.

![Figura 1 Topologia do ambiente de desenvolvimento](imagens/arquitetura.png)

Durante o desenvolvimento, o Vite encaminha `/api` e `/sanctum` para a porta 8000 e `/socket.io` para a porta 3001. Esta topologia é local. A configuração da publicação será ajustada à infraestrutura disponibilizada pela ESTG.

| Componente | Tecnologia verificada | Função atual |
| --- | --- | --- |
| API REST | Laravel 13.40.0 e PHP 8.5.12 | Contas, autorização e validação das jogadas. |
| Persistência | SQLite 3.54.1 | Utilizadores, sessões, recuperação e histórico de ações. |
| Interface SPA | Vue 3.5.44 e Vue Router 5.4.0 | Formulários, navegação e áreas de jogador e administrador. |
| Compilação | TypeScript 6.0.3 e Vite 8.3.5 | Verificação de tipos e geração dos ficheiros de produção. |
| Tempo real | Node.js 26.0.0 e Socket.IO 4.8.4 | Salas privadas e avisos de alterações das partidas. |

A organização do código separa `backend/`, `frontend/` e `realtime/`. No Laravel, controllers recebem os pedidos, Form Requests validam entradas e middleware protege as rotas. O serviço `AccountSecurity` centraliza a revogação de acessos e o registo de ações. No Vue, as páginas estão em `src/views/` e o estado da sessão em `src/composables/useAuth.ts`.

As tecnologias seguem a stack recomendada no enunciado. Sanctum permite autenticar a SPA com sessões; Vue Router organiza as páginas; PHPUnit verifica o comportamento da API; Pint e Prettier uniformizam a formatação. VS Code e Codex foram usados como ferramentas de apoio ao desenvolvimento e à preparação da documentação.

<!-- page -->

## 3 Persistência e segurança das contas

As migrações Laravel descrevem as entidades persistentes. As tabelas auxiliares do framework incluem migrações, cache e jobs.

| Tabela | Conteúdo principal | Relação ou finalidade |
| --- | --- | --- |
| users | Nome, email único, hash da palavra-passe, papel, confirmação, bloqueio, eliminação e versão de acesso. | Entidade central da conta; a eliminação é lógica e recuperável. |
| sessions | Identificador, utilizador, dados da sessão e última atividade. | Mantém acessos e o estado privado da partida local. |
| password_reset_tokens | Email, hash do token e data de criação. | Recuperação temporária da palavra-passe. |
| audit_logs | Autor, conta afetada, ação, detalhes e data. | Autor e destinatário referenciam users; inclui contas desativadas. |
| online_games e rank_entries | Estado, revisão, relógios, presença, resultados e pontos. | Partidas associadas a contas e a torneios. |
| tournaments e tournament_participants | Inscrições, escalões, horários, classificações e prémios. | Participantes ligados às contas. |
| wallets, wallet_transactions e wallet_entries | Saldos, reservas, pedidos e movimentos. | Rasto das operações financeiras por utilizador. |
| platform_settings | Tempo por jogada e comissão predefinida. | Valores administrativos usados nas novas salas e torneios. |

<!-- page -->

## 3.1 Regras de acesso

| Operação | Visitante | Jogador | Administrador |
| --- | --- | --- | --- |
| Explorar páginas públicas | Sim | Sim | Sim |
| Registo público | Sim | Após sair | Após sair |
| Pedir recuperação | Sim | Sim | Sim |
| Consultar e editar o próprio perfil | Não | Sim | Sim |
| Eliminar a própria conta | Não | Com palavra-passe e confirmação | Impedido |
| Consultar membros e histórico | Não | Não | Sim |
| Alterar outras contas | Não | Não | Email confirmado e palavra-passe |

O registo público cria sempre jogadores e rejeita a escolha de um papel administrativo. O primeiro administrador pode ser criado pelo comando `app:create-admin`. Um administrador com email confirmado pode alterar o papel de outro membro. Não pode bloquear, eliminar ou alterar o próprio papel; a API verifica também a manutenção de um administrador ativo.

As palavras-passe são guardadas como hash. As sessões usam cookies HttpOnly, proteção CSRF e regeneração do identificador no login. Login, registo, recuperação, reenvio de confirmação e alterações de conta têm limitação de tentativas. A API verifica estas regras mesmo quando os pedidos não passam pelos formulários.

A alteração da palavra-passe termina as sessões e invalida tokens anteriores. O bloqueio, a eliminação e alterações relevantes feitas pelo administrador revogam acessos. Uma versão interna da conta impede reutilizar sessões antigas. A alteração do email exige a palavra-passe atual, invalida os links antigos e requer nova confirmação. Os registos de auditoria não incluem palavras-passe, hashes ou tokens.

<!-- page -->

## 4 Interface de acesso e perfil

Os ecrãs mantêm navegação e estilos comuns, com mensagens de validação junto dos campos. A interface adapta-se a ecrãs pequenos e disponibiliza labels, foco visível e estados de carregamento. As capturas desta secção usam contas fictícias do ambiente local.

![Figura 2 Detalhe do formulário de entrada](imagens/login.png)

Depois de se registar, o jogador entra automaticamente na sua conta. O perfil permite alterar o nome e o email. Quando muda o endereço, é pedida a palavra-passe atual e o novo email fica por confirmar.

![Figura 3 Edição dos dados do perfil](imagens/perfil.png)

<!-- page -->

## 5 Gestão de membros e auditoria

A área administrativa apresenta os totais reais e uma lista paginada. A pesquisa considera nome ou email, e os filtros distinguem papéis e estados da conta. As ações de edição e moderação exigem a palavra-passe do administrador. O bloqueio impede o acesso; a eliminação desativa a conta, que pode ser recuperada no filtro de contas eliminadas. O email permanece reservado para essa recuperação.

![Figura 4 Gestão de utilizadores em captura anterior à inclusão das métricas](imagens/administracao.png)

O histórico identifica autor, ação e momento, incluindo alterações de configuração e decisões administrativas sobre torneios. O painel apresenta salas em espera e contagens de partidas e torneios em curso ou terminados. A configuração permite escolher entre 15 e 180 segundos por jogada para novas salas casuais e competitivas e entre 0 e 30 por cento de comissão sugerida ao criar torneios. A API exige email confirmado e confirmação da palavra-passe para estas alterações.

![Figura 5 Histórico de alterações de conta](imagens/historico.png)

<!-- page -->

## 6 Motor de damas e treino

A página Jogar permite a duas pessoas alternar brancas e pretas no mesmo dispositivo, incluindo visitantes. A mesma interface é usada no modo online. O Vue apresenta peças selecionáveis e destinos legais recebidos do Laravel; não calcula uma versão independente das regras. O serviço CheckersEngine é reutilizado nas partidas multijogador.

![Figura 6 Tabuleiro local com histórico de lances](imagens/partida-local.png)

O motor aplica movimentos e capturas dos peões apenas para a frente, damas de longo alcance, captura obrigatória e múltipla, prioridade da quantidade e depois da qualidade. As peças capturadas permanecem como obstáculos até terminar o lance. A promoção termina a jogada. Estão implementados vitória por captura ou bloqueio, desistência, repetição tripla, regra dos 20 lances e limite da forçada, conforme os artigos 3 e 5 das regras [2].

A API recebe a trajetória completa, o identificador da partida e a revisão. Rejeita tabuleiros enviados pelo cliente, trajetórias ilegais ou incompletas e pedidos de uma revisão antiga. O estado fica na sessão privada do navegador, armazenada no SQLite. As rotas usam CSRF, limitação de pedidos e bloqueio da sessão para serializar operações concorrentes. O histórico não é ainda um registo permanente de partidas associado à conta.

O treino permite jogar com as brancas contra o bot sem criar conta. O Laravel calcula a resposta entre os lances legais, avaliando o material e a melhor resposta imediata do adversário. O treino tem uma partida própria na sessão e fica fora da classificação e da carteira. No jogo local e no treino, o estado depende da sessão do navegador.

<!-- page -->

## 7 Salas online e espectadores

Qualquer visitante pode criar uma sala casual e decidir se a publica em Encontrar partida. Também pode entrar com o código ou o link completo. A sessão criadora recebe as brancas e outra sessão recebe as pretas. Dois separadores da mesma sessão representam a mesma pessoa.

![Figura 7 Partida online com cor atribuída e histórico sincronizado](imagens/partida-online.png)

A tabela online_games guarda identificador, código único, hashes das identidades, referências aos utilizadores, fase, revisão, estado JSON, prazo da vez e datas de início e fim. O Laravel valida a participação, a cor, a vez e a trajetória. Uma atualização condicional da revisão impede que dois pedidos concorrentes substituam o mesmo estado. A disputa pela vaga usa também uma atualização condicional, aceite apenas uma vez.

Depois de gravar, o Laravel envia ao Node um aviso autenticado com segredo privado. Os clientes entram apenas na sala autorizada por um ticket HMAC válido durante 90 segundos. O aviso contém identificador e revisão; cada navegador consulta o estado autorizado na API. O cliente não pode transmitir jogadas diretamente aos restantes jogadores.

Na reconexão, o cliente recupera o estado e obtém um novo ticket. A consulta periódica cobre avisos perdidos. No casual, o lugar pertence à sessão. No competitivo e nos torneios, o lugar pertence à conta. A bancada pública permite a visitantes ver o tabuleiro, os relógios e o histórico das partidas competitivas e de torneio em curso. O acesso de espectador é de leitura.

<!-- page -->

## 8 Relógios e histórico no perfil

O servidor guarda o prazo de cada vez. O valor inicial é 60 segundos por jogada e pode ser configurado para novas salas casuais e competitivas. O relógio total começa com a partida e fica fixo no fim. Quem esgota o tempo da sua vez perde em todos os modos.

Online, depois do primeiro lance, um jogador pode propor empate e o outro pode aceitar ou recusar sem reiniciar o relógio. O motor deteta automaticamente o empate técnico em finais de duas peças quando consegue demonstrá-lo. No jogo online, local e de treino, o jogador que tem a vez pode reclamar empate técnico em qualquer posição. A pesquisa explora até 6 000 posições e testa separadamente se cada cor consegue evitar a derrota, mesmo assumindo que as continuações por explorar favorecem o adversário. Se a análise não comprovar o empate, a partida continua.

O serviço GameClock atualiza o estado com base nos prazos persistidos. Uma jogada atrasada é rejeitada e atualizar a página não repõe o relógio. Nas partidas online, 30 segundos sem comunicação determinam derrota por abandono se o adversário continuar presente. Se ambos estiverem ausentes, aplicam-se os prazos do jogo. O agendador verifica os prazos mesmo sem pedidos dos jogadores.

![Figura 8 Estatísticas e histórico privado do jogador](imagens/historico-jogador.png)

O perfil apresenta jogos, vitórias, empates, derrotas, partidas em curso e terminadas. A lista paginada identifica adversário, cor, data, resultado, duração e lances. Os totais são calculados a partir das partidas persistidas, evitando duplicações quando a página é atualizada. A consulta exige autenticação e só devolve partidas associadas à própria conta.

A conta deve estar autenticada ao criar ou entrar na sala para receber o registo. Partidas anónimas e locais não são atribuídas retroativamente ao perfil. Uma sala de espera da própria sessão pode ser associada ao utilizador quando este entra na conta e volta a criar a sala antes do início. O mesmo utilizador registado não pode ocupar ambos os lados. Os exemplos apresentados usam a conta fictícia de testes.

<!-- page -->

## 9 Modos de jogo e ranking competitivo

O modo casual admite visitantes e contas autenticadas, sem alterar o rank. O competitivo exige duas contas diferentes. Neste modo, o lugar pertence à conta e pode ser recuperado noutra sessão através do convite. A classificação pública apresenta apenas nomes, escalões e pontos de participantes com jogos competitivos terminados ou pódios de torneios, excluindo contas bloqueadas ou eliminadas.

Cada vitória vale 30 pontos, cada derrota retira 15 e o empate mantém o total. O mínimo é zero. Os dez escalões começam em Novato 0, Bronze 100, Prata 250, Ouro 450, Platina 700, Diamante 1000, Mestre 1350, Grão-Mestre 1750, Lenda 2200 e Imortal 2800. A subida e a descida dependem dos pontos; a relação vitórias/derrotas é apenas informativa.

![Figura 9 Classificação competitiva e escalões](imagens/ranking.png)

O servidor grava o resultado e os pontos na mesma transação. A tabela rank_entries guarda resultado, variação e totais anterior e posterior, com unicidade por partida e utilizador. Atualizar a página ou repetir um pedido não volta a pontuar. O perfil mostra progresso e estatísticas competitivas; o histórico identifica o modo e a variação de pontos. Os jogos anteriores permanecem casuais.

Os pódios dos torneios também alteram o rank. Cada jogador paga 50 Damas quando a partida competitiva começa. O vencedor recebe 100 Damas. Num empate, ambos recuperam a entrada. A confirmação da vaga e as duas cobranças pertencem à mesma transação. Se faltar saldo a um jogador, a sala continua à espera e os saldos mantêm-se. Os movimentos aparecem na carteira e na auditoria.

<!-- page -->

## 10 Torneios em liga e resultados pessoais

Uma conta autenticada pode criar um torneio com nome, 4 a 16 participantes, escalões mínimo e máximo e duração de ronda e jogo entre 1 e 120 minutos. O jogo não pode ultrapassar a ronda. O criador inicia quando todas as vagas estiverem preenchidas e deve inscrever-se caso queira participar. Antes do início é possível retirar a inscrição ou cancelar o torneio, conservando o registo.

O formato é todos contra todos, uma partida por par. A rotação dos participantes gera os confrontos; um número ímpar implica uma ronda de descanso por jogador. Os lugares dependem das contas e cada participante abre o seu jogo pelo torneio. O limite total determina empate se a partida ainda estiver ativa. As rondas avançam pelo horário; um agendador e as consultas recuperam o estado após interrupções.

![Figura 10 Torneio de demonstração em captura anterior à inclusão da carteira](imagens/torneio.png)

Vitória vale 3 pontos no torneio, empate 1 e derrota 0. Desempata-se por vitórias, pontos de rank à inscrição e inscrição mais antiga. Só o pódio recebe pontos de rank: primeiro 150, segundo 100 e terceiro 60. As partidas do torneio não aplicam os pontos do competitivo normal. A transação final e a unicidade por torneio e conta impedem prémios parciais ou repetidos.

Cada torneio define o valor de inscrição e a comissão. O fundo após a comissão é repartido pelo pódio em 50, 30 e 20 por cento, com arredondamento em Damas inteiras. A receita da plataforma é registada na conclusão. Retirar a inscrição ou cancelar antes do início devolve o valor pago.

O perfil mostra participações, torneios criados e pódios. A administração consulta todos os estados, pode iniciar torneios com lotação completa e pode cancelá-los antes do início. As ações exigem confirmação da palavra-passe e ficam no histórico.

<!-- page -->

## 11 Carteira e pagamentos simulados

A carteira guarda saldo e reservas em Damas, com 100 Damas por euro. O serviço aceita euros inteiros de 1 a 99. A comissão é de 10 por cento do valor pedido. Um carregamento de 10 euros credita 900 Damas. Um levantamento para receber 10 euros debita 1 100 Damas. Os cálculos usam números inteiros.

![Figura 11 Pré-visualização da comissão no carregamento](imagens/carteira.png)

O Laravel valida o método e a referência antes de enviar o pedido ao simulador. As referências são cifradas e aparecem parcialmente ocultas no histórico. O levantamento reserva saldo antes do envio. Uma resposta 201 confirma o movimento e a 422 liberta a reserva. Uma resposta inconclusiva deixa o pedido para verificação. Um identificador por operação impede reenvios duplicados.

A auditoria apresenta saldos, reservas, comissões e resultados. Um administrador com email confirmado pode ajustar saldos e resolver operações inconclusivas com palavra-passe e motivo. Os testes cobriram respostas simuladas de sucesso e falha. Na verificação externa anterior, o endpoint de pagamentos indicado no Anexo A devolveu HTTP 404. A validação de carregamentos e levantamentos de ponta a ponta continua pendente.

<!-- page -->

## 12 Validação e resultados dos testes

A execução guardada com esta versão terminou com **168 testes aprovados, 1692 asserções, zero falhas e zero erros**. Os testes da API usam SQLite em memória. O detalhe de cada caso está em `relatorio/evidencias/testes.xml`.

| Grupo de testes | Testes | Asserções | Resultado |
| --- | --- | --- | --- |
| Regras das damas portuguesas | 21 | 610 | Aprovado |
| Perfil e segurança da conta | 10 | 52 | Aprovado |
| Acesso à administração | 3 | 12 | Aprovado |
| Registo e autenticação | 13 | 55 | Aprovado |
| Modos e pontos competitivos | 15 | 158 | Aprovado |
| Criação de administradores | 2 | 9 | Aprovado |
| Confirmação do email | 4 | 17 | Aprovado |
| Relógios e expiração da vez | 12 | 57 | Aprovado |
| Emails locais | 2 | 6 | Aprovado |
| Salas e autorização por cor | 15 | 110 | Aprovado |
| Recuperação da palavra-passe | 6 | 31 | Aprovado |
| Histórico privado e estatísticas | 5 | 35 | Aprovado |
| Partida local e treino contra o bot | 7 | 49 | Aprovado |
| Espectadores e partidas públicas | 2 | 30 | Aprovado |
| Torneios rondas e prémios | 12 | 222 | Aprovado |
| Moderação e auditoria | 11 | 51 | Aprovado |
| Carteira e pagamentos simulados | 26 | 186 | Aprovado |
| Testes de base | 2 | 2 | Aprovado |
| Total | 168 | 1692 | Aprovado |

Os testes recentes verificam o treino de visitante e a resposta do bot, o empate por acordo sem reiniciar o relógio, a confirmação de um empate técnico com cinco peças, a rejeição da reclamação perante uma captura vencedora, a atribuição única de pontos por derrota por tempo, a derrota por abandono, a cobrança das duas entradas, o prémio, o reembolso no empate, a reversão da cobrança por saldo insuficiente e as permissões na gestão administrativa de torneios.

Passaram os dois testes de Socket.IO e o teste integrado contra Laravel, SQLite, Vite e Node, incluindo disputa pela vaga, avisos, jogadas e desistência. Os resultados estão em `tempo-real.txt` e `partida-integrada.txt`.

A compilação TypeScript e Vite e o diagnóstico HTTP, SQLite e WebSocket passaram. No navegador, foi confirmada a sequência de treino em que as brancas jogaram 9-13 e o bot respondeu 21-18, com ambos os lances no histórico. As capturas anteriores ilustram os fluxos que já existiam.

Não foi medida a cobertura do código nem executados ensaios de carga ou uma auditoria de segurança independente.

<!-- page -->

## 13 Execução e reprodução

Para uma instalação nova, preparar PHP e Composer, Node.js e npm. No backend, instalar as dependências, criar o ficheiro de ambiente, gerar a chave e aplicar as migrações. As versões usadas nesta execução estão registadas em `relatorio/evidencias/ambiente.json`; as dependências exatas estão nos ficheiros de lock.

```sh
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

Executar `npm ci` em `frontend/` e `realtime/`. Na raiz, executar `node scripts/configure-realtime.mjs` para gerar o segredo privado partilhado entre Laravel e Node, sem o expor. Para iniciar a plataforma, abrir quatro terminais na pasta principal e executar os comandos de cada componente, sem os fechar durante a utilização.

| Terminal | Pasta | Comando |
| --- | --- | --- |
| API | backend | php artisan serve --host=127.0.0.1 --port=8000 |
| Tempo real | realtime | npm run dev |
| Interface | frontend | npm run dev |
| Agendador | backend | php artisan schedule:work |

A aplicação abre em `http://127.0.0.1:5173`. O VS Code disponibiliza também a tarefa **DAD: Iniciar tudo**. Para criar um administrador, executar o comando seguinte no backend e responder aos pedidos de palavra-passe.

```sh
php artisan app:create-admin admin@example.test --name="Administrador"
```

As credenciais de avaliação serão definidas para a instância publicada; os ficheiros de acesso locais não integram este relatório.

### Confirmação de email e recuperação

O modo `MAIL_MAILER=log` guarda mensagens em `backend/storage/logs/mail.log`; não as envia para uma caixa de correio real. Depois de pedir confirmação ou recuperação na interface, executar `php artisan app:mail-links` no backend e abrir o link apresentado. A confirmação exige autenticação na conta destinatária. Os links de recuperação expiram ao fim de 60 minutos e só podem ser utilizados uma vez.

### Repetir a validação

```sh
# Dentro de backend
php artisan test --compact
# Dentro de frontend
npm run build
# Na pasta principal com os serviços ligados
node scripts/check-local.mjs
```

O guia `docs/COMECAR_AQUI.md` explica a execução no VS Code e os ficheiros a editar. A pasta `relatorio/` contém o texto-fonte, configuração, gerador, capturas e resultados utilizados nesta versão.

<!-- page -->

## 14 Trabalho seguinte e conclusão desta fase

A reclamação de empate técnico está disponível para qualquer posição. A pesquisa limitada pode produzir um resultado inconclusivo. Nesses casos, a partida pode terminar por acordo ou pelas regras de repetição e de limite de lances quando aplicáveis.

A validação externa da carteira requer confirmar carregamentos e levantamentos com o simulador do Anexo A. Na infraestrutura da ESTG, será preciso configurar HTTPS, cookies, WebSocket, agendador e email, carregar dados realistas e testar com dois jogadores e um espectador em dispositivos distintos.

### Elementos a completar para a entrega

- Identificar grupo, elementos, turma, instituição e docentes e aplicar o modelo da unidade curricular quando estiver disponível.
- Registar o endereço e as credenciais de avaliação da instância publicada.
- Submeter o código, o relatório e o questionário individual e preparar a demonstração oral.
- Prazo indicado no enunciado de 19 de dezembro de 2026 [1].

### Referências

[1] Enunciado do projeto Checkers. Desenvolvimento de Aplicações Distribuídas 2026/27. Ficheiro `2026-27-EI-DAD-Project.pdf`, em particular as secções 4, 5 e 5.1, páginas 3 a 5.

[2] Federação Portuguesa de Damas. Regras oficiais do Jogo de Damas Portuguesas em vigor desde 1 de abril de 2026. Ficheiro `RegrasDoJogoDeDamas_VD_MM_2026_03_29_FINAL.pdf`.

[3] Código desta versão. Pastas `backend/`, `frontend/` e `realtime/`; rotas em `backend/routes/api.php`; testes em `backend/tests/Feature/` e `backend/tests/Unit/`.

[4] Laravel. Documentação oficial da versão 13 sobre autenticação, Sanctum, confirmação de email e recuperação de palavras-passe. https://laravel.com/docs/13.x/authentication e https://laravel.com/docs/13.x/sanctum

[5] Evidências locais desta execução. `relatorio/evidencias/testes.xml`, `testes.txt`, `compilacao.txt`, `integracao.txt`, `ambiente.json` e `capturas.json`.
