
<?php
require_once "includes/authentication.php";
require_once "includes/database.php";

requireCustomer();

$userId = (int) $_SESSION["user_id"];

$error = "";
$success = "";

// Retrieve only the logged-in customer's account.
$stmt = $pdo->prepare(
    "SELECT id, name, email, created_at
     FROM users
     WHERE id = ? AND role = 'customer'
     LIMIT 1"
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: login.php");
    exit;
}

// CSRF protection for profile updates.
if (empty($_SESSION["profile_csrf_token"])) {
    $_SESSION["profile_csrf_token"] = bin2hex(random_bytes(32));
}

// Update the customer's name.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $submittedToken = $_POST["csrf_token"] ?? "";
    $newName = trim($_POST["name"] ?? "");

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION["profile_csrf_token"], $submittedToken)
    ) {
        $error = "Session verification failed. Please refresh and try again.";
    } elseif ($newName === "") {
        $error = "Please enter your name.";
    } elseif (strlen($newName) > 100) {
        $error = "Your name cannot exceed 100 characters.";
    } elseif (preg_match('/[\x00-\x1F\x7F]/', $newName)) {
        $error = "Please enter a valid name.";
    } else {
        try {
            $update = $pdo->prepare(
                "UPDATE users
                 SET name = ?
                 WHERE id = ? AND role = 'customer'"
            );
            $update->execute([$newName, $userId]);

            $_SESSION["name"] = $newName;

            $user["name"] = $newName;
            $success = "Your profile has been updated successfully.";
        } catch (PDOException $e) {
            error_log("Profile update error: " . $e->getMessage());
            $error = "Unable to update your profile. Please try again.";
        }
    }
}

// Retrieve only this customer's orders, newest first.
$orderStmt = $pdo->prepare(
    "SELECT id, total, delivery_fee, status, payment_method,
            created_at, delivery_name, delivery_phone,
            delivery_address, delivery_city, delivery_postal_code,
            payment_reference
     FROM orders
     WHERE user_id = ?
     ORDER BY created_at DESC, id DESC"
);
$orderStmt->execute([$userId]);
$orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

// Most recent order provides the displayed delivery details.
$latestOrder = !empty($orders) ? $orders[0] : null;

// Escape values before displaying them in HTML.
function profileEscape($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Profile | DeeHandMade</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 28px 16px;
            background: #f7f1e9;
            color: #35271f;
            font-family: Arial, sans-serif;
            line-height: 1.6;
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
        }

        .page-header {
            text-align: center;
            margin-bottom: 25px;
        }

        .page-header h1 {
            color: #6b3f2a;
            margin-bottom: 5px;
        }

        .section-card {
            background: #fff;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 13px;
            box-shadow: 0 4px 16px rgba(70, 45, 30, 0.07);
        }

        h2 {
            color: #6b3f2a;
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #eadfd5;
        }

        h3 {
            color: #6b3f2a;
        }

        .profile-icon {
            width: 65px;
            height: 65px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #efe0d2;
            color: #6b3f2a;
            border-radius: 50%;
            font-size: 27px;
            font-weight: bold;
        }

        .form-group {
            margin-bottom: 17px;
        }

        label {
            display: block;
            margin-bottom: 6px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 11px;
            border: 1px solid #d7c8ba;
            border-radius: 7px;
            font-size: 15px;
        }

        input[readonly] {
            background: #f3f0ed;
            color: #665d56;
        }

        .button {
            display: inline-block;
            padding: 11px 17px;
            border: none;
            border-radius: 7px;
            background: #6b3f2a;
            color: #fff;
            text-decoration: none;
            cursor: pointer;
            font-size: 15px;
        }

        .button:hover {
            background: #4f2d1e;
        }

        .secondary-button {
            background: #e9ded3;
            color: #4c3022;
        }

        .secondary-button:hover {
            background: #ddcbbb;
        }

        .message {
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 18px;
        }

        .error {
            background: #fbe8e6;
            color: #8b2119;
        }

        .success {
            background: #e8f5e9;
            color: #22632b;
        }

        .detail-row {
            margin: 7px 0;
            overflow-wrap: anywhere;
        }

        .muted {
            color: #756b63;
        }

        .order-card {
            border: 1px solid #eadfd5;
            border-radius: 10px;
            padding: 18px;
            margin-top: 18px;
        }

        .order-heading {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
            flex-wrap: wrap;
        }

        .status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            background: #f0e5d9;
            color: #6b3f2a;
            font-size: 13px;
            font-weight: bold;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 14px;
        }

        th, td {
            padding: 10px;
            border-bottom: 1px solid #eee4db;
            text-align: left;
        }

        th {
            background: #f7f1e9;
            color: #513423;
        }

        .totals {
            max-width: 340px;
            margin: 15px 0 0 auto;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 5px 0;
        }

        .grand-total {
            font-size: 18px;
            font-weight: bold;
            border-top: 1px solid #d7c8ba;
            margin-top: 6px;
            padding-top: 10px;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
        }

        @media (max-width: 550px) {
            .section-card {
                padding: 18px;
            }

            .order-card {
                padding: 13px;
            }

            th, td {
                padding: 8px;
                font-size: 13px;
            }
        }
    </style>
