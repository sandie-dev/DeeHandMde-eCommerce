
<?php

require_once __DIR__ . "/includes/database.php";
require_once __DIR__ . "/includes/mail_config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . "/PHPMailer/Exception.php";
require_once __DIR__ . "/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/PHPMailer/SMTP.php";

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");

    if ($email === "" || !filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } else {

        // Use a general message so the page does not reveal
        // whether an account exists for this email address.
        $message = "If an account exists for that email address, "
                 . "you will receive a password-reset link shortly.";

        try {

            $stmt = $pdo->prepare(
                "SELECT id, name, email FROM users WHERE email = ? LIMIT 1"
            );

            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {

                // Invalidate previous unused reset links for this user.
                $stmt = $pdo->prepare(
                    "UPDATE password_resets
                     SET used_at = NOW()
                     WHERE user_id = ? AND used_at IS NULL"
                );

                $stmt->execute([$user["id"]]);

                // Generate a secure random token.
                $token = bin2hex(random_bytes(32));
                $tokenHash = password_hash($token, PASSWORD_DEFAULT);

                // Link expires in one hour.
                $stmt = $pdo->prepare(
                    "INSERT INTO password_resets
                        (user_id, token_hash, expires_at)
                     VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))"
                );

                $stmt->execute([
                    $user["id"],
                    $tokenHash
                ]);

                // Local development URL.
                // Change this to your current public HTTPS URL
                // when testing through ngrok.
                $resetLink = "http://localhost/deeHandMade/reset_password.php?token="
                           . urlencode($token);

                $mail = new PHPMailer(true);

                try {

                    $mail->isSMTP();
                    $mail->Host = MAIL_HOST;
                    $mail->SMTPAuth = true;
                    $mail->Username = MAIL_USERNAME;
                    $mail->Password = MAIL_PASSWORD;
                    $mail->Port = MAIL_PORT;
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

                    $mail->CharSet = "UTF-8";
                    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
                    $mail->addAddress($user["email"], $user["name"]);

                    $safeName = htmlspecialchars(
                        $user["name"],
                        ENT_QUOTES,
                        "UTF-8"
                    );

                    $safeLink = htmlspecialchars(
                        $resetLink,
                        ENT_QUOTES,
                        "UTF-8"
                    );

                    $mail->isHTML(true);
                    $mail->Subject = "Reset your DeeHandMade password";

                    $mail->Body = "
                        <h2>Password Reset</h2>
                        <p>Hello {$safeName},</p>
                        <p>We received a request to reset your DeeHandMade password.</p>
                        <p>
                            <a href=\"{$safeLink}\">
                                Reset My Password
                            </a>
                        </p>
                        <p>This link expires in one hour and can only be used once.</p>
                        <p>If you did not request this, you can ignore this email.</p>
                    ";

                    $mail->AltBody =
                        "We received a request to reset your DeeHandMade password. "
                        . "Open this link within one hour: "
                        . $resetLink
                        . ". If you did not request this, ignore this email.";

                    $mail->send();

                } catch (Exception $e) {

                    // Do not expose SMTP details to website visitors.
                    error_log(
                        "Password reset email failed: " . $mail->ErrorInfo
                    );

                    // Invalidate the token if the email could not be sent.
                    $stmt = $pdo->prepare(
                        "UPDATE password_resets
                         SET used_at = NOW()
                         WHERE user_id = ? AND used_at IS NULL"
                    );

                    $stmt->execute([$user["id"]]);
                }
            }

        } catch (Throwable $e) {

            error_log("Password reset request failed: " . $e->getMessage());

            $error = "We could not process your request right now. Please try again later.";
            $message = "";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Forgot Password - DeeHandMade</title>

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loginStyle.css">
</head>

<body>

<div class="auth-container">

    <h1>DeeHandMade</h1>

    <h2>Forgot Password?</h2>

    <p>
        Enter the email address associated with your account.
        If an account exists, we will send you a password-reset link.
    </p>

    <?php if ($error !== ""): ?>
        <p class="error">
            <?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?>
        </p>
    <?php endif; ?>

    <?php if ($message !== ""): ?>
        <p class="success">
            <?php echo htmlspecialchars($message, ENT_QUOTES, "UTF-8"); ?>
        </p>
    <?php endif; ?>

    <form method="POST" action="forgot_password.php">

        <label for="email">Email Address</label>

        <input
            type="email"
            id="email"
            name="email"
            autocomplete="email"
            required
        >

        <button type="submit">
            Send Reset Link
        </button>

    </form>

    <p>
        Remembered your password?
        <a href="login.php">Back to Login</a>
    </p>

</div>

</body>

</html>
