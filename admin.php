
<?php
require_once "includes/authentication.php";
require_once "includes/database.php";

requireAdmin();

// Successful orders are those whose payment has been confirmed.
$successfulStatuses = [
    "Paid",
    "Processing",
    "Shipped",
    "Delivered"
];

$placeholders = implode(
    ",",
    array_fill(0, count($successfulStatuses), "?")
);

// 1. Total revenue
$stmt = $pdo->prepare(
    "SELECT COALESCE(SUM(total), 0)
     FROM orders
     WHERE status IN ($placeholders)"
);
$stmt->execute($successfulStatuses);
$totalRevenue = (float)$stmt->fetchColumn();

// 2. Successful purchases
$stmt = $pdo->prepare(
    "SELECT COUNT(*)
     FROM orders
     WHERE status IN ($placeholders)"
);
$stmt->execute($successfulStatuses);
$successfulPurchases = (int)$stmt->fetchColumn();

// 3. Successful deliveries
$stmt = $pdo->query(
    "SELECT COUNT(*)
     FROM orders
     WHERE status = 'Delivered'"
);
$successfulDeliveries = (int)$stmt->fetchColumn();

// 4. Registered customers
$stmt = $pdo->query(
    "SELECT COUNT(*)
     FROM users
     WHERE role = 'customer'"
);
$registeredCustomers = (int)$stmt->fetchColumn();

// 5. Total customer reviews
$stmt = $pdo->query(
    "SELECT COUNT(*) FROM reviews"
);
$totalReviews = (int)$stmt->fetchColumn();

// Approved reviews
$stmt = $pdo->query(
    "SELECT COUNT(*)
     FROM reviews
     WHERE status = 'Approved'"
);
$approvedReviews = (int)$stmt->fetchColumn();

// 6. Pending orders
$stmt = $pdo->query(
    "SELECT COUNT(*)
     FROM orders
     WHERE status = 'Pending'"
);
$pendingOrders = (int)$stmt->fetchColumn();

// 7. Customer favourite products
// Ranked by the total quantity sold in successfully paid orders.
$stmt = $pdo->prepare(
    "SELECT
        products.id,
        products.name,
        SUM(order_items.quantity) AS quantity_sold,
        SUM(order_items.quantity * order_items.price) AS sales_value
     FROM order_items
     INNER JOIN orders
        ON order_items.order_id = orders.id
     INNER JOIN products
        ON order_items.product_id = products.id
     WHERE orders.status IN ($placeholders)
     GROUP BY products.id, products.name
     ORDER BY quantity_sold DESC
     LIMIT 5"
);
$stmt->execute($successfulStatuses);
$favouriteProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 8. Monthly sales trends - last six months
$startDate = new DateTimeImmutable("first day of this month");
$startDate = $startDate->modify("-5 months");

$chartStartDate = $startDate->format("Y-m-01");

// Prepare six months with zero values.
// This ensures months without sales still appear.
$monthlySales = [];

for ($i = 0; $i < 6; $i++) {

    $month = $startDate->modify("+$i months");

    $key = $month->format("Y-m");

    $monthlySales[$key] = [
        "label" => $month->format("M Y"),
        "revenue" => 0
    ];
}

$stmt = $pdo->prepare(
    "SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS sale_month,
        SUM(total) AS monthly_revenue
     FROM orders
     WHERE status IN ($placeholders)
       AND created_at >= ?
     GROUP BY DATE_FORMAT(created_at, '%Y-%m')
     ORDER BY sale_month ASC"
);

$monthlyParams = $successfulStatuses;
$monthlyParams[] = $chartStartDate;

$stmt->execute($monthlyParams);

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {

    $key = $row["sale_month"];

    if (isset($monthlySales[$key])) {
        $monthlySales[$key]["revenue"] =
            (float)$row["monthly_revenue"];
    }
}

$monthlySales = array_values($monthlySales);

// Find the highest monthly revenue for chart scaling.
$maxMonthlyRevenue = 0;

foreach ($monthlySales as $month) {
    if ($month["revenue"] > $maxMonthlyRevenue) {
        $maxMonthlyRevenue = $month["revenue"];
    }
}

