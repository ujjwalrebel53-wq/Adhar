<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║          REBEL WEPLAY DEPOSIT BOT                               ║
 * ║  Telegram bot — users /Deposit karke WePlay ID + amount dete    ║
 * ║  hain, UPI QR/details bot pe milte hain, UTR/screenshot admin   ║
 * ║  verify karta hai.                                              ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 * Setup:
 *   1. Is file ko server pe upload karo.
 *   2. Browser mein kholo -> admin panel.
 *   3. Bot token, admin chat ID, UPI details aur WePlay recharge link set karo.
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
define('WPB_RATE_FILE',    __DIR__ . '/wpb_ratelimit.json');
define('WPB_QR_DIR',       __DIR__ . '/wpb_qr/');
define('TG_BASE',          'https://api.telegram.org/bot');
define('WPB_BLOCK_MINUTES', 30);
define('WPB_MAX_INCOMPLETE', 2);

if (!is_dir(WPB_QR_DIR)) @mkdir(WPB_QR_DIR, 0755, true);

$defaultConfig = [
    'admin_pass'       => 'rebel@2026',
    'bot_token'        => '',
    'admin_chat_id'    => '',
    'upi_id'           => '',
    'upi_name'         => 'WePlay Recharge',
    'min_deposit'      => 100,
    'max_deposit'      => 100000,
    'weplay_site'      => 'https://weplayapp.com',
    'weplay_recharge'  => 'https://weplayapp.com/recharge/?region=C',
    'support_contact'  => '@Rebel_babyyy',
    'welcome_msg'      => "🎮 <b>WePlay Deposit Bot</b>\n\n💰 /Deposit - Deposit request\n💸 /Withdrawal - Withdrawal request\n💳 /Balance - Balance check\n❓ /Help - Help",
    'deposit_thanks'   => "✅ <b>Deposit submitted!</b>\n\nAdmin verify karke WePlay account me credit karega.",
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

function wpbLedgerDeposit($chatId, $amount, $utr, $txnId, $weplayId) {
    $ledger = wpbJsonLoad(WPB_LEDGER_FILE);
    $key = (string)$chatId;
    if (!isset($ledger[$key])) $ledger[$key] = ['chat_id' => $chatId, 'balance' => 0, 'deposits' => [], 'withdrawals' => []];
    $ledger[$key]['balance'] += (float)$amount;
    $ledger[$key]['deposits'][] = [
        'amount' => (float)$amount,
        'utr' => $utr,
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

function tgSendPhoto($token, $chatId, $photoPath, $caption = '', $keyboard = null) {
    if (!$token || !$chatId || !file_exists($photoPath)) return ['ok' => false];
    $fields = [
        'chat_id' => $chatId,
        'caption' => $caption,
        'parse_mode' => 'HTML',
        'photo' => new CURLFile($photoPath, 'image/png', 'qr.png'),
    ];
    if ($keyboard) $fields['reply_markup'] = json_encode($keyboard);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => TG_BASE . $token . '/sendPhoto',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_POSTFIELDS => $fields,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    return json_decode($raw, true) ?: [];
}

function wpbQr($upiString, $outputFile) {
    $apis = [
        'https://api.qrserver.com/v1/create-qr-code/?size=450x450&data=' . urlencode($upiString),
        'https://quickchart.io/qr?size=450&text=' . urlencode($upiString),
    ];
    foreach ($apis as $url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        if ($code === 200 && $data && (stripos((string)$ct, 'image') !== false || strlen($data) > 1000)) {
            file_put_contents($outputFile, $data);
            return file_exists($outputFile) && filesize($outputFile) > 500;
        }
    }
    return false;
}

function wpbUpiString($cfg, $amount, $txnId) {
    $pa = trim($cfg['upi_id'] ?? '');
    $pn = trim($cfg['upi_name'] ?? 'WePlay Recharge');
    return 'upi://pay?pa=' . rawurlencode($pa)
        . '&pn=' . rawurlencode($pn)
        . '&am=' . rawurlencode(number_format((float)$amount, 2, '.', ''))
        . '&cu=INR&tn=' . rawurlencode('WePlay deposit ' . $txnId);
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

function wpbAdminButtons($txnId) {
    return ['inline_keyboard' => [[
        ['text' => '✅ Approve', 'callback_data' => 'approve:' . $txnId],
        ['text' => '❌ Reject', 'callback_data' => 'reject:' . $txnId],
    ]]];
}

function wpbHandleDepositCommand($cfg, $chatId, $token) {
    if ($blocked = wpbIsBlocked($chatId)) {
        tgSend($token, $chatId, "⏳ Aap temporary blocked ho.\nPlease " . wpbRemaining($blocked) . " baad try karo.");
        return;
    }
    if (!trim($cfg['upi_id'] ?? '')) {
        tgSend($token, $chatId, "⚠️ UPI setup pending hai. Admin se contact karo.");
        return;
    }
    if (!wpbStartDeposit($chatId)) {
        tgSend($token, $chatId, "⚠️ Bahut saare incomplete deposits detect hue. Please " . WPB_BLOCK_MINUTES . " minutes baad try karo.");
        return;
    }
    wpbSetState($chatId, 'await_weplay_id', []);
    tgSend($token, $chatId, "🎮 Apna <b>WePlay User ID / Username</b> bhejo.\n\nCancel ke liye /cancel bhejo.");
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
        tgSend($token, $chatId, "❓ <b>Help</b>\n\n/Deposit - Deposit QR banao\n/Withdrawal - Withdrawal request\n/Balance - Local balance\n/cancel - Current process cancel\n\nSupport: " . htmlspecialchars($cfg['support_contact'], ENT_NOQUOTES, 'UTF-8'));
        return;
    }
    if (strcasecmp($text, '/cancel') === 0) {
        wpbClearState($chatId);
        tgSend($token, $chatId, "✅ Cancelled.");
        return;
    }
    if (strcasecmp($text, '/deposit') === 0) {
        wpbHandleDepositCommand($cfg, $chatId, $token);
        return;
    }
    if (strcasecmp($text, '/balance') === 0) {
        $user = wpbLedgerUser($chatId);
        tgSend($token, $chatId, "💳 <b>Your Balance</b>\n\nAmount: <b>₹" . number_format((float)$user['balance'], 2) . "</b>");
        return;
    }
    if (strcasecmp($text, '/withdrawal') === 0) {
        wpbSetState($chatId, 'withdraw_weplay_id', []);
        tgSend($token, $chatId, "🎮 Withdrawal ke liye apna <b>WePlay User ID / Username</b> bhejo.");
        return;
    }

    if (!$state) {
        tgSend($token, $chatId, "Command samajh nahi aaya. /Deposit, /Withdrawal, /Balance ya /Help use karo.");
        return;
    }

    $s = $state['state'] ?? '';
    $data = $state['data'] ?? [];

    if ($s === 'await_weplay_id') {
        if (mb_strlen($text) < 2) {
            tgSend($token, $chatId, "Valid WePlay ID bhejo.");
            return;
        }
        $data['weplay_id'] = $text;
        wpbSetState($chatId, 'await_amount', $data);
        tgSend($token, $chatId, "💰 Deposit amount bhejo.\n\nMinimum: ₹" . (int)$cfg['min_deposit'] . "\nMaximum: ₹" . (int)$cfg['max_deposit']);
        return;
    }

    if ($s === 'await_amount') {
        $amount = (float)preg_replace('/[^0-9.]/', '', $text);
        if ($amount < (float)$cfg['min_deposit'] || $amount > (float)$cfg['max_deposit']) {
            tgSend($token, $chatId, "❌ Amount ₹" . (int)$cfg['min_deposit'] . " se ₹" . (int)$cfg['max_deposit'] . " ke beech hona chahiye.");
            return;
        }
        $txnId = 'WP' . date('ymdHis') . mt_rand(100, 999);
        $qrFile = WPB_QR_DIR . $txnId . '.png';
        $upi = wpbUpiString($cfg, $amount, $txnId);
        if (!wpbQr($upi, $qrFile)) {
            tgSend($token, $chatId, "❌ QR generate nahi ho paya. Admin se contact karo.");
            wpbLog("QR failed for {$txnId}", 'error');
            return;
        }
        $pending = [
            'txn_id' => $txnId,
            'chat_id' => $chatId,
            'user_name' => wpbUserName($msg),
            'weplay_id' => $data['weplay_id'] ?? '',
            'amount' => $amount,
            'status' => 'awaiting_proof',
            'created_at' => date('c'),
        ];
        wpbPendingSave($txnId, $pending);
        wpbSetState($chatId, 'await_proof', $pending);
        $keyboard = ['inline_keyboard' => [[
            ['text' => '🌐 Open WePlay Recharge', 'url' => $cfg['weplay_recharge']],
        ]]];
        $caption = "💰 <b>WePlay Deposit</b>\n\n"
            . "🎮 WePlay ID: <code>" . htmlspecialchars($pending['weplay_id'], ENT_NOQUOTES, 'UTF-8') . "</code>\n"
            . "💵 Amount: <b>₹" . number_format($amount, 2) . "</b>\n"
            . "🧾 Transaction ID: <code>{$txnId}</code>\n\n"
            . "UPI: <code>" . htmlspecialchars($cfg['upi_id'], ENT_NOQUOTES, 'UTF-8') . "</code>\n\n"
            . "Payment ke baad UTR number ya screenshot bhejo.";
        tgSendPhoto($token, $chatId, $qrFile, $caption, $keyboard);
        wpbLog("Deposit QR sent txn={$txnId} chat={$chatId} amount={$amount}", 'success');
        return;
    }

    if ($s === 'await_proof') {
        if (mb_strlen($text) < 6) {
            tgSend($token, $chatId, "UTR/transaction reference valid nahi lag raha. Screenshot ya proper UTR bhejo.");
            return;
        }
        $txnId = $data['txn_id'] ?? '';
        wpbPendingUpdate($txnId, ['utr' => $text, 'status' => 'submitted', 'submitted_at' => date('c')]);
        wpbClearState($chatId);
        wpbDepositCompleted($chatId);
        tgSend($token, $chatId, $cfg['deposit_thanks']);
        wpbNotifyAdmin($cfg, $txnId, 'UTR: ' . $text);
        return;
    }

    if ($s === 'withdraw_weplay_id') {
        $data['weplay_id'] = $text;
        wpbSetState($chatId, 'withdraw_amount', $data);
        tgSend($token, $chatId, "💸 Withdrawal amount bhejo.");
        return;
    }

    if ($s === 'withdraw_amount') {
        $amount = (float)preg_replace('/[^0-9.]/', '', $text);
        if ($amount <= 0) {
            tgSend($token, $chatId, "Valid amount bhejo.");
            return;
        }
        wpbLedgerWithdrawal($chatId, $amount, $data['weplay_id'] ?? '');
        wpbClearState($chatId);
        tgSend($token, $chatId, "✅ Withdrawal request submitted.\nSupport: " . htmlspecialchars($cfg['support_contact'], ENT_NOQUOTES, 'UTF-8'));
        if (!empty($cfg['admin_chat_id'])) {
            tgSend($token, $cfg['admin_chat_id'], "💸 <b>Withdrawal Request</b>\n\nUser: " . htmlspecialchars(wpbUserName($msg), ENT_NOQUOTES, 'UTF-8') . "\nChat ID: <code>{$chatId}</code>\nWePlay ID: <code>" . htmlspecialchars($data['weplay_id'] ?? '', ENT_NOQUOTES, 'UTF-8') . "</code>\nAmount: <b>₹" . number_format($amount, 2) . "</b>");
        }
        return;
    }
}

function wpbNotifyAdmin($cfg, $txnId, $proofText = '') {
    $token = trim($cfg['bot_token'] ?? '');
    $admin = trim($cfg['admin_chat_id'] ?? '');
    $p = wpbPendingGet($txnId);
    if (!$token || !$admin || !$p) return;
    $msg = "🧾 <b>WePlay Deposit Submitted</b>\n\n"
        . "Txn: <code>{$txnId}</code>\n"
        . "User: " . htmlspecialchars($p['user_name'] ?? '', ENT_NOQUOTES, 'UTF-8') . "\n"
        . "Chat ID: <code>" . htmlspecialchars((string)$p['chat_id'], ENT_NOQUOTES, 'UTF-8') . "</code>\n"
        . "WePlay ID: <code>" . htmlspecialchars($p['weplay_id'] ?? '', ENT_NOQUOTES, 'UTF-8') . "</code>\n"
        . "Amount: <b>₹" . number_format((float)$p['amount'], 2) . "</b>\n"
        . ($proofText ? "\nProof: <code>" . htmlspecialchars($proofText, ENT_NOQUOTES, 'UTF-8') . "</code>\n" : '')
        . "\nRecharge link: " . htmlspecialchars($cfg['weplay_recharge'], ENT_NOQUOTES, 'UTF-8');
    tgSend($token, $admin, $msg, wpbAdminButtons($txnId));
}

function wpbHandlePhotoProof($cfg, $msg) {
    $token = trim($cfg['bot_token'] ?? '');
    $chatId = $msg['chat']['id'] ?? '';
    $state = wpbGetState($chatId);
    if (!$state || ($state['state'] ?? '') !== 'await_proof') return false;
    $data = $state['data'] ?? [];
    $txnId = $data['txn_id'] ?? '';
    $caption = trim($msg['caption'] ?? '');
    $photos = $msg['photo'] ?? [];
    $bestPhoto = is_array($photos) && $photos ? $photos[count($photos) - 1] : [];
    $photoFileId = $bestPhoto['file_id'] ?? '';
    wpbPendingUpdate($txnId, ['photo_file_id' => $photoFileId, 'utr' => $caption, 'status' => 'submitted', 'submitted_at' => date('c')]);
    wpbClearState($chatId);
    wpbDepositCompleted($chatId);
    tgSend($token, $chatId, $cfg['deposit_thanks']);
    wpbNotifyAdmin($cfg, $txnId, $caption ? ('caption: ' . $caption) : 'screenshot received');
    if (!empty($cfg['admin_chat_id']) && $photoFileId) {
        tg('sendPhoto', [
            'chat_id' => $cfg['admin_chat_id'],
            'photo' => $photoFileId,
            'caption' => "Screenshot proof for <code>{$txnId}</code>",
            'parse_mode' => 'HTML',
        ], $token);
    }
    return true;
}

function wpbHandleCallback($cfg, $cb) {
    $token = trim($cfg['bot_token'] ?? '');
    $data = $cb['data'] ?? '';
    $cbId = $cb['id'] ?? '';
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
        wpbPendingUpdate($txnId, ['status' => 'approved', 'approved_at' => date('c')]);
        wpbLedgerDeposit($p['chat_id'], $p['amount'], $p['utr'] ?? '', $txnId, $p['weplay_id'] ?? '');
        tgSend($token, $p['chat_id'], "✅ <b>Deposit Approved!</b>\n\nAmount: <b>₹" . number_format((float)$p['amount'], 2) . "</b>\nTxn: <code>{$txnId}</code>");
        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Approved'], $token);
        wpbLog("Approved txn={$txnId}", 'success');
    } else {
        wpbPendingUpdate($txnId, ['status' => 'rejected', 'rejected_at' => date('c')]);
        tgSend($token, $p['chat_id'], "❌ <b>Deposit Rejected</b>\n\nTxn: <code>{$txnId}</code>\nSupport: " . htmlspecialchars($cfg['support_contact'], ENT_NOQUOTES, 'UTF-8'));
        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Rejected'], $token);
        wpbLog("Rejected txn={$txnId}", 'error');
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
            if (!empty($msg['photo']) && wpbHandlePhotoProof($cfg, $msg)) {
                http_response_code(200); exit;
            }
            wpbHandleText($cfg, $msg);
        }
    }
    http_response_code(200);
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
            foreach (['bot_token','admin_chat_id','upi_id','upi_name','weplay_site','weplay_recharge','support_contact','welcome_msg','deposit_thanks'] as $k) {
                if (isset($body[$k])) $cfg[$k] = trim((string)$body[$k]);
            }
            if (isset($body['min_deposit'])) $cfg['min_deposit'] = max(1, (int)$body['min_deposit']);
            if (isset($body['max_deposit'])) $cfg['max_deposit'] = max($cfg['min_deposit'], (int)$body['max_deposit']);
            if (!empty($body['new_pass']) && strlen(trim($body['new_pass'])) >= 4) $cfg['admin_pass'] = trim($body['new_pass']);
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
    <div class="row"><div class="f1"><label>UPI ID</label><input id="upi_id" placeholder="name@upi"></div><div class="f1"><label>UPI Name</label><input id="upi_name"></div></div>
    <div class="row"><div class="f1"><label>Min Deposit</label><input id="min_deposit" type="number"></div><div class="f1"><label>Max Deposit</label><input id="max_deposit" type="number"></div></div>
    <div class="row"><div class="f1"><label>WePlay Site</label><input id="weplay_site"></div><div class="f1"><label>WePlay Recharge Link</label><input id="weplay_recharge"></div></div>
    <div class="row"><div class="f1"><label>Support Contact</label><input id="support_contact"></div><div class="f1"><label>Change Admin Password</label><input id="new_pass" type="password" placeholder="blank = no change"></div></div>
    <div class="row"><div class="f1"><label>Welcome Message</label><textarea id="welcome_msg"></textarea></div><div class="f1"><label>Deposit Thanks Message</label><textarea id="deposit_thanks"></textarea></div></div>
    <button class="btn bc" onclick="saveConfig()">💾 Save</button>
    <button class="btn bg" onclick="setWebhook()">🔗 Set Webhook</button>
    <button class="btn br" onclick="removeWebhook()">Remove Webhook</button>
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
async function loadConfig(){const r=await fetch('?api_action=get_config').then(x=>x.json());if(!r.ok)return;for(const [k,v] of Object.entries(r.data||{})){if(g(k))g(k).value=v}}
async function saveConfig(){const keys=['bot_token','admin_chat_id','upi_id','upi_name','min_deposit','max_deposit','weplay_site','weplay_recharge','support_contact','welcome_msg','deposit_thanks','new_pass'];const p={};keys.forEach(k=>p[k]=g(k).value);const r=await api('save_config',p);toast(r.ok?'Saved':'Error: '+(r.error||''))}
async function setWebhook(){const r=await api('set_webhook');toast(r.ok?'Webhook set':'Error: '+(r.error||r.tg?.description||''))}
async function removeWebhook(){const r=await api('remove_webhook');toast(r.ok?'Webhook removed':'Error')}
async function loadLogs(){const r=await fetch('?api_action=get_logs').then(x=>x.json());const box=g('logs');if(!r.ok||!r.data?.length){box.textContent='No logs';return}box.innerHTML=r.data.map(l=>`<div class="${l.type==='success'?'ok':l.type==='error'?'err':'info'}">[${new Date(l.time).toLocaleString()}] ${esc(l.text)}</div>`).join('')}
async function clearLogs(){await api('clear_logs');loadLogs();toast('Logs cleared')}
async function loadPending(){const r=await fetch('?api_action=get_pending').then(x=>x.json());const box=g('pending');const rows=Object.values(r.data||{}).reverse();if(!rows.length){box.textContent='No pending deposits';return}box.innerHTML=rows.map(p=>`<div style="border-bottom:1px solid var(--b);padding:7px 0"><b>${esc(p.txn_id)}</b> <span class="${p.status==='approved'?'ok':p.status==='rejected'?'err':'info'}">${esc(p.status)}</span><br>WePlay: ${esc(p.weplay_id)} | Amount: ₹${Number(p.amount||0).toFixed(2)} | Chat: ${esc(p.chat_id)}</div>`).join('')}
function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
loadConfig();loadLogs();loadPending();
</script>
</body>
</html>
