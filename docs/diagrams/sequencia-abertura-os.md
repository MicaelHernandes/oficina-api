# Diagrama de sequência — Abertura de Ordem de Serviço (OS)

Fluxo de criação de uma OS e envio para diagnóstico, já autenticado via gateway.

```mermaid
sequenceDiagram
    autonumber
    participant C as Cliente/Atendente
    participant GW as API Gateway (+Authorizer)
    participant MW as Middlewares (RequestId, gateway.jwt, Metrics)
    participant Ctrl as OrderServiceController
    participant UC as CreateOrderService (UseCase)
    participant Repo as OrderServiceRepository
    participant DB as RDS PostgreSQL
    participant Q as Redis (fila)

    C->>GW: POST /api/order-services<br/>{ customer_id, vehicle_id, complaint }
    GW->>MW: proxy (Bearer válido)
    MW->>MW: gera X-Request-Id, valida JWT, inicia timer de latência
    MW->>Ctrl: request validado
    Ctrl->>UC: execute(dto)
    UC->>Repo: save(OrderService status=created)
    Repo->>DB: INSERT INTO order_services (...)
    DB-->>Repo: id
    Repo-->>UC: OrderService
    UC-->>Ctrl: OrderService
    Ctrl-->>MW: 201 Created (OrderServiceResource)
    MW->>MW: observa http_request_duration_seconds{status=201}
    MW-->>C: 201 { id, status: "created", ... }

    Note over C,DB: Envio para diagnóstico
    C->>GW: POST /api/order-services/{id}/send-to-analysis
    GW->>Ctrl: (via middlewares)
    Ctrl->>UC: sendToAnalysis(id)
    UC->>Repo: update(status=in_analysis)
    Repo->>DB: UPDATE order_services SET status='in_analysis'
    UC->>Q: dispatch(notificações/e-mail) [assíncrono]
    Ctrl-->>C: 200 { status: "in_analysis" }
```

O gauge `oficina_order_services{status}` (lido em `/metrics`) reflete o volume por status; o `PrometheusRule` alerta sobre falhas (5xx) no processamento.
