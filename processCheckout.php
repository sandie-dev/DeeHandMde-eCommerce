
<?php
require_once "includes/authentication.php";
require_once "includes/database.php";

function checkoutFail(string $message): void
{
    $_SESSION["checkout_error"] = $message;
    header("Location: checkout.php");
    exit;
}

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php?redirect=checkout");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: checkout.php");
    exit;
}

$token = $_POST["checkout_token"] ?? "";

if (
    !is_string($token) ||
    empty($_SESSION["checkout_token"]) ||
    !hash_equals($_SESSION["checkout_token"], $token)
) {
    checkoutFail("Your checkout session expired. Please try again.");
}

// Validate delivery confirmation on the server.
if (($_POST["delivery_confirm"] ?? "") !== "1") {
    checkoutFail(
        "Please confirm that your delivery details are correct."
    );
}

// Consume checkout token.
unset($_SESSION["checkout_token"]);

$deliveryName = trim($_POST["delivery_name"] ?? "");
$deliveryPhone = trim($_POST["delivery_phone"] ?? "");
$deliveryAddress = trim($_POST["delivery_address"] ?? "");
$deliveryCity = trim($_POST["delivery_city"] ?? "");
$deliveryPostalCode = trim($_POST["delivery_postal_code"] ?? "");

if (
    $deliveryName === "" ||
    $deliveryPhone === "" ||
    $deliveryAddress === "" ||
    $deliveryCity === "" ||
    $deliveryPostalCode === ""
) {
    checkoutFail("Please complete every delivery field.");
}

if (
    strlen($deliveryName) > 150 ||
    strlen($deliveryPhone) > 30 ||
    strlen($deliveryAddress) > 255 ||
    strlen($deliveryCity) > 100 ||
    strlen($deliveryPostalCode) > 20
) {
    checkoutFail("One or more delivery fields are too long.");
}

if (
    empty($_SESSION["cart"]) ||
    !is_array($_SESSION["cart"])
) {
    checkoutFail("Your cart is empty.");
}

$cart = $_SESSION["cart"];

// Fixed server-side delivery fee.
// Do not accept this amount from the browser.
$deliveryFee = 25.00;

try {
    $pdo->beginTransaction();

    $subtotal = 0;
    $items = [];

    foreach ($cart as $id => $quantity) {
        $id = filter_var($id, FILTER_VALIDATE_INT);
        $quantity = filter_var($quantity, FILTER_VALIDATE_INT);

        if (!$id || !$quantity || $quantity < 1) {
            throw new RuntimeException(
                "Your cart contains an invalid item."
            );
        }

        // Lock product while checking availability.
        $stmt = $pdo->prepare(
            "SELECT id, name, price, stock, active
             FROM products
             WHERE id = ?
             FOR UPDATE"
        );
        $stmt->execute([$id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if (
            !$product ||
            (int)$product["active"] !== 1 ||
            (int)$product["stock"] < $quantity
        ) {
            throw new RuntimeException(
                "A product is unavailable or no longer has enough stock. " .
                "Please review your cart."
            );
        }

        $price = (float)$product["price"];

        if (!is_finite($price) || $price < 0) {
            throw new RuntimeException(
                "An invalid product price was found."
            );
        }

        $subtotal += $price * $quantity;

        $items[] = [
            "id" => (int)$product["id"],
            "quantity" => $quantity,
            "price" => $price
        ];
    }

    $subtotal = round($subtotal, 2);
    $total = round($subtotal + $deliveryFee, 2);

    if (!is_finite($total) || $total <= 0) {
        throw new RuntimeException(
            "The order total is invalid."
        );
    }

    // Create the pending order.
    // Total includes delivery.
    $stmt = $pdo->prepare(
        "INSERT INTO orders
        (
            user_id,
            total,
            delivery_fee,
            status,
            payment_method,
            delivery_name,
            delivery_phone,
            delivery_address,
            delivery_city,
            delivery_postal_code
        )
        VALUES (?, ?, ?, 'Pending', 'PayFast', ?, ?, ?, ?, ?)"
    );

    $stmt->execute([
        $_SESSION["user_id"],
        number_format($total, 2, ".", ""),
        number_format($deliveryFee, 2, ".", ""),
        $deliveryName,
        $deliveryPhone,
        $deliveryAddress,
        $deliveryCity,
        $deliveryPostalCode
    ]);

    $orderId = (int)$pdo->lastInsertId();

    // Save order items.
    // Item prices remain product prices,
    // without delivery being added to each item.
    $itemStmt = $pdo->prepare(
        "INSERT INTO order_items
            (order_id, product_id, quantity, price)
         VALUES (?, ?, ?, ?)"
    );

    foreach ($items as $item) {
        $itemStmt->execute([
            $orderId,
            $item["id"],
            $item["quantity"],
            number_format($item["price"], 2, ".", "")
        ]);
    }

    $pdo->commit();

    // Keep cart until payment is confirmed.
    $_SESSION["pending_order_id"] = $orderId;

    header("Location: payment.php");
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        "DeeHandMade checkout error: " . $e->getMessage()
    );

    if ($e instanceof RuntimeException) {
        checkoutFail($e->getMessage());
    }

    checkoutFail(
        "We could not create your order. Please try again."
    );
}
