# ADR-001 — Padrão de comunicação: REST + JSON

- **Status:** Aceita · **Data:** 2026-09

## Contexto

A plataforma é composta por um cliente (web/mobile), o API Gateway, a Lambda de auth e o backend Laravel. É preciso um padrão de comunicação síncrono, simples de consumir e amplamente suportado por ferramentas (Swagger/Postman).

## Decisão

Comunicação **REST sobre HTTP com payloads JSON**. Recursos modelados como substantivos (`/api/order-services`, `/api/customers`), verbos HTTP para operações CRUD e rotas de ação para transições de estado da OS (`/order-services/{id}/start-execution`). Respostas e erros sempre em JSON (middleware `ForceJsonResponse`). Contrato documentado via **OpenAPI/Swagger** (l5-swagger).

## Alternativas

- **gRPC:** mais performático e tipado, porém overhead de tooling e menos amigável para clientes web/Postman; desnecessário para o volume do desafio.
- **GraphQL:** flexível para o cliente, mas adiciona complexidade de schema/resolvers sem ganho claro aqui.
- **Mensageria (assíncrono) como padrão de borda:** inadequado para request/response de UI; usamos filas (Redis) apenas internamente (e-mails, jobs).

## Consequências

- (+) Baixa fricção de integração, testável com Pest/HTTP, documentável com Swagger.
- (+) Compatível com o proxy do API Gateway (`ANY /{proxy+}` → ALB).
- (−) Sem streaming/bidirecional nativo; não necessário no escopo.
