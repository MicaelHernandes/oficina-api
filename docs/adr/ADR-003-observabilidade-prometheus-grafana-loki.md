# ADR-003 — Observabilidade: Prometheus + Grafana + Loki (não Datadog/New Relic)

- **Status:** Aceita · **Data:** 2026-09

## Contexto

O requisito pede: latência das APIs, CPU/memória do K8s, healthchecks/uptime, alertas de falha no processamento de OS, logs JSON com correlação, e dashboards de negócio (volume diário de OS, tempo médio por status, erros nas integrações). Precisamos escolher o stack de observabilidade.

## Decisão

Adotar **kube-prometheus-stack (Prometheus + Alertmanager + Grafana) + Loki/Promtail**, instalado no EKS pelo repo 2. A aplicação expõe **`/metrics`** (promphp, backend Redis) coletado por um **ServiceMonitor**; alertas em **PrometheusRule**; **dashboards** entregues como ConfigMap (`grafana_dashboard: "1"`); **logs JSON** no stdout (Monolog `JsonFormatter`) com `request_id` (Loki/Promtail agrega). Grafana publicado em `grafana.codefive.com.br` (ALB compartilhado).

## Alternativas

| Opção | Contra |
|---|---|
| **Datadog / New Relic** | Custo por host/ingestão incompatível com o crédito Free Tier; vendor lock-in. |
| **Somente CloudWatch** | Dashboards de negócio (volume de OS, tempo por status) exigem PromQL/consultas ricas e visualização flexível; métricas custom + logs no CloudWatch encarecem e limitam. |
| **ELK (Elasticsearch)** | Operação/custo de Elasticsearch elevado; Loki é mais leve e integra nativo com Grafana. |

## Consequências

- (+) Custo previsível (roda no próprio cluster), PromQL para métricas de negócio, um só Grafana para infra + negócio.
- (+) Correlação por `request_id` entre logs (Loki) e traços do gateway (`x-amzn-trace-id`).
- (−) Operamos o stack nós mesmos; armazenamento efêmero (custo) — não durável, documentado. Em produção real usaríamos storage persistente/retenção maior.
