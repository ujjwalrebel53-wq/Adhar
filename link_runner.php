<?php
/**
 * ╔═══════════════════════════════════════════════════════════╗
 * ║          REBEL LINK RUNNER — Standalone Edition           ║
 * ║  Specific links run karke unke responses Telegram pe      ║
 * ║  reflect karta hai. Separate script, separate config.     ║
 * ╚═══════════════════════════════════════════════════════════╝
 *
 * Usage modes:
 *   1. Web UI  → open link_runner.php in browser (admin panel)
 *   2. Run now → ?run=1&secret=YOUR_SECRET   (trigger via URL)
 *   3. Cron    → php link_runner.php          (CLI scheduler)
 *   4. Webhook → ?webhook=1 (Telegram bot receives /run command)
 *
 * Config: Edit CONFIG section below or use the web UI.
 */

// ─── compat shims ───────────────────────────────────────────
if (!function_exists('str_starts_with')) {
    function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_contains')) {
    function str_contains($h, $n) { return strpos($h, $n) !== false; }
}

// ────────────────────────────────────────────────────────────
//  ███████╗ ██████╗ ███╗   ██╗███████╗██╗ ██████╗
//  ██╔════╝██╔════╝ ████╗  ██║██╔════╝██║██╔════╝
//  ██║     ██║  ███╗██╔██╗ ██║█████╗  ██║██║  ███╗
//  ██║     ██║   ██║██║╚██╗██║██╔══╝  ██║██║   ██║
//  ╚██████╗╚██████╔╝██║ ╚████║██║     ██║╚██████╔╝
//   ╚═════╝ ╚═════╝ ╚═╝  ╚═══╝╚═╝     ╚═╝ ╚═════╝
// ────────────────────────────────────────────────────────────

define('LR_VERSION', '1.2');
define('LR_CONFIG_FILE', __DIR__ . '/lr_config.json');
define('LR_LOG_FILE',    __DIR__ . '/lr_logs.json');
define('LR_TG_BASE',     'https://api.telegram.org/bot');
define('LR_CURL_TO',     30);
define('LR_SS_DIR',      __DIR__ . '/lr_screenshots/');

// ─── Default config (loaded from lr_config.json if exists) ──
$defaultConfig = [
    'admin_pass'   => 'rebel@2026',   // Change after first login!
    'run_secret'   => 'changeme123',  // Secret for ?run=1&secret=X
    'bot_token'    => '',             // Telegram bot token
    'chat_id'      => '',             // Default chat/channel to send to
    'send_prefix'  => '🔗 <b>Link Runner</b>\n\n', // Prefix for messages
    'links'        => [],             // Array of link rules (see addLink())
    'webhook_token'=> '',             // Bot token for webhook mode
    'webhook_cmd'  => '/run',         // Command that triggers a run
];

if (!is_dir(LR_SS_DIR)) @mkdir(LR_SS_DIR, 0755, true);

// ─── Load/save config ───────────────────────────────────────
function lrLoadConfig() {
    global $defaultConfig;
    if (!file_exists(LR_CONFIG_FILE)) return $defaultConfig;
    $loaded = json_decode(file_get_contents(LR_CONFIG_FILE), true);
    if (!is_array($loaded)) return $defaultConfig;
    return array_merge($defaultConfig, $loaded);
}
function lrSaveConfig($cfg) {
    file_put_contents(LR_CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ─── Logging ────────────────────────────────────────────────
function lrLog($text, $type = 'info') {
    $logs = file_exists(LR_LOG_FILE) ? (json_decode(file_get_contents(LR_LOG_FILE), true) ?: []) : [];
    array_unshift($logs, ['time' => date('c'), 'text' => $text, 'type' => $type]);
    if (count($logs) > 300) $logs = array_slice($logs, 0, 300);
    file_put_contents(LR_LOG_FILE, json_encode($logs, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ─── Telegram sender ────────────────────────────────────────
function lrTg($method, $params, $token) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => LR_TG_BASE . $token . '/' . $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($params),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $r = curl_exec($ch);
    curl_close($ch);
    return json_decode($r, true) ?: [];
}

function lrSend($token, $chatId, $text) {
    if (!$token || !$chatId || !trim($text)) return false;
    $chunks = lrChunk($text, 4000);
    $ok = true;
    foreach ($chunks as $chunk) {
        $r = lrTg('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => $chunk,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ], $token);
        if (!($r['ok'] ?? false)) $ok = false;
    }
    return $ok;
}

function lrChunk($text, $maxLen = 4000) {
    $chunks = [];
    while (mb_strlen($text) > $maxLen) {
        $pos = mb_strrpos(mb_substr($text, 0, $maxLen), "\n");
        if ($pos === false) $pos = $maxLen;
        $chunks[] = mb_substr($text, 0, $pos);
        $text = mb_substr($text, $pos);
    }
    if (trim($text) !== '') $chunks[] = $text;
    return $chunks ?: [''];
}

// ─── cURL fetcher ───────────────────────────────────────────
function lrFetch($url, $method = 'GET', $headers = '', $body = '', $timeout = 30, $sslVerify = true) {
    $hdrs = [];
    if ($headers) {
        foreach (explode("\n", $headers) as $h) {
            $h = trim($h);
            if ($h && strpos($h, ':') !== false) $hdrs[] = $h;
        }
    }
    if (empty($hdrs)) {
        $hdrs = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
            'Accept: application/json,text/html,*/*',
        ];
    }
    $ch = curl_init();
    $o = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => $sslVerify,
        CURLOPT_SSL_VERIFYHOST => $sslVerify ? 2 : 0,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_HTTPHEADER     => $hdrs,
    ];
    $m = strtoupper($method);
    if ($m === 'POST') {
        $o[CURLOPT_POST]       = true;
        $o[CURLOPT_POSTFIELDS] = $body;
    } elseif ($m !== 'GET') {
        $o[CURLOPT_CUSTOMREQUEST] = $m;
        if ($body) $o[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $o);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $res ?: '', 'error' => $err];
}

// ─── JSON path extractor ────────────────────────────────────
function lrJsonPath($data, $path) {
    if (empty($path)) return is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : (string)$data;
    foreach (explode('.', $path) as $k) {
        if (is_array($data) && isset($data[$k])) $data = $data[$k];
        elseif (is_array($data) && is_numeric($k) && isset($data[(int)$k])) $data = $data[(int)$k];
        else return null;
    }
    return is_array($data) ? json_encode($data, JSON_UNESCAPED_UNICODE) : (string)$data;
}

function lrFlatten($data, $prefix = '', $map = []) {
    if (!is_array($data)) {
        if ($prefix !== '') $map[$prefix] = (string)$data;
        return $map;
    }
    foreach ($data as $k => $v) {
        $full = $prefix !== '' ? $prefix . '.' . $k : (string)$k;
        if (is_array($v)) $map = lrFlatten($v, $full, $map);
        else { $map[$full] = (string)$v; if (!isset($map[$k])) $map[$k] = (string)$v; }
    }
    return $map;
}

// ─── Variable replacement ────────────────────────────────────
function lrReplace($text, $vars) {
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string)$v, $text);
    }
    return $text;
}

