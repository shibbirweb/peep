<?php

// Peep configuration for Laravel. Publish with:
//     php artisan vendor:publish --tag=peep-config

return [

    // Null: enabled everywhere except in production. Set PEEP_ENABLED=true or false to force it.
    'enabled' => env('PEEP_ENABLED'),

    // Where the Peep viewer listens.
    'host' => env('PEEP_HOST', '127.0.0.1'),
    'port' => env('PEEP_PORT', 23517),

    // Only for a viewer that listens beyond this machine: the token from its settings page.
    'token' => env('PEEP_TOKEN'),

    // Shown in the viewer to keep apps apart. Null: config('app.name').
    'project' => env('PEEP_PROJECT'),

    // For apps in Docker or a VM: path inside the container, and the same path on your machine,
    // so "open in editor" links work.
    'remote_path' => env('PEEP_REMOTE_PATH'),
    'local_path' => env('PEEP_LOCAL_PATH'),

    // The log folder the viewer's Logs tab shows (Laravel's own .log files, read by the viewer).
    // Null: storage/logs. False: show none.
    'log_path' => env('PEEP_LOG_PATH'),

    // "peep" never calls code on the values it shows. "var-dumper" uses Symfony VarDumper's
    // casters (richer for Doctrine, Carbon, DOM, ...), which may call methods on values.
    'serializer' => env('PEEP_SERIALIZER', 'peep'),

    // Queries. "queries" shows every query (filtered by "query_types"); slow and duplicate
    // queries can be shown on their own. The viewer's query switch overrides these while it runs.
    'send_queries' => env('PEEP_QUERIES', false),
    'query_types' => ['select', 'insert', 'update', 'delete', 'other'],
    'send_slow_queries' => env('PEEP_SLOW_QUERIES', false),
    'slow_query_ms' => env('PEEP_SLOW_QUERY_MS', 500),
    'send_duplicate_queries' => env('PEEP_DUPLICATE_QUERIES', false),

    // Other framework activity.
    'send_events' => env('PEEP_EVENTS', false),
    'send_jobs' => env('PEEP_JOBS', false),
    'send_cache' => env('PEEP_CACHE', false),
    'send_http_client' => env('PEEP_HTTP_CLIENT', false),
    'send_views' => env('PEEP_VIEWS', false),
    'send_requests' => env('PEEP_REQUESTS', false),
    'send_logs' => env('PEEP_LOGS', true),
    'send_exceptions' => env('PEEP_EXCEPTIONS', true),
    'send_mail' => env('PEEP_MAIL', true),

    // SMS notifications (Vonage / Nexmo, Twilio channel) go to the viewer's SMS inbox.
    // block_real_sms also skips the real send (never in production), so no phone gets them.
    'send_sms' => env('PEEP_SMS', true),
    'block_real_sms' => env('PEEP_BLOCK_SMS', false),
    'send_dumps' => env('PEEP_DUMPS', true),
    'send_deprecations' => env('PEEP_DEPRECATIONS', false),

    // dump() still prints where it normally does, in addition to Peep.
    'dumps_passthrough' => true,

    // Events not shown by the event watcher. "*" matches anything.
    'ignore_events' => [
        'Illuminate\\Log\\Events\\*',
        'Illuminate\\Database\\Events\\*',
        'Illuminate\\Cache\\Events\\*',
        'Illuminate\\Console\\Events\\*',
        'Illuminate\\Foundation\\Events\\*',
        'Illuminate\\Routing\\Events\\*',
        'Illuminate\\Http\\Client\\Events\\*',
        'Illuminate\\Queue\\Events\\*',
        'Illuminate\\Auth\\Events\\Attempting',
        'eloquent.*',
        'bootstrapped: *',
        'bootstrapping: *',
        'creating: *',
        'composing: *',
    ],

];
