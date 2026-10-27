<?php
/**
 * Skafos Men's Wellness - hero lead form API (single file).
 *
 * POST target of the shared hero form (hero-form.html). Flow:
 *   capture + validate -> save to MySQL (table is created automatically) -> WhatsApp admin alert -> email -> redirect.
 *
 * Sections:  1 CONFIG   2 HELPERS   3 DATABASE SCHEMA   4 FORM CAPTURE   5 LEAD SOURCE
 *            6 SAVE     7 WHATSAPP  8 EMAIL             9 RESPONSE
 *
 * Put the real SMTP / database / WhatsApp values in section 1 ON THE SERVER. Do not commit the filled-in file
 * to a public repository.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/* ============================== 1. CONFIG ============================== */
$CONFIG = [
    'site' => [
        'success_page'  => '../enquiry-success.html',   // relative to /api/
        'home_page'     => '../index.html',
        'clinic_phone'  => '+91 73055 12199',
    ],
    'smtp' => [
        'host'      => 'smtp.hostinger.com',
        'username'  => 'CHANGE_ME',
        'password'  => 'CHANGE_ME',
        'port'      => 465,
        'secure'    => 'ssl',
        'from'      => 'CHANGE_ME',                     // usually the same as username
        'from_name' => "Skafos Men's Wellness",
        'to'        => 'CHANGE_ME',                     // where lead emails are delivered
    ],
    'db' => [
        'host'    => 'localhost',
        'name'    => 'CHANGE_ME',
        'user'    => 'CHANGE_ME',
        'pass'    => 'CHANGE_ME',
        'charset' => 'utf8mb4',
        'table'   => 'hero_leads',
    ],
    'whatsapp' => [                                      // Meta WhatsApp Cloud API admin alert
        'enabled'         => false,                      // set true after filling the values below
        'token'           => 'CHANGE_ME',                // permanent System User access token
        'phone_number_id' => 'CHANGE_ME',                // sender phone number ID (not the number itself)
        'to'              => ['CHANGE_ME'],              // admin numbers: country code + digits, e.g. 917305512199
        'template'        => 'new_website_lead_alert',
        'language'        => 'en',
        'api_version'     => 'v21.0',
    ],
    'sheets' => [                                        // Google Sheet via Apps Script web app (see api/google-sheet-script.gs)
        'enabled'     => false,                          // set true after filling the values below
        'webhook_url' => 'CHANGE_ME',                    // Apps Script "Web app" URL ending in /exec
        'secret'      => 'CHANGE_ME',                    // same value as SECRET in the Apps Script
    ],
];

/*
 * Optional overrides: api/mail-config.php (kept out of git) can supply the real values instead of editing this file.
 * It returns a flat array: host, username, password, port, secure, from, from_name, to (SMTP/email)
 * plus nested 'db' and 'whatsapp' arrays. Anything left as CHANGE_ME there is ignored.
 */
$overrideFile = __DIR__ . '/mail-config.php';
if (is_file($overrideFile)) {
    $o = require $overrideFile;
    if (is_array($o)) {
        foreach (['host', 'username', 'password', 'port', 'secure', 'from', 'from_name', 'to'] as $k) {
            if (isset($o[$k]) && $o[$k] !== 'CHANGE_ME' && $o[$k] !== '') $CONFIG['smtp'][$k] = $o[$k];
        }
        foreach (['db', 'whatsapp', 'sheets'] as $section) {
            if (!empty($o[$section]) && is_array($o[$section])) {
                foreach ($o[$section] as $k => $v) {
                    if ($v !== 'CHANGE_ME' && $v !== '' && $v !== ['CHANGE_ME']) $CONFIG[$section][$k] = $v;
                }
            }
        }
    }
}

/* ============================== 2. HELPERS ============================== */
function is_placeholder($v): bool { return $v === '' || $v === 'CHANGE_ME'; }

function str_cut(string $v, int $len): string {
    return function_exists('mb_substr') ? mb_substr($v, 0, $len) : substr($v, 0, $len);
}

function field(string $key, int $max): string {
    return str_cut(trim((string)($_POST[$key] ?? '')), $max);
}

