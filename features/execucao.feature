# language: pt
Funcionalidade: Execução da ordem de serviço na oficina
  Para que a saga da ordem de serviço avance
  Como serviço de Execução
  Quero receber os comandos do orquestrador, registrar o trabalho do mecânico
  e avisar o serviço de OS de cada resultado

  Contexto:
    Dado que o orquestrador pediu o diagnóstico da OS "os-100"

  Cenário: Fluxo completo, do diagnóstico ao reparo concluído
    Então a tarefa da OS "os-100" está na fila "QUEUED_DIAGNOSIS"
    Quando o mecânico inicia o diagnóstico da OS "os-100"
    E o mecânico conclui o diagnóstico da OS "os-100" com 1 serviço e 2 peças
    Então a tarefa da OS "os-100" fica com o status "DIAGNOSED"
    E o serviço de OS recebe os eventos "diagnosis.started, diagnosis.completed"
    E o evento "diagnosis.completed" leva 2 itens
    Quando o orquestrador manda iniciar o reparo da OS "os-100"
    Então a tarefa da OS "os-100" está na fila "QUEUED_REPAIR"
    Quando o mecânico inicia o reparo da OS "os-100"
    E o mecânico conclui o reparo da OS "os-100"
    Então a tarefa da OS "os-100" fica com o status "REPAIRED"
    E o serviço de OS recebe os eventos "diagnosis.started, diagnosis.completed, repair.started, repair.completed"
    E todo evento publicado leva a saga e a OS de origem

  Cenário: O reparo falha e a saga é avisada para compensar
    Dado que a OS "os-100" já foi diagnosticada e está em reparo
    Quando o mecânico registra que o reparo da OS "os-100" falhou por "peça com defeito"
    Então a tarefa da OS "os-100" fica com o status "REPAIR_FAILED"
    E o último evento publicado é "repair.failed" com o motivo "peça com defeito"

  Cenário: O orçamento é rejeitado e a tarefa é cancelada como compensação
    Dado que o mecânico concluiu o diagnóstico da OS "os-100"
    Quando o orquestrador manda cancelar a execução da OS "os-100" por "orçamento rejeitado"
    Então a tarefa da OS "os-100" fica com o status "CANCELLED"
    E o último evento publicado é "execution.cancelled"
    Quando o mecânico tenta iniciar o reparo da OS "os-100"
    Então a ação é recusada

  Cenário: A mesma mensagem entregue duas vezes não repete o efeito
    Quando o orquestrador repete o pedido de diagnóstico da OS "os-100"
    Então existe uma única tarefa na fila "QUEUED_DIAGNOSIS"
    E nenhuma mensagem fica parada na fila do serviço

  Cenário: A compensação repetida é confirmada de novo, sem mudar a tarefa
    Quando o orquestrador manda cancelar a execução da OS "os-100" por "prazo vencido"
    E o orquestrador manda cancelar a execução da OS "os-100" por "prazo vencido"
    Então a tarefa da OS "os-100" fica com o status "CANCELLED"
    E o serviço de OS recebe os eventos "execution.cancelled, execution.cancelled"
