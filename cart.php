<?php
require_once "includes/authentication.php";
require_once "includes/database.php";

// Guests can use the cart without logging in.
if (!isset($_SESSION["cart"]) || !is_array($_SESSION["cart"])) {
    $_SESSION["cart"] = [];
}

// Use the same CSRF token as main.php.
if (empty($_SESSION["cart_token"])) {
    $_SESSION["cart_token"] = bin2hex(random_bytes(32));
}

$error = $_SESSION["cart_error"] ?? "";
$success = $_SESSION["cart_success"] ?? "";

unset($_SESSION["cart_error"], $_SESSION["cart_success"]);

// Only allow redirects to these local pages.

function cartRedirect($page)
{
    if ($page === "cart.php") {
        header("Location: cart.php");
        exit;
    }

    // Allow main.php and safe product anchors only.
    if (preg_match(
        '/^main\.php(?:#product-[1-9][0-9]*)?$/',
        $page
    )) {
        header("Location: " . $page);
        exit;
    }

    header("Location: main.php");
    exit;
}


// Handle cart changes.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["cart_token"], $token)
    ) {
        $_SESSION["cart_error"] =
            "Your request could not be verified. Please try again.";

        cartRedirect("cart.php");
    }

    $action = $_POST["action"] ?? "";
    $productId = filter_var(
        $_POST["product_id"] ?? null,
        FILTER_VALIDATE_INT
    );

   $returnTo = $_POST["return_to"] ?? "main.php";

	if (
		$returnTo !== "cart.php" &&
		!preg_match(
			'/^main\.php(?:#product-[1-9][0-9]*)?$/',
			$returnTo
		)
	) {
		$returnTo = "cart.php";
	}

    if (!$productId || $productId < 1) {
        $_SESSION["cart_error"] = "Invalid product.";
        cartRedirect($returnTo);
    }

    // Find the latest product status and stock.
    $stmt = $pdo->prepare(
        "SELECT id, name, price, stock, active
         FROM products
         WHERE id = :id
         LIMIT 1"
    );

    $stmt->execute(["id" => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product || (int)$product["active"] !== 1) {
        unset($_SESSION["cart"][$productId]);

        $_SESSION["cart_error"] =
            "This product is no longer available.";

        cartRedirect($returnTo);
    }

    $stock = (int)$product["stock"];
    $currentQuantity = (int)(
        $_SESSION["cart"][$productId] ?? 0
    );

    if ($stock < 1) {
        unset($_SESSION["cart"][$productId]);

        $_SESSION["cart_error"] =
            "This product is currently out of stock.";

        cartRedirect($returnTo);
    }

    // ADD: add the requested quantity to the existing quantity.
    if ($action === "add") {

        $requestedQuantity = filter_var(
            $_POST["quantity"] ?? 1,
            FILTER_VALIDATE_INT
        );

        if (
            $requestedQuantity === false ||
            $requestedQuantity < 1
        ) {
            $_SESSION["cart_error"] =
                "Please choose a valid quantity.";

            cartRedirect($returnTo);
        }

        $newQuantity = $currentQuantity + $requestedQuantity;

        if ($newQuantity > $stock) {
            $_SESSION["cart_error"] =
                "Only " . $stock . " of this product are available. " .
                "Your cart has not been changed.";

            cartRedirect($returnTo);
        }

        $_SESSION["cart"][$productId] = $newQuantity;

        $_SESSION["cart_success"] =
            htmlspecialchars($product["name"]) .
            " added to your cart.";

        cartRedirect($returnTo);
    }

    // UPDATE: quantity zero removes the item.
    if ($action === "update") {

        $newQuantity = filter_var(
            $_POST["quantity"] ?? null,
            FILTER_VALIDATE_INT
        );

        if (
            $newQuantity === false ||
            $newQuantity < 0
        ) {
            $_SESSION["cart_error"] =
                "Please enter a valid quantity.";

            cartRedirect($returnTo);
        }

        if ($newQuantity === 0) {

            unset($_SESSION["cart"][$productId]);

            $_SESSION["cart_success"] =
                htmlspecialchars($product["name"]) .
                " removed from your cart.";

            cartRedirect($returnTo);
        }

        if ($newQuantity > $stock) {
            $_SESSION["cart_error"] =
                "Only " . $stock . " of this product are available.";

            cartRedirect($returnTo);
        }

        $_SESSION["cart"][$productId] = $newQuantity;

        $_SESSION["cart_success"] =
            "Cart quantity updated.";

        cartRedirect($returnTo);
    }

    // REMOVE: remove an item entirely.
    if ($action === "remove") {

        unset($_SESSION["cart"][$productId]);

        $_SESSION["cart_success"] =
            htmlspecialchars($product["name"]) .
            " removed from your cart.";

        cartRedirect($returnTo);
    }

    $_SESSION["cart_error"] = "Unknown cart action.";
    cartRedirect($returnTo);
}


