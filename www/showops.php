<?php
require_once "/opt/fpp/www/common.php";

$mediaDir = isset($settings['mediaDirectory']) ? $settings['mediaDirectory'] : '/home/fpp/media';
$pluginDir = dirname(__DIR__);
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
  $binPlugin = $pluginDir . '/bin/fpp-monitor-agent';
  if (is_executable($binPlugin) || file_exists($binPlugin)) {
    return $binPlugin;
  }
  $binLegacy = '/opt/fpp-monitor-agent/fpp-monitor-agent';
  if (is_executable($binLegacy) || file_exists($binLegacy)) {
    return $binLegacy;
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
  return 'v1.2.29';
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
    @unlink($tmpBin);
    @unlink($tmpSum);
    $errors[] = 'Failed to download agent ' . $version . ' (' . $arch . '). Check that this FPP can reach api.showops.io, then try Install Agent again.';
    return false;
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
  run_cmd('pgrep -x fpp-monitor-agent', $output, $code);
  return $code === 0;
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
  run_cmd('pkill -x fpp-monitor-agent >/dev/null 2>&1; true', $output, $code);

  // Start the binary directly (wrapper log redirect often fails for the web user).
  $startCmds = array(
    'sudo -n -u fpp nohup ' . escapeshellarg($bin) . ' --config ' . escapeshellarg($configPath) .
      ' >>' . escapeshellarg($logFile) . ' 2>&1 &',
    'nohup ' . escapeshellarg($bin) . ' --config ' . escapeshellarg($configPath) .
      ' >>' . escapeshellarg($logFile) . ' 2>&1 &',
  );
  if (file_exists($fallbackScript)) {
    @chmod($fallbackScript, 0755);
    $startCmds[] = 'nohup ' . escapeshellarg($fallbackScript) . ' >/dev/null 2>&1 &';
  }

  $launched = false;
  foreach ($startCmds as $cmd) {
    run_cmd($cmd . ' echo ok', $output, $code);
    // Give nohup a moment; Armbian/FPP web PHP can be slow to observe the child.
    for ($i = 0; $i < 5; $i++) {
      usleep(400000);
      if (agent_is_running()) {
        $launched = true;
        break 2;
      }
    }
  }

  if (!$launched) {
    // Never run the agent with --config as a probe — that pairs against the API,
    // burns rate limits, and then timeout kills the process.
    run_cmd(escapeshellarg($bin) . ' --version 2>&1', $probeOut, $probeCode);
    $probe = trim(implode("\n", array_slice($probeOut, -5)));
    $logTail = '';
    if ($logFile !== '' && file_exists($logFile)) {
      run_cmd('tail -n 8 ' . escapeshellarg($logFile), $logOut, $logCode);
      if ($logCode === 0) {
        $logTail = trim(implode("\n", $logOut));
      }
    }

    if (strpos($logTail, 'http_status_429') !== false || strpos($logTail, 'rate_limited') !== false) {
      $errors[] = 'Agent is installed, but pairing is rate-limited from earlier attempts. Wait a minute, then click Generate Pairing Code once (do not click Install again).';
      return false;
    }
    if (strpos($logTail, 'http_status_409') !== false || strpos($logTail, 'device_already_paired') !== false) {
      $errors[] = 'Agent is installed, but this FPP is already paired in ShowOps. Remove it under Devices, then Generate Pairing Code once.';
      return false;
    }

    $errors[] = 'Agent binary is installed but did not stay running.' .
      ($probe !== '' ? (' Version check: ' . substr($probe, 0, 120)) : '') .
      ' Try Restart Agent. If it still fails, check plugin logs.';
    return false;
  }

  $msg = 'Agent is running.';
  if ($reason !== '') {
    $msg .= ' ' . $reason;
  }
  $messages[] = $msg;
  return true;
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
    if (!agent_is_running()) {
      return $cfg;
    }
    usleep(500000);
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
    if (empty($errors)) {
      $current = read_config($configPath);
      $updated = $current;
      $updated['api_base_url'] = 'https://api.showops.io';
      $updated['pairing_requested'] = true;
      $updated['pairing_request_id'] = '';
      $updated['pairing_code'] = '';
      $updated['pairing_expires_at'] = '';
      $updated['pairing_status'] = '';
      $updated['unpair_requested'] = false;
      $updated['enrollment_token'] = '';

      $error = '';
      if (write_config_atomic($configPath, $updated, $error)) {
        $messages[] = 'Pairing request created. Restarting agent to generate a code.';
        if (restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, $messages, $errors)) {
          $config = wait_for_pairing_code($configPath, 12);
          if (!empty($config['pairing_code'])) {
            $messages[] = 'Pairing code ready.';
          } elseif (empty($errors)) {
            $errors[] = 'Agent is running but no pairing code yet. Wait a few seconds and refresh, or check the plugin log.';
          }
        }
      } else {
        $errors[] = $error;
      }
    }
  } elseif ($action === 'unpair') {
    if (empty($errors)) {
      $current = read_config($configPath);
      $updated = $current;
      $updated['api_base_url'] = isset($updated['api_base_url']) && $updated['api_base_url'] !== ''
        ? $updated['api_base_url']
        : 'https://api.showops.io';
      $updated['pairing_requested'] = false;
      $updated['unpair_requested'] = true;
      $updated['pairing_status'] = 'UNPAIRING';

      $error = '';
      if (write_config_atomic($configPath, $updated, $error)) {
        $messages[] = 'Unpair requested. Restarting agent.';
        restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, $messages, $errors);
      } else {
        $errors[] = $error;
      }
    }
  } elseif ($action === 'restart') {
    restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, $messages, $errors);
  } elseif ($action === 'install') {
    $errors = array();
    $hadBinary = agent_binary_path($pluginDir) !== '';
    if ($hadBinary) {
      $messages[] = 'Agent is already installed. Starting it…';
    }
    if ($hadBinary || try_install_agent($pluginDir, $messages, $errors)) {
      // Install success is the binary on disk. Start is best-effort — do not
      // bury a good install under pairing rate-limit log spam.
      $startErrors = array();
      $started = restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, $messages, $startErrors);
      if (!$started) {
        foreach ($startErrors as $se) {
          $errors[] = $se;
        }
        if ($hadBinary || agent_binary_path($pluginDir) !== '') {
          $messages[] = 'Install is complete. Next step: Generate Pairing Code (not Install again).';
        }
      }
    }
  } elseif ($action === 'tail') {
    $logs = tail_logs($serviceName, 50, $pluginLogPath);
  }
}