function esc(?string $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function or_na($v): string {
    return $v !== null && $v !== '' ? esc((string)$v) : 'Not specified';
}

function go(string $url): void {
    header('Location: ' . $url);
    exit;
}

function fail_page(string $message, int $status): void {
    http_response_code($status);
    echo '<script>alert(' . json_encode($message) . '); window.history.back();</script>';
    exit;
}

/* ========================== 3. DATABASE SCHEMA ========================== */
/** Columns of the leads table. Used to create the table and to add any column that is missing later. */
function lead_columns(): array {
    return [
        'name'          => 'VARCHAR(120) NOT NULL',
        'phone'         => 'VARCHAR(30) NOT NULL',
        'service'       => 'VARCHAR(80) NOT NULL',
        'location'      => 'VARCHAR(120) NULL',
        'visit_date'    => 'DATE NULL',
        'visit_time'    => 'VARCHAR(40) NULL',
        'form_name'     => 'VARCHAR(40) NULL',            // which form: hero-form
        'lead_source'   => 'VARCHAR(60) NULL',            // google, facebook, instagram, direct, or utm_source
        'page_url'      => 'VARCHAR(300) NULL',           // page the form was submitted on
        'page_title'    => 'VARCHAR(200) NULL',
        'landing_page'  => 'VARCHAR(300) NULL',           // first page of the visit
        'referrer'      => 'VARCHAR(300) NULL',
        'utm_source'    => 'VARCHAR(60) NULL',
        'utm_medium'    => 'VARCHAR(60) NULL',
        'utm_campaign'  => 'VARCHAR(100) NULL',
        'ip_address'    => 'VARCHAR(45) NULL',
        'user_agent'    => 'VARCHAR(255) NULL',
        'whatsapp_alert'=> 'TINYINT(1) NOT NULL DEFAULT 0',
        'email_sent'    => 'TINYINT(1) NOT NULL DEFAULT 0',
        'status'        => "ENUM('new','contacted','booked','closed') NOT NULL DEFAULT 'new'",
        'created_at'    => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP',
    ];
}

/** Creates the table if it does not exist and adds missing columns. Safe to run repeatedly. */
function ensure_schema(PDO $pdo, string $table): void {
    $defs = [];
    foreach (lead_columns() as $col => $def) $defs[] = "`$col` $def";
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `$table` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            " . implode(",\n            ", $defs) . ",
            KEY `idx_created` (`created_at`),
            KEY `idx_status` (`status`),
            KEY `idx_source` (`lead_source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $have = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    foreach (lead_columns() as $col => $def) {
        if (!in_array($col, $have, true)) {
            $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        }
    }
}

function db_connect(array $db): PDO {
    return new PDO(
        "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
}

/* ============================ 4. FORM CAPTURE ============================ */
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    go($CONFIG['site']['home_page']);
}

// Honeypot: bots fill the hidden "website" field. Pretend success and drop the lead.
if (!empty($_POST['website'])) {
    go($CONFIG['site']['success_page']);
}

$lead = [
    'name'         => field('name', 120),
    'phone'        => field('phone', 30),
    'service'      => field('service', 80),
    'location'     => field('location', 120),
    'visit_time'   => field('visit_time', 40),
    'page_url'     => field('page_url', 300),
    'page_title'   => field('page_title', 200),
    'landing_page' => field('landing_page', 300),
    'referrer'     => field('referrer', 300),
    'utm_source'   => strtolower(field('utm_source', 60)),
    'utm_medium'   => strtolower(field('utm_medium', 60)),
    'utm_campaign' => field('utm_campaign', 100),
    'form_name'    => 'hero-form',
    'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '',
    'user_agent'   => str_cut((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 255),
];

$date = field('visit_date', 10);
$lead['visit_date'] = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && strtotime($date)) ? $date : '';

$digits = preg_replace('/\D/', '', $lead['phone']);
if ($lead['name'] === '' || $lead['service'] === '' || strlen($digits) < 10 || strlen($digits) > 13) {
    fail_page('Please enter your name, a valid mobile number and select a service.', 400);
}

/* ============================= 5. LEAD SOURCE ============================= */
// utm_source wins, otherwise the referrer site, otherwise "direct".
$lead['lead_source'] = $lead['utm_source'];
if ($lead['lead_source'] === '') {
    $host    = strtolower((string)parse_url($lead['referrer'], PHP_URL_HOST));
    $ownHost = strtolower((string)parse_url($lead['page_url'], PHP_URL_HOST));
    if ($host === '' || ($ownHost !== '' && $host === $ownHost)) {
        $lead['lead_source'] = 'direct';
    } else {
        $known = ['google' => 'google', 'bing' => 'bing', 'yahoo' => 'yahoo', 'duckduckgo' => 'duckduckgo',
                  'facebook' => 'facebook', 'instagram' => 'instagram', 'youtube' => 'youtube',
                  'whatsapp' => 'whatsapp', 't.co' => 'twitter', 'twitter' => 'twitter', 'linkedin' => 'linkedin'];
        $lead['lead_source'] = $host;
        foreach ($known as $needle => $label) {
            if (strpos($host, $needle) !== false) { $lead['lead_source'] = $label; break; }
        }
    }
}

/* ================================ 6. SAVE ================================ */
$pdo = null;
$leadId = null;
$saved = false;

function save_lead(PDO $pdo, string $table, array $lead): int {
    $cols = ['name', 'phone', 'service', 'location', 'visit_date', 'visit_time', 'form_name', 'lead_source',
             'page_url', 'page_title', 'landing_page', 'referrer', 'utm_source', 'utm_medium', 'utm_campaign',
             'ip_address', 'user_agent'];
    $sql = "INSERT INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (:' . implode(',:', $cols) . ')';
    $params = [];
    foreach ($cols as $c) {
        $v = $lead[$c] ?? '';
        $params[':' . $c] = $v === '' && !in_array($c, ['name', 'phone', 'service'], true) ? null : $v;
    }
    $pdo->prepare($sql)->execute($params);
    return (int)$pdo->lastInsertId();
}

if (!is_placeholder($CONFIG['db']['name'])) {
    try {
        $pdo   = db_connect($CONFIG['db']);
        $table = $CONFIG['db']['table'];
        try {
            $leadId = save_lead($pdo, $table, $lead);
        } catch (PDOException $e) {
            // Table or column missing (first run / schema updated): build the schema, then retry once.
            if (in_array($e->getCode(), ['42S02', '42S22'], true)) {
                ensure_schema($pdo, $table);
                $leadId = save_lead($pdo, $table, $lead);
            } else {
                throw $e;
            }
        }
        $saved = true;
    } catch (Throwable $e) {
        error_log('mailer: DB save failed: ' . $e->getMessage());
    }
}

/* ============================== 7. WHATSAPP ============================== */
function wa_param($v): string {
    $v = trim(preg_replace('/\s+/', ' ', (string)$v));   // Meta rejects newlines/tabs in template variables
    return str_cut($v !== '' ? $v : 'Not specified', 200);
}

/** Sends the approved admin-alert template (7 body variables) to every admin number. Returns true if any send worked. */
function wa_send_admin_alert(array $wa, array $vars): bool {
    if (empty($wa['enabled']) || is_placeholder($wa['token']) || is_placeholder($wa['phone_number_id'])) {
        return false;
    }
    $params = array_map(fn($v) => ['type' => 'text', 'text' => wa_param($v)], $vars);
    $url    = 'https://graph.facebook.com/' . $wa['api_version'] . '/' . $wa['phone_number_id'] . '/messages';
    $any    = false;

    foreach ((array)$wa['to'] as $to) {
        $to = preg_replace('/\D/', '', (string)$to);
        if ($to === '') continue;
        $payload = json_encode([
            'messaging_product' => 'whatsapp',
            'to'                => $to,
            'type'              => 'template',
            'template'          => [
                'name'       => $wa['template'],
                'language'   => ['code' => $wa['language']],
                'components' => [['type' => 'body', 'parameters' => $params]],
            ],
        ]);
        $headers = ['Authorization: Bearer ' . $wa['token'], 'Content-Type: application/json'];

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            $res  = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        } else {
            $ctx  = stream_context_create(['http' => [
                'method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $payload,
                'timeout' => 8, 'ignore_errors' => true,
            ]]);
            $res  = @file_get_contents($url, false, $ctx);
            $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
        }
        if ($code >= 200 && $code < 300) {
            $any = true;
        } else {
            error_log('mailer: WhatsApp alert failed (' . $code . ') to ' . $to . ': ' . substr((string)$res, 0, 300));
        }
    }
    return $any;
}

$waSent = false;
if ($saved) {
    try {
        $waSent = wa_send_admin_alert($CONFIG['whatsapp'], [
            $lead['name'],                                       // {{1}} Name
            $lead['phone'],                                      // {{2}} Phone
            $lead['service'],                                    // {{3}} Service
            $lead['location'],                                   // {{4}} Area
            trim($lead['visit_date'] . ' ' . $lead['visit_time']), // {{5}} Preferred slot
            $lead['lead_source'],                                // {{6}} Lead source
            (string)parse_url($lead['page_url'], PHP_URL_PATH),  // {{7}} Page
        ]);
    } catch (Throwable $e) {
        error_log('mailer: WhatsApp alert error: ' . $e->getMessage());
    }
}

/* ============================ 7b. GOOGLE SHEET ============================ */
/** Appends the lead as a row in Google Sheets through an Apps Script web app. Returns true on success. */
function sheet_send_lead(array $gs, array $lead, ?int $leadId): bool {
    if (empty($gs['enabled']) || is_placeholder($gs['webhook_url']) || is_placeholder($gs['secret'])) {
        return false;
    }
    $payload = json_encode([
        'secret'       => $gs['secret'],
        'submitted_at' => date('Y-m-d H:i:s'),
        'id'           => $leadId,
        'name'         => $lead['name'],
        'phone'        => $lead['phone'],
        'service'      => $lead['service'],
        'location'     => $lead['location'],
        'visit_date'   => $lead['visit_date'],
        'visit_time'   => $lead['visit_time'],
        'lead_source'  => $lead['lead_source'],
        'utm_medium'   => $lead['utm_medium'],
        'utm_campaign' => $lead['utm_campaign'],
        'page_url'     => $lead['page_url'],
        'landing_page' => $lead['landing_page'],
        'referrer'     => $lead['referrer'],
    ]);

    if (function_exists('curl_init')) {
        $ch = curl_init($gs['webhook_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true,   // Apps Script answers via a redirect
            CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    } else {
        $ctx  = stream_context_create(['http' => [
            'method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $payload,
            'timeout' => 10, 'ignore_errors' => true, 'follow_location' => 1,
        ]]);
        $res  = @file_get_contents($gs['webhook_url'], false, $ctx);
        $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    }

    $ok = $code >= 200 && $code < 300 && strpos((string)$res, '"ok":true') !== false;
    if (!$ok) {
        error_log('mailer: Google Sheet append failed (' . $code . '): ' . substr((string)$res, 0, 300));
    }
    return $ok;
}

$sheetSent = false;
try {
    $sheetSent = sheet_send_lead($CONFIG['sheets'], $lead, $leadId);
} catch (Throwable $e) {
    error_log('mailer: Google Sheet error: ' . $e->getMessage());
}

/* ================================ 8. EMAIL ================================ */
$mailed = false;
$smtp   = $CONFIG['smtp'];
if (!is_placeholder($smtp['password'])) {
    $root = dirname(__DIR__) . '/PHPMailer/src/';
    require_once $root . 'Exception.php';
    require_once $root . 'PHPMailer.php';
    require_once $root . 'SMTP.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = $smtp['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $smtp['username'];
        $mail->Password   = $smtp['password'];
        $mail->SMTPSecure = $smtp['secure'];
        $mail->Port       = $smtp['port'];
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($smtp['from'], $smtp['from_name']);
        $mail->addAddress($smtp['to']);

        $mail->isHTML(true);
        $mail->Subject = 'New Hero Enquiry: ' . $lead['name'] . ' - ' . $lead['service'];
        $mail->Body    = '
            <h3>New Hero Form Enquiry</h3>
            <p><b>Name:</b> ' . esc($lead['name']) . '</p>
            <p><b>Phone:</b> ' . esc($lead['phone']) . '</p>
            <p><b>Service:</b> ' . esc($lead['service']) . '</p>
            <p><b>Location:</b> ' . or_na($lead['location']) . '</p>
            <p><b>Preferred date:</b> ' . or_na($lead['visit_date']) . '</p>
            <p><b>Preferred time:</b> ' . or_na($lead['visit_time']) . '</p>
            <p><b>Lead source:</b> ' . or_na($lead['lead_source']) . '</p>
            <p><b>Page lead came from:</b> ' . or_na($lead['page_url']) . '</p>
            <p><b>Landing page:</b> ' . or_na($lead['landing_page']) . '</p>
            <p><b>Referrer:</b> ' . or_na($lead['referrer']) . '</p>
            <p><b>Saved to database:</b> ' . ($saved ? 'Yes (#' . (int)$leadId . ')' : 'NO - check server log') . '</p>
            <p><b>WhatsApp alert sent:</b> ' . ($waSent ? 'Yes' : 'No') . '</p>
            <p><b>Added to Google Sheet:</b> ' . ($sheetSent ? 'Yes' : 'No') . '</p>
            <p><b>Submitted on:</b> ' . date('Y-m-d H:i:s') . '</p>
        ';
        $mail->send();
        $mailed = true;
    } catch (MailException $e) {
        error_log('mailer: email failed: ' . $mail->ErrorInfo);
    }
}

// Record what actually went out for this lead.
if ($saved && $pdo && $leadId) {
    try {
        $pdo->prepare('UPDATE `' . $CONFIG['db']['table'] . '` SET whatsapp_alert = ?, email_sent = ? WHERE id = ?')
            ->execute([(int)$waSent, (int)$mailed, $leadId]);
    } catch (Throwable $e) {
        error_log('mailer: status update failed: ' . $e->getMessage());
    }
}

/* ============================== 9. RESPONSE ============================== */
// The visitor only sees an error if the lead was neither saved nor emailed.
if ($saved || $mailed) {
    go($CONFIG['site']['success_page']);
}

fail_page('Sorry, we could not send your request. Please call us on ' . $CONFIG['site']['clinic_phone'] . '.', 500);
