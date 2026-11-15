locals {
  name_prefix  = "oficina-${var.environment}"
  service      = "execucao"
  service_name = "oficina-execucao"

  common_tags = {
    Project     = "oficina-mecanica"
    Environment = var.environment
    ManagedBy   = "terraform"
    Repo        = "oficina-execucao"
  }

  # Tipos de mensagem que este serviço consome: é o filtro da assinatura do tópico.
  # A lista é a do catálogo (docs/fase-4/CONTRATOS.md §5, no oficina-api). Tipo novo entra
  # aqui, no handler do worker e no catálogo, no mesmo conjunto de PRs.
  consumed_message_types = [
    "execution.request-diagnosis",
    "execution.start-repair",
    "execution.cancel",
  ]

  # ServiceAccount do deploy/ (deploy/base/serviceaccount.yaml).
  service_account = "oficina-execucao"
  namespace       = data.aws_ssm_parameter.namespace.value

  oidc_provider_arn = data.aws_ssm_parameter.oidc_provider_arn.value
  oidc_issuer       = replace(local.oidc_provider_arn, "/^.*oidc-provider\\//", "")
}
