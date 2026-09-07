# ADR-002 — Escalabilidade: HPA por CPU e memória

- **Status:** Aceita · **Data:** 2026-09

## Contexto

O requisito exige escalabilidade automática do cluster. A carga da API é predominantemente CPU/memória-bound (PHP-FPM). Precisamos escalar as réplicas do backend conforme a demanda, dentro do node group (`t3.medium`, 1–3 nós).

## Decisão

Usar o **HorizontalPodAutoscaler (autoscaling/v2)** no deployment `oficina-api`, com **duas métricas de recurso**: CPU (target 70% de utilização) e memória (target 80%), `minReplicas: 2`, `maxReplicas: 6`. O **metrics-server** (instalado pelo repo 2) fornece as métricas. O node group do EKS absorve a pressão de pods até o teto de 3 nós.

## Alternativas

- **HPA só por CPU:** simples, mas ignora pressão de memória do PHP; escolhemos CPU **e** memória.
- **KEDA (event-driven):** útil para escalar por tamanho de fila; overkill para o backend HTTP. As filas aqui têm 1 worker fixo.
- **Cluster Autoscaler/Karpenter:** adiaria o teto de nós; decisão do projeto foi manter node group fixo (custo/simplicidade) — o HPA opera dentro desse teto.

## Consequências

- (+) Escala automática e nativa, sem componentes extras além do metrics-server.
- (+) Duas dimensões evitam saturação de memória do FPM.
- (−) Teto de nós fixo (3) limita o pico; aceitável para a apresentação, documentado.
