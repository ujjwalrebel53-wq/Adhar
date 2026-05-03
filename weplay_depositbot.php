<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║          REBEL WEPLAY DEPOSIT BOT                               ║
 * ║  Telegram bot — users /Deposit karke WePlay ID + amount dete    ║
 * ║  hain, payment details bot pe milte hain, admin dashboard se     ║
 * ║  payment verify karta hai.                                       ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 * Setup:
 *   1. Is file ko server pe upload karo.
 *   2. Browser mein kholo -> admin panel.
 *   3. Bot token, admin chat ID, and WePlay recharge/payment link set karo.
 *   4. "Set Webhook" dabao.
 *   5. Users ko bot link bhejo. Ve /Deposit se payment request bana sakte hain.
 *
 * Note:
 *   weplayapp.com ka public deposit API available nahi hai, isliye ye bot
 *   manual/admin approval flow use karta hai. Admin approve karne ke baad
 *   local ledger credit hota hai aur admin ko WePlay recharge link milta hai.
 */

if (!function_exists('str_starts_with')) {
    function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; }
}

define('WPB_VERSION',      '1.0');
define('WPB_CONFIG_FILE',  __DIR__ . '/wpb_config.json');
define('WPB_LOG_FILE',     __DIR__ . '/wpb_logs.json');
define('WPB_STATE_FILE',   __DIR__ . '/wpb_states.json');
define('WPB_LEDGER_FILE',  __DIR__ . '/wpb_ledger.json');
define('WPB_PENDING_FILE', __DIR__ . '/wpb_pending.json');
define('WPB_PROFILE_FILE', __DIR__ . '/wpb_profiles.json');
define('WPB_RATE_FILE',    __DIR__ . '/wpb_ratelimit.json');
define('TG_BASE',          'https://api.telegram.org/bot');
define('WPB_BLOCK_MINUTES', 30);
define('WPB_MAX_INCOMPLETE', 2);

$defaultConfig = [
    'admin_pass'       => 'rebel@2026',
    'bot_token'        => '',
    'admin_chat_id'    => '',
    'min_deposit'      => 100,
    'max_deposit'      => 100000,
    'weplay_site'      => 'https://weplayapp.com',
    'weplay_recharge'  => 'https://weplayapp.com/recharge/?region=C',
    'support_contact'  => '@Rebel_babyyy',
    'welcome_msg'      => "🎮 <b>WePlay Deposit Bot</b>\n\n<b>🆔 /id &lt;your-id&gt; — Link your WePlay ID &amp; open recharge</b>\n<b>💰 /Deposit — Create a deposit request</b>\n<b>💳 /pay — Open the secure payment section</b>\n<b>💸 /Withdrawal — Create a withdrawal request</b>\n<b>💳 /Balance — Check your balance</b>\n<b>❓ /Help — Show help</b>",
    'deposit_thanks'   => "✅ <b>Deposit submitted!</b>\n\n<b>The admin will verify the payment and credit your WePlay account.</b>",
    'card_notice'      => "🔐 <b>Do not send card details in this bot. Enter card number/CVV only on the official WePlay/payment gateway page.</b>",
    'auto_card_charge' => false,
    'card_charge_url'  => '',
    'card_charge_method' => 'POST',
    'card_charge_headers' => "Content-Type: application/json",
    'card_charge_body' => "{\"txn_id\":{txn_id_json},\"amount\":{amount},\"weplay_id\":{weplay_id_json},\"chat_id\":{chat_id_json},\"callback_url\":{callback_url_json}}",
    'card_charge_link_path' => 'payment_url',
    'card_charge_status_path' => 'status',
    'card_webhook_secret' => '',
    'card_webhook_txn_path' => 'txn_id',
    'card_webhook_status_path' => 'status',
    // Each package: label shown on button, coins, price (INR)
    'coin_packages'    => [
        ['label' => '60 Coins — ₹80',   'coins' => 60,   'price' => 80],
        ['label' => '120 Coins — ₹160', 'coins' => 120,  'price' => 160],
        ['label' => '300 Coins — ₹400', 'coins' => 300,  'price' => 400],
        ['label' => '600 Coins — ₹800', 'coins' => 600,  'price' => 800],
        ['label' => '1200 Coins — ₹1600','coins' => 1200, 'price' => 1600],
    ],
    // Payment methods shown as buttons after package selection
    'payment_methods'  => [
        ['label' => '💳 UPI / Google Pay', 'id' => 'upi'],
        ['label' => '🏦 Net Banking',       'id' => 'netbanking'],
        ['label' => '💵 Debit / Credit Card','id' => 'card'],
    ],
];

function wpbLoadConfig() {
    global $defaultConfig;
    if (!file_exists(WPB_CONFIG_FILE)) return $defaultConfig;
    $loaded = json_decode(file_get_contents(WPB_CONFIG_FILE), true);
    return is_array($loaded) ? array_merge($defaultConfig, $loaded) : $defaultConfig;
}

