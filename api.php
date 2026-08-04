<?php

/**
 * FPP plugin API endpoints for fpp-plugin-showops-agent.
 *
 * Registered by FPP's /www/api/index.php addPluginEndpoints().
 * Function name must match getEndpoints + plugin-dir with hyphens removed.
 */

function getEndpointsfpppluginshowopsagent()
{
    return array(
        array(
            'method' => 'GET',
            'endpoint' => 'updates',
            'callback' => 'showopsAgentUpdates',
        ),
    );
}

/** @deprecated Legacy install dir name showops-agent; kept for mid-upgrade hosts. */
function getEndpointsshowopsagent()
{
    return getEndpointsfpppluginshowopsagent();
}

function showopsAgentUpdates()
{
    $currentVersion = showopsAgentDetectCurrentVersion();
    $latestVersion = showopsAgentResolveLatestVersion();

    $response = array(
        'status' => 'ok',
        'repo' => 'showops-io/fpp-agent-monitor',
        'currentVersion' => $currentVersion,
        'latestVersion' => $latestVersion,
    );

    if ($currentVersion !== null && $latestVersion !== null) {
        $response['updateAvailable'] = showopsAgentCompareVersions($currentVersion, $latestVersion) < 0;
    }

    return json($response);
}

function showopsAgentDetectCurrentVersion()
{
    global $settings;

    $media = isset($settings['mediaDirectory']) ? $settings['mediaDirectory'] : '/home/fpp/media';
    $pluginRoot = dirname(__FILE__);

    $versionPaths = array(
        $pluginRoot . '/bin/VERSION',
        $media . '/plugins/fpp-plugin-showops-agent/bin/VERSION',
        $media . '/plugins/showops-agent/bin/VERSION',
        '/opt/fpp-monitor-agent/VERSION',
    );

    foreach ($versionPaths as $path) {
        if (!file_exists($path)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            continue;
        }
        $trimmed = trim($raw);
        if ($trimmed !== '') {
            return $trimmed;
        }
    }

    return null;
}

function showopsAgentResolveLatestVersion()
{
    $showopsUrl = 'https://api.showops.io/v1/agent/releases/latest';
    $json = showopsAgentHttpGet($showopsUrl, array(
        'Accept: application/json',
        'User-Agent: fpp-plugin-showops-agent',
    ));
    if ($json !== null) {
        $data = json_decode($json, true);
        if (is_array($data) && !empty($data['version']) && is_string($data['version'])) {
            return trim($data['version']);
        }
    }

    $apiUrl = 'https://api.github.com/repos/showops-io/fpp-agent-monitor/releases/latest';
    $json = showopsAgentHttpGet($apiUrl, array(
        'Accept: application/vnd.github+json',
        'User-Agent: fpp-plugin-showops-agent',
    ));
    if ($json !== null) {
        $data = json_decode($json, true);
        if (is_array($data) && !empty($data['tag_name']) && is_string($data['tag_name'])) {
            return trim($data['tag_name']);
        }
    }

    $manifestUrl = 'https://raw.githubusercontent.com/showops-io/fpp-agent-monitor/main/latest.json';
    $manifestJson = showopsAgentHttpGet($manifestUrl, array(
        'User-Agent: fpp-plugin-showops-agent',
    ));
    if ($manifestJson !== null) {
        $manifest = json_decode($manifestJson, true);
        if (is_array($manifest) && !empty($manifest['version']) && is_string($manifest['version'])) {
            return trim($manifest['version']);
        }
    }

    return null;
}

function showopsAgentHttpGet($url, $headers = array())
{
    $headerStr = '';
    if (!empty($headers)) {
        $headerStr = implode("\r\n", $headers) . "\r\n";
    }

    $context = stream_context_create(array(
        'http' => array(
            'method' => 'GET',
            'timeout' => 3,
            'header' => $headerStr,
            'ignore_errors' => true,
        ),
    ));

    $result = @file_get_contents($url, false, $context);
    if ($result === false) {
        return null;
    }
    return $result;
}

function showopsAgentVersionParts($version)
{
    if (!is_string($version)) {
        return array();
    }
    $clean = preg_replace('/^[vV]/', '', trim($version));
    if ($clean === '') {
        return array();
    }
    $segments = preg_split('/[^0-9]+/', $clean);
    $parts = array();
    if (!is_array($segments)) {
        return $parts;
    }
    foreach ($segments as $segment) {
        if ($segment === '') {
            continue;
        }
        $parts[] = intval($segment, 10);
    }
    return $parts;
}

/**
 * Returns -1 if $a < $b, 0 if equal, 1 if $a > $b.
 */
function showopsAgentCompareVersions($a, $b)
{
    $left = showopsAgentVersionParts($a);
    $right = showopsAgentVersionParts($b);
    $len = max(count($left), count($right), 3);

    for ($i = 0; $i < $len; $i++) {
        $lv = isset($left[$i]) ? $left[$i] : 0;
        $rv = isset($right[$i]) ? $right[$i] : 0;
        if ($lv > $rv) {
            return 1;
        }
        if ($lv < $rv) {
            return -1;
        }
    }

    return 0;
}
