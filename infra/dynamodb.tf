# Tabela única do serviço (docs/fase-4/CONTRATOS.md §7, no oficina-api):
#   PK = TASK#<os_id>
#   SK = META | EVENT#<timestamp>#<id> | OUTBOX#<id> | INBOX#<id>
#   GSI1 (fila da oficina): GSI1PK = STATUS#<status>, GSI1SK = <created_at>
# Sob demanda: sem custo com o ambiente parado.

resource "aws_dynamodb_table" "main" {
  name         = "${local.name_prefix}-${local.service}"
  billing_mode = "PAY_PER_REQUEST"
  hash_key     = "PK"
  range_key    = "SK"

  attribute {
    name = "PK"
    type = "S"
  }

  attribute {
    name = "SK"
    type = "S"
  }

  attribute {
    name = "GSI1PK"
    type = "S"
  }

  attribute {
    name = "GSI1SK"
    type = "S"
  }

  global_secondary_index {
    name            = "GSI1"
    hash_key        = "GSI1PK"
    range_key       = "GSI1SK"
    projection_type = "ALL"
  }

  point_in_time_recovery {
    enabled = var.environment == "prod"
  }

  server_side_encryption {
    enabled = true
  }

  tags = { Name = "${local.name_prefix}-${local.service}" }
}
