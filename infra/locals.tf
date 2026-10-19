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

  consumed_topic_arns = [
    for t in var.consumed_topics : "arn:aws:sns:${var.region}:${data.aws_caller_identity.current.account_id}:${t}"
  ]
}
