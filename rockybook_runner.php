<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║          REBEL ROCKYBOOK RUNNER — Standalone Edition            ║
 * ║  rockybook.vip site ko automate karta hai aur results           ║
 * ║  Telegram pe bhejta hai. Separate script, separate config.      ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 * Site: https://rockybook.vip
 * Auth: Cookie-based (login → token stored in session cookie)
 * API:  https://rockybook.vip/api
 *
 * Usage modes:
 *   1. Web UI  → open rockybook_runner.php in browser (admin panel)
 *   2. Run now → ?run=1&secret=YOUR_SECRET   (trigger via URL)
 *   3. Cron    → php rockybook_runner.php     (CLI scheduler)
 *   4. Webhook → ?webhook=1 (Telegram bot receives /rb command)
 */

// ─── compat shims ───────────────────────────────────────────
if (!function_exists('str_starts_with')) {
    function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_contains')) {
    function str_contains($h, $n) { return strpos($h, $n) !== false; }
}

// ────────────────────────────────────────────────────────────
define('RB_VERSION',    '1.0');
define('RB_CONFIG_FILE', __DIR__ . '/rb_config.json');
define('RB_LOG_FILE',    __DIR__ . '/rb_logs.json');
define('RB_COOKIE_FILE', __DIR__ . '/rb_cookies.txt');
define('RB_SS_DIR',      __DIR__ . '/rb_screenshots/');
define('RB_API_BASE',    'https://rockybook.vip/api');
define('LR_TG_BASE',     'https://api.telegram.org/bot');

// ─── Default config ──────────────────────────────────────────
$defaultConfig = [
    'admin_pass'      => 'rebel@2026',    // Admin panel password
    'run_secret'      => 'changeme123',   // ?run=1&secret=X
    'bot_token'       => '',              // Telegram bot token
    'chat_id'         => '',              // Telegram chat/channel ID
    'send_prefix'     => "🎯 <b>RockyBook Runner</b>\n\n",
    'webhook_token'   => '',              // Bot token for webhook
    'webhook_cmd'     => '/rb',           // Command to trigger run

    // RockyBook credentials
    'rb_phone'        => '',              // Login phone number (loginType)
    'rb_password'     => '',             // Account password

    // Which tasks to run (all enabled by default)
    'tasks'           => [
        'dashboard_summary'   => true,
        'active_users'        => true,
        'deposit_users'       => true,
        'withdraw_users'      => true,
        'transactions_today'  => true,
        'no_transaction_users'=> true,
        'panels'              => false,
        'sub_accounts'        => false,
        'games'               => false,
        'announcements'       => true,
    ],

    // Custom extra link rules (same format as link_runner)
    'links'           => [],
];

if (!is_dir(RB_SS_DIR)) @mkdir(RB_SS_DIR, 0755, true);