// ─── Screenshot via free external APIs (no browser install needed) ──
// Tries multiple free screenshot services in order until one works.
function lrGetScreenshotUrl($targetUrl) {
    $enc = urlencode($targetUrl);
    // List of free screenshot API endpoints (no key needed)
    return [
        // screenshotone free tier (no API key, limited)
        'https://api.screenshotone.com/take?url=' . $enc . '&viewport_width=1280&viewport_height=900&format=png&timeout=30',
        // thum.io – simple, no key
        'https://image.thum.io/get/width/1280/crop/900/' . $enc,
        // Microlink – free, no key
        'https://api.microlink.io/?url=' . $enc . '&screenshot=true&meta=false&embed=screenshot.url',
        // htmlcsstoimage – URL passthrough screenshot
        'https://hcti.io/v1/image?url=' . $enc,
    ];
}

function lrTakeScreenshot($url, $token, $chatId, $caption, $timeout = 30) {
    if (!$token || !$chatId) return false;

    // noanimate/ forces a static PNG (no animated GIF stream); URL appended raw per thum.io docs
    $thumbUrl = 'https://image.thum.io/get/width/1280/crop/900/png/noanimate/' . $url;
    $r = lrTg('sendPhoto', [
        'chat_id'    => $chatId,
        'photo'      => $thumbUrl,
        'caption'    => $caption,
        'parse_mode' => 'HTML',
    ], $token);
    lrLog('SS-DEBUG Telegram sendPhoto → ' . ($r['ok'] ? 'ok' : ('FAIL: ' . ($r['description'] ?? json_encode($r)))), $r['ok'] ? 'info' : 'error');
    return !empty($r['ok']);
}

