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
- Qualidade: `pint` (estilo), `phpstan` (nível 3 + baseline), `pest` (testes).
  PHPStan roda com `--memory-limit=1G`.

## Estrutura relevante

- `app/Http/Middleware/ValidateGatewayJwt.php` — valida o JWT da Lambda
  (config `gateway.jwt.enforce`, ligado só em produção).
- `app/Http/Middleware/PrometheusMetrics.php` + `MetricsController` — `/metrics`.
- `app/Http/Middleware/RequestId.php` — correlação `X-Request-Id` nos logs JSON.
- `k8s/base` + `k8s/overlays/prod` — Kustomize (php-fpm + nginx sidecar, HPA,
  Ingress ALB, ServiceMonitor, PrometheusRule, dashboards).
- `docs/` — ADRs, RFCs, diagramas.

## Nota de schema

`customers.document` (não `cpf`); cliente ativo = `deleted_at IS NULL`.
