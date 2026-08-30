<?php
require_once "/opt/fpp/www/common.php";

$mediaDir = isset($settings['mediaDirectory']) ? $settings['mediaDirectory'] : '/home/fpp/media';
$pluginDir = __DIR__;
$GLOBALS['pluginDir'] = $pluginDir;
$pluginRepoName = 'fpp-plugin-showops-agent';
$pluginDataDir = $mediaDir . '/plugindata/' . $pluginRepoName;
$configPath = $pluginDataDir . '/fpp-monitor-agent.json';
$legacyConfigPath = (isset($settings['configDirectory']) ? $settings['configDirectory'] : ($mediaDir . '/config')) . '/fpp-monitor-agent.json';
if (!file_exists($configPath) && file_exists($legacyConfigPath)) {
  $configPath = $legacyConfigPath;
}
$serviceName = 'fpp-monitor-agent.service';
$fallbackScript = $pluginDir . '/system/fpp-monitor-agent.sh';
$versionPaths = array(
  $pluginDir . '/bin/VERSION',
  '/opt/fpp-monitor-agent/VERSION',
);
$pluginLogPath = plugin_log_path($mediaDir, $pluginRepoName);

function h($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function read_config($path) {
  if (!file_exists($path)) {
    return array();
  }
  $raw = file_get_contents($path);
  if ($raw === false) {
    return array();
  }
  $data = json_decode($raw, true);
  if (!is_array($data)) {
    return array();
  }
  return $data;
}

function write_config_atomic($path, $data, &$error) {
  $dir = dirname($path);
  if (!is_dir($dir)) {
    if (!mkdir($dir, 0755, true)) {
      $error = 'Failed to create config directory';
      return false;
    }
  }
  $tmp = tempnam($dir, 'fppmon');
  if ($tmp === false) {
    $error = 'Failed to create temp file';
    return false;
  }
  $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  if ($json === false) {
    $error = 'Failed to encode JSON';
    @unlink($tmp);
    return false;
  }
  if (file_put_contents($tmp, $json . "\n") === false) {
    $error = 'Failed to write config';
    @unlink($tmp);
    return false;
  }
  @chmod($tmp, 0600);
  if (!rename($tmp, $path)) {
    $error = 'Failed to move config into place';
    @unlink($tmp);
    return false;
  }
  return true;
}

function run_cmd($cmd, &$output, &$exitCode) {
  $output = array();
  $exitCode = 0;
  exec($cmd, $output, $exitCode);
}

function is_systemd() {
  return is_dir('/run/systemd/system') && trim((string)shell_exec('command -v systemctl 2>/dev/null')) !== '';
}

function agent_binary_path($pluginDir) {
  $candidates = array(
    $pluginDir . '/bin/fpp-monitor-agent',
    '/opt/fpp-monitor-agent/fpp-monitor-agent',
  );
  foreach ($candidates as $bin) {
    if (!file_exists($bin)) {
      continue;
    }
    // Ignore empty/corrupt stubs left by a failed download.
    $size = @filesize($bin);
    if ($size !== false && $size > 1000) {
      return $bin;
    }
  }
  return '';
}

function service_status($serviceName) {
  if (is_systemd() && systemd_unit_path($serviceName) !== '') {
    run_cmd('systemctl is-active ' . escapeshellarg($serviceName), $output, $code);
    if ($code === 0 && isset($output[0])) {
      $state = trim($output[0]);
      if ($state === 'active') {
        return $state;
      }
    }
  }

  // Exact process name — do not match fpp-monitor-agent.sh.
  run_cmd('pgrep -x fpp-monitor-agent', $output, $code);
  return $code === 0 ? 'running' : 'stopped';
}

function last_log_line($pluginLogPath, $serviceName) {
  if ($pluginLogPath !== '' && file_exists($pluginLogPath)) {
    run_cmd('tail -n 1 ' . escapeshellarg($pluginLogPath), $output, $code);
    if ($code === 0 && isset($output[0]) && trim($output[0]) !== '') {
      return trim($output[0]);
    }
  }

  if (is_systemd() && systemd_unit_path($serviceName) !== '') {
    run_cmd('journalctl -u ' . escapeshellarg($serviceName) . ' -n 1 --no-pager --output=short-iso', $output, $code);
    if ($code === 0 && isset($output[0])) {
      return trim($output[0]);
    }
  }

  return '';
}

function detect_agent_version($paths) {
  foreach ($paths as $path) {
    if (file_exists($path)) {
      $raw = trim((string)file_get_contents($path));
      if ($raw !== '') {
        return $raw;
      }
    }
  }
  return 'unknown';
}

function detect_running_agent_version($pluginDir) {
  $bin = agent_binary_path($pluginDir);
  if ($bin === '') {
    return '';
  }
  $output = array();
  $code = 0;
  exec(escapeshellarg($bin) . ' -version 2>/dev/null', $output, $code);
  if ($code === 0 && isset($output[0])) {
    $ver = trim($output[0]);
    if ($ver !== '') {
      return $ver;
    }
  }
  return '';
}

function detect_arch() {
  $arch = php_uname('m');
  if (strpos($arch, 'armv7') !== false) {
    return 'armv7';
  }
  if ($arch === 'aarch64' || $arch === 'arm64') {
    return 'arm64';
  }
  return $arch !== '' ? $arch : 'unknown';
}

function systemd_unit_path($serviceName) {
  $systemdPath = '/etc/systemd/system/' . $serviceName;
  if (file_exists($systemdPath)) {
    return $systemdPath;
  }
  $systemdLibPath = '/lib/systemd/system/' . $serviceName;
  if (file_exists($systemdLibPath)) {
    return $systemdLibPath;
  }
  return '';
}

function service_installed($serviceName, $fallbackScript, $pluginDir) {
  return agent_binary_path($pluginDir) !== '';
}

function install_script_path($pluginDir) {
  return $pluginDir . '/scripts/fpp_install.sh';
}

function resolve_agent_release_version() {
  $ctx = stream_context_create(array(
    'http' => array('timeout' => 15, 'ignore_errors' => true),
    'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
  ));
  $raw = @file_get_contents('https://api.showops.io/v1/agent/releases/latest', false, $ctx);
  if ($raw !== false) {
    $data = json_decode($raw, true);
    if (is_array($data) && !empty($data['version'])) {
      return (string)$data['version'];
    }
  }
  return 'v1.2.68';
}

/**
 * Download the agent binary into the plugin bin/ tree (no root required).
 * Uses the public ShowOps release channel — GitHub is private and 404s anonymously.
 */
function install_agent_binary_from_channel($pluginDir, &$messages, &$errors) {
  @set_time_limit(180);
  @ini_set('max_execution_time', '180');

  $arch = detect_arch();
  if ($arch !== 'arm64' && $arch !== 'armv7') {
    $errors[] = 'Unsupported architecture for ShowOps agent: ' . $arch;
    return false;
  }

  $version = resolve_agent_release_version();
  $asset = 'fpp-monitor-agent-linux-' . $arch;
  $base = 'https://api.showops.io/v1/agent/releases/' . rawurlencode($version);
  $binDir = $pluginDir . '/bin';
  $dest = $binDir . '/fpp-monitor-agent';

  if (!is_dir($binDir) && !@mkdir($binDir, 0755, true)) {
    $errors[] = 'Cannot create ' . $binDir . ' (permission denied).';
    return false;
  }

  $tmpBin = tempnam(sys_get_temp_dir(), 'showopsbin');
  $tmpSum = tempnam(sys_get_temp_dir(), 'showopssum');
  if ($tmpBin === false || $tmpSum === false) {
    $errors[] = 'Cannot create temp files for agent download.';
    return false;
  }

  run_cmd(
    'curl -fsSL --connect-timeout 15 --max-time 120 -o ' . escapeshellarg($tmpBin) . ' ' . escapeshellarg($base . '/' . $asset),
    $output,
    $code
  );
  if ($code !== 0 || !file_exists($tmpBin) || filesize($tmpBin) < 1000) {
    // FPP sometimes disables shell exec; fall back to PHP streams.
    $ctx = stream_context_create(array(
      'http' => array('timeout' => 120, 'follow_location' => 1),
      'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $bytes = @file_get_contents($base . '/' . $asset, false, $ctx);
    if ($bytes === false || strlen($bytes) < 1000) {
      @unlink($tmpBin);
      @unlink($tmpSum);
      $errors[] = 'Failed to download agent ' . $version . ' (' . $arch . '). Check that this FPP can reach api.showops.io, then try Install Agent again.';
      return false;
    }
    if (@file_put_contents($tmpBin, $bytes) === false) {
      @unlink($tmpBin);
      @unlink($tmpSum);
      $errors[] = 'Downloaded agent but could not write temp file.';
      return false;
    }
  }

  run_cmd(
    'curl -fsSL --connect-timeout 10 --max-time 30 -o ' . escapeshellarg($tmpSum) . ' ' . escapeshellarg($base . '/checksums.txt'),
    $output,
    $code
  );
  if ($code === 0 && file_exists($tmpSum)) {
    $sums = file_get_contents($tmpSum);
    $expected = '';
    foreach (preg_split("/\r\n|\n|\r/", (string)$sums) as $line) {
      $line = trim($line);
      if ($line !== '' && substr($line, -strlen($asset)) === $asset) {
        $parts = preg_split('/\s+/', $line);
        $expected = isset($parts[0]) ? $parts[0] : '';
        break;
      }
    }
    if ($expected !== '') {
      $actual = hash_file('sha256', $tmpBin);
      if ($actual === false || !hash_equals($expected, $actual)) {
        @unlink($tmpBin);
        @unlink($tmpSum);
        $errors[] = 'Checksum mismatch for downloaded agent binary. Try Install Agent again.';
        return false;
      }
    }
  }
  @unlink($tmpSum);

  if (!@rename($tmpBin, $dest)) {
    if (!@copy($tmpBin, $dest)) {
      @unlink($tmpBin);
      $errors[] = 'Failed to install agent binary to ' . $dest;
      return false;
    }
    @unlink($tmpBin);
  }
  @chmod($dest, 0755);
  @file_put_contents($binDir . '/VERSION', $version . "\n");

  $wrapper = $pluginDir . '/system/fpp-monitor-agent.sh';
  if (file_exists($wrapper)) {
    @chmod($wrapper, 0755);
  }

  $messages[] = 'Installed agent ' . $version . ' (' . $arch . '). Next: Generate Pairing Code.';
  return true;
}

function try_install_agent($pluginDir, &$messages, &$errors) {
  if (agent_binary_path($pluginDir) !== '') {
    return true;
  }

  // Prefer in-UI download into plugin bin/ — no root / SSH required.
  if (install_agent_binary_from_channel($pluginDir, $messages, $errors)) {
    return true;
  }

  // Optional: full install script (systemd unit) if passwordless sudo works.
  $script = install_script_path($pluginDir);
  if (file_exists($script)) {
    run_cmd('sudo -n bash ' . escapeshellarg($script) . ' 2>&1', $output, $code);
    if ($code === 0 && agent_binary_path($pluginDir) !== '') {
      $messages[] = 'Agent installed via fpp_install.sh.';
      $errors = array();
      return true;
    }
  }

  return agent_binary_path($pluginDir) !== '';
}

function ensure_agent_present($pluginDir, &$messages, &$errors) {
  if (agent_binary_path($pluginDir) !== '') {
    return true;
  }
  $errors[] = 'Agent is not installed yet. Click Install Agent first, wait for it to finish, then Generate Pairing Code.';
  return false;
}

function plugin_log_path($mediaDir, $pluginRepoName) {
  $logDir = isset($GLOBALS['settings']['logDirectory'])
    ? $GLOBALS['settings']['logDirectory']
    : ($mediaDir . '/logs');
  return $logDir . '/plugin-' . $pluginRepoName . '.log';
}

function tail_logs($serviceName, $lines, $pluginLogPath = '') {
  if ($pluginLogPath !== '' && file_exists($pluginLogPath)) {
    run_cmd('tail -n ' . intval($lines) . ' ' . escapeshellarg($pluginLogPath), $output, $code);
    if ($code === 0) {
      return implode("\n", $output);
    }
  }

  if (is_systemd() && systemd_unit_path($serviceName) !== '') {
    run_cmd('journalctl -u ' . escapeshellarg($serviceName) . ' -n ' . intval($lines) . ' --no-pager', $output, $code);
    if ($code === 0) {
      return implode("\n", $output);
    }
    return 'Failed to read plugin log or journal.';
  }

  return 'No plugin log yet. If the agent is not installed, run: sudo bash ' .
    (isset($GLOBALS['pluginDir']) ? install_script_path($GLOBALS['pluginDir']) : 'scripts/fpp_install.sh');
}

function agent_is_running() {
  run_cmd('pgrep -x fpp-monitor-agent >/dev/null 2>&1', $output, $code);
  if ($code === 0) {
    return true;
  }
  run_cmd('pidof fpp-monitor-agent >/dev/null 2>&1', $output, $code);
  if ($code === 0) {
    return true;
  }
  run_cmd('pgrep -f "[f]pp-monitor-agent" >/dev/null 2>&1', $output, $code);
  return $code === 0;
}

function rotate_plugin_log($pluginLogPath) {
  if ($pluginLogPath === '' || !file_exists($pluginLogPath)) {
    return;
  }
  @rename($pluginLogPath, $pluginLogPath . '.bak');
  @file_put_contents($pluginLogPath, '');
}

function reset_pairing_config($configPath) {
  $reset = read_config($configPath);
  $reset['api_base_url'] = 'https://api.showops.io';
  $reset['pairing_requested'] = false;
  $reset['pairing_request_id'] = '';
  $reset['pairing_code'] = '';
  $reset['pairing_expires_at'] = '';
  $reset['pairing_status'] = '';
  $reset['pairing_device_nonce'] = '';
  $reset['unpair_requested'] = false;
  $err = '';
  return write_config_atomic($configPath, $reset, $err);
}

function enrollment_stash_path($mediaDir) {
  return rtrim((string)$mediaDir, '/') . '/config/showops-agent-enrollment.json';
}

function delete_enrollment_stash($mediaDir) {
  $path = enrollment_stash_path($mediaDir);
  if (is_file($path)) {
    @unlink($path);
  }
}

/** Wipe local enrollment so the UI cannot show a cloud device that no longer exists. */
function clear_local_enrollment($configPath) {
  $reset = read_config($configPath);
  $reset['api_base_url'] = 'https://api.showops.io';
  $reset['device_id'] = '';
  $reset['device_token'] = '';
  $reset['enrollment_token'] = '';
  $reset['last_heartbeat_ts'] = '';
  $reset['pairing_requested'] = false;
  $reset['pairing_request_id'] = '';
  $reset['pairing_code'] = '';
  $reset['pairing_expires_at'] = '';
  $reset['pairing_status'] = '';
  $reset['pairing_device_nonce'] = '';
  $reset['unpair_requested'] = false;
  $err = '';
  return write_config_atomic($configPath, $reset, $err);
}

function pairing_code_usable($code, $expiresAt) {
  if ($code === '') {
    return false;
  }
  if ($expiresAt === '') {
    return true;
  }
  $ts = strtotime($expiresAt);
  if ($ts === false) {
    return true;
  }
  return $ts > time();
}

function compute_device_fingerprint() {
  $parts = array();
  foreach (array('/etc/machine-id', '/var/lib/dbus/machine-id') as $path) {
    if (!is_readable($path)) {
      continue;
    }
    $v = trim((string)@file_get_contents($path));
    if ($v !== '') {
      $parts[] = 'mid:' . $v;
      break;
    }
  }

  $macs = array();
  foreach (glob('/sys/class/net/*') as $dir) {
    $name = basename($dir);
    if ($name === 'lo' || preg_match('/^(docker|veth|br-|tun|tap|wg|zt|tailscale)/', $name)) {
      continue;
    }
    $addrFile = $dir . '/address';
    if (!is_readable($addrFile)) {
      continue;
    }
    $mac = strtolower(trim((string)@file_get_contents($addrFile)));
    if ($mac === '' || $mac === '00:00:00:00:00:00') {
      continue;
    }
    $macs[$name] = $mac;
  }
  ksort($macs);
  foreach ($macs as $mac) {
    $parts[] = 'mac:' . $mac;
  }

  if (empty($parts)) {
    return '';
  }
  return hash('sha256', implode('|', $parts));
}

function http_json_post($url, $payload, &$error, $timeoutSec = 20) {
  $error = '';
  $body = json_encode($payload);
  if ($body === false) {
    $error = 'Failed to encode request.';
    return null;
  }

  $tmp = tempnam(sys_get_temp_dir(), 'showopspost');
  if ($tmp === false) {
    $error = 'Cannot create temp file.';
    return null;
  }

  $cmd = 'curl -sSL --connect-timeout 10 --max-time ' . intval($timeoutSec) .
    ' -H ' . escapeshellarg('Content-Type: application/json') .
    ' -d ' . escapeshellarg($body) .
    ' -o ' . escapeshellarg($tmp) .
    ' -w ' . escapeshellarg('%{http_code}') .
    ' ' . escapeshellarg($url);
  run_cmd($cmd, $output, $code);
  $status = isset($output[0]) ? trim($output[0]) : '';
  $raw = @file_get_contents($tmp);
  @unlink($tmp);

  if ($code !== 0 || $raw === false || $status === '') {
    // PHP stream fallback when exec/curl is restricted.
    $ctx = stream_context_create(array(
      'http' => array(
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => $body,
        'timeout' => $timeoutSec,
        'ignore_errors' => true,
      ),
      'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
    ));
    $raw = @file_get_contents($url, false, $ctx);
    $status = '0';
    $headers = function_exists('http_get_last_response_headers')
      ? http_get_last_response_headers()
      : (isset($GLOBALS['http_response_header']) ? $GLOBALS['http_response_header'] : null);
    if (is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $m)) {
      $status = $m[1];
    }
    if ($raw === false) {
      $error = 'Could not reach ShowOps API.';
      return null;
    }
  }

  $data = json_decode((string)$raw, true);
  if (!is_array($data)) {
    $error = 'Invalid response from ShowOps API (HTTP ' . $status . ').';
    return null;
  }
  $data['_http_status'] = intval($status);
  return $data;
}

function local_hostname() {
  foreach (array('/etc/hostname', '/proc/sys/kernel/hostname') as $path) {
    if (!is_readable($path)) {
      continue;
    }
    $value = trim((string)@file_get_contents($path));
    if ($value !== '') {
      return $value;
    }
  }
  $uname = php_uname('n');
  return is_string($uname) ? trim($uname) : '';
}

function create_pairing_code_via_api($apiBase, $fingerprint, &$error) {
  $url = rtrim($apiBase, '/') . '/v1/pairing/requests';
  $hostname = local_hostname();
  $payload = array('device_fingerprint' => $fingerprint);
  if ($hostname !== '') {
    $payload['device_info'] = array('hostname' => $hostname);
  }
  $resp = http_json_post($url, $payload, $error, 25);
  if ($resp === null) {
    return null;
  }
  $status = isset($resp['_http_status']) ? intval($resp['_http_status']) : 0;
  if ($status === 429 || (isset($resp['error']) && $resp['error'] === 'rate_limited')) {
    $error = 'Pairing is rate-limited. Wait a couple of minutes, then try once.';
    return null;
  }
  if ($status < 200 || $status >= 300 || empty($resp['pairing_code']) || empty($resp['request_id'])) {
    $err = isset($resp['error']) ? (string)$resp['error'] : ('HTTP ' . $status);
    $error = 'Could not create pairing code (' . $err . ').';
    return null;
  }
  return $resp;
}

function ensure_writable_log($preferredLog, $pluginDir) {
  $dir = dirname($preferredLog);
  if (!is_dir($dir)) {
    @mkdir($dir, 0755, true);
  }
  if (is_dir($dir) && is_writable($dir)) {
    return $preferredLog;
  }
  $fallback = $pluginDir . '/bin/agent-runtime.log';
  if (!is_dir(dirname($fallback))) {
    @mkdir(dirname($fallback), 0755, true);
  }
  return $fallback;
}

function start_fallback_runner($fallbackScript, $pluginDir, $configPath, $pluginLogPath, &$messages, &$errors, $reason = '') {
  $bin = agent_binary_path($pluginDir);
  if ($bin === '') {
    $errors[] = 'Cannot start agent: binary missing after install attempt.';
    return false;
  }
  if (!is_executable($bin)) {
    @chmod($bin, 0755);
  }
  if (!file_exists($configPath)) {
    $errors[] = 'Agent config missing at ' . $configPath;
    return false;
  }

  $logFile = ensure_writable_log($pluginLogPath, $pluginDir);

  // Soft stop only — avoid long multi-start storms that blank the FPP UI.
  run_cmd('pkill -x fpp-monitor-agent >/dev/null 2>&1; true', $output, $code);
  usleep(200000);

  $startCmds = array(
    'nohup ' . escapeshellarg($bin) . ' --config ' . escapeshellarg($configPath) .
      ' >>' . escapeshellarg($logFile) . ' 2>&1 &',
    'sudo -n -u fpp nohup ' . escapeshellarg($bin) . ' --config ' . escapeshellarg($configPath) .
      ' >>' . escapeshellarg($logFile) . ' 2>&1 &',
  );
  if (file_exists($fallbackScript)) {
    @chmod($fallbackScript, 0755);
    $startCmds[] = 'nohup ' . escapeshellarg($fallbackScript) . ' >/dev/null 2>&1 &';
  }

  foreach ($startCmds as $cmd) {
    run_cmd($cmd . ' echo ok', $output, $code);
    for ($i = 0; $i < 4; $i++) {
      usleep(250000);
      if (agent_is_running()) {
        $messages[] = 'Agent is running.';
        return true;
      }
    }
  }

  // Process detection is flaky under the FPP web user. If the binary exists,
  // treat start as best-effort and let the caller decide from outcomes.
  return agent_is_running();
}

function wait_for_pairing_code($configPath, $seconds = 10) {
  $deadline = time() + $seconds;
  $cfg = read_config($configPath);
  while (time() < $deadline) {
    clearstatcache(true, $configPath);
    $cfg = read_config($configPath);
    if (!empty($cfg['pairing_code'])) {
      return $cfg;
    }
    usleep(400000);
  }
  return $cfg;
}

function try_register_systemd_unit($pluginDir, $configPath, $pluginLogPath, &$messages) {
  if (!is_systemd() || systemd_unit_path('fpp-monitor-agent.service') !== '') {
    return false;
  }
  $bin = agent_binary_path($pluginDir);
  if ($bin === '') {
    return false;
  }
  $unitSrc = $pluginDir . '/system/fpp-monitor-agent.service';
  if (!file_exists($unitSrc)) {
    return false;
  }
  $generated = $pluginDir . '/system/fpp-monitor-agent.generated.service';
  $unit = file_get_contents($unitSrc);
  if ($unit === false) {
    return false;
  }
  $unit = str_replace(
    array('__PLUGIN_DIR__', '__CONFIG_PATH__', '__BIN_PATH__', '__LOG_FILE__'),
    array($pluginDir, $configPath, $bin, $pluginLogPath),
    $unit
  );
  if (@file_put_contents($generated, $unit) === false) {
    return false;
  }
  run_cmd(
    'sudo -n install -m 0644 ' . escapeshellarg($generated) . ' /etc/systemd/system/fpp-monitor-agent.service' .
    ' && sudo -n systemctl daemon-reload' .
    ' && sudo -n systemctl enable --now fpp-monitor-agent.service 2>&1',
    $output,
    $code
  );
  if ($code === 0 && systemd_unit_path('fpp-monitor-agent.service') !== '') {
    $messages[] = 'Registered systemd unit fpp-monitor-agent.service.';
    return true;
  }
  return false;
}

function restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, &$messages, &$errors) {
  if (!ensure_agent_present($pluginDir, $messages, $errors)) {
    return false;
  }

  try_register_systemd_unit($pluginDir, $configPath, $pluginLogPath, $messages);

  $unitPresent = systemd_unit_path($serviceName) !== '';

  if (is_systemd() && $unitPresent) {
    run_cmd('systemctl restart ' . escapeshellarg($serviceName) . ' 2>&1', $output, $code);
    if ($code !== 0) {
      run_cmd('sudo -n systemctl restart ' . escapeshellarg($serviceName) . ' 2>&1', $output, $code);
    }
    usleep(800000);
    if ($code === 0 && agent_is_running()) {
      $messages[] = 'Agent restarted via systemd.';
      return true;
    }
    $detail = trim(implode("\n", $output));
    return start_fallback_runner(
      $fallbackScript,
      $pluginDir,
      $configPath,
      $pluginLogPath,
      $messages,
      $errors,
      'Systemd restart failed' . ($detail !== '' ? (': ' . substr($detail, 0, 200)) : '') . '.'
    );
  }

  return start_fallback_runner(
    $fallbackScript,
    $pluginDir,
    $configPath,
    $pluginLogPath,
    $messages,
    $errors,
    $unitPresent ? '' : 'systemd unit not registered yet.'
  );
}

$messages = array();
$errors = array();
$logs = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = isset($_POST['action']) ? $_POST['action'] : '';

  if ($action === 'pair') {
    if (!ensure_agent_present($pluginDir, $messages, $errors)) {
      // keep error
    } else {
      $current = read_config($configPath);
      $apiBase = !empty($current['api_base_url']) ? $current['api_base_url'] : 'https://api.showops.io';
      $fingerprint = isset($current['device_fingerprint']) ? trim((string)$current['device_fingerprint']) : '';
      if ($fingerprint === '') {
        $fingerprint = compute_device_fingerprint();
      }
      if ($fingerprint === '') {
        $errors[] = 'Could not determine device identity for pairing.';
      } else {
        $apiError = '';
        $resp = create_pairing_code_via_api($apiBase, $fingerprint, $apiError);
        if ($resp === null) {
          $errors[] = $apiError !== '' ? $apiError : 'Could not create pairing code.';
        } else {
          $updated = $current;
          $updated['api_base_url'] = $apiBase;
          $updated['device_id'] = '';
          $updated['device_token'] = '';
          $updated['enrollment_token'] = '';
          $updated['last_heartbeat_ts'] = '';
          $updated['device_fingerprint'] = $fingerprint;
          $updated['pairing_requested'] = false;
          $updated['pairing_request_id'] = (string)$resp['request_id'];
          $updated['pairing_code'] = (string)$resp['pairing_code'];
          $updated['pairing_expires_at'] = isset($resp['expires_at']) ? (string)$resp['expires_at'] : '';
          $updated['pairing_status'] = 'PENDING';
          $updated['pairing_device_nonce'] = isset($resp['device_nonce']) ? (string)$resp['device_nonce'] : '';
          $updated['unpair_requested'] = false;

          $writeErr = '';
          if (!write_config_atomic($configPath, $updated, $writeErr)) {
            $errors[] = $writeErr !== '' ? $writeErr : 'Failed to save pairing code.';
          } else {
            // Start/restart agent so it can poll until the code is claimed.
            $startMessages = array();
            $startErrors = array();
            restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, $startMessages, $startErrors);
            $messages = array('Pairing code ready — claim it in ShowOps → Devices.');
            $errors = array();
          }
        }
      }
    }
  } elseif ($action === 'unpair') {
    // Always clear local credentials first. Cloud device may already be gone
    // (reinstall/replace), and the agent may not be installed to finish unpair.
    clear_local_enrollment($configPath);
    delete_enrollment_stash($mediaDir);
    run_cmd('pkill -x fpp-monitor-agent >/dev/null 2>&1; true', $output, $code);
    rotate_plugin_log($pluginLogPath);
    $messages[] = 'Local pairing cleared. Install the agent, then generate a new code.';
  } elseif ($action === 'restart') {
    restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, $messages, $errors);
  } elseif ($action === 'install') {
    $errors = array();
    $messages[] = 'Installing agent…';
    clear_local_enrollment($configPath);
    rotate_plugin_log($pluginLogPath);

    $ok = false;
    if (agent_binary_path($pluginDir) !== '') {
      $ok = true;
    } else {
      $ok = try_install_agent($pluginDir, $messages, $errors);
    }

    if ($ok && agent_binary_path($pluginDir) !== '') {
      $startMessages = array();
      $startErrors = array();
      restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, $startMessages, $startErrors);
      $errors = array();
      $messages = array('Agent installed. Next: Generate Pairing Code.');
    } elseif (empty($errors)) {
      $errors[] = 'Install did not complete. Check that this FPP can reach api.showops.io and try again.';
    }
  } elseif ($action === 'tail') {
    $logs = tail_logs($serviceName, 50, $pluginLogPath);
  }
}

