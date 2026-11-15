# oficina-execucao — guia para agentes

Serviço de **Execução** da Oficina Mecânica: fila de trabalho da oficina, diagnóstico e reparo. PHP 8.2 puro, sem framework
(ADR-001), nas mesmas camadas do `oficina-api`. Banco: DynamoDB.

O sistema tem seis repos; o mapa, as regras entre eles e as ferramentas (`bin/ws`) ficam no
repositório principal `oficina-mecanica`. O contrato entre os repos é
`docs/fase-4/CONTRATOS.md`, no `oficina-api` (ele soma ao `docs/fase-3/CONTRATOS.md`). É
normativo: se divergir daqui, o documento vence.

**Estado: esqueleto.** Só existem `/api/execution/health` e `/api/execution/ready`. A regra de negócio,
o worker e o acesso ao banco entram nas etapas seguintes do plano da Fase 4.

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
- Aqui: `make test`, `make coverage` (falha abaixo de 80% das linhas de `src/`), `make stan`,
  `make lint`.
- Sem conta AWS, o teto do `infra/` é `terraform fmt` e `validate` sem backend.

## Convenções

- Branches `feat/…`, `fix/…`, `chore/…`, `ci/…`, com PR para `develop`; `develop` faz deploy em
  hml e `main` em prod.
- Commits em português, sem acento no assunto, Conventional Commits com escopo, só com o nome
  do autor: sem `Co-Authored-By` nem rodapé de ferramenta.
- Código em inglês para os conceitos do domínio (glossário no `AGENTS.md` do `oficina-api`).
- Agente nunca roda `terraform apply`, `kubectl apply`/`delete`, nunca lê `.env` nem valor de
  segredo.