function wpbSaveConfig($cfg) {
    file_put_contents(WPB_CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function wpbJsonLoad($file, $default = []) {
    if (!file_exists($file)) return $default;
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : $default;
}

function wpbJsonSave($file, $data, $pretty = false) {
    $flags = JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0);
    file_put_contents($file, json_encode($data, $flags), LOCK_EX);
}

function wpbLog($text, $type = 'info') {
    $logs = wpbJsonLoad(WPB_LOG_FILE);
    array_unshift($logs, ['time' => date('c'), 'text' => $text, 'type' => $type]);
    if (count($logs) > 500) $logs = array_slice($logs, 0, 500);
    wpbJsonSave(WPB_LOG_FILE, $logs);
}

function wpbSetState($chatId, $state, $data = []) {
    $states = wpbJsonLoad(WPB_STATE_FILE);
    $states[(string)$chatId] = ['state' => $state, 'data' => $data, 'ts' => time()];
    wpbJsonSave(WPB_STATE_FILE, $states);
}

function wpbGetState($chatId) {
    $states = wpbJsonLoad(WPB_STATE_FILE);
    $state = $states[(string)$chatId] ?? null;
    if ($state && time() - ($state['ts'] ?? 0) > 1800) {
        wpbClearState($chatId);
        return null;
    }
    return $state;
}

function wpbClearState($chatId) {
    $states = wpbJsonLoad(WPB_STATE_FILE);
    unset($states[(string)$chatId]);
    wpbJsonSave(WPB_STATE_FILE, $states);
}

function wpbIsBlocked($chatId) {
    $rate = wpbJsonLoad(WPB_RATE_FILE);
    $rec = $rate[(string)$chatId] ?? null;
    if ($rec && !empty($rec['blocked_until']) && time() < $rec['blocked_until']) {
        return $rec['blocked_until'];
    }
    return false;
}

function wpbStartDeposit($chatId) {
    $rate = wpbJsonLoad(WPB_RATE_FILE);
    $key = (string)$chatId;
    if (!isset($rate[$key])) $rate[$key] = ['incomplete' => 0, 'blocked_until' => 0, 'last_start' => 0];
    $rate[$key]['incomplete']++;
    $rate[$key]['last_start'] = time();
    if ($rate[$key]['incomplete'] >= WPB_MAX_INCOMPLETE) {
        $rate[$key]['blocked_until'] = time() + (WPB_BLOCK_MINUTES * 60);
        $rate[$key]['incomplete'] = 0;
        wpbJsonSave(WPB_RATE_FILE, $rate);
        return false;
    }
    wpbJsonSave(WPB_RATE_FILE, $rate);
    return true;
}

function wpbDepositCompleted($chatId) {
    $rate = wpbJsonLoad(WPB_RATE_FILE);
    $key = (string)$chatId;
    if (isset($rate[$key])) {
        $rate[$key]['incomplete'] = 0;
        $rate[$key]['blocked_until'] = 0;
        wpbJsonSave(WPB_RATE_FILE, $rate);
    }
}

function wpbRemaining($until) {
    $mins = ceil(max(0, $until - time()) / 60);
    return $mins . ' minute' . ($mins == 1 ? '' : 's');
}

function wpbLedgerUser($chatId) {
    $ledger = wpbJsonLoad(WPB_LEDGER_FILE);
    return $ledger[(string)$chatId] ?? ['chat_id' => $chatId, 'balance' => 0, 'deposits' => [], 'withdrawals' => []];
}

function wpbLedgerDeposit($chatId, $amount, $txnId, $weplayId) {
    $ledger = wpbJsonLoad(WPB_LEDGER_FILE);
    $key = (string)$chatId;
    if (!isset($ledger[$key])) $ledger[$key] = ['chat_id' => $chatId, 'balance' => 0, 'deposits' => [], 'withdrawals' => []];
    $ledger[$key]['balance'] += (float)$amount;
    $ledger[$key]['deposits'][] = [
        'amount' => (float)$amount,
        'txn_id' => $txnId,
        'weplay_id' => $weplayId,
        'status' => 'approved',
        'time' => date('c'),
    ];
    wpbJsonSave(WPB_LEDGER_FILE, $ledger, true);
}

function wpbLedgerWithdrawal($chatId, $amount, $weplayId) {
    $ledger = wpbJsonLoad(WPB_LEDGER_FILE);
    $key = (string)$chatId;
    if (!isset($ledger[$key])) $ledger[$key] = ['chat_id' => $chatId, 'balance' => 0, 'deposits' => [], 'withdrawals' => []];
    $ledger[$key]['withdrawals'][] = [
        'amount' => (float)$amount,
        'weplay_id' => $weplayId,
        'status' => 'pending',
        'time' => date('c'),
    ];
    wpbJsonSave(WPB_LEDGER_FILE, $ledger, true);
}

function wpbGetProfile($chatId) {
    $profiles = wpbJsonLoad(WPB_PROFILE_FILE);
    return $profiles[(string)$chatId] ?? ['chat_id' => $chatId, 'weplay_id' => '', 'updated_at' => null];
}

function wpbSaveProfile($chatId, $weplayId, $userName = '') {
    $profiles = wpbJsonLoad(WPB_PROFILE_FILE);
    $profiles[(string)$chatId] = [
        'chat_id' => $chatId,
        'weplay_id' => trim($weplayId),
        'telegram_name' => $userName,
        'updated_at' => date('c'),
    ];
    wpbJsonSave(WPB_PROFILE_FILE, $profiles, true);
}

function tg($method, $params, $token) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => TG_BASE . $token . '/' . $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode($raw, true) ?: [];
}

function tgSend($token, $chatId, $text, $keyboard = null) {
    if (!$token || !$chatId) return ['ok' => false, 'description' => 'missing token/chat'];
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($keyboard) $params['reply_markup'] = json_encode($keyboard);
    return tg('sendMessage', $params, $token);
}

function wpbUserName($msg) {
    $from = $msg['from'] ?? [];
    if (!empty($from['username'])) return '@' . $from['username'];
    return trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: ('ID ' . ($from['id'] ?? ''));
}

function wpbPendingSave($txnId, $data) {
    $pending = wpbJsonLoad(WPB_PENDING_FILE);
    $pending[$txnId] = $data;
    wpbJsonSave(WPB_PENDING_FILE, $pending, true);
}

function wpbPendingGet($txnId) {
    $pending = wpbJsonLoad(WPB_PENDING_FILE);
    return $pending[$txnId] ?? null;
}

function wpbPendingUpdate($txnId, $data) {
    $pending = wpbJsonLoad(WPB_PENDING_FILE);
    if (!isset($pending[$txnId])) return false;
    $pending[$txnId] = array_merge($pending[$txnId], $data);
    wpbJsonSave(WPB_PENDING_FILE, $pending, true);
    return true;
}

function wpbBool($value) {
    if (is_bool($value)) return $value;
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function wpbJsonPath($data, $path) {
    $path = trim((string)$path);
    if ($path === '') return $data;
    foreach (explode('.', $path) as $part) {
        if (is_array($data) && array_key_exists($part, $data)) {
            $data = $data[$part];
        } elseif (is_array($data) && ctype_digit($part) && array_key_exists((int)$part, $data)) {
            $data = $data[(int)$part];
        } else {
            return null;
        }
    }
    return $data;
}

function wpbCurrentUrl($extraQuery = []) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $base = $scheme . ($_SERVER['HTTP_HOST'] ?? '') . strtok($_SERVER['REQUEST_URI'] ?? '', '?');
    return $base . ($extraQuery ? ('?' . http_build_query($extraQuery)) : '');
}

function wpbTemplate($template, $vars) {
    foreach ($vars as $key => $value) {
        $template = str_replace('{json:' . $key . '}', json_encode($value, JSON_UNESCAPED_UNICODE), $template);
        if (is_array($value)) $value = json_encode($value, JSON_UNESCAPED_UNICODE);
        $template = str_replace('{' . $key . '}', (string)$value, $template);
    }
    return $template;
}

function wpbTemplateVars($vars) {
    foreach ($vars as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $vars[$key . '_json'] = json_encode($value, JSON_UNESCAPED_UNICODE);
        }
    }
    return $vars;
}

function wpbHeaderLines($headersText) {
    $headers = [];
    foreach (explode("\n", (string)$headersText) as $line) {
        $line = trim($line);
        if ($line !== '' && strpos($line, ':') !== false) $headers[] = $line;
    }
    return $headers;
}

function wpbIsPaidStatus($status) {
    return in_array(strtolower(trim((string)$status)), ['paid', 'succeeded', 'success', 'approved', 'captured', 'complete', 'completed'], true);
}

function wpbIsFailedStatus($status) {
    return in_array(strtolower(trim((string)$status)), ['failed', 'failure', 'declined', 'cancelled', 'canceled', 'rejected', 'expired'], true);
}

function wpbPaymentLinkKeyboard($url, $label = '💳 Complete Secure Card Payment') {
    return $url ? ['inline_keyboard' => [[['text' => $label, 'url' => $url]]]] : null;
}

function wpbApprovePending($cfg, $txnId, $p, $source = 'admin') {
    if (($p['status'] ?? '') === 'approved') return ['ok' => false, 'message' => 'Already approved'];
    if (($p['status'] ?? '') === 'rejected') return ['ok' => false, 'message' => 'Already rejected'];
    wpbPendingUpdate($txnId, ['status' => 'approved', 'approved_at' => date('c'), 'approved_by' => $source]);
    wpbLedgerDeposit($p['chat_id'], $p['amount'], $txnId, $p['weplay_id'] ?? '');
    $token = trim($cfg['bot_token'] ?? '');
    $coinsNote = !empty($p['coins']) ? "\n<b>Coins:</b> <b>" . (int)$p['coins'] . " Coins</b>" : '';
    tgSend($token, $p['chat_id'], "✅ <b>Deposit Approved!</b>\n\n<b>Amount:</b> <b>₹" . number_format((float)$p['amount'], 2) . "</b>{$coinsNote}\n<b>Txn:</b> <code>{$txnId}</code>");
    wpbLog("Approved txn={$txnId} source={$source}", 'success');
    return ['ok' => true, 'message' => 'Approved'];
}

