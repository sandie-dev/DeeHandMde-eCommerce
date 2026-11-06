<?php
require_once "../includes/authentication.php";
require_once "../includes/database.php";

requireAdmin();

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header("Location: products.php");
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    $stmt = $pdo->prepare("SELECT id, name, active FROM products WHERE id = ?");
    $stmt->execute([$id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        header("Location: products.php");
        exit;
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Deactivate Product</title>
        <link rel="stylesheet" href="../css/style.css">
		<link rel="stylesheet" href="../css/adminProductsStyle.css">
    </head>
    <body>
        <main style="max-width:600px;margin:60px auto;padding:25px;background:white;">
            <h1>Deactivate Product</h1>
            <p>
                Are you sure you want to deactivate
                <strong><?= htmlspecialchars($product["name"]) ?></strong>?
            </p>
            <p>The product will no longer appear in the customer store.</p>

            <form method="POST" action="deleteProduct.php?id=<?= (int)$id ?>">
                <button type="submit">Yes, Deactivate Product</button>
                <a href="products.php">Cancel</a>
            </form>
        </main>
    </body>
    </html>
    <?php
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE products SET active = 0 WHERE id = ?");
    $stmt->execute([$id]);

    // Remove this product from customers' carts.
    $stmt = $pdo->prepare("DELETE FROM cart WHERE product_id = ?");
    $stmt->execute([$id]);

    $pdo->commit();

    header("Location: products.php");
    exit;

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    http_response_code(500);
    exit("Could not deactivate the product. Please return and try again.");
}
?>