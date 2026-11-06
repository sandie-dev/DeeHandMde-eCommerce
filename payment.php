<?php
require_once "includes/authentication.php";
require_once "includes/database.php";
require_once "payfast_config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php?redirect=checkout");
    exit;
}

$orderId = filter_var(
    $_SESSION["pending_order_id"] ?? null,
    FILTER_VALIDATE_INT
);

if (!$orderId) {
    header("Location: cart.php");
    exit;
}

$stmt = $pdo->prepare(
    "SELECT o.*, u.email
     FROM orders o
     JOIN users u ON u.id = o.user_id
     WHERE o.id = ? AND o.user_id = ?
     LIMIT 1"
);
$stmt->execute([$orderId, $_SESSION["user_id"]]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order || $order["status"] !== "Pending") {
    header("Location: cart.php");
    exit;
}

if ((float)$order["total"] < 5.00) {
    exit("The PayFast minimum transaction amount is R5.00.");
}

$nameParts = preg_split(
    '/\s+/',
    trim($order["delivery_name"]),
    2
);

$firstName = $nameParts[0] ?? "Customer";
$lastName = $nameParts[1] ?? "";

$data = [
    "merchant_id" => PAYFAST_MERCHANT_ID,
    "merchant_key" => PAYFAST_MERCHANT_KEY,
    "return_url" => payfastUrl("paymentSuccess.php"),
    "cancel_url" => payfastUrl("paymentCancel.php"),
    "notify_url" => payfastUrl("paymentNotify.php"),
    "name_first" => $firstName,
    "name_last" => $lastName,
    "email_address" => $order["email"],
    "m_payment_id" => (string)$order["id"],
    "amount" => number_format((float)$order["total"], 2, ".", ""),
    "item_name" => "DeeHandMade Order #" . $order["id"]
];

$data["signature"] = payfastSignature($data);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pay for Your Order | DeeHandMade</title>
</head>
<body style="font-family:Arial,sans-serif;max-width:650px;margin:60px auto;padding:20px;">
    <h1>Continue to PayFast Sandbox</h1>

    <p>Order number: <strong>
        <?php echo (int)$order["id"]; ?>
    </strong></p>

    <p>Amount:
        <strong>R<?php echo number_format((float)$order["total"], 2); ?></strong>
    </p>

    <p>You will be redirected to PayFast's sandbox to complete a test payment.</p>

    <form action="<?php echo htmlspecialchars(PAYFAST_PROCESS_URL); ?>"
          method="POST">

        <?php foreach ($data as $key => $value): ?>
            <input type="hidden"
                   name="<?php echo htmlspecialchars($key); ?>"
                   value="<?php echo htmlspecialchars($value); ?>">
        <?php endforeach; ?>

        <button type="submit"
                style="padding:14px 20px;background:#6b3f2a;color:white;border:0;cursor:pointer;">
            Pay R<?php echo number_format((float)$order["total"], 2); ?>
        </button>
    </form>

    <p><a href="cart.php">Return to cart</a></p>
</body>
</html>