# RFC-002 — Banco de dados: Amazon RDS for PostgreSQL

- **Status:** Aceita · **Data:** 2026-09

> A RFC completa e o **diagrama ER** vivem no repositório de infraestrutura do banco:
> **[oficina-db-infra/docs](https://github.com/MicaelHernandes/oficina-db-infra/tree/master/docs)**
> (`rfc-002-rds-postgresql.md` e `er.md`). Este resumo evita divergência.

## Resumo da decisão

**Amazon RDS for PostgreSQL 16**, `db.t4g.micro` (Free Tier), 20 GB gp3, single-AZ, **não público** (subnets privadas), 1 database `oficina`, senha no Secrets Manager (`/oficina/rds/master`), acesso 5432 restrito ao SG dos nós EKS e ao SG da Lambda.

## Por quê

O domínio é fortemente relacional (ver [ER](../diagrams/er.md)): `order_services` referencia `customers`, `vehicles`, `users`, agrega itens de serviço/peça e um `budget` 1:1, com FKs e valores `decimal(10,2)`. PostgreSQL atende nativamente e o app já foi escrito para ele. Gerenciado = backup/patch/observabilidade sem operação manual, compatível com a Lambda de auth.

Alternativas (Aurora Serverless, Postgres self-hosted no EKS, DynamoDB) foram rejeitadas por custo mínimo maior, perda do "gerenciado" ou incompatibilidade com o modelo relacional. Detalhes na RFC do repo `oficina-db-infra`.