// ─── Load/save config ────────────────────────────────────────
function rbLoadConfig() {
    global $defaultConfig;
    if (!file_exists(RB_CONFIG_FILE)) return $defaultConfig;
    $loaded = json_decode(file_get_contents(RB_CONFIG_FILE), true);
    if (!is_array($loaded)) return $defaultConfig;
    // Deep merge tasks
    $merged = array_merge($defaultConfig, $loaded);
    if (isset($loaded['tasks']) && is_array($loaded['tasks'])) {
        $merged['tasks'] = array_merge($defaultConfig['tasks'], $loaded['tasks']);
    }
    return $merged;
}
function rbSaveConfig($cfg) {
    file_put_contents(RB_CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ─── Logging ─────────────────────────────────────────────────
function rbLog($text, $type = 'info') {
    $logs = file_exists(RB_LOG_FILE) ? (json_decode(file_get_contents(RB_LOG_FILE), true) ?: []) : [];
    array_unshift($logs, ['time' => date('c'), 'text' => $text, 'type' => $type]);
    if (count($logs) > 300) $logs = array_slice($logs, 0, 300);
    file_put_contents(RB_LOG_FILE, json_encode($logs, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ─── Telegram sender ─────────────────────────────────────────
function rbTg($method, $params, $token) {
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

function rbSend($token, $chatId, $text) {
    if (!$token || !$chatId || !trim($text)) return false;
    $chunks = rbChunk($text, 4000);
    $ok = true;
    foreach ($chunks as $chunk) {
        $r = rbTg('sendMessage', [
            'chat_id'                  => $chatId,
            'text'                     => $chunk,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => true,
        ], $token);
        if (!($r['ok'] ?? false)) $ok = false;
    }
    return $ok;
}

function rbChunk($text, $maxLen = 4000) {
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

// ─── RockyBook API client ─────────────────────────────────────
function rbApiCall($endpoint, $method = 'GET', $data = null, $extraHeaders = []) {
    $url = RB_API_BASE . $endpoint;
    $headers = array_merge([
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
        'Origin: https://rockybook.vip',
        'Referer: https://rockybook.vip/',
    ], $extraHeaders);

    $ch = curl_init();
    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_COOKIEJAR      => RB_COOKIE_FILE,
        CURLOPT_COOKIEFILE     => RB_COOKIE_FILE,
    ];

    $m = strtoupper($method);
    if ($m === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $data !== null ? json_encode($data) : '{}';
    } elseif ($m === 'PUT' || $m === 'PATCH') {
        $opts[CURLOPT_CUSTOMREQUEST] = $m;
        if ($data !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($data);
    } elseif ($m === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    }

    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $parsed = json_decode($raw, true);
    return [
        'code'   => $code,
        'raw'    => $raw ?: '',
        'data'   => $parsed,
        'error'  => $err,
        'ok'     => $code >= 200 && $code < 300,
    ];
}

// ─── Login / Session ──────────────────────────────────────────
function rbLogin($phone, $password) {
    if (!$phone || !$password) {
        rbLog('RockyBook login skip: credentials not set', 'error');
        return false;
    }

    // Check if already logged in
    $check = rbApiCall('/auth/fetchUserByToken');
    if ($check['ok'] && isset($check['data']['user'])) {
        rbLog('RockyBook: already logged in as ' . ($check['data']['user']['clientName'] ?? 'unknown'), 'info');
        return $check['data']['user'];
    }

    // Login
    $res = rbApiCall('/auth/login', 'POST', [
        'loginType' => $phone,
        'password'  => $password,
    ]);

    if (!$res['ok'] || empty($res['data'])) {
        rbLog('RockyBook login failed: HTTP ' . $res['code'] . ' | ' . mb_substr($res['raw'], 0, 300), 'error');
        return false;
    }

    $d = $res['data'];
    if (!empty($d['success']) || isset($d['user']) || isset($d['data'])) {
        $user = $d['user'] ?? $d['data'] ?? $d;
        rbLog('RockyBook login OK — ' . ($user['clientName'] ?? $phone), 'success');
        return $user;
    }

    rbLog('RockyBook login error: ' . ($d['message'] ?? $d['error'] ?? json_encode($d)), 'error');
    return false;
}

function rbLogout() {
    rbApiCall('/auth/logout', 'POST');
    if (file_exists(RB_COOKIE_FILE)) @unlink(RB_COOKIE_FILE);
    rbLog('RockyBook: logged out', 'info');
}

// ─── Screenshot helper (same as link_runner) ─────────────────
function rbFetchScreenshotBytes($targetUrl, $timeout = 30) {
    $thumbUrl = 'https://image.thum.io/get/width/1280/crop/900/png/' . urlencode($targetUrl);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $thumbUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; RBRunner/1.0)',
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($code === 200 && $data && str_contains((string)$ct, 'image')) {
        return ['bytes' => $data, 'source' => 'thum.io'];
    }
    return null;
}

function rbTakeScreenshot($url, $token, $chatId, $caption, $timeout = 30) {
    if (!$token || !$chatId) return false;
    $result = rbFetchScreenshotBytes($url, $timeout);
    if (!$result) return false;

    $ssFile = RB_SS_DIR . 'ss_' . md5($url . microtime()) . '.png';
    file_put_contents($ssFile, $result['bytes']);
    if (!file_exists($ssFile) || filesize($ssFile) < 500) { @unlink($ssFile); return false; }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => LR_TG_BASE . $token . '/sendPhoto',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_POSTFIELDS     => [
            'chat_id'    => $chatId,
            'caption'    => $caption . "\n<i>via thum.io</i>",
            'parse_mode' => 'HTML',
            'photo'      => new CURLFile($ssFile, 'image/png', 'screenshot.png'),
        ],
    ]);
    $r = json_decode(curl_exec($ch), true);
    curl_close($ch);
    @unlink($ssFile);

    if (empty($r['ok'])) {
        $thumbUrl = 'https://image.thum.io/get/width/1280/crop/900/png/' . urlencode($url);
        $r2 = rbTg('sendPhoto', ['chat_id' => $chatId, 'photo' => $thumbUrl, 'caption' => $caption, 'parse_mode' => 'HTML'], $token);
        return !empty($r2['ok']);
    }
    return true;
}

// ─── Helper: format number ────────────────────────────────────
function rbFmt($n, $decimals = 2) {
    if ($n === null || $n === '') return '—';
    return '₹' . number_format((float)$n, $decimals, '.', ',');
}
function rbNum($n) {
    if ($n === null || $n === '') return '—';
    return number_format((int)$n, 0, '.', ',');
}

// ─── TASKS ───────────────────────────────────────────────────

function rbTask_DashboardSummary($cfg) {
    $ts = date('Y-m-d');
    $start = $ts . 'T00:00:00.000Z';
    $end   = $ts . 'T23:59:59.000Z';
    $res = rbApiCall("/transaction/dash-summary?startDate={$start}&endDate={$end}");

    if (!$res['ok'] || empty($res['data'])) {
        rbLog('DashboardSummary fetch failed: HTTP ' . $res['code'], 'error');
        return ['ok' => false, 'msg' => '❌ Dashboard summary fetch failed (HTTP ' . $res['code'] . ')'];
    }

    $d = $res['data'];
    $dep   = $d['totalDeposit']    ?? $d['deposit']    ?? $d['depositAmount']    ?? 0;
    $wit   = $d['totalWithdraw']   ?? $d['withdraw']   ?? $d['withdrawAmount']   ?? 0;
    $prof  = $d['profit']          ?? $d['netProfit']  ?? ($dep - $wit);
    $users = $d['totalUsers']      ?? $d['users']      ?? '—';
    $acu   = $d['activeUsers']     ?? $d['active']     ?? '—';

    $msg = "📊 <b>Dashboard Summary</b> — <code>{$ts}</code>\n\n"
         . "💰 Total Deposit:   <b>" . rbFmt($dep) . "</b>\n"
         . "💸 Total Withdraw:  <b>" . rbFmt($wit) . "</b>\n"
         . "📈 Net Profit:      <b>" . rbFmt($prof) . "</b>\n"
         . "👥 Total Users:     <b>" . rbNum($users) . "</b>\n"
         . "🟢 Active Users:    <b>" . rbNum($acu) . "</b>";

    rbLog("DashboardSummary OK — dep={$dep} wit={$wit}", 'success');
    return ['ok' => true, 'msg' => $msg, 'data' => $d];
}

function rbTask_ActiveUsers($cfg) {
    $res = rbApiCall('/user/getActiveUserLogsCount');
    $res2 = rbApiCall('/user/getActiveUserLogs');

    $count = 0;
    if ($res['ok'] && isset($res['data'])) {
        $count = $res['data']['count'] ?? $res['data']['total'] ?? count($res['data']);
    }

    $lines = '';
    if ($res2['ok'] && is_array($res2['data'])) {
        $users = $res2['data']['users'] ?? $res2['data']['data'] ?? $res2['data'];
        if (is_array($users)) {
            $slice = array_slice($users, 0, 15);
            foreach ($slice as $u) {
                $name   = $u['clientName'] ?? $u['username'] ?? $u['name'] ?? '?';
                $phone  = isset($u['phone']) ? '📱' . $u['phone'] : '';
                $role   = $u['role'] ?? $u['userType'] ?? '';
                $lines .= "\n• <code>{$name}</code> {$phone}" . ($role ? " [{$role}]" : '');
            }
            $total = count($users);
            if ($total > 15) $lines .= "\n<i>...and " . ($total - 15) . " more</i>";
        }
    }

    $ts  = date('Y-m-d H:i');
    $msg = "🟢 <b>Active Users</b> — <code>{$ts}</code>\n"
         . "Total Online: <b>" . rbNum($count) . "</b>"
         . $lines;

    rbLog('ActiveUsers OK — count=' . $count, 'success');
    return ['ok' => true, 'msg' => $msg];
}

function rbTask_DepositUsers($cfg) {
    $ts    = date('Y-m-d');
    $start = $ts . 'T00:00:00.000Z';
    $end   = date('Y-m-d', strtotime('+1 day')) . 'T00:00:00.000Z';
    $res   = rbApiCall("/transaction/getDeposit_Users_Transaction_forDashboard?startDate={$start}&endDate={$end}");

    if (!$res['ok']) {
        rbLog('DepositUsers fetch failed: HTTP ' . $res['code'], 'error');
        return ['ok' => false, 'msg' => '❌ Deposit users fetch failed (HTTP ' . $res['code'] . ')'];
    }

    $d     = $res['data'];
    $users = $d['users'] ?? $d['data'] ?? (is_array($d) ? $d : []);
    $total = count($users);

    $depSum = 0;
    foreach ($users as $u) {
        $depSum += (float)($u['totalDeposit'] ?? $u['amount'] ?? $u['depositAmount'] ?? 0);
    }

    $lines = '';
    foreach (array_slice($users, 0, 10) as $u) {
        $name = $u['clientName'] ?? $u['username'] ?? $u['name'] ?? '?';
        $amt  = $u['totalDeposit'] ?? $u['amount'] ?? $u['depositAmount'] ?? 0;
        $lines .= "\n• <code>{$name}</code> — " . rbFmt($amt);
    }
    if ($total > 10) $lines .= "\n<i>...and " . ($total - 10) . " more</i>";

    $msg = "💰 <b>Today's Deposit Users</b> — <code>{$ts}</code>\n"
         . "Users: <b>" . rbNum($total) . "</b>  |  Total: <b>" . rbFmt($depSum) . "</b>"
         . $lines;

    rbLog("DepositUsers OK — {$total} users, sum={$depSum}", 'success');
    return ['ok' => true, 'msg' => $msg, 'data' => compact('total', 'depSum')];
}

function rbTask_WithdrawUsers($cfg) {
    $ts    = date('Y-m-d');
    $start = $ts . 'T00:00:00.000Z';
    $end   = date('Y-m-d', strtotime('+1 day')) . 'T00:00:00.000Z';
    $res   = rbApiCall("/transaction/getWithdraw_Users_Transaction_forDashboard?startDate={$start}&endDate={$end}");

    if (!$res['ok']) {
        rbLog('WithdrawUsers fetch failed: HTTP ' . $res['code'], 'error');
        return ['ok' => false, 'msg' => '❌ Withdraw users fetch failed (HTTP ' . $res['code'] . ')'];
    }

    $d     = $res['data'];
    $users = $d['users'] ?? $d['data'] ?? (is_array($d) ? $d : []);
    $total = count($users);

    $witSum = 0;
    foreach ($users as $u) {
        $witSum += (float)($u['totalWithdraw'] ?? $u['amount'] ?? $u['withdrawAmount'] ?? 0);
    }

    $lines = '';
    foreach (array_slice($users, 0, 10) as $u) {
        $name = $u['clientName'] ?? $u['username'] ?? $u['name'] ?? '?';
        $amt  = $u['totalWithdraw'] ?? $u['amount'] ?? $u['withdrawAmount'] ?? 0;
        $lines .= "\n• <code>{$name}</code> — " . rbFmt($amt);
    }
    if ($total > 10) $lines .= "\n<i>...and " . ($total - 10) . " more</i>";

    $msg = "💸 <b>Today's Withdraw Users</b> — <code>{$ts}</code>\n"
         . "Users: <b>" . rbNum($total) . "</b>  |  Total: <b>" . rbFmt($witSum) . "</b>"
         . $lines;

    rbLog("WithdrawUsers OK — {$total} users, sum={$witSum}", 'success');
    return ['ok' => true, 'msg' => $msg, 'data' => compact('total', 'witSum')];
}

function rbTask_TransactionsToday($cfg) {
    $res = rbApiCall('/transaction/getToday_creatAt_and_today_first_transaction');

    if (!$res['ok']) {
        rbLog('TransactionsToday fetch failed: HTTP ' . $res['code'], 'error');
        return ['ok' => false, 'msg' => '❌ Transactions today fetch failed (HTTP ' . $res['code'] . ')'];
    }

    $d    = $res['data'];
    $data = $d['data'] ?? $d;

    $created  = $data['todayCreated']  ?? $data['createdToday']  ?? '—';
    $firstTrx = $data['firstTransaction'] ?? null;
    $total    = $data['total'] ?? $data['count'] ?? '—';

    $msg = "🔄 <b>Today's Transactions</b> — <code>" . date('Y-m-d') . "</code>\n"
         . "📋 Total Transactions: <b>" . rbNum($total) . "</b>\n"
         . "🆕 New Users Today:    <b>" . rbNum($created) . "</b>";

    if ($firstTrx) {
        $fname = $firstTrx['clientName'] ?? $firstTrx['username'] ?? '?';
        $famt  = $firstTrx['amount'] ?? 0;
        $ftype = $firstTrx['type'] ?? $firstTrx['transactionType'] ?? '?';
        $msg  .= "\n\n🥇 <b>First Transaction Today:</b>\n"
              . "User: <code>{$fname}</code>\nType: {$ftype} | Amount: " . rbFmt($famt);
    }

    rbLog('TransactionsToday OK', 'success');
    return ['ok' => true, 'msg' => $msg];
}

function rbTask_NoTransactionUsers($cfg) {
    $ts    = date('Y-m-d');
    $start = $ts . 'T00:00:00.000Z';
    $end   = date('Y-m-d', strtotime('+1 day')) . 'T00:00:00.000Z';
    $res   = rbApiCall("/transaction/getUsers_of_NoTransaction_forDashboard?startDate={$start}&endDate={$end}");

    if (!$res['ok']) {
        rbLog('NoTransactionUsers fetch failed: HTTP ' . $res['code'], 'error');
        return ['ok' => false, 'msg' => '❌ No-transaction users fetch failed (HTTP ' . $res['code'] . ')'];
    }

    $d     = $res['data'];
    $users = $d['users'] ?? $d['data'] ?? (is_array($d) ? $d : []);
    $total = count($users);

    $lines = '';
    foreach (array_slice($users, 0, 10) as $u) {
        $name = $u['clientName'] ?? $u['username'] ?? $u['name'] ?? '?';
        $bal  = $u['balance'] ?? $u['walletBalance'] ?? '?';
        $lines .= "\n• <code>{$name}</code>" . ($bal !== '?' ? " — Bal: " . rbFmt($bal) : '');
    }
    if ($total > 10) $lines .= "\n<i>...and " . ($total - 10) . " more</i>";

    $msg = "😴 <b>No-Transaction Users Today</b> — <code>{$ts}</code>\n"
         . "Count: <b>" . rbNum($total) . "</b>"
         . ($lines ? "\n" . $lines : '');

    rbLog("NoTransactionUsers OK — {$total} users", 'success');
    return ['ok' => true, 'msg' => $msg];
}

function rbTask_Panels($cfg) {
    $res = rbApiCall('/panel/getAllPanels?page=1&limit=50');

    if (!$res['ok']) {
        rbLog('Panels fetch failed: HTTP ' . $res['code'], 'error');
        return ['ok' => false, 'msg' => '❌ Panels fetch failed (HTTP ' . $res['code'] . ')'];
    }

    $d      = $res['data'];
    $panels = $d['panels'] ?? $d['data'] ?? (is_array($d) ? $d : []);
    $total  = count($panels);

    $lines = '';
    foreach (array_slice($panels, 0, 15) as $p) {
        $name   = $p['panelName'] ?? $p['name'] ?? '?';
        $status = !empty($p['isActive']) ? '🟢' : '🔴';
        $bal    = $p['balance'] ?? null;
        $lines .= "\n{$status} <b>{$name}</b>" . ($bal !== null ? " — " . rbFmt($bal) : '');
    }
    if ($total > 15) $lines .= "\n<i>...and " . ($total - 15) . " more</i>";

    $msg = "🖥️ <b>Panels</b> ({$total} total)\n" . $lines;

    rbLog("Panels OK — {$total} panels", 'success');
    return ['ok' => true, 'msg' => $msg];
}

function rbTask_SubAccounts($cfg) {
    $res = rbApiCall('/subAccount/getSubAccounts?page=1&limit=50');

    if (!$res['ok']) {
        rbLog('SubAccounts fetch failed: HTTP ' . $res['code'], 'error');
        return ['ok' => false, 'msg' => '❌ Sub-accounts fetch failed (HTTP ' . $res['code'] . ')'];
    }

    $d    = $res['data'];
    $accs = $d['subAccounts'] ?? $d['data'] ?? (is_array($d) ? $d : []);
    $total = count($accs);

    $lines = '';
    foreach (array_slice($accs, 0, 10) as $a) {
        $name   = $a['clientName'] ?? $a['name'] ?? '?';
        $role   = $a['role'] ?? '';
        $status = !empty($a['isActive']) ? '🟢' : '🔴';
        $lines .= "\n{$status} <code>{$name}</code>" . ($role ? " [{$role}]" : '');
    }
    if ($total > 10) $lines .= "\n<i>...and " . ($total - 10) . " more</i>";

    $msg = "👤 <b>Sub-Accounts</b> ({$total} total)\n" . $lines;

    rbLog("SubAccounts OK — {$total} accounts", 'success');
    return ['ok' => true, 'msg' => $msg];
}

function rbTask_Games($cfg) {
    $res = rbApiCall('/game/getAllGamesWithPagination?page=1&limit=100');

    if (!$res['ok']) {
        rbLog('Games fetch failed: HTTP ' . $res['code'], 'error');
        return ['ok' => false, 'msg' => '❌ Games fetch failed (HTTP ' . $res['code'] . ')'];
    }

    $d     = $res['data'];
    $games = $d['games'] ?? $d['data'] ?? (is_array($d) ? $d : []);
    $total = count($games);
    $active = count(array_filter($games, fn($g) => !empty($g['isActive'])));

    $lines = '';
    foreach (array_slice($games, 0, 15) as $g) {
        $name   = $g['gameName'] ?? $g['name'] ?? '?';
        $status = !empty($g['isActive']) ? '🟢' : '🔴';
        $lines .= "\n{$status} {$name}";
    }
    if ($total > 15) $lines .= "\n<i>...and " . ($total - 15) . " more</i>";

    $msg = "🎮 <b>Games</b> ({$active} active / {$total} total)\n" . $lines;

    rbLog("Games OK — {$total} games, {$active} active", 'success');
    return ['ok' => true, 'msg' => $msg];
}

function rbTask_Announcements($cfg) {
    $res = rbApiCall('/announcement/getAnnouncement');

    if (!$res['ok']) {
        rbLog('Announcements fetch failed: HTTP ' . $res['code'], 'error');
        return ['ok' => false, 'msg' => '❌ Announcements fetch failed (HTTP ' . $res['code'] . ')'];
    }

    $d    = $res['data'];
    $anns = $d['announcements'] ?? $d['data'] ?? (is_array($d) ? $d : []);
    if (!is_array($anns)) $anns = [$d];

    $lines = '';
    foreach (array_slice($anns, 0, 5) as $a) {
        $title   = $a['title'] ?? $a['heading'] ?? '?';
        $content = $a['content'] ?? $a['message'] ?? $a['body'] ?? '';
        $lines  .= "\n📢 <b>" . htmlspecialchars($title, ENT_NOQUOTES, 'UTF-8') . "</b>";
        if ($content) $lines .= "\n<i>" . htmlspecialchars(mb_substr($content, 0, 200), ENT_NOQUOTES, 'UTF-8') . "</i>";
    }

    if (!$lines) $lines = "\nNo announcements found.";

    $msg = "📢 <b>Announcements</b>\n" . $lines;

    rbLog('Announcements OK', 'success');
    return ['ok' => true, 'msg' => $msg];
}

// ─── Run extra link rules (same engine as link_runner) ────────
function rbRunLinks($cfg) {
    $results = [];
    $links = $cfg['links'] ?? [];
    foreach ($links as $link) {
        if (empty($link['enabled'])) continue;
        $id   = $link['id'] ?? uniqid('rb_');
        $name = $link['name'] ?? $id;
        $url  = trim($link['url'] ?? '');
        if (!$url) continue;

        $vars = ['ts' => date('Y-m-d H:i:s'), 'date' => date('Y-m-d'), 'time' => date('H:i:s')];

        $hdrs = [];
        foreach (explode("\n", $link['headers'] ?? '') as $h) {
            $h = trim($h);
            if ($h && strpos($h, ':') !== false) $hdrs[] = $h;
        }
        if (empty($hdrs)) $hdrs = [];

        $ch = curl_init();
        $o = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => !isset($link['ssl_verify']) || (bool)$link['ssl_verify'],
            CURLOPT_TIMEOUT        => max(5, min(120, (int)($link['timeout'] ?? 30))),
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_HTTPHEADER     => $hdrs,
        ];
        $m = strtoupper($link['method'] ?? 'GET');
        if ($m === 'POST') {
            $o[CURLOPT_POST] = true;
            $o[CURLOPT_POSTFIELDS] = $link['body'] ?? '';
        }
        curl_setopt_array($ch, $o);
        $rawBody = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $failed = ($code >= 400 || $rawBody === false || trim($rawBody) === '');
        $results[] = ['id' => $id, 'name' => $name, 'url' => $url, 'code' => $code, 'failed' => $failed, 'body' => $rawBody];
        rbLog(($failed ? "FAIL [{$id}] HTTP {$code}" : "OK [{$id}] HTTP {$code}") . " → " . $name, $failed ? 'error' : 'success');
    }
    return $results;
}

// ─── Main runner ─────────────────────────────────────────────
function rbRunAll($cfg) {
    $token  = trim($cfg['bot_token'] ?? '');
    $chatId = trim($cfg['chat_id'] ?? '');
    $prefix = str_replace('\n', "\n", $cfg['send_prefix'] ?? '');
    $tasks  = $cfg['tasks'] ?? [];

    // Login
    $phone    = trim($cfg['rb_phone'] ?? '');
    $password = trim($cfg['rb_password'] ?? '');
    $user = rbLogin($phone, $password);

    $results  = [];
    $ts       = date('Y-m-d H:i:s');

    // If not logged in, note it but still try tasks that might work
    $loggedIn = ($user !== false);

    // Run each enabled task
    $taskMap = [
        'dashboard_summary'    => 'rbTask_DashboardSummary',
        'active_users'         => 'rbTask_ActiveUsers',
        'deposit_users'        => 'rbTask_DepositUsers',
        'withdraw_users'       => 'rbTask_WithdrawUsers',
        'transactions_today'   => 'rbTask_TransactionsToday',
        'no_transaction_users' => 'rbTask_NoTransactionUsers',
        'panels'               => 'rbTask_Panels',
        'sub_accounts'         => 'rbTask_SubAccounts',
        'games'                => 'rbTask_Games',
        'announcements'        => 'rbTask_Announcements',
    ];

    foreach ($taskMap as $key => $fn) {
        if (empty($tasks[$key])) continue;
        $r = $fn($cfg);
        $results[$key] = $r;
        // Send to Telegram
        if ($token && $chatId && !empty($r['msg'])) {
            rbSend($token, $chatId, $prefix . $r['msg']);
        }
    }

    // Run extra link rules
    $linkResults = rbRunLinks($cfg);

    // Summary
    $ok  = count(array_filter($results, fn($r) => !empty($r['ok'])));
    $tot = count($results);
    rbLog("Run complete — {$ok}/{$tot} tasks OK", 'info');

    return ['tasks' => $results, 'links' => $linkResults, 'user' => $user, 'ts' => $ts];
}

// ─── Auth ─────────────────────────────────────────────────────
session_start();
function rbSanitize($v) { return htmlspecialchars(strip_tags(trim($v)), ENT_QUOTES, 'UTF-8'); }

$cfg = rbLoadConfig();
$isLoggedIn = !empty($_SESSION['rb_ok']);

// ─── Webhook mode ─────────────────────────────────────────────
if (isset($_GET['webhook'])) {
    $wToken = trim($cfg['webhook_token'] ?: $cfg['bot_token']);
    $update = json_decode(file_get_contents('php://input'), true);
    if (!is_array($update)) { http_response_code(200); exit; }
    $msg  = $update['message'] ?? $update['channel_post'] ?? null;
    if (!$msg) { http_response_code(200); exit; }
    $text   = trim($msg['text'] ?? '');
    $chatId = $msg['chat']['id'] ?? '';
    $cmd    = trim($cfg['webhook_cmd'] ?? '/rb');

    if (str_starts_with(strtolower($text), strtolower($cmd))) {
        rbTg('sendMessage', ['chat_id' => $chatId, 'text' => '⏳ RockyBook Runner chal raha hai...', 'parse_mode' => 'HTML'], $wToken);
        $results = rbRunAll($cfg);
        $ok  = count(array_filter($results['tasks'], fn($r) => !empty($r['ok'])));
        $tot = count($results['tasks']);
        rbTg('sendMessage', [
            'chat_id'    => $chatId,
            'text'       => "✅ <b>RockyBook Runner Done!</b>\n\n📊 Tasks: <code>{$ok}/{$tot}</code> success",
            'parse_mode' => 'HTML',
        ], $wToken);
    }
    http_response_code(200);
    exit;
}

// ─── URL trigger (?run=1&secret=X) ───────────────────────────
if (isset($_GET['run'])) {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== $cfg['run_secret']) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid secret']);
        exit;
    }
    header('Content-Type: application/json');
    $results = rbRunAll($cfg);
    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}

// ─── CLI mode ─────────────────────────────────────────────────
if (php_sapi_name() === 'cli') {
    echo "=== Rebel RockyBook Runner v" . RB_VERSION . " ===\n";
    $results = rbRunAll($cfg);
    foreach ($results['tasks'] as $key => $r) {
        $icon = empty($r['ok']) ? '❌' : '✅';
        echo "{$icon} [{$key}] " . ($r['ok'] ? 'OK' : 'FAILED') . "\n";
    }
    echo "\nDone. Tasks: " . count($results['tasks']) . "\n";
    exit(0);
}

// ─── Admin API ────────────────────────────────────────────────
if (isset($_GET['api_action'])) {
    header('Content-Type: application/json');
    $act = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['api_action'] ?? '');

    if ($act === 'login') {
        $pass = $_POST['pass'] ?? '';
        if ($pass === $cfg['admin_pass']) {
            $_SESSION['rb_ok'] = true;
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
            unset($safe['admin_pass']);
            echo json_encode(['ok' => true, 'data' => $safe]); exit;

        case 'save_config':
            $newCfg = $cfg;
            if (isset($body['bot_token']))    $newCfg['bot_token']    = trim($body['bot_token']);
            if (isset($body['chat_id']))      $newCfg['chat_id']      = trim($body['chat_id']);
            if (isset($body['send_prefix']))  $newCfg['send_prefix']  = $body['send_prefix'];
            if (isset($body['run_secret']))   $newCfg['run_secret']   = trim($body['run_secret']);
            if (isset($body['webhook_token']))$newCfg['webhook_token']= trim($body['webhook_token']);
            if (isset($body['webhook_cmd']))  $newCfg['webhook_cmd']  = trim($body['webhook_cmd']);
            if (isset($body['rb_phone']))     $newCfg['rb_phone']     = trim($body['rb_phone']);
            if (isset($body['rb_password']))  $newCfg['rb_password']  = trim($body['rb_password']);
            if (isset($body['tasks']) && is_array($body['tasks'])) {
                $newCfg['tasks'] = array_merge($cfg['tasks'], $body['tasks']);
            }
            if (!empty($body['new_pass']) && strlen(trim($body['new_pass'])) >= 4) {
                $newCfg['admin_pass'] = trim($body['new_pass']);
            }
            rbSaveConfig($newCfg);
            $cfg = $newCfg;
            rbLog('Config saved', 'info');
            echo json_encode(['ok' => true]); exit;

        case 'run_now':
            $results = rbRunAll($cfg);
            $ok  = count(array_filter($results['tasks'], fn($r) => !empty($r['ok'])));
            $tot = count($results['tasks']);
            rbLog("Manual run — {$ok}/{$tot} tasks", 'info');
            echo json_encode(['ok' => true, 'results' => $results, 'success' => $ok, 'total' => $tot]); exit;

        case 'run_task':
            $taskKey = preg_replace('/[^a-zA-Z0-9_]/', '', $body['task'] ?? '');
            $taskMap = [
                'dashboard_summary'    => 'rbTask_DashboardSummary',
                'active_users'         => 'rbTask_ActiveUsers',
                'deposit_users'        => 'rbTask_DepositUsers',
                'withdraw_users'       => 'rbTask_WithdrawUsers',
                'transactions_today'   => 'rbTask_TransactionsToday',
                'no_transaction_users' => 'rbTask_NoTransactionUsers',
                'panels'               => 'rbTask_Panels',
                'sub_accounts'         => 'rbTask_SubAccounts',
                'games'                => 'rbTask_Games',
                'announcements'        => 'rbTask_Announcements',
            ];
            if (!isset($taskMap[$taskKey])) {
                echo json_encode(['ok' => false, 'error' => 'Unknown task']); exit;
            }
            // Login first
            rbLogin(trim($cfg['rb_phone'] ?? ''), trim($cfg['rb_password'] ?? ''));
            $fn = $taskMap[$taskKey];
            $r  = $fn($cfg);
            echo json_encode(['ok' => true, 'result' => $r]); exit;

        case 'rb_login_test':
            $phone = trim($body['phone'] ?? $cfg['rb_phone'] ?? '');
            $pass  = trim($body['password'] ?? $cfg['rb_password'] ?? '');
            if (file_exists(RB_COOKIE_FILE)) @unlink(RB_COOKIE_FILE);
            $user  = rbLogin($phone, $pass);
            if ($user) {
                echo json_encode(['ok' => true, 'user' => $user]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'Login failed — check phone/password']);
            }
            exit;

        case 'rb_logout':
            rbLogout();
            echo json_encode(['ok' => true]); exit;

        case 'get_logs':
            $logs = file_exists(RB_LOG_FILE) ? (json_decode(file_get_contents(RB_LOG_FILE), true) ?: []) : [];
            echo json_encode(['ok' => true, 'data' => array_slice($logs, 0, 100)]); exit;

        case 'clear_logs':
            file_put_contents(RB_LOG_FILE, '[]', LOCK_EX);
            echo json_encode(['ok' => true]); exit;

        case 'set_webhook':
            $wToken = trim($cfg['webhook_token'] ?: $cfg['bot_token']);
            if (!$wToken) { echo json_encode(['ok' => false, 'error' => 'Bot token not set']); exit; }
            $pr  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $wUrl = $pr . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . '?webhook=1';
            $r = rbTg('setWebhook', ['url' => $wUrl, 'allowed_updates' => ['message', 'channel_post']], $wToken);
            echo json_encode(['ok' => $r['ok'] ?? false, 'webhook_url' => $wUrl, 'tg' => $r]); exit;

        case 'remove_webhook':
            $wToken = trim($cfg['webhook_token'] ?: $cfg['bot_token']);
            if (!$wToken) { echo json_encode(['ok' => false, 'error' => 'Bot token not set']); exit; }
            $r = rbTg('deleteWebhook', [], $wToken);
            echo json_encode(['ok' => $r['ok'] ?? false]); exit;

        case 'screenshot':
            $url   = trim($body['url'] ?? 'https://rockybook.vip');
            $token = trim($cfg['bot_token'] ?? '');
            $cid   = trim($body['chat_id'] ?? $cfg['chat_id'] ?? '');
            $cap   = trim($body['caption'] ?? "📸 <b>RockyBook</b>\n<code>{$url}</code>\n<i>" . date('Y-m-d H:i') . "</i>");
            $ok    = rbTakeScreenshot($url, $token, $cid, $cap);
            echo json_encode(['ok' => $ok]); exit;

        case 'save_links':
            $links = [];
            foreach ($body['links'] ?? [] as $lk) {
                $url = trim($lk['url'] ?? '');
                if (!$url) continue;
                $links[] = [
                    'id'            => preg_replace('/[^a-zA-Z0-9_]/', '_', $lk['id'] ?? uniqid('l_')),
                    'name'          => trim($lk['name'] ?? 'Link'),
                    'enabled'       => (bool)($lk['enabled'] ?? true),
                    'url'           => $url,
                    'method'        => strtoupper(trim($lk['method'] ?? 'GET')),
                    'headers'       => trim($lk['headers'] ?? ''),
                    'body'          => trim($lk['body'] ?? ''),
                    'timeout'       => max(5, min(120, (int)($lk['timeout'] ?? 30))),
                    'ssl_verify'    => !isset($lk['ssl_verify']) || (bool)$lk['ssl_verify'],
                ];
            }
            $cfg['links'] = $links;
            rbSaveConfig($cfg);
            rbLog('Links saved — ' . count($links) . ' rule(s)', 'info');
            echo json_encode(['ok' => true, 'count' => count($links)]); exit;

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
<title>RockyBook Runner <?= RB_VERSION ?></title>
<style>
:root{--bg:#0e0e12;--s1:#15151c;--s2:#1c1c26;--b:#2a2a3a;--t:#e8e8f0;--td:#8888aa;--tf:#555577;--c:#7c7cff;--g:#39ff14;--r:#ff4466;--y:#ffd700;--or:#ff8c00;--rb:#ff6b1a}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--t);font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;min-height:100vh}
.wrap{max-width:960px;margin:0 auto;padding:20px 16px}
h1{color:var(--rb);font-size:22px;margin-bottom:4px}
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
.brb{background:var(--rb);color:#fff}.brb:hover{opacity:.85}
.bgr{background:var(--s2);color:var(--t);border:1px solid var(--b)}.bgr:hover{border-color:var(--c)}
.bsm{padding:5px 10px;font-size:11px}
.badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700}
.ba{background:rgba(57,255,20,.15);color:var(--g);border:1px solid rgba(57,255,20,.3)}
.bi{background:rgba(255,68,102,.15);color:var(--r);border:1px solid rgba(255,68,102,.3)}
.log-box{background:var(--s2);border:1px solid var(--b);border-radius:8px;padding:12px;max-height:300px;overflow-y:auto;font-family:'Share Tech Mono',monospace;font-size:11px}
.log-entry{padding:2px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.log-ok{color:var(--g)}.log-err{color:var(--r)}.log-info{color:var(--c)}
.toast{position:fixed;bottom:24px;right:24px;background:var(--s1);border:1px solid var(--b);border-radius:8px;padding:12px 18px;font-size:13px;z-index:999;transition:.3s;opacity:0;pointer-events:none}
.toast.show{opacity:1;pointer-events:all}
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-box{background:var(--s1);border:1px solid var(--b);border-radius:14px;padding:32px;width:320px;text-align:center}
.login-box h2{color:var(--rb);margin-bottom:6px}
.login-box p{color:var(--td);font-size:12px;margin-bottom:20px}
.flex-end{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
.f1{flex:1}.mono{font-family:'Share Tech Mono',monospace;font-size:11px}
.result-box{background:var(--s2);border:1px solid var(--b);border-radius:8px;padding:14px;margin-bottom:10px;font-size:12px}
.task-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:14px}
.task-item{background:var(--s2);border:1px solid var(--b);border-radius:8px;padding:12px;display:flex;flex-direction:column;gap:8px}
.task-item .task-name{font-weight:600;font-size:13px}
.switch{display:inline-flex;align-items:center;gap:6px;cursor:pointer}
.switch input{width:auto}
.link-card{background:var(--s2);border:1px solid var(--b);border-radius:8px;padding:14px;margin-bottom:10px}
.link-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:6px}
.tag{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;background:rgba(255,107,26,.15);color:var(--rb);border:1px solid rgba(255,107,26,.3)}
@media(max-width:600px){.row{flex-direction:column}.task-grid{grid-template-columns:1fr 1fr}}
</style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<div class="login-wrap">
  <div class="login-box">
    <h2>🎯 RockyBook Runner</h2>
    <p>Rebel Admin — RockyBook Automation Panel</p>
    <div style="margin-bottom:12px"><label>Password</label><input type="password" id="lpass" placeholder="Enter admin password" onkeydown="if(event.key==='Enter')doLogin()"></div>
    <button class="btn brb" style="width:100%" onclick="doLogin()">🔓 Login</button>
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

<!-- ─── Main Panel ──────────────────────────────────────────── -->
<div class="wrap">
  <h1>🎯 RockyBook Runner <small style="font-size:13px;color:var(--td)">v<?= RB_VERSION ?></small></h1>
  <div class="sub">rockybook.vip automation — Telegram pe data bhejo | <a href="?api_action=logout" style="color:var(--r)">Logout</a></div>

  <!-- Action Bar -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
    <button class="btn bg" onclick="runAll()">▶️ Run All Tasks</button>
    <button class="btn bc" onclick="savePage()">💾 Save Config</button>
    <button class="btn bgr" onclick="testLogin()">🔑 Test RB Login</button>
    <button class="btn bgr" onclick="rbLogout()">🚪 RB Logout</button>
    <button class="btn bgr" onclick="loadLogs()">📋 Refresh Logs</button>
    <button class="btn bgr" onclick="clearLogs()">🗑️ Clear Logs</button>
    <button class="btn bgr bsm" onclick="takeScreenshot()">📸 Screenshot</button>
    <span id="run-status" style="line-height:34px;font-size:12px;color:var(--td)"></span>
  </div>

  <!-- Global Config -->
  <div class="card">
    <h2>⚙️ Global Config</h2>
    <div class="row">
      <div class="f1"><label>🤖 Telegram Bot Token</label><input type="password" id="cfg-token" placeholder="123456789:ABC..."></div>
      <div class="f1"><label>💬 Default Chat ID</label><input type="text" id="cfg-chat" placeholder="-100xxxx or @channel"></div>
    </div>
    <div class="row">
      <div class="f1"><label>📝 Message Prefix (use \n for newline)</label><input type="text" id="cfg-prefix" placeholder="🎯 &lt;b&gt;RockyBook Runner&lt;/b&gt;\n\n"></div>
      <div class="f1"><label>🔑 URL Run Secret (?run=1&amp;secret=X)</label><input type="text" id="cfg-secret" placeholder="changeme123"></div>
    </div>
    <div class="row">
      <div class="f1"><label>🔔 Webhook Bot Token (blank = use main token)</label><input type="text" id="cfg-wtoken" placeholder="Same or different token"></div>
      <div class="f1"><label>📟 Webhook Trigger Command</label><input type="text" id="cfg-wcmd" placeholder="/rb"></div>
    </div>
    <div style="margin-bottom:12px;padding:12px;background:rgba(255,107,26,.07);border:1px solid rgba(255,107,26,.25);border-radius:8px">
      <div style="color:var(--rb);font-size:12px;font-weight:700;margin-bottom:8px">🎯 RockyBook Account</div>
      <div class="row">
        <div class="f1"><label>📱 Phone (loginType)</label><input type="text" id="cfg-rbphone" placeholder="10-digit phone number"></div>
        <div class="f1"><label>🔒 Password</label><input type="password" id="cfg-rbpass" placeholder="Account password"></div>
      </div>
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

  <!-- Tasks -->
  <div class="card">
    <h2>📋 Automation Tasks <span class="tag">rockybook.vip API</span></h2>
    <div class="task-grid" id="tasks-grid"></div>
    <div id="task-results"></div>
  </div>

  <!-- Extra Links -->
  <div class="card">
    <h2>🔗 Extra Link Rules <span id="link-count" class="tag">0</span></h2>
    <div id="links-container"></div>
    <button class="btn bc" onclick="addLinkUI()" style="margin-top:6px">+ Add Link</button>
  </div>

  <!-- Logs -->
  <div class="card">
    <h2>📋 Logs</h2>
    <div class="log-box" id="log-box"><div style="color:var(--td)">Loading...</div></div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
/* ─── State ───────────────────────────────────────────── */
let _cfg = {};
let _links = [];
const TASKS = {
  dashboard_summary:   {label:'📊 Dashboard Summary',   desc:'Aaj ka deposit/withdraw/profit'},
  active_users:        {label:'🟢 Active Users',         desc:'Abhi online users'},
  deposit_users:       {label:'💰 Deposit Users',        desc:'Aaj deposit karne wale'},
  withdraw_users:      {label:'💸 Withdraw Users',       desc:'Aaj withdraw karne wale'},
  transactions_today:  {label:'🔄 Transactions Today',   desc:'Aaj ki transactions'},
  no_transaction_users:{label:'😴 No-Tx Users',         desc:'Aaj koi tx nahi ki'},
  panels:              {label:'🖥️ Panels',               desc:'Sab panels ki list'},
  sub_accounts:        {label:'👤 Sub Accounts',         desc:'Sub accounts ki list'},
  games:               {label:'🎮 Games',                desc:'Available games'},
  announcements:       {label:'📢 Announcements',        desc:'Latest announcements'},
};

/* ─── Utils ───────────────────────────────────────────── */
function g(id){ return document.getElementById(id); }
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function toast(msg,type='info'){
  const t=g('toast');
  t.textContent=msg;
  t.style.borderColor=type==='success'?'var(--g)':type==='error'?'var(--r)':'var(--c)';
  t.style.color=type==='success'?'var(--g)':type==='error'?'var(--r)':'var(--c)';
  t.classList.add('show');
  setTimeout(()=>t.classList.remove('show'),3500);
}
async function api(action,payload={}){
  const opts={method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)};
  const r=await fetch('?api_action='+action,opts).then(x=>x.json()).catch(e=>({ok:false,error:String(e)}));
  return r;
}

/* ─── Config ──────────────────────────────────────────── */
async function loadConfig(){
  const r=await fetch('?api_action=get_config').then(x=>x.json());
  if(!r.ok) return;
  const d=r.data||{};
  _cfg=d;
  g('cfg-token').value=d.bot_token||'';
  g('cfg-chat').value=d.chat_id||'';
  g('cfg-prefix').value=d.send_prefix||'';
  g('cfg-secret').value=d.run_secret||'';
  g('cfg-wtoken').value=d.webhook_token||'';
  g('cfg-wcmd').value=d.webhook_cmd||'/rb';
  g('cfg-rbphone').value=d.rb_phone||'';
  g('cfg-rbpass').value=d.rb_password||'';
  _links=d.links||[];
  renderTasks(d.tasks||{});
  renderLinks();
}

async function saveConfig(){
  const tasks={};
  Object.keys(TASKS).forEach(k=>{
    const el=g('task_'+k);
    if(el) tasks[k]=el.checked;
  });
  const payload={
    bot_token:     g('cfg-token').value.trim(),
    chat_id:       g('cfg-chat').value.trim(),
    send_prefix:   g('cfg-prefix').value,
    run_secret:    g('cfg-secret').value.trim(),
    webhook_token: g('cfg-wtoken').value.trim(),
    webhook_cmd:   g('cfg-wcmd').value.trim(),
    rb_phone:      g('cfg-rbphone').value.trim(),
    rb_password:   g('cfg-rbpass').value.trim(),
    new_pass:      g('cfg-newpass').value.trim(),
    tasks,
  };
  const r=await api('save_config',payload);
  r.ok ? toast('✅ Config saved!','success') : toast('Error: '+(r.error||''),'error');
}

async function savePage(){
  await saveConfig();
  const r=await api('save_links',{links:_links});
  r.ok ? toast('✅ Saved!','success') : toast('Error: '+(r.error||''),'error');
}

/* ─── Tasks ───────────────────────────────────────────── */
function renderTasks(enabledTasks){
  const grid=g('tasks-grid');
  grid.innerHTML='';
  Object.entries(TASKS).forEach(([key,info])=>{
    const enabled=enabledTasks[key]!==false;
    const div=document.createElement('div');
    div.className='task-item';
    div.innerHTML=`
      <div class="task-name">${info.label}</div>
      <div style="color:var(--td);font-size:11px">${info.desc}</div>
      <div style="display:flex;justify-content:space-between;align-items:center;gap:6px">
        <label class="switch"><input type="checkbox" id="task_${key}" ${enabled?'checked':''}> Enable</label>
        <button class="btn bgr bsm" onclick="runTask('${key}')">▶ Test</button>
      </div>
      <div id="task_res_${key}" style="display:none;font-size:11px;color:var(--td);margin-top:4px;white-space:pre-wrap;word-break:break-word"></div>
    `;
    grid.appendChild(div);
  });
}

async function runTask(key){
  const res=g('task_res_'+key);
  if(res){res.style.display='block';res.style.color='var(--y)';res.textContent='⏳ Running...';}
  const r=await api('run_task',{task:key});
  if(!r.ok){if(res){res.style.color='var(--r)';res.textContent='❌ Error: '+(r.error||'unknown');}return;}
  const result=r.result||{};
  if(res){
    res.style.color=result.ok?'var(--g)':'var(--r)';
    res.textContent=(result.ok?'✅ OK':'❌ FAILED')+'\n'+(result.msg||'');
    res.style.display='block';
  }
  loadLogs();
}

/* ─── Run All ─────────────────────────────────────────── */
async function runAll(){
  g('run-status').textContent='⏳ Running all tasks...';
  g('run-status').style.color='var(--y)';
  const r=await api('run_now');
  g('run-status').textContent='';
  if(!r.ok){toast('Error: '+(r.error||''),'error');return;}
  const tasks=r.results?.tasks||{};
  const ok=Object.values(tasks).filter(t=>t.ok).length;
  const tot=Object.keys(tasks).length;
  // Show mini results
  const box=g('task-results');
  box.innerHTML='<div style="margin-top:12px;font-size:12px;color:var(--td)"><b>Last Run:</b><br>'
    +Object.entries(tasks).map(([k,v])=>`<span style="color:${v.ok?'var(--g)':'var(--r)'}">${v.ok?'✅':'❌'} ${TASKS[k]?.label||k}</span>`).join(' &nbsp;')+'</div>';
  toast('✅ Done! '+ok+'/'+tot+' tasks OK','success');
  loadLogs();
}

/* ─── RockyBook Login Test ────────────────────────────── */
async function testLogin(){
  toast('Testing RockyBook login...','info');
  const r=await api('rb_login_test',{
    phone:    g('cfg-rbphone').value.trim(),
    password: g('cfg-rbpass').value.trim(),
  });
  if(r.ok){
    const u=r.user||{};
    toast('✅ Login OK! User: '+(u.clientName||u.name||JSON.stringify(u).slice(0,80)),'success');
  } else {
    toast('❌ Login failed: '+(r.error||'check credentials'),'error');
  }
}

async function rbLogout(){
  await api('rb_logout');
  toast('RockyBook session cleared','info');
}

/* ─── Screenshot ──────────────────────────────────────── */
async function takeScreenshot(){
  const url=prompt('URL to screenshot:','https://rockybook.vip');
  if(!url) return;
  toast('📸 Taking screenshot...','info');
  const r=await api('screenshot',{url,chat_id:g('cfg-chat').value.trim()});
  r.ok ? toast('✅ Screenshot sent!','success') : toast('❌ Screenshot failed','error');
}

/* ─── Webhook ─────────────────────────────────────────── */
async function setWebhook(){
  toast('Setting webhook...','info');
  const r=await api('set_webhook');
  r.ok ? toast('✅ Webhook set: '+r.webhook_url,'success') : toast('Error: '+(r.error||r.tg?.description||''),'error');
}
async function removeWebhook(){
  const r=await api('remove_webhook');
  r.ok ? toast('Webhook removed','info') : toast('Error','error');
}

/* ─── Extra Links ─────────────────────────────────────── */
let _lid=0;
function mkId(){ return 'l_'+Date.now()+'_'+(++_lid); }

function addLinkUI(preset={}){
  const id=preset.id||mkId();
  _links.push({
    id, name:preset.name||'Extra Link', enabled:preset.enabled!==false,
    url:preset.url||'', method:preset.method||'GET',
    headers:preset.headers||'', body:preset.body||'',
    timeout:preset.timeout||30, ssl_verify:preset.ssl_verify!==false,
  });
  renderLinks();
  g('links-container').lastElementChild?.scrollIntoView({behavior:'smooth'});
}

function renderLinks(){
  const c=g('links-container');
  c.innerHTML='';
  g('link-count').textContent=_links.length;
  _links.forEach((lk,i)=>{
    const div=document.createElement('div');
    div.className='link-card';
    div.innerHTML=`
<div class="link-head">
  <div style="display:flex;align-items:center;gap:8px">
    <input type="text" value="${esc(lk.name)}" style="background:transparent;border:none;border-bottom:1px solid var(--b);border-radius:0;padding:2px 4px;width:160px;color:var(--t);font-weight:600" onchange="syncLk('${lk.id}','name',this.value)">
    <span class="badge ${lk.enabled?'ba':'bi'}">${lk.enabled?'ON':'OFF'}</span>
  </div>
  <button class="btn bor bsm" onclick="deleteLink('${lk.id}')">🗑</button>
</div>
<div class="row">
  <div class="f1"><label>URL</label><input type="text" value="${esc(lk.url)}" placeholder="https://..." onchange="syncLk('${lk.id}','url',this.value)"></div>
  <div style="width:90px"><label>Method</label>
    <select onchange="syncLk('${lk.id}','method',this.value)">
      ${['GET','POST','PUT','PATCH','DELETE'].map(m=>`<option${lk.method===m?' selected':''}>${m}</option>`).join('')}
    </select>
  </div>
  <div style="width:80px"><label>Timeout(s)</label><input type="number" value="${lk.timeout||30}" min="5" max="120" onchange="syncLk('${lk.id}','timeout',+this.value)"></div>
</div>
<div class="row">
  <div class="f1"><label>Headers (Key: Value, one per line)</label><textarea rows="2" onchange="syncLk('${lk.id}','headers',this.value)">${esc(lk.headers)}</textarea></div>
  <div class="f1"><label>Body (POST)</label><textarea rows="2" onchange="syncLk('${lk.id}','body',this.value)">${esc(lk.body)}</textarea></div>
</div>
<label class="switch" style="margin-top:4px"><input type="checkbox" ${lk.enabled?'checked':''} onchange="syncLk('${lk.id}','enabled',this.checked)"> Enabled</label>
`;
    c.appendChild(div);
  });
}

function syncLk(id,field,val){ const lk=_links.find(l=>l.id===id); if(lk)lk[field]=val; }
function deleteLink(id){ if(!confirm('Delete?')) return; _links=_links.filter(l=>l.id!==id); renderLinks(); }

/* ─── Logs ────────────────────────────────────────────── */
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

/* ─── Init ────────────────────────────────────────────── */
loadConfig();
loadLogs();
</script>
</body>
</html>
