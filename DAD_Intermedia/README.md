# DAD — entrega intermédia 2026/27

Esta pasta contém apenas os componentes R1–R3: login e consulta autenticada em Laravel/SQLite, eco Socket.IO em Node.js e uma SPA Vue para testar os dois. O token de acesso fica apenas em memória e expira após uma hora. Não inclui jogos, registo de contas, administração, torneios, ranking nem carteira.

## Executar localmente

Num terminal:

```sh
cd backend
composer install
# Numa cópia nova: cp .env.example .env
# Configurar DEMO_PASSWORD no .env antes do seed.
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

Noutro terminal: `cd realtime`, `npm ci`, `npm start`.
Noutro terminal: `cd frontend`, `npm ci`, `npm run dev`.
Abrir http://127.0.0.1:5173.

A cópia local está preparada com email `teste@example.test` e palavra-passe `EntregaDAD2026!`. Para publicação, configurar credenciais próprias através do Secret Kubernetes.

## Verificar

1. Antes do login, carregar em **Testar GET /api/auth/me**: deve apresentar HTTP 401.
2. Entrar e voltar a testar: deve mostrar id, nome e email do utilizador.
3. Sair: o token é revogado.
4. Carregar em **Ligar**, escrever uma mensagem e **Enviar eco**: deve receber exatamente a mesma mensagem por WebSocket.

Validação automática: `cd backend && php artisan test`; `cd frontend && npm run build`; `cd realtime && npm test` (o teste do eco utiliza o cliente instalado em frontend).

## Publicar na ESTG — obrigatório

Os Dockerfiles e `deploy/kubernetes.yaml` estão preparados como base. Ainda é necessário adaptar aos valores e às regras do cluster atribuídos pelo docente: registry, grupo, namespace, hostname, autenticação do registry, classe de Ingress e StorageClass, se exigidas. O backend usa uma réplica e volume persistente SQLite.

1. Construir e enviar as três imagens (substituir REGISTRY e GG):

```sh
docker build -t REGISTRY/grp-GG/backend:intermediate backend
docker build -t REGISTRY/grp-GG/realtime:intermediate realtime
docker build -t REGISTRY/grp-GG/frontend:intermediate frontend
docker push REGISTRY/grp-GG/backend:intermediate
docker push REGISTRY/grp-GG/realtime:intermediate
docker push REGISTRY/grp-GG/frontend:intermediate
```

2. Substituir REGISTRY, GG e HOST_ESTG no manifesto. Usar o namespace atribuído. Criar `dad-secret` nesse namespace com `APP_KEY` (gerar com `php artisan key:generate --show`), `APP_URL`, `DEMO_EMAIL` e `DEMO_PASSWORD`. Não guardar segredos no manifesto. O seed cria/atualiza o utilizador de teste no arranque.
3. Aplicar `kubectl -n NAMESPACE apply -f deploy/kubernetes.yaml` e confirmar os deployments com `kubectl -n NAMESPACE get pods,svc,ingress,pvc`.
4. Testar os quatro passos acima no Chrome através da intranet ESTG. Confirmar que os dados persistem após recriar o pod backend.

As imagens e o deployment ainda não foram testados no cluster. Não basta entregar o código: o enunciado exige a plataforma publicada e acessível para avaliação.

## Entrega Moodle

Preencher o template oficial `grp_intermediate_report_GG.xlsx` quando fornecido, substituir GG pelo número do grupo e entregar até **21 de novembro de 2026**. O antigo relatório PDF/DOCX de fases posteriores foi retirado desta pasta. O template XLSX oficial não estava disponível, por isso não foi inventado um substituto.

A cópia do projeto completo anterior está em `/private/tmp/dad-original/projeto-completo.tgz`, fora da entrega. Mover esse backup para um local permanente se quiseres conservá-lo; a pasta temporária pode ser limpa pelo sistema.
