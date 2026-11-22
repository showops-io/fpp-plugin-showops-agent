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

// The plugin page's own scripts call showops.php with nopage=1. FPP still
// prints its own header bits on that path, so the JSON rides between markers
// the page script looks for. JSON_HEX_* keeps markup characters out of it.
const SHOWOPS_JSON_START = '@@SHOWOPS_JSON@@';
const SHOWOPS_JSON_END = '@@SHOWOPS_JSON_END@@';

function showops_json_envelope($data)
{
    $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;
    return SHOWOPS_JSON_START . json_encode($data, $flags) . SHOWOPS_JSON_END;
}

// What the page's status check is allowed to see: the step name only.
function showops_public_step($step)
{
    $allowed = array('install', 'pair', 'claim', 'paired');
    return in_array($step, $allowed, true) ? $step : 'pair';
}
