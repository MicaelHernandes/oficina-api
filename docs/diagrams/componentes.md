# Diagrama de componentes

Visão dos componentes da plataforma e suas dependências (4 repositórios).

```mermaid
flowchart TB
    Client["Cliente (web/mobile)"]

    subgraph AWS["AWS us-east-1"]
        GW["API Gateway HTTP API\napi.codefive.com.br\n(repo 1)"]
        subgraph LMB["Serverless (repo 1)"]
            AUTH["Lambda auth\n(CPF -> JWT HS256)"]
            AUTHZ["Lambda Authorizer\n(valida Bearer)"]
        end
        subgraph EKS["Amazon EKS (repo 2)"]
            ALB["ALB compartilhado\napp / grafana"]
            APP["Deployment oficina-api\nphp-fpm + nginx (repo 4)"]
            REDIS["Redis (cache/fila)"]
            WORKER["queue-worker / scheduler"]
            MON["kube-prometheus-stack\n+ Loki + Grafana"]
        end
        RDS[("RDS PostgreSQL 16\n(repo 3)")]
        SM["Secrets Manager\n/oficina/rds/master\n/oficina/jwt/secret"]
        ECR["ECR"]
        SSM["SSM /oficina/*"]
    end
    CF["Cloudflare DNS\ncodefive.com.br"]

    Client -->|HTTPS| CF --> GW
    GW -->|POST /auth| AUTH
    GW -->|ANY /proxy+| AUTHZ
    AUTHZ -->|autorizado| ALB
    ALB --> APP
    APP --> REDIS
    APP --> RDS
    AUTH --> RDS
    WORKER --> RDS
    APP -->|/metrics| MON
    APP -->|logs JSON| MON
    AUTH -.->|JWT_SECRET| SM
    APP -.->|secret via pipeline| SM
    APP -.->|imagem| ECR
    APP -.->|reads| SSM

    classDef repo1 fill:#fde68a,stroke:#b45309;
    classDef repo2 fill:#bfdbfe,stroke:#1d4ed8;
    classDef repo3 fill:#bbf7d0,stroke:#15803d;
    classDef repo4 fill:#e9d5ff,stroke:#7e22ce;
    class GW,AUTH,AUTHZ repo1;
    class ALB,REDIS,MON repo2;
    class RDS repo3;
    class APP,WORKER repo4;
```

**Legenda de responsabilidade:** amarelo = repo 1 (auth serverless), azul = repo 2 (k8s infra), verde = repo 3 (banco), roxo = repo 4 (aplicação).
