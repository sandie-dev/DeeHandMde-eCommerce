
<?php
require_once "../includes/authentication.php";
require_once "../includes/database.php";

requireAdmin();

$message = "";
$error = "";

// Create a CSRF token for secure form submissions.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Only delivery progress can be changed by the admin.
// Payment status must be updated by PayFast.
function getAllowedNextDeliveryStatuses($currentStatus)
{
    switch ($currentStatus) {
        case "Processing":
            return ["Shipped"];

        case "Shipped":
            return ["Delivered"];

        default:
            return [];
    }
}

// Process a delivery status update.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $orderId = filter_input(
        INPUT_POST,
        "order_id",
        FILTER_VALIDATE_INT
    );

    $newDeliveryStatus = $_POST["delivery_status"] ?? "";
    $csrfToken = $_POST["csrf_token"] ?? "";

    if (
        !$orderId ||
        $orderId < 1 ||
        !is_string($csrfToken) ||
        !hash_equals($_SESSION["csrf_token"], $csrfToken)
    ) {
        $error = "Invalid request. Please refresh the page and try again.";

    } else {

        try {
            $pdo->beginTransaction();

            // Lock the order while checking and updating delivery progress.
            $check = $pdo->prepare(
                "SELECT id, payment_status, delivery_status,
                        stock_deducted
                 FROM orders
                 WHERE id = ?
                 FOR UPDATE"
            );

            $check->execute([$orderId]);
            $order = $check->fetch(PDO::FETCH_ASSOC);

            if (!$order) {

                $error = "Order not found.";

            } elseif ($order["payment_status"] !== "Paid") {

                $error = "Only paid orders can have their delivery status updated.";

            } elseif ((int)$order["stock_deducted"] !== 1) {

                $error = "This order has not completed stock processing.";

            } else {

                $currentDeliveryStatus = $order["delivery_status"];

                $allowedStatuses = getAllowedNextDeliveryStatuses(
                    $currentDeliveryStatus
                );

                if (!in_array($newDeliveryStatus, $allowedStatuses, true)) {

                    $error = "This delivery status change is not allowed.";

                } else {

                    // Keep the old status column updated for compatibility
                    // with existing dashboard pages.
                    $update = $pdo->prepare(
                        "UPDATE orders
                         SET delivery_status = ?,
                             status = ?
                         WHERE id = ?
                           AND payment_status = 'Paid'
                           AND delivery_status = ?
                           AND stock_deducted = 1"
                    );

                    $update->execute([
                        $newDeliveryStatus,
                        $newDeliveryStatus,
                        $orderId,
                        $currentDeliveryStatus
                    ]);

                    if ($update->rowCount() === 1) {
                        $message = "Order #" . $orderId .
                                   " delivery status updated successfully.";
                    } else {
                        $error = "The order could not be updated. Please try again.";
                    }
                }
            }

            $pdo->commit();

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log("Order delivery update error: " . $e->getMessage());

            $error = "An error occurred while updating the order.";
        }
    }
}

// Retrieve all orders and customer details.
$stmt = $pdo->query(
    "SELECT orders.id,
            orders.user_id,
            orders.total,
            orders.delivery_fee,
            orders.status,
            orders.payment_status,
            orders.delivery_status,
            orders.payment_method,
            orders.payment_reference,
            orders.created_at,
            orders.delivery_name,
            orders.delivery_phone,
            orders.delivery_address,
            orders.delivery_city,
            orders.delivery_postal_code,
            orders.stock_deducted,
            users.name AS customer_name,
            users.email AS customer_email
     FROM orders
     INNER JOIN users ON orders.user_id = users.id
     ORDER BY orders.created_at DESC, orders.id DESC"
);

$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Orders - DeeHandMade</title>

<link rel="stylesheet" href="../css/style.css">
<link rel="stylesheet" href="../css/adminProductsStyle.css">
<link rel="stylesheet" href="../css/ordersStyle.css">

</head>

<body>
<?php require_once __DIR__ . "/../includes/backButton.php"; ?>
<header>

<h1>DeeHandMade Admin</h1>

<nav>
    <a href="../admin.php">Dashboard</a>
    <a href="products.php">Products</a>
    <a href="stock.php">Stock</a>
    <a href="orders.php">Orders</a>
    <a href="../logout.php">Logout</a>
</nav>

</header>

<main class="page-container">

<h2>Customer Orders</h2>

<p>View customer purchases and manage order delivery progress.</p>

<?php if ($message !== ""): ?>

    <p class="success">
        <?= htmlspecialchars($message, ENT_QUOTES, "UTF-8") ?>
    </p>

<?php endif; ?>

<?php if ($error !== ""): ?>

    <p class="error">
        <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
    </p>

<?php endif; ?>

<?php if (count($orders) === 0): ?>

    <p>No customer orders have been placed yet.</p>

<?php endif; ?>

<?php foreach ($orders as $order): ?>

