
<?php

session_start();

require_once "includes/database.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($email === "" || $password === "") {

        $error = "Please enter your email and password.";

    } else {

        $stmt = $pdo->prepare(
            "SELECT * FROM users WHERE email = ?"
        );

        $stmt->execute([$email]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user["password"])) {

            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["name"] = $user["name"];
            $_SESSION["email"] = $user["email"];
            $_SESSION["role"] = $user["role"];

            if ($user["role"] === "admin") {

                header("Location: admin.php");
                exit;

            } else {

                header("Location: main.php");
                exit;
            }

        } else {

            $error = "Invalid email or password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Login - DeeHandMade</title>

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/loginStyle.css">

</head>

<body>

<div class="auth-container">

    <h1>DeeHandMade</h1>

    <h2>Login</h2>

    <?php if ($error): ?>

        <p class="error">
            <?php echo htmlspecialchars($error); ?>
        </p>

    <?php endif; ?>

    <form method="POST" action="login.php">

        <label for="email">Email</label>

        <input
            type="email"
            id="email"
            name="email"
            autocomplete="email"
            required
        >

        <label for="password">Password</label>

        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit">
            Login
        </button>
		 <p class="forgot-password">
            Forgot password?
            <a href="forgot_password.php">Click here</a>
        </p>

    </form>

    <p>
        Don't have an account?
        <a href="registration.php">Create an account</a>
    </p>

</div>

</body>

</html>