function wpbRejectPending($cfg, $txnId, $p, $source = 'admin') {
    if (($p['status'] ?? '') === 'approved') return ['ok' => false, 'message' => 'Already approved'];
    if (($p['status'] ?? '') === 'rejected') return ['ok' => false, 'message' => 'Already rejected'];
    wpbPendingUpdate($txnId, ['status' => 'rejected', 'rejected_at' => date('c'), 'rejected_by' => $source]);
    $token = trim($cfg['bot_token'] ?? '');
    tgSend($token, $p['chat_id'], "❌ <b>Deposit Rejected</b>\n\n<b>Txn:</b> <code>{$txnId}</code>\n<b>Support:</b> " . htmlspecialchars($cfg['support_contact'], ENT_NOQUOTES, 'UTF-8'));
    wpbLog("Rejected txn={$txnId} source={$source}", 'error');
    return ['ok' => true, 'message' => 'Rejected'];
}

function wpbCreateCardCharge($cfg, $txnId, $pending) {
    if (!wpbBool($cfg['auto_card_charge'] ?? false) || empty($cfg['card_charge_url'])) {
        return ['enabled' => false];
    }

    $callbackQuery = ['card_webhook' => 1];
    if (!empty($cfg['card_webhook_secret'])) $callbackQuery['secret'] = $cfg['card_webhook_secret'];
    $vars = wpbTemplateVars(array_merge($pending, [
        'txn_id' => $txnId,
        'amount' => number_format((float)($pending['amount'] ?? 0), 2, '.', ''),
        'callback_url' => wpbCurrentUrl($callbackQuery),
    ]));

    $method = strtoupper(trim((string)($cfg['card_charge_method'] ?? 'POST'))) ?: 'POST';
    $body = wpbTemplate((string)($cfg['card_charge_body'] ?? ''), $vars);
    $headers = wpbHeaderLines($cfg['card_charge_headers'] ?? '');
    $ch = curl_init();
    $opts = [
        CURLOPT_URL => $cfg['card_charge_url'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = $body;
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        if ($body !== '') $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $json = json_decode($raw ?: '', true);
    $status = is_array($json) ? wpbJsonPath($json, $cfg['card_charge_status_path'] ?? 'status') : null;
    $paymentUrl = is_array($json) ? wpbJsonPath($json, $cfg['card_charge_link_path'] ?? 'payment_url') : null;
    wpbPendingUpdate($txnId, [
        'charge_http_code' => $code,
        'charge_status' => $status,
        'charge_payment_url' => is_scalar($paymentUrl) ? (string)$paymentUrl : '',
        'charge_response_at' => date('c'),
    ]);

    if ($err || $code < 200 || $code >= 300) {
        wpbLog("Card charge failed txn={$txnId} http={$code} error={$err}", 'error');
        return ['enabled' => true, 'ok' => false, 'error' => $err ?: ('HTTP ' . $code)];
    }
    if (wpbIsPaidStatus($status)) {
        $fresh = wpbPendingGet($txnId) ?: $pending;
        wpbApprovePending($cfg, $txnId, $fresh, 'card_gateway');
    } elseif (wpbIsFailedStatus($status)) {
        $fresh = wpbPendingGet($txnId) ?: $pending;
        wpbRejectPending($cfg, $txnId, $fresh, 'card_gateway');
    }

    return ['enabled' => true, 'ok' => true, 'status' => $status, 'payment_url' => is_scalar($paymentUrl) ? (string)$paymentUrl : ''];
}

function wpbHandleCardWebhook($cfg) {
    if (!empty($cfg['card_webhook_secret'])) {
        $provided = (string)($_GET['secret'] ?? $_SERVER['HTTP_X_WPB_CARD_SECRET'] ?? '');
        if (!hash_equals((string)$cfg['card_webhook_secret'], $provided)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Invalid webhook secret']);
            return;
        }
    }

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);
    if (!is_array($payload) || !$payload) {
        $payload = array_merge($_GET, $_POST);
        unset($payload['card_webhook'], $payload['secret']);
    }

    $txnId = wpbJsonPath($payload, $cfg['card_webhook_txn_path'] ?? 'txn_id');
    $status = wpbJsonPath($payload, $cfg['card_webhook_status_path'] ?? 'status');
    $txnId = is_scalar($txnId) ? trim((string)$txnId) : '';
    $statusText = is_scalar($status) ? trim((string)$status) : '';
    $pending = $txnId !== '' ? wpbPendingGet($txnId) : null;

    if (!$pending) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Transaction not found']);
        return;
    }

    wpbPendingUpdate($txnId, [
        'gateway_status' => $statusText,
        'gateway_webhook_at' => date('c'),
    ]);
    $pending = wpbPendingGet($txnId) ?: $pending;

    if (wpbIsPaidStatus($statusText)) {
        $result = wpbApprovePending($cfg, $txnId, $pending, 'card_webhook');
    } elseif (wpbIsFailedStatus($statusText)) {
        $result = wpbRejectPending($cfg, $txnId, $pending, 'card_webhook');
    } else {
        wpbLog("Card webhook txn={$txnId} status={$statusText}", 'info');
        $result = ['ok' => true, 'message' => 'Status recorded'];
    }

    echo json_encode(['ok' => $result['ok'], 'message' => $result['message']]);
}

function wpbAdminButtons($txnId) {
    return ['inline_keyboard' => [[
        ['text' => '✅ Approve', 'callback_data' => 'approve:' . $txnId],
        ['text' => '❌ Reject', 'callback_data' => 'reject:' . $txnId],
    ]]];
}

function wpbPaymentKeyboard($cfg) {
    $buttons = [];
    if (!empty($cfg['weplay_recharge'])) {
        $buttons[] = [['text' => '💳 Open Secure WePlay Payment', 'url' => $cfg['weplay_recharge']]];
    }
    if (!empty($cfg['weplay_site'])) {
        $buttons[] = [['text' => '🎮 Open WePlay', 'url' => $cfg['weplay_site']]];
    }
    return $buttons ? ['inline_keyboard' => $buttons] : null;
}

/**
 * Build inline keyboard with coin package buttons (2 per row).
 * Each button callback: pkg:<index>
 */
function wpbCoinPackagesKeyboard($cfg) {
    $packages = $cfg['coin_packages'] ?? [];
    $rows = [];
    $row = [];
    foreach ($packages as $i => $pkg) {
        $row[] = ['text' => $pkg['label'], 'callback_data' => 'pkg:' . $i];
        if (count($row) === 2) { $rows[] = $row; $row = []; }
    }
    if ($row) $rows[] = $row;
    return $rows ? ['inline_keyboard' => $rows] : null;
}

/**
 * Build inline keyboard with payment method buttons (1 per row).
 * Each button callback: pm:<pkg_index>:<method_id>
 */
function wpbPaymentMethodsKeyboard($cfg, $pkgIndex) {
    $methods = $cfg['payment_methods'] ?? [];
    $rows = [];
    foreach ($methods as $m) {
        $rows[] = [['text' => $m['label'], 'callback_data' => 'pm:' . $pkgIndex . ':' . $m['id']]];
    }
    return $rows ? ['inline_keyboard' => $rows] : null;
}

/**
 * Show coin package selection to user after /id is linked.
 * Also sends a URL button to open weplay recharge page.
 */
