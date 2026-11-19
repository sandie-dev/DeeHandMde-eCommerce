<?php

require_once "includes/database.php";

$message = "";
$error = "";


   

function validate_password($password){
	
$pattern='/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_]).{8,}$/';

return(bool)preg_match($pattern, $password);
	
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"]);
    $email = trim($_POST["email"]);
    $password = $_POST["password"];
    $confirmPassword = $_POST["confirm_password"];

    if ($name === "" || $email === "" || $password === "") {

        $error = "Please fill in all required fields.";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $error = "Please enter a valid email address.";

    } elseif ($password !== $confirmPassword) {

        $error = "Passwords do not match.";

    } elseif (!validate_password($pasword)) {

        $error = "Password does not match conditions, it should at be not less than 6 characters contain A-Z, a-z,special character and 1-9 .";

    } else {

        // Check whether email already exists
        $check = $pdo->prepare(
            "SELECT id FROM users WHERE email = ?"
        );

        $check->execute([$email]);

        if ($check->fetch()) {

            $error = "An account with this email already exists.";

        } else {

            // Hash password
            $hashedPassword = password_hash(
                $password,
                PASSWORD_DEFAULT
            );

            // New registrations are customers
            $stmt = $pdo->prepare(
                "INSERT INTO users (name, email, password, role)
                 VALUES (?, ?, ?, 'customer')"
            );

            $stmt->execute([
                $name,
                $email,
                $hashedPassword
            ]);

            $message = "Registration successful! You can now log in.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Register - DeeHandMade</title>

    <link rel="stylesheet" href="css/style.css">
	<link rel="stylesheet" href="css/logInstyle.css">
</head>

<body>

<div class="auth-container">

    <h1>DeeHandMade</h1>

    <h2>Create Account</h2>

    <?php if ($error): ?>
        <p class="error">
            <?php echo htmlspecialchars($error); ?>
        </p>
    <?php endif; ?>

    <?php if ($message): ?>
        <p class="success">
            <?php echo htmlspecialchars($message); ?>
        </p>
    <?php endif; ?>

    <form method="POST" action="register.php">

        <label>Full Name</label>

        <input
            type="text"
            name="name"
            required
        >

        <label>Email</label>

        <input
            type="email"
            name="email"
            required
        >

        <label>Password</label>

        <input
            type="password"
            name="password"
            required
        >

        <label>Confirm Password</label>

        <input
            type="password"
            name="confirm_password"
            required
        >

        <button type="submit">
            Create Account
        </button>

    </form>

    <p>
        Already have an account?
        <a href="login.php">Login here</a>
    </p>

</div>

</body>
</html>