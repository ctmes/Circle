<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| The browser calls the API from a different origin than it loaded the page
| from (:4321 -> :8000), so every request carrying an Authorization header is
| preceded by an OPTIONS preflight.
|
| The framework default is `max_age => 0`, which forbids caching that
| preflight — so each of the ten Circle views paid two round trips per fetch
| instead of one, and every one of them booted the framework. Ten minutes is
| long enough to cover a working session and short enough that a change to the
| allowed methods or headers is picked up without anyone clearing a cache.
|
*/

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
     * Who may call this API from a browser.
     *
     * `*` is correct for local development, where the front end moves between
     * localhost ports, and wrong the moment this is reachable from the
     * internet: it invites any page anywhere to make credentialed calls on
     * behalf of somebody who happens to be signed in.
     *
     * Set CORS_ALLOWED_ORIGINS to the front end's real origin — comma-separated
     * if there is more than one — and this narrows to exactly that. The default
     * stays permissive so nothing about running it locally changes.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', '*')),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 600,

    'supports_credentials' => false,

];