function wpbShowCoinPackages($cfg, $chatId, $token, $weplayId) {
    $rechargeUrl = $cfg['weplay_recharge'] ?? 'https://weplayapp.com/recharge/?region=C';
    $msg = "🎮 <b>WePlay Recharge</b>\n\n"
        . "<b>Linked ID:</b> <code>" . htmlspecialchars($weplayId, ENT_NOQUOTES, 'UTF-8') . "</code>\n\n"
        . "🛒 <b>Select a coin package to recharge:</b>";

    $keyboard = wpbCoinPackagesKeyboard($cfg);
    // Append a URL button to open the recharge page directly
    if (!empty($rechargeUrl)) {
        $keyboard['inline_keyboard'][] = [['text' => '🌐 Open WePlay Recharge Page', 'url' => $rechargeUrl]];
    }
    tgSend($token, $chatId, $msg, $keyboard);
}

function wpbStartCardDeposit($cfg, $chatId, $token, $weplayId) {
    wpbSetState($chatId, 'await_amount', ['weplay_id' => $weplayId, 'payment_method' => 'card']);
    tgSend(
        $token,
        $chatId,
        "💳 <b>Card payment selected.</b>\n\n"
        . "<b>WePlay ID:</b> <code>" . htmlspecialchars($weplayId, ENT_NOQUOTES, 'UTF-8') . "</code>\n\n"
        . "<b>Please send the deposit amount.</b>\n\n"
        . "<b>Minimum:</b> ₹" . (int)$cfg['min_deposit'] . "\n"
        . "<b>Maximum:</b> ₹" . (int)$cfg['max_deposit']
    );
}

function wpbShowPaymentSection($cfg, $chatId, $token) {
    $profile = wpbGetProfile($chatId);
    if (empty($profile['weplay_id'])) {
        wpbSetState($chatId, 'await_link_id', []);
        tgSend($token, $chatId, "🆔 <b>Please send your WePlay User ID / Username first.</b>\n\n<b>Example:</b> <code>12345678</code>\n<b>Send /cancel to cancel.</b>");
        return;
    }

    $cardNotice = strip_tags((string)($cfg['card_notice'] ?? ''), '<b><i><u><code>');
    $msg = "🎮 <b>WePlay Payment Section</b>\n\n"
        . "<b>Linked WePlay ID:</b> <code>" . htmlspecialchars($profile['weplay_id'], ENT_NOQUOTES, 'UTF-8') . "</code>\n\n"
        . "<b>💳 For credit/debit card payment, open the official secure page below.</b>\n"
        . $cardNotice . "\n\n"
        . "<b>After payment is completed, the admin will verify it in the payment dashboard.</b>";
    tgSend($token, $chatId, $msg, wpbPaymentKeyboard($cfg));
}

function wpbHandleDepositCommand($cfg, $chatId, $token) {
    if ($blocked = wpbIsBlocked($chatId)) {
        tgSend($token, $chatId, "⏳ <b>You are temporarily blocked.</b>\n<b>Please try again after " . wpbRemaining($blocked) . ".</b>");
        return;
    }
    if (!wpbStartDeposit($chatId)) {
        tgSend($token, $chatId, "⚠️ <b>Too many incomplete deposits were detected. Please try again after " . WPB_BLOCK_MINUTES . " minutes.</b>");
        return;
    }
    $profile = wpbGetProfile($chatId);
    if (!empty($profile['weplay_id'])) {
        wpbStartCardDeposit($cfg, $chatId, $token, $profile['weplay_id']);
        return;
    }

    wpbSetState($chatId, 'await_weplay_id', []);
    tgSend($token, $chatId, "🎮 <b>Please send your WePlay User ID / Username.</b>\n\n<b>Tip: Use /id to link your ID permanently.</b>\n<b>Send /cancel to cancel.</b>");
}