$config = read_config($configPath);
$status = service_status($serviceName);
$installed = service_installed($serviceName, $fallbackScript, $pluginDir);
$agentVersion = detect_running_agent_version($pluginDir);
if ($agentVersion === '') {
  $agentVersion = detect_agent_version($versionPaths);
}
$arch = detect_arch();
$deviceId = isset($config['device_id']) ? trim((string)$config['device_id']) : '';
$heartbeatTs = isset($config['last_heartbeat_ts']) ? $config['last_heartbeat_ts'] : '';
$hasLocalDevice = $deviceId !== '';
$running = agent_is_running() || $status === 'active' || $status === 'running';

// Ghost "Paired" after plugin reinstall / cloud replace: local device_id with no binary.
if ($hasLocalDevice && !$installed) {
  clear_local_enrollment($configPath);
  rotate_plugin_log($pluginLogPath);
  $config = read_config($configPath);
  $deviceId = '';
  $heartbeatTs = '';
  $hasLocalDevice = false;
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $messages[] = 'Previous pairing was stale (device missing in ShowOps). Start fresh below.';
  }
}

$enrolled = $hasLocalDevice && $installed;

$pairingCode = isset($config['pairing_code']) ? $config['pairing_code'] : '';
$pairingExpires = isset($config['pairing_expires_at']) ? $config['pairing_expires_at'] : '';
$pairingStatus = isset($config['pairing_status']) ? $config['pairing_status'] : '';
$pairingRequestId = isset($config['pairing_request_id']) ? $config['pairing_request_id'] : '';

