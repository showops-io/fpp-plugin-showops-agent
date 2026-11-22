<?php
require_once "/opt/fpp/www/common.php";
require_once __DIR__ . '/scripts/post_origin.php';
require_once __DIR__ . '/scripts/connect_link.php';

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

// The agent's command line. pgrep/pkill -x cannot match it: process names are
// cut to 15 characters ("fpp-monitor-age"). The [/] keeps the pattern from
// matching the shell that runs pgrep itself.
define('SHOWOPS_AGENT_PROC', escapeshellarg('[/]bin/fpp-monitor-agent( |$)'));

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

  // The binary, not fpp-monitor-agent.sh or the supervisor.
  run_cmd('pgrep -f ' . SHOWOPS_AGENT_PROC, $output, $code);
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
  $errors[] = 'Agent is not installed yet. Install or update ShowOps from FPP Plugin Manager, then click Sign in to ShowOps.';
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
  run_cmd('pgrep -f ' . SHOWOPS_AGENT_PROC . ' >/dev/null 2>&1', $output, $code);
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
  $reset['api_base_url'] = SHOWOPS_DEFAULT_API_BASE_URL;
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
  $reset['api_base_url'] = SHOWOPS_DEFAULT_API_BASE_URL;
  $reset['device_id'] = '';
  $reset['device_token'] = '';
  $reset['enrollment_token'] = '';
  $reset['device_fingerprint'] = '';
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

// Passes the plugin page's sign-in on to ShowOps. The body goes to curl on
// stdin (never on a command line, where ps could show it), redirects are not
// followed, and nothing is written to disk or to a log.
function showops_forward_signin($apiBase, $payload) {
  $url = rtrim($apiBase, '/') . '/v1/pairing/sign-in';
  $body = json_encode($payload);
  if ($body === false) {
    return array(null, 0);
  }
  $proto = strpos($url, 'https://') === 0 ? '=https' : '=http';
  if (function_exists('proc_open')) {
    $cmd = 'curl -sS --proto ' . escapeshellarg($proto) . ' --connect-timeout 10 --max-time 25' .
      ' -H ' . escapeshellarg('Content-Type: application/json') .
      ' --data-binary @- -w ' . escapeshellarg("\n%{http_code}") .
      ' ' . escapeshellarg($url);
    $proc = @proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (is_resource($proc)) {
      fwrite($pipes[0], $body);
      fclose($pipes[0]);
      $out = stream_get_contents($pipes[1]);
      fclose($pipes[1]);
      stream_get_contents($pipes[2]);
      fclose($pipes[2]);
      $code = proc_close($proc);
      $cut = is_string($out) ? strrpos($out, "\n") : false;
      if ($code === 0 && $cut !== false) {
        $status = intval(trim(substr($out, $cut + 1)));
        if ($status > 0) {
          return array(json_decode(substr($out, 0, $cut), true), $status);
        }
      }
    }
  }
  // No curl: PHP's own HTTPS client, certificate checks on, no redirects.
  $ctx = stream_context_create(array(
    'http' => array(
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\n",
      'content' => $body,
      'timeout' => 25,
      'ignore_errors' => true,
      'follow_location' => 0,
    ),
    'ssl' => array('verify_peer' => true, 'verify_peer_name' => true),
  ));
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) {
    return array(null, 0);
  }
  $headers = function_exists('http_get_last_response_headers')
    ? http_get_last_response_headers()
    : (isset($http_response_header) ? $http_response_header : null);
  $status = 0;
  if (is_array($headers) && isset($headers[0]) && preg_match('/\s(\d{3})(\s|$)/', $headers[0], $m)) {
    $status = intval($m[1]);
  }
  return array(json_decode((string)$raw, true), $status);
}

function restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, &$messages, &$errors) {
  if (!ensure_agent_present($pluginDir, $messages, $errors)) {
    return false;
  }
  if (!is_systemd()) {
    return restart_supervised_agent($pluginDir, $messages, $errors);
  }
  if (systemd_unit_path($serviceName) === '') {
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

/**
 * No systemd (FPP in Docker): postStart.sh runs the agent under
 * system/fpp-monitor-agent-supervise.sh, which starts it again 5 seconds after
 * it exits. Restart = stop the agent and let the supervisor bring it back, or
 * start the supervisor when it is not running.
 */
function restart_supervised_agent($pluginDir, &$messages, &$errors) {
  // [/] keeps pgrep from matching the shell that runs it.
  $supervisor = escapeshellarg('[/]system/fpp-monitor-agent-supervise[.]sh');
  run_cmd('pgrep -f ' . $supervisor . ' >/dev/null 2>&1', $output, $code);
  if ($code === 0) {
    run_cmd('pkill -f ' . SHOWOPS_AGENT_PROC . ' 2>&1', $output, $code);
    // 1 = nothing to stop; the supervisor starts it within 5 seconds anyway.
    if ($code === 0 || $code === 1) {
      $messages[] = 'Agent restarted.';
      return true;
    }
    $errors[] = 'Could not restart the agent' . (count($output) ? (': ' . substr(trim(implode(' ', $output)), 0, 180)) : '.');
    return false;
  }
  run_cmd('bash ' . escapeshellarg($pluginDir . '/scripts/postStart.sh') . ' 2>&1', $output, $code);
  if ($code !== 0) {
    $errors[] = 'Could not start the agent' . (count($output) ? (': ' . substr(trim(implode(' ', $output)), 0, 180)) : '.');
    return false;
  }
  usleep(500000);
  run_cmd('pgrep -f ' . $supervisor . ' >/dev/null 2>&1', $output, $code);
  if ($code !== 0) {
    $errors[] = 'Could not start the agent. Check the log below, or reinstall ShowOps in Plugin Manager.';
    return false;
  }
  $messages[] = 'Agent started.';
  return true;
}

function config_flag_on($config, $key) {
  return isset($config[$key]) && $config[$key];
}

$messages = array();
$errors = array();
$logs = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
  $referer = isset($_SERVER['HTTP_REFERER']) ? (string)$_SERVER['HTTP_REFERER'] : '';
  $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
  $httpHost = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
  if (!showops_request_from_player($httpHost, $origin, $referer, $https)) {
    $errors[] = 'This page only accepts requests from this player.';
    $action = '';
  } else {
    $action = isset($_POST['action']) ? $_POST['action'] : '';
  }

  if ($action === 'signin') {
    // Sign-in from this page, passed on to ShowOps (see showops_forward_signin).
    $signinConfig = read_config($configPath);
    $signinCode = isset($signinConfig['pairing_code']) ? (string)$signinConfig['pairing_code'] : '';
    $alreadyConnected = !empty($signinConfig['device_id']) && !empty($signinConfig['device_token']);
    $payload = $alreadyConnected ? null
      : showops_signin_forward_body(isset($_POST['body']) ? (string)$_POST['body'] : '', $signinCode);
    if ($alreadyConnected) {
      $reply = array('error' => 'already_connected', 'user_message' => 'This player is already connected to ShowOps.');
    } elseif ($payload === null) {
      $reply = array('error' => 'pairing_not_pending', 'user_message' => 'Sign-in timed out. Try again.');
    } else {
      list($data, $httpStatus) = showops_forward_signin(showops_api_base_for_browser($signinConfig), $payload);
      unset($payload);
      $reply = $httpStatus > 0
        ? showops_signin_public_reply($data, $httpStatus)
        : array('error' => 'unreachable', 'user_message' => 'This player could not reach ShowOps. Check its internet connection and try again.');
    }
    unset($_POST['body']);
    echo showops_json_envelope($reply);
    exit;
  }

  if ($action === 'pair') {
    $currentForGuard = read_config($configPath);
    $alreadyConnected = !empty($currentForGuard['device_id']) && !empty($currentForGuard['device_token']);
    if ($alreadyConnected) {
      // Never drop a working connection from this button. Disconnect first.
      $errors[] = 'This player is already connected to ShowOps. Disconnect it first to connect it to another account.';
    } elseif (!ensure_agent_present($pluginDir, $messages, $errors)) {
      // keep error
    } else {
      $current = read_config($configPath);
      $apiBase = !empty($current['api_base_url']) ? $current['api_base_url'] : SHOWOPS_DEFAULT_API_BASE_URL;
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
            $started = restart_agent($serviceName, $fallbackScript, $pluginDir, $configPath, $pluginLogPath, $startMessages, $startErrors);
            if ($started) {
              $messages = array('Ready. Sign in below to add this player to ShowOps.');
              $errors = array();
            } else {
              // The code is saved, but nothing will pick it up until the agent runs.
              $messages = array();
              $errors = array_merge(
                array('The pairing code is saved, but the agent is not running, so this player will stay offline in ShowOps.'),
                $startErrors
              );
            }
          }
        }
      }
    }
  } elseif ($action === 'unpair') {
    // Always clear local credentials first. Cloud device may already be gone
    // (reinstall/replace), and the agent may not be installed to finish unpair.
    clear_local_enrollment($configPath);
    delete_enrollment_stash($mediaDir);
    run_cmd('pkill -f ' . SHOWOPS_AGENT_PROC . ' >/dev/null 2>&1; true', $output, $code);
    $messages[] = 'Disconnected on this player. Sign in again when you are ready to connect it.';
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

// The page's own scripts: a status check (GET) and the Sign in button (POST).
$wantsJson = isset($_REQUEST['format']) ? (string)$_REQUEST['format'] : '';
if ($wantsJson === 'status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
  echo showops_json_envelope(array('step' => showops_public_step($step)));
  exit;
}
if ($wantsJson === 'json' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  $reply = array('step' => showops_public_step($step));
  if ($step === 'claim' && empty($errors)) {
    $reply['connect_url'] = showops_connect_url($config, $pairingCode);
    // Tells the page a one-time code is ready for signing in right here.
    $reply['pairing_code'] = (string)$pairingCode;
  }
  if (!empty($errors)) {
    $reply['error'] = (string)$errors[0];
  }
  echo showops_json_envelope($reply);
  exit;
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

// "about 7 minutes" until a pairing code expires; '' when unknown or past.
function minutes_left_text($expiresAt) {
  $ts = strtotime((string)$expiresAt);
  if ($ts === false) {
    return '';
  }
  $mins = (int)ceil(($ts - time()) / 60);
  if ($mins <= 0) {
    return '';
  }
  return $mins === 1 ? 'about 1 minute' : 'about ' . $mins . ' minutes';
}

// "3 min ago" for the last check-in; the raw value when it does not parse.
function ago_text($when) {
  $ts = strtotime((string)$when);
  if ($ts === false) {
    return (string)$when;
  }
  $secs = max(0, time() - $ts);
  if ($secs < 90) {
    return 'just now';
  }
  if ($secs < 5400) {
    return round($secs / 60) . ' min ago';
  }
  if ($secs < 172800) {
    return round($secs / 3600) . ' h ago';
  }
  return date('M j', $ts);
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
/* Own layout for checkboxes and the QR row: FPP's theme sizes checkboxes
   bigger than Bootstrap's .form-check expects, so floats stair-step. */
.showops-page .showops-check {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  margin: 0 0 0.6rem 0;
  font-weight: normal;
  cursor: pointer;
}
.showops-page .showops-check input[type='checkbox'] {
  float: none;
  flex: 0 0 auto;
  margin: 0;
}
.showops-page .showops-qr-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1rem;
}
.showops-page .showops-qr-row .showops-qr-hint {
  flex: 1 1 12rem;
}
</style>

<div class="container-fluid showops-page px-0 px-sm-2" id="showops-root">

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
          <h3 class="h5">Setup did not finish</h3>
          <p class="text-body-secondary mb-2">The ShowOps agent was not downloaded. Make sure this player can reach the internet, then open Content Setup → Plugin Manager and click Update (or Reinstall) on ShowOps Agent. Come back here when it finishes.</p>
          <a class="btn btn-outline-secondary" href="plugins.php">Open Plugin Manager</a>

        <?php elseif ($step === 'pair' || $step === 'claim'): ?>
          <h3 class="h5">Connect this player to ShowOps</h3>
          <p class="text-body-secondary mb-3">Sign in with your ShowOps account and this player is added to it. Your password is only used to sign you in to ShowOps. This player never saves it.</p>
          <?php if ($rateLimited): ?>
            <div class="alert alert-warning">Too many tries. Wait about 2 minutes, then try once.</div>
          <?php elseif ($alreadyPairedCloud): ?>
            <div class="alert alert-warning">This player is still linked to a ShowOps account. Remove it under Devices in that account, then sign in again.</div>
          <?php endif; ?>
        <?php endif; ?>
      </form>

      <?php if ($step === 'pair' || $step === 'claim'): ?>
        <!-- Inputs have no name: without JavaScript nothing typed here is ever posted to the player. -->
        <div id="showops-signin" class="showops-signin" data-ready="0"<?php if ($step === 'claim' && $pairingCode !== ''): ?> data-code="<?php echo h($pairingCode); ?>"<?php endif; ?>>
          <form id="showops-signin-form" class="showops-signin-step" data-step="password" onsubmit="return false;" autocomplete="on">
            <div class="mb-2">
              <label class="form-label mb-1" for="showops-email">Email</label>
              <input class="form-control" type="email" id="showops-email" autocomplete="username" required>
            </div>
            <div class="mb-3">
              <label class="form-label mb-1" for="showops-password">Password</label>
              <input class="form-control" type="password" id="showops-password" autocomplete="current-password" required>
            </div>
            <button class="btn btn-success btn-lg" type="submit" id="showops-signin-button" <?php echo $rateLimited ? 'disabled' : ''; ?>>Sign in and connect</button>
          </form>

          <form id="showops-mfa-form" class="showops-signin-step" data-step="mfa" onsubmit="return false;" hidden>
            <p class="mb-2" id="showops-mfa-help">Enter the 6-digit code from your authenticator app.</p>
            <div class="mb-3" id="showops-mfa-code-row">
              <label class="form-label mb-1" for="showops-mfa-code">2-step code</label>
              <input class="form-control showops-code-input" type="text" id="showops-mfa-code" inputmode="numeric" autocomplete="one-time-code" maxlength="8" pattern="[0-9]*">
            </div>
            <div class="showops-actions">
              <button class="btn btn-success btn-lg" type="submit" id="showops-mfa-button">Confirm</button>
              <button class="btn btn-outline-secondary" type="button" id="showops-push-button" hidden>Approve on my phone instead</button>
            </div>
          </form>

          <form id="showops-choose-form" class="showops-signin-step" data-step="choose" onsubmit="return false;" hidden>
            <div class="mb-2" id="showops-account-row">
              <label class="form-label mb-1" for="showops-account">Account</label>
              <select class="form-select" id="showops-account"></select>
            </div>
            <div class="mb-3">
              <label class="form-label mb-1" for="showops-show">Show</label>
              <select class="form-select" id="showops-show"></select>
            </div>
            <button class="btn btn-success btn-lg" type="submit" id="showops-choose-button">Connect player</button>
          </form>

          <div id="showops-done" class="showops-signin-step" data-step="done" hidden>
            <div class="alert alert-success mb-0" role="status" id="showops-done-text">Connected. Finishing setup on this player…</div>
          </div>

          <div class="alert alert-danger mt-3 mb-0" id="showops-js-error" role="alert" hidden></div>
        </div>

        <?php if ($step === 'pair'): ?>
        <form method="post" class="mt-3">
          <p class="text-body-secondary small mb-1">No ShowOps account yet? <a href="https://showops.io/signup" target="_blank" rel="noopener noreferrer">Request one at showops.io</a>.</p>
          <p class="text-body-secondary small mb-0">Use Google, Apple, GitHub or a passkey to sign in?
            <button class="btn btn-link btn-sm p-0 align-baseline" type="submit" name="action" value="pair" id="showops-web-signin">Sign in on showops.io instead</button>
          </p>
        </form>
        <?php endif; ?>
      <?php endif; ?>

      <form method="post">
        <?php if ($step === 'pair' || $step === 'install'): ?>
          <?php /* shown above */ ?>
        <?php elseif ($step === 'claim'): ?>
          <?php $connectUrl = showops_connect_url($config, $pairingCode); ?>
          <details class="mt-3 mb-3" id="showops-web-details">
            <summary class="text-body-secondary">Signing in on showops.io or your phone instead?</summary>
            <div class="mt-2">
              <p class="text-body-secondary mb-2">Sign in there and this page turns green by itself within a minute.</p>
              <?php if ($connectUrl !== ''): ?>
                <div class="showops-actions mb-3">
                  <a class="btn btn-outline-success" id="showops-open-signin" href="<?php echo h($connectUrl); ?>" target="_blank" rel="noopener noreferrer">Open showops.io sign-in</a>
                </div>
                <div class="showops-qr-row">
                  <img id="showops-qr" data-url="<?php echo h($connectUrl); ?>" alt="QR code for signing in to ShowOps on a phone" class="border rounded bg-white p-1">
                  <div class="showops-qr-hint text-body-secondary small">On your phone: scan this with the camera or the ShowOps app and sign in there.</div>
                </div>
              <?php endif; ?>
              <?php $left = minutes_left_text($pairingExpires); ?>
              <p class="text-body-secondary small mb-1"><?php echo $left !== '' ? 'Works for ' . h($left) . '. ' : ''; ?>On another computer, go to <span class="font-monospace"><?php echo h(showops_connect_base_url($config)); ?>/connect</span> and enter:</p>
              <div class="showops-code fw-bold font-monospace mb-2"><?php echo h($pairingCode); ?></div>
            </div>
          </details>
          <div class="showops-actions">
            <button class="btn btn-outline-secondary" type="submit" name="action" value="pair">Start over</button>
            <button class="btn btn-outline-secondary" type="submit" name="action" value="restart">Restart agent</button>
          </div>

        <?php else: /* paired */ ?>
          <h3 class="h5">Connected to ShowOps</h3>
          <?php $claimedOrg = isset($config['claimed_org_name']) ? trim((string)$config['claimed_org_name']) : ''; ?>
          <?php if ($claimedOrg !== ''): ?>
            <p class="mb-1">ShowOps account: <?php echo h($claimedOrg); ?></p>
          <?php endif; ?>
          <p class="text-body-secondary small mb-3">
            Agent <?php echo h($running ? 'running' : 'stopped'); ?>
            · <?php echo h($agentVersion); ?>
            <?php if ($heartbeatTs !== ''): ?> · last check-in <?php echo h(ago_text($heartbeatTs)); ?><?php endif; ?>
          </p>
          <div class="showops-actions">
            <button class="btn btn-outline-secondary" type="submit" name="action" value="restart">Restart agent</button>
            <button class="btn btn-outline-danger" type="submit" name="action" value="unpair">Disconnect from ShowOps</button>
          </div>
        <?php endif; ?>
      </form>
      <?php if ($step === 'paired'): ?>
        <form method="post" class="mt-3">
          <input type="hidden" name="action" value="settings">
          <h3 class="h6 mt-2">Optional data and actions</h3>
          <p class="text-body-secondary small">Off until you turn them on. Monitoring of show status stays on.</p>
          <label class="showops-check" for="fpp_collect_enabled">
            <input type="checkbox" name="fpp_collect_enabled" id="fpp_collect_enabled" <?php echo config_flag_on($config, 'fpp_collect_enabled') ? 'checked' : ''; ?>>
            <span>Send diagnostics (plugins, network, logs)</span>
          </label>
          <label class="showops-check" for="location_enabled">
            <input type="checkbox" name="location_enabled" id="location_enabled" <?php echo config_flag_on($config, 'location_enabled') ? 'checked' : ''; ?>>
            <span>Send map location</span>
          </label>
          <label class="showops-check" for="backup_enabled">
            <input type="checkbox" name="backup_enabled" id="backup_enabled" <?php echo config_flag_on($config, 'backup_enabled') ? 'checked' : ''; ?>>
            <span>Allow configuration backup upload (passwords removed)</span>
          </label>
          <label class="showops-check" for="reboot_enabled">
            <input type="checkbox" name="reboot_enabled" id="reboot_enabled" <?php echo config_flag_on($config, 'reboot_enabled') ? 'checked' : ''; ?>>
            <span>Allow ShowOps to request an FPP reboot banner</span>
          </label>
          <button class="btn btn-outline-primary" type="submit">Save settings</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <details class="showops-muted-details mb-3">
    <summary class="text-body-secondary">Technical details</summary>
    <div class="card mt-2 border bg-body-tertiary">
      <div class="card-body small">
        <?php if ($deviceId !== ''): ?><div>Device ID: <span class="font-monospace"><?php echo h($deviceId); ?></span></div><?php endif; ?>
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
<script>
// Shared by the scripts below: this page fetched without FPP's menus.
// FPP still prints a few header bits on that path, so the reply sits between
// markers (see showops_json_envelope).
var showopsPage = (function () {
  function url(extra) {
    var params = new URLSearchParams(window.location.search);
    params.set('nopage', '1');
    Object.keys(extra).forEach(function (k) { params.set(k, extra[k]); });
    return window.location.pathname + '?' + params.toString();
  }
  function parse(text) {
    var start = text.indexOf('<?php echo SHOWOPS_JSON_START; ?>');
    var end = text.indexOf('<?php echo SHOWOPS_JSON_END; ?>');
    if (start < 0 || end < start) throw new Error('no reply');
    return JSON.parse(text.slice(start + <?php echo strlen(SHOWOPS_JSON_START); ?>, end));
  }
  function call(method, extra, body) {
    var opts = { method: method, credentials: 'same-origin', cache: 'no-store' };
    if (body) {
      opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
      opts.body = new URLSearchParams(body).toString();
    }
    return fetch(url(extra), opts).then(function (r) { return r.text(); }).then(parse);
  }
  function reload() { window.location.replace(window.location.pathname + window.location.search); }
  return { call: call, reload: reload };
})();
</script>
<?php if ($step === 'pair' || $step === 'claim'): ?>
<script>
(function () {
  // Sign in right here. The player passes the email and password on to
  // ShowOps over HTTPS without saving them, ShowOps links the player to the
  // account, and the agent then fetches its own device key, as before. No
  // ShowOps session or password ever stays on the player.
  var root = document.getElementById('showops-signin');
  var errorBox = document.getElementById('showops-js-error');
  if (!root || !window.fetch || !window.URLSearchParams || !window.JSON) return;
  root.setAttribute('data-ready', '1');

  var el = function (id) { return document.getElementById(id); };
  var session = { code: root.getAttribute('data-code') || '', ticket: '', accounts: [], pushTimer: null };

  function showStep(name) {
    var steps = root.querySelectorAll('.showops-signin-step');
    for (var i = 0; i < steps.length; i++) steps[i].hidden = steps[i].getAttribute('data-step') !== name;
  }
  function showError(message) {
    errorBox.textContent = message;
    errorBox.hidden = false;
  }
  function busy(button, on, label) {
    if (!button) return;
    if (on) { button.setAttribute('data-label', button.textContent); button.textContent = label || 'Working…'; }
    else if (button.getAttribute('data-label')) { button.textContent = button.getAttribute('data-label'); }
    button.disabled = on;
  }

  // A fresh one-time code from this player (kept while it is still good).
  function ensureCode(forceNew) {
    if (session.code && !forceNew) return Promise.resolve();
    return showopsPage.call('POST', { format: 'json' }, { action: 'pair' }).then(function (reply) {
      if (reply.error) throw new Error(reply.error);
      if (!reply.pairing_code) throw new Error('Could not start sign-in. Reload this page and try again.');
      session.code = reply.pairing_code;
    });
  }

  // FPP only lets this page talk to the player itself, so the player passes
  // the sign-in on to ShowOps (action=signin) and hands back the answer.
  function api(body) {
    return showopsPage.call('POST', { format: 'json' }, { action: 'signin', body: JSON.stringify(body) }).catch(function () {
      throw new Error('Could not reach this player. Check that it is still on, then try again.');
    });
  }

  function handle(reply, button) {
    busy(button, false);
    if (reply.status === 'claimed') return connected(reply);
    if (reply.status === 'mfa_required') return askMfa(reply);
    if (reply.status === 'choose') return askChoice(reply);
    if (reply.status === 'waiting') return;
    if (reply.error === 'pairing_not_pending' || reply.error === 'ticket_expired') {
      session.code = ''; session.ticket = '';
      showStep('password');
    }
    showError(reply.user_message || 'Sign-in did not work. Try again.');
  }

  function askMfa(reply) {
    session.ticket = reply.ticket;
    var methods = reply.methods || [];
    var hasCode = methods.indexOf('totp') >= 0;
    var hasPush = methods.indexOf('push') >= 0;
    el('showops-mfa-code-row').hidden = !hasCode;
    el('showops-mfa-button').hidden = !hasCode;
    el('showops-push-button').hidden = !(hasCode && hasPush);
    if (!hasCode || reply.push_sent) {
      el('showops-mfa-help').textContent = 'We sent a sign-in request to your phone. Approve it there and this page carries on by itself.';
      el('showops-push-button').hidden = true;
      pollPush();
    }
    showStep('mfa');
    if (hasCode && !reply.push_sent) el('showops-mfa-code').focus();
  }

  function pollPush() {
    if (session.pushTimer) clearTimeout(session.pushTimer);
    session.pushTimer = setTimeout(function () {
      api({ ticket: session.ticket, check_push: true }).then(function (reply) {
        if (reply.status === 'waiting') { pollPush(); return; }
        handle(reply);
      }).catch(function (e) { showError(e.message); });
    }, 2500);
  }

  function fillShows() {
    var account = session.accounts[el('showops-account').selectedIndex] || session.accounts[0];
    var show = el('showops-show');
    show.textContent = '';
    (account ? account.shows : []).forEach(function (s) {
      var opt = document.createElement('option');
      opt.value = s.location_id;
      opt.textContent = s.name;
      show.appendChild(opt);
    });
  }

  function askChoice(reply) {
    session.ticket = reply.ticket;
    session.accounts = reply.accounts || [];
    var account = el('showops-account');
    account.textContent = '';
    session.accounts.forEach(function (a) {
      var opt = document.createElement('option');
      opt.value = a.org_id;
      opt.textContent = a.name;
      account.appendChild(opt);
    });
    el('showops-account-row').hidden = session.accounts.length < 2;
    fillShows();
    showStep('choose');
  }

  function connected(reply) {
    if (session.pushTimer) clearTimeout(session.pushTimer);
    var where = reply.show_name ? (' to ' + reply.show_name) : '';
    el('showops-done-text').textContent = 'Connected' + where + '. Finishing setup on this player…';
    showStep('done');
    // Have the agent pick up its key now instead of waiting out its back-off.
    showopsPage.call('POST', { format: 'json' }, { action: 'restart' }).catch(function () {});
    var tries = 0;
    (function check() {
      showopsPage.call('GET', { format: 'status' }).then(function (r) {
        if (r.step === 'paired') { showopsPage.reload(); return; }
        tries += 1;
        if (tries === 30) el('showops-done-text').textContent = 'Connected' + where + '. Still waiting for this player to check in. If this takes more than a minute, click Restart agent below.';
        setTimeout(check, 2000);
      }).catch(function () { setTimeout(check, 3000); });
    })();
  }

  el('showops-signin-form').addEventListener('submit', function (event) {
    event.preventDefault();
    errorBox.hidden = true;
    var button = el('showops-signin-button');
    var email = el('showops-email').value.trim();
    var password = el('showops-password').value;
    if (!email || !password) { showError('Enter your email and password.'); return; }
    busy(button, true, 'Signing in…');
    ensureCode(false).then(function () {
      return api({ email: email, password: password });
    }).then(function (reply) {
      // A code that ran out: get a new one and try once more.
      if (reply.error === 'pairing_not_pending') {
        return ensureCode(true).then(function () { return api({ email: email, password: password }); });
      }
      return reply;
    }).then(function (reply) {
      el('showops-password').value = '';
      handle(reply, button);
    }).catch(function (e) {
      busy(button, false);
      showError(e.message);
    });
  });

  el('showops-mfa-form').addEventListener('submit', function (event) {
    event.preventDefault();
    errorBox.hidden = true;
    var button = el('showops-mfa-button');
    var code = el('showops-mfa-code').value.replace(/\s+/g, '');
    if (!/^[0-9]{6,8}$/.test(code)) { showError('Enter the 6-digit code from your authenticator app.'); return; }
    busy(button, true, 'Checking…');
    api({ ticket: session.ticket, mfa_code: code }).then(function (reply) {
      el('showops-mfa-code').value = '';
      handle(reply, button);
    }).catch(function (e) { busy(button, false); showError(e.message); });
  });

  el('showops-push-button').addEventListener('click', function () {
    errorBox.hidden = true;
    var button = el('showops-push-button');
    busy(button, true, 'Sending…');
    api({ ticket: session.ticket, start_push: true }).then(function (reply) {
      busy(button, false);
      if (reply.status === 'mfa_required') { reply.push_sent = true; askMfa(reply); return; }
      handle(reply);
    }).catch(function (e) { busy(button, false); showError(e.message); });
  });

  el('showops-account').addEventListener('change', fillShows);

  el('showops-choose-form').addEventListener('submit', function (event) {
    event.preventDefault();
    errorBox.hidden = true;
    var button = el('showops-choose-button');
    busy(button, true, 'Connecting…');
    api({ ticket: session.ticket, org_id: el('showops-account').value, location_id: el('showops-show').value })
      .then(function (reply) { handle(reply, button); })
      .catch(function (e) { busy(button, false); showError(e.message); });
  });

  // "Sign in on showops.io instead": open the tab on the click (so pop-up
  // blockers allow it), then point it at this player's one-time link.
  var web = el('showops-web-signin');
  if (web) web.addEventListener('click', function (event) {
    event.preventDefault();
    var tab = window.open('', '_blank');
    if (tab) { try { tab.opener = null; } catch (e) {} }
    ensureCode(true).then(function () {
      return showopsPage.call('GET', { format: 'status' });
    }).then(function () {
      if (tab) tab.location.replace('<?php echo h(showops_connect_base_url($config)); ?>/connect#code=' + encodeURIComponent(session.code));
      showopsPage.reload();
    }).catch(function (e) {
      if (tab) tab.close();
      showError(e.message);
    });
  });
})();
</script>
<?php endif; ?>
<?php if ($step === 'claim'): ?>
<script>
<?php readfile(__DIR__ . '/www/js/qrcode.js'); ?>

(function () {
  // Draw the sign-in link as a QR code (a GIF data URL, no markup injected).
  var img = document.getElementById('showops-qr');
  if (!img || typeof qrcode !== 'function') return;
  try {
    var qr = qrcode(0, 'M');
    qr.addData(img.getAttribute('data-url'));
    qr.make();
    img.src = qr.createDataURL(5, 2);
  } catch (e) {
    img.style.display = 'none';
  }
})();
(function () {
  // Check every 3 seconds and reload (a plain GET, never a re-POST) once the
  // player is connected or the link ran out. Falls back to the old 15-second
  // reload if the check itself does not work.
  if (!window.fetch || !window.URLSearchParams) {
    window.setTimeout(showopsPage.reload, 15000);
    return;
  }
  var failures = 0;
  var lastNudge = Date.now();
  function check() {
    if (document.hidden) { window.setTimeout(check, 3000); return; }
    showopsPage.call('GET', { format: 'status' }).then(function (reply) {
      failures = 0;
      if (reply.step !== 'claim') { showopsPage.reload(); return; }
      window.setTimeout(check, 3000);
    }).catch(function () {
      failures += 1;
      if (failures >= 5) { window.setTimeout(showopsPage.reload, 15000); return; }
      window.setTimeout(check, 3000);
    });
  }
  window.setTimeout(check, 3000);
  // Back from the sign-in tab: have the agent look right away instead of
  // waiting out its back-off (up to a minute). At most every 30 seconds.
  document.addEventListener('visibilitychange', function () {
    if (document.hidden || Date.now() - lastNudge < 30000) return;
    lastNudge = Date.now();
    showopsPage.call('POST', { format: 'json' }, { action: 'restart' }).catch(function () {});
  });
})();
</script>
<?php endif; ?>