</head>

<body>

<main class="container">

    <header class="page-header">
        <h1>My DeeHandMade Account</h1>
        <p>Manage your personal details, delivery information and orders.</p>
    </header>

    <?php if ($error !== ""): ?>
        <div class="message error">
            <?php echo profileEscape($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?php echo profileEscape($success); ?>
        </div>
    <?php endif; ?>

    <!-- SECTION 1: PERSONAL INFORMATION -->
    <section class="section-card">
        <h2>Personal Information</h2>

        <div class="profile-icon" aria-hidden="true">
            <?php echo profileEscape(strtoupper(substr($user["name"], 0, 1))); ?>
        </div>

        <form method="POST" action="profile.php">
            <input
                type="hidden"
                name="csrf_token"
                value="<?php echo profileEscape($_SESSION["profile_csrf_token"]); ?>"
            >

            <div class="form-group">
                <label for="name">Full name</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    maxlength="100"
                    value="<?php echo profileEscape($user["name"]); ?>"
                    autocomplete="name"
                    required
                >
            </div>

            <div class="form-group">
                <label for="email">Registered email</label>
                <input
                    type="email"
                    id="email"
                    value="<?php echo profileEscape($user["email"]); ?>"
                    readonly
                >
            </div>

            <p class="detail-row">
                <strong>Customer ID:</strong>
                <?php echo profileEscape($user["id"]); ?>
            </p>

            <p class="detail-row">
                <strong>Member since:</strong>
                <?php echo profileEscape(date("d M Y", strtotime($user["created_at"]))); ?>
            </p>

            <button type="submit" class="button">Save Changes</button>
        </form>
    </section>

    <!-- SECTION 2: MOST RECENT DELIVERY DETAILS -->
    <section class="section-card">
        <h2>My Delivery Details</h2>

        <?php if ($latestOrder): ?>
            <p class="muted">
                These details are from your most recent order
                (#<?php echo profileEscape($latestOrder["id"]); ?>).
                They are not saved separately as default delivery details.
            </p>

            <p class="detail-row">
                <strong>Recipient:</strong>
                <?php echo profileEscape($latestOrder["delivery_name"]); ?>
            </p>

            <p class="detail-row">
                <strong>Phone:</strong>
                <?php echo profileEscape($latestOrder["delivery_phone"]); ?>
            </p>

            <p class="detail-row">
                <strong>Street address:</strong>
                <?php echo profileEscape($latestOrder["delivery_address"]); ?>
            </p>

            <p class="detail-row">
                <strong>City / town:</strong>
                <?php echo profileEscape($latestOrder["delivery_city"]); ?>
            </p>

            <p class="detail-row">
                <strong>Postal code:</strong>
                <?php echo profileEscape($latestOrder["delivery_postal_code"]); ?>
            </p>
        <?php else: ?>
            <p>You haven't placed an order yet, so you don't have delivery details to display.</p>
            <p class="muted">
                Your delivery details will appear here after your first order is created.
            </p>
        <?php endif; ?>
    </section>

    <!-- SECTION 3: CUSTOMER ORDER HISTORY -->
    <section class="section-card">
        <h2>My Order History</h2>

        <?php if (empty($orders)): ?>
            <p>You haven't placed any orders yet.</p>
            <p class="muted">
                When you place an order, its details and payment status will appear here.
            </p>
        <?php else: ?>

            <p>
                You have placed
                <strong><?php echo count($orders); ?></strong>
                order(s).
            </p>

            <?php foreach ($orders as $order): ?>

                <article class="order-card">
                    <div class="order-heading">
                        <div>
                            <h3>
                                Order #<?php echo profileEscape($order["id"]); ?>
                            </h3>

                            <p class="muted">
                                Placed on
                                <?php echo profileEscape(date(
                                    "d M Y, H:i",
                                    strtotime($order["created_at"])
                                )); ?>
                            </p>
                        </div>

                        <span class="status">
                            Payment: <?php echo profileEscape($order["status"]); ?>
                        </span>
                    </div>

                    <p>
                        <strong>Payment method:</strong>
                        <?php echo profileEscape($order["payment_method"] ?: "Not specified"); ?>
                    </p>

                    <p>
                        <strong>Delivery recipient:</strong>
                        <?php echo profileEscape($order["delivery_name"]); ?>
                    </p>

                    <p>
                        <strong>Delivery address:</strong>
                        <?php
                        echo profileEscape(
                            $order["delivery_address"] . ", " .
                            $order["delivery_city"] . " " .
                            $order["delivery_postal_code"]
                        );
                        ?>
                    </p>

                    <p>
                        <strong>Delivery phone:</strong>
                        <?php echo profileEscape($order["delivery_phone"]); ?>
                    </p>

                    <?php if (!empty($order["payment_reference"])): ?>
                        <p>
                            <strong>Payment reference:</strong>
                            <?php echo profileEscape($order["payment_reference"]); ?>
                        </p>
                    <?php endif; ?>

                    <?php
                    // Retrieve items belonging to this order only.
                    $itemStmt = $pdo->prepare(
                        "SELECT p.name, oi.quantity, oi.price
                         FROM order_items oi
                         LEFT JOIN products p ON p.id = oi.product_id
                         WHERE oi.order_id = ?
                         ORDER BY oi.id ASC"
                    );
                    $itemStmt->execute([(int) $order["id"]]);
                    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>

                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Price</th>
                                    <th>Quantity</th>
                                    <th>Line total</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr>
                                        <td>
                                            <?php
                                            echo profileEscape(
                                                $item["name"] ?? "Product no longer available"
                                            );
                                            ?>
                                        </td>

                                        <td>
                                            R<?php echo number_format((float) $item["price"], 2); ?>
                                        </td>

                                        <td>
                                            <?php echo profileEscape($item["quantity"]); ?>
                                        </td>

                                        <td>
                                            R<?php echo number_format(
                                                (float) $item["price"] * (int) $item["quantity"],
                                                2
                                            ); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>

                                <?php if (empty($items)): ?>
                                    <tr>
                                        <td colspan="4">No order items were found for this order.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php
                    $deliveryFee = (float) $order["delivery_fee"];
                    $grandTotal = (float) $order["total"];
                    $subtotal = max(0, $grandTotal - $deliveryFee);
                    ?>

                    <div class="totals">
                        <div class="total-row">
                            <span>Subtotal</span>
                            <strong>R<?php echo number_format($subtotal, 2); ?></strong>
                        </div>

                        <div class="total-row">
                            <span>Delivery</span>
                            <strong>R<?php echo number_format($deliveryFee, 2); ?></strong>
                        </div>

                        <div class="total-row grand-total">
                            <span>Order total</span>
                            <strong>R<?php echo number_format($grandTotal, 2); ?></strong>
                        </div>
                    </div>
                </article>

            <?php endforeach; ?>

        <?php endif; ?>
    </section>

    <div class="actions">
        <a href="main.php" class="button">Continue Shopping</a>
        <a href="cart.php" class="button secondary-button">View Cart</a>
    </div>

</main>
</body>
</html>
