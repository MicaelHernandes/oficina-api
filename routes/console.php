<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Compensa sagas cujo pagamento ou início de execução venceu o prazo.
Schedule::command('saga:timeouts')->everyMinute()->withoutOverlapping();
