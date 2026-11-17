# oficina-execucao — guia para agentes

Serviço de **Execução** da Oficina Mecânica: fila de trabalho da oficina, diagnóstico e reparo. PHP 8.2 puro, sem framework
(ADR-001), nas mesmas camadas do `oficina-api`. Banco: DynamoDB.

O sistema tem seis repos; o mapa, as regras entre eles e as ferramentas (`bin/ws`) ficam no
repositório principal `oficina-mecanica`. O contrato entre os repos é
`docs/fase-4/CONTRATOS.md`, no `oficina-api` (ele soma ao `docs/fase-3/CONTRATOS.md`). É
normativo: se divergir daqui, o documento vence.

## Camadas (`src/`)

- **`Domain/`**: `WorkTask/WorkTask.php` é o agregado, uma tarefa por OS. Cada transição é um
  método nomeado (`startDiagnosis`, `completeDiagnosis`, `failDiagnosis`, `queueRepair`,
  `startRepair`, `completeRepair`, `failRepair`, `cancel`); **não existe `setStatus`**. Cada
  transição registra a linha do tempo e, quando o serviço de OS precisa saber, um evento
  (`releaseEvents()`). `Messaging/Message.php` é o envelope do contrato.
- **`Application/`**: portas em `Port/`; um use case por ação do mecânico em `UseCase/Task/`
  (todos sobre `TaskAction`); `HandleIncomingMessageUseCase` trata os comandos da fila;
  `Worker/Worker.php` é o laço do worker.
- **`Infrastructure/`**: `Aws/` (DynamoDB, SNS, SQS), `InMemory/` (os mesmos contratos em
  memória, para testes e BDD), `ServiceFactory` (a montagem, usada pela API e pelo worker).
- **`Presentation/`**: `TaskController` e o roteador.

## Regras que não podem quebrar

- **Estado e evento na mesma gravação.** Toda mudança passa por
  `WorkTaskRepositoryInterface::save($task, $outbox, $inboxMessageId)`, que grava tudo numa
  transação. Nunca publique direto no tópico a partir de um use case: quem publica é o
  `OutboxRelay`, depois do `save` (na API) e a cada volta do worker.
- **Todo handler roda duas vezes sem repetir efeito.** Comando novo precisa de teste com a mesma
  mensagem e com o mesmo comando sob outro id.
- **O desenho da tabela existe em três lugares** que mudam juntos: `infra/dynamodb.tf`,
  `Infrastructure/Aws/DynamoDbWorkTaskRepository.php` e `scripts/localstack-init.sh`.
- **O JSON do envelope é contrato.** `tests/Unit/Domain/MessageTest.php` tem o literal; os
  outros serviços têm o mesmo.

## O que este repo tem

- `src/`: `Domain`, `Application`, `Infrastructure`, `Presentation`. A dependência aponta para
  dentro.
- `public/index.php`: composition root, com **todas as rotas e a matriz de autorização**. Toda
  rota fica sob `/api/execution`. Rota nova entra aqui e no `swagger.yaml`. Rota sem JWT
  (`requireAuth: false`) também precisa de rota dedicada no `apigateway.tf` do
  `oficina-gateway-auth`.
- `deploy/`: kustomize. Os objetos têm o nome do serviço (`oficina-execucao-config`,
  `oficina-execucao-secret`, `oficina-execucao-nginx`), porque os três serviços dividem o namespace.
- `infra/`: Terraform só do que é deste serviço (ECR, fila, DLQ, assinatura, role IRSA e o
  banco). Lê a plataforma por SSM; não lê parâmetro de outro serviço.

## Contratos

- **JWT:** o mesmo token dos outros serviços. `tests/Unit/Infrastructure/Security/JwtContractTest.php`
  tem o token literal; o `JwtProvider` não muda sozinho aqui (use `/mudar-jwt` no repositório
  principal).
- **Mensagens:** envelope e catálogo no §5 do contrato. Este serviço consome
  `execution.request-diagnosis`, `execution.start-repair`, `execution.cancel`; a lista é `local.consumed_message_types`, em `infra/locals.tf`. Tipo novo
  entra lá, no handler e no catálogo, juntos.
- **Consome (SSM):** `messaging/topic_arn`, `eks/oidc_provider_arn`, `eks/namespace`,
  `eks/cluster_name` e `nlb/execucao/target_group_arn`.
- **Publica (SSM):** `/oficina/<env>/execucao/*`, lidos só pelo `deploy.yml` deste repo.
- **Banco:** só este serviço acessa o próprio banco, e ele não acessa o de ninguém.

## Placeholders do deploy

`TARGET_GROUP_ARN_PLACEHOLDER` (`deploy/base/targetgroupbinding.yaml`) e
`IRSA_ROLE_ARN_PLACEHOLDER` (`deploy/base/serviceaccount.yaml`) **ficam como estão**: o
`deploy.yml` troca os dois por `sed`.

## Validar

- A partir do repositório principal: `bin/ws verify --repo execucao`.
- Aqui: `make test`, `make bdd`, `make coverage` (falha abaixo de 80% das linhas de `src/`),
  `make stan`, `make lint`.
- Contra SNS, SQS e DynamoDB emulados: `bin/ws e2e --fase4`, no repositório principal. Os
  testes daqui usam os adaptadores em memória; só o e2e exercita a tabela de verdade.
- Sem conta AWS, o teto do `infra/` é `terraform fmt` e `validate` sem backend.

## Convenções

- Branches `feat/…`, `fix/…`, `chore/…`, `ci/…`, com PR para `develop`; `develop` faz deploy em
  hml e `main` em prod.
- Commits em português, sem acento no assunto, Conventional Commits com escopo, só com o nome
  do autor: sem `Co-Authored-By` nem rodapé de ferramenta.
- Código em inglês para os conceitos do domínio (glossário no `AGENTS.md` do `oficina-api`).
- Agente nunca roda `terraform apply`, `kubectl apply`/`delete`, nunca lê `.env` nem valor de
  segredo.
