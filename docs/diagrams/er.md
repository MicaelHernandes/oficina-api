# Diagrama ER (modelo relacional)

Modelo persistido no PostgreSQL (tabelas de domínio; framework omitido). Fonte de verdade e detalhes em [oficina-db-infra/docs/er.md](https://github.com/MicaelHernandes/oficina-db-infra/tree/master/docs/er.md).

> `customers.document` = CPF/CNPJ (só dígitos); "inativo" = soft delete (`deleted_at`). `status` (OS, part_requests) são strings de enums PHP.

```mermaid
erDiagram
    customers ||--o{ vehicles : "customer_id"
    customers ||--o{ order_services : "customer_id"
    vehicles  ||--o{ order_services : "vehicle_id"
    users     ||--o{ order_services : "mechanic_user_id"
    users     ||--o{ part_requests : "requested_by_user_id"
    order_services ||--o{ part_requests : "os_id (nullable)"
    order_services ||--|| budgets : "os_id (1:1)"
    order_services ||--o{ os_service_items : "os_id"
    order_services ||--o{ os_part_items : "os_id"
    order_services ||--o{ os_requested_service_items : "os_id"
    order_services ||--o{ os_requested_part_items : "os_id"
    services  ||--o{ os_service_items : "service_id"
    services  ||--o{ os_requested_service_items : "service_id"
    parts     ||--o{ os_part_items : "part_id"
    parts     ||--o{ os_requested_part_items : "part_id"
    part_requests ||--o{ part_request_items : "part_request_id"
    parts     ||--o{ part_request_items : "part_id"

    customers {
        bigint id PK
        string document UK "CPF/CNPJ"
        string name
        string email UK
        timestamp deleted_at
    }
    order_services {
        bigint id PK
        string status "OsStatus"
        bigint customer_id FK
        bigint vehicle_id FK
        bigint mechanic_user_id FK
        timestamp started_at
        timestamp finished_at
    }
    budgets {
        bigint id PK
        bigint os_id FK "unique"
        decimal total_amount
    }
    vehicles {
        bigint id PK
        bigint customer_id FK
        string plate UK
    }
    parts {
        bigint id PK
        string code UK
        decimal unit_price
        int stock_quantity
    }
    services {
        bigint id PK
        decimal base_price
        boolean is_active
    }
    part_requests {
        bigint id PK
        string status "PartRequestStatus"
        bigint requested_by_user_id FK
        bigint os_id FK
    }
```
