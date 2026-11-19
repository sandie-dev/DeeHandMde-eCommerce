
<?php

require_once "includes/authentication.php";
require_once "includes/database.php";
require_once "paystack_config.php";

function paymentMessage(string $message): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Payment Result | DeeHandMade</title>
    </head>
    <body style="font-family:Arial,sans-serif;max-width:650px;margin:60px auto;padding:20px;">

        <h1>DeeHandMade Payment</h1>

        <p><?php echo htmlspecialchars($message); ?></p>

        <p><a href="profile.php">View My Orders</a></p>
        <p><a href="main.php">Return to Shop</a></p>

    </body>
    </html>
    <?php

    exit;
}

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php?redirect=checkout");
    exit;
}

$reference = $_GET["reference"] ?? "";

if (
    !is_string($reference) ||
    !preg_match('/^DHM-[0-9]+-[a-f0-9]{16}$/', $reference)
) {
    paymentMessage("Invalid payment reference.");
}

$orderId = filter_var(
    $_SESSION["pending_order_id"] ?? null,
    FILTER_VALIDATE_INT
);

if (!$orderId) {
    paymentMessage("Your pending order could not be found.");
}

// Find the exact order and payment reference.
$stmt = $pdo->prepare(
    "SELECT *
     FROM orders
     WHERE id = ?
       AND user_id = ?
       AND payment_method = 'Paystack'
       AND payment_reference = ?
     LIMIT 1"
);

$stmt->execute([
    $orderId,
    $_SESSION["user_id"],
    $reference
]);

$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    paymentMessage(
        "The payment reference does not match your order."
    );
}

// Already processed? Do not deduct stock again.
if (
    $order["payment_status"] === "Paid" &&
    (int)$order["stock_deducted"] === 1
) {
    unset($_SESSION["cart"]);
    unset($_SESSION["pending_order_id"]);
    unset($_SESSION["payment_token"]);

    paymentMessage("Your payment has already been confirmed. Thank you!");
}

if (
    $order["payment_status"] !== "Pending" ||
    (int)$order["stock_deducted"] !== 0
) {
    paymentMessage("This order is not awaiting payment.");
}

// Verify transaction directly with Paystack.
$ch = curl_init(
    PAYSTACK_VERIFY_URL . rawurlencode($reference)
);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPGET => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
        "Cache-Control: no-cache"
    ],
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

curl_close($ch);

if ($response === false || $httpCode < 200 || $httpCode >= 300) {

    error_log(
        "Paystack verification error: " .
        $httpCode . " " . $curlError
    );

    paymentMessage(
        "We could not verify your payment yet. " .
        "Your order has not been marked as paid. " .
        "Please contact the store administrator before trying again."
    );
}

$result = json_decode($response, true);

if (
    !is_array($result) ||
    empty($result["status"]) ||
    !isset($result["data"])
) {
    paymentMessage("Paystack returned an invalid verification response.");
}

$transaction = $result["data"];

$expectedAmount = (int)round(
    (float)$order["total"] * 100
);

// Check reference, amount, currency and successful status.
if (
    ($transaction["reference"] ?? "") !== $reference ||
    ($transaction["status"] ?? "") !== "success" ||
    ($transaction["currency"] ?? "") !== PAYSTACK_CURRENCY ||
    (int)($transaction["amount"] ?? 0) !== $expectedAmount
) {
    paymentMessage(
        "Payment was not successful or the transaction details did not match."
    );
}

try {

    $pdo->beginTransaction();

    // Lock the order so that the payment cannot be processed twice.
    $lockOrder = $pdo->prepare(
        "SELECT *
         FROM orders
         WHERE id = ?
           AND user_id = ?
         FOR UPDATE"
    );

    $lockOrder->execute([
        $orderId,
        $_SESSION["user_id"]
    ]);

    $lockedOrder = $lockOrder->fetch(PDO::FETCH_ASSOC);

    if (
        !$lockedOrder ||
        $lockedOrder["payment_method"] !== "Paystack" ||
        $lockedOrder["payment_reference"] !== $reference
    ) {
        throw new RuntimeException("Order verification failed.");
    }

    if (
        $lockedOrder["payment_status"] === "Paid" &&
        (int)$lockedOrder["stock_deducted"] === 1
    ) {
        $pdo->commit();

        unset($_SESSION["cart"]);
        unset($_SESSION["pending_order_id"]);
        unset($_SESSION["payment_token"]);

        paymentMessage("Your payment has already been confirmed.");
    }

    if (
        $lockedOrder["payment_status"] !== "Pending" ||
        (int)$lockedOrder["stock_deducted"] !== 0
    ) {
        throw new RuntimeException(
            "The order is no longer awaiting payment."
        );
    }

    // Retrieve order items.
    $itemStmt = $pdo->prepare(
        "SELECT product_id, SUM(quantity) AS quantity
         FROM order_items
         WHERE order_id = ?
         GROUP BY product_id"
    );

    $itemStmt->execute([$orderId]);

    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new RuntimeException("No order items were found.");
    }

    // Lock and check product stock before deducting.
    foreach ($items as $item) {

        $productStmt = $pdo->prepare(
            "SELECT id, stock
             FROM products
             WHERE id = ?
             FOR UPDATE"
        );

        $productStmt->execute([
            $item["product_id"]
        ]);

        $product = $productStmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$product ||
            (int)$product["stock"] < (int)$item["quantity"]
        ) {
            throw new RuntimeException(
                "There is insufficient stock to fulfil this paid order. " .
                "Please contact the administrator."
            );
        }
    }

    // Deduct stock.
    $deductStmt = $pdo->prepare(
        "UPDATE products
         SET stock = stock - ?
         WHERE id = ? AND stock >= ?"
    );

    foreach ($items as $item) {

        $quantity = (int)$item["quantity"];

        $deductStmt->execute([
            $quantity,
            $item["product_id"],
            $quantity
        ]);

        if ($deductStmt->rowCount() !== 1) {
            throw new RuntimeException(
                "Stock could not be updated."
            );
        }
    }

    // Confirm payment and mark order as processing.
    $updateOrder = $pdo->prepare(
        "UPDATE orders
         SET payment_status = 'Paid',
             delivery_status = 'Processing',
             status = 'Paid',
             stock_deducted = 1
         WHERE id = ?
           AND payment_status = 'Pending'
           AND stock_deducted = 0"
    );

    $updateOrder->execute([$orderId]);

    if ($updateOrder->rowCount() !== 1) {
        throw new RuntimeException(
            "The order payment status could not be updated."
        );
    }

    $pdo->commit();

    unset($_SESSION["cart"]);
    unset($_SESSION["pending_order_id"]);
    unset($_SESSION["payment_token"]);

    paymentMessage(
        "Payment successful! Your DeeHandMade order has been confirmed."
    );

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        "Paystack order processing error: " . $e->getMessage()
    );

    paymentMessage(
        "Your payment could not be fully processed by the store. " .
        "Please contact the administrator before making another payment."
    );
}
