<?php

use craft\helpers\App;

return [
    // If we are in 'dev' mode, use the Vite Dev Server
    'useDevServer' => App::env('CRAFT_ENVIRONMENT') === 'dev',

    // Path to your manifest file (Vite 6 puts this in .vite/)
    'manifestPath' => '@webroot/dist/.vite/manifest.json',

    // The public URL to the build assets
    'serverPublic' => App::env('PRIMARY_SITE_URL') . '/dist/',

    // Local DDEV settings
    'devServerInternal' => 'http://localhost:5173',
    'devServerPublic' => 'https://fox-plan.ddev.site:5173',

    // Ping the dev server before deciding to use it — falls back to manifest if Vite isn't running
    'checkDevServer' => true,

    // Suppress the modulepreload polyfill shim (not needed for modern browsers)
    'includeModulePreloadShim' => false,

    // Suppress the onload custom event handler on <script> tags
    'includeScriptOnloadHandler' => false,

    'errorEntry' => '',
    'cacheKeySuffix' => '',
];