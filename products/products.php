<?php
require_once "../includes/authentication.php";
require_once "../includes/database.php";

requireAdmin();

$stmt = $pdo->query(
    "SELECT * FROM products ORDER BY id DESC"
);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Products - DeeHandMade</title>
    <link rel="stylesheet" href="../css/style.css">
	<link rel="stylesheet" href="../css/adminProductsStyle.css">
  <!--  <style>
        .page-container { max-width: 1200px; margin: 30px auto; padding: 20px; }
        .table-wrapper { overflow-x: auto; }
        .action-link { margin-right: 10px; }
        .add-button, .action-button {
            display: inline-block; padding: 10px 14px;
            background: #6b3f2a; color: white;
            text-decoration: none; border: none; border-radius: 5px;
            cursor: pointer;
        }
        .status-active { color: green; font-weight: bold; }
        .status-inactive { color: #a33; font-weight: bold; }
        .product-image { width: 65px; height: 65px; object-fit: cover; }
    </style>
	--!>
</head>
<body>

<header>
    <h1>DeeHandMade Admin</h1>
    <nav>
        <a href="admin.php">Dashboard</a>
        <a href="products.php">Products</a>
        <a href="stock.php">Stock</a>
        <a href="orders.php">Orders</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<main class="page-container">
    <h2>Manage Products</h2>
    <p>View, edit, and deactivate products in your store.</p>

    <p>
        <a class="add-button" href="addProducts.php">+ Add New Product</a>

    </p>
	

	

    <div class="table-wrapper">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Image</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Price</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (count($products) > 0): ?>
                <?php foreach ($products as $product): ?>
                    <tr>
                        <td><?= (int)$product["id"] ?></td>
                        <td>
                            <?php if (!empty($product["image"])): ?>
                                <img class="product-image"
                                     src="../images/<?= htmlspecialchars($product["image"]) ?>"
                                     alt="<?= htmlspecialchars($product["name"]) ?>">
                            <?php else: ?>
                                No image
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($product["name"]) ?></td>
                        <td><?= htmlspecialchars($product["category"]) ?></td>
                        <td>R<?= number_format((float)$product["price"], 2) ?></td>
                        <td><?= (int)$product["stock"] ?></td>
                        <td>
                            <?php if ((int)$product["active"] === 1): ?>
                                <span class="status-active">Active</span>
                            <?php else: ?>
                                <span class="status-inactive">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a class="add-button"
                               href="editProducts.php?id=<?= (int)$product["id"] ?>">
                                Edit
                            </a>

                            <?php if ((int)$product["active"] === 1): ?>
                                <a class="add-button"
                                   href="deleteProduct.php?id=<?= (int)$product["id"] ?>"
                                   onclick="return confirm('Deactivate this product? It will no longer appear in the customer store.');">
                                    Deactivate
                                </a>
                            <?php else: ?>
                                <span>Inactive</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="8">No products have been added yet.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</main>

</body>
</html>