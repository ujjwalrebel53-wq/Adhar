<?php
/**
 * ╔══════════════════════════════════════════════════════════════════╗
 * ║          REBEL ROCKYBOOK DEPOSIT BOT                            ║
 * ║  Telegram bot — users /Deposit karke amount dalte hain,         ║
 * ║  RockyBook pe transaction create hoti hai, UPI QR code          ║
 * ║  automatically Telegram pe aa jaata hai.                        ║
 * ╚══════════════════════════════════════════════════════════════════╝
 *
 * Setup:
 *   1. Is file ko apne server pe upload karo
 *   2. Browser mein kholo → admin panel
 *   3. Bot Token, RockyBook credentials set karo
 *   4. "Set Webhook" dabao
 *   5. Users ko bot link bhejo — ve /Deposit karke deposit kar sakte hain
 *
 * Flow:
 *   User: /Deposit
 *   Bot: "Amount enter karo (min ₹500):"
 *   User: 1000
 *   Bot: [QR Code image + UPI details + transaction ID]
 *   User: payment kare → UTR/screenshot submit kare
 *   Bot: "Transaction submitted! Admin verify karega."
 */

// ─── compat shims ───────────────────────────────────────────
if (!function_exists('str_starts_with')) {
    function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; }
}
if (!function_exists('str_contains')) {
    function str_contains($h, $n) { return strpos($h, $n) !== false; }
}

// ────────────────────────────────────────────────────────────
define('RBB_VERSION',     '1.0');
define('RBB_CONFIG_FILE', __DIR__ . '/rbb_config.json');
define('RBB_LOG_FILE',    __DIR__ . '/rbb_logs.json');
define('RBB_STATE_FILE',  __DIR__ . '/rbb_states.json');
define('RBB_COOKIE_DIR',  __DIR__ . '/rbb_cookies/');
define('RBB_QR_DIR',      __DIR__ . '/rbb_qr/');
define('RB_API_BASE',     'https://rockybook.vip/api');
define('TG_BASE',         'https://api.telegram.org/bot');
define('MIN_DEPOSIT',     500);

if (!is_dir(RBB_COOKIE_DIR)) @mkdir(RBB_COOKIE_DIR, 0755, true);
if (!is_dir(RBB_QR_DIR))     @mkdir(RBB_QR_DIR, 0755, true);

// ─── Default config ──────────────────────────────────────────
$defaultConfig = [
    'admin_pass'     => 'rebel@2026',
    'bot_token'      => '',
    'admin_chat_id'  => '',      // Admin ko notifications jaati hain
    'rb_phone'       => '',      // RockyBook login phone
    'rb_password'    => '',      // RockyBook login password
    'rb_branch'      => 'RBVIP1D',  // Branch name used in transactions
    'rb_bank_id'     => '69ca38e87f96dde534afef82', // Default bank ID for UPI
    'min_deposit'    => 500,
    'max_deposit'    => 100000,
    'welcome_msg'    => "🎯 <b>RockyBook Deposit Bot</b>\n\nNamaste! Is bot ke zariye aap seedha RockyBook pe deposit kar sakte ho.\n\n/Deposit — Deposit shuru karo\n/Balance — Balance dekho\n/Help — Help",
    'deposit_thanks' => "✅ <b>Transaction Submit Ho Gayi!</b>\n\nAdmin jald hi verify karega. Koi pareshani ho to support se contact karo.",
];