function wpbHandleText($cfg, $msg) {
    $token = trim($cfg['bot_token'] ?? '');
    $chatId = $msg['chat']['id'] ?? '';
    $text = trim($msg['text'] ?? '');
    $state = wpbGetState($chatId);

    if ($text === '/start' || $text === '/Start') {
        wpbClearState($chatId);
        tgSend($token, $chatId, $cfg['welcome_msg']);
        return;
    }
    if (strcasecmp($text, '/help') === 0) {
        tgSend($token, $chatId, "❓ <b>Help</b>\n\n<b>/id - Link your WePlay ID and open the payment section</b>\n<b>/pay - Open the secure WePlay payment page</b>\n<b>/Deposit - Create a deposit request</b>\n<b>/Withdrawal - Create a withdrawal request</b>\n<b>/Balance - Check your local balance</b>\n<b>/cancel - Cancel the current process</b>\n\n<b>Support:</b> " . htmlspecialchars($cfg['support_contact'], ENT_NOQUOTES, 'UTF-8'));
        return;
    }
    if (strcasecmp($text, '/cancel') === 0) {
        wpbClearState($chatId);
        tgSend($token, $chatId, "✅ <b>Cancelled.</b>");
        return;
    }
    if (strcasecmp($text, '/deposit') === 0) {
        wpbHandleDepositCommand($cfg, $chatId, $token);
        return;
    }
    if (preg_match('/^\/id(?:\s+(.+))?$/i', $text, $idMatch)) {
        $inlineId = isset($idMatch[1]) ? trim($idMatch[1]) : '';
        if ($inlineId !== '') {
            // ID provided inline: /id 12345678
            wpbSaveProfile($chatId, $inlineId, wpbUserName($msg));
            wpbClearState($chatId);
            tgSend($token, $chatId, "✅ <b>WePlay ID linked:</b> <code>" . htmlspecialchars($inlineId, ENT_NOQUOTES, 'UTF-8') . "</code>\n\n<b>Opening recharge page…</b>");
            wpbShowCoinPackages($cfg, $chatId, $token, $inlineId);
        } else {
            $profile = wpbGetProfile($chatId);
            if (!empty($profile['weplay_id'])) {
                tgSend($token, $chatId, "✅ <b>Linked WePlay ID:</b> <code>" . htmlspecialchars($profile['weplay_id'], ENT_NOQUOTES, 'UTF-8') . "</code>\n\n<b>Send a new WePlay ID now to update it.</b>");
            } else {
                tgSend($token, $chatId, "🆔 <b>Send your WePlay User ID / Username:</b>\n\n<b>Example:</b> <code>/id 12345678</code>\n\n<b>Or just send your ID as the next message.</b>\n<b>Send /cancel to cancel.</b>");
            }
            wpbSetState($chatId, 'await_link_id', []);
        }
        return;
    }
    if (strcasecmp($text, '/pay') === 0) {
        wpbShowPaymentSection($cfg, $chatId, $token);
        return;
    }
    if (strcasecmp($text, '/balance') === 0) {
        $user = wpbLedgerUser($chatId);
        tgSend($token, $chatId, "💳 <b>Your Balance</b>\n\n<b>Amount:</b> <b>₹" . number_format((float)$user['balance'], 2) . "</b>");
        return;
    }
    if (strcasecmp($text, '/withdrawal') === 0) {
        wpbSetState($chatId, 'withdraw_weplay_id', []);
        tgSend($token, $chatId, "🎮 <b>Please send your WePlay User ID / Username for withdrawal.</b>");
        return;
    }

    if (!$state) {
        tgSend($token, $chatId, "❓ <b>Command not recognized. Please use /Deposit, /Withdrawal, /Balance, /id, /pay, or /Help.</b>");
        return;
    }

    $s = $state['state'] ?? '';
    $data = $state['data'] ?? [];

    if ($s === 'await_weplay_id') {
        if (mb_strlen($text) < 2) {
            tgSend($token, $chatId, "⚠️ <b>Please send a valid WePlay ID.</b>");
            return;
        }
        $data['weplay_id'] = $text;
        wpbStartCardDeposit($cfg, $chatId, $token, $data['weplay_id']);
        return;
    }

    if ($s === 'await_link_id') {
        if (mb_strlen($text) < 2) {
            tgSend($token, $chatId, "⚠️ <b>Please send a valid WePlay ID.</b>");
            return;
        }
        wpbSaveProfile($chatId, $text, wpbUserName($msg));
        wpbClearState($chatId);
        tgSend($token, $chatId, "✅ <b>WePlay ID linked:</b> <code>" . htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8') . "</code>\n\n<b>Opening recharge page…</b>");
        wpbShowCoinPackages($cfg, $chatId, $token, $text);
        return;
    }

    if ($s === 'await_amount') {
        $amount = (float)preg_replace('/[^0-9.]/', '', $text);
        if ($amount < (float)$cfg['min_deposit'] || $amount > (float)$cfg['max_deposit']) {
            tgSend($token, $chatId, "❌ <b>The amount must be between ₹" . (int)$cfg['min_deposit'] . " and ₹" . (int)$cfg['max_deposit'] . ".</b>");
            return;
        }
        $txnId = 'WP' . date('ymdHis') . mt_rand(100, 999);
        $pending = [
            'txn_id' => $txnId,
            'chat_id' => $chatId,
            'user_name' => wpbUserName($msg),
            'weplay_id' => $data['weplay_id'] ?? '',
            'amount' => $amount,
            'payment_method' => 'card',
            'status' => 'pending_verification',
            'created_at' => date('c'),
        ];
        wpbPendingSave($txnId, $pending);
        wpbClearState($chatId);
        wpbDepositCompleted($chatId);
        $cardNotice = strip_tags((string)($cfg['card_notice'] ?? ''), '<b><i><u><code>');
        $charge = wpbCreateCardCharge($cfg, $txnId, $pending);
        $payUrl = $charge['payment_url'] ?? '';
        $keyboard = $payUrl ? wpbPaymentLinkKeyboard($payUrl) : wpbPaymentKeyboard($cfg);
        $afterText = !empty($charge['enabled'])
            ? "<b>Use the secure payment button below. Once the gateway confirms payment, your deposit will be approved automatically.</b>"
            : "<b>After payment is completed, the admin will verify it and you will receive a success notification.</b>";
        if (!empty($charge['enabled']) && empty($charge['ok'])) {
            $afterText = "<b>Automatic charge could not be started right now. Please use the fallback payment link below; admin verification will still be available.</b>";
        }
        tgSend(
            $token,
            $chatId,
            "💳 <b>Card Payment Selected</b>\n\n"
            . "<b>🎮 WePlay ID:</b> <code>" . htmlspecialchars($pending['weplay_id'], ENT_NOQUOTES, 'UTF-8') . "</code>\n"
            . "<b>💵 Amount:</b> <b>₹" . number_format($amount, 2) . "</b>\n"
            . "<b>🧾 Transaction ID:</b> <code>{$txnId}</code>\n\n"
            . "<b>Open the secure payment page below and enter your card details there.</b>\n"
            . $cardNotice . "\n\n"
            . $afterText,
            $keyboard
        );
        wpbNotifyAdmin($cfg, $txnId);
        wpbLog("Card deposit request txn={$txnId} chat={$chatId} amount={$amount}", 'success');
        return;
    }

    if ($s === 'withdraw_weplay_id') {
        $data['weplay_id'] = $text;
        wpbSetState($chatId, 'withdraw_amount', $data);
        tgSend($token, $chatId, "💸 <b>Please send the withdrawal amount.</b>");
        return;
    }

    if ($s === 'withdraw_amount') {
        $amount = (float)preg_replace('/[^0-9.]/', '', $text);
        if ($amount <= 0) {
            tgSend($token, $chatId, "⚠️ <b>Please send a valid amount.</b>");
            return;
        }
        wpbLedgerWithdrawal($chatId, $amount, $data['weplay_id'] ?? '');
        wpbClearState($chatId);
        tgSend($token, $chatId, "✅ <b>Withdrawal request submitted.</b>\n<b>Support:</b> " . htmlspecialchars($cfg['support_contact'], ENT_NOQUOTES, 'UTF-8'));
        if (!empty($cfg['admin_chat_id'])) {
            tgSend($token, $cfg['admin_chat_id'], "💸 <b>Withdrawal Request</b>\n\n<b>User:</b> " . htmlspecialchars(wpbUserName($msg), ENT_NOQUOTES, 'UTF-8') . "\n<b>Chat ID:</b> <code>{$chatId}</code>\n<b>WePlay ID:</b> <code>" . htmlspecialchars($data['weplay_id'] ?? '', ENT_NOQUOTES, 'UTF-8') . "</code>\n<b>Amount:</b> <b>₹" . number_format($amount, 2) . "</b>");
        }
        return;
    }
}

function wpbNotifyAdmin($cfg, $txnId) {
    $token = trim($cfg['bot_token'] ?? '');
    $admin = trim($cfg['admin_chat_id'] ?? '');
    $p = wpbPendingGet($txnId);
    if (!$token || !$admin || !$p) return;
    $coinsLine = !empty($p['coins']) ? "<b>Coins:</b> <b>" . (int)$p['coins'] . " Coins</b>\n" : '';
    $msg = "🧾 <b>WePlay Deposit Submitted</b>\n\n"
        . "<b>Txn:</b> <code>{$txnId}</code>\n"
        . "<b>User:</b> " . htmlspecialchars($p['user_name'] ?? '', ENT_NOQUOTES, 'UTF-8') . "\n"
        . "<b>Chat ID:</b> <code>" . htmlspecialchars((string)$p['chat_id'], ENT_NOQUOTES, 'UTF-8') . "</code>\n"
        . "<b>WePlay ID:</b> <code>" . htmlspecialchars($p['weplay_id'] ?? '', ENT_NOQUOTES, 'UTF-8') . "</code>\n"
        . $coinsLine
        . "<b>Amount:</b> <b>₹" . number_format((float)$p['amount'], 2) . "</b>\n"
        . "<b>Payment Method:</b> <b>" . strtoupper(htmlspecialchars($p['payment_method'] ?? 'card', ENT_NOQUOTES, 'UTF-8')) . "</b>\n"
        . "\n<b>Verify the payment from the WePlay/payment dashboard, then approve or reject.</b>\n"
        . "<b>Recharge link:</b> " . htmlspecialchars($cfg['weplay_recharge'], ENT_NOQUOTES, 'UTF-8');
    tgSend($token, $admin, $msg, wpbAdminButtons($txnId));
}

function wpbHandleCallback($cfg, $cb) {
    $token = trim($cfg['bot_token'] ?? '');
    $data = $cb['data'] ?? '';
    $cbId = $cb['id'] ?? '';
    $chatId = $cb['message']['chat']['id'] ?? ($cb['from']['id'] ?? '');

    // ── Coin package selected: pkg:<index> ──────────────────────────────────
    if (preg_match('/^pkg:(\d+)$/', $data, $m)) {
        $pkgIndex = (int)$m[1];
        $packages = $cfg['coin_packages'] ?? [];
        if (!isset($packages[$pkgIndex])) {
            tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Package not found'], $token);
            return;
        }
        $pkg = $packages[$pkgIndex];
        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => '✅ Package selected!'], $token);

        $profile = wpbGetProfile($chatId);
        $weplayId = $profile['weplay_id'] ?? '';

        $msg = "🛒 <b>Package Selected</b>\n\n"
            . "🎮 <b>Coins:</b> <b>" . (int)$pkg['coins'] . " Coins</b>\n"
            . "💵 <b>Price:</b> <b>₹" . number_format((float)$pkg['price'], 2) . "</b>\n"
            . ($weplayId ? "\n<b>WePlay ID:</b> <code>" . htmlspecialchars($weplayId, ENT_NOQUOTES, 'UTF-8') . "</code>\n" : '')
            . "\n💳 <b>Select a payment method:</b>";

        $keyboard = wpbPaymentMethodsKeyboard($cfg, $pkgIndex);
        tgSend($token, $chatId, $msg, $keyboard);
        return;
    }

    // ── Payment method selected: pm:<pkg_index>:<method_id> ─────────────────
    if (preg_match('/^pm:(\d+):([a-z0-9_]+)$/', $data, $m)) {
        $pkgIndex = (int)$m[1];
        $methodId = $m[2];
        $packages = $cfg['coin_packages'] ?? [];
        $methods = $cfg['payment_methods'] ?? [];

        $pkg = $packages[$pkgIndex] ?? null;
        $method = null;
        foreach ($methods as $met) { if ($met['id'] === $methodId) { $method = $met; break; } }

        if (!$pkg || !$method) {
            tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Invalid selection'], $token);
            return;
        }

        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => '✅ Payment method selected!'], $token);

        $profile = wpbGetProfile($chatId);
        $weplayId = $profile['weplay_id'] ?? '';

        // Create a pending deposit record so admin can verify
        if (!empty($weplayId)) {
            $txnId = 'WP' . date('ymdHis') . mt_rand(100, 999);
            $pending = [
                'txn_id'         => $txnId,
                'chat_id'        => $chatId,
                'user_name'      => ($profile['telegram_name'] ?? ('ID ' . $chatId)),
                'weplay_id'      => $weplayId,
                'coins'          => (int)$pkg['coins'],
                'amount'         => (float)$pkg['price'],
                'payment_method' => $methodId,
                'status'         => 'pending_verification',
                'created_at'     => date('c'),
            ];
            wpbPendingSave($txnId, $pending);
            $charge = ($methodId === 'card') ? wpbCreateCardCharge($cfg, $txnId, $pending) : ['enabled' => false];
            $rechargeUrl = $cfg['weplay_recharge'] ?? 'https://weplayapp.com/recharge/?region=C';
            $payUrl = $charge['payment_url'] ?? '';
            $keyboard = $payUrl ? wpbPaymentLinkKeyboard($payUrl) : ['inline_keyboard' => [[['text' => '💳 Complete Payment on WePlay', 'url' => $rechargeUrl]]]];
            $cardNotice = htmlspecialchars($cfg['card_notice'] ?? '', ENT_NOQUOTES, 'UTF-8');
            $autoLine = ($methodId === 'card' && !empty($charge['enabled']))
                ? "\n<b>Gateway confirmation will approve the deposit automatically.</b>"
                : "\n<b>Admin will verify the payment after completion.</b>";
            if ($methodId === 'card' && !empty($charge['enabled']) && empty($charge['ok'])) {
                $autoLine = "\n<b>Automatic charge could not be started right now. Use the fallback payment link; admin verification remains available.</b>";
            }
            $msg = "✅ <b>Payment Method Selected</b>\n\n"
                . "🎮 <b>Package:</b> " . htmlspecialchars($pkg['label'], ENT_NOQUOTES, 'UTF-8') . "\n"
                . "💳 <b>Method:</b> " . htmlspecialchars($method['label'], ENT_NOQUOTES, 'UTF-8') . "\n"
                . "<b>WePlay ID:</b> <code>" . htmlspecialchars($weplayId, ENT_NOQUOTES, 'UTF-8') . "</code>\n"
                . "<b>Txn:</b> <code>{$txnId}</code>\n"
                . "\n<b>🔗 Open the secure link below to complete your payment:</b>\n\n"
                . $cardNotice
                . $autoLine;
            tgSend($token, $chatId, $msg, $keyboard);
            wpbNotifyAdmin($cfg, $txnId);
            wpbLog("Deposit via pkg {$pkgIndex} method={$methodId} txn={$txnId} chat={$chatId}", 'success');
        } else {
            tgSend($token, $chatId, "⚠️ <b>Please link your WePlay ID first with /id.</b>");
        }
        return;
    }

    // ── Admin approve/reject ─────────────────────────────────────────────────
    $admin = (string)($cfg['admin_chat_id'] ?? '');
    $fromId = (string)($cb['from']['id'] ?? '');
    if ($admin && $admin[0] !== '-' && $fromId !== $admin) {
        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Admin only', 'show_alert' => true], $token);
        return;
    }
    if (!preg_match('/^(approve|reject):(.+)$/', $data, $m)) return;
    $action = $m[1];
    $txnId = $m[2];
    $p = wpbPendingGet($txnId);
    if (!$p) {
        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Transaction not found'], $token);
        return;
    }
    if (($p['status'] ?? '') === 'approved' || ($p['status'] ?? '') === 'rejected') {
        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Already handled'], $token);
        return;
    }
    if ($action === 'approve') {
        wpbApprovePending($cfg, $txnId, $p, 'admin');
        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Approved'], $token);
    } else {
        wpbRejectPending($cfg, $txnId, $p, 'admin');
        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Rejected'], $token);
    }
}

