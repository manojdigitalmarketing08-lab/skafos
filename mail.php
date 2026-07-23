

<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Form fields matching your HTML
    $name    = htmlspecialchars(trim($_POST["name"] ?? ""));      // Full Name
    $email   = htmlspecialchars(trim($_POST["email"] ?? ""));     // Email Address
    $phone   = htmlspecialchars(trim($_POST["phone"] ?? ""));     // Mobile Number
    $service = htmlspecialchars(trim($_POST["subject"] ?? ""));   // Service Required
    $message = htmlspecialchars(trim($_POST["message"] ?? ""));   // Describe Issue
    // $privacy = isset($_POST["privacy_consent"]) ? "Yes" : "No";   // Privacy checkbox

    // Required fields (adjust as needed)
    if (empty($name) || empty($phone) || empty($service) || empty($email)) {
        die("All required fields (name, email, phone, service) must be filled.");
    }

    $mail = new PHPMailer(true);

    try {
        // SMTP configuration
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'info@skafosmenswellness.com';
        $mail->Password   = 'X7RZ:UeY0x^E'; // 🔒 Replace with your actual password
        $mail->SMTPSecure = 'ssl';
        $mail->Port       = 465;

        // Sender & recipient
        $mail->setFrom('info@skafosmenswellness.com', 'Skafos Mens Wellness');
        $mail->addAddress('admin@skafosaesthetics.com');

        // Email content
        $mail->isHTML(true);
        $mail->Subject = "New Booking: $name - $service";

        $mail->Body = "
            <h3>New Booking Request</h3>
            <p><b>Name:</b> $name</p>
            <p><b>Email:</b> $email</p>
            <p><b>Phone:</b> $phone</p>
            <p><b>Service Required:</b> $service</p>
            <p><b>Message / Issue:</b><br>" . nl2br($message) . "</p>
            <p><b>Submitted on:</b> " . date('Y-m-d H:i:s') . "</p>
        ";

        $mail->send();

        // Success: show alert and redirect
        echo "
        <script>
            alert('✅ Your booking has been sent successfully!');
            window.location.href = 'thankyou.html';
        </script>
        ";

    } catch (Exception $e) {
        echo "<script>alert('❌ Mail failed: {$mail->ErrorInfo}'); window.history.back();</script>";
    }
}
?>