$config = read_config($configPath);
$status = service_status($serviceName);
$lastLog = last_log_line($pluginLogPath, $serviceName);
$installed = service_installed($serviceName, $fallbackScript, $pluginDir);
$agentVersion = detect_agent_version($versionPaths);
$arch = detect_arch();
$deviceId = isset($config['device_id']) ? $config['device_id'] : '';
$heartbeatTs = isset($config['last_heartbeat_ts']) ? $config['last_heartbeat_ts'] : '';
$enrolled = $deviceId !== '';
$running = ($status === 'active' || $status === 'running');
$logs = tail_logs($serviceName, 50, $pluginLogPath);

$pairingCode = isset($config['pairing_code']) ? $config['pairing_code'] : '';
$pairingExpires = isset($config['pairing_expires_at']) ? $config['pairing_expires_at'] : '';
$pairingStatus = isset($config['pairing_status']) ? $config['pairing_status'] : '';
$pairingRequestId = isset($config['pairing_request_id']) ? $config['pairing_request_id'] : '';
$pairingRequested = !empty($config['pairing_requested']);

$pairingHint = '';
$statusUpper = strtoupper($pairingStatus);
if ($statusUpper === 'ALREADY_PAIRED' || strpos($logs, 'http_status_409') !== false || strpos($logs, 'device_already_paired') !== false) {
  $pairingHint = 'This FPP is already paired in ShowOps. Open ShowOps → Devices, remove/unpair the existing device for this player, wait a minute, then Generate Pairing Code once.';
} elseif ($statusUpper === 'RATE_LIMITED' || strpos($logs, 'http_status_429') !== false || strpos($logs, 'rate_limited') !== false) {
  $pairingHint = 'Pairing is rate-limited after too many attempts. Wait a few minutes, then click Generate Pairing Code once.';
}
?>

