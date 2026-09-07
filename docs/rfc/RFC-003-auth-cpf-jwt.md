# RFC-003 — Estratégia de autenticação: CPF → JWT HS256 com Lambda Authorizer

- **Status:** Aceita · **Data:** 2026-09

## Contexto

O requisito exige que as **rotas sensíveis** sejam protegidas por autenticação **via CPF**, com uma **Function Serverless** que valida o CPF, consulta a existência/status do cliente no banco e devolve um **JWT** válido para as APIs protegidas. O JWT deve ser validado no **API Gateway**.

## Decisão

Fluxo em duas camadas (defense-in-depth):

1. **Emissão (Lambda `oficina-auth`, repo 1):** `POST /auth { cpf }` → valida dígitos do CPF → consulta `customers` no RDS → emite **JWT HS256** com `sub=customer_id`, `cpf`, `iss=oficina-auth`, `exp=15min`. Erros: **400** CPF inválido, **401** cliente inexistente/inativo.
2. **Validação no gateway (Lambda Authorizer, repo 1):** rota `ANY /{proxy+}` protegida por um **Lambda Authorizer** (payload v2, simple response) que valida o `Authorization: Bearer <jwt>` antes de fazer proxy para o ALB.
3. **Validação no backend (defense-in-depth, este repo):** middleware `ValidateGatewayJwt` (`firebase/php-jwt`) revalida o mesmo JWT com a **mesma `JWT_SECRET`** (Secrets Manager `/oficina/jwt/secret`). Configurável por `gateway.jwt.enforce` — **ligado em produção**, desligado em testes/local (onde o Sanctum do staff é usado).

A `JWT_SECRET` é criada pelo repo 1 e compartilhada com este app via Secrets Manager.

### Consulta ao cliente (importante)

A tabela `customers` usa a coluna **`document`** (CPF/CNPJ, só dígitos), **não** `cpf`; **não há coluna `status`**. Cliente **ativo = `deleted_at IS NULL`** (soft delete). A query é:

```sql
SELECT id FROM customers WHERE document = $1 AND deleted_at IS NULL LIMIT 1
```

## Alternativas

| Opção | Análise |
|---|---|
| **JWT HS256 (escolhida)** | Segredo simétrico simples de compartilhar entre Lambda e Laravel; suficiente para o escopo. |
| **JWT RS256 (par de chaves)** | Evita compartilhar segredo (só a pública valida), melhor para muitos consumidores; overhead de gestão de chaves desnecessário aqui. |
| **Cognito** | Gerenciado, mas o requisito é auth **por CPF** com function própria; Cognito não modela login por CPF sem custom flows. |
| **Só Sanctum (sem gateway)** | Não atende o requisito de gateway + serverless. Mantido apenas para o painel interno do staff. |

## Consequências

- (+) Atende o requisito (gateway + serverless + CPF + JWT); validação em duas camadas.
- (+) Token curto (15 min) reduz janela de exposição.
- (−) Segredo simétrico compartilhado — mitigado por Secrets Manager e rotação possível.
- (−) Convivência de dois modelos (JWT do cliente x Sanctum do staff): o JWT do gateway é a fronteira pública; o Sanctum atende operações internas. Documentado.
