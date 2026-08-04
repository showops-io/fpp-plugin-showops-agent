<?php
require_once "/opt/fpp/www/common.php";

$mediaDir = isset($settings['mediaDirectory']) ? $settings['mediaDirectory'] : '/home/fpp/media';
$pluginDir = dirname(__DIR__);
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

function service_status($serviceName) {
  if (is_systemd()) {
    run_cmd('systemctl is-active ' . escapeshellarg($serviceName), $output, $code);
    if ($code === 0 && isset($output[0])) {
      return trim($output[0]);
    }
    return 'inactive';
  }

  run_cmd('pgrep -f fpp-monitor-agent', $output, $code);
  return $code === 0 ? 'running' : 'stopped';
}

function last_log_line($serviceName) {
  if (is_systemd()) {
    run_cmd('journalctl -u ' . escapeshellarg($serviceName) . ' -n 1 --no-pager --output=short-iso', $output, $code);
    if ($code === 0 && isset($output[0])) {
      return trim($output[0]);
    }
    return '';
  }

  $paths = array('/var/log/syslog', '/var/log/messages');
  foreach ($paths as $path) {
    if (file_exists($path)) {
      run_cmd('tail -n 1 ' . escapeshellarg($path), $output, $code);
      if ($code === 0 && isset($output[0])) {
        return trim($output[0]);
      }
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

function service_installed($serviceName, $fallbackScript, $pluginDir) {
  $systemdPath = '/etc/systemd/system/' . $serviceName;
  $systemdLibPath = '/lib/systemd/system/' . $serviceName;
  $binPlugin = $pluginDir . '/bin/fpp-monitor-agent';
  $binLegacy = '/opt/fpp-monitor-agent/fpp-monitor-agent';

  return file_exists($systemdPath) ||
    file_exists($systemdLibPath) ||
    file_exists($fallbackScript) ||
    file_exists($binPlugin) ||
    file_exists($binLegacy);
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

  if (is_systemd()) {
    run_cmd('journalctl -u ' . escapeshellarg($serviceName) . ' -n ' . intval($lines) . ' --no-pager', $output, $code);
    if ($code === 0) {
      return implode("\n", $output);
    }
    return 'Failed to read plugin log or journal.';
  }

  $paths = array('/var/log/syslog', '/var/log/messages');
  foreach ($paths as $path) {
    if (file_exists($path)) {
      run_cmd('tail -n ' . intval($lines) . ' ' . escapeshellarg($path), $output, $code);
      if ($code === 0) {
        return implode("\n", $output);
      }
    }
  }
  return 'No log source found.';
}

function restart_agent($serviceName, $fallbackScript, &$messages, &$errors) {
  if (is_systemd()) {
    // Prefer direct systemctl (FPP pages often run as a privileged web user).
    run_cmd('systemctl restart ' . escapeshellarg($serviceName) . ' 2>&1', $output, $code);
    if ($code !== 0) {
      run_cmd('sudo -n systemctl restart ' . escapeshellarg($serviceName) . ' 2>&1', $output, $code);
    }
    if ($code === 0) {
      $messages[] = 'Agent restarted via systemd.';
    } else {
      $detail = trim(implode("\n", $output));
      if ($detail === '') {
        $detail = 'systemctl restart exited with code ' . $code . '.';
      }
      $errors[] = 'Failed to restart via systemd: ' . $detail;
    }
    return;
  }

  run_cmd('nohup ' . escapeshellarg($fallbackScript) . ' >/dev/null 2>&1 &', $output, $code);
  $messages[] = 'Systemd not available; fallback runner launched.';
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
      if (isset($updated['api_base_url'])) {
        unset($updated['api_base_url']);
      }
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
        restart_agent($serviceName, $fallbackScript, $messages, $errors);
      } else {
        $errors[] = $error;
      }
    }
  } elseif ($action === 'unpair') {
    if (empty($errors)) {
      $current = read_config($configPath);
      $updated = $current;
      if (isset($updated['api_base_url'])) {
        unset($updated['api_base_url']);
      }
      $updated['pairing_requested'] = false;
      $updated['unpair_requested'] = true;
      $updated['pairing_status'] = 'UNPAIRING';

      $error = '';
      if (write_config_atomic($configPath, $updated, $error)) {
        $messages[] = 'Unpair requested. Restarting agent.';
        restart_agent($serviceName, $fallbackScript, $messages, $errors);
      } else {
        $errors[] = $error;
      }
    }
  } elseif ($action === 'restart') {
    restart_agent($serviceName, $fallbackScript, $messages, $errors);
  } elseif ($action === 'tail') {
    $logs = tail_logs($serviceName, 50, $pluginLogPath);
  }
}

$config = read_config($configPath);
$status = service_status($serviceName);
$lastLog = last_log_line($serviceName);
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
    Pair this Falcon Player with ShowOps, then claim the code under Devices on showops.io.
  </p>

  <?php foreach ($messages as $msg): ?>
    <div class="alert alert-success"><?php echo h($msg); ?></div>
  <?php endforeach; ?>
  <?php foreach ($errors as $msg): ?>
    <div class="alert alert-danger"><?php echo h($msg); ?></div>
  <?php endforeach; ?>

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
          <button class="btn btn-success" type="submit" name="action" value="pair" <?php echo $enrolled ? 'disabled' : ''; ?>>
            Generate Pairing Code
          </button>
          <button class="btn btn-outline-secondary" type="submit" name="action" value="unpair" <?php echo $enrolled ? '' : 'disabled'; ?>>
            Unpair / Reset
          </button>
          <button class="btn btn-outline-secondary" type="submit" name="action" value="restart">Restart Agent</button>
        </div>
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
