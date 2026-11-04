<?php

namespace App\Http\Controllers;

use Domain\Saga\Domain\Enums\SagaState;
use Domain\Workshop\Domain\Enums\OsStatus;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

/**
 * Endpoint /metrics (formato texto do Prometheus). Além das métricas de
 * requisição coletadas pelo middleware, expõe um gauge com o volume de OS
 * por status (calculado no momento do scrape) para os dashboards de negócio,
 * e o estado das sagas e do outbox para acompanhar o fluxo distribuído.
 */
class MetricsController extends Controller
{
    public function __construct(private readonly CollectorRegistry $registry) {}

    public function __invoke(): Response
    {
        $this->collectOrderServiceGauges();
        $this->collectSagaGauges();

        $renderer = new RenderTextFormat;
        $body = $renderer->render($this->registry->getMetricFamilySamples());

        return response($body, 200, ['Content-Type' => RenderTextFormat::MIME_TYPE]);
    }

    private function collectOrderServiceGauges(): void
    {
        $ns = config('metrics.namespace', 'oficina');

        try {
            $gauge = $this->registry->getOrRegisterGauge(
                $ns,
                'order_services',
                'Quantidade de ordens de serviço por status',
                ['status'],
            );

            $totals = DB::table('order_services')
                ->select('status', DB::raw('count(*) as total'))
                ->whereNull('deleted_at')
                ->groupBy('status')
                ->pluck('total', 'status');

            // Publica TODOS os status, inclusive os que não têm OS agora. O
            // registry persiste no Redis: um status que caiu para zero e não
            // fosse reescrito ficaria congelado no último valor.
            foreach (OsStatus::cases() as $status) {
                $gauge->set((float) ($totals[$status->value] ?? 0), [$status->value]);
            }

            // Tempo médio de execução (finished_at - started_at) em segundos.
            $avg = DB::table('order_services')
                ->whereNotNull('started_at')
                ->whereNotNull('finished_at')
                ->selectRaw('avg(extract(epoch from (finished_at - started_at))) as avg_seconds')
                ->value('avg_seconds');

            if ($avg !== null) {
                $this->registry
                    ->getOrRegisterGauge($ns, 'order_service_avg_execution_seconds', 'Tempo médio de execução das OS finalizadas (s)', [])
                    ->set((float) $avg, []);
            }
        } catch (\Throwable $e) {
            // Sem banco disponível: expõe só as métricas de requisição.
        }
    }

    private function collectSagaGauges(): void
    {
        $ns = config('metrics.namespace', 'oficina');

        try {
            $sagas = $this->registry->getOrRegisterGauge(
                $ns,
                'sagas',
                'Quantidade de sagas de OS por estado',
                ['state'],
            );

            $totals = DB::table('saga_instances')
                ->select('state', DB::raw('count(*) as total'))
                ->groupBy('state')
                ->pluck('total', 'state');

            foreach (SagaState::cases() as $state) {
                $sagas->set((float) ($totals[$state->value] ?? 0), [$state->value]);
            }

            $this->registry
                ->getOrRegisterGauge($ns, 'outbox_pending_messages', 'Mensagens do outbox ainda não publicadas', [])
                ->set((float) DB::table('outbox_messages')->whereNull('published_at')->count(), []);
        } catch (\Throwable $e) {
            // Sem banco disponível: segue sem as métricas da saga.
        }
    }
}
