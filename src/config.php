<?php

/**
 * Copy to config/escape-exportimport.php
 */
return [
    // Handles of the sections whose entries can be exported
    'exportableSections' => [],

    // Handles of the category groups whose categories can be exported
    'exportableCategoryGroups' => [],

    // The secret that sites send each other when fetching exports. Every site that exports or imports must use the
    // same token, at least 32 characters long. Generate one with `openssl rand -hex 32`
    'token' => '$ESCAPE_EXPORTIMPORT_TOKEN',

    // The hosts that elements can be imported from. The token is only ever sent to these
    'sourceHosts' => array_filter(array_map('trim', explode(',', (string)\craft\helpers\App::env('ESCAPE_EXPORTIMPORT_SOURCE_HOSTS')))),
];
