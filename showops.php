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

function ensure_agent_present($pluginDir, &$messages, &$errors) {
  if (agent_binary_path($pluginDir) !== '') {
    return true;
  }
  $errors[] = 'Agent is not installed yet. Install or update ShowOps from FPP Plugin Manager, then generate a pairing code.';
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

  return 'No plugin log yet. Install or update ShowOps from FPP Plugin Manager.';
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

function reset_pairing_config($configPath) {
  $reset = read_config($configPath);
  $reset['api_base_url'] = 'https://api.showops.io';
  $reset['pairing_requested'] = false;
  $reset['pairing_request_id'] = '';
  $reset['pairing_code'] = '';
  $reset['pairing_expires_at'] = '';
  $reset['pairing_status'] = '';
  $reset['pairing_device_nonce'] = '';
  $reset['claimed_org_name'] = '';
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
  $reset['claimed_org_name'] = '';
  $reset['unpair_requested'] = false;
  $err = '';
  return write_config_atomic($configPath, $reset, $err);
}

function pairing_code_usable($code, $expiresAt) {
  if ($code === '') {
    return false;
  }
  $ts = strtotime((string)$expiresAt);
  if ($expiresAt === '' || $ts === false) {
    return false;
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
    // Physical player NICs only. Virtual interfaces are not part of the hardware id.
    if (!preg_match('/^(eth|en|wlan|wl|usb)/', $name)) {
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

function restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, &$messages, &$errors) {
  if (!ensure_agent_present($pluginDir, $messages, $errors)) {
    return false;
  }
  if (!is_systemd() || systemd_unit_path($serviceName) === '') {
    $errors[] = 'The agent service is not installed. Use FPP Plugin Manager to reinstall ShowOps.';
    return false;
  }
  global $SUDO;
  $sudo = isset($SUDO) ? $SUDO : '';
  run_cmd(trim($sudo . ' systemctl restart ' . escapeshellarg($serviceName)) . ' 2>&1', $output, $code);
  if ($code === 0) {
    $messages[] = 'Agent restarted.';
    return true;
  }
  $detail = trim(implode(' ', $output));
  $errors[] = 'Could not restart the agent' . ($detail !== '' ? (': ' . substr($detail, 0, 180)) : '.');
  return false;
}

function config_flag_on($config, $key) {
  return isset($config[$key]) && $config[$key];
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
    $messages[] = 'Local pairing cleared. Generate a new code when you are ready.';
  } elseif ($action === 'restart') {
    restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, $messages, $errors);
  } elseif ($action === 'settings') {
    $current = read_config($configPath);
    $current['backup_enabled'] = isset($_POST['backup_enabled']);
    $current['location_enabled'] = isset($_POST['location_enabled']);
    $current['fpp_collect_enabled'] = isset($_POST['fpp_collect_enabled']);
    $current['reboot_enabled'] = isset($_POST['reboot_enabled']);
    $writeErr = '';
    if (!write_config_atomic($configPath, $current, $writeErr)) {
      $errors[] = $writeErr !== '' ? $writeErr : 'Could not save settings.';
    } else {
      restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, $messages, $errors);
      $messages[] = 'Settings saved.';
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
          <h3 class="h5">Install from Plugin Manager</h3>
          <p class="text-body-secondary mb-0">This page does not download software. Use FPP → Content Setup → Plugin Manager to install or update ShowOps, then return here to pair. Pair only on a network you trust.</p>

        <?php elseif ($step === 'pair'): ?>
          <h3 class="h5">Generate a pairing code</h3>
          <p class="text-body-secondary mb-2">
            Agent <?php echo h($agentVersion); ?> is installed<?php echo $running ? ' and running' : ''; ?>.
            Create a code on a network you trust, then claim it in ShowOps → Devices. Anyone who can open this page can see the code until it expires.
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
          <p class="text-body-secondary mb-2">Enter this code in ShowOps → Devices → Claim an FPP. Do this on a network you trust.</p>
          <div class="showops-code fw-bold font-monospace mb-1"><?php echo h($pairingCode); ?></div>
          <div class="text-body-secondary small mb-3">Expires: <?php echo h($pairingExpires); ?></div>
          <div class="showops-actions">
            <button class="btn btn-outline-secondary" type="submit" name="action" value="pair">Get a new code</button>
            <button class="btn btn-outline-secondary" type="submit" name="action" value="restart">Restart agent</button>
          </div>

        <?php else: /* paired */ ?>
          <h3 class="h5">Paired</h3>
          <?php $claimedOrg = isset($config['claimed_org_name']) ? trim((string)$config['claimed_org_name']) : ''; ?>
          <?php if ($claimedOrg !== ''): ?>
            <p class="mb-1">ShowOps account: <?php echo h($claimedOrg); ?></p>
          <?php endif; ?>
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
      <?php if ($step === 'paired'): ?>
        <form method="post" class="mt-3">
          <input type="hidden" name="action" value="settings">
          <h3 class="h6">Optional data and actions</h3>
          <p class="text-body-secondary small">Off until you turn them on. Monitoring of show status stays on.</p>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="fpp_collect_enabled" id="fpp_collect_enabled" <?php echo config_flag_on($config, 'fpp_collect_enabled') ? 'checked' : ''; ?>>
            <label class="form-check-label" for="fpp_collect_enabled">Send diagnostics (plugins, network, logs)</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="location_enabled" id="location_enabled" <?php echo config_flag_on($config, 'location_enabled') ? 'checked' : ''; ?>>
            <label class="form-check-label" for="location_enabled">Send map location</label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="checkbox" name="backup_enabled" id="backup_enabled" <?php echo config_flag_on($config, 'backup_enabled') ? 'checked' : ''; ?>>
            <label class="form-check-label" for="backup_enabled">Allow configuration backup upload (passwords removed)</label>
          </div>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="reboot_enabled" id="reboot_enabled" <?php echo config_flag_on($config, 'reboot_enabled') ? 'checked' : ''; ?>>
            <label class="form-check-label" for="reboot_enabled">Allow ShowOps to request an FPP reboot banner</label>
          </div>
          <button class="btn btn-outline-primary" type="submit">Save settings</button>
        </form>
      <?php endif; ?>
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
