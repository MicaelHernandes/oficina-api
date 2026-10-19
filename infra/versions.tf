# ---------------------------------------------------------------------------
# Infra própria do OS Service (Fase 4): mensageria e IRSA.
# Apply roda exclusivamente no GitHub Actions (deploy.yml, branch master).
# ---------------------------------------------------------------------------
terraform {
  required_version = ">= 1.5.0"

  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.60"
    }
  }

  backend "s3" {
    bucket         = "oficina-tfstate-909314263457"
    key            = "oficina-api/terraform.tfstate"
    region         = "us-east-1"
    dynamodb_table = "oficina-tflock"
    encrypt        = true
  }
}
