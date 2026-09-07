# ADR-004 — Ambiente único de produção; homologação como etapa de CI

- **Status:** Aceita · **Data:** 2026-09

## Contexto

O desafio pede branch principal protegida e deploy automático. Manter múltiplos ambientes (homolog + prod) na AWS dobraria custo (outro EKS/RDS/ALB) — inviável no crédito de Free Tier.

## Decisão

Existe **um único ambiente implantado: produção**, publicado a partir de **`master`**. A branch **`homolog`** existe apenas no GitHub como etapa do fluxo de PR: roda o **CI completo (lint, testes, build, terraform plan)**, mas **não faz deploy**. Não há namespace, banco, stage ou subdomínio de homologação.

Fluxo: `feature/*` → PR para `homolog` (CI verde) → PR de `homolog` para `master` (CI verde + 1 aprovação) → deploy automático em produção. Branch protection em ambas (sem push direto, sem force-push, status checks obrigatórios).

## Alternativas

- **Homolog + Prod completos:** ideal para validação realista, mas ~2× o custo (EKS ~US$73/mês cada). Rejeitado por custo.
- **Preview environments efêmeros por PR:** ótimos, porém complexos de automatizar com EKS/RDS no tempo do desafio.

## Consequências

- (+) Custo mínimo; pipeline simples; branch principal protegida com deploy automático.
- (+) Qualidade ainda garantida por CI obrigatório em `homolog`.
- (−) Sem ambiente de staging real: mitigado por testes automatizados e `terraform plan` no PR.
