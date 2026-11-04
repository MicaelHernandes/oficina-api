# ADR-005 — Saga orquestrada pelo OS Service, mensageria SNS + SQS

- **Status:** Aceita · **Data:** 2026-10

## Contexto

Na Fase 4 a aplicação é dividida em três microsserviços, cada um com banco próprio: **OS Service** (este repositório), **Billing Service** (orçamento e pagamento via Mercado Pago) e **Execution Service** (fila de execução). Abrir uma OS, aprovar o orçamento, cobrar e executar passa a envolver três bancos. Sem transação distribuída, precisamos garantir consistência com rollback seguro (Saga Pattern).

## Decisão

**Saga orquestrada**, com o **OS Service como orquestrador** (`src/Saga`):

- A saga de cada OS é uma linha em `saga_instances` (estado, contexto, prazo do passo atual, histórico).
- O orquestrador envia **comandos** (`billing.budget.generate`, `billing.payment.request`, `execution.job.enqueue`, `billing.payment.refund`…) e reage aos **eventos** dos outros serviços (`billing.payment.confirmed`, `execution.job.failed`…).
- O pagamento é cobrado **na aprovação do orçamento**, antes da execução. Se a reserva de peças ou a execução falharem depois, a compensação é **devolver as peças e estornar o pagamento**.

```
Diagnosis ──▶ AwaitingApproval ──▶ AwaitingPayment ──▶ AwaitingExecution ──▶ Executing ──▶ Completed
                  │ recusa              │ recusado/expirou       │ sem estoque / falhou / expirou
                  ▼ definitiva          ▼                        ▼
              Cancelled            Compensated            Compensating ──(estorno)──▶ Compensated
```

**Mensageria: SNS + SQS.** Cada serviço publica no próprio tópico SNS e consome a própria fila SQS (com DLQ). As filas assinam os tópicos dos quais consomem, com filter policy pelo atributo `type`.

**Garantias:**

- **Transactional outbox** (`outbox_messages`): o comando é gravado na mesma transação da mudança de estado e publicado depois pelo `saga:relay`. Nenhum comando sai sem commit, e nenhum commit fica sem o seu comando.
- **Inbox** (`inbox_messages`): o SQS entrega "pelo menos uma vez", então o consumidor (`saga:consume`) ignora mensagens já processadas.
- **Lock da saga** (`SELECT … FOR UPDATE`) durante o processamento de cada mensagem.
- **Timeouts** (`saga:timeouts`, a cada minuto): pagamento ou início de execução não confirmados no prazo disparam a compensação.
- **DLQ** depois de 5 tentativas, para mensagens que falham sempre.

## Alternativas

- **Coreografia** (cada serviço reage aos eventos dos outros): menos acoplamento a um orquestrador, mas o fluxo da OS fica espalhado em três serviços. As compensações (estorno, devolução de peças) também ficariam implícitas, mais difíceis de testar, de rastrear e de explicar. A OS já tinha uma máquina de estados central (`OsStatus`), que é o lugar natural para a saga.
- **RabbitMQ / Kafka no cluster:** mais recursos, mas exigem pods com estado no único nó do EKS (Free plan), que já roda Prometheus, Loki e Grafana. O SNS/SQS é gerenciado, sem custo fixo, com DLQ nativa e autenticação por IRSA.
- **Two-phase commit:** inviável entre bancos e serviços independentes; bloqueia recursos e não tolera falhas parciais.

## Consequências

- (+) O fluxo inteiro e as compensações ficam num só lugar (`OsSagaOrchestrator`), cobertos por testes de feature.
- (+) `GET /api/order-services/{id}/saga` mostra o estado e o histórico. O `correlation_id` (X-Request-Id da abertura) segue em todas as mensagens e logs, para rastrear a OS no Loki. O Prometheus expõe `oficina_sagas{state}` e `oficina_outbox_pending_messages`.
- (−) O OS Service vira ponto central do fluxo: se ele cair, a saga pausa, mas as mensagens esperam na fila (até 4 dias) e o outbox retoma a publicação.
- (−) Consistência eventual: entre o pagamento confirmado e a OS em execução há uma janela em que os serviços divergem.
- A flag `SAGA_ENABLED` mantém o fluxo local da Fase 3 até o Billing e o Execution entrarem no ar. Com a saga ligada, as rotas locais de aprovação e execução respondem 422.
