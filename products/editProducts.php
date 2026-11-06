<?php
require_once "../includes/authentication.php";
require_once "../includes/database.php";

requireAdmin();

$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

if (!$id || $id < 1) {
    header("Location: products.php");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header("Location: products.php");
    exit;
}

$error = "";
$categories = ["Teas", "Soaps", "Oils"];

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $category = $_POST["category"] ?? "";
    $priceInput = $_POST["price"] ?? "";
    $stockInput = $_POST["stock"] ?? "";

    if (
        $name === "" ||
        !in_array($category, $categories, true) ||
        !is_numeric($priceInput) ||
        !is_finite((float)$priceInput) ||
        (float)$priceInput < 0 ||
        (float)$priceInput > 99999999.99 ||
        !preg_match('/^\d+$/', $stockInput) ||
        (float)$stockInput > 2147483647
    ) {
        $error = "Please enter a valid name, category, price, and stock quantity.";
    } else {
        $imageName = $product["image"];
        $uploadedPath = null;

        if (
            isset($_FILES["image"]) &&
            $_FILES["image"]["error"] !== UPLOAD_ERR_NO_FILE
        ) {
            if ($_FILES["image"]["error"] !== UPLOAD_ERR_OK) {
                $error = "The image upload failed.";
            } elseif ($_FILES["image"]["size"] > 5 * 1024 * 1024) {
                $error = "The image must be 5 MB or smaller.";
            } else {
                $tmpPath = $_FILES["image"]["tmp_name"];
                $imageInfo = @getimagesize($tmpPath);
                $mime = $imageInfo["mime"] ?? "";

                $allowedTypes = [
                    "image/jpeg" => "jpg",
                    "image/png" => "png",
                    "image/webp" => "webp"
                ];

                if (
                    !$imageInfo ||
                    !isset($allowedTypes[$mime]) ||
                    !is_uploaded_file($tmpPath)
                ) {
                    $error = "Please upload a valid JPG, PNG, or WebP image.";
                } else {
                    $imageName = uniqid("product_", true) . "." . $allowedTypes[$mime];
                    $uploadedPath = "../images/" . $imageName;

                    if (!move_uploaded_file($tmpPath, $uploadedPath)) {
                        $error = "Could not save the uploaded image.";
                    }
                }
            }
        }

        if ($error === "") {
            try {
                $stmt = $pdo->prepare(
                    "UPDATE products
                     SET name = ?, description = ?, category = ?,
                         price = ?, stock = ?, image = ?
                     WHERE id = ?"
                );

                $stmt->execute([
                    $name,
                    $description,
                    $category,
                    number_format((float)$priceInput, 2, ".", ""),
                    (int)$stockInput,
                    $imageName,
                    $id
                ]);

                // Remove the old image only after a successful update.
                if (
                    $uploadedPath !== null &&
                    !empty($product["image"]) &&
                    $product["image"] !== $imageName
                ) {
                    $oldPath = "../images/" . basename($product["image"]);
                    if (is_file($oldPath)) {
                        unlink($oldPath);
                    }
                }

                header("Location: products.php");
                exit;

            } catch (PDOException $e) {
                if ($uploadedPath !== null && is_file($uploadedPath)) {
                    unlink($uploadedPath);
                }
                $error = "Could not update the product. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Product - DeeHandMade</title>
    <link rel="stylesheet" href="../css/style.css">
	<link rel="stylesheet" href="../css/adminProductsStyle.css">
    <style>
        .form-container { max-width: 650px; margin: 30px auto; padding: 25px; background: white; border-radius: 10px; }
        .form-container label { display: block; margin-top: 15px; }
        .form-container input, .form-container textarea, .form-container select {
            width: 100%; padding: 10px; margin-top: 5px; box-sizing: border-box;
        }
        .form-container button { margin-top: 20px; padding: 12px 18px; background: #6b3f2a; color: white; border: 0; border-radius: 5px; cursor: pointer; }
        .current-image { width: 120px; height: 120px; object-fit: cover; }
        .error { margin-top: 15px; }
    </style>
</head>
<body>

<header>
    <h1>DeeHandMade Admin</h1>
    <nav>
        <a href="index.php">Dashboard</a>
        <a href="products.php">Products</a>
        <a href="stock.php">Stock</a>
        <a href="orders.php">Orders</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<main class="form-container">
    <h2>Edit Product</h2>

    <?php if ($error !== ""): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data">
        <label for="name">Product Name</label>
        <input id="name" type="text" name="name" required maxlength="150"
               value="<?= htmlspecialchars($_POST["name"] ?? $product["name"]) ?>">

        <label for="category">Category</label>
        <select id="category" name="category" required>
            <?php foreach ($categories as $cat): ?>
                <option value="<?= htmlspecialchars($cat) ?>"
                    <?= (($_POST["category"] ?? $product["category"]) === $cat) ? "selected" : "" ?>>
                    <?= htmlspecialchars($cat) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <label for="description">Description</label>
        <textarea id="description" name="description" rows="4"><?= htmlspecialchars($_POST["description"] ?? $product["description"]) ?></textarea>

        <label for="price">Price (R)</label>
        <input id="price" type="number" name="price" min="0" max="99999999.99"
               step="0.01" required
               value="<?= htmlspecialchars($_POST["price"] ?? $product["price"]) ?>">

        <label for="stock">Stock Quantity</label>
        <input id="stock" type="number" name="stock" min="0" step="1" required
               value="<?= htmlspecialchars($_POST["stock"] ?? $product["stock"]) ?>">

        <label>Current Image</label>
        <?php if (!empty($product["image"])): ?>
            <p>
                <img class="current-image"
                     src="../images/<?= htmlspecialchars($product["image"]) ?>"
                     alt="Current product image">
            </p>
        <?php else: ?>
            <p>No image uploaded.</p>
        <?php endif; ?>

        <label for="image">Replace Image (optional)</label>
        <input id="image" type="file" name="image" accept=".jpg,.jpeg,.png,.webp">

        <button type="submit">Save Changes</button>
    </form>

    <p><a href="products.php">Back to Products</a></p>
</main>

</body>
</html>