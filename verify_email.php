
<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . "/includes/database.php";
require_once __DIR__ . "/includes/mail_config.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . "/PHPMailer/Exception.php";
require_once __DIR__ . "/PHPMailer/PHPMailer.php";
require_once __DIR__ . "/PHPMailer/SMTP.php";

$error = "";
$success = "";

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function sendVerificationEmail($email, $name, $code)
{
    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = MAIL_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = MAIL_USERNAME;
        $mail->Password   = MAIL_PASSWORD;
        $mail->Port       = MAIL_PORT;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = "UTF-8";

        $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
        $mail->addAddress($email, $name);

        $safeName = htmlspecialchars(
            $name,
            ENT_QUOTES,
            "UTF-8"
        );

        $mail->isHTML(true);
        $mail->Subject = "Your new DeeHandMade verification code";

        $mail->Body = "
            <h2>Hello, {$safeName}!</h2>
            <p>Your new DeeHandMade verification code is:</p>
            <h1 style='letter-spacing: 5px;'>{$code}</h1>
            <p>This code expires in 10 minutes.</p>
        ";

        $mail->AltBody =
            "Your DeeHandMade verification code is {$code}. "
            . "It expires in 10 minutes.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Verification email failed: " . $mail->ErrorInfo);
        return false;
    }
}

if (!isset($_SESSION["pending_registration"])) {
    $error = "There is no pending registration. Please register first.";
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submittedToken = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION["csrf_token"], $submittedToken)
    ) {
        $error = "Your session has expired. Please refresh the page.";

    } elseif (!isset($_SESSION["pending_registration"])) {
        $error = "Your registration session has expired. Please register again.";

    } else {

        $action = $_POST["action"] ?? "";
        $pending = &$_SESSION["pending_registration"];

        if ($action === "verify") {

            if ($pending["attempts"] >= VERIFICATION_MAX_ATTEMPTS) {
                unset($_SESSION["pending_registration"]);
                $error = "Too many incorrect attempts. Please register again.";

            } elseif (time() > $pending["expires_at"]) {
                $error = "Your code has expired. You can request a new code below.";

            } else {

                $code = trim($_POST["verification_code"] ?? "");

                $pending["attempts"]++;

                if (
                    !is_string($code) ||
                    !preg_match('/^\d{6}$/', $code) ||
                    !password_verify($code, $pending["code_hash"])
                ) {
                    $remaining = VERIFICATION_MAX_ATTEMPTS
                               - $pending["attempts"];

                    if ($remaining <= 0) {
                        unset($_SESSION["pending_registration"]);
                        $error = "Too many incorrect attempts. Please register again.";
                    } else {
                        $error = "Incorrect code. You have "
                               . $remaining
                               . " attempt(s) remaining.";
                    }

                } else {

                    try {
                        // Check again in case the email was registered
                        // after the first registration form was submitted.
                        $check = $pdo->prepare(
                            "SELECT id FROM users WHERE email = ? LIMIT 1"
                        );
                        $check->execute([$pending["email"]]);

                        if ($check->fetch()) {
                            unset($_SESSION["pending_registration"]);
                            $error = "An account with this email already exists.";
                        } else {
                            $insert = $pdo->prepare(
                                "INSERT INTO users (name, email, password, role)
                                 VALUES (?, ?, ?, 'customer')"
                            );

                            $insert->execute([
                                $pending["name"],
                                $pending["email"],
                                $pending["password_hash"]
                            ]);

                            unset($_SESSION["pending_registration"]);

                            // Rotate the CSRF token after registration.
                            $_SESSION["csrf_token"] =
                                bin2hex(random_bytes(32));

                            $success = "Your email has been verified and "
                                     . "your DeeHandMade account has been created. "
                                     . "You can now log in.";
                        }
                    } catch (PDOException $e) {
                        error_log(
                            "Verified registration error: " . $e->getMessage()
                        );
                        $error = "We could not create your account. "
                               . "Please try again.";
                    }
                }
            }

        } elseif ($action === "resend") {

            if ($pending["resends"] >= VERIFICATION_MAX_RESENDS) {
                $error = "You have reached the maximum number of resend requests. "
                       . "Please start registration again.";

            } elseif (
                time() - $pending["last_sent_at"]
                < VERIFICATION_RESEND_COOLDOWN
            ) {
                $wait = VERIFICATION_RESEND_COOLDOWN
                      - (time() - $pending["last_sent_at"]);

                $error = "Please wait " . $wait
                       . " second(s) before requesting another code.";

            } else {

                $code = (string) random_int(100000, 999999);

                if (
                    sendVerificationEmail(
                        $pending["email"],
                        $pending["name"],
                        $code
                    )
                ) {
                    $pending["code_hash"] = password_hash(
                        $code,
                        PASSWORD_DEFAULT
                    );
                    $pending["expires_at"] =
                        time() + VERIFICATION_CODE_EXPIRY;
                    $pending["attempts"] = 0;
                    $pending["resends"]++;
                    $pending["last_sent_at"] = time();

                    $success = "A new verification code has been sent to "
                             . $pending["email"] . ".";
                } else {
                    $error = "We could not send a new code. "
                           . "Please try again later.";
                }
            }

        } else {
            $error = "Invalid request. Please try again.";
        }
    }
}

$hasPendingRegistration =
    isset($_SESSION["pending_registration"]);

$pendingEmail = $hasPendingRegistration
    ? $_SESSION["pending_registration"]["email"]
    : "";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Email | DeeHandMade</title>

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/logInstyle.css">
</head>

<body>

<div class="auth-container">

    <h2>Verify Your Email</h2>

    <?php if ($error !== ""): ?>
        <p class="error">
            <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
        </p>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <p class="success">
            <?= htmlspecialchars($success, ENT_QUOTES, "UTF-8") ?>
        </p>
    <?php endif; ?>

    <?php if ($hasPendingRegistration): ?>

        <p>
            We sent a six-digit verification code to:
        </p>

        <p>
            <strong>
                <?= htmlspecialchars($pendingEmail, ENT_QUOTES, "UTF-8") ?>
            </strong>
        </p>

        <p>The code expires after 10 minutes.</p>

        <form method="POST" action="verify_email.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>"
            >

            <input type="hidden" name="action" value="verify">

            <label for="verification_code">Verification Code</label>

            <input
                type="text"
                id="verification_code"
                name="verification_code"
                inputmode="numeric"
                pattern="[0-9]{6}"
                maxlength="6"
                minlength="6"
                autocomplete="one-time-code"
                placeholder="Enter your 6-digit code"
                required
            >

            <button type="submit">Verify Email</button>

        </form>

        <hr>

        <form method="POST" action="verify_email.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>"
            >

            <input type="hidden" name="action" value="resend">

            <button type="submit">Resend Verification Code</button>

        </form>

        <p>
            <a href="register.php">Start registration again</a>
        </p>

    <?php elseif ($success !== ""): ?>

        <p>
            <a href="login.php">Go to Login</a>
        </p>

    <?php else: ?>

        <p>
            Please start a new registration to receive a verification code.
        </p>

        <p>
            <a href="register.php">Return to Registration</a>
        </p>

    <?php endif; ?>

</div>

</body>
</html>