// ─── Load/Save config ────────────────────────────────────────
function rbbLoadConfig() {
    global $defaultConfig;
    if (!file_exists(RBB_CONFIG_FILE)) return $defaultConfig;
    $loaded = json_decode(file_get_contents(RBB_CONFIG_FILE), true);
    if (!is_array($loaded)) return $defaultConfig;
    return array_merge($defaultConfig, $loaded);
}
function rbbSaveConfig($cfg) {
    file_put_contents(RBB_CONFIG_FILE, json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ─── User states (conversation state machine) ────────────────
function rbbGetStates() {
    if (!file_exists(RBB_STATE_FILE)) return [];
    return json_decode(file_get_contents(RBB_STATE_FILE), true) ?: [];
}
function rbbSetState($chatId, $state, $data = []) {
    $states = rbbGetStates();
    $states[(string)$chatId] = ['state' => $state, 'data' => $data, 'ts' => time()];
    file_put_contents(RBB_STATE_FILE, json_encode($states, JSON_UNESCAPED_UNICODE), LOCK_EX);
}
function rbbGetState($chatId) {
    $states = rbbGetStates();
    $s = $states[(string)$chatId] ?? null;
    // Expire states older than 30 minutes
    if ($s && (time() - ($s['ts'] ?? 0)) > 1800) {
        rbbClearState($chatId);
        return null;
    }
    return $s;
}
function rbbClearState($chatId) {
    $states = rbbGetStates();
    unset($states[(string)$chatId]);
    file_put_contents(RBB_STATE_FILE, json_encode($states, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ─── Logging ─────────────────────────────────────────────────
function rbbLog($text, $type = 'info') {
    $logs = file_exists(RBB_LOG_FILE) ? (json_decode(file_get_contents(RBB_LOG_FILE), true) ?: []) : [];
    array_unshift($logs, ['time' => date('c'), 'text' => $text, 'type' => $type]);
    if (count($logs) > 500) $logs = array_slice($logs, 0, 500);
    file_put_contents(RBB_LOG_FILE, json_encode($logs, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// ─── Telegram API ────────────────────────────────────────────
function tg($method, $params, $token) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => TG_BASE . $token . '/' . $method,
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

function tgSend($token, $chatId, $text, $keyboard = null) {
    $params = [
        'chat_id'                  => $chatId,
        'text'                     => $text,
        'parse_mode'               => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($keyboard) {
        $params['reply_markup'] = json_encode($keyboard);
    }
    return tg('sendMessage', $params, $token);
}

function tgSendPhoto($token, $chatId, $photoPath, $caption = '', $keyboard = null) {
    $ch = curl_init();
    $fields = [
        'chat_id'    => $chatId,
        'caption'    => $caption,
        'parse_mode' => 'HTML',
        'photo'      => new CURLFile($photoPath, 'image/png', 'qr.png'),
    ];
    if ($keyboard) {
        $fields['reply_markup'] = json_encode($keyboard);
    }
    curl_setopt_array($ch, [
        CURLOPT_URL            => TG_BASE . $token . '/sendPhoto',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_POSTFIELDS     => $fields,
    ]);
    $r = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $r;
}

function tgChunk($text, $maxLen = 4000) {
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
function rbApi($endpoint, $method = 'GET', $data = null, $cookieFile = null) {
    $url = RB_API_BASE . $endpoint;
    $cFile = $cookieFile ?? (RBB_COOKIE_DIR . 'admin.txt');
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
        'Origin: https://rockybook.vip',
        'Referer: https://rockybook.vip/',
    ];

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
        CURLOPT_COOKIEJAR      => $cFile,
        CURLOPT_COOKIEFILE     => $cFile,
    ];

    $m = strtoupper($method);
    if ($m === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $data !== null ? json_encode($data) : '{}';
    } elseif (in_array($m, ['PUT', 'PATCH', 'DELETE'])) {
        $opts[CURLOPT_CUSTOMREQUEST] = $m;
        if ($data !== null) $opts[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($ch, $opts);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    return [
        'code' => $code,
        'raw'  => $raw ?: '',
        'data' => json_decode($raw, true),
        'error'=> $err,
        'ok'   => $code >= 200 && $code < 300,
    ];
}

// ─── Admin login to RockyBook ─────────────────────────────────
function rbAdminLogin($cfg) {
    $cookieFile = RBB_COOKIE_DIR . 'admin.txt';

    // Check existing session
    $check = rbApi('/auth/fetchUserByToken', 'GET', null, $cookieFile);
    if ($check['ok'] && isset($check['data']['user'])) {
        return $check['data']['user'];
    }

    $phone    = trim($cfg['rb_phone'] ?? '');
    $password = trim($cfg['rb_password'] ?? '');
    if (!$phone || !$password) return false;

    $res = rbApi('/auth/login', 'POST', [
        'loginType' => $phone,
        'password'  => $password,
    ], $cookieFile);

    if (!$res['ok'] || empty($res['data'])) return false;
    $d = $res['data'];
    return $d['user'] ?? $d['data'] ?? ((!empty($d['success'])) ? $d : false);
}

// ─── Normalize a bank object to standard keys ─────────────────
function rbNormalizeBank($b) {
    if (!is_array($b) || empty($b)) return null;
    $n = [
        'upiId'        => $b['upiId']         ?? $b['upi']          ?? $b['vpa']            ?? null,
        'accNo'        => $b['accNo']          ?? $b['accountNo']    ?? $b['accountNumber']   ?? $b['account_no'] ?? null,
        'ifscCode'     => $b['ifscCode']       ?? $b['ifsc']         ?? $b['IFSC']            ?? null,
        'bankName'     => $b['bankName']       ?? $b['bank']         ?? $b['bank_name']       ?? null,
        'accHolderName'=> $b['accHolderName']  ?? $b['holderName']   ?? $b['accountHolder']   ?? $b['name']       ?? null,
        'isActive'     => $b['isActive']       ?? true,
    ];
    // Return only if at least one useful field present
    if ($n['upiId'] || $n['accNo'] || $n['ifscCode']) return $n;
    return null;
}

// ─── Get deposit bank details (real-time from RockyBook) ──────
// Uses the same endpoint the site uses for the deposit QR page
function rbGetBankDetails($cfg) {
    $bankId = trim($cfg['rb_bank_id'] ?? '69ca38e87f96dde534afef82');

    // Method 1 (PRIMARY): getActiveBankDetails — same endpoint site uses for deposit QR
    $res = rbApi("/bank/getActiveBankDetails/{$bankId}");
    rbbLog("getActiveBankDetails HTTP={$res['code']} data=" . mb_substr(json_encode($res['data']), 0, 200), 'info');
    if ($res['ok'] && !empty($res['data'])) {
        $d    = $res['data'];
        $bank = $d['data'] ?? $d['bank'] ?? $d;
        if (is_array($bank)) {
            $n = rbNormalizeBank($bank);
            if ($n) return $n;
        }
    }

    // Method 2: getAllBanks_With_Pagination (page 1, limit 10) — pick first active
    $res2 = rbApi("/bank/getAllBanks_With_Pagination/dummy?page=1&limit=10");
    if (!$res2['ok']) {
        // Try with admin user ID
        $adminUser = rbGetAdminUser($cfg);
        $uid = $adminUser['_id'] ?? $adminUser['id'] ?? 'dummy';
        $res2 = rbApi("/bank/getAllBanks_With_Pagination/{$uid}?page=1&limit=10");
    }
    rbbLog("getAllBanks_With_Pagination HTTP={$res2['code']} data=" . mb_substr(json_encode($res2['data']), 0, 200), 'info');
    if ($res2['ok'] && !empty($res2['data'])) {
        $banks = $res2['data']['banks'] ?? $res2['data']['data'] ?? $res2['data'];
        if (is_array($banks)) {
            foreach ($banks as $b) {
                $n = rbNormalizeBank($b);
                if ($n) return $n;
            }
        }
    }

    // Method 3: getAllBanksWithoutPagination for admin user
    $adminUser = $adminUser ?? rbGetAdminUser($cfg);
    $uid = $adminUser['_id'] ?? $adminUser['id'] ?? null;
    if ($uid) {
        $res3 = rbApi("/bank/getAllBanksWithoutPagination/{$uid}");
        rbbLog("getAllBanksWithoutPagination HTTP={$res3['code']} data=" . mb_substr(json_encode($res3['data']), 0, 200), 'info');
        if ($res3['ok'] && !empty($res3['data'])) {
            $banks = $res3['data']['banks'] ?? $res3['data']['data'] ?? $res3['data'];
            if (is_array($banks)) {
                foreach ($banks as $b) {
                    $n = rbNormalizeBank($b);
                    if ($n) return $n;
                }
            }
        }
    }

    // Method 4: getSavedBanks
    $res4 = rbApi('/user/getSavedBanks');
    rbbLog("getSavedBanks HTTP={$res4['code']} data=" . mb_substr(json_encode($res4['data']), 0, 200), 'info');
    if ($res4['ok'] && !empty($res4['data'])) {
        $banks = $res4['data']['data'] ?? $res4['data']['banks'] ?? $res4['data'];
        if (is_array($banks)) {
            foreach ($banks as $b) {
                $n = rbNormalizeBank($b);
                if ($n) return $n;
            }
        }
    }

    rbbLog('rbGetBankDetails: all methods failed — no bank data returned', 'error');
    return null;
}

// ─── Create deposit transaction ───────────────────────────────
function rbCreateDeposit($cfg, $userId, $amount, $mode = 'PowerPay') {
    $branch = trim($cfg['rb_branch'] ?? 'RBVIP1D');
    $payload = [
        'userId'        => $userId,
        'amount'        => (float)$amount,
        'transactionType' => 'Deposit',
        'role'          => 'User',
        'mode'          => $mode,
        'branchUserName'=> $branch,
    ];
    $res = rbApi('/transaction/createTransaction', 'POST', $payload);
    if (!$res['ok']) return null;
    $d = $res['data'];
    if (!empty($d['success']) && isset($d['data'])) return $d['data'];
    return $d;
}


// ─── Get admin user info (cached after first login) ──────────
function rbGetAdminUser($cfg) {
    $cacheFile = RBB_COOKIE_DIR . 'admin_user.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        $cached = json_decode(file_get_contents($cacheFile), true);
        if ($cached && isset($cached['_id'])) return $cached;
    }
    $user = rbAdminLogin($cfg);
    if ($user && is_array($user)) {
        file_put_contents($cacheFile, json_encode($user, JSON_UNESCAPED_UNICODE), LOCK_EX);
    }
    return $user;
}

// ─── Generate QR code (PHP GD — no library needed) ───────────
function rbbGenerateQR($upiString, $outputFile) {
    // Use a free QR API (no library needed)
    $apis = [
        'https://api.qrserver.com/v1/create-qr-code/?size=400x400&data=' . urlencode($upiString),
        'https://quickchart.io/qr?size=400&text=' . urlencode($upiString),
        'https://chart.googleapis.com/chart?cht=qr&chs=400x400&chl=' . urlencode($upiString),
    ];

    foreach ($apis as $apiUrl) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'RBBot/1.0',
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($code === 200 && $data && strlen($data) > 500 && str_contains((string)$ct, 'image')) {
            file_put_contents($outputFile, $data);
            return true;
        }
    }
    return false;
}

// ─── Handle /Deposit command — seedha amount poochta hai ─────
function handleDeposit($token, $chatId, $userName, $cfg) {
    $minDep = (int)($cfg['min_deposit'] ?? MIN_DEPOSIT);
    $maxDep = (int)($cfg['max_deposit'] ?? 100000);

    tgSend($token, $chatId,
        "💰 <b>Deposit Amount</b>\n\n"
      . "Kitna deposit karna chahte ho?\n"
      . "Minimum: <b>₹" . number_format($minDep) . "</b>\n"
      . "Maximum: <b>₹" . number_format($maxDep) . "</b>\n\n"
      . "<i>Sirf number daalo (e.g. 1000)</i>",
    [
        'inline_keyboard' => [
            [
                ['text' => '₹500',   'callback_data' => 'amt_500'],
                ['text' => '₹1000',  'callback_data' => 'amt_1000'],
                ['text' => '₹2000',  'callback_data' => 'amt_2000'],
            ],
            [
                ['text' => '₹5000',  'callback_data' => 'amt_5000'],
                ['text' => '₹10000', 'callback_data' => 'amt_10000'],
                ['text' => '₹25000', 'callback_data' => 'amt_25000'],
            ],
        ],
    ]);
    rbbSetState($chatId, 'awaiting_amount', []);
}

// ─── Fetch URL as image bytes ─────────────────────────────────
function rbFetchImage($url, $timeout = 25) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0.0.0 Safari/537.36',
    ]);
    $data = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $ct   = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($code === 200 && $data && strlen($data) > 500) return $data;
    return null;
}

// ─── Take screenshot of a URL ─────────────────────────────────
// Tries microlink → screenshotone → thum.io
function rbScreenshotUrl($url, $outputFile, $timeout = 35) {
    $enc = urlencode($url);

    // 1. microlink (best quality, returns JSON with screenshot URL)
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => "https://api.microlink.io/?url={$enc}&screenshot=true&meta=false&embed=screenshot.url&timeout=20000",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $ml    = json_decode($raw, true);
    $ssUrl = $ml['data']['screenshot']['url'] ?? $ml['data']['screenshot'] ?? null;
    if ($ssUrl && str_starts_with($ssUrl, 'http')) {
        $data = rbFetchImage($ssUrl, 20);
        if ($data && strlen($data) > 500) {
            file_put_contents($outputFile, $data);
            return 'microlink';
        }
    }

    // 2. screenshotone (no key, free tier)
    $data = rbFetchImage("https://api.screenshotone.com/take?url={$enc}&viewport_width=800&viewport_height=1200&format=png&timeout=20", 25);
    if ($data && strlen($data) > 500) {
        file_put_contents($outputFile, $data);
        return 'screenshotone';
    }

    // 3. thum.io (fallback)
    $data = rbFetchImage("https://image.thum.io/get/width/800/crop/1200/png/{$enc}", 20);
    if ($data && strlen($data) > 500) {
        file_put_contents($outputFile, $data);
        return 'thum.io';
    }

    return null;
}

// ─── Fetch real QR/screenshot from RockyBook payment page ────
function rbFetchRealQR($txnId, $branch, $outputFile, $timeout = 35) {
    // Method 1: RockyBook built-in screenshot API
    $res = rbApi("/transaction/fetch_powerPay_transaction_screenshot/{$txnId}");
    if ($res['ok'] && !empty($res['data'])) {
        $d = $res['data'];
        $imgData = $d['screenshot'] ?? $d['image'] ?? $d['data'] ?? $d['url'] ?? $d['screenshotUrl'] ?? null;
        if ($imgData) {
            if (str_starts_with($imgData, 'data:image')) {
                $bytes = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $imgData));
                if ($bytes && strlen($bytes) > 500) {
                    file_put_contents($outputFile, $bytes);
                    return 'rockybook-api';
                }
            } elseif (str_starts_with($imgData, 'http')) {
                $bytes = rbFetchImage($imgData, 20);
                if ($bytes && strlen($bytes) > 500) {
                    file_put_contents($outputFile, $bytes);
                    return 'rockybook-api-url';
                }
            }
        }
    }

    // Method 2: Screenshot of actual PowerDreams payment page
    // (exact page that opens when user clicks Deposit on site)
    $payUrl = "https://www.powerdreams.co/online/pay/{$branch}/{$txnId}";
    $src = rbScreenshotUrl($payUrl, $outputFile, $timeout);
    if ($src) return "payment-page-{$src}";

    return null;
}

// ─── Process deposit amount → create txn → send REAL details ─
function processDepositAmount($token, $chatId, $amount, $rbUser, $cfg) {
    $minDep = (int)($cfg['min_deposit'] ?? MIN_DEPOSIT);
    $maxDep = (int)($cfg['max_deposit'] ?? 100000);

    if ($amount < $minDep) {
        tgSend($token, $chatId, "❌ Minimum deposit amount <b>₹" . number_format($minDep) . "</b> hai.\n\nDobara try karo:");
        return false;
    }
    if ($amount > $maxDep) {
        tgSend($token, $chatId, "❌ Maximum deposit amount <b>₹" . number_format($maxDep) . "</b> hai.\n\nDobara try karo:");
        return false;
    }

    tgSend($token, $chatId, "⏳ <b>Processing...</b>\n\nRockyBook pe deposit request create ho rahi hai...");

    // Login with panel credentials
    $adminUser = rbGetAdminUser($cfg);
    if (!$adminUser) {
        tgSend($token, $chatId, "❌ Server error. Admin ko contact karo.");
        rbbLog("Deposit failed: admin RB login failed for chat {$chatId}", 'error');
        return false;
    }

    $rbUserId = $adminUser['_id'] ?? $adminUser['id'] ?? null;
    $branch   = trim($cfg['rb_branch'] ?? 'RBVIP1D');

    // Create transaction on RockyBook (same as clicking Deposit button on site)
    $txn   = rbCreateDeposit($cfg, $rbUserId, $amount);
    $txnId = $txn['_id'] ?? $txn['id'] ?? $txn['transactionId'] ?? null;
    $mode  = $txn['mode'] ?? 'PowerPay';

    if (!$txnId) {
        tgSend($token, $chatId, "❌ Transaction create nahi ho saki. Thodi der baad try karo.");
        rbbLog("Deposit failed: no txnId — chat={$chatId} amount={$amount} txn=" . json_encode($txn), 'error');
        return false;
    }

    // ── Get REAL bank details — always fetch fresh from API ──
    $bank   = rbGetBankDetails($cfg);
    $upiId  = $bank['upiId']         ?? null;
    $accNo  = $bank['accNo']         ?? null;
    $ifsc   = $bank['ifscCode']      ?? null;
    $bankNm = $bank['bankName']      ?? null;
    $accName= $bank['accHolderName'] ?? null;

    rbbLog("Bank details fetched — upi={$upiId} acc={$accNo} ifsc={$ifsc} bank={$bankNm} name={$accName}", 'info');

    // Real payment page URL (jo site pe redirect karti hai)
    $payPageUrl = "https://www.powerdreams.co/online/pay/{$branch}/{$txnId}";

    // ── Build keyboard ────────────────────────────────────────
    $keyboard = [
        'inline_keyboard' => [[
            ['text' => '✅ Payment Ho Gayi — UTR Bhejo', 'callback_data' => 'submit_utr_' . $txnId],
        ]],
    ];

    // ── Full bank details message (always send this) ──────────
    $bankMsg = "🎯 <b>RockyBook Deposit Details</b>\n\n"
             . "💰 Amount: <b>₹" . number_format($amount) . "</b>\n"
             . "🔖 Txn ID: <code>{$txnId}</code>\n"
             . "\n<b>💳 Bank / UPI Details:</b>\n"
             . ($upiId   ? "📱 UPI ID: <code>{$upiId}</code>\n" : '')
             . ($accName ? "👤 Name: <b>{$accName}</b>\n" : '')
             . ($accNo   ? "🔢 Acc No: <code>{$accNo}</code>\n" : '')
             . ($ifsc    ? "🏛 IFSC: <code>{$ifsc}</code>\n" : '')
             . ($bankNm  ? "🏦 Bank: {$bankNm}\n" : '')
             . "\n⚠️ <b>Sirf ₹" . number_format($amount) . " hi bhejo (exact amount)</b>\n\n"
             . "Payment ke baad UTR number ya screenshot yahan bhejo 👇";

    // ── QR / Screenshot ───────────────────────────────────────
    tgSend($token, $chatId, "⏳ QR code le raha hai...");

    $qrFile = RBB_QR_DIR . 'qr_' . $chatId . '_' . time() . '.png';
    sleep(3); // payment page initialize hone do

    // Step 1: Try RockyBook screenshot API + payment page screenshot
    $qrSource = rbFetchRealQR($txnId, $branch, $qrFile);

    if ($qrSource && file_exists($qrFile) && filesize($qrFile) > 500) {
        // Send screenshot with bank details caption
        $caption = "📸 <b>Payment QR — RockyBook</b>\n\n"
                 . "💰 Amount: <b>₹" . number_format($amount) . "</b>\n"
                 . ($upiId ? "📱 UPI ID: <code>{$upiId}</code>\n" : '')
                 . "🔖 Txn ID: <code>{$txnId}</code>";
        $r = tgSendPhoto($token, $chatId, $qrFile, $caption, null);
        @unlink($qrFile);
        if (!empty($r['ok'])) {
            // Also send full bank details as separate message
            tgSend($token, $chatId, $bankMsg, $keyboard);
            rbbLog("Real QR screenshot sent — chat={$chatId} txn={$txnId} src={$qrSource}", 'success');
            goto save_state;
        }
    }

    // Step 2: Generate UPI QR from UPI ID (if screenshot failed)
    if ($upiId) {
        $upiStr = "upi://pay?pa={$upiId}&am={$amount}&cu=INR&tn=RockyBook";
        $upiQrFile = RBB_QR_DIR . 'upi_' . $chatId . '_' . time() . '.png';
        $qrApis = [
            "https://api.qrserver.com/v1/create-qr-code/?size=512x512&data=" . urlencode($upiStr),
            "https://quickchart.io/qr?size=512&text=" . urlencode($upiStr),
            "https://chart.googleapis.com/chart?cht=qr&chs=512x512&chl=" . urlencode($upiStr),
        ];
        $upiQrSent = false;
        foreach ($qrApis as $qrApi) {
            $bytes = rbFetchImage($qrApi, 12);
            if ($bytes && strlen($bytes) > 500) {
                file_put_contents($upiQrFile, $bytes);
                $caption = "📱 <b>UPI QR Code</b>\n\n"
                         . "💰 Amount: <b>₹" . number_format($amount) . "</b>\n"
                         . "📱 UPI ID: <code>{$upiId}</code>\n"
                         . "🔖 Txn ID: <code>{$txnId}</code>";
                $r = tgSendPhoto($token, $chatId, $upiQrFile, $caption, null);
                @unlink($upiQrFile);
                if (!empty($r['ok'])) {
                    $upiQrSent = true;
                    tgSend($token, $chatId, $bankMsg, $keyboard);
                    rbbLog("UPI QR sent — chat={$chatId} txn={$txnId}", 'success');
                    break;
                }
            }
        }
        if (!$upiQrSent) {
            // Text only with UPI link
            tgSend($token, $chatId, $bankMsg . "\n\n🔗 UPI Pay: <code>{$upiStr}</code>", $keyboard);
            rbbLog("Text-only deposit sent — chat={$chatId} txn={$txnId}", 'info');
        }
    } else {
        // No UPI ID — just send bank details
        tgSend($token, $chatId, $bankMsg, $keyboard);
        rbbLog("Bank details sent (no UPI) — chat={$chatId} txn={$txnId}", 'info');
    }

    save_state:

    // Save state for UTR submission
    rbbSetState($chatId, 'awaiting_utr', [
        'amount'  => $amount,
        'txn_id'  => $txnId,
        'upi_id'  => $upiId,
        'pay_url' => $payPageUrl,
    ]);

    // Notify admin
    $adminChatId = trim($cfg['admin_chat_id'] ?? '');
    if ($adminChatId) {
        tgSend($token, $adminChatId,
            "🆕 <b>New Deposit</b>\n\n"
          . "📊 TG: <code>{$chatId}</code>\n"
          . "💰 Amount: ₹" . number_format($amount) . "\n"
          . "🔖 Txn: <code>{$txnId}</code>\n"
          . ($upiId ? "📱 UPI: <code>{$upiId}</code>\n" : '')
          . "🌐 Mode: {$mode}\n"
          . "🕐 " . date('d/m/Y H:i:s')
        );
    }

    return true;
}

// ─── Process UTR submission ───────────────────────────────────
function processUtrSubmission($token, $chatId, $utr, $state, $cfg) {
    $data   = $state['data'] ?? [];
    $amount = $data['amount'] ?? 0;
    $txnId  = $data['txn_id'] ?? null;

    $thankMsg = str_replace('\n', "\n", $cfg['deposit_thanks'] ?? "✅ UTR submit ho gayi! Admin verify karega.");
    tgSend($token, $chatId, $thankMsg);

    rbbClearState($chatId);

    // Notify admin
    $adminChatId = trim($cfg['admin_chat_id'] ?? '');
    if ($adminChatId) {
        tgSend($token, $adminChatId,
            "💳 <b>UTR Submitted</b>\n\n"
          . "📊 TG Chat ID: <code>{$chatId}</code>\n"
          . "💰 Amount: ₹" . number_format($amount) . "\n"
          . "🔢 UTR: <code>{$utr}</code>\n"
          . ($txnId ? "🔖 Txn ID: <code>{$txnId}</code>\n" : '')
          . "🕐 Time: " . date('d/m/Y H:i:s')
        );
    }

    rbbLog("UTR submitted — chat={$chatId} utr={$utr} txn={$txnId}", 'success');
}

// ─── Main webhook handler ─────────────────────────────────────
function handleUpdate($update, $cfg) {
    $token = trim($cfg['bot_token'] ?? '');
    if (!$token) return;

    // Callback query (inline buttons)
    if (isset($update['callback_query'])) {
        $cq     = $update['callback_query'];
        $chatId = $cq['message']['chat']['id'] ?? '';
        $data   = $cq['data'] ?? '';
        $cqId   = $cq['id'] ?? '';

        // Acknowledge
        tg('answerCallbackQuery', ['callback_query_id' => $cqId], $token);

        if (str_starts_with($data, 'amt_')) {
            $amount = (int)substr($data, 4);
            processDepositAmount($token, $chatId, $amount, [], $cfg);
            return;
        }

        if (str_starts_with($data, 'submit_utr_')) {
            $state = rbbGetState($chatId);
            if ($state && $state['state'] === 'awaiting_utr') {
                tgSend($token, $chatId, "🔢 <b>UTR Number daalo:</b>\n\n<i>Bank app mein transaction ID / UTR / reference number hota hai</i>");
                rbbSetState($chatId, 'awaiting_utr_text', $state['data']);
            }
            return;
        }

        return;
    }

    // Regular message
    $msg    = $update['message'] ?? $update['channel_post'] ?? null;
    if (!$msg) return;

    $chatId   = $msg['chat']['id'] ?? '';
    $text     = trim($msg['text'] ?? '');
    $userName = $msg['from']['username'] ?? $msg['from']['first_name'] ?? 'User';
    $photo    = $msg['photo'] ?? null;
    $doc      = $msg['document'] ?? null;

    if (!$chatId) return;

    // Handle screenshot submission for UTR
    $state = rbbGetState($chatId);
    if ($state && in_array($state['state'], ['awaiting_utr', 'awaiting_utr_text']) && ($photo || $doc)) {
        $data   = $state['data'] ?? [];
        $amount = $data['amount'] ?? 0;
        $txnId  = $data['txn_id'] ?? null;
        tgSend($token, $chatId, str_replace('\n', "\n", $cfg['deposit_thanks'] ?? "✅ Screenshot submit ho gayi! Admin verify karega."));
        rbbClearState($chatId);

        $adminChatId = trim($cfg['admin_chat_id'] ?? '');
        if ($adminChatId) {
            if ($photo) {
                tg('forwardMessage', [
                    'chat_id'      => $adminChatId,
                    'from_chat_id' => $chatId,
                    'message_id'   => $msg['message_id'],
                ], $token);
            }
            tgSend($token, $adminChatId,
                "📸 <b>Payment Screenshot Received</b>\n\n"
              . "📊 TG Chat ID: <code>{$chatId}</code>\n"
              . "💰 Amount: ₹" . number_format($amount) . "\n"
              . ($txnId ? "🔖 Txn ID: <code>{$txnId}</code>\n" : '')
            );
        }
        rbbLog("Screenshot submitted — chat={$chatId} txn={$txnId}", 'success');
        return;
    }

    // Commands
    $cmd = strtolower(explode('@', explode(' ', $text)[0])[0]);

    if ($cmd === '/start') {
        $welcome = str_replace('\n', "\n", $cfg['welcome_msg'] ?? "Welcome to RockyBook Bot!");
        tgSend($token, $chatId, $welcome, [
            'keyboard' => [
                [['text' => '💰 Deposit'], ['text' => '❓ Help']],
            ],
            'resize_keyboard'   => true,
            'one_time_keyboard' => false,
        ]);
        rbbClearState($chatId);
        return;
    }

    if ($cmd === '/deposit' || $text === '💰 Deposit') {
        handleDeposit($token, $chatId, $userName, $cfg);
        return;
    }

    if ($cmd === '/balance') {
        $adminUser = rbGetAdminUser($cfg);
        if (!$adminUser) {
            tgSend($token, $chatId, "❌ Server error. Admin ko contact karo.");
            return;
        }
        $rbUserId = $adminUser['_id'] ?? $adminUser['id'] ?? null;
        if ($rbUserId) {
            $res = rbApi("/transaction/get_MainUserBalance/{$rbUserId}");
            if ($res['ok']) {
                $bal = $res['data']['balance'] ?? $res['data']['currentBalance'] ?? $res['data']['data'] ?? '—';
                tgSend($token, $chatId, "💳 <b>RockyBook Balance</b>\n\n₹" . (is_numeric($bal) ? number_format((float)$bal, 2) : $bal));
            } else {
                tgSend($token, $chatId, "❌ Balance fetch nahi ho saka. Baad mein try karo.");
            }
        }
        return;
    }

    if ($cmd === '/help' || $text === '❓ Help') {
        tgSend($token, $chatId,
            "❓ <b>Help</b>\n\n"
          . "/Deposit — RockyBook pe deposit karo\n"
          . "/Balance — Balance dekho\n"
          . "/Start — Bot restart karo\n\n"
          . "Support ke liye admin se contact karo."
        );
        return;
    }

    // State machine
    if (!$state) {
        tgSend($token, $chatId, "👇 Kya karna chahte ho?\n\n/Deposit — Deposit karo\n/Balance — Balance dekho\n/Help — Madad lao");
        return;
    }

    switch ($state['state']) {

        case 'awaiting_amount':
            $amount = (float)preg_replace('/[^0-9.]/', '', $text);
            if ($amount <= 0) {
                tgSend($token, $chatId, "❌ Valid amount daalo (sirf number).\n\nExample: <code>1000</code>");
                return;
            }
            processDepositAmount($token, $chatId, (int)$amount, [], $cfg);
            break;

        case 'awaiting_utr':
        case 'awaiting_utr_text':
            $utr = trim(preg_replace('/[^a-zA-Z0-9]/', '', $text));
            if (strlen($utr) < 6) {
                tgSend($token, $chatId, "❌ Valid UTR/reference number daalo.");
                return;
            }
            processUtrSubmission($token, $chatId, $utr, $state, $cfg);
            break;

        default:
            rbbClearState($chatId);
            tgSend($token, $chatId, "👇 /Deposit — Deposit karo\n/Balance — Balance dekho");
    }
}

// ────────────────────────────────────────────────────────────
// ─── Web Entry Points ─────────────────────────────────────
// ────────────────────────────────────────────────────────────

$cfg = rbbLoadConfig();

// ─── Webhook endpoint ─────────────────────────────────────────
if (isset($_GET['webhook'])) {
    $update = json_decode(file_get_contents('php://input'), true);
    if (is_array($update)) {
        handleUpdate($update, $cfg);
    }
    http_response_code(200);
    exit;
}

// ─── Admin API ────────────────────────────────────────────────
session_start();
$isLoggedIn = !empty($_SESSION['rbb_ok']);

if (isset($_GET['api_action'])) {
    header('Content-Type: application/json');
    $act = preg_replace('/[^a-zA-Z0-9_]/', '', $_GET['api_action'] ?? '');

    if ($act === 'login') {
        $pass = $_POST['pass'] ?? '';
        if ($pass === $cfg['admin_pass']) {
            $_SESSION['rbb_ok'] = true;
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
            $newCfg = $cfg;
            foreach (['bot_token','admin_chat_id','rb_phone','rb_password','rb_branch','rb_bank_id','welcome_msg','deposit_thanks'] as $k) {
                if (isset($body[$k])) $newCfg[$k] = trim($body[$k]);
            }
            foreach (['min_deposit','max_deposit'] as $k) {
                if (isset($body[$k])) $newCfg[$k] = (int)$body[$k];
            }
            if (!empty($body['new_pass']) && strlen(trim($body['new_pass'])) >= 4) {
                $newCfg['admin_pass'] = trim($body['new_pass']);
            }
            rbbSaveConfig($newCfg);
            $cfg = $newCfg;
            rbbLog('Config saved', 'info');
            echo json_encode(['ok' => true]); exit;

        case 'set_webhook':
            $wToken = trim($cfg['bot_token'] ?? '');
            if (!$wToken) { echo json_encode(['ok' => false, 'error' => 'Bot token not set']); exit; }
            $pr  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $wUrl = $pr . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . '?webhook=1';
            $r = tg('setWebhook', ['url' => $wUrl, 'allowed_updates' => ['message', 'channel_post', 'callback_query']], $wToken);
            echo json_encode(['ok' => $r['ok'] ?? false, 'webhook_url' => $wUrl, 'tg' => $r]); exit;

        case 'remove_webhook':
            $wToken = trim($cfg['bot_token'] ?? '');
            if (!$wToken) { echo json_encode(['ok' => false, 'error' => 'Bot token not set']); exit; }
            $r = tg('deleteWebhook', [], $wToken);
            echo json_encode(['ok' => $r['ok'] ?? false]); exit;

        case 'test_rb_login':
            @unlink(RBB_COOKIE_DIR . 'admin.txt');
            $user = rbAdminLogin($cfg);
            echo json_encode($user ? ['ok' => true, 'user' => $user] : ['ok' => false, 'error' => 'Login failed']); exit;

        case 'test_bank':
            rbAdminLogin($cfg);
            $bankId = trim($cfg['rb_bank_id'] ?? '69ca38e87f96dde534afef82');
            $adminU = rbGetAdminUser($cfg);
            $uid    = $adminU['_id'] ?? $adminU['id'] ?? '';
            $r1 = rbApi("/bank/getActiveBankDetails/{$bankId}");
            $r2 = rbApi("/bank/getAllBanksWithoutPagination/{$uid}");
            $r3 = rbApi('/user/getSavedBanks');
            $r4 = rbApi("/bank/getAllBanks_With_Pagination/{$uid}?page=1&limit=10");
            $bank = rbGetBankDetails($cfg);
            echo json_encode([
                'ok'   => (bool)$bank,
                'bank' => $bank,
                'debug'=> [
                    'getActiveBankDetails'        => ['code'=>$r1['code'],'raw'=>mb_substr($r1['raw'],0,500),'data'=>$r1['data']],
                    'getAllBanksWithoutPagination' => ['code'=>$r2['code'],'raw'=>mb_substr($r2['raw'],0,500),'data'=>$r2['data']],
                    'getSavedBanks'               => ['code'=>$r3['code'],'raw'=>mb_substr($r3['raw'],0,500),'data'=>$r3['data']],
                    'getAllBanks_With_Pagination'  => ['code'=>$r4['code'],'raw'=>mb_substr($r4['raw'],0,500),'data'=>$r4['data']],
                ],
            ]); exit;

        case 'test_screenshot':
            // Screenshot rockybook.vip and send to admin chat
            $tok = trim($cfg['bot_token'] ?? '');
            $cid = trim($body['chat_id'] ?? $cfg['admin_chat_id'] ?? '');
            $url = trim($body['url'] ?? 'https://rockybook.vip');
            if (!$tok || !$cid) { echo json_encode(['ok' => false, 'error' => 'Bot token / chat_id missing']); exit; }
            $ssFile = RBB_QR_DIR . 'test_ss_' . time() . '.png';
            $src = rbScreenshotUrl($url, $ssFile, 40);
            if ($src && file_exists($ssFile) && filesize($ssFile) > 500) {
                $r = tgSendPhoto($tok, $cid, $ssFile, "📸 <b>Screenshot Test</b>\n🌐 <code>" . htmlspecialchars($url) . "</code>\n✅ Source: <b>{$src}</b>\n🕐 " . date('d/m/Y H:i:s'), null);
                @unlink($ssFile);
                echo json_encode(['ok' => !empty($r['ok']), 'source' => $src, 'tg' => $r]); exit;
            }
            @unlink($ssFile);
            echo json_encode(['ok' => false, 'error' => "Screenshot fetch failed — tried microlink, screenshotone, thum.io", 'source' => null]); exit;

        case 'send_test':
            $cid = trim($body['chat_id'] ?? $cfg['admin_chat_id'] ?? '');
            $tok = trim($cfg['bot_token'] ?? '');
            if (!$cid || !$tok) { echo json_encode(['ok' => false, 'error' => 'Bot token / chat_id missing']); exit; }
            $r = tgSend($tok, $cid, "✅ <b>RockyBook Deposit Bot</b> is working!\n\n" . date('d/m/Y H:i:s'));
            echo json_encode(['ok' => $r['ok'] ?? false]); exit;

        case 'get_deposit_logs':
            // Return last 50 deposit states/transactions from log
            $logs = file_exists(RBB_LOG_FILE) ? (json_decode(file_get_contents(RBB_LOG_FILE), true) ?: []) : [];
            $depLogs = array_values(array_filter($logs, fn($l) => str_contains($l['text'] ?? '', 'Deposit') || str_contains($l['text'] ?? '', 'UTR')));
            echo json_encode(['ok' => true, 'data' => array_slice($depLogs, 0, 50), 'count' => count($depLogs)]); exit;

        case 'get_logs':
            $logs = file_exists(RBB_LOG_FILE) ? (json_decode(file_get_contents(RBB_LOG_FILE), true) ?: []) : [];
            echo json_encode(['ok' => true, 'data' => array_slice($logs, 0, 150)]); exit;

        case 'clear_logs':
            file_put_contents(RBB_LOG_FILE, '[]', LOCK_EX);
            echo json_encode(['ok' => true]); exit;

        default:
            echo json_encode(['ok' => false, 'error' => 'Unknown action']); exit;
    }
}

// ─── HTML Admin Panel ─────────────────────────────────────────
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>RockyBook Deposit Bot <?= RBB_VERSION ?></title>
<style>
:root{--bg:#0e0e12;--s1:#15151c;--s2:#1c1c26;--b:#2a2a3a;--t:#e8e8f0;--td:#8888aa;--tf:#555577;--c:#7c7cff;--g:#39ff14;--r:#ff4466;--y:#ffd700;--or:#ff8c00;--rb:#ff6b1a}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--t);font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;min-height:100vh}
.wrap{max-width:900px;margin:0 auto;padding:20px 16px}
h1{color:var(--rb);font-size:22px;margin-bottom:4px}
.sub{color:var(--td);font-size:12px;margin-bottom:20px}
.card{background:var(--s1);border:1px solid var(--b);border-radius:12px;padding:18px;margin-bottom:16px}
.card h2{font-size:15px;color:var(--c);margin-bottom:14px}
.row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px}
label{display:block;color:var(--td);font-size:11px;margin-bottom:4px}
input,select,textarea{width:100%;background:var(--s2);border:1px solid var(--b);color:var(--t);border-radius:6px;padding:8px 10px;font-size:13px;font-family:inherit;outline:none;transition:border .2s}
input:focus,select:focus,textarea:focus{border-color:var(--c)}
textarea{resize:vertical;min-height:60px;font-size:12px}
.btn{display:inline-flex;align-items:center;gap:5px;padding:7px 14px;border-radius:6px;border:none;cursor:pointer;font-size:13px;font-weight:600;transition:.18s}
.bc{background:var(--c);color:#000}.bc:hover{background:#9090ff}
.bg{background:var(--g);color:#000}.bg:hover{opacity:.85}
.br{background:var(--r);color:#fff}.br:hover{opacity:.85}
.by{background:var(--y);color:#000}.by:hover{opacity:.85}
.bor{background:var(--or);color:#fff}.bor:hover{opacity:.85}
.brb{background:var(--rb);color:#fff}.brb:hover{opacity:.85}
.bgr{background:var(--s2);color:var(--t);border:1px solid var(--b)}.bgr:hover{border-color:var(--c)}
.bsm{padding:5px 10px;font-size:11px}
.flex-end{display:flex;justify-content:flex-end;gap:8px;margin-top:10px}
.f1{flex:1}
.log-box{background:var(--s2);border:1px solid var(--b);border-radius:8px;padding:12px;max-height:320px;overflow-y:auto;font-family:'Share Tech Mono',monospace;font-size:11px}
.log-entry{padding:2px 0;border-bottom:1px solid rgba(255,255,255,.04)}
.log-ok{color:var(--g)}.log-err{color:var(--r)}.log-info{color:var(--c)}
.toast{position:fixed;bottom:24px;right:24px;background:var(--s1);border:1px solid var(--b);border-radius:8px;padding:12px 18px;font-size:13px;z-index:999;transition:.3s;opacity:0;pointer-events:none}
.toast.show{opacity:1;pointer-events:all}
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh}
.login-box{background:var(--s1);border:1px solid var(--b);border-radius:14px;padding:32px;width:320px;text-align:center}
.login-box h2{color:var(--rb);margin-bottom:6px}
.login-box p{color:var(--td);font-size:12px;margin-bottom:20px}
.info-box{background:rgba(57,255,20,.06);border:1px solid rgba(57,255,20,.2);border-radius:8px;padding:12px;font-size:12px;color:var(--g);margin-bottom:14px}
.user-table{width:100%;border-collapse:collapse;font-size:12px}
.user-table th,.user-table td{padding:8px 10px;border-bottom:1px solid var(--b);text-align:left}
.user-table th{color:var(--td);font-weight:600}
.mono{font-family:'Share Tech Mono',monospace;font-size:11px}
@media(max-width:600px){.row{flex-direction:column}}
</style>
</head>
<body>

<?php if (!$isLoggedIn): ?>
<div class="login-wrap">
  <div class="login-box">
    <h2>💰 RockyBook Deposit Bot</h2>
    <p>Admin Panel — Bot Configuration</p>
    <div style="margin-bottom:12px"><label>Password</label><input type="password" id="lpass" placeholder="Admin password" onkeydown="if(event.key==='Enter')doLogin()"></div>
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

<div class="wrap">
  <h1>💰 RockyBook Deposit Bot <small style="font-size:13px;color:var(--td)">v<?= RBB_VERSION ?></small></h1>
  <div class="sub">Telegram bot — Users deposit karte hain, QR code automatically milta hai | <a href="?api_action=logout" style="color:var(--r)">Logout</a></div>

  <div class="info-box">
    ✅ <b>Bot Flow:</b> User /Deposit → RockyBook username link → Amount enter karo → QR code + UPI ID automatically aata hai → User pay karta hai → UTR/screenshot bhejta hai → Admin ko notification
  </div>

  <!-- Action Bar -->
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px">
    <button class="btn bc" onclick="saveCfg()">💾 Save Config</button>
    <button class="btn bgr" onclick="setWebhook()">🔗 Set Webhook</button>
    <button class="btn bgr" onclick="removeWebhook()">❌ Remove Webhook</button>
    <button class="btn bgr" onclick="testRbLogin()">🔑 Test RB Login</button>
    <button class="btn bgr" onclick="testBank()">🏦 Test Bank/UPI</button>
    <button class="btn bg" onclick="sendTest()">📨 Test Message</button>
    <button class="btn brb" onclick="testScreenshot()">📸 Test Screenshot</button>
    <button class="btn bgr" onclick="loadUsers()">📋 Deposit Logs</button>
    <button class="btn bgr" onclick="loadLogs()">📋 All Logs</button>
  </div>

  <!-- Screenshot Test Box -->
  <div class="card" id="ss-test-card" style="display:none">
    <h2>📸 Screenshot Test</h2>
    <div class="row">
      <div class="f1"><label>URL to screenshot</label><input type="text" id="ss-url" value="https://rockybook.vip" placeholder="https://rockybook.vip"></div>
      <div style="width:200px"><label>Send to Chat ID</label><input type="text" id="ss-chat" placeholder="Admin chat ID"></div>
    </div>
    <div style="display:flex;gap:8px;margin-top:8px">
      <button class="btn brb" onclick="doTestScreenshot()">📸 Take & Send Screenshot</button>
      <span id="ss-status" style="line-height:34px;font-size:12px;color:var(--td)"></span>
    </div>
    <div id="ss-result" style="margin-top:10px;font-size:12px;display:none;background:var(--s2);padding:10px;border-radius:6px;word-break:break-all"></div>
  </div>

  <!-- Config -->
  <div class="card">
    <h2>⚙️ Bot Configuration</h2>
    <div class="row">
      <div class="f1"><label>🤖 Telegram Bot Token</label><input type="password" id="cfg-token" placeholder="123456789:ABCdef..."></div>
      <div class="f1"><label>👑 Admin Chat ID (notifications ke liye)</label><input type="text" id="cfg-admin-chat" placeholder="-100xxxx ya apna personal ID"></div>
    </div>

    <div style="background:rgba(255,107,26,.07);border:1px solid rgba(255,107,26,.25);border-radius:8px;padding:12px;margin-bottom:10px">
      <div style="color:var(--rb);font-size:12px;font-weight:700;margin-bottom:8px">🎯 RockyBook Account (Admin)</div>
      <div class="row">
        <div class="f1"><label>📱 Phone (loginType)</label><input type="text" id="cfg-rbphone" placeholder="10-digit phone number"></div>
        <div class="f1"><label>🔒 Password</label><input type="password" id="cfg-rbpass" placeholder="Account password"></div>
      </div>
      <div class="row">
        <div class="f1"><label>🌿 Branch Name</label><input type="text" id="cfg-branch" placeholder="RBVIP1D"></div>
        <div class="f1"><label>🏦 Bank ID (UPI ke liye)</label><input type="text" id="cfg-bankid" placeholder="69ca38e87f96dde534afef82"></div>
      </div>
    </div>

    <div class="row">
      <div style="width:150px"><label>💰 Min Deposit (₹)</label><input type="number" id="cfg-minDep" placeholder="500" min="100"></div>
      <div style="width:150px"><label>💰 Max Deposit (₹)</label><input type="number" id="cfg-maxDep" placeholder="100000" min="500"></div>
    </div>
    <div class="row">
      <div class="f1"><label>👋 Welcome Message (\n for newline)</label><textarea id="cfg-welcome" rows="3"></textarea></div>
    </div>
    <div class="row">
      <div class="f1"><label>✅ Deposit Thanks Message (\n for newline)</label><textarea id="cfg-thanks" rows="2"></textarea></div>
    </div>
    <div class="row">
      <div class="f1"><label>🔒 Change Admin Password (min 4 chars)</label><input type="password" id="cfg-newpass" placeholder="Leave blank to keep current"></div>
    </div>
    <div class="flex-end">
      <button class="btn bc" onclick="saveCfg()">💾 Save Config</button>
    </div>
  </div>

  <!-- Linked Users -->
  <div class="card" id="users-card" style="display:none">
    <h2>👥 Linked Users</h2>
    <div id="users-body"></div>
  </div>

  <!-- Logs -->
  <div class="card">
    <h2>📋 Logs</h2>
    <div style="display:flex;gap:8px;margin-bottom:10px">
      <button class="btn bgr bsm" onclick="loadLogs()">🔄 Refresh</button>
      <button class="btn bor bsm" onclick="clearLogs()">🗑️ Clear</button>
    </div>
    <div class="log-box" id="log-box"><div style="color:var(--td)">Loading...</div></div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
function g(id){ return document.getElementById(id); }
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function toast(msg,type='info'){
  const t=g('toast');t.textContent=msg;
  t.style.borderColor=type==='success'?'var(--g)':type==='error'?'var(--r)':'var(--c)';
  t.style.color=type==='success'?'var(--g)':type==='error'?'var(--r)':'var(--c)';
  t.classList.add('show');setTimeout(()=>t.classList.remove('show'),3500);
}
async function api(action,payload={}){
  const opts={method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)};
  return await fetch('?api_action='+action,opts).then(x=>x.json()).catch(e=>({ok:false,error:String(e)}));
}

async function loadConfig(){
  const r=await fetch('?api_action=get_config').then(x=>x.json());
  if(!r.ok) return;
  const d=r.data||{};
  g('cfg-token').value=d.bot_token||'';
  g('cfg-admin-chat').value=d.admin_chat_id||'';
  g('cfg-rbphone').value=d.rb_phone||'';
  g('cfg-rbpass').value=d.rb_password||'';
  g('cfg-branch').value=d.rb_branch||'RBVIP1D';
  g('cfg-bankid').value=d.rb_bank_id||'69ca38e87f96dde534afef82';
  g('cfg-minDep').value=d.min_deposit||500;
  g('cfg-maxDep').value=d.max_deposit||100000;
  g('cfg-welcome').value=d.welcome_msg||'';
  g('cfg-thanks').value=d.deposit_thanks||'';
}

async function saveCfg(){
  const payload={
    bot_token:      g('cfg-token').value.trim(),
    admin_chat_id:  g('cfg-admin-chat').value.trim(),
    rb_phone:       g('cfg-rbphone').value.trim(),
    rb_password:    g('cfg-rbpass').value.trim(),
    rb_branch:      g('cfg-branch').value.trim()||'RBVIP1D',
    rb_bank_id:     g('cfg-bankid').value.trim(),
    min_deposit:    parseInt(g('cfg-minDep').value)||500,
    max_deposit:    parseInt(g('cfg-maxDep').value)||100000,
    welcome_msg:    g('cfg-welcome').value,
    deposit_thanks: g('cfg-thanks').value,
    new_pass:       g('cfg-newpass').value.trim(),
  };
  const r=await api('save_config',payload);
  r.ok ? toast('✅ Config saved!','success') : toast('Error: '+(r.error||''),'error');
}

async function setWebhook(){
  toast('Setting webhook...','info');
  const r=await api('set_webhook');
  r.ok ? toast('✅ Webhook set: '+r.webhook_url,'success') : toast('❌ '+( r.error||r.tg?.description||'failed'),'error');
}
async function removeWebhook(){
  const r=await api('remove_webhook');
  r.ok ? toast('Webhook removed','info') : toast('Error','error');
}

async function testRbLogin(){
  toast('Testing RockyBook login...','info');
  const r=await api('test_rb_login');
  if(r.ok){ const u=r.user||{}; toast('✅ Login OK! '+( u.clientName||JSON.stringify(u).slice(0,60)),'success'); }
  else toast('❌ '+( r.error||'Login failed'),'error');
}

async function testBank(){
  toast('Fetching bank details...','info');
  const r=await api('test_bank');
  const card=g('users-card');
  const body=g('users-body');
  card.style.display='block';
  if(r.ok && r.bank){
    const b=r.bank;
    toast('✅ Bank details mili!','success');
    body.innerHTML=`<div style="font-size:13px;line-height:2">
      <b>✅ Bank Details (Live):</b><br>
      📱 UPI ID: <code>${esc(b.upiId||'—')}</code><br>
      👤 Holder: <b>${esc(b.accHolderName||'—')}</b><br>
      🔢 Acc No: <code>${esc(b.accNo||'—')}</code><br>
      🏛 IFSC: <code>${esc(b.ifscCode||'—')}</code><br>
      🏦 Bank: ${esc(b.bankName||'—')}
    </div>
    <details style="margin-top:10px"><summary style="cursor:pointer;color:var(--td);font-size:11px">Raw Debug</summary>
    <pre style="font-size:10px;overflow:auto;max-height:200px;color:var(--td)">${esc(JSON.stringify(r.debug,null,2))}</pre></details>`;
  } else {
    toast('❌ Bank details nahi mili — RB login sahi hai?','error');
    body.innerHTML=`<div style="color:var(--r)">❌ Bank details nahi mili<br><pre style="font-size:10px;margin-top:8px">${esc(JSON.stringify(r.debug||r,null,2))}</pre></div>`;
    card.style.display='block';
  }
}

async function testScreenshot(){
  const card=g('ss-test-card');
  card.style.display=card.style.display==='none'?'block':'none';
  if(card.style.display==='block'){
    g('ss-chat').value=g('cfg-admin-chat').value||'';
  }
}

async function doTestScreenshot(){
  const url=g('ss-url').value.trim();
  const chat=g('ss-chat').value.trim();
  if(!url||!chat){toast('URL aur Chat ID dono chahiye','error');return;}
  const st=g('ss-status');const res=g('ss-result');
  st.textContent='⏳ Screenshot le raha hai (30-40 sec lag sakte hain)...';
  st.style.color='var(--y)';
  res.style.display='none';
  const r=await api('test_screenshot',{url,chat_id:chat});
  if(r.ok){
    st.textContent='✅ Screenshot sent! Source: '+(r.source||'?');
    st.style.color='var(--g)';
    toast('✅ Screenshot send ho gayi!','success');
  } else {
    st.textContent='❌ Failed: '+(r.error||'unknown');
    st.style.color='var(--r)';
    toast('❌ Screenshot fail — '+( r.error||''),'error');
    res.style.display='block';
    res.innerHTML='<b style="color:var(--r)">Error:</b> '+esc(r.error||'')+
      (r.source?'<br>Source tried: '+esc(r.source):'');
  }
}

async function sendTest(){
  toast('Sending test message...','info');
  const r=await api('send_test',{chat_id:g('cfg-admin-chat').value.trim()});
  r.ok ? toast('✅ Message sent!','success') : toast('❌ '+(r.error||'failed'),'error');
}

async function loadUsers(){
  const r=await api('get_deposit_logs');
  const card=g('users-card');
  const body=g('users-body');
  card.style.display='block';
  if(!r.ok||!r.data?.length){body.innerHTML='<div style="color:var(--td)">Koi deposit log nahi abhi tak.</div>';return;}
  let rows='';
  r.data.forEach(l=>{
    const d=new Date(l.time).toLocaleString();
    const cls=l.type==='success'?'log-ok':l.type==='error'?'log-err':'log-info';
    rows+=`<tr><td style="color:var(--td);font-size:11px">${d}</td><td class="${cls}">${esc(l.text)}</td></tr>`;
  });
  body.innerHTML=`<div style="margin-bottom:8px;font-size:12px;color:var(--td)">Deposit & UTR logs: <b>${r.count}</b></div>`
    +`<table class="user-table"><thead><tr><th>Time</th><th>Log</th></tr></thead><tbody>${rows}</tbody></table>`;
}

async function loadLogs(){
  const r=await fetch('?api_action=get_logs').then(x=>x.json());
  const box=g('log-box');
  if(!r.ok||!r.data?.length){box.innerHTML='<div style="color:var(--tf)">No logs yet.</div>';return;}
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

loadConfig();
loadLogs();
</script>
</body>
</html>