// ─── RUN all link rules and return results ──────────────────
function lrRunAll($cfg, $extraVars = []) {
    $results = [];
    $links   = $cfg['links'] ?? [];

    foreach ($links as $link) {
        if (empty($link['enabled'])) continue;
        $id    = $link['id'] ?? uniqid('lr_');
        $name  = $link['name'] ?? $id;
        $url   = trim($link['url'] ?? '');
        if (!$url) continue;

        $vars = array_merge([
            'ts'   => date('Y-m-d H:i:s'),
            'date' => date('Y-m-d'),
            'time' => date('H:i:s'),
        ], $extraVars);

        $url     = lrReplace($url,                   $vars);
        $headers = lrReplace($link['headers'] ?? '',  $vars);
        $body    = lrReplace($link['body'] ?? '',     $vars);
        $timeout = max(5, min(120, (int)($link['timeout'] ?? 30)));
        $ssl     = !isset($link['ssl_verify']) || (bool)$link['ssl_verify'];

        // Override chat_id per link if set
        $chatId = trim($link['chat_id'] ?? '') ?: trim($cfg['chat_id'] ?? '');
        $token  = trim($cfg['bot_token'] ?? '');

        // ── Screenshot mode ──────────────────────────────────
        $useScreenshot = !empty($link['screenshot_mode']);
        if ($useScreenshot) {
            $ssCaption = lrReplace(
                $link['screenshot_caption'] ?? "📸 <b>{name}</b>\n🌐 <code>{url}</code>\n🕐 {ts}",
                array_merge($vars, [
                    'name' => htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8'),
                    'url'  => htmlspecialchars($url,  ENT_NOQUOTES, 'UTF-8'),
                ])
            );
            $sent = false;
            if ($token && $chatId) {
                $sent = lrTakeScreenshot($url, $token, $chatId, $ssCaption, $timeout);
            }
            $results[] = [
                'id'        => $id,
                'name'      => $name,
                'url'       => $url,
                'code'      => 0,
                'failed'    => !$sent,
                'extracted' => $sent ? '[screenshot sent]' : '[screenshot failed]',
                'sent'      => $sent,
                'msg'       => $ssCaption,
                'mode'      => 'screenshot',
            ];
            lrLog(($sent ? "SS OK [{$id}]" : "SS FAIL [{$id}]") . " → " . $name, $sent ? 'success' : 'error');
            continue;
        }

        // ── Normal cURL mode ─────────────────────────────────
        $result  = lrFetch($url, $link['method'] ?? 'GET', $headers, $body, $timeout, $ssl);
        $rawBody = $result['body'] ?? '';
        $code    = $result['code'] ?? 0;

        // Extract value
        $extracted   = null;
        $respPath    = trim($link['response_path'] ?? '');
        $respData    = json_decode($rawBody, true);

        if ($respPath !== '' && $respData !== null) {
            $extracted = lrJsonPath($respData, $respPath);
        }
        if ($extracted === null && is_array($respData)) {
            foreach (['result', 'response', 'text', 'content', 'answer', 'message', 'output', 'data', 'value'] as $fk) {
                if (isset($respData[$fk]) && is_string($respData[$fk]) && trim($respData[$fk]) !== '') {
                    $extracted = $respData[$fk];
                    break;
                }
            }
        }
        if ($extracted === null) $extracted = $rawBody;

        $failed = ($code >= 400 || $extracted === null || $extracted === '');

        // Build reply text from template
        $replyTpl = $link['reply_template'] ?? "📌 <b>{name}</b>\n{response}";
        $allVars  = array_merge($vars, [
            'name'          => htmlspecialchars($name, ENT_NOQUOTES, 'UTF-8'),
            'url'           => htmlspecialchars($url,  ENT_NOQUOTES, 'UTF-8'),
            'http_code'     => $code,
            'response'      => htmlspecialchars((string)$extracted, ENT_NOQUOTES, 'UTF-8'),
            'result'        => htmlspecialchars((string)$extracted, ENT_NOQUOTES, 'UTF-8'),
            'curl_response' => htmlspecialchars((string)$extracted, ENT_NOQUOTES, 'UTF-8'),
            'raw'           => htmlspecialchars($rawBody, ENT_NOQUOTES, 'UTF-8'),
            'status'        => $failed ? '❌ FAILED' : '✅ OK',
            'error'         => htmlspecialchars($result['error'] ?? '', ENT_NOQUOTES, 'UTF-8'),
        ]);
        if (is_array($respData)) {
            $flat = lrFlatten($respData);
            uksort($flat, fn($a, $b) => strlen($b) - strlen($a));
            foreach ($flat as $fk => $fv) {
                $allVars[$fk] = htmlspecialchars((string)$fv, ENT_NOQUOTES, 'UTF-8');
            }
        }

        $msgText = lrReplace($replyTpl, $allVars);

        // Send if enabled
        $sent = false;
        if (!$failed && $token && $chatId) {
            $prefix = str_replace('\n', "\n", $cfg['send_prefix'] ?? '');
            $sent = lrSend($token, $chatId, $prefix . $msgText);
        } elseif ($failed && !empty($link['send_on_error']) && $token && $chatId) {
            $errTpl = $link['error_message'] ?? '⚠️ <b>{name}</b>\n\nURL: <code>{url}</code>\nHTTP: <code>{http_code}</code>\nError: <code>{error}</code>';
            $errMsg = lrReplace($errTpl, $allVars);
            lrSend($token, $chatId, $errMsg);
        }

        $results[] = [
            'id'        => $id,
            'name'      => $name,
            'url'       => $url,
            'code'      => $code,
            'failed'    => $failed,
            'extracted' => $extracted,
            'sent'      => $sent,
            'msg'       => $msgText,
            'mode'      => 'curl',
        ];

        lrLog(($failed ? "FAIL [{$id}] HTTP {$code}" : "OK [{$id}] HTTP {$code}") . " → " . $name, $failed ? 'error' : 'success');
    }

    return $results;
}

// ─── Auth ────────────────────────────────────────────────────
session_start();
function lrSanitize($v) { return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8'); }

$cfg = lrLoadConfig();
$isLoggedIn = !empty($_SESSION['lr_ok']);

// ─── Webhook mode ────────────────────────────────────────────
if (isset($_GET['webhook'])) {
    $wToken = trim($cfg['webhook_token'] ?: $cfg['bot_token']);
    $update = json_decode(file_get_contents('php://input'), true);
    if (!is_array($update)) { http_response_code(200); exit; }
    $msg  = $update['message'] ?? $update['channel_post'] ?? null;
    if (!$msg) { http_response_code(200); exit; }
    $text   = trim($msg['text'] ?? '');
    $chatId = $msg['chat']['id'] ?? '';
    $cmd    = trim($cfg['webhook_cmd'] ?? '/run');
    if (str_starts_with(strtolower($text), strtolower($cmd))) {
        lrTg('sendMessage', ['chat_id' => $chatId, 'text' => '⏳ Running links...', 'parse_mode' => 'HTML'], $wToken);
        $results = lrRunAll($cfg, ['tg_chat' => $chatId]);
        $ok  = count(array_filter($results, fn($r) => !$r['failed']));
        $tot = count($results);
        lrTg('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "✅ <b>Link Runner Done!</b>\n\n📊 Results: <code>$ok/$tot</code> success",
            'parse_mode' => 'HTML',
        ], $wToken);
    }
    http_response_code(200);
    exit;
}

// ─── URL trigger (?run=1&secret=X) ──────────────────────────
if (isset($_GET['run'])) {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== $cfg['run_secret']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid secret']);
        exit;
    }
    header('Content-Type: application/json');
    $results = lrRunAll($cfg);
    echo json_encode(['ok' => true, 'results' => $results, 'total' => count($results),
        'success' => count(array_filter($results, fn($r) => !$r['failed']))]);
    exit;
}

// ─── CLI mode ────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    echo "=== Rebel Link Runner v" . LR_VERSION . " ===\n";
    $results = lrRunAll($cfg);
    foreach ($results as $r) {
        $icon = $r['failed'] ? '❌' : '✅';
        echo "$icon [{$r['id']}] {$r['name']} → HTTP {$r['code']}" . ($r['sent'] ? ' | Sent' : '') . "\n";
    }
    echo "\nDone. Total: " . count($results) . "\n";
    exit(0);
}

