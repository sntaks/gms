<?php

/*
|--------------------------------------------------------------------------
| Kadi API prediction markets, as GMS shows them
|--------------------------------------------------------------------------
|
| Labels for the Kadi API's prediction markets (api config/predict.php and
| App\Models\PredictMarket). The API owns the values; keep these in step with
| it. Terms left blank on a new market take the API's own defaults.
|
*/

return [

    'categories' => [
        'sport' => 'Sport',
        'entertainment' => 'Entertainment',
        'politics' => 'Politics',
    ],

    'countries' => [
        'KE' => 'Kenya',
    ],

    'statuses' => [
        'draft' => 'Draft',
        'open' => 'Open',
        'suspended' => 'Suspended',
        'closed' => 'Closed',
        'resolved' => 'Resolved',
        'settled' => 'Settled',
        'voided' => 'Voided',
        'rejected' => 'Rejected',
    ],

    'engines' => [
        'pool' => 'Pool',
        'lmsr' => 'LMSR',
        'reference' => 'Fixed odds',
    ],

    'sources' => [
        'custom' => 'Our own',
        'polymarket' => 'Polymarket import',
    ],

    // Drafts sent per bulk call (the API's BulkPredictMarketsRequest::MAX).
    'bulk_limit' => 500,

    // Bulk calls one "publish or reject all matching" may make (each one is a write, 30 a minute).
    'bulk_max_calls' => 10,

];
