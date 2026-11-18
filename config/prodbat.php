<?php

return [
    // From the environment; `npx prodbat init` writes both to .env.
    'key' => env('PRODBAT_KEY'),
    'url' => env('PRODBAT_URL'),

    // Queues to report every minute through the scheduler, as "name" or
    // "connection:name". Empty turns queue reports off.
    'queues' => array_filter(explode(',', (string) env('PRODBAT_QUEUES', 'default'))),
];
