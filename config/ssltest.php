<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SSL pinning test harness
    |--------------------------------------------------------------------------
    |
    | Server-side companion to the Android certificate-pinning rollout. These
    | routes rewrite the TLS identity Traefik serves for the test host, so they
    | are the most dangerous routes in the app. They are HARD-GATED behind an
    | explicit flag (not APP_ENV) so they can never be armed on a real prod box
    | by accident. Leave `enabled` false everywhere except the dedicated test
    | VPS. See ssl-test/README.md for the full architecture.
    |
    */

    // Master switch. Routes 404 unless this is explicitly true.
    'enabled' => env('SSL_TEST_ENABLED', false),

    // Host whose live chain the inspector probes and whose cert sets we swap.
    'host' => env('SSL_TEST_HOST', 'wallet.birchlabs.tech'),

    // Privileged mutator, executed inside this (app) container. It writes the
    // bind-mounted /opt/ssl-test tree; Traefik's file provider hot-reloads.
    'script' => env('SSL_TEST_SCRIPT', '/opt/ssl-test/ssl-apply.sh'),

];
