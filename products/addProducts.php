
<?php

require_once "../includes/authentication.php";
require_once "../includes/database.php";

requireAdmin();

$error = "";
$categories = ["Teas", "Soaps", "Oils"];

// Absolute path to the images folder.
// __DIR__ is the current "products" folder.
// dirname(__DIR__) is the main "deeHandMade" folder.
$imageFolder = dirname(__DIR__) . DIRECTORY_SEPARATOR . "images";

// Create the images folder if it does not exist.
if (!is_dir($imageFolder)) {
    if (!mkdir($imageFolder, 0755, true) && !is_dir($imageFolder)) {
        $error = "The images folder could not be created.";
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $error === "") {

    $name = trim($_POST["name"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $price = trim($_POST["price"] ?? "");
    $stock = trim($_POST["stock"] ?? "");
    $category = trim($_POST["category"] ?? "");

    if ($name === "") {
        $error = "Please enter a product name.";

    } elseif (
        $price === "" ||
        !is_numeric($price) ||
        !is_finite((float)$price) ||
        (float)$price < 0 ||
        (float)$price > 99999999.99
    ) {
        $error = "Please enter a valid price.";

    } elseif (
        $stock === "" ||
        !preg_match('/^\d+$/', $stock) ||
        (float)$stock > 2147483647
    ) {
        $error = "Please enter a valid stock quantity.";

    } elseif (!in_array($category, $categories, true)) {
        $error = "Please select a valid category.";
    }

    // This variable will contain only the image filename.
    $imageName = null;
    $uploadedPath = null;

    // Process the optional image upload.
    if (
        $error === "" &&
        isset($_FILES["image"]) &&
        $_FILES["image"]["error"] !== UPLOAD_ERR_NO_FILE
    ) {
        if ($_FILES["image"]["error"] !== UPLOAD_ERR_OK) {
            $error = "There was a problem uploading the image.";

        } elseif ($_FILES["image"]["size"] > 5 * 1024 * 1024) {
            $error = "The image must be no larger than 5 MB.";

        } else {
            $temporaryPath = $_FILES["image"]["tmp_name"];
            $imageInfo = @getimagesize($temporaryPath);
            $mimeType = $imageInfo["mime"] ?? "";

            $allowedTypes = [
                "image/jpeg" => "jpg",
                "image/png"  => "png",
                "image/webp" => "webp"
            ];

            if (
                !$imageInfo ||
                !isset($allowedTypes[$mimeType]) ||
                !is_uploaded_file($temporaryPath)
            ) {
                $error = "Please upload a valid JPG, PNG, or WEBP image.";

            } else {
                // Generate a unique filename.
                $extension = $allowedTypes[$mimeType];
                $imageName = uniqid("product_", true) . "." . $extension;

                // Full filesystem path used to save the file.
                $uploadedPath = $imageFolder
                    . DIRECTORY_SEPARATOR . $imageName;

                if (!move_uploaded_file($temporaryPath, $uploadedPath)) {
                    $error = "Could not save the image. Check folder permissions.";
                    $imageName = null;
                    $uploadedPath = null;
                }
            }
        }
    }

    // Save the product details to MySQL.
    if ($error === "") {
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO products
                    (name, description, price, image, stock, category, active)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $name,
                $description,
                number_format((float)$price, 2, ".", ""),
                $imageName,
                (int)$stock,
                $category,
                1
            ]);

            // Return to the product list after a successful insert.
            header("Location: products.php");
            exit;

        } catch (PDOException $e) {
            // If the database insert fails, remove the uploaded image.
            if ($uploadedPath !== null && is_file($uploadedPath)) {
                unlink($uploadedPath);
            }

            $error = "Could not add the product. Please check the database and try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Add Product | DeeHandMade</title>
    <link rel="stylesheet" href="../css/style.css">

    <style>
        .product-form-container {
            max-width: 600px;
            width: 92%;
            margin: 40px auto;
            padding: 30px;
            background: white;
            border-radius: 10px;
            box-shadow: 0 3px 10px rgba(0,0,0,0.1);
        }

        .product-form-container label {
            display: block;
            margin-top: 16px;
            margin-bottom: 6px;
            font-weight: bold;
        }

        .product-form-container input,
        .product-form-container textarea,
        .product-form-container select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font: inherit;
            box-sizing: border-box;
        }

        .product-form-container button {
            width: 100%;
            margin-top: 22px;
            padding: 13px;
            background: #6b3f2a;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }

        .product-form-container button:hover {
            background: #4e2d1f;
        }

        .form-note {
            font-size: 13px;
            color: #666;
        }

        .active-note {
            padding: 12px;
            margin-top: 18px;
            background: #e9eee2;
            border-radius: 5px;
        }
    </style>
</head>

<body>

<header>
    <h1>DeeHandMade Admin</h1>

    <nav>
        <a href="../main.php">Dashboard</a>
        <a href="products.php">Products</a>
        <a href="addProducts.php">Add Product</a>
        <a href="../logout.php">Logout</a>
    </nav>
</header>

<main>
    <div class="product-form-container">
        <h2>Add New Product</h2>
        <p>Add a tea, handmade soap, or oil to your store.</p>

        <?php if ($error !== ""): ?>
            <div class="error">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST"
              action="addProducts.php"
              enctype="multipart/form-data">

            <label for="name">Product Name</label>
            <input type="text" id="name" name="name"
                   maxlength="150"
                   value="<?= htmlspecialchars($_POST["name"] ?? "") ?>"
                   required>

            <label for="category">Category</label>
            <select id="category" name="category" required>
                <option value="">Select a category</option>

                <?php foreach ($categories as $cat): ?>
                    <option value="<?= htmlspecialchars($cat) ?>"
                        <?= (($_POST["category"] ?? "") === $cat)
                            ? "selected" : "" ?>>
                        <?= htmlspecialchars($cat) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="description">Product Description</label>
            <textarea id="description" name="description"
                      rows="4"><?= htmlspecialchars($_POST["description"] ?? "") ?></textarea>

            <label for="price">Price (R)</label>
            <input type="number" id="price" name="price"
                   min="0" max="99999999.99" step="0.01"
                   value="<?= htmlspecialchars($_POST["price"] ?? "") ?>"
                   required>

            <label for="stock">Stock Quantity</label>
            <input type="number" id="stock" name="stock"
                   min="0" max="2147483647" step="1"
                   value="<?= htmlspecialchars($_POST["stock"] ?? "") ?>"
                   required>

            <label for="image">Product Image (Optional)</label>
            <input type="file" id="image" name="image"
                   accept=".jpg,.jpeg,.png,.webp">

            <p class="form-note">
                Accepted formats: JPG, PNG and WEBP. Maximum size: 5 MB.
            </p>

            <div class="active-note">
                <strong>Product status: Active</strong>
                <p>New products are active by default and can appear in the
                   customer store when they have stock.</p>
            </div>

            <button type="submit">Add Product</button>
        </form>

        <p style="text-align:center; margin-top:20px;">
            <a href="products.php">Back to Products</a>
        </p>
    </div>
</main>

</body>
</html>



