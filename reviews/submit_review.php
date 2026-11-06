<?php
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/authentication.php';

// Only logged-in customers may submit reviews.
requireLogin();
requireCustomer();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../main.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$productId = filter_input(INPUT_POST, 'product_id', FILTER_VALIDATE_INT);
$rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
$comment = trim($_POST['comment'] ?? '');
$csrfToken = $_POST['csrf_token'] ?? '';

// Check CSRF token.
if (
    !isset($_SESSION['csrf_token']) ||
    !is_string($csrfToken) ||
    !hash_equals($_SESSION['csrf_token'], $csrfToken)
) {
    header('Location: ../main.php?review=invalid_token');
    exit;
}

// Validate submitted values.
if (
    !$productId ||
    $productId < 1 ||
    !$rating ||
    $rating < 1 ||
    $rating > 5 ||
    $comment === '' ||
    mb_strlen($comment) > 2000
) {
    header('Location: ../main.php?review=invalid_input');
    exit;
}

try {
    // Confirm that this customer purchased this product
    // in a successfully paid order.
    $purchaseQuery = $pdo->prepare("
        SELECT 1
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        WHERE o.user_id = :user_id
          AND oi.product_id = :product_id
          AND o.status = 'Paid'
          AND o.payment_reference IS NOT NULL
          AND o.payment_reference <> ''
        LIMIT 1
    ");

    $purchaseQuery->execute([
        'user_id' => $userId,
        'product_id' => $productId
    ]);

    if (!$purchaseQuery->fetchColumn()) {
        header('Location: ../main.php?review=not_eligible');
        exit;
    }

    // Save the review for admin moderation.
    $insertQuery = $pdo->prepare("
        INSERT INTO reviews
            (user_id, product_id, rating, comment, status)
        VALUES
            (:user_id, :product_id, :rating, :comment, 'Approved')
    ");

    $insertQuery->execute([
        'user_id' => $userId,
        'product_id' => $productId,
        'rating' => $rating,
        'comment' => $comment
    ]);

    header('Location: ../main.php?review=submitted#product-' . $productId);
    exit;

} catch (PDOException $e) {
    // SQLSTATE 23000 covers duplicate-key and other
    // integrity constraint violations.
    if ($e->getCode() === '23000') {
        header('Location: ../main.php?review=already_submitted');
        exit;
    }

    error_log('Review submission error: ' . $e->getMessage());
    header('Location: ../main.php?review=error');
    exit;
}
