<?php
require_once "includes/authentication.php";
require_once "includes/database.php";

$orderId = filter_var(
    $_SESSION["pending_order_id"] ?? null,
    FILTER_VALIDATE_INT
);

$order = null;

if (isset($_SESSION["user_id"]) && $orderId) {
    $stmt = $pdo->prepare(
        "SELECT id, total, status, payment_reference
         FROM orders
         WHERE id = ? AND user_id = ?
         LIMIT 1"
    );

    $stmt->execute([$orderId, $_SESSION["user_id"]]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
}

$paid = $order && $order["status"] === "Paid";

if ($paid) {
    // Empty the cart only after confirmed payment.
    $_SESSION["cart"] = [];
    unset($_SESSION["pending_order_id"]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Result | DeeHandMade</title>
</head>
<body style="font-family:Arial,sans-serif;max-width:700px;margin:60px auto;padding:20px;">
    <?php if ($paid): ?>
        <h1>Thank you for your order!</h1>
        <p>Your PayFast Sandbox payment has been confirmed.</p>
        <p>Order number: <strong>
            <?php echo (int)$order["id"]; ?>
        </strong></p>
        <p>Total paid: <strong>
            R<?php echo number_format((float)$order["total"], 2); ?>
        </strong></p>
        <p>Payment reference:
            <?php echo htmlspecialchars(
                $order["payment_reference"] ?? ""
            ); ?>
        </p>
        <p><a href="main.php">Continue shopping</a></p>
    <?php elseif ($order): ?>
        <h1>Payment is being confirmed</h1>
        <p>
            We have received your return from PayFast, but the server
            has not yet confirmed the payment. Please wait a moment
            and check your order status before trying to pay again.
        </p>
        <p>Order number: <?php echo (int)$order["id"]; ?></p>
        <p><a href="main.php">Return to the shop</a></p>
    <?php else: ?>
        <h1>Payment result</h1>
        <p>
            We could not retrieve your order from this session.
            Please log in and check your order history.
        </p>
        <p><a href="login.php">Login</a></p>
    <?php endif; ?>
</body>
</html>