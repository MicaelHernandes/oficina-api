locals {
  tags = {
    Project   = var.project
    ManagedBy = "terraform"
    Repo      = "oficina-api"
  }

  topic_name = "${var.project}-os-events"
  queue_name = "${var.project}-${var.service_name}"

  # arn:aws:iam::<conta>:oidc-provider/<issuer sem https://>
  oidc_provider_arn = data.aws_ssm_parameter.eks_oidc_provider_arn.value
  oidc_issuer       = replace(local.oidc_provider_arn, "/^arn:aws:iam::[0-9]+:oidc-provider\\//", "")

  # Tópicos de eventos dos outros serviços (oficina-billing-events,
  # oficina-execution-events...). Cada serviço cria o próprio tópico e a
  # assinatura dele nesta fila; aqui só se autoriza o padrão de nome, então
  # não há ordem de deploy entre os repositórios.
  event_topics_pattern = "arn:aws:sns:${var.region}:${data.aws_caller_identity.current.account_id}:${var.project}-*-events"
}
