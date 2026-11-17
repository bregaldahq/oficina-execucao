# oficina-execucao

Serviço de **Execução** do sistema Oficina Mecânica (Tech Challenge SOAT, Fase 4):
fila de trabalho da oficina, diagnóstico e reparo.

É um dos três microsserviços do sistema, ao lado do `oficina-api` (ordem de serviço e
orquestrador da saga) e do outro serviço de negócio. Cada um tem repositório, banco,
infraestrutura e pipeline próprios.

## O que o serviço faz

Uma **tarefa** por ordem de serviço. Ela nasce quando o orquestrador da saga pede o diagnóstico
e anda conforme o mecânico trabalha:

```
QUEUED_DIAGNOSIS → DIAGNOSING → DIAGNOSED → QUEUED_REPAIR → REPAIRING → REPAIRED
                       └→ DIAGNOSIS_FAILED                    └→ REPAIR_FAILED
qualquer status antes de REPAIRED → CANCELLED   (compensação pedida pela saga)
```

| Chega pela fila (comando do orquestrador) | Efeito |
|---|---|
| `execution.request-diagnosis` | abre a tarefa na fila de diagnóstico |
| `execution.start-repair` | orçamento aprovado e pago: a tarefa vai para a fila de reparo |
| `execution.cancel` | compensação: cancela a tarefa e confirma com `execution.cancelled` |

| Ação do mecânico (API) | Evento publicado |
|---|---|
| iniciar, concluir ou falhar o diagnóstico | `diagnosis.started`, `diagnosis.completed` (com os itens), `diagnosis.failed` |
| iniciar, concluir ou falhar o reparo | `repair.started`, `repair.completed`, `repair.failed` |

`repair.failed` é o evento que faz a saga estornar o pagamento e cancelar a OS.

### Consistência (ADR-017)

- **Outbox:** a tarefa, a linha do tempo e o evento a publicar são gravados numa única
  transação do DynamoDB. A API publica logo em seguida, e o worker publica o que tiver ficado
  para trás (tópico fora do ar, processo que caiu). Não existe estado alterado sem evento.
- **Inbox:** o id de cada comando tratado é gravado na mesma transação. Reentrega da fila é
  confirmada sem efeito.
- **Comando repetido** com outro id também não repete o efeito: há uma tarefa por OS.
- **Trava otimista:** duas ações simultâneas sobre a mesma tarefa, a segunda recebe `409`.

## Arquitetura

```
API Gateway ─ /api/execution/** ─► NLB interno :82 ─► Pods oficina-execucao ─► DynamoDB
                                                             │
        SNS oficina-<env>-mensagens ◄─► SQS oficina-<env>-execucao ─► worker (mesma imagem)
```

- **Entrada HTTP:** rotas sob `/api/execution`, com o mesmo JWT dos outros serviços, revalidado
  aqui.
- **Mensagens:** publica só no tópico e lê só da própria fila. Consome
  `execution.request-diagnosis`, `execution.start-repair`, `execution.cancel`.
- **Banco:** DynamoDB, só deste serviço. Nenhum serviço acessa o banco de outro.
- **Código:** PHP 8.2 sem framework, em camadas (`Domain`, `Application`, `Infrastructure`,
  `Presentation`), como o `oficina-api`.

O contrato entre os serviços (rotas, mensagens, saga, segredos, parâmetros) é o
`docs/fase-4/CONTRATOS.md` do repositório `oficina-api`. As decisões estão nas ADRs 011 a 017,
no mesmo lugar.

## Estrutura

| Pasta | O que tem |
|---|---|
| `src/`, `public/`, `bin/worker.php` | a API e o worker |
| `tests/`, `features/` | testes unitários e o BDD |
| `deploy/` | manifests kustomize (`base` e overlays `hml` e `prod`) |
| `infra/` | Terraform do que é só deste serviço: ECR, fila e DLQ, assinatura do tópico, role dos pods e o banco |
| `.github/workflows/` | `pr.yml` e `deploy.yml` |
| `swagger.yaml`, `docs/` | a API e o Swagger UI (`/api/execution/docs`) |

## Rodar e testar

A máquina de desenvolvimento não precisa de PHP: tudo roda em container.

```bash
make install    # dependências
make test       # testes unitários
make bdd        # o fluxo completo em Behat (features/execucao.feature)
make coverage   # testes com cobertura; falha abaixo de 80%
make stan       # PHPStan nível 8
make lint       # estilo (PSR-12)
```

O ponta a ponta contra um LocalStack (SNS, SQS e DynamoDB de verdade, emulados) roda a partir
do repositório principal: `bin/ws e2e --fase4`. Para subir os recursos à mão num LocalStack:
`docker exec -i <localstack> sh < scripts/localstack-init.sh`.

## Qualidade

- **139 testes unitários, 95,1% de cobertura** de linhas de `src/` (medido em 2026-11-15).
- **BDD:** 5 cenários em `features/execucao.feature`: fluxo completo, falha do reparo,
  cancelamento por compensação, mensagem duplicada e compensação repetida.

- Cobertura mínima de **80%** das linhas de `src/`, exigida no `pr.yml` e de novo antes do
  deploy.
- SonarCloud com quality gate no PR (projeto `bregaldahq_oficina-execucao`).
- `main` e `develop` protegidas: só entram por PR com as checagens verdes.

<!-- Evidências (badges do Sonar e da cobertura, link do último run) entram aqui quando o
     primeiro pipeline rodar no GitHub. -->

## CI/CD

| Workflow | Quando | O que faz |
|---|---|---|
| `pr.yml` | PR para `develop` ou `main` | estilo, PHPStan, testes com cobertura, Sonar, build da imagem, `kustomize build`, `terraform fmt` e `validate` |
| `deploy.yml` | push em `develop` (hml) ou `main` (prod) | testes, `terraform apply` do `infra/`, imagem no ECR, `kubectl apply -k`, rollout e smoke |

Autenticação na AWS por OIDC, sem access key. Segredos de repositório: `AWS_ROLE_ARN`,
`TF_STATE_BUCKET`, `NEW_RELIC_LICENSE_KEY`, `NEW_RELIC_API_KEY`, `SONAR_TOKEN`.

Antes do primeiro deploy precisam existir a fundação, o cluster e o gateway (ordem de apply do
contrato).
