<?php
// Builds the "Connect to ShowOps" link shown next to a pairing code.
// The code rides in the URL fragment so it never reaches a server log;
// showops.io reads it in the browser and the claim still needs a signed-in owner.

// Where the agent talks to ShowOps unless the config names another API.
const SHOWOPS_DEFAULT_API_BASE_URL = 'https://api.showops.io';

function showops_connect_base_url($config)
{
    $base = isset($config['app_base_url']) ? trim((string)$config['app_base_url']) : '';
    if ($base === '' || !preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]+)?$#', rtrim($base, '/'))) {
        return 'https://showops.io';
    }
    return rtrim($base, '/');
}

function showops_connect_url($config, $code)
{
    $code = strtoupper(trim((string)$code));
    if (!preg_match('/^[A-Z0-9]{2,8}(-[A-Z0-9]{2,8}){1,3}$/', $code)) {
        return '';
    }
    return showops_connect_base_url($config) . '/connect#code=' . $code;
}
