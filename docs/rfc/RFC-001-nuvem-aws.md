# RFC-001 — Escolha da nuvem: AWS (EKS + Lambda + API Gateway), e por que não VPS

- **Status:** Aceita · **Data:** 2026-09

## Contexto

A Fase 3 exige infraestrutura **em nuvem**, provisionada por Terraform, com API Gateway, Function Serverless, banco gerenciado e cluster Kubernetes com escalabilidade. A versão anterior do projeto usava uma VPS (Contabo + k3s), **descartada** por exigência de nuvem.

## Decisão

Padronizar em **AWS, região `us-east-1`**, com:

- **Amazon EKS** para o Kubernetes gerenciado (HPA nativo, add-ons, IRSA).
- **AWS Lambda + API Gateway HTTP API** para a autenticação serverless por CPF.
- **Amazon RDS PostgreSQL** para o banco gerenciado (ver [RFC-002](RFC-002-rds-postgresql.md)).
- **ECR, ACM, S3, Secrets Manager, SSM** como serviços de apoio.
- **Terraform** com state em **S3 + lock DynamoDB**; deploy via **GitHub Actions com OIDC** (sem chaves de longa duração).

Uma **única nuvem** simplifica IAM, rede e Terraform. A rede usa VPC própria (2 AZs) **sem NAT Gateway** (nós em subnets públicas com SG restritivo) para reduzir ~US$32/mês.

## Alternativas

| Opção | Análise |
|---|---|
| **VPS (k3s/Contabo)** | Barata, mas viola o requisito de nuvem gerenciada; operação de cluster/banco/backup por nossa conta. **Rejeitada.** |
| **GKE Autopilot + Lambda/RDS na AWS** | Crédito do GKE cobriria o cluster, mas nuvem híbrida aumenta complexidade de rede/IAM e exigiria RFC própria. Mantido como plano B se o custo do EKS inviabilizar. |
| **AWS 100% (escolhida)** | IAM/rede/Terraform unificados; todos os requisitos atendidos com serviços gerenciados. |
| **ECS Fargate em vez de EKS** | Simples, mas o requisito cita Kubernetes/HPA explicitamente. |

## Consequências

- (+) Um só provedor, Terraform coeso, OIDC sem segredos, HPA nativo.
- (−) EKS tem custo fixo (~US$73/mês) — mitigado destruindo o ambiente após a apresentação e sem NAT Gateway.
- **Custo estimado:** EKS ~US$73 + `t3.medium` ~US$30 + ALB ~US$16 + RDS/Lambda ~Free Tier.
