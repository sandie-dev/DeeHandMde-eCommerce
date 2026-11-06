
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

function sendRegistrationCode($email, $name, $code)
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
        $mail->Subject = "Verify your DeeHandMade account";

        $mail->Body = "
            <h2>Welcome to DeeHandMade, {$safeName}!</h2>
            <p>Use the verification code below to complete
            your registration:</p>
            <h1 style='letter-spacing: 5px;'>{$code}</h1>
            <p>This code expires in 10 minutes.</p>
            <p>If you did not request this, you can ignore
            this email.</p>
        ";

        $mail->AltBody =
            "Your DeeHandMade verification code is {$code}. "
            . "It expires in 10 minutes.";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Registration email failed: " . $mail->ErrorInfo);
        return false;
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submittedToken = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION["csrf_token"], $submittedToken)
    ) {
        $error = "Your session has expired. Please try again.";

    } else {

        $name = trim($_POST["name"] ?? "");
        $email = trim($_POST["email"] ?? "");
        $password = $_POST["password"] ?? "";
        $confirmPassword = $_POST["confirm_password"] ?? "";

        if (
            !is_string($name) ||
            !is_string($email) ||
            !is_string($password) ||
            !is_string($confirmPassword)
        ) {
            $error = "Please enter valid registration details.";

        } elseif (
            $name === "" ||
            $email === "" ||
            $password === "" ||
            $confirmPassword === ""
        ) {
            $error = "Please fill in all fields.";

        } elseif (strlen($name) > 100) {
            $error = "Your name must not exceed 100 characters.";

        } elseif (
            strlen($email) > 150 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            $error = "Please enter a valid email address.";

        } elseif (
            !preg_match(
                '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).{6,}$/',
                $password
            )
        ) {
            $error = "Your password must contain at least 6 characters, "
                   . "an uppercase letter, a lowercase letter, "
                   . "a number and a special character.";

        } elseif ($password !== $confirmPassword) {
            $error = "Your passwords do not match.";

        } else {

            try {
                $check = $pdo->prepare(
                    "SELECT id FROM users WHERE email = ? LIMIT 1"
                );
                $check->execute([$email]);

                if ($check->fetch()) {
                    $error = "An account with this email already exists.";

                } else {

                    $code = (string) random_int(100000, 999999);

                    $pendingRegistration = [
                        "name" => $name,
                        "email" => $email,
                        "password_hash" => password_hash(
                            $password,
                            PASSWORD_DEFAULT
                        ),
                        "code_hash" => password_hash(
                            $code,
                            PASSWORD_DEFAULT
                        ),
                        "expires_at" => time() + VERIFICATION_CODE_EXPIRY,
                        "attempts" => 0,
                        "resends" => 0,
                        "last_sent_at" => time()
                    ];

                    if (sendRegistrationCode($email, $name, $code)) {

                        $_SESSION["pending_registration"] =
                            $pendingRegistration;

                        header("Location: verify_email.php");
                        exit;

                    } else {
                        $error = "We could not send your verification email. "
                               . "Please check your email configuration "
                               . "and try again.";
                    }
                }

            } catch (PDOException $e) {
                error_log("Registration error: " . $e->getMessage());
                $error = "Registration could not be started. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | DeeHandMade</title>

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/logInstyle.css">
</head>

<body>

<div class="auth-container">

    <h2>Create Your DeeHandMade Account</h2>

    <p>Register to shop our handmade products.</p>

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

    <form method="POST" action="registration.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>"
        >

        <label for="name">Full Name</label>
        <input
            type="text"
            id="name"
            name="name"
            maxlength="100"
            required
            autocomplete="name"
            value="<?= htmlspecialchars($name ?? "", ENT_QUOTES, "UTF-8") ?>"
        >

        <label for="email">Email Address</label>
        <input
            type="email"
            id="email"
            name="email"
            maxlength="150"
            required
            autocomplete="email"
            value="<?= htmlspecialchars($email ?? "", ENT_QUOTES, "UTF-8") ?>"
        >

        <label for="password">Password</label>
        <input
            type="password"
            id="password"
            name="password"
            minlength="6"
            required
            autocomplete="new-password"
        >

        <small>
            At least 6 characters, including uppercase and lowercase
            letters, a number and a special character.
        </small>

        <label for="confirm_password">Confirm Password</label>
        <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            minlength="6"
            required
            autocomplete="new-password"
        >

        <button type="submit">Send Verification Code</button>

    </form>

    <p>
        Already have an account?
        <a href="login.php">Login here</a>
    </p>

</div>

</body>
</html>