if (!$installed && !$enrolled) {
  if ($pairingCode !== '' || $pairingRequestId !== '' || !empty($config['pairing_requested'])) {
    reset_pairing_config($configPath);
    $config = read_config($configPath);
  }
  $pairingCode = '';
  $pairingExpires = '';
  $pairingStatus = '';
  $pairingRequestId = '';
}

if ($pairingCode !== '' && !pairing_code_usable($pairingCode, $pairingExpires) && !$enrolled) {
  reset_pairing_config($configPath);
  $config = read_config($configPath);
  $pairingCode = '';
  $pairingExpires = '';
  $pairingStatus = '';
  $pairingRequestId = '';
}

$logs = ($installed || $enrolled) ? tail_logs($serviceName, 50, $pluginLogPath) : '';
$lastLog = ($installed || $enrolled) ? last_log_line($pluginLogPath, $serviceName) : '';

$statusUpper = strtoupper($pairingStatus);
// Only warn about rate limits when we do not already have a usable code.
$rateLimited = $installed && $pairingCode === '' && (
  $statusUpper === 'RATE_LIMITED' ||
  strpos($logs, 'http_status_429') !== false ||
  strpos($logs, 'rate_limited') !== false
);
$alreadyPairedCloud = $installed && $pairingCode === '' && !$enrolled && (
  $statusUpper === 'ALREADY_PAIRED' ||
  strpos($logs, 'http_status_409') !== false ||
  strpos($logs, 'device_already_paired') !== false
);

