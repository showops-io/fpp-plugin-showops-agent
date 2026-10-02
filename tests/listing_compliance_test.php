<?php

$root = dirname(__DIR__);
require_once $root . '/scripts/post_origin.php';
require_once $root . '/api.php';

$failed = 0;

function expect($label, $actual, $want)
{
    global $failed;
    if ($actual !== $want) {
        fwrite(STDERR, "FAIL $label: got " . var_export($actual, true) . " want " . var_export($want, true) . "\n");
        $failed++;
        return;
    }
    echo "ok $label\n";
}

expect('matching origin', showops_request_from_player('fpp.local', 'http://fpp.local/plugin.php', '', false), true);
expect('mismatched origin', showops_request_from_player('fpp.local', 'http://other.example/', '', false), false);
expect('referer fallback', showops_request_from_player('fpp.local', '', 'http://fpp.local/plugin.php?page=showops', false), true);
expect('missing both', showops_request_from_player('fpp.local', '', '', false), false);
expect('missing host', showops_request_from_player('', 'http://fpp.local/', '', false), false);
expect('explicit player port', showops_request_from_player('fpp.local:80', 'http://fpp.local/', '', false), true);
expect('port mismatch', showops_request_from_player('fpp.local', 'http://fpp.local:8080/', '', false), false);
expect('https default port', showops_request_from_player('fpp.local', 'https://fpp.local/plugin.php', '', true), true);
expect('https rejects http origin', showops_request_from_player('fpp.local', 'http://fpp.local/', '', true), false);
expect('origin wins over referer', showops_request_from_player('fpp.local', 'http://fpp.local/', 'http://other.example/', false), true);
expect('host case', showops_request_from_player('FPP.Local', 'http://fpp.local/', '', false), true);

$committed = trim(file_get_contents($root . '/AGENT_VERSION'));
expect('committed version', showopsAgentResolveLatestVersion(), $committed);
expect('older binary needs update', showopsAgentCompareVersions('v0.0.1', $committed) < 0, true);
expect('same version', showopsAgentCompareVersions($committed, $committed), 0);
expect('newer binary', showopsAgentCompareVersions('v99.0.0', $committed) > 0, true);

$forbidden = array(
    '/v1/agent/releases/latest',
    'api.github.com',
    'raw.githubusercontent.com',
);
$scan = array(
    $root . '/api.php',
    $root . '/scripts/fpp_install.sh',
);
foreach (glob($root . '/scripts/*.sh') as $script) {
    $scan[] = $script;
}
$scan = array_values(array_unique($scan));
foreach ($scan as $path) {
    $text = file_get_contents($path);
    foreach ($forbidden as $needle) {
        if (strpos($text, $needle) !== false) {
            fwrite(STDERR, "FAIL " . basename($path) . " still contains $needle\n");
            $failed++;
        }
    }
}
if (!is_file($root . '/scripts/fpp_update_check.sh')) {
    echo "ok update check script removed\n";
} else {
    fwrite(STDERR, "FAIL scripts/fpp_update_check.sh still exists\n");
    $failed++;
}

if (!function_exists('showopsAgentHttpGet')) {
    echo "ok remote version lookup removed\n";
} else {
    fwrite(STDERR, "FAIL showopsAgentHttpGet still defined\n");
    $failed++;
}

if ($failed !== 0) {
    fwrite(STDERR, "$failed check(s) failed\n");
    exit(1);
}
echo "listing compliance checks passed\n";