// Fetch the latest product details for the cart.
$cartProducts = [];
$cartTotal = 0;
$totalItems = 0;
$unavailableItems = [];

if (!empty($_SESSION["cart"])) {

    foreach ($_SESSION["cart"] as $id => $quantity) {

        $id = (int)$id;
        $quantity = (int)$quantity;

        if ($id < 1 || $quantity < 1) {
            unset($_SESSION["cart"][$id]);
            continue;
        }

        $stmt = $pdo->prepare(
            "SELECT id, name, description, price, stock, image, active
             FROM products
             WHERE id = :id
             LIMIT 1"
        );

        $stmt->execute(["id" => $id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        // Remove deleted, inactive, or out-of-stock products.
        if (
            !$product ||
            (int)$product["active"] !== 1 ||
            (int)$product["stock"] < 1
        ) {
            unset($_SESSION["cart"][$id]);

            $unavailableItems[] = $product
                ? $product["name"]
                : "A product that is no longer available";

            continue;
        }

        $stock = (int)$product["stock"];

        // Adjust saved session quantities if stock has fallen.
        if ($quantity > $stock) {
            $quantity = $stock;
            $_SESSION["cart"][$id] = $quantity;
        }

        $product["quantity"] = $quantity;
        $product["subtotal"] =
            (float)$product["price"] * $quantity;

        $cartProducts[] = $product;
        $cartTotal += $product["subtotal"];
        $totalItems += $quantity;
    }
}

$cartCount = array_sum($_SESSION["cart"]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Your Cart | DeeHandMade</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --green: #294538;
            --light-green: #e9ede4;
            --cream: #faf9f5;
            --text: #263b30;
            --muted: #778076;
            --border: #e8e8e0;
        }

        body {
            font-family: Arial, sans-serif;
            color: var(--text);
            background: white;
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        .navbar {
            min-height: 76px;
            padding: 18px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            border-bottom: 1px solid var(--border);
        }

        .brand {
            font-family: Georgia, serif;
            font-size: 25px;
            font-weight: bold;
            color: var(--green);
            white-space: nowrap;
        }

        .nav-links {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: center;
            font-size: 14px;
        }

        .nav-links a:hover {
            color: #79917b;
        }

        .page-heading {
            background: var(--light-green);
            padding: 45px 5%;
        }

        .eyebrow {
            color: #647365;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 12px;
        }

        h1, h2 {
            font-family: Georgia, serif;
            font-weight: normal;
            color: var(--green);
        }

        h1 {
            font-size: clamp(32px, 4vw, 44px);
            margin-bottom: 12px;
        }

        .page-heading p:last-child {
            color: #697469;
            line-height: 1.7;
            font-size: 14px;
        }

        .container {
            width: 92%;
            max-width: 1150px;
            margin: 40px auto 65px;
        }

        .message {
            padding: 14px 16px;
            margin-bottom: 20px;
            border-radius: 4px;
            font-size: 14px;
            line-height: 1.5;
        }

        .error {
            background: #fff0ed;
            color: #8a3023;
        }

        .success {
            background: var(--light-green);
            color: var(--green);
        }

        .cart-layout {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 310px;
            gap: 35px;
            align-items: start;
        }

        .cart-heading {
            font-size: 26px;
            margin-bottom: 20px;
        }

        .cart-item {
            display: grid;
            grid-template-columns: 120px minmax(0, 1fr);
            gap: 20px;
            padding: 20px 0;
            border-bottom: 1px solid var(--border);
        }

        .product-image {
            width: 120px;
            height: 125px;
            object-fit: cover;
            background: var(--cream);
        }

        .item-name {
            font-size: 19px;
            margin-bottom: 8px;
        }

        .item-description {
            font-size: 13px;
            line-height: 1.5;
            color: var(--muted);
            margin-bottom: 12px;
        }

        .item-price {
            font-weight: bold;
            margin-bottom: 12px;
        }

        .item-actions {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        .quantity-controller {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 4px;
            border: 1px solid #dce2d8;
            border-radius: 4px;
        }

        .quantity-controller form {
            margin: 0;
        }

        .quantity-btn {
            width: 32px;
            height: 32px;
            border: none;
            background: var(--light-green);
            color: var(--green);
            font-size: 21px;
            border-radius: 3px;
            cursor: pointer;
        }

        .quantity-btn:disabled {
            opacity: .4;
            cursor: not-allowed;
        }

        .quantity-number {
            min-width: 16px;
            text-align: center;
            font-weight: bold;
        }

        .remove-btn {
            border: none;
            background: transparent;
            color: #85564b;
            text-decoration: underline;
            cursor: pointer;
            font-size: 13px;
        }

        .item-subtotal {
            margin-top: 12px;
            font-size: 13px;
            color: var(--muted);
        }

        .summary {
            background: var(--cream);
            padding: 25px;
            border: 1px solid var(--border);
            border-radius: 5px;
        }

        .summary h2 {
            font-size: 24px;
            margin-bottom: 22px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 17px;
            font-size: 14px;
        }

        .summary-total {
            padding-top: 17px;
            border-top: 1px solid #deded4;
            font-size: 18px;
            font-weight: bold;
        }

        .summary-note {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
            margin: 18px 0;
        }

        .primary-btn, .secondary-btn {
            display: block;
            width: 100%;
            text-align: center;
            padding: 13px 16px;
            border-radius: 3px;
            font-size: 14px;
            cursor: pointer;
        }

        .primary-btn {
            background: var(--green);
            color: white;
            border: 1px solid var(--green);
            margin-bottom: 12px;
        }

        .primary-btn:hover {
            background: #3e604d;
        }

        .secondary-btn {
            border: 1px solid var(--green);
            color: var(--green);
            background: white;
        }

        .empty-cart {
            text-align: center;
            padding: 55px 20px;
            background: var(--cream);
            border-radius: 5px;
        }

        .empty-cart h2 {
            font-size: 28px;
            margin-bottom: 12px;
        }

        .empty-cart p {
            color: var(--muted);
            margin-bottom: 25px;
            font-size: 14px;
        }

        .empty-cart .primary-btn {
            max-width: 250px;
            margin: 0 auto;
        }

        footer {
            background: var(--light-green);
            padding: 30px 5%;
            text-align: center;
            font-size: 12px;
            line-height: 1.8;
            color: #697469;
        }

        @media (max-width: 800px) {
            .cart-layout {
                grid-template-columns: 1fr;
            }

            .summary {
                max-width: none;
            }
        }

        @media (max-width: 600px) {
            .navbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .nav-links {
                gap: 14px;
            }

            .page-heading {
                padding: 35px 5%;
            }

            .cart-item {
                grid-template-columns: 90px minmax(0, 1fr);
                gap: 14px;
            }

            .product-image {
                width: 90px;
                height: 105px;
            }

            .item-name {
                font-size: 16px;
            }

            .item-actions {
                gap: 10px;
            }

            .quantity-controller {
                gap: 7px;
            }
        }
    </style>
</head>

<body>

<header class="navbar">

    <a href="main.php" class="brand">DeeHandMade</a>

    <nav class="nav-links">
        <a href="main.php">Continue Shopping</a>

        <a href="cart.php">
            Cart (<?php echo (int)$cartCount; ?>)
        </a>

        <?php if (isset($_SESSION["user_id"])): ?>
            <a href="profile.php">My Profile</a>
            <a href="logout.php">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php">Register</a>
        <?php endif; ?>
    </nav>

</header>


<section class="page-heading">
    <p class="eyebrow">Your DeeHandMade selection</p>

    <h1>Your Shopping Cart</h1>

    <p>
        Review your handmade favourites before continuing.
    </p>
</section>


<main class="container">

    <?php if ($error !== ""): ?>
        <div class="message error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($unavailableItems)): ?>
        <div class="message error">
            Some products were removed because they are no longer
            available or are out of stock.
        </div>
    <?php endif; ?>


    <?php if (empty($cartProducts)): ?>

        <section class="empty-cart">

            <h2>Your cart is waiting</h2>

            <p>
                You haven't added any products yet.
                Discover teas, handmade soaps and botanical oils.
            </p>

            <a href="main.php" class="primary-btn">
                Explore Our Collection
            </a>

        </section>

    <?php else: ?>

        <div class="cart-layout">

            <!-- CART ITEMS -->
            <section>

                <h2 class="cart-heading">
                    Your Items (<?php echo (int)$totalItems; ?>)
                </h2>

                <?php foreach ($cartProducts as $product): ?>

                    <?php
                    $id = (int)$product["id"];
                    $quantity = (int)$product["quantity"];
                    $stock = (int)$product["stock"];

                    $image = !empty($product["image"])
                        ? "images/" . $product["image"]
                        : "images/placeholder.jpg";
                    ?>

                    <article class="cart-item">

                        <img
                            class="product-image"
                            src="<?php echo htmlspecialchars($image); ?>"
                            alt="<?php echo htmlspecialchars($product["name"]); ?>"
                        >

                        <div>

                            <h3 class="item-name">
                                <?php echo htmlspecialchars($product["name"]); ?>
                            </h3>

                            <p class="item-description">
                                <?php
                                echo htmlspecialchars(
                                    $product["description"] ?? ""
                                );
                                ?>
                            </p>

                            <p class="item-price">
                                R<?php
                                echo number_format(
                                    (float)$product["price"],
                                    2
                                );
                                ?>
                            </p>

                            <div class="item-actions">

                                <!-- DECREASE QUANTITY -->
                                <div class="quantity-controller">

                                    <form action="cart.php" method="POST">

                                        <input type="hidden" name="csrf_token"
                                            value="<?php echo htmlspecialchars($_SESSION["cart_token"]); ?>">

                                        <input type="hidden" name="action"
                                            value="update">

                                        <input type="hidden" name="product_id"
                                            value="<?php echo $id; ?>">

                                        <input type="hidden" name="quantity"
                                            value="<?php echo $quantity - 1; ?>">

                                        <input type="hidden" name="return_to"
                                            value="cart.php">

                                        <button type="submit"
                                            class="quantity-btn"
                                            aria-label="Decrease quantity">
                                            −
                                        </button>

                                    </form>

                                    <span class="quantity-number">
                                        <?php echo $quantity; ?>
                                    </span>

                                    <!-- INCREASE QUANTITY -->
                                    <form action="cart.php" method="POST">

                                        <input type="hidden" name="csrf_token"
                                            value="<?php echo htmlspecialchars($_SESSION["cart_token"]); ?>">

                                        <input type="hidden" name="action"
                                            value="update">

                                        <input type="hidden" name="product_id"
                                            value="<?php echo $id; ?>">

                                        <input type="hidden" name="quantity"
                                            value="<?php echo $quantity + 1; ?>">

                                        <input type="hidden" name="return_to"
                                            value="cart.php">

                                        <button type="submit"
                                            class="quantity-btn"
                                            aria-label="Increase quantity"
                                            <?php echo ($quantity >= $stock) ? "disabled" : ""; ?>>
                                            +
                                        </button>

                                    </form>

                                </div>

                                <!-- REMOVE ITEM -->
                                <form action="cart.php" method="POST">

                                    <input type="hidden" name="csrf_token"
                                        value="<?php echo htmlspecialchars($_SESSION["cart_token"]); ?>">

                                    <input type="hidden" name="action"
                                        value="remove">

                                    <input type="hidden" name="product_id"
                                        value="<?php echo $id; ?>">

                                    <input type="hidden" name="return_to"
                                        value="cart.php">

                                    <button type="submit" class="remove-btn">
                                        Remove
                                    </button>

                                </form>

                            </div>

                            <?php if ($quantity >= $stock): ?>
                                <p class="item-subtotal">
                                    Maximum available quantity reached.
                                </p>
                            <?php endif; ?>

                            <p class="item-subtotal">
                                Subtotal:
                                <strong>
                                    R<?php
                                    echo number_format(
                                        $product["subtotal"],
                                        2
                                    );
                                    ?>
                                </strong>
                            </p>

                        </div>

                    </article>

                <?php endforeach; ?>

            </section>


            <!-- ORDER SUMMARY -->
            <aside class="summary">

                <h2>Order Summary</h2>

                <div class="summary-row">
                    <span>Items</span>
                    <span><?php echo (int)$totalItems; ?></span>
                </div>

                <div class="summary-row">
                    <span>Subtotal</span>
                    <span>
                        R<?php echo number_format($cartTotal, 2); ?>
                    </span>
                </div>

                <div class="summary-row summary-total">
                    <span>Total</span>
                    <span>
                        R<?php echo number_format($cartTotal, 2); ?>
                    </span>
                </div>

                <p class="summary-note">
                    Delivery costs, if applicable, will be confirmed
                    before your order is finalised.
                </p>

                <?php if (isset($_SESSION["user_id"])): ?>

                    <a href="checkout.php" class="primary-btn">
                        Continue to Checkout
                    </a>

                <?php else: ?>

                    <a href="login.php?redirect=checkout"
                       class="primary-btn">
                        Login to Continue
                    </a>

                    <p class="summary-note">
                        You can browse and build your cart as a guest.
                        Please log in or register before placing an order.
                    </p>

                    <a href="registration.php" class="secondary-btn">
                        Create an Account
                    </a>

                <?php endif; ?>

                <a href="main.php"
                   class="secondary-btn"
                   style="margin-top:12px;">
                    Continue Shopping
                </a>

            </aside>

        </div>

    <?php endif; ?>

</main>


<footer>
    <p>DeeHandMade | Natural. Pure. Handmade.</p>
    <p>Made with love.</p>
</footer>

</body>
</html>
