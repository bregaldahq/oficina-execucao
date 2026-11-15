# Parâmetros lidos pelo deploy.yml deste repo (docs/fase-4/CONTRATOS.md §2, no oficina-api).
# Nenhum outro serviço os lê.

resource "aws_ssm_parameter" "queue_url" {
  name  = "/oficina/${var.environment}/${local.service}/queue_url"
  type  = "String"
  value = aws_sqs_queue.main.url
}

resource "aws_ssm_parameter" "queue_arn" {
  name  = "/oficina/${var.environment}/${local.service}/queue_arn"
  type  = "String"
  value = aws_sqs_queue.main.arn
}

resource "aws_ssm_parameter" "irsa_role_arn" {
  name  = "/oficina/${var.environment}/${local.service}/irsa_role_arn"
  type  = "String"
  value = aws_iam_role.service.arn
}

resource "aws_ssm_parameter" "ecr_repository_url" {
  name  = "/oficina/${var.environment}/${local.service}/ecr_repository_url"
  type  = "String"
  value = local.ecr_repository_url
}

resource "aws_ssm_parameter" "table_name" {
  name  = "/oficina/${var.environment}/${local.service}/table_name"
  type  = "String"
  value = aws_dynamodb_table.main.name
}