<section class="order-card">

    <h3>
        Order #<?= (int)$order["id"] ?>
    </h3>

    <div class="order-details">

        <p>
            <strong>Customer:</strong>
            <?= htmlspecialchars($order["customer_name"], ENT_QUOTES, "UTF-8") ?>
        </p>

        <p>
            <strong>Email:</strong>
            <?= htmlspecialchars($order["customer_email"], ENT_QUOTES, "UTF-8") ?>
        </p>

        <p>
            <strong>Date:</strong>
            <?= htmlspecialchars($order["created_at"], ENT_QUOTES, "UTF-8") ?>
        </p>

        <p>
            <strong>Total:</strong>
            R<?= number_format((float)$order["total"], 2) ?>
        </p>

        <p>
            <strong>Delivery fee:</strong>
            R<?= number_format((float)$order["delivery_fee"], 2) ?>
        </p>

        <p>
            <strong>Payment status:</strong>

            <span class="status-badge">
                <?= htmlspecialchars($order["payment_status"], ENT_QUOTES, "UTF-8") ?>
            </span>
        </p>

        <p>
            <strong>Delivery status:</strong>

            <span class="status-badge">
                <?= htmlspecialchars($order["delivery_status"], ENT_QUOTES, "UTF-8") ?>
            </span>
        </p>

        <p>
            <strong>Payment method:</strong>
            <?= htmlspecialchars($order["payment_method"] ?? "Not specified", ENT_QUOTES, "UTF-8") ?>
        </p>

        <p>
            <strong>Payment reference:</strong>
            <?= htmlspecialchars($order["payment_reference"] ?? "Not available", ENT_QUOTES, "UTF-8") ?>
        </p>

        <p>
            <strong>Stock deducted:</strong>
            <?= (int)$order["stock_deducted"] === 1 ? "Yes" : "No" ?>
        </p>

    </div>

    <h4>Delivery Information</h4>

    <p>
        <strong>Recipient:</strong>
        <?= htmlspecialchars($order["delivery_name"] ?? "Not provided", ENT_QUOTES, "UTF-8") ?>
    </p>

    <p>
        <strong>Phone:</strong>
        <?= htmlspecialchars($order["delivery_phone"] ?? "Not provided", ENT_QUOTES, "UTF-8") ?>
    </p>

    <p>
        <strong>Address:</strong>
        <?= htmlspecialchars($order["delivery_address"] ?? "Not provided", ENT_QUOTES, "UTF-8") ?>
    </p>

    <p>
        <strong>City:</strong>
        <?= htmlspecialchars($order["delivery_city"] ?? "Not provided", ENT_QUOTES, "UTF-8") ?>
    </p>

    <p>
        <strong>Postal code:</strong>
        <?= htmlspecialchars($order["delivery_postal_code"] ?? "Not provided", ENT_QUOTES, "UTF-8") ?>
    </p>

    <h4>Items in this Order</h4>

    <div class="table-wrapper">

        <table class="order-items">

            <thead>
                <tr>
                    <th>Product</th>
                    <th>Quantity</th>
                    <th>Price at Purchase</th>
                    <th>Subtotal</th>
                </tr>
            </thead>

            <tbody>

            <?php
            $itemStmt = $pdo->prepare(
                "SELECT order_items.quantity,
                        order_items.price,
                        products.name AS product_name
                 FROM order_items
                 LEFT JOIN products
                    ON order_items.product_id = products.id
                 WHERE order_items.order_id = ?"
            );

            $itemStmt->execute([(int)$order["id"]]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php if (count($items) > 0): ?>

                <?php foreach ($items as $item): ?>

                    <tr>

                        <td>
                            <?= htmlspecialchars(
                                $item["product_name"] ?? "Product no longer available",
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </td>

                        <td>
                            <?= (int)$item["quantity"] ?>
                        </td>

                        <td>
                            R<?= number_format((float)$item["price"], 2) ?>
                        </td>

                        <td>
                            R<?= number_format(
                                (float)$item["price"] * (int)$item["quantity"],
                                2
                            ) ?>
                        </td>

                    </tr>

                <?php endforeach; ?>

            <?php else: ?>

                <tr>
                    <td colspan="4">
                        No order items found for this order.
                    </td>
                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

    <h4>Update Delivery Status</h4>

    <?php
    $availableStatuses = getAllowedNextDeliveryStatuses(
        $order["delivery_status"]
    );
    ?>

    <?php if (
        count($availableStatuses) > 0 &&
        $order["payment_status"] === "Paid" &&
        (int)$order["stock_deducted"] === 1
    ): ?>

        <form class="status-form" method="POST" action="orders.php">

            <input
                type="hidden"
                name="order_id"
                value="<?= (int)$order["id"] ?>"
            >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars(
                    $_SESSION["csrf_token"],
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>"
            >

            <select
                name="delivery_status"
                required
                aria-label="New delivery status"
            >

                <option value="">Select delivery status</option>

                <?php foreach ($availableStatuses as $status): ?>

                    <option value="<?= htmlspecialchars($status, ENT_QUOTES, "UTF-8") ?>">
                        <?= htmlspecialchars($status, ENT_QUOTES, "UTF-8") ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <button type="submit">
                Save Delivery Status
            </button>

        </form>

    <?php else: ?>

        <p class="no-action">

            <?php if ($order["payment_status"] === "Pending"): ?>

                Awaiting PayFast payment confirmation.

            <?php elseif ($order["payment_status"] === "Cancelled"): ?>

                Payment was cancelled. No delivery update is available.

            <?php elseif ((int)$order["stock_deducted"] !== 1): ?>

                Stock processing has not been completed. Please review this order.

            <?php elseif ($order["delivery_status"] === "Delivered"): ?>

                This order has been delivered.

            <?php elseif ($order["delivery_status"] === "Cancelled"): ?>

                This delivery has been cancelled.

            <?php else: ?>

                No delivery status updates are currently available.

            <?php endif; ?>

        </p>

    <?php endif; ?>

</section>

<?php endforeach; ?>

</main>

</body>
</html>
