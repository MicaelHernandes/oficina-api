# language: pt
Funcionalidade: Saga da ordem de serviço entre OS, Billing e Execution
  Para atender o cliente com consistência entre os três microsserviços
  Como oficina
  Eu quero que a abertura, o orçamento, o pagamento e a execução da OS sejam
  coordenados pela saga, com rollback seguro quando alguma etapa falhar

  Contexto:
    Dado que a saga está ligada
    E o cliente "Ana Souza" com o veículo "ABC1D23" está cadastrado
    E o catálogo tem o serviço "Revisão" por R$ 200,00
    E o catálogo tem a peça "Pastilha de freio" por R$ 50,00 com 5 unidades em estoque
    E o atendente abre uma OS com a reclamação "Freio rangendo"
    E o mecânico envia a OS para diagnóstico
    E o mecânico gera o orçamento com 1 "Revisão" e 2 "Pastilha de freio"

  Cenário: OS percorre todo o fluxo até a entrega do veículo
    Então o Billing deve receber o comando "billing.budget.generate" com total de R$ 300,00
    E a saga deve estar em "awaiting_approval"
    Quando o Billing publica "billing.budget.approved"
    Então a OS deve estar "approved"
    E o Billing deve receber o comando "billing.payment.request" com total de R$ 300,00
    Quando o Billing publica "billing.payment.confirmed"
    Então o estoque de "Pastilha de freio" deve ser 3
    E o Execution deve receber o comando "execution.job.enqueue"
    Quando o Execution publica "execution.job.started"
    Então a OS deve estar "in_execution"
    Quando o Execution publica "execution.job.finished"
    Então a OS deve estar "execution_finished"
    E a saga deve estar em "completed"
    Quando o atendente entrega o veículo
    Então a OS deve estar "delivered_and_finalized"
    E todas as mensagens da saga devem carregar o mesmo correlation id

  Cenário: Falta de estoque depois do pagamento dispara o estorno
    Dado que o Billing publica "billing.budget.approved"
    E outra OS consome o estoque de "Pastilha de freio", restando 1 unidade
    Quando o Billing publica "billing.payment.confirmed"
    Então a OS deve estar "rejected"
    E a saga deve estar em "compensating"
    E o Billing deve receber o comando "billing.payment.refund"
    E o estoque de "Pastilha de freio" deve ser 1
    Quando o Billing publica "billing.payment.refunded"
    Então a saga deve estar em "compensated"
    E o motivo da falha da saga deve conter "estoque insuficiente"

  Cenário: Falha na execução devolve as peças, estorna e cancela a OS
    Dado que o Billing publica "billing.budget.approved"
    E o Billing publica "billing.payment.confirmed"
    E o Execution publica "execution.job.started"
    Quando o Execution publica "execution.job.failed" com o motivo "Elevador hidráulico quebrado"
    Então a OS deve estar "rejected"
    E o estoque de "Pastilha de freio" deve ser 5
    E o Billing deve receber o comando "billing.payment.refund"
    Quando o Billing publica "billing.payment.refunded"
    Então a saga deve estar em "compensated"

  Cenário: Pagamento recusado cancela o orçamento e a OS
    Dado que o Billing publica "billing.budget.approved"
    Quando o Billing publica "billing.payment.failed" com o motivo "cartão recusado"
    Então a OS deve estar "rejected"
    E a saga deve estar em "compensated"
    E o Billing deve receber o comando "billing.budget.cancel"
    E o estoque de "Pastilha de freio" deve ser 5

  Cenário: Cliente pede nova proposta e depois desiste da OS
    Quando o Billing publica "billing.budget.rejected"
    Então a OS deve estar "in_renegotiation"
    E a saga deve estar em "diagnosis"
    Quando o mecânico gera o orçamento com 1 "Revisão" e 1 "Pastilha de freio"
    Então o Billing deve receber o comando "billing.budget.generate" com total de R$ 250,00
    Quando o Billing publica "billing.budget.rejected" de forma definitiva
    Então a OS deve estar "rejected"
    E a saga deve estar em "cancelled"