session_start();
$cfg = wpbLoadConfig();
$isLoggedIn = !empty($_SESSION['wpb_ok']);

if (isset($_GET['webhook'])) {
    $update = json_decode(file_get_contents('php://input'), true);
    if (!is_array($update)) { http_response_code(200); exit; }
    if (!empty($update['callback_query'])) {
        wpbHandleCallback($cfg, $update['callback_query']);
    } else {
        $msg = $update['message'] ?? $update['channel_post'] ?? null;
        if ($msg) {
            wpbHandleText($cfg, $msg);
        }
    }
    http_response_code(200);
    exit;
}

if (isset($_GET['card_webhook'])) {
    header('Content-Type: application/json');
    wpbHandleCardWebhook($cfg);
    exit;
}

if (isset($_GET['api_action'])) {
    header('Content-Type: application/json');
    $act = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['api_action'] ?? '');
    if ($act === 'login') {
        if (($_POST['pass'] ?? '') === $cfg['admin_pass']) {
            $_SESSION['wpb_ok'] = true;
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
            $safe = $cfg; unset($safe['admin_pass']);
            echo json_encode(['ok' => true, 'data' => $safe]); exit;
        case 'save_config':
            foreach (['bot_token','admin_chat_id','weplay_site','weplay_recharge','support_contact','welcome_msg','deposit_thanks','card_notice','card_charge_url','card_charge_method','card_charge_headers','card_charge_body','card_charge_link_path','card_charge_status_path','card_webhook_secret','card_webhook_txn_path','card_webhook_status_path'] as $k) {
                if (isset($body[$k])) $cfg[$k] = trim((string)$body[$k]);
            }
            if (isset($body['auto_card_charge'])) $cfg['auto_card_charge'] = wpbBool($body['auto_card_charge']);
            if (isset($body['min_deposit'])) $cfg['min_deposit'] = max(1, (int)$body['min_deposit']);
            if (isset($body['max_deposit'])) $cfg['max_deposit'] = max($cfg['min_deposit'], (int)$body['max_deposit']);
            if (!empty($body['new_pass']) && strlen(trim($body['new_pass'])) >= 4) $cfg['admin_pass'] = trim($body['new_pass']);
            if (isset($body['coin_packages']) && is_array($body['coin_packages'])) {
                $cfg['coin_packages'] = array_values(array_filter(array_map(function($p) {
                    if (!is_array($p)) return null;
                    $label = trim((string)($p['label'] ?? ''));
                    $coins = max(0, (int)($p['coins'] ?? 0));
                    $price = max(0, (float)($p['price'] ?? 0));
                    if ($label === '' || $coins === 0) return null;
                    return ['label' => $label, 'coins' => $coins, 'price' => $price];
                }, $body['coin_packages'])));
            }
            if (isset($body['payment_methods']) && is_array($body['payment_methods'])) {
                $cfg['payment_methods'] = array_values(array_filter(array_map(function($m) {
                    if (!is_array($m)) return null;
                    $label = trim((string)($m['label'] ?? ''));
                    $id = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($m['id'] ?? '')));
                    if ($label === '' || $id === '') return null;
                    return ['label' => $label, 'id' => $id];
                }, $body['payment_methods'])));
            }
            wpbSaveConfig($cfg);
            wpbLog('Config saved', 'info');
            echo json_encode(['ok' => true]); exit;
        case 'set_webhook':
            if (empty($cfg['bot_token'])) { echo json_encode(['ok' => false, 'error' => 'Bot token missing']); exit; }
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $url = $scheme . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . '?webhook=1';
            $r = tg('setWebhook', ['url' => $url, 'allowed_updates' => ['message', 'channel_post', 'callback_query']], $cfg['bot_token']);
            echo json_encode(['ok' => $r['ok'] ?? false, 'webhook_url' => $url, 'tg' => $r]); exit;
        case 'remove_webhook':
            $r = tg('deleteWebhook', [], $cfg['bot_token']);
            echo json_encode(['ok' => $r['ok'] ?? false, 'tg' => $r]); exit;
        case 'get_logs':
            echo json_encode(['ok' => true, 'data' => array_slice(wpbJsonLoad(WPB_LOG_FILE), 0, 100)]); exit;
        case 'get_pending':
            echo json_encode(['ok' => true, 'data' => wpbJsonLoad(WPB_PENDING_FILE)]); exit;
        case 'clear_logs':
            wpbJsonSave(WPB_LOG_FILE, []);
            echo json_encode(['ok' => true]); exit;
        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit;
    }
}

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>WePlay Deposit Bot <?= WPB_VERSION ?></title>
<style>
:root{--bg:#0d1017;--s1:#141a24;--s2:#1b2330;--b:#2b3545;--t:#eef3ff;--td:#9aa8bd;--c:#6ee7ff;--g:#37ff8b;--r:#ff5470;--y:#ffd166}
*{box-sizing:border-box;margin:0;padding:0}body{background:var(--bg);color:var(--t);font-family:Segoe UI,system-ui,sans-serif;font-size:14px;min-height:100vh}.wrap{max-width:920px;margin:0 auto;padding:20px 16px}.card{background:var(--s1);border:1px solid var(--b);border-radius:12px;padding:18px;margin-bottom:16px}h1{color:var(--c);font-size:23px}.sub{color:var(--td);font-size:12px;margin:4px 0 18px}.row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px}.f1{flex:1;min-width:240px}label{display:block;color:var(--td);font-size:11px;margin-bottom:4px}input,textarea{width:100%;background:var(--s2);border:1px solid var(--b);color:var(--t);border-radius:7px;padding:9px 10px;font:inherit;outline:none}textarea{min-height:72px;font-family:monospace;font-size:12px}.btn{display:inline-flex;align-items:center;gap:6px;border:0;border-radius:7px;padding:8px 14px;font-weight:700;cursor:pointer}.bc{background:var(--c);color:#001017}.bg{background:var(--g);color:#001508}.br{background:var(--r);color:#fff}.bgr{background:var(--s2);color:var(--t);border:1px solid var(--b)}.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center}.login-box{width:330px;background:var(--s1);border:1px solid var(--b);border-radius:14px;padding:30px;text-align:center}.log-box,.pending-box{background:var(--s2);border:1px solid var(--b);border-radius:8px;padding:12px;max-height:320px;overflow:auto;font-family:monospace;font-size:11px}.ok{color:var(--g)}.err{color:var(--r)}.info{color:var(--c)}.toast{position:fixed;right:22px;bottom:22px;background:var(--s1);border:1px solid var(--c);border-radius:8px;padding:12px 16px;color:var(--c);opacity:0;transition:.2s}.toast.show{opacity:1}
</style>
</head>
<body>
<?php if (!$isLoggedIn): ?>
<div class="login-wrap"><div class="login-box">
  <h2>🎮 WePlay Bot</h2><p class="sub">Admin Panel</p>
  <label>Password</label><input type="password" id="pass" onkeydown="if(event.key==='Enter')login()">
  <button class="btn bc" style="width:100%;justify-content:center;margin-top:12px" onclick="login()">Login</button>
  <div id="err" class="err" style="margin-top:10px;font-size:12px"></div>
</div></div>
<script>
async function login(){const fd=new FormData();fd.append('pass',document.getElementById('pass').value);const r=await fetch('?api_action=login',{method:'POST',body:fd}).then(x=>x.json());if(r.ok)location.reload();else document.getElementById('err').textContent=r.error||'Wrong password'}
document.getElementById('pass').focus();
</script></body></html>
<?php exit; endif; ?>

<div class="wrap">
  <h1>🎮 Rebel WePlay Deposit Bot <small style="font-size:13px;color:var(--td)">v<?= WPB_VERSION ?></small></h1>
  <div class="sub">Telegram deposit/withdrawal flow for weplayapp.com | <a href="?api_action=logout" style="color:var(--r)">Logout</a></div>

  <div class="card">
    <h2>⚙️ Config</h2>
    <div class="row"><div class="f1"><label>Bot Token</label><input id="bot_token" type="password"></div><div class="f1"><label>Admin Chat ID</label><input id="admin_chat_id"></div></div>
    <div class="row"><div class="f1"><label>Min Deposit</label><input id="min_deposit" type="number"></div><div class="f1"><label>Max Deposit</label><input id="max_deposit" type="number"></div></div>
    <div class="row"><div class="f1"><label>WePlay Site</label><input id="weplay_site"></div><div class="f1"><label>WePlay Recharge Link</label><input id="weplay_recharge"></div></div>
    <div class="row"><div class="f1"><label>Support Contact</label><input id="support_contact"></div><div class="f1"><label>Change Admin Password</label><input id="new_pass" type="password" placeholder="blank = no change"></div></div>
    <div class="row"><div class="f1"><label>Welcome Message</label><textarea id="welcome_msg"></textarea></div><div class="f1"><label>Deposit Thanks Message</label><textarea id="deposit_thanks"></textarea></div></div>
    <div class="row"><div class="f1"><label>Card Safety Notice</label><textarea id="card_notice"></textarea></div></div>
    <div class="row"><div class="f1"><label><input id="auto_card_charge" type="checkbox" style="width:auto;margin-right:6px">Enable Automated Card Charging</label><p class="sub">Bot sends a secure gateway request and auto-approves paid webhooks. Card details must stay on the gateway page.</p></div><div class="f1"><label>Gateway Charge URL</label><input id="card_charge_url" placeholder="https://gateway.example/charges"></div></div>
    <div class="row"><div class="f1"><label>Gateway Method</label><input id="card_charge_method" placeholder="POST"></div><div class="f1"><label>Webhook Secret</label><input id="card_webhook_secret" type="password" placeholder="optional shared secret"></div></div>
    <div class="row"><div class="f1"><label>Gateway Headers (one per line)</label><textarea id="card_charge_headers"></textarea></div><div class="f1"><label>Gateway Request Body Template</label><textarea id="card_charge_body"></textarea></div></div>
    <div class="row"><div class="f1"><label>Payment URL JSON Path</label><input id="card_charge_link_path" placeholder="payment_url"></div><div class="f1"><label>Charge Status JSON Path</label><input id="card_charge_status_path" placeholder="status"></div></div>
    <div class="row"><div class="f1"><label>Webhook Transaction JSON Path</label><input id="card_webhook_txn_path" placeholder="txn_id"></div><div class="f1"><label>Webhook Status JSON Path</label><input id="card_webhook_status_path" placeholder="status"></div></div>
    <button class="btn bc" onclick="saveConfig()">💾 Save</button>
    <button class="btn bg" onclick="setWebhook()">🔗 Set Webhook</button>
    <button class="btn br" onclick="removeWebhook()">Remove Webhook</button>
  </div>

  <div class="card">
    <h2>🪙 Coin Packages</h2>
    <p class="sub">These appear as buttons in the bot after the user links their WePlay ID.</p>
    <div id="coin-packages-list"></div>
    <div style="margin-top:10px;display:flex;gap:8px;">
      <button class="btn bg" onclick="addCoinPkg()">+ Add Package</button>
      <button class="btn bc" onclick="saveCoinPackages()">💾 Save Packages</button>
    </div>
  </div>

  <div class="card">
    <h2>💳 Payment Methods</h2>
    <p class="sub">These appear as buttons after user selects a coin package.</p>
    <div id="payment-methods-list"></div>
    <div style="margin-top:10px;display:flex;gap:8px;">
      <button class="btn bg" onclick="addPayMethod()">+ Add Method</button>
      <button class="btn bc" onclick="savePayMethods()">💾 Save Methods</button>
    </div>
  </div>

  <div class="card">
    <h2>🧾 Pending Deposits</h2>
    <button class="btn bgr" onclick="loadPending()">Refresh Pending</button>
    <div id="pending" class="pending-box" style="margin-top:10px">Loading...</div>
  </div>

  <div class="card">
    <h2>📋 Logs</h2>
    <button class="btn bgr" onclick="loadLogs()">Refresh Logs</button>
    <button class="btn bgr" onclick="clearLogs()">Clear Logs</button>
    <div id="logs" class="log-box" style="margin-top:10px">Loading...</div>
  </div>
</div>
<div class="toast" id="toast"></div>
<script>
function g(id){return document.getElementById(id)}
function toast(msg){const t=g('toast');t.textContent=msg;t.classList.add('show');setTimeout(()=>t.classList.remove('show'),2500)}
async function api(action,payload={}){return fetch('?api_action='+action,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}).then(x=>x.json()).catch(e=>({ok:false,error:String(e)}))}

