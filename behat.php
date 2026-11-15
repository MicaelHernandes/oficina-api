<?php

// BDD (Behat): fluxo completo da saga da OS em Gherkin, em features/.
// Roda contra o app Laravel com PostgreSQL (mesmas variáveis DB_* da suíte Pest).

use Behat\Config\Config;
use Behat\Config\Profile;
use Behat\Config\Suite;
use Tests\Behat\SagaContext;

return (new Config)
    ->withProfile(
        (new Profile('default'))->withSuite(
            (new Suite('saga'))
                ->withPaths('%paths.base%/features')
                ->withContexts(SagaContext::class)
        )
    );
