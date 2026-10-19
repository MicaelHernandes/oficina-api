# oficina-api

Aplicação principal (Laravel 13) do **Tech Challenge Fase 3 — Oficina Mecânica**. É o **repositório 4 de 4**: a API REST do domínio (clientes, veículos, catálogo, estoque, ordens de serviço, relatórios), rodando em **Amazon EKS** atrás do **API Gateway** (repo 1) e do **ALB** (repo 2), usando o **RDS PostgreSQL** (repo 3).

| Repo | Papel |
|---|---|
| [oficina-k8s-infra](https://github.com/MicaelHernandes/oficina-k8s-infra) | VPC, EKS, ECR, ALB, DNS/TLS, monitoring, OIDC |
| [oficina-db-infra](https://github.com/MicaelHernandes/oficina-db-infra) | RDS PostgreSQL gerenciado |
| [oficina-auth-lambda](https://github.com/MicaelHernandes/oficina-auth-lambda) | Lambda de auth por CPF + API Gateway |
| **oficina-api** (atual) | Aplicação Laravel no EKS |

## Tecnologias

- **PHP 8.4 / Laravel 13**, arquitetura **DDD** (bounded contexts em `src/`: Customer, Catalog, Inventory, Workshop, Reports).
- **PostgreSQL** (RDS), **Redis** (cache/fila/sessão), **Sanctum** (staff), **firebase/php-jwt** (JWT do gateway).
- **Swagger/OpenAPI** (l5-swagger), **Pest** (testes), **Pint** (estilo), **PHPStan/Larastan** (análise estática).
- **prometheus_client_php** (`/metrics`), **Monolog JSON** (logs correlacionados).
- **Docker** (php-fpm) + **nginx sidecar**, **Kustomize** (base + overlay `prod`), **HPA**.

## Arquitetura no cluster

```mermaid
flowchart LR
    GW["API Gateway (repo 1)"] --> ALB["ALB (repo 2)"]
    ALB --> N["nginx sidecar :80"]
    N -->|fastcgi| F["php-fpm :9000"]
    F --> R["Redis"]
    F --> DB[("RDS PostgreSQL")]
    W["queue-worker / scheduler"] --> DB
    F -->|/metrics| P["Prometheus"]
    F -->|logs JSON| L["Loki"]
```

Cada pod roda **php-fpm** (app) + **nginx** (sidecar, fastcgi). HPA por CPU/memória (2→6 réplicas). Ingress via **ALB compartilhado** (`app.codefive.com.br`).

## Saga da OS (Fase 4)

A Fase 4 divide a aplicação em três microsserviços: este repositório é o **OS Service** (clientes, veículos, catálogo, estoque e ciclo de vida da OS), e o **Billing Service** e o **Execution Service** ficam em repositórios próprios. A consistência entre eles é garantida por uma **Saga orquestrada pelo OS Service**, com mensageria **SNS + SQS**. A decisão e as alternativas estão no [ADR-005](docs/adr/ADR-005-saga-orquestrada-sns-sqs.md).

```mermaid
sequenceDiagram
    participant OS as OS Service (orquestrador)
    participant B as Billing Service
    participant E as Execution Service
    OS->>OS: abre OS (saga: diagnosis)
    OS->>B: billing.budget.generate
    B-->>OS: billing.budget.approved
    OS->>B: billing.payment.request (Mercado Pago)
    B-->>OS: billing.payment.confirmed
    OS->>OS: reserva peças
    OS->>E: execution.job.enqueue
    E-->>OS: execution.job.started / finished
    Note over OS,E: falha (sem estoque, execução falhou, prazo vencido)
    OS->>OS: devolve peças, OS cancelada
    OS->>B: billing.payment.refund
    B-->>OS: billing.payment.refunded (saga: compensated)
```

**Por que orquestração:** o fluxo e as compensações ficam num só lugar (`src/Saga/Application/OsSagaOrchestrator.php`), em vez de espalhados pelos três serviços. A OS já tinha uma máquina de estados central, o que facilita testar, rastrear e explicar o rollback. O pagamento é cobrado na aprovação do orçamento, então a compensação de qualquer falha posterior é o estorno.

**Como funciona:**

| Peça | Papel |
|---|---|
| `saga_instances` | Estado, contexto (orçamento, pagamento, peças reservadas), prazo e histórico de cada saga |
| `outbox_messages` + `saga:relay` | Comandos gravados na mesma transação e publicados no SNS depois do commit |
| `inbox_messages` + `saga:consume` | Consome a fila SQS sem processar a mesma mensagem duas vezes |
| `saga:timeouts` (scheduler, 1/min) | Compensa pagamento ou início de execução não confirmados no prazo |
| `GET /api/order-services/{id}/saga` | Estado e histórico da saga (staff) |
| `infra/` (Terraform) | Tópico `oficina-os-events`, fila `oficina-os-service` + DLQ, role IRSA |

**Rastreamento:** o `X-Request-Id` da abertura da OS vira o `correlation_id` da saga e segue em todas as mensagens e logs (filtre por `correlation_id` no Loki). O `/metrics` expõe `oficina_sagas{state}` e `oficina_outbox_pending_messages`.

**Ativação:** `SAGA_ENABLED=false` mantém o fluxo local da Fase 3 até o Billing e o Execution estarem no ar. Com a saga ligada, aprovação e recusa do orçamento e início e fim da execução chegam por evento, e as rotas locais dessas etapas respondem 422. Para receber os eventos, inclua os tópicos dos outros serviços em `consumed_topics` (`infra/`).

## Documentação

- **ADRs** — [`docs/adr`](docs/adr): comunicação REST+JSON, HPA, observabilidade (Prometheus vs Datadog/New Relic), ambiente único, saga orquestrada com SNS/SQS.
- **RFCs** — [`docs/rfc`](docs/rfc): nuvem AWS, RDS PostgreSQL, autenticação CPF+JWT.
- **Diagramas** — [`docs/diagrams`](docs/diagrams): componentes, sequência (autenticação e abertura de OS), ER.
- **Swagger:** `GET /api/documentation` (l5-swagger) na aplicação publicada.

## Autenticação

A API tem **dois públicos**, com credenciais distintas no header `Authorization` — por isso cada rota exige um deles, nunca os dois.

| Público | Rotas | Autenticação | Caminho |
|---|---|---|---|
| **Cliente** | `/api/me/*` (dados, veículos e OS do próprio cliente) | JWT por **CPF**, emitido pela Lambda (repo 1) e revalidado pelo middleware `gateway.jwt` com a mesma `JWT_SECRET` | `api.codefive.com.br` (API Gateway + Lambda Authorizer) |
| **Staff** | demais rotas (clientes, veículos, catálogo, estoque, OS, relatórios) | token **Sanctum** de `/api/auth/login` | `app.codefive.com.br` (ALB) |

No portal do cliente o id vem do claim `sub` do token, nunca da URL, então um cliente não alcança dados de outro. Em produção o `GATEWAY_JWT_ENFORCE=true` liga a revalidação do JWT; em local/testes ele é passthrough. Ver [RFC-003](docs/rfc/RFC-003-auth-cpf-jwt.md).

## Observabilidade

- **`/metrics`** (Prometheus): latência por rota/status, OS por status, tempo médio de execução, falhas de integração. Coletado por `ServiceMonitor`.
- **Alertas** (`PrometheusRule`): falhas 5xx, latência p95 alta, deployment sem réplicas.
- **Dashboards** Grafana via ConfigMap (`grafana_dashboard: "1"`).
- **Logs JSON** no stdout com `X-Request-Id` (e `x-amzn-trace-id` do gateway) → Loki.

## Desenvolvimento local

```bash
composer install
cp .env.example .env && php artisan key:generate
# suba Postgres/Redis (compose.yaml) e rode:
php artisan migrate
php artisan test          # Pest
./vendor/bin/pint --test  # estilo
./vendor/bin/phpstan analyse --memory-limit=1G
```

## Deploy (automático)

- **`ci.yml`** (PR e push em `homolog`/`master`): Pint + PHPStan + Pest (com Postgres), com **cobertura mínima de 80%** (pcov; relatório `coverage.xml` como artefato) + `terraform fmt/validate` da `infra/` + `docker build` sem push.
- **`deploy.yml`** (push em `master`, `environment: production`): `terraform apply` da **`infra/`** (SNS, SQS, IRSA) → build → push **ECR** (tag SHA + `latest`) → `aws eks update-kubeconfig` → cria o **Secret** a partir do Secrets Manager e o **ConfigMap** `oficina-messaging` a partir do SSM → anota a role IRSA no ServiceAccount → `kubectl apply -k k8s/overlays/prod` → **Job de migrations** → `rollout status` → atualiza o **CNAME** `app.codefive.com.br` na Cloudflare.

**Secrets do repositório:** `AWS_ROLE_ARN` (role OIDC `oficina-gha-oficina-api`), `CLOUDFLARE_API_TOKEN`, `LARAVEL_APP_KEY` (chave `base64:...` estável do Laravel). Environment `production`.

## Dependência de ordem

Requer os repos **2 → 3 → 1** aplicados (lê ECR/cluster/DB/JWT via SSM e Secrets Manager). A `infra/` lê `/oficina/eks/oidc_provider_arn` e usa as permissões de SNS/SQS/IAM da role OIDC, ambos criados pelo `oficina-k8s-infra`. Ordem global de provisionamento: **2 → 3 → 1 → 4**.

## Custo

Roda sobre o EKS do repo 2 (sem custo adicional além de tráfego/armazenamento). **Destruir o ambiente após a apresentação.**
