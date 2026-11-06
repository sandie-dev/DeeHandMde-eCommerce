<?php
require_once "../includes/authentication.php";
require_once "../includes/database.php";

requireAdmin();

$message = "";
$error = "";

$allowedStatuses = [
    "Pending",
    "Processing",
    "Completed",
    "Cancelled"
];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $orderId = filter_input(INPUT_POST, "order_id", FILTER_VALIDATE_INT);
    $newStatus = $_POST["status"] ?? "";

    if (
        !$orderId ||
        $orderId < 1 ||
        !in_array($newStatus, $allowedStatuses, true)
    ) {
        $error = "Please select a valid order and status.";
    } else {
        $check = $pdo->prepare("SELECT id FROM orders WHERE id = ?");
        $check->execute([$orderId]);

        if (!$check->fetch()) {
            $error = "Order not found.";
        } else {
            $stmt = $pdo->prepare(
                "UPDATE orders SET status = ? WHERE id = ?"
            );
            $stmt->execute([$newStatus, $orderId]);

            $message = "Order status updated successfully.";
        }
    }
}

$stmt = $pdo->query(
    "SELECT orders.id,
            orders.user_id,
            orders.total,
            orders.status,
            orders.created_at,
            users.name AS customer_name,
            users.email AS customer_email
     FROM orders
     INNER JOIN users ON orders.user_id = users.id
     ORDER BY orders.created_at DESC"
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
	
    <style>
        .page-container { max-width: 1200px; margin: 30px auto; padding: 20px; }
        .table-wrapper { overflow-x: auto; }
        .order-card {
            background: white; padding: 20px; margin: 20px 0;
            border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        .status-form { display: flex; gap: 8px; flex-wrap: wrap; }
        .status-form select, .status-form button {
            padding: 9px; border-radius: 5px;
        }
        .status-form button {
            background: #6b3f2a; color: white; border: 0; cursor: pointer;
        }
        .order-items { margin-top: 15px; }
    </style>
</head>
<body>

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

    <?php if ($message !== ""): ?>
        <p class="success"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <?php if (count($orders) === 0): ?>
        <p>No customer orders have been placed yet.</p>
    <?php endif; ?>

    <?php foreach ($orders as $order): ?>
        <section class="order-card">
            <h3>Order #<?= (int)$order["id"] ?></h3>

            <p>
                <strong>Customer:</strong>
                <?= htmlspecialchars($order["customer_name"]) ?>
            </p>

            <p>
                <strong>Email:</strong>
                <?= htmlspecialchars($order["customer_email"]) ?>
            </p>

            <p>
                <strong>Date:</strong>
                <?= htmlspecialchars($order["created_at"]) ?>
            </p>

            <p>
                <strong>Total:</strong>
                R<?= number_format((float)$order["total"], 2) ?>
            </p>

            <p>
                <strong>Current status:</strong>
                <?= htmlspecialchars($order["status"]) ?>
            </p>

            <h4>Items in this order</h4>

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
                         INNER JOIN products
                             ON order_items.product_id = products.id
                         WHERE order_items.order_id = ?"
                    );
                    $itemStmt->execute([(int)$order["id"]]);
                    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>

                    <?php if (count($items) > 0): ?>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item["product_name"]) ?></td>
                                <td><?= (int)$item["quantity"] ?></td>
                                <td>R<?= number_format((float)$item["price"], 2) ?></td>
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
                            <td colspan="4">No order items found for this order.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <h4>Update Order Status</h4>

            <form class="status-form" method="POST" action="orders.php">
                <input type="hidden" name="order_id"
                       value="<?= (int)$order["id"] ?>">

                <select name="status" aria-label="New order status" required>
                    <?php foreach ($allowedStatuses as $status): ?>
                        <option value="<?= htmlspecialchars($status) ?>"
                            <?= $order["status"] === $status ? "selected" : "" ?>>
                            <?= htmlspecialchars($status) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <button type="submit">Save Status</button>
            </form>
        </section>
    <?php endforeach; ?>
</main>

</body>
</html>