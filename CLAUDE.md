# Convenções — oficina-api

Repositório 4 de 4 do Tech Challenge Fase 3. Aplicação Laravel 13 (DDD em
`src/`) no EKS, atrás do API Gateway (repo 1) e do ALB (repo 2), usando o RDS
(repo 3).

## Regras

- **Deploy só no GitHub Actions** (branch `master`, `deploy.yml`): build → push
  ECR → `kubectl apply -k k8s/overlays/prod` → migrations → rollout.
- Branch protegida; merge só via PR (`feature/*` → `homolog` → `master`).
- Segredos nunca no repo: o Secret do k8s é criado pela pipeline a partir do
  Secrets Manager (`/oficina/rds/master`, `/oficina/jwt/secret`) + `LARAVEL_APP_KEY`.
- Qualidade: `pint` (estilo), `phpstan` (nível 3 + baseline), `pest` (testes,
  cobertura mínima de 80% no CI). PHPStan roda com `--memory-limit=1G`. Para
  medir a cobertura de `app/`, o pcov precisa de `pcov.directory=.`.

## Estrutura relevante

- `app/Http/Middleware/ValidateGatewayJwt.php` — valida o JWT da Lambda
  (config `gateway.jwt.enforce`, ligado só em produção).
- `app/Http/Middleware/PrometheusMetrics.php` + `MetricsController` — `/metrics`.
- `app/Http/Middleware/RequestId.php` — correlação `X-Request-Id` nos logs JSON.
- `k8s/base` + `k8s/overlays/prod` — Kustomize (php-fpm + nginx sidecar, HPA,
  Ingress ALB, ServiceMonitor, PrometheusRule, dashboards).
- `src/Saga` — saga orquestrada da OS (Fase 4): `OsSagaOrchestrator`, outbox
  (`saga:relay`), inbox + consumidor SQS (`saga:consume`), `saga:timeouts`.
  Ligada por `SAGA_ENABLED`; ver ADR-005.
- `infra/` — Terraform do serviço (SNS, SQS + DLQ, IRSA), aplicado no `deploy.yml`.
- `docs/` — ADRs, RFCs, diagramas.

## Nota de schema

`customers.document` (não `cpf`); cliente ativo = `deleted_at IS NULL`.