// ─── Admin API calls ─────────────────────────────────────────
if (isset($_GET['api_action'])) {
    header('Content-Type: application/json');
    $act = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['api_action'] ?? '');

    if ($act === 'login') {
        $pass = $_POST['pass'] ?? '';
        if ($pass === $cfg['admin_pass']) {
            $_SESSION['lr_ok'] = true;
            echo json_encode(['ok' => true]); exit;
        }
        echo json_encode(['ok' => false, 'error' => 'Wrong password']); exit;
    }

    if (!$isLoggedIn) { echo json_encode(['ok' => false, 'error' => 'Not logged in']); exit; }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    switch ($act) {
        case 'logout':
            session_unset(); session_destroy();
            echo json_encode(['ok' => true]); exit;

        case 'get_config':
            $safe = $cfg;
            unset($safe['admin_pass']); // never expose password
            echo json_encode(['ok' => true, 'data' => $safe]); exit;

        case 'save_config':
            $newCfg = $cfg;
            if (isset($body['bot_token']))    $newCfg['bot_token']    = trim($body['bot_token']);
            if (isset($body['chat_id']))      $newCfg['chat_id']      = trim($body['chat_id']);
            if (isset($body['send_prefix']))  $newCfg['send_prefix']  = $body['send_prefix'];
            if (isset($body['run_secret']))   $newCfg['run_secret']   = trim($body['run_secret']);
            if (isset($body['webhook_token']))$newCfg['webhook_token']= trim($body['webhook_token']);
            if (isset($body['webhook_cmd']))  $newCfg['webhook_cmd']  = trim($body['webhook_cmd']);
            if (!empty($body['new_pass']) && strlen(trim($body['new_pass'])) >= 4) {
                $newCfg['admin_pass'] = trim($body['new_pass']);
            }
            lrSaveConfig($newCfg);
            $cfg = $newCfg;
            lrLog('Config saved', 'info');
            echo json_encode(['ok' => true]); exit;

        case 'save_links':
            $links = [];
            foreach ($body['links'] ?? [] as $lk) {
                $url = trim($lk['url'] ?? '');
                if (!$url) continue;
                $links[] = [
                    'id'             => preg_replace('/[^a-zA-Z0-9_]/', '_', $lk['id'] ?? uniqid('l_')),
                    'name'           => trim($lk['name'] ?? 'Link'),
                    'enabled'        => (bool)($lk['enabled'] ?? true),
                    'url'            => $url,
                    'method'         => strtoupper(trim($lk['method'] ?? 'GET')),
                    'headers'        => trim($lk['headers'] ?? ''),
                    'body'           => trim($lk['body'] ?? ''),
                    'timeout'        => max(5, min(120, (int)($lk['timeout'] ?? 30))),
                    'ssl_verify'     => !isset($lk['ssl_verify']) || (bool)$lk['ssl_verify'],
                    'response_path'  => trim($lk['response_path'] ?? ''),
                    'reply_template' => trim($lk['reply_template'] ?? "📌 <b>{name}</b>\n\n{response}"),
                    'error_message'  => trim($lk['error_message'] ?? '⚠️ <b>{name}</b> failed!\nHTTP: <code>{http_code}</code>'),
                    'send_on_error'      => (bool)($lk['send_on_error'] ?? false),
                    'chat_id'            => trim($lk['chat_id'] ?? ''),
                    'screenshot_mode'    => (bool)($lk['screenshot_mode'] ?? false),
                    'screenshot_caption' => trim($lk['screenshot_caption'] ?? "📸 <b>{name}</b>\n🌐 <code>{url}</code>\n🕐 {ts}"),
                ];
            }
            $cfg['links'] = $links;
            lrSaveConfig($cfg);
            lrLog('Links saved — ' . count($links) . ' rule(s)', 'info');
            echo json_encode(['ok' => true, 'count' => count($links)]); exit;

        case 'run_now':
            $results = lrRunAll($cfg);
            lrLog('Manual run — ' . count($results) . ' link(s)', 'info');
            echo json_encode(['ok' => true, 'results' => $results,
                'success' => count(array_filter($results, fn($r) => !$r['failed']))]); exit;

        case 'run_single':
            $linkId = trim($body['link_id'] ?? '');
            $singleLink = null;
            foreach ($cfg['links'] as $lk) {
                if (($lk['id'] ?? '') === $linkId) { $singleLink = $lk; break; }
            }
            if (!$singleLink) { echo json_encode(['ok' => false, 'error' => 'Link not found']); exit; }
            $tmpCfg = $cfg; $tmpCfg['links'] = [$singleLink];
            $results = lrRunAll($tmpCfg);
            echo json_encode(['ok' => true, 'result' => $results[0] ?? null]); exit;

        case 'test_link':
            $url     = trim($body['url'] ?? '');
            $method  = strtoupper(trim($body['method'] ?? 'GET'));
            $headers = trim($body['headers'] ?? '');
            $bdy     = trim($body['body'] ?? '');
            $timeout = max(5, min(60, (int)($body['timeout'] ?? 15)));
            if (!$url) { echo json_encode(['ok' => false, 'error' => 'URL required']); exit; }
            $r = lrFetch($url, $method, $headers, $bdy, $timeout);
            echo json_encode(['ok' => true, 'code' => $r['code'], 'body' => mb_substr($r['body'], 0, 2000), 'error' => $r['error']]); exit;

        case 'get_logs':
            $logs = file_exists(LR_LOG_FILE) ? (json_decode(file_get_contents(LR_LOG_FILE), true) ?: []) : [];
            echo json_encode(['ok' => true, 'data' => array_slice($logs, 0, 100)]); exit;

        case 'clear_logs':
            file_put_contents(LR_LOG_FILE, '[]', LOCK_EX);
            echo json_encode(['ok' => true]); exit;

        case 'set_webhook':
            $wToken = trim($cfg['webhook_token'] ?: $cfg['bot_token']);
            if (!$wToken) { echo json_encode(['ok' => false, 'error' => 'Bot token not set']); exit; }
            $pr  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $wUrl = $pr . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . '?webhook=1';
            $r = lrTg('setWebhook', ['url' => $wUrl, 'allowed_updates' => ['message', 'channel_post']], $wToken);
            echo json_encode(['ok' => $r['ok'] ?? false, 'webhook_url' => $wUrl, 'tg' => $r]); exit;

        case 'remove_webhook':
            $wToken = trim($cfg['webhook_token'] ?: $cfg['bot_token']);
            if (!$wToken) { echo json_encode(['ok' => false, 'error' => 'Bot token not set']); exit; }
            $r = lrTg('deleteWebhook', [], $wToken);
            echo json_encode(['ok' => $r['ok'] ?? false]); exit;

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit;
    }
}

