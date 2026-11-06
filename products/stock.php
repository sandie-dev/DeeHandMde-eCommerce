<?php
require_once "../includes/authentication.php";
require_once "../includes/database.php";

requireAdmin();

$message = "";
$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $id = filter_input(INPUT_POST, "product_id", FILTER_VALIDATE_INT);
    $stockInput = $_POST["stock"] ?? "";

    if (
        !$id ||
        $id < 1 ||
        !preg_match('/^\d+$/', $stockInput) ||
        (float)$stockInput > 2147483647
    ) {
        $error = "Please enter a valid stock quantity.";
    } else {
        $check = $pdo->prepare("SELECT id FROM products WHERE id = ?");
        $check->execute([$id]);

        if (!$check->fetch()) {
            $error = "Product not found.";
        } else {
            $stmt = $pdo->prepare(
                "UPDATE products SET stock = ? WHERE id = ?"
            );
            $stmt->execute([(int)$stockInput, $id]);

            $message = "Stock quantity updated successfully.";
        }
    }
}

$stmt = $pdo->query(
    "SELECT id, name, category, stock, active
     FROM products
     ORDER BY name ASC"
);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Stock - DeeHandMade</title>
    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/adminProductsStyle.css">
    <style>
        .page-container { max-width: 1100px; margin: 30px auto; padding: 20px; }
        .table-wrapper { overflow-x: auto; }
        .stock-form { display: flex; gap: 8px; align-items: center; }
        .stock-form input { width: 100px; padding: 8px; }
        .stock-form button {
            padding: 9px 12px; background: #6b3f2a;
            color: white; border: 0; border-radius: 5px; cursor: pointer;
        }
        .inactive { color: #a33; }
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
    <h2>Manage Stock</h2>
    <p>Enter the new total quantity available for each product.</p>

    <?php if ($message !== ""): ?>
        <p class="success"><?= htmlspecialchars($message) ?></p>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Product</th>
                    <th>Category</th>
                    <th>Current Stock</th>
                    <th>Status</th>
                    <th>Update Stock</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= (int)$product["id"] ?></td>
                        <td><?= htmlspecialchars($product["name"]) ?></td>
                        <td><?= htmlspecialchars($product["category"]) ?></td>
                        <td><?= (int)$product["stock"] ?></td>
                        <td>
                            <?php if ((int)$product["active"] === 1): ?>
                                Active
                            <?php else: ?>
                                <span class="inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form class="stock-form" method="POST" action="stock.php">
                                <input type="hidden" name="product_id"
                                       value="<?= (int)$product["id"] ?>">

                                <input type="number" name="stock"
                                       min="0" max="2147483647" step="1"
                                       value="<?= (int)$product["stock"] ?>"
                                       aria-label="New stock quantity for <?= htmlspecialchars($product["name"]) ?>"
                                       required>

                                <button type="submit">Update</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (count($products) === 0): ?>
                    <tr>
                        <td colspan="6">No products available.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

</body>
</html>