// 9. Recent orders
$stmt = $pdo->query(
    "SELECT
        orders.id,
        orders.total,
        orders.status,
        orders.created_at,
        users.name AS customer_name
     FROM orders
     INNER JOIN users
        ON orders.user_id = users.id
     ORDER BY orders.created_at DESC
     LIMIT 5"
);

$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Helper for safely displaying text.
function dashboardEscape($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Admin Dashboard - DeeHandMade</title>

<link rel="stylesheet" href="css/style.css">

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
}

.dashboard-container {
    max-width: 1250px;
    margin: 30px auto;
    padding: 20px;
}

.welcome-section {
    margin-bottom: 30px;
}

.welcome-section h2 {
    margin-bottom: 8px;
}

/* Dashboard metric cards */

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin: 25px 0;
}

.stat-card {
    background: white;
    padding: 22px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 5px solid #98745D;
}

.stat-card h3 {
    font-size: 15px;
    color: #666;
    margin: 0 0 12px;
}

.stat-card .stat-value {
    font-size: 27px;
    font-weight: bold;
    color: #4b3024;
    margin: 0;
}

/* Dashboard sections */

.dashboard-section {
    background: white;
    padding: 22px;
    margin: 25px 0;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.dashboard-section h3 {
    margin-top: 0;
    color: #4b3024;
}

.dashboard-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
}

/* Monthly sales chart */

.chart-container {
    display: flex;
    align-items: flex-end;
    justify-content: space-around;
    gap: 15px;
    min-height: 230px;
    padding: 20px 10px 0;
    border-bottom: 1px solid #ddd;
}

.chart-column {
    flex: 1;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    align-items: center;
    height: 200px;
    min-width: 0;
}

.chart-value {
    font-size: 12px;
    margin-bottom: 7px;
    text-align: center;
    overflow-wrap: anywhere;
}

.chart-bar {
    width: 70%;
    max-width: 65px;
    min-height: 3px;
    background: #98745D;
    border-radius: 6px 6px 0 0;
}

.chart-label {
    font-size: 12px;
    margin-top: 9px;
    text-align: center;
}

/* Tables */

.table-wrapper {
    width: 100%;
    overflow-x: auto;
}

.dashboard-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 450px;
}

.dashboard-table th,
.dashboard-table td {
    padding: 12px;
    border-bottom: 1px solid #eee;
    text-align: left;
}

.dashboard-table th {
    background: #f5f0ec;
    color: #4b3024;
}

.status-label {
    display: inline-block;
    padding: 5px 9px;
    border-radius: 5px;
    background: #eee;
    font-size: 12px;
}

/* Navigation links */

.admin-options {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 20px;
}

.admin-options a {
    display: inline-block;
    background: #6b3f2a;
    color: white;
    text-decoration: none;
    padding: 11px 18px;
    border-radius: 6px;
}

.admin-options a:hover {
    background: #4e2c1d;
}

.empty-message {
    color: #777;
    padding: 12px 0;
}

@media (max-width: 850px) {

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .dashboard-grid {
        grid-template-columns: 1fr;
    }

}

@media (max-width: 500px) {

    .dashboard-container {
        padding: 12px;
    }

    .stats-grid {
        grid-template-columns: 1fr;
    }

    .stat-card .stat-value {
        font-size: 24px;
    }

    .dashboard-section {
        padding: 15px;
    }

    .chart-container {
        gap: 5px;
    }

    .chart-value,
    .chart-label {
        font-size: 10px;
    }

}

</style>

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