<style>
/* Minimal plugin-scoped layout only — colors from Bootstrap theme tokens. */
.showops-page .showops-pre {
  max-height: 24rem;
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
</style>

<div class="container-fluid showops-page px-0 px-sm-2">
  <h2 class="mb-3">ShowOps Configuration</h2>
  <p class="text-body-secondary mb-3">
    <?php if (!$installed): ?>
      Step 1: Install Agent. Step 2: Generate Pairing Code. Step 3: Claim the code in ShowOps → Devices.
    <?php elseif ($pairingCode !== '' && !$enrolled): ?>
      Copy the pairing code below into ShowOps → Devices → Claim an FPP. You do not need Install Agent.
    <?php else: ?>
      Generate a pairing code, then claim it under Devices on showops.io.
    <?php endif; ?>
  </p>

  <?php foreach ($messages as $msg): ?>
    <div class="alert alert-success"><?php echo h($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach (array_slice($errors, 0, 3) as $msg): ?>
    <div class="alert alert-danger"><?php echo h(strlen($msg) > 500 ? substr($msg, 0, 500) . '…' : $msg); ?></div>
  <?php endforeach; ?>
  <?php if ($pairingHint !== '' && $pairingCode === ''): ?>
    <div class="alert alert-warning"><?php echo h($pairingHint); ?></div>
  <?php endif; ?>

  <div class="card mb-3 border bg-body-tertiary">
    <div class="card-body">
      <h3 class="h5 card-title">Connection status</h3>
      <div class="row g-3">
        <div class="col-6 col-md-4 col-lg-3">
          <div class="text-body-secondary text-uppercase small">Installed</div>
          <div class="fw-semibold"><?php echo h($installed ? 'yes' : 'no'); ?></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
          <div class="text-body-secondary text-uppercase small">Agent</div>
          <div class="fw-semibold"><?php echo h($running ? 'running' : 'stopped'); ?></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
          <div class="text-body-secondary text-uppercase small">Pairing</div>
          <div class="fw-semibold"><?php echo h($enrolled ? 'paired' : 'unpaired'); ?></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
          <div class="text-body-secondary text-uppercase small">Service</div>
          <div class="fw-semibold"><?php echo h($status); ?></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
          <div class="text-body-secondary text-uppercase small">Agent version</div>
          <div class="fw-semibold"><?php echo h($agentVersion); ?></div>
        </div>
        <div class="col-6 col-md-4 col-lg-3">
          <div class="text-body-secondary text-uppercase small">Architecture</div>
          <div class="fw-semibold"><?php echo h($arch); ?></div>
        </div>
        <div class="col-12 col-md-6">
          <div class="text-body-secondary text-uppercase small">Device ID</div>
          <div class="fw-semibold text-break"><?php echo h($deviceId !== '' ? $deviceId : 'N/A'); ?></div>
        </div>
        <div class="col-12 col-md-6">
          <div class="text-body-secondary text-uppercase small">Last heartbeat</div>
          <div class="fw-semibold text-break"><?php echo h($heartbeatTs !== '' ? $heartbeatTs : 'N/A'); ?></div>
        </div>
        <div class="col-12">
          <div class="text-body-secondary text-uppercase small">Last log line</div>
          <div class="fw-semibold text-break"><?php echo h($lastLog !== '' ? $lastLog : 'N/A'); ?></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card mb-3 border bg-body-tertiary">
    <div class="card-body">
      <h3 class="h5 card-title">Pairing</h3>
      <form method="post">
        <div class="mb-2">
          <?php if ($enrolled): ?>
            <span class="badge text-bg-success">Paired</span>
          <?php elseif ($pairingCode !== '' || $pairingRequestId !== '' || $pairingRequested): ?>
            <span class="badge text-bg-warning"><?php echo h($pairingStatus !== '' ? $pairingStatus : 'PENDING'); ?></span>
          <?php else: ?>
            <span class="badge text-bg-secondary">Unpaired</span>
          <?php endif; ?>
        </div>

        <?php if ($pairingCode !== ''): ?>
          <div class="mb-2">
            <div class="text-body-secondary text-uppercase small">Pairing code</div>
            <div class="fs-4 fw-bold font-monospace"><?php echo h($pairingCode); ?></div>
            <div class="text-body-secondary small">Expires at: <?php echo h($pairingExpires !== '' ? $pairingExpires : 'unknown'); ?></div>
          </div>
        <?php endif; ?>

        <?php if ($pairingCode === '' && !$enrolled): ?>
          <p class="text-body-secondary small mb-2">
            Click Generate Pairing Code, then enter it in ShowOps to claim this device.
          </p>
        <?php endif; ?>

        <div class="showops-actions mt-2">
          <?php if (!$installed): ?>
            <button class="btn btn-primary" type="submit" name="action" value="install">
              1. Install Agent
            </button>
          <?php endif; ?>
          <button class="btn btn-success" type="submit" name="action" value="pair" <?php echo ($enrolled || !$installed) ? 'disabled' : ''; ?>>
            <?php echo !$installed ? '2. Generate Pairing Code' : 'Generate Pairing Code'; ?>
          </button>
          <button class="btn btn-outline-secondary" type="submit" name="action" value="unpair" <?php echo $enrolled ? '' : 'disabled'; ?>>
            Unpair / Reset
          </button>
          <button class="btn btn-outline-secondary" type="submit" name="action" value="restart" <?php echo !$installed ? 'disabled' : ''; ?>>Restart Agent</button>
        </div>
        <?php if (!$installed): ?>
          <p class="text-body-secondary small mt-2 mb-0">
            Install downloads the agent (~7MB) and can take up to a minute. Do not click repeatedly.
          </p>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <div class="card mb-3 border bg-body-tertiary">
    <div class="card-body">
      <h3 class="h5 card-title">Logs</h3>
      <form method="post" class="mb-2">
        <button class="btn btn-outline-secondary" type="submit" name="action" value="tail">Refresh Logs</button>
      </form>
      <pre class="showops-pre border rounded p-3 bg-body text-body mb-2"><?php echo h($logs !== '' ? $logs : 'No log output available.'); ?></pre>
      <div class="text-body-secondary small">Showing latest 50 lines from the plugin log (FPP-rotated).</div>
    </div>
  </div>
</div>
