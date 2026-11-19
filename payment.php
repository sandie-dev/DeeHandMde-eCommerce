
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
    header("Location: cart.php");
    exit;
}

// Generate a token for the Paystack form.
if (empty($_SESSION["payment_token"])) {
    $_SESSION["payment_token"] = bin2hex(random_bytes(32));
}

$paymentToken = $_SESSION["payment_token"];

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
    "amount" => number_format(
        (float)$order["total"],
        2,
        ".",
        ""
    ),
    "item_name" => "DeeHandMade Order #" . $order["id"]
];

$data["signature"] = payfastSignature($data);
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Choose Payment Method | DeeHandMade</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 750px;
            margin: 50px auto;
            padding: 20px;
            background: #faf7f4;
            color: #333;
        }

        .payment-box {
            background: white;
            padding: 25px;
            margin: 20px 0;
            border-radius: 10px;
            border: 1px solid #ddd;
        }

        button {
            padding: 14px 20px;
            border: none;
            color: white;
            cursor: pointer;
            border-radius: 5px;
            font-size: 15px;
            width: 100%;
        }

        .payfast {
            background: #6b3f2a;
        }

        .paystack {
            background: #087c64;
        }

        button:hover {
            opacity: 0.9;
        }

        a {
            color: #6b3f2a;
        }

        .amount {
            font-size: 22px;
            font-weight: bold;
        }
    </style>
</head>

<body>

    <h1>Choose Your Payment Method</h1>

    <p>Thank you for shopping with DeeHandMade!</p>

    <div class="payment-box">

        <p>Order number:
            <strong><?php echo (int)$order["id"]; ?></strong>
        </p>

        <p>Total amount:</p>

        <p class="amount">
            R<?php echo number_format(
                (float)$order["total"],
                2
            ); ?>
        </p>

    </div>

    <div class="payment-box">

        <h2>PayFast Sandbox</h2>

        <p>
            Continue to PayFast to complete your test payment.
        </p>

        <?php if ((float)$order["total"] >= 5.00): ?>

            <form
                action="<?php echo htmlspecialchars(PAYFAST_PROCESS_URL); ?>"
                method="POST"
            >

                <?php foreach ($data as $key => $value): ?>

                    <input
                        type="hidden"
                        name="<?php echo htmlspecialchars($key); ?>"
                        value="<?php echo htmlspecialchars($value); ?>"
                    >

                <?php endforeach; ?>

                <button type="submit" class="payfast">
                    Continue with PayFast
                </button>

            </form>

        <?php else: ?>

            <p>PayFast requires a minimum payment of R5.00.</p>

        <?php endif; ?>

    </div>

    <div class="payment-box">

        <h2>Paystack Test Payment</h2>

        <p>
            Use Paystack's test environment to simulate
            a card payment.
        </p>

        <form action="paystackInitialize.php" method="POST">

            <input
                type="hidden"
                name="payment_token"
                value="<?php echo htmlspecialchars($paymentToken); ?>"
            >

            <button type="submit" class="paystack">
                Continue with Paystack
            </button>

        </form>

        <p>
            <small>
                Test mode only. No real money will be charged.
            </small>
        </p>

    </div>

    <p><a href="cart.php">Return to cart</a></p>

</body>
</html>
