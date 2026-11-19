<?php
require_once "../includes/authentication.php";
require_once "../includes/database.php";

requireAdmin();

// Only allow POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: products.php");
    exit;
}

// Verify CSRF token
$token = $_POST["csrf_token"] ?? "";

if (
    empty($_SESSION["product_status_token"]) ||
    !hash_equals($_SESSION["product_status_token"], $token)
) {
    die("Invalid request. Please return to the products page and try again.");
}

// Get product ID and requested action
$productId = filter_input(INPUT_POST, "product_id", FILTER_VALIDATE_INT);
$action = $_POST["action"] ?? "";

if (!$productId || !in_array($action, ["activate", "deactivate"], true)) {
    header("Location: products.php");
    exit;
}

// Set the new product status
$newStatus = ($action === "activate") ? 1 : 0;

// Update product
$stmt = $pdo->prepare("
    UPDATE products
    SET active = :active
    WHERE id = :id
");

$stmt->execute([
    "active" => $newStatus,
    "id" => $productId
]);

header("Location: products.php");
exit;
