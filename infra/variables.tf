variable "region" {
  description = "Região AWS."
  type        = string
  default     = "us-east-1"
}

variable "project" {
  description = "Prefixo do projeto."
  type        = string
  default     = "oficina"
}

variable "service_name" {
  description = "Nome do microsserviço (fila, role e parâmetros SSM)."
  type        = string
  default     = "os-service"
}

variable "k8s_namespace" {
  description = "Namespace do Deployment no EKS."
  type        = string
  default     = "oficina"
}

variable "k8s_service_account" {
  description = "ServiceAccount dos pods que publicam/consomem (IRSA)."
  type        = string
  default     = "oficina-api"
}

variable "consumed_topics" {
  description = <<-EOT
    Tópicos SNS de outros serviços cujos eventos o OS Service consome. Cada
    serviço cria o próprio tópico; inclua aqui depois que a infra dele for
    aplicada (a assinatura falha se o tópico não existir).
  EOT
  type        = list(string)
  default     = []
}

variable "max_receive_count" {
  description = "Tentativas antes de a mensagem ir para a DLQ."
  type        = number
  default     = 5
}