<main class="dashboard-container">

    <!-- Welcome section -->

    <section class="welcome-section">

        <h2>
            Welcome,
            <?= dashboardEscape($_SESSION["name"]) ?>!
        </h2>

        <p>
            Here is an overview of your DeeHandMade online store.
        </p>

    </section>


    <!-- Dashboard statistics -->

    <section class="stats-grid">

        <div class="stat-card">

            <h3>Total Revenue</h3>

            <p class="stat-value">
                R<?= number_format($totalRevenue, 2) ?>
            </p>

        </div>


        <div class="stat-card">

            <h3>Successful Purchases</h3>

            <p class="stat-value">
                <?= number_format($successfulPurchases) ?>
            </p>

        </div>


        <div class="stat-card">

            <h3>Successful Deliveries</h3>

            <p class="stat-value">
                <?= number_format($successfulDeliveries) ?>
            </p>

        </div>


        <div class="stat-card">

            <h3>Registered Customers</h3>

            <p class="stat-value">
                <?= number_format($registeredCustomers) ?>
            </p>

        </div>


        <div class="stat-card">

            <h3>Customer Reviews</h3>

            <p class="stat-value">
                <?= number_format($totalReviews) ?>
            </p>

            <small>
                <?= number_format($approvedReviews) ?> approved
            </small>

        </div>


        <div class="stat-card">

            <h3>Pending Orders</h3>

            <p class="stat-value">
                <?= number_format($pendingOrders) ?>
            </p>

        </div>

    </section>


    <!-- Monthly sales chart -->

    <section class="dashboard-section">

        <h3>Monthly Sales Trends</h3>

        <p>
            Revenue from successfully paid orders over the last six months.
        </p>

        <div class="chart-container">

            <?php foreach ($monthlySales as $month): ?>

                <?php

                if ($maxMonthlyRevenue > 0) {

                    $barHeight =
                        ($month["revenue"] / $maxMonthlyRevenue) * 160;

                } else {

                    $barHeight = 3;

                }

                ?>

                <div class="chart-column">

                    <span class="chart-value">
                        R<?= number_format($month["revenue"], 0) ?>
                    </span>

                    <div
                        class="chart-bar"
                        style="height: <?= max(3, $barHeight) ?>px;"
                        title="Revenue: R<?= number_format($month["revenue"], 2) ?>"
                    ></div>

                    <span class="chart-label">
                        <?= dashboardEscape($month["label"]) ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>

    </section>


    <!-- Favourite products and review summary -->

    <section class="dashboard-grid">

        <div class="dashboard-section">

            <h3>Customer Favourite Products</h3>

            <p>
                Top five products ranked by quantity sold.
            </p>

            <?php if (count($favouriteProducts) > 0): ?>

                <div class="table-wrapper">

                    <table class="dashboard-table">

                        <thead>

                            <tr>
                                <th>Product</th>
                                <th>Quantity Sold</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php foreach ($favouriteProducts as $product): ?>

                                <tr>

                                    <td>
                                        <?= dashboardEscape($product["name"]) ?>
                                    </td>

                                    <td>
                                        <?= (int)$product["quantity_sold"] ?>
                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <p class="empty-message">
                    No successful purchases recorded yet.
                </p>

            <?php endif; ?>

        </div>


        <div class="dashboard-section">

            <h3>Customer Reviews</h3>

            <p>
                Overview of customer feedback.
            </p>

            <p>
                <strong>Total reviews:</strong>
                <?= number_format($totalReviews) ?>
            </p>

            <p>
                <strong>Approved reviews:</strong>
                <?= number_format($approvedReviews) ?>
            </p>

            <p>
                <strong>Pending reviews:</strong>
                <?= number_format($totalReviews - $approvedReviews) ?>
            </p>

            <a href="products/products.php">
                Manage Products
            </a>

        </div>

    </section>


    <!-- Recent orders -->

    <section class="dashboard-section">

        <h3>Recent Customer Orders</h3>

        <?php if (count($recentOrders) > 0): ?>

            <div class="table-wrapper">

                <table class="dashboard-table">

                    <thead>

                        <tr>
                            <th>Order Number</th>
                            <th>Customer</th>
                            <th>Date</th>
                            <th>Total</th>
                            <th>Status</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($recentOrders as $order): ?>

                            <tr>

                                <td>
                                    #<?= (int)$order["id"] ?>
                                </td>

                                <td>
                                    <?= dashboardEscape($order["customer_name"]) ?>
                                </td>

                                <td>
                                    <?= dashboardEscape($order["created_at"]) ?>
                                </td>

                                <td>
                                    R<?= number_format((float)$order["total"], 2) ?>
                                </td>

                                <td>
                                    <span class="status-label">
                                        <?= dashboardEscape($order["status"]) ?>
                                    </span>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <p class="empty-message">
                No customer orders are available.
            </p>

        <?php endif; ?>

        <div class="admin-options">

            <a href="products/orders.php">
                View All Orders
            </a>

            <a href="products/products.php">
                Manage Products
            </a>

            <a href="products/stock.php">
                Manage Stock
            </a>

        </div>

    </section>

</main>

</body>

</html>
