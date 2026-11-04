data "aws_caller_identity" "current" {}

# OIDC provider do EKS (repo oficina-k8s-infra) para o IRSA.
data "aws_ssm_parameter" "eks_oidc_provider_arn" {
  name = "/oficina/eks/oidc_provider_arn"
}
