<?php

/**
 * Plugin-page POSTs must come from this player's own web UI.
 * Origin is checked when the browser sends it; otherwise Referer is checked.
 * Host and port must match HTTP_HOST.
 */
function showops_request_from_player($httpHost, $origin, $referer, $https)
{
    $httpHost = strtolower(trim((string)$httpHost));
    if ($httpHost === '') {
        return false;
    }

    $source = trim((string)$origin);
    if ($source === '') {
        $source = trim((string)$referer);
    }
    if ($source === '') {
        return false;
    }

    $parts = parse_url($source);
    if (!is_array($parts) || empty($parts['host'])) {
        return false;
    }

    $originHost = strtolower($parts['host']);
    $originPort = isset($parts['port']) ? (int)$parts['port'] : 0;
    if ($originPort === 0) {
        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : 'http';
        $originPort = ($scheme === 'https') ? 443 : 80;
    }

    $playerHost = $httpHost;
    $playerPort = $https ? 443 : 80;
    if (preg_match('/^\[([^\]]+)\]:(\d+)$/', $httpHost, $matches)) {
        $playerHost = strtolower($matches[1]);
        $playerPort = (int)$matches[2];
    } elseif (preg_match('/^([^:]+):(\d+)$/', $httpHost, $matches)) {
        $playerHost = strtolower($matches[1]);
        $playerPort = (int)$matches[2];
    }

    return $originHost === $playerHost && $originPort === $playerPort;
}
