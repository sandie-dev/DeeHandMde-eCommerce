<?php

require_once "includes/authentication.php";

requireAdmin();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Admin Dashboard - DeeHandMade</title>

    <link rel="stylesheet" href="css/style.css">

</head>

<body>

<header>

    <h1>DeeHandMade Admin</h1>

    <nav>

        <a href="admin.php">Dashboard</a>

        <a href="products/products.php">Products</a>

        <a href="products/stock.php">Stock</a>

        <a href="products/orders.php">Orders</a>

        <a href="logout.php">Logout</a>

    </nav>

</header>

<main>

    <h2>
        Welcome,
        <?php echo htmlspecialchars($_SESSION["name"]); ?>!
    </h2>

    <p>
        You are logged in as an administrator.
    </p>

    <div class="admin-options">

        <div>
            <h3>Products</h3>
            <p>Add, edit and delete products.</p>
        </div>

        <div>
            <h3>Stock</h3>
            <p>Manage product stock.</p>
        </div>

        <div>
            <h3>Orders</h3>
            <p>View customer orders.</p>
        </div>

    </div>

</main>

</body>

</html>