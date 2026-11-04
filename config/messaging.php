<?php

// Mensageria entre microsserviços (Fase 4) e Saga orquestrada da OS.
//
// O OS Service publica comandos/eventos no seu tópico SNS (via outbox) e
// consome os eventos do Billing e do Execution Service na sua fila SQS.
// Em local/testes o driver `log` só registra as mensagens publicadas.

return [
    // Liga a saga: a abertura da OS e o orçamento passam a disparar comandos
    // para o Billing/Execution, e as transições de aprovação, pagamento e
    // execução deixam de ser feitas pelas rotas locais.
    'saga_enabled' => (bool) env('SAGA_ENABLED', false),

    // Identifica o remetente no envelope das mensagens.
    'source' => env('MESSAGING_SOURCE', 'oficina-os-service'),

    // sns | log
    'publisher' => env('MESSAGING_PUBLISHER', 'log'),

    // sqs | null
    'consumer' => env('MESSAGING_CONSUMER', 'null'),

    'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),

    'sns' => [
        'topic_arn' => env('MESSAGING_TOPIC_ARN'),
    ],

    'sqs' => [
        'queue_url' => env('MESSAGING_QUEUE_URL'),
        'wait_seconds' => (int) env('MESSAGING_WAIT_SECONDS', 20),
        'max_messages' => 10,
    ],

    // Prazos dos passos que dependem de outro serviço. Vencido o prazo, o
    // `saga:timeouts` dispara a compensação.
    'timeouts' => [
        'payment_minutes' => (int) env('SAGA_PAYMENT_TIMEOUT_MINUTES', 2880),
        'execution_ack_minutes' => (int) env('SAGA_EXECUTION_ACK_TIMEOUT_MINUTES', 1440),
    ],
];
