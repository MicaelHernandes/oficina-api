# ---------------------------------------------------------------------------
# Mensageria do OS Service (orquestrador da saga).
#
# - Tópico SNS `oficina-os-events`: comandos para o Billing/Execution e eventos
#   da OS, publicados pelo outbox (`saga:relay`). Cada serviço assina com
#   filter policy pelo atributo `type` (ex.: prefixo `billing.`).
# - Fila SQS `oficina-os-service` + DLQ: recebe os eventos dos tópicos do
#   Billing e do Execution (`saga:consume`). Raw delivery: o corpo é o
#   envelope JSON.
# - Role IRSA do ServiceAccount `oficina-api`: publica no tópico e consome a
#   fila, sem chaves estáticas.
# ---------------------------------------------------------------------------

resource "aws_sns_topic" "events" {
  name = local.topic_name
  # Criptografia em repouso com a chave gerenciada da AWS (sem custo de CMK).
  kms_master_key_id = "alias/aws/sns"
}

resource "aws_sqs_queue" "dlq" {
  name                      = "${local.queue_name}-dlq"
  message_retention_seconds = 1209600 # 14 dias para investigar/reprocessar
}

resource "aws_sqs_queue" "inbox" {
  name                       = local.queue_name
  visibility_timeout_seconds = 60
  receive_wait_time_seconds  = 20
  message_retention_seconds  = 345600 # 4 dias

  redrive_policy = jsonencode({
    deadLetterTargetArn = aws_sqs_queue.dlq.arn
    maxReceiveCount     = var.max_receive_count
  })
}

resource "aws_sqs_queue_redrive_allow_policy" "dlq" {
  queue_url = aws_sqs_queue.dlq.id

  redrive_allow_policy = jsonencode({
    redrivePermission = "byQueue"
    sourceQueueArns   = [aws_sqs_queue.inbox.arn]
  })
}

# Só os tópicos assinados podem entregar na fila.
data "aws_iam_policy_document" "inbox" {
  count = length(local.consumed_topic_arns) > 0 ? 1 : 0

  statement {
    effect    = "Allow"
    actions   = ["sqs:SendMessage"]
    resources = [aws_sqs_queue.inbox.arn]

    principals {
      type        = "Service"
      identifiers = ["sns.amazonaws.com"]
    }

    condition {
      test     = "ArnEquals"
      variable = "aws:SourceArn"
      values   = local.consumed_topic_arns
    }
  }
}

resource "aws_sqs_queue_policy" "inbox" {
  count = length(local.consumed_topic_arns) > 0 ? 1 : 0

  queue_url = aws_sqs_queue.inbox.id
  policy    = data.aws_iam_policy_document.inbox[0].json
}

resource "aws_sns_topic_subscription" "consumed" {
  for_each = toset(local.consumed_topic_arns)

  topic_arn            = each.value
  protocol             = "sqs"
  endpoint             = aws_sqs_queue.inbox.arn
  raw_message_delivery = true
}

# --- IRSA -----------------------------------------------------------------
data "aws_iam_policy_document" "irsa_trust" {
  statement {
    effect  = "Allow"
    actions = ["sts:AssumeRoleWithWebIdentity"]

    principals {
      type        = "Federated"
      identifiers = [local.oidc_provider_arn]
    }

    condition {
      test     = "StringEquals"
      variable = "${local.oidc_issuer}:sub"
      values   = ["system:serviceaccount:${var.k8s_namespace}:${var.k8s_service_account}"]
    }

    condition {
      test     = "StringEquals"
      variable = "${local.oidc_issuer}:aud"
      values   = ["sts.amazonaws.com"]
    }
  }
}

resource "aws_iam_role" "irsa" {
  name               = "${var.project}-${var.service_name}-irsa"
  assume_role_policy = data.aws_iam_policy_document.irsa_trust.json
}

data "aws_iam_policy_document" "irsa" {
  statement {
    effect    = "Allow"
    actions   = ["sns:Publish"]
    resources = [aws_sns_topic.events.arn]
  }

  statement {
    effect = "Allow"
    actions = [
      "sqs:ReceiveMessage",
      "sqs:DeleteMessage",
      "sqs:ChangeMessageVisibility",
      "sqs:GetQueueAttributes",
    ]
    resources = [aws_sqs_queue.inbox.arn]
  }
}

resource "aws_iam_role_policy" "irsa" {
  name   = "${var.project}-${var.service_name}-messaging"
  role   = aws_iam_role.irsa.id
  policy = data.aws_iam_policy_document.irsa.json
}
