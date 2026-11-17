#!/bin/sh
# Cria no LocalStack o que o infra/ cria na AWS para este serviço: a tabela, a fila com DLQ e a
# assinatura do tópico. Roda DENTRO do container do LocalStack (usa awslocal):
#
#   docker exec -i <localstack> sh < scripts/localstack-init.sh
#
# Usado no desenvolvimento local e no e2e do repositório principal. O desenho da tabela e o
# filtro da assinatura têm de acompanhar infra/dynamodb.tf e infra/locals.tf.
set -eu

ENVIRONMENT="${ENVIRONMENT:-local}"
PREFIX="oficina-${ENVIRONMENT}"
SERVICE="execucao"

awslocal dynamodb create-table \
  --table-name "${PREFIX}-${SERVICE}" \
  --billing-mode PAY_PER_REQUEST \
  --attribute-definitions \
    AttributeName=PK,AttributeType=S AttributeName=SK,AttributeType=S \
    AttributeName=GSI1PK,AttributeType=S AttributeName=GSI1SK,AttributeType=S \
  --key-schema AttributeName=PK,KeyType=HASH AttributeName=SK,KeyType=RANGE \
  --global-secondary-indexes \
    'IndexName=GSI1,KeySchema=[{AttributeName=GSI1PK,KeyType=HASH},{AttributeName=GSI1SK,KeyType=RANGE}],Projection={ProjectionType=ALL}' \
  >/dev/null

awslocal dynamodb update-time-to-live --table-name "${PREFIX}-${SERVICE}" \
  --time-to-live-specification "Enabled=true,AttributeName=expires_at" >/dev/null

TOPIC_ARN="$(awslocal sns create-topic --name "${PREFIX}-mensagens" --query TopicArn --output text)"

DLQ_URL="$(awslocal sqs create-queue --queue-name "${PREFIX}-${SERVICE}-dlq" --query QueueUrl --output text)"
DLQ_ARN="$(awslocal sqs get-queue-attributes --queue-url "$DLQ_URL" --attribute-names QueueArn --query Attributes.QueueArn --output text)"
QUEUE_URL="$(awslocal sqs create-queue --queue-name "${PREFIX}-${SERVICE}" \
  --attributes "{\"RedrivePolicy\":\"{\\\"deadLetterTargetArn\\\":\\\"${DLQ_ARN}\\\",\\\"maxReceiveCount\\\":\\\"5\\\"}\"}" \
  --query QueueUrl --output text)"
QUEUE_ARN="$(awslocal sqs get-queue-attributes --queue-url "$QUEUE_URL" --attribute-names QueueArn --query Attributes.QueueArn --output text)"

# Os tipos que este serviço consome: a mesma lista de local.consumed_message_types.
awslocal sns subscribe --topic-arn "$TOPIC_ARN" --protocol sqs --notification-endpoint "$QUEUE_ARN" \
  --attributes '{"RawMessageDelivery":"true","FilterPolicy":"{\"type\":[\"execution.request-diagnosis\",\"execution.start-repair\",\"execution.cancel\"]}"}' \
  >/dev/null

echo "DYNAMODB_TABLE=${PREFIX}-${SERVICE}"
echo "MESSAGING_TOPIC_ARN=${TOPIC_ARN}"
echo "MESSAGING_QUEUE_URL=${QUEUE_URL}"