// ─── HTML UI ─────────────────────────────────────────────────
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rebel Link Runner <?= LR_VERSION ?></title>
<style>
:root{--bg:#0e0e12;--s1:#15151c;--s2:#1c1c26;--b:#2a2a3a;--t:#e8e8f0;--td:#8888aa;--tf:#555577;--c:#7c7cff;--g:#39ff14;--r:#ff4466;--y:#ffd700;--or:#ff8c00}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--t);font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;min-height:100vh}
.wrap{max-width:900px;margin:0 auto;padding:20px 16px}
h1{color:var(--c);font-size:22px;margin-bottom:4px}
.sub{color:var(--td);font-size:12px;margin-bottom:20px}
.card{background:var(--s1);border:1px solid var(--b);border-radius:12px;padding:18px;margin-bottom:16px}
.card h2{font-size:15px;color:var(--c);margin-bottom:14px;display:flex;align-items:center;gap:6px}
.row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px}
label{display:block;color:var(--td);font-size:11px;margin-bottom:4px}
input,select,textarea{width:100%;background:var(--s2);border:1px solid var(--b);color:var(--t);border-radius:6px;padding:8px 10px;font-size:13px;font-family:inherit;outline:none;transition:border .2s}
input:focus,select:focus,textarea:focus{border-color:var(--c)}
textarea{resize:vertical;min-height:60px;font-family:'Share Tech Mono',monospace;font-size:12px}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-weight:600;transition:.18s}
.bc{background:var(--c);color:#000}.bc:hover{background:#9090ff}
.bg{background:var(--g);color:#000}.bg:hover{opacity:.85}
.br{background:var(--r);color:#fff}.br:hover{opacity:.85}
.by{background:var(--y);color:#000}.by:hover{opacity:.85}
.bor{background:var(--or);color:#fff}.bor:hover{opacity:.85}
.bgr{background:var(--s2);color:var(--t);border:1px solid var(--b)}.bgr:hover{border-color:var(--c)}
.bsm{padding:5px 10px;font-size:11px}
.badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700}
.ba{background:rgba(57,255,20,.15);color:var(--g);border:1px solid rgba(57,255,20,.3)}
.bi{background:rgba(255,68,102,.15);color:var(--r);border:1px solid rgba(255,68,102,.3)}
.link-card{background:var(--s2);border:1px solid var(--b);border-radius:8px;padding:14px;margin-bottom:10px;position:relative}
.link-card .link-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:6px}
.link-card .link-name{font-weight:600;color:var(--t)}
.link-card .link-url{font-size:11px;color:var(--td);font-family:monospace;word-break:break-all}
.log-box{background:var(--s2);border:1px solid var(--b);border-radius:8px;padding:12px;max-height:300px;overflow-y:auto;font-family:'Share Tech Mono',monospace;font-size:11px}
.log-entry{padding:2px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.log-ok{color:var(--g)}.log-err{color:var(--r)}.log-info{color:var(--c)}
.toast{position:fixed;bottom:24px;right:24px;background:var(--s1);border:1px solid var(--b);border-radius:8px;padding:12px 18px;font-size:13px;z-index:999;transition:.3s;opacity:0;pointer-events:none}
.toast.show{opacity:1;pointer-events:all}
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-box{background:var(--s1);border:1px solid var(--b);border-radius:14px;padding:32px;width:320px;text-align:center}
.login-box h2{color:var(--c);margin-bottom:6px}
.login-box p{color:var(--td);font-size:12px;margin-bottom:20px}
.flex-end{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
.f1{flex:1}
.mono{font-family:'Share Tech Mono',monospace;font-size:11px}
.result-box{background:var(--s2);border:1px solid var(--b);border-radius:6px;padding:10px;margin-top:10px;font-size:12px;display:none}
.switch{display:inline-flex;align-items:center;gap:6px;cursor:pointer}
.switch input{width:auto}
.tag{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;background:rgba(124,124,255,.15);color:var(--c);border:1px solid rgba(124,124,255,.3)}
.collapsed .link-body{display:none}
.chevron{cursor:pointer;user-select:none;font-size:16px;color:var(--td);transition:.2s}
.link-card.collapsed .chevron{transform:rotate(-90deg)}
@media(max-width:600px){.row{flex-direction:column}}
</style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<!-- ─── Login Screen ─────────────────────────────────────── -->
<div class="login-wrap">
  <div class="login-box">
    <h2>🔗 Link Runner</h2>
    <p>Rebel Admin — Link Automation Panel</p>
    <div style="margin-bottom:12px"><label>Password</label><input type="password" id="lpass" placeholder="Enter password" onkeydown="if(event.key==='Enter')doLogin()"></div>
    <button class="btn bc" style="width:100%" onclick="doLogin()">🔓 Login</button>
    <div id="lerr" style="color:var(--r);font-size:12px;margin-top:10px"></div>
  </div>
</div>
<script>
async function doLogin(){
  const p=document.getElementById('lpass').value;
  const fd=new FormData();fd.append('pass',p);
  const r=await fetch('?api_action=login',{method:'POST',body:fd}).then(x=>x.json());
  if(r.ok) location.reload();
  else document.getElementById('lerr').textContent=r.error||'Wrong password';
}
document.getElementById('lpass').focus();
</script>
</body></html>
<?php exit; endif; ?>

<!-- ─── Main Panel ─────────────────────────────────────────── -->
<div class="wrap">
  <h1>🔗 Rebel Link Runner <small style="font-size:13px;color:var(--td)">v<?= LR_VERSION ?></small></h1>
  <div class="sub">Specific links run karo aur responses Telegram pe bhejo | <a href="?api_action=logout" style="color:var(--r)">Logout</a></div>

  <!-- Status Bar -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
    <button class="btn bg" onclick="runAll()">▶️ Run All Now</button>
    <button class="btn bc" onclick="savePage()">💾 Save All</button>
    <button class="btn bgr" onclick="loadLogs()">📋 Refresh Logs</button>
    <button class="btn bgr" onclick="clearLogs()">🗑️ Clear Logs</button>
    <span id="run-status" style="line-height:34px;font-size:12px;color:var(--td)"></span>
  </div>

  <!-- Global Config Card -->
  <div class="card" id="cfg-card">
    <h2>⚙️ Global Config</h2>
    <div class="row">
      <div class="f1"><label>🤖 Bot Token</label><input type="password" id="cfg-token" placeholder="123456789:ABC..."></div>
      <div class="f1"><label>💬 Default Chat ID</label><input type="text" id="cfg-chat" placeholder="-100xxxx or @channel"></div>
    </div>
    <div class="row">
      <div class="f1"><label>📝 Message Prefix (use \n for newline)</label><input type="text" id="cfg-prefix" placeholder="🔗 &lt;b&gt;Link Runner&lt;/b&gt;\n\n"></div>
      <div class="f1"><label>🔑 URL Run Secret (?run=1&amp;secret=X)</label><input type="text" id="cfg-secret" placeholder="changeme123"></div>
    </div>
    <div class="row">
      <div class="f1"><label>🔔 Webhook Bot Token (optional, leave blank to use main token)</label><input type="text" id="cfg-wtoken" placeholder="Same or different token"></div>
      <div class="f1"><label>📟 Webhook Trigger Command</label><input type="text" id="cfg-wcmd" placeholder="/run"></div>
    </div>
    <div class="row">
      <div class="f1"><label>🔒 Change Admin Password (min 4 chars)</label><input type="password" id="cfg-newpass" placeholder="Leave blank to keep current"></div>
    </div>
    <div class="flex-end">
      <button class="btn bgr bsm" onclick="setWebhook()">🔗 Set Webhook</button>
      <button class="btn bgr bsm" onclick="removeWebhook()">❌ Remove Webhook</button>
      <button class="btn bc" onclick="saveConfig()">💾 Save Config</button>
    </div>
  </div>

  <!-- Links Card -->
  <div class="card">
    <h2>🔗 Link Rules <span id="link-count" class="tag">0</span></h2>
    <div id="links-container"></div>
    <button class="btn bc" onclick="addLinkUI()" style="margin-top:6px">+ Add Link</button>
  </div>

  <!-- Run Results -->
  <div class="card" id="results-card" style="display:none">
    <h2>📊 Last Run Results</h2>
    <div id="results-body"></div>
  </div>

  <!-- Logs Card -->
  <div class="card">
    <h2>📋 Logs</h2>
    <div class="log-box" id="log-box"><div style="color:var(--td)">Loading...</div></div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
/* ─── State ──────────────────────────────────────────── */
let _links = [];

/* ─── Utils ──────────────────────────────────────────── */
function g(id){ return document.getElementById(id); }
function toast(msg, type='info'){
  const t=g('toast');
  t.textContent=msg;
  t.style.borderColor=type==='success'?'var(--g)':type==='error'?'var(--r)':'var(--c)';
  t.style.color=type==='success'?'var(--g)':type==='error'?'var(--r)':'var(--c)';
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'),3000);
}
async function api(action, payload={}){
  const opts={method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)};
  const r=await fetch('?api_action='+action, opts).then(x=>x.json()).catch(e=>({ok:false,error:String(e)}));
  return r;
}

/* ─── Config ─────────────────────────────────────────── */
async function loadConfig(){
  const r=await fetch('?api_action=get_config').then(x=>x.json());
  if(!r.ok) return;
  const d=r.data||{};
  g('cfg-token').value=d.bot_token||'';
  g('cfg-chat').value=d.chat_id||'';
  g('cfg-prefix').value=d.send_prefix||'';
  g('cfg-secret').value=d.run_secret||'';
  g('cfg-wtoken').value=d.webhook_token||'';
  g('cfg-wcmd').value=d.webhook_cmd||'/run';
  _links=d.links||[];
  renderLinks();
}

async function saveConfig(){
  const payload={
    bot_token: g('cfg-token').value.trim(),
    chat_id:   g('cfg-chat').value.trim(),
    send_prefix: g('cfg-prefix').value,
    run_secret:  g('cfg-secret').value.trim(),
    webhook_token: g('cfg-wtoken').value.trim(),
    webhook_cmd:   g('cfg-wcmd').value.trim(),
    new_pass:      g('cfg-newpass').value.trim(),
  };
  const r=await api('save_config', payload);
  r.ok ? toast('✅ Config saved!','success') : toast('Error: '+(r.error||''),'error');
}

async function setWebhook(){
  toast('Setting webhook...','info');
  const r=await api('set_webhook');
  r.ok ? toast('✅ Webhook set: '+r.webhook_url,'success') : toast('Error: '+(r.error||r.tg?.description||''),'error');
}
async function removeWebhook(){
  const r=await api('remove_webhook');
  r.ok ? toast('Webhook removed','info') : toast('Error','error');
}

/* ─── Links UI ───────────────────────────────────────── */
let _linkId=0;
function mkId(){ return 'l_'+Date.now()+'_'+(++_linkId); }

function addLinkUI(preset={}){
  const id=preset.id||mkId();
  _links.push({
    id,
    name:               preset.name||'New Link',
    enabled:            preset.enabled!==false,
    url:                preset.url||'',
    method:             preset.method||'GET',
    headers:            preset.headers||'',
    body:               preset.body||'',
    timeout:            preset.timeout||30,
    ssl_verify:         preset.ssl_verify!==false,
    response_path:      preset.response_path||'',
    reply_template:     preset.reply_template||'📌 <b>{name}</b>\n\n{response}',
    error_message:      preset.error_message||'⚠️ <b>{name}</b> failed!\nHTTP: <code>{http_code}</code>',
    send_on_error:      preset.send_on_error||false,
    chat_id:            preset.chat_id||'',
    screenshot_mode:    preset.screenshot_mode||false,
    screenshot_caption: preset.screenshot_caption||'📸 <b>{name}</b>\n🌐 <code>{url}</code>\n🕐 {ts}',
  });
  renderLinks();
  // scroll to bottom
  const c=g('links-container');
  if(c)c.lastElementChild?.scrollIntoView({behavior:'smooth'});
}

function renderLinks(){
  const c=g('links-container');
  c.innerHTML='';
  g('link-count').textContent=_links.length;
  _links.forEach((lk,i)=>{ c.appendChild(buildLinkEl(lk,i)); });
}

function buildLinkEl(lk,i){
  const div=document.createElement('div');
  div.className='link-card collapsed';
  div.id='lcard_'+lk.id;
  div.innerHTML=`
<div class="link-head">
  <div style="display:flex;align-items:center;gap:8px">
    <span class="chevron" onclick="toggleCard('${lk.id}')">▼</span>
    <input type="text" value="${esc(lk.name)}" class="link-name" id="ln_${lk.id}"
      style="background:transparent;border:none;border-bottom:1px solid var(--b);border-radius:0;padding:2px 4px;width:160px;color:var(--t);font-weight:600"
      onchange="syncField('${lk.id}','name',this.value)">
    <span class="badge ${lk.enabled?'ba':'bi'}">${lk.enabled?'ON':'OFF'}</span>
  </div>
  <div style="display:flex;gap:5px;flex-wrap:wrap">
    <button class="btn bgr bsm" onclick="runSingle('${lk.id}')">▶ Test</button>
    <button class="btn bor bsm" onclick="deleteLink('${lk.id}')">🗑</button>
  </div>
</div>
<div class="link-url mono">${esc(lk.url)||'<span style="color:var(--tf)">No URL set</span>'}</div>
<div class="link-body" style="margin-top:12px">
  <div class="row">
    <div class="f1"><label>🌐 URL</label><input type="text" id="lu_${lk.id}" value="${esc(lk.url)}" placeholder="https://..." onchange="syncField('${lk.id}','url',this.value);this.closest('.link-card').querySelector('.link-url').textContent=this.value||'No URL set'"></div>
    <div style="width:90px"><label>Method</label>
      <select id="lm_${lk.id}" onchange="syncField('${lk.id}','method',this.value)">
        ${['GET','POST','PUT','PATCH','DELETE'].map(m=>`<option${lk.method===m?' selected':''}>${m}</option>`).join('')}
      </select>
    </div>
    <div style="width:80px"><label>Timeout(s)</label><input type="number" id="lt_${lk.id}" value="${lk.timeout||30}" min="5" max="120" onchange="syncField('${lk.id}','timeout',+this.value)"></div>
  </div>
  <div class="row">
    <div class="f1"><label>📋 Headers (one per line, Key: Value)</label><textarea id="lh_${lk.id}" rows="3" onchange="syncField('${lk.id}','headers',this.value)">${esc(lk.headers)}</textarea></div>
    <div class="f1"><label>📦 Request Body (POST/PUT)</label><textarea id="lb_${lk.id}" rows="3" onchange="syncField('${lk.id}','body',this.value)">${esc(lk.body)}</textarea></div>
  </div>
  <div class="row">
    <div class="f1"><label>🔍 Response JSON Path (e.g. data.result)</label><input id="lrp_${lk.id}" value="${esc(lk.response_path)}" placeholder="Leave blank for auto-detect" onchange="syncField('${lk.id}','response_path',this.value)"></div>
    <div class="f1"><label>💬 Override Chat ID (blank = use global)</label><input id="lci_${lk.id}" value="${esc(lk.chat_id)}" placeholder="Optional" onchange="syncField('${lk.id}','chat_id',this.value)"></div>
  </div>
  <div class="row">
    <div class="f1">
      <label>📝 Reply Template</label>
      <textarea id="lrt_${lk.id}" rows="3" onchange="syncField('${lk.id}','reply_template',this.value)">${esc(lk.reply_template)}</textarea>
      <small style="color:var(--tf)">Vars: {name} {url} {response} {result} {http_code} {status} {ts} {date} {time} + any JSON key</small>
    </div>
  </div>
  <div class="row">
    <div class="f1">
      <label>❌ Error Message Template</label>
      <textarea id="lem_${lk.id}" rows="2" onchange="syncField('${lk.id}','error_message',this.value)">${esc(lk.error_message)}</textarea>
    </div>
  </div>
  <div class="row" style="align-items:center;gap:16px;flex-wrap:wrap">
    <label class="switch"><input type="checkbox" id="len_${lk.id}" ${lk.enabled?'checked':''} onchange="syncField('${lk.id}','enabled',this.checked);this.closest('.link-card').querySelector('.badge').textContent=this.checked?'ON':'OFF';this.closest('.link-card').querySelector('.badge').className='badge '+(this.checked?'ba':'bi')"> Enabled</label>
    <label class="switch"><input type="checkbox" id="lssl_${lk.id}" ${lk.ssl_verify!==false?'checked':''} onchange="syncField('${lk.id}','ssl_verify',this.checked)"> SSL Verify</label>
    <label class="switch"><input type="checkbox" id="lsoe_${lk.id}" ${lk.send_on_error?'checked':''} onchange="syncField('${lk.id}','send_on_error',this.checked)"> Send on Error</label>
    <label class="switch"><input type="checkbox" id="lssm_${lk.id}" ${lk.screenshot_mode?'checked':''} onchange="syncField('${lk.id}','screenshot_mode',this.checked);toggleSsCaption('${lk.id}',this.checked)"> 📸 Screenshot Mode</label>
  </div>
  <div id="lss_wrap_${lk.id}" style="${lk.screenshot_mode?'':'display:none'}">
    <div class="row" style="margin-top:8px">
      <div class="f1">
        <label>📸 Screenshot Caption (HTML, Telegram pe photo ke saath)</label>
        <textarea id="lssc_${lk.id}" rows="2" onchange="syncField('${lk.id}','screenshot_caption',this.value)">${esc(lk.screenshot_caption||'📸 <b>{name}</b>\n🌐 <code>{url}</code>\n🕐 {ts}')}</textarea>
        <small style="color:var(--tf)">Vars: {name} {url} {ts} {date} {time} | Browser khulegaa → screenshot → Telegram photo</small>
      </div>
    </div>
    <div style="background:rgba(57,255,20,.06);border:1px solid rgba(57,255,20,.2);border-radius:6px;padding:8px 12px;margin-top:4px;font-size:11px;color:var(--g)">
      ✅ <b>Koi browser install nahi chahiye!</b> thum.io screenshot API use hoti hai.<br>
      Bas URL dalo, screenshot automatically bot pe aa jayega.
    </div>
  </div>
  <div class="result-box" id="lr_${lk.id}"></div>
</div>`;
  return div;
}

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function syncField(id,field,val){ const lk=_links.find(l=>l.id===id); if(lk)lk[field]=val; }
function toggleCard(id){
  const el=g('lcard_'+id);
  if(el) el.classList.toggle('collapsed');
}
function toggleSsCaption(id,show){
  const w=g('lss_wrap_'+id);
  if(w) w.style.display=show?'':'none';
}
function deleteLink(id){
  if(!confirm('Delete this link?')) return;
  _links=_links.filter(l=>l.id!==id);
  renderLinks();
}

/* ─── Save links ─────────────────────────────────────── */
async function saveLinks(){
  const r=await api('save_links',{links:_links});
  r.ok ? toast('✅ '+r.count+' link(s) saved!','success') : toast('Error: '+(r.error||''),'error');
}

async function savePage(){
  await saveConfig();
  await saveLinks();
}

/* ─── Run All ────────────────────────────────────────── */
async function runAll(){
  g('run-status').textContent='⏳ Running...';
  g('run-status').style.color='var(--y)';
  const r=await api('run_now');
  g('run-status').textContent='';
  if(!r.ok){ toast('Error: '+(r.error||''),'error'); return; }
  showResults(r.results||[]);
  toast('✅ Done! '+r.success+'/'+(r.results||[]).length+' success','success');
  loadLogs();
}

/* ─── Run single ─────────────────────────────────────── */
async function runSingle(linkId){
  const box=g('lr_'+linkId);
  if(box){ box.style.display='block'; box.innerHTML='<span style="color:var(--y)">⏳ Testing...</span>'; }
  const r=await api('run_single',{link_id:linkId});
  if(!r.ok){ if(box) box.innerHTML='<span style="color:var(--r)">Error: '+(r.error||'')+'</span>'; return; }
  const res=r.result||{};
  if(box){
    const isSS=res.mode==='screenshot';
    box.innerHTML=`<b style="color:${res.failed?'var(--r)':'var(--g)'}">${res.failed?'❌ FAILED':'✅ SUCCESS'}</b>`
      +(isSS?' <span class="tag">📸 Screenshot</span>':` HTTP <code>${res.code}</code>`)
      +(res.sent?' <span style="color:var(--g)">| Sent ✓</span>':'')
      +(!isSS?`<br><small style="color:var(--td)">Response:</small><br><div class="mono" style="white-space:pre-wrap;max-height:200px;overflow:auto">${esc(String(res.extracted||''))}</div>`:'');
  }
  loadLogs();
}

/* ─── Show run results ───────────────────────────────── */
function showResults(results){
  const card=g('results-card');
  const body=g('results-body');
  card.style.display='block';
  body.innerHTML=results.map(r=>`
<div style="background:var(--s2);border:1px solid var(--b);border-radius:6px;padding:10px;margin-bottom:8px">
  <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:6px">
    <b>${esc(r.name)}</b>
    <div style="display:flex;gap:5px;align-items:center;flex-wrap:wrap">
      ${r.mode==='screenshot'?'<span class="tag">📸 Screenshot</span>':''}
      <span class="badge ${r.failed?'bi':'ba'}">${r.failed?'❌ FAILED':'✅ OK'}${r.mode!=='screenshot'?' HTTP '+r.code:''}</span>
      ${r.sent?'<span style="color:var(--g);font-size:11px">✓ Sent</span>':''}
    </div>
  </div>
  ${(!r.failed&&r.mode!=='screenshot')?'<div class="mono" style="margin-top:6px;white-space:pre-wrap;font-size:11px;max-height:100px;overflow:auto;color:var(--td)">'+esc(String(r.extracted||'')).slice(0,500)+'</div>':''}
</div>`).join('');
}

/* ─── Logs ───────────────────────────────────────────── */
async function loadLogs(){
  const r=await fetch('?api_action=get_logs').then(x=>x.json());
  const box=g('log-box');
  if(!r.ok||!r.data?.length){ box.innerHTML='<div style="color:var(--tf)">No logs yet.</div>'; return; }
  box.innerHTML=r.data.map(l=>{
    const cls=l.type==='success'?'log-ok':l.type==='error'?'log-err':'log-info';
    return `<div class="log-entry ${cls}">[${new Date(l.time).toLocaleTimeString()}] ${esc(l.text)}</div>`;
  }).join('');
}

async function clearLogs(){
  await api('clear_logs');
  loadLogs();
  toast('Logs cleared','info');
}

/* ─── Init ───────────────────────────────────────────── */
loadConfig();
loadLogs();
</script>
</body>
</html>