let _coinPkgs=[];
let _payMethods=[];

async function loadConfig(){
  const r=await fetch('?api_action=get_config').then(x=>x.json());
  if(!r.ok)return;
  for(const [k,v] of Object.entries(r.data||{})){if(g(k)&&typeof v==='string')g(k).value=v;else if(g(k)&&typeof v==='number')g(k).value=v;}
  if(g('auto_card_charge'))g('auto_card_charge').checked=!!r.data.auto_card_charge;
  _coinPkgs=(r.data.coin_packages||[]).map(p=>({...p}));
  _payMethods=(r.data.payment_methods||[]).map(m=>({...m}));
  renderCoinPkgs();renderPayMethods();
}

function renderCoinPkgs(){
  const el=g('coin-packages-list');
  el.innerHTML=_coinPkgs.map((p,i)=>`
    <div style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
      <input style="flex:2" placeholder="Label (e.g. 60 Coins — ₹80)" value="${esc(p.label||'')}" oninput="_coinPkgs[${i}].label=this.value">
      <input style="flex:1;max-width:100px" type="number" placeholder="Coins" value="${p.coins||''}" oninput="_coinPkgs[${i}].coins=Number(this.value)">
      <input style="flex:1;max-width:100px" type="number" placeholder="Price ₹" value="${p.price||''}" oninput="_coinPkgs[${i}].price=Number(this.value)">
      <button class="btn br" style="padding:6px 10px" onclick="_coinPkgs.splice(${i},1);renderCoinPkgs()">✕</button>
    </div>`).join('');
}

