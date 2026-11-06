
<?php
require_once "includes/authentication.php";
require_once "includes/database.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php?redirect=checkout");
    exit;
}

if (
    !isset($_SESSION["cart"]) ||
    !is_array($_SESSION["cart"]) ||
    empty($_SESSION["cart"])
) {
    header("Location: cart.php");
    exit;
}

// Fixed delivery fee per order.
$deliveryFee = 25.00;

$stmt = $pdo->prepare(
    "SELECT id, name, email
     FROM users
     WHERE id = ?
     LIMIT 1"
);
$stmt->execute([$_SESSION["user_id"]]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: logout.php");
    exit;
}

$products = [];
$subtotal = 0;
$error = $_SESSION["checkout_error"] ?? "";
unset($_SESSION["checkout_error"]);

foreach ($_SESSION["cart"] as $id => $quantity) {
    $id = filter_var($id, FILTER_VALIDATE_INT);
    $quantity = filter_var($quantity, FILTER_VALIDATE_INT);

    if (!$id || !$quantity || $quantity < 1) {
        continue;
    }

    $stmt = $pdo->prepare(
        "SELECT id, name, price, stock, active
         FROM products
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (
        !$product ||
        (int)$product["active"] !== 1 ||
        (int)$product["stock"] < $quantity
    ) {
        $error =
            "A product is unavailable or there is not enough stock. " .
            "Please review your cart.";
        break;
    }

    $product["quantity"] = $quantity;
    $product["subtotal"] =
        (float)$product["price"] * $quantity;

    $subtotal += $product["subtotal"];
    $products[] = $product;
}

if ($error !== "") {
    $_SESSION["checkout_error"] = $error;
    header("Location: cart.php");
    exit;
}

if (empty($products)) {
    header("Location: cart.php");
    exit;
}

$subtotal = round($subtotal, 2);
$total = round($subtotal + $deliveryFee, 2);

if (empty($_SESSION["checkout_token"])) {
    $_SESSION["checkout_token"] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1">

    <title>Checkout | DeeHandMade</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #fffaf3;
            color: #30261f;
        }

        header, main {
            max-width: 1050px;
            margin: auto;
            padding: 24px;
        }

        header {
            border-bottom: 1px solid #eadfce;
        }

        header a {
            color: #6b3f2a;
            text-decoration: none;
        }

        h1, h2 {
            font-family: Georgia, serif;
            font-weight: normal;
        }

        .layout {
            display: grid;
            grid-template-columns: 1fr 330px;
            gap: 30px;
        }

        .panel {
            background: white;
            border: 1px solid #eadfce;
            padding: 24px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin: 16px 0 7px;
            font-size: 14px;
        }

        input:not([type="checkbox"]) {
            width: 100%;
            padding: 12px;
            border: 1px solid #d8cbbb;
            border-radius: 3px;
            font: inherit;
        }

        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            accent-color: #6b3f2a;
            flex-shrink: 0;
        }

        button, .button {
            display: inline-block;
            width: 100%;
            margin-top: 20px;
            padding: 14px;
            background: #6b3f2a;
            color: white;
            border: 0;
            border-radius: 3px;
            text-align: center;
            text-decoration: none;
            cursor: pointer;
            font: inherit;
        }

        .line {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin: 15px 0;
        }

        .error {
            background: #fff0ed;
            padding: 12px;
            color: #8a3023;
        }

        .muted {
            color: #786b60;
            font-size: 13px;
            line-height: 1.5;
        }

        .confirmation {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            margin-top: 22px;
            line-height: 1.5;
        }

        .confirmation label {
            margin: 0;
        }

        @media(max-width: 750px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>
<header>
    <a href="main.php"><strong>DeeHandMade</strong></a>
    &nbsp; | &nbsp;
    <a href="cart.php">Back to cart</a>
</header>

<main>
    <h1>Checkout</h1>

    <p class="muted">
        Confirm your delivery information and order total.
    </p>

    <?php if ($error !== ""): ?>
        <p class="error">
            <?php echo htmlspecialchars($error); ?>
        </p>
    <?php endif; ?>

    <div class="layout">

        <section class="panel">
            <h2>Delivery details</h2>

            <form action="processCheckout.php" method="POST">

                <input type="hidden"
                       name="checkout_token"
                       value="<?php echo htmlspecialchars(
                           $_SESSION["checkout_token"]
                       ); ?>">

                <label for="delivery_name">
                    Recipient's full name
                </label>
                <input id="delivery_name"
                       name="delivery_name"
                       maxlength="150"
                       autocomplete="name"
                       required
                       value="<?php echo htmlspecialchars(
                           $user["name"]
                       ); ?>">

                <label for="email">Email address</label>
                <input id="email"
                       type="email"
                       readonly
                       value="<?php echo htmlspecialchars(
                           $user["email"]
                       ); ?>">

                <label for="delivery_phone">
                    Phone number
                </label>
                <input id="delivery_phone"
                       name="delivery_phone"
                       type="tel"
                       maxlength="30"
                       autocomplete="tel"
                       required>

                <label for="delivery_address">
                    Street address
                </label>
                <input id="delivery_address"
                       name="delivery_address"
                       maxlength="255"
                       autocomplete="street-address"
                       required>

                <label for="delivery_city">
                    City or town
                </label>
                <input id="delivery_city"
                       name="delivery_city"
                       maxlength="100"
                       autocomplete="address-level2"
                       required>

                <label for="delivery_postal_code">
                    Postal code
                </label>
                <input id="delivery_postal_code"
                       name="delivery_postal_code"
                       maxlength="20"
                       autocomplete="postal-code"
                       required>

                <div class="confirmation">
                    <input type="checkbox"
                           id="delivery_confirm"
                           name="delivery_confirm"
                           value="1"
                           required>

                    <label for="delivery_confirm">
                        I confirm that my delivery details are
                        correct and I acknowledge the R25.00
                        delivery fee and the total payable.
                    </label>
                </div>

                <button type="submit">
                    Place Order and Continue
                </button>

                <p class="muted">
                    Your order will be created as pending
                    until payment is confirmed.
                </p>

            </form>
        </section>

        <aside class="panel">
            <h2>Your order</h2>

            <?php foreach ($products as $product): ?>
                <div class="line">
                    <span>
                        <?php echo htmlspecialchars(
                            $product["name"]
                        ); ?>
                        × <?php echo (int)$product["quantity"]; ?>
                    </span>

                    <span>
                        R<?php echo number_format(
                            $product["subtotal"], 2
                        ); ?>
                    </span>
                </div>
            <?php endforeach; ?>

            <hr>

            <div class="line">
                <span>Subtotal</span>
                <span>
                    R<?php echo number_format($subtotal, 2); ?>
                </span>
            </div>

            <div class="line">
                <span>Delivery</span>
                <span>
                    R<?php echo number_format($deliveryFee, 2); ?>
                </span>
            </div>

            <hr>

            <div class="line">
                <strong>Total payable</strong>
                <strong>
                    R<?php echo number_format($total, 2); ?>
                </strong>
            </div>

            <p class="muted">
                A fixed delivery fee of R25.00 is charged
                once per order.
            </p>

            <a class="button" href="cart.php">
                Edit Cart
            </a>
        </aside>

    </div>
</main>
</body>
</html>
