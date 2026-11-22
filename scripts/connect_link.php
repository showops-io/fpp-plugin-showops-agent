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

// Where this player forwards the plugin page's sign-in. HTTPS only; plain
// http only for a local test API on this machine.
function showops_api_base_for_browser($config)
{
    $base = isset($config['api_base_url']) ? rtrim(trim((string)$config['api_base_url']), '/') : '';
    if ($base !== '' && preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]+)?$#', $base)) {
        return $base;
    }
    if ($base !== '' && preg_match('#^http://(127\.0\.0\.1|localhost)(:[0-9]+)?$#', $base)) {
        return $base;
    }
    return SHOWOPS_DEFAULT_API_BASE_URL;
}

// The plugin page cannot call the ShowOps API itself (FPP's page policy only
// lets it talk to this player), so the player passes the sign-in along.
// Only these fields go on, the pairing code always comes from this player's
// own config (never from the browser), and nothing here is saved or logged.
const SHOWOPS_SIGNIN_MAX_BYTES = 8192;

function showops_signin_forward_body($raw, $pairingCode)
{
    $pairingCode = trim((string)$pairingCode);
    if ($pairingCode === '' || !is_string($raw) || $raw === '' || strlen($raw) > SHOWOPS_SIGNIN_MAX_BYTES) {
        return null;
    }
    $in = json_decode($raw, true);
    if (!is_array($in)) {
        return null;
    }
    $out = array('pairing_code' => $pairingCode);
    foreach (array('email', 'password', 'ticket', 'mfa_code', 'org_id', 'location_id') as $key) {
        if (isset($in[$key]) && is_string($in[$key]) && $in[$key] !== '') {
            $out[$key] = $in[$key];
        }
    }
    foreach (array('start_push', 'check_push') as $key) {
        if (isset($in[$key]) && $in[$key] === true) {
            $out[$key] = true;
        }
    }
    return $out;
}

// What goes back to the page: the API's own reply, minus anything that is
// not part of the sign-in answer.
function showops_signin_public_reply($data, $httpStatus)
{
    $reply = array('http_status' => intval($httpStatus));
    if (!is_array($data)) {
        $reply['error'] = 'bad_reply';
        $reply['user_message'] = 'ShowOps sent an answer this player could not read. Try again.';
        return $reply;
    }
    foreach (array('status', 'error', 'user_message', 'ticket', 'methods', 'push_sent',
        'device_id', 'account_name', 'show_name', 'retry_after') as $key) {
        if (array_key_exists($key, $data) && (is_scalar($data[$key]) || is_array($data[$key]))) {
            $reply[$key] = $data[$key];
        }
    }
    if (isset($data['accounts']) && is_array($data['accounts'])) {
        $reply['accounts'] = $data['accounts'];
    }
    return $reply;
}