function addCoinPkg(){_coinPkgs.push({label:'',coins:0,price:0});renderCoinPkgs()}

async function saveCoinPackages(){
  const r=await api('save_config',{coin_packages:_coinPkgs});
  toast(r.ok?'Coin packages saved':'Error: '+(r.error||''));
}

function renderPayMethods(){
  const el=g('payment-methods-list');
  el.innerHTML=_payMethods.map((m,i)=>`
    <div style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
      <input style="flex:2" placeholder="Label (e.g. 💳 UPI / Google Pay)" value="${esc(m.label||'')}" oninput="_payMethods[${i}].label=this.value">
      <input style="flex:1;max-width:160px" placeholder="ID (e.g. upi)" value="${esc(m.id||'')}" oninput="_payMethods[${i}].id=this.value.replace(/[^a-z0-9_]/g,'')">
      <button class="btn br" style="padding:6px 10px" onclick="_payMethods.splice(${i},1);renderPayMethods()">✕</button>
    </div>`).join('');
}

function addPayMethod(){_payMethods.push({label:'',id:''});renderPayMethods()}

async function savePayMethods(){
  const r=await api('save_config',{payment_methods:_payMethods});
  toast(r.ok?'Payment methods saved':'Error: '+(r.error||''));
}

async function saveConfig(){
  const keys=['bot_token','admin_chat_id','min_deposit','max_deposit','weplay_site','weplay_recharge','support_contact','welcome_msg','deposit_thanks','card_notice','new_pass','card_charge_url','card_charge_method','card_charge_headers','card_charge_body','card_charge_link_path','card_charge_status_path','card_webhook_secret','card_webhook_txn_path','card_webhook_status_path'];
  const p={};keys.forEach(k=>{if(g(k))p[k]=g(k).value});
  if(g('auto_card_charge'))p.auto_card_charge=g('auto_card_charge').checked;
  const r=await api('save_config',p);toast(r.ok?'Saved':'Error: '+(r.error||''))
}
async function setWebhook(){const r=await api('set_webhook');toast(r.ok?'Webhook set':'Error: '+(r.error||r.tg?.description||''))}
async function removeWebhook(){const r=await api('remove_webhook');toast(r.ok?'Webhook removed':'Error')}
async function loadLogs(){const r=await fetch('?api_action=get_logs').then(x=>x.json());const box=g('logs');if(!r.ok||!r.data?.length){box.textContent='No logs';return}box.innerHTML=r.data.map(l=>`<div class="${l.type==='success'?'ok':l.type==='error'?'err':'info'}">[${new Date(l.time).toLocaleString()}] ${esc(l.text)}</div>`).join('')}
async function clearLogs(){await api('clear_logs');loadLogs();toast('Logs cleared')}
async function loadPending(){const r=await fetch('?api_action=get_pending').then(x=>x.json());const box=g('pending');const rows=Object.values(r.data||{}).reverse();if(!rows.length){box.textContent='No pending deposits';return}box.innerHTML=rows.map(p=>`<div style="border-bottom:1px solid var(--b);padding:7px 0"><b>${esc(p.txn_id)}</b> <span class="${p.status==='approved'?'ok':p.status==='rejected'?'err':'info'}">${esc(p.status)}</span><br>WePlay: ${esc(p.weplay_id)} | Coins: ${esc(p.coins||'—')} | Amount: ₹${Number(p.amount||0).toFixed(2)} | Method: ${esc(p.payment_method||'card')} | Chat: ${esc(p.chat_id)}</div>`).join('')}
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
loadConfig();loadLogs();loadPending();
</script>
</body>
</html>