if ($enrolled) {
  $step = 'paired';
} elseif (!$installed) {
  $step = 'install';
} elseif ($pairingCode !== '') {
  $step = 'claim';
} else {
  $step = 'pair';
}

// Never show "no code yet" when the code is already on screen.
if ($pairingCode !== '') {
  $filtered = array();
  foreach ($errors as $err) {
    if (strpos($err, 'No pairing code yet') === false) {
      $filtered[] = $err;
    }
  }
  $errors = $filtered;
}
?>

<style>
.showops-page .showops-pre {
  max-height: 16rem;
  overflow: auto;
  white-space: pre-wrap;
  word-break: break-word;
}
.showops-page .showops-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.showops-page .showops-actions .btn {
  min-height: 44px;
}
.showops-page .showops-code {
  font-size: 1.75rem;
  letter-spacing: 0.06em;
}
.showops-page .showops-muted-details {
  margin-top: 1rem;
}
</style>

<div class="container-fluid showops-page px-0 px-sm-2" id="showops-root">
  <h2 class="mb-2">ShowOps</h2>

  <?php foreach ($messages as $msg): ?>
    <div class="alert alert-success"><?php echo h($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach (array_slice($errors, 0, 1) as $msg): ?>
    <div class="alert alert-danger"><?php echo h(strlen($msg) > 220 ? substr($msg, 0, 220) . '…' : $msg); ?></div>
  <?php endforeach; ?>

  <div class="card mb-3 border bg-body-tertiary">
    <div class="card-body">
      <form method="post">
        <?php if ($step === 'install'): ?>
          <h3 class="h5">Install the agent</h3>
          <p class="text-body-secondary">Downloads the monitoring agent (~7MB). Click once and wait — the page may pause briefly while it downloads.</p>
          <div class="showops-actions">
            <button class="btn btn-primary btn-lg" type="submit" name="action" value="install">
              Install Agent
            </button>
          </div>

        <?php elseif ($step === 'pair'): ?>
          <h3 class="h5">Generate a pairing code</h3>
          <p class="text-body-secondary mb-2">
            Agent <?php echo h($agentVersion); ?> is installed<?php echo $running ? ' and running' : ''; ?>.
            Create a code, then claim it in ShowOps → Devices.
          </p>
          <?php if ($rateLimited): ?>
            <div class="alert alert-warning">Too many pairing attempts. Wait about 2 minutes, then try once.</div>
          <?php elseif ($alreadyPairedCloud): ?>
            <div class="alert alert-warning">This player is already linked in ShowOps. Remove it under Devices, then generate a new code.</div>
          <?php endif; ?>
          <div class="showops-actions">
            <button class="btn btn-success btn-lg" type="submit" name="action" value="pair" <?php echo $rateLimited ? 'disabled' : ''; ?>>
              Generate Pairing Code
            </button>
          </div>

        <?php elseif ($step === 'claim'): ?>
          <h3 class="h5">Claim this player</h3>
          <p class="text-body-secondary mb-2">Enter this code in ShowOps → Devices → Claim an FPP.</p>
          <div class="showops-code fw-bold font-monospace mb-1"><?php echo h($pairingCode); ?></div>
          <div class="text-body-secondary small mb-3">Expires: <?php echo h($pairingExpires !== '' ? $pairingExpires : 'soon'); ?></div>
          <div class="showops-actions">
            <button class="btn btn-outline-secondary" type="submit" name="action" value="pair">Get a new code</button>
            <button class="btn btn-outline-secondary" type="submit" name="action" value="restart">Restart agent</button>
          </div>

        <?php else: /* paired */ ?>
          <h3 class="h5">Paired</h3>
          <p class="mb-1">Device ID: <span class="font-monospace"><?php echo h($deviceId); ?></span></p>
          <p class="text-body-secondary small mb-3">
            Agent <?php echo h($running ? 'running' : 'stopped'); ?>
            · <?php echo h($agentVersion); ?>
            <?php if ($heartbeatTs !== ''): ?> · last heartbeat <?php echo h($heartbeatTs); ?><?php endif; ?>
          </p>
          <div class="showops-actions">
            <button class="btn btn-outline-secondary" type="submit" name="action" value="restart">Restart Agent</button>
            <button class="btn btn-outline-danger" type="submit" name="action" value="unpair">Unpair</button>
          </div>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <details class="showops-muted-details mb-3">
    <summary class="text-body-secondary">Technical details</summary>
    <div class="card mt-2 border bg-body-tertiary">
      <div class="card-body small">
        <div>Installed: <?php echo h($installed ? 'yes' : 'no'); ?> · Running: <?php echo h($running ? 'yes' : 'no'); ?> · Arch: <?php echo h($arch); ?></div>
        <div class="text-break mt-1">Last log: <?php echo h($lastLog !== '' ? $lastLog : 'none'); ?></div>
      </div>
    </div>
    <?php if ($installed || $enrolled): ?>
      <div class="card mt-2 border bg-body-tertiary">
        <div class="card-body">
          <form method="post" class="mb-2">
            <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="tail">Refresh Logs</button>
          </form>
          <pre class="showops-pre border rounded p-2 bg-body text-body mb-0"><?php echo h($logs !== '' ? $logs : 'No log output yet.'); ?></pre>
        </div>
      </div>
    <?php endif; ?>
  </details>
</div>
