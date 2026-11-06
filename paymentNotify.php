<?php
require_once "includes/database.php";
require_once "payfast_config.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST" || empty($_POST)) {
    http_response_code(400);
    exit("Invalid notification");
}

$pfData = $_POST;
$receivedSignature = $pfData["signature"] ?? "";

if (!is_string($receivedSignature) || $receivedSignature === "") {
    http_response_code(400);
    exit("Missing signature");
}

// Re-create the signature from the submitted fields,
// preserving the incoming field order.
$expectedSignature = payfastItnSignature($pfData);

if (!hash_equals($expectedSignature, $receivedSignature)) {
    http_response_code(400);
    exit("Invalid signature");
}

$merchantId = (string)($pfData["merchant_id"] ?? "");
$orderId = filter_var(
    $pfData["m_payment_id"] ?? null,
    FILTER_VALIDATE_INT
);
$paymentStatus = (string)($pfData["payment_status"] ?? "");
$paymentId = trim((string)($pfData["pf_payment_id"] ?? ""));
$amountGross = filter_var(
    $pfData["amount_gross"] ?? null,
    FILTER_VALIDATE_FLOAT
);

if (
    $merchantId !== PAYFAST_MERCHANT_ID ||
    !$orderId ||
    $paymentId === "" ||
    $amountGross === false
) {
    http_response_code(400);
    exit("Invalid payment details");
}

// Confirm the notification with PayFast's validation endpoint.
$validationUrl = "https://sandbox.payfast.co.za/eng/query/validate";

// The validation request contains the posted parameters except signature.
$signatureData = $pfData;
unset($signatureData["signature"]);

$validationParts = [];

foreach ($signatureData as $key => $value) {
    if (!is_scalar($value)) {
        http_response_code(400);
        exit("Invalid parameter");
    }

    $validationParts[] = $key . "=" . urlencode((string)$value);
}

$validationBody = implode("&", $validationParts);

$ch = curl_init($validationUrl);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $validationBody,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_HTTPHEADER => [
        "Content-Type: application/x-www-form-urlencoded"
    ]
]);

$validationResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if (
    $validationResponse === false ||
    $httpCode !== 200 ||
    trim($validationResponse) !== "VALID"
) {
    error_log(
        "PayFast ITN validation failed. HTTP: " .
        $httpCode . "; Error: " . $curlError
    );

    http_response_code(400);
    exit("Notification validation failed");
}


try {
    $pdo->beginTransaction();

    // Lock the order while processing its payment.
    $stmt = $pdo->prepare(
        "SELECT id, total, status, stock_deducted
         FROM orders
         WHERE id = ?
         FOR UPDATE"
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new RuntimeException("Order not found: " . $orderId);
    }

    // Verify the payment amount.
    if (abs((float)$order["total"] - (float)$amountGross) > 0.01) {
        throw new RuntimeException("Payment amount mismatch for order " . $orderId);
    }

    // A completed order with stock already deducted is a duplicate ITN.
    if ($order["status"] === "Paid" && (int)$order["stock_deducted"] === 1) {
        $pdo->commit();
        http_response_code(200);
        exit("Already processed");
    }

    // Do not automatically process older paid orders with an unset flag.
    if ($order["status"] === "Paid") {
        error_log("Paid order " . $orderId . " requires stock review.");
        $pdo->rollBack();
        http_response_code(200);
        exit("Paid order requires stock review");
    }

    // Handle cancellation only while the order is pending.
    if ($paymentStatus === "CANCELLED") {
        $stmt = $pdo->prepare(
            "UPDATE orders
             SET status = 'Cancelled',
                 payment_reference = ?
             WHERE id = ? AND status = 'Pending'"
        );
        $stmt->execute([$paymentId, $orderId]);

        $pdo->commit();
        http_response_code(200);
        exit("OK");
    }

    // Only a completed payment can deduct stock.
    if ($paymentStatus !== "COMPLETE") {
        $pdo->commit();
        http_response_code(200);
        exit("No completed payment to process");
    }

    if ($order["status"] !== "Pending" || (int)$order["stock_deducted"] !== 0) {
        throw new RuntimeException("Order is not eligible for processing.");
    }

    // Aggregate quantities in case a product appears more than once.
    $stmt = $pdo->prepare(
        "SELECT product_id, SUM(quantity) AS quantity
         FROM order_items
         WHERE order_id = ?
         GROUP BY product_id"
    );
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$items) {
        throw new RuntimeException("No items found for order " . $orderId);
    }

    // Lock and validate every product before deducting any stock.
    foreach ($items as $item) {
        $productId = (int)$item["product_id"];
        $quantity = (int)$item["quantity"];

        if ($quantity < 1) {
            throw new RuntimeException("Invalid quantity for product " . $productId);
        }

        $stmt = $pdo->prepare(
            "SELECT stock
             FROM products
             WHERE id = ?
             FOR UPDATE"
        );
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$product) {
            throw new RuntimeException("Product not found: " . $productId);
        }

        if ((int)$product["stock"] < $quantity) {
            throw new RuntimeException(
                "Insufficient stock for product " . $productId .
                " in order " . $orderId
            );
        }
    }

    // Deduct stock, guarding against negative quantities in stock.
    foreach ($items as $item) {
        $productId = (int)$item["product_id"];
        $quantity = (int)$item["quantity"];

        $stmt = $pdo->prepare(
            "UPDATE products
             SET stock = stock - ?
             WHERE id = ? AND stock >= ?"
        );
        $stmt->execute([$quantity, $productId, $quantity]);

        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException("Stock deduction failed for product " . $productId);
        }
    }

    // Mark payment and stock processing together.
    $stmt = $pdo->prepare(
        "UPDATE orders
         SET status = 'Paid',
             payment_reference = ?,
             stock_deducted = 1
         WHERE id = ?
           AND status = 'Pending'
           AND stock_deducted = 0"
    );
    $stmt->execute([$paymentId, $orderId]);

    if ($stmt->rowCount() !== 1) {
        throw new RuntimeException("Could not mark order as paid: " . $orderId);
    }

    $pdo->commit();

    http_response_code(200);
    echo "OK";

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("PayFast notification error: " . $e->getMessage());
    http_response_code(500);
    echo "Processing error";
}
