<?php

require_once "includes/authentication.php";
require_once "includes/database.php";
require_once "paystack_config.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php?redirect=checkout");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: payment.php");
    exit;
}

$token = $_POST["payment_token"] ?? "";

if (
    !is_string($token) ||
    empty($_SESSION["payment_token"]) ||
    !hash_equals($_SESSION["payment_token"], $token)
) {
    exit("Invalid payment request. Please return and try again.");
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

$stmt->execute([
    $orderId,
    $_SESSION["user_id"]
]);

$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (
    !$order ||
    $order["payment_status"] !== "Pending" ||
    (int)$order["stock_deducted"] === 1
) {
    exit("This order cannot be paid.");
}

if (!filter_var($order["email"], FILTER_VALIDATE_EMAIL)) {
    exit("The customer email address is invalid.");
}

if (!function_exists("curl_init")) {
    exit("cURL is not enabled on this hosting account.");
}

// Paystack requires the amount in cents.
// Example: R100.00 = 10000.
$amountInCents = (int)round(
    (float)$order["total"] * 100
);

if ($amountInCents < 100) {
    exit("The minimum Paystack transaction is R1.00.");
}

// Create a unique reference for this payment attempt.
$reference = "DHM-" . $orderId . "-" . bin2hex(random_bytes(8));

// Store the chosen payment method and reference.
$update = $pdo->prepare(
    "UPDATE orders
     SET payment_method = 'Paystack',
         payment_reference = ?
     WHERE id = ?
       AND user_id = ?
       AND payment_status = 'Pending'
       AND stock_deducted = 0"
);

$update->execute([
    $reference,
    $orderId,
    $_SESSION["user_id"]
]);

if ($update->rowCount() !== 1) {
    exit("Unable to prepare this payment. Please try again.");
}

// Build the callback URL using the current website address.
// Only allow the known local and hosted project domains.
$host = strtolower($_SERVER["HTTP_HOST"] ?? "");

if ($host === "localhost" || $host === "127.0.0.1") {

    $callbackUrl =
        "http://" . $host . "/deeHandMade/paystackCallback.php";

} elseif ($host === "deehandmade.ct.ws") {

    $callbackUrl =
        "https://deehandmade.ct.ws/paystackCallback.php";

} else {

    exit("This website address is not configured for Paystack.");
}

$payload = [
    "email" => $order["email"],
    "amount" => $amountInCents,
    "currency" => PAYSTACK_CURRENCY,
    "reference" => $reference,
    "callback_url" => $callbackUrl,
    "channels" => ["card"],
    "metadata" => [
        "order_id" => (int)$order["id"],
        "customer_id" => (int)$order["user_id"],
        "store" => "DeeHandMade"
    ]
];

$ch = curl_init(PAYSTACK_INITIALIZE_URL);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer " . PAYSTACK_SECRET_KEY,
        "Content-Type: application/json",
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
        "Paystack initialization failed. HTTP: " .
        $httpCode . " Error: " . $curlError
    );

    exit(
        "We could not connect to Paystack. " .
        "Please try again later."
    );
}

$result = json_decode($response, true);

if (
    !is_array($result) ||
    empty($result["status"]) ||
    empty($result["data"]["authorization_url"])
) {

    error_log("Paystack response: " . $response);

    exit("Paystack could not initialize your payment.");
}

// Redirect the customer to Paystack's hosted checkout.
header(
    "Location: " . $result["data"]["authorization_url"]
);

exit;
