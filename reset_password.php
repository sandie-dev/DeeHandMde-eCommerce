
<?php

session_start();

require_once __DIR__ . "/includes/database.php";

$error = "";
$success = "";
$token = trim($_GET["token"] ?? $_POST["token"] ?? "");

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {

        $error = "This password reset link is invalid.";

    } elseif (
        !preg_match(
            '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^a-zA-Z\d]).{6,}$/',
            $password
        )
    ) {

        $error = "Password must contain at least 6 characters, "
               . "an uppercase letter, a lowercase letter, "
               . "a number and a special character.";

    } elseif ($password !== $confirmPassword) {

        $error = "The passwords do not match.";

    } else {

        try {

            // Find unused, unexpired reset records.
            $stmt = $pdo->prepare(
                "SELECT id, user_id, token_hash
                 FROM password_resets
                 WHERE used_at IS NULL
                 AND expires_at > NOW()
                 ORDER BY id DESC"
            );

            $stmt->execute();
            $resetRecord = null;

            while ($record = $stmt->fetch(PDO::FETCH_ASSOC)) {

                if (password_verify($token, $record["token_hash"])) {
                    $resetRecord = $record;
                    break;
                }
            }

            if (!$resetRecord) {

                $error = "This reset link has expired or has already been used. "
                       . "Please request a new one.";

            } else {

                $pdo->beginTransaction();

                // Lock the reset record and verify it again
                // to prevent reuse during simultaneous requests.
                $stmt = $pdo->prepare(
                    "SELECT id
                     FROM password_resets
                     WHERE id = ?
                     AND used_at IS NULL
                     AND expires_at > NOW()
                     FOR UPDATE"
                );

                $stmt->execute([$resetRecord["id"]]);

                if (!$stmt->fetch()) {

                    $pdo->rollBack();

                    $error = "This reset link has expired or has already been used. "
                           . "Please request a new one.";

                } else {

                    $newPasswordHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $stmt = $pdo->prepare(
                        "UPDATE users SET password = ? WHERE id = ?"
                    );

                    $stmt->execute([
                        $newPasswordHash,
                        $resetRecord["user_id"]
                    ]);

                    $stmt = $pdo->prepare(
                        "UPDATE password_resets
                         SET used_at = NOW()
                         WHERE id = ?"
                    );

                    $stmt->execute([$resetRecord["id"]]);

                    // Invalidate every other outstanding reset link
                    // for this user.
                    $stmt = $pdo->prepare(
                        "UPDATE password_resets
                         SET used_at = NOW()
                         WHERE user_id = ?
                         AND used_at IS NULL"
                    );

                    $stmt->execute([$resetRecord["user_id"]]);

                    $pdo->commit();

                    $success = "Your password has been changed successfully. "
                             . "You can now log in using your new password.";
                }
            }

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Password reset failed: " . $e->getMessage());

            $error = "Something went wrong. Please request a new reset link "
                   . "or try again later.";
        }
    }
}

// Do not keep displaying the form after a successful reset.
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reset Password - DeeHandMade</title>

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loginStyle.css">
</head>

<body>

<div class="auth-container">

    <h1>DeeHandMade</h1>

    <h2>Reset Password</h2>

    <?php if ($error !== ""): ?>

        <p class="error">
            <?php echo htmlspecialchars($error, ENT_QUOTES, "UTF-8"); ?>
        </p>

        <p>
            <a href="forgot_password.php">Request a new reset link</a>
        </p>

    <?php elseif ($success !== ""): ?>

        <p class="success">
            <?php echo htmlspecialchars($success, ENT_QUOTES, "UTF-8"); ?>
        </p>

        <p>
            <a href="login.php">Go to Login</a>
        </p>

    <?php else: ?>

        <p>
            Enter your new password below.
        </p>

        <form method="POST" action="reset_password.php">

            <input
                type="hidden"
                name="token"
                value="<?php echo htmlspecialchars($token, ENT_QUOTES, "UTF-8"); ?>"
            >

            <label for="password">New Password</label>

            <input
                type="password"
                id="password"
                name="password"
                autocomplete="new-password"
                required
            >

            <label for="confirm_password">Confirm New Password</label>

            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                autocomplete="new-password"
                required
            >

            <p>
                Your password must contain at least 6 characters,
                including uppercase and lowercase letters, a number,
                and a special character.
            </p>

            <button type="submit">Reset Password</button>

        </form>

    <?php endif; ?>

</div>

</body>

</html>
