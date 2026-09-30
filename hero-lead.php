<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.html');
    exit;
}

// Honeypot: bots fill the hidden field. Pretend success and drop the lead.
if (!empty($_POST['website'])) {
    header('Location: thankyou.html');
    exit;
}

// Raw (trimmed, length-capped) values: stored as-is in the DB, escaped only for the email.
$raw = fn($k, $max) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
$name      = $raw('name', 120);
$phone     = $raw('phone', 30);
$service   = $raw('service', 80);
$location  = $raw('location', 120);
$visitTime = $raw('visit_time', 40);
$pageUrl   = $raw('page_url', 300);
$pageTitle = $raw('page_title', 200);
$landing   = $raw('landing_page', 300);
$referrer  = $raw('referrer', 300);
$utmSource = strtolower($raw('utm_source', 60));
$utmMedium = strtolower($raw('utm_medium', 60));
$utmCamp   = $raw('utm_campaign', 100);
$formName  = 'hero-form';

// Lead source: explicit utm_source wins, otherwise derive it from the referrer host, otherwise 'direct'.
$leadSource = $utmSource;
if ($leadSource === '') {
    $host    = strtolower((string)parse_url($referrer, PHP_URL_HOST));
    $ownHost = strtolower((string)parse_url($pageUrl, PHP_URL_HOST));
    if ($host === '' || ($ownHost !== '' && $host === $ownHost)) {
        $leadSource = 'direct';
    } else {
        $map = ['google' => 'google', 'bing' => 'bing', 'yahoo' => 'yahoo', 'duckduckgo' => 'duckduckgo',
                'facebook' => 'facebook', 'instagram' => 'instagram', 'youtube' => 'youtube',
                'whatsapp' => 'whatsapp', 't.co' => 'twitter', 'twitter' => 'twitter', 'linkedin' => 'linkedin'];
        $leadSource = $host;
        foreach ($map as $needle => $label) {
            if (strpos($host, $needle) !== false) { $leadSource = $label; break; }
        }
    }
}
$visitDate = $raw('visit_date', 10);
$visitDate = (preg_match('/^\d{4}-\d{2}-\d{2}$/', $visitDate) && strtotime($visitDate)) ? $visitDate : null;

$digits = preg_replace('/\D/', '', $phone);
if ($name === '' || $service === '' || strlen($digits) < 10 || strlen($digits) > 13) {
    http_response_code(400);
    echo "<script>alert('Please enter your name, a valid mobile number and select a service.'); window.history.back();</script>";
    exit;
}

$cfg = require __DIR__ . '/mail-config.php';

// 1) Save the lead first, so it is kept even if the email fails.
try {
    $db  = $cfg['db'];
    $pdo = new PDO(
        "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}",
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->prepare(
        'INSERT INTO hero_leads (name, phone, service, location, visit_date, visit_time, form_name, lead_source, page_url, page_title, landing_page, referrer, utm_source, utm_medium, utm_campaign, ip_address, user_agent)
         VALUES (:name, :phone, :service, :location, :visit_date, :visit_time, :form_name, :lead_source, :page_url, :page_title, :landing_page, :referrer, :utm_source, :utm_medium, :utm_campaign, :ip, :ua)'
    )->execute([
        ':name'       => $name,
        ':phone'      => $phone,
        ':service'    => $service,
        ':location'   => $location !== '' ? $location : null,
        ':visit_date' => $visitDate,
        ':visit_time' => $visitTime !== '' ? $visitTime : null,
        ':form_name'    => $formName,
        ':lead_source'  => $leadSource,
        ':page_url'     => $pageUrl !== '' ? $pageUrl : null,
        ':page_title'   => $pageTitle !== '' ? $pageTitle : null,
        ':landing_page' => $landing !== '' ? $landing : null,
        ':referrer'     => $referrer !== '' ? $referrer : null,
        ':utm_source'   => $utmSource !== '' ? $utmSource : null,
        ':utm_medium'   => $utmMedium !== '' ? $utmMedium : null,
        ':utm_campaign' => $utmCamp !== '' ? $utmCamp : null,
        ':ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
        ':ua'         => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255) ?: null,
    ]);
    $saved = true;
} catch (Throwable $e) {
    error_log('hero-lead DB save failed: ' . $e->getMessage());
    $saved = false;
}

// 2) Email notification.
$e = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$or = fn($v) => $v !== null && $v !== '' ? $e($v) : 'Not specified';

$mail = new PHPMailer(true);
try {
    $mail->isSMTP();
    $mail->Host       = $cfg['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $cfg['username'];
    $mail->Password   = $cfg['password'];
    $mail->SMTPSecure = $cfg['secure'];
    $mail->Port       = $cfg['port'];

    $mail->setFrom($cfg['from'], $cfg['from_name']);
    $mail->addAddress($cfg['to']);

    $mail->isHTML(true);
    $mail->Subject = 'New Hero Enquiry: ' . $name . ' - ' . $service;
    $mail->Body    = '
        <h3>New Hero Form Enquiry</h3>
        <p><b>Name:</b> ' . $e($name) . '</p>
        <p><b>Phone:</b> ' . $e($phone) . '</p>
        <p><b>Service:</b> ' . $e($service) . '</p>
        <p><b>Location:</b> ' . $or($location) . '</p>
        <p><b>Preferred date:</b> ' . $or($visitDate) . '</p>
        <p><b>Preferred time:</b> ' . $or($visitTime) . '</p>
        <p><b>Lead source:</b> ' . $or($leadSource) . '</p>
        <p><b>Page lead came from:</b> ' . $or($pageUrl) . '</p>
        <p><b>Landing page:</b> ' . $or($landing) . '</p>
        <p><b>Referrer:</b> ' . $or($referrer) . '</p>
        <p><b>Saved to database:</b> ' . ($saved ? 'Yes' : 'NO - check server log') . '</p>
        <p><b>Submitted on:</b> ' . date('Y-m-d H:i:s') . '</p>
    ';
    $mail->send();
    $mailed = true;
} catch (Exception $ex) {
    error_log('hero-lead mail failed: ' . $mail->ErrorInfo);
    $mailed = false;
}

// The visitor only sees an error if the lead was neither saved nor emailed.
if ($saved || $mailed) {
    header('Location: thankyou.html');
    exit;
}

http_response_code(500);
echo "<script>alert('Sorry, we could not send your request. Please call us on +91 73055 12199.'); window.history.back();</script>";
