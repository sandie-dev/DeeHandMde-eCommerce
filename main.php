<?php
require_once "includes/authentication.php";
require_once "includes/database.php";

// Guests can browse without logging in.
// authentication.php starts the session.

// Initialise session cart
if (!isset($_SESSION["cart"])) {
    $_SESSION["cart"] = [];
}

// Review CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

//Cart CSRF token
if (empty($_SESSION["cart_token"])) {
    $_SESSION["cart_token"] = bin2hex(random_bytes(32));
}

$cartCount = array_sum($_SESSION["cart"]);

// Search
$search = trim($_GET["search"] ?? "");

// Fetch products
if ($search !== "") {

    $sql = "SELECT * FROM products
            WHERE active = 1
            AND stock > 0
            AND (
                name LIKE :search
                OR description LIKE :search
                OR category LIKE :search
            )
            ORDER BY created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        "search" => "%" . $search . "%"
    ]);

} else {

    $sql = "SELECT * FROM products
            WHERE active = 1
            AND stock > 0
            ORDER BY created_at DESC";

    $stmt = $pdo->query($sql);
}

$allProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Organise products by category
$teas = [];
$soaps = [];
$oils = [];

foreach ($allProducts as $product) {

    if ($product["category"] === "Teas") {
        $teas[] = $product;
    }

    if ($product["category"] === "Soaps") {
        $soaps[] = $product;
    }

    if ($product["category"] === "Oils") {
        $oils[] = $product;
    }
}

// Function to display products
function displayProducts($products)
{
	global $pdo;
    foreach ($products as $product):

        $id = (int)$product["id"];
        $stock = (int)$product["stock"];
        $price = (float)$product["price"];
		
		
        // Getting average rating and reviews
        $ratingQuery = $pdo->prepare("
            SELECT
                AVG(rating) AS average_rating,
                COUNT(*) AS review_count
            FROM reviews
            WHERE product_id = :product_id
              AND status = 'Approved'
        ");

        $ratingQuery->execute([
            'product_id' => $id
        ]);

        $ratingData = $ratingQuery->fetch(PDO::FETCH_ASSOC);

        $averageRating = (float)($ratingData['average_rating'] ?? 0);
        $reviewCount = (int)($ratingData['review_count'] ?? 0);

        // Get the 3 most recent approved reviews
        $reviewsQuery = $pdo->prepare("
            SELECT
                r.rating,
                r.comment,
                r.created_at,
                u.name
            FROM reviews r
            INNER JOIN users u ON u.id = r.user_id
            WHERE r.product_id = :product_id
              AND r.status = 'Approved'
            ORDER BY r.created_at DESC, r.id DESC
            LIMIT 3
        ");

        $reviewsQuery->execute([
            'product_id' => $id
        ]);

        $approvedReviews = $reviewsQuery->fetchAll(PDO::FETCH_ASSOC);

		

        $inCart = isset($_SESSION["cart"][$id]);
        $quantity = $inCart
            ? (int)$_SESSION["cart"][$id]
            : 0;

        // Do not allow the displayed quantity to exceed stock
        $quantity = min($quantity, $stock);
        $image = !empty($product["image"])
            ? "images/" . $product["image"]
            : "images/placeholder.jpg";
			
		
        // Check whether the logged-in customer has reviewed this product
        $hasReviewed = false;

        if (
            isset($_SESSION['user_id']) &&
            ($_SESSION['role'] ?? '') === 'customer'
        ) {
            $existingReviewQuery = $pdo->prepare("
                SELECT 1
                FROM reviews
                WHERE user_id = :user_id
                  AND product_id = :product_id
                LIMIT 1
            ");

            $existingReviewQuery->execute([
                'user_id' => (int) $_SESSION['user_id'],
                'product_id' => $id
            ]);

            $hasReviewed = (bool) $existingReviewQuery->fetchColumn();
        }
	
?>

    <article class="product-card" id= "product-<?php echo $id;?>" >

        <div class="product-image">
            <img
                src="<?php echo htmlspecialchars($image); ?>"
                alt="<?php echo htmlspecialchars($product["name"]); ?>"
                loading="lazy"
            >
        </div>

        <div class="product-info">

            <h3>
                <?php echo htmlspecialchars($product["name"]); ?>
            </h3>

            <p class="product-description">
                <?php echo htmlspecialchars($product["description"]); ?>
            </p>

            <p class="product-price">
                R<?php echo number_format($price, 2); ?>
            </p>

            <p class="stock-info">
                <?php echo $stock; ?> available
            </p>

            <?php if ($inCart && $quantity > 0): ?>

                <!-- Item already in cart -->
                <div class="quantity-controller">

                    <form action="cart.php" method="POST">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION["cart_token"]); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="product_id"
                            value="<?php echo $id; ?>">
                        <input type="hidden" name="quantity"
                            value="<?php echo max(0, $quantity - 1); ?>">

                        <button type="submit"
                            class="quantity-btn"
                            aria-label="Decrease quantity">
                            −
                        </button>
                    </form>

                    <span class="quantity-number">
                        <?php echo $quantity; ?>
                    </span>

                    <form action="cart.php" method="POST">
                        <input type="hidden" name="csrf_token"
                            value="<?php echo htmlspecialchars($_SESSION["cart_token"]); ?>">
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="product_id"
                            value="<?php echo $id; ?>">
                        <input type="hidden" name="quantity"
                            value="<?php echo min($stock, $quantity + 1); ?>">

                        <button type="submit"
                            class="quantity-btn"
                            aria-label="Increase quantity"
                            <?php echo ($quantity >= $stock) ? "disabled" : ""; ?>>
                            +
                        </button>
                    </form>

                </div>

                <p class="added-message">
                    Added to your cart
                </p>

            <?php else: ?>

                <!-- Add item with quantity 1 -->
                <form action="cart.php" method="POST">

                    <input type="hidden" name="csrf_token"
                        value="<?php echo htmlspecialchars($_SESSION["cart_token"]); ?>">

                    <input type="hidden" name="action" value="add">

                    <input type="hidden" name="product_id"
                        value="<?php echo $id; ?>">

                    <input type="hidden" name="quantity" value="1">
					<input type="hidden" name="return_to" value="main.php#product-<?php echo $id; ?>">

                    <button type="submit" class="add-cart-btn">
                        Add to Cart
                    </button>

                </form>

            <?php endif; ?>
			
			
            <!-- Product review section -->
           
            
            <div class="product-review-section">

                <h4>Customer Reviews</h4>

                <!-- Average rating and total approved reviews -->
                <?php if ($reviewCount > 0): ?>

                    <p class="review-summary">
                        <strong>
                            <?php echo number_format($averageRating, 1); ?>/5
                        </strong>

                        <span aria-label="<?php echo number_format($averageRating, 1); ?> out of 5 stars">
                            <?php
                            $fullStars = (int) round($averageRating);

                            echo str_repeat('★', $fullStars);
                            echo str_repeat('☆', 5 - $fullStars);
                            ?>
                        </span>

                        (<?php echo $reviewCount; ?>
                        <?php echo $reviewCount === 1 ? 'review' : 'reviews'; ?>)
                    </p>

                <?php else: ?>

                    <p class="no-reviews">
                        No reviews yet. Be the first to review!
                    </p>

                <?php endif; ?>


                <!-- Display latest approved reviews -->
                <?php if (!empty($approvedReviews)): ?>

                    <div class="approved-reviews">

                        <?php foreach ($approvedReviews as $review): ?>

                            <article class="customer-review">

                                <p class="review-author">
                                    <strong>
                                        <?php
                                        echo htmlspecialchars(
                                            $review['name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        );
                                        ?>
                                    </strong>
                                </p>

                                <p class="review-stars"
                                   aria-label="<?php echo (int) $review['rating']; ?> out of 5 stars">

                                    <?php
                                    $reviewRating = (int) $review['rating'];

                                    echo str_repeat('★', $reviewRating);
                                    echo str_repeat('☆', 5 - $reviewRating);
                                    ?>

                                </p>

                                <p class="review-comment">
                                    <?php
                                    echo nl2br(
                                        htmlspecialchars(
                                            $review['comment'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        )
                                    );
                                    ?>
                                </p>

                                <small class="review-date">
                                    <?php
                                    echo htmlspecialchars(
                                        date(
                                            'd M Y',
                                            strtotime($review['created_at'])
                                        ),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    );
                                    ?>
                                </small>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>


                <!-- Customer review form -->
                
            <!-- Product review section -->
            <div class="product-review-section">

                <h4>Customer Reviews</h4>

                <!-- Average rating -->
                <?php if ($reviewCount > 0): ?>

                    <p class="review-summary">
                        <strong>
                            <?php echo number_format($averageRating, 1); ?>/5
                        </strong>

                        <span>
                            <?php
                            $fullStars = (int) round($averageRating);
                            echo str_repeat('★', $fullStars);
                            echo str_repeat('☆', 5 - $fullStars);
                            ?>
                        </span>

                        (<?php echo $reviewCount; ?>
                        <?php echo $reviewCount === 1 ? 'review' : 'reviews'; ?>)
                    </p>

                <?php else: ?>

                    <p class="no-reviews">
                        No reviews yet. Be the first to review!
                    </p>

                <?php endif; ?>


                <!-- Show approved reviews -->
                <?php if (!empty($approvedReviews)): ?>

                    <div class="approved-reviews">

                        <?php foreach ($approvedReviews as $review): ?>

                            <article class="customer-review">

                                <p class="review-author">
                                    <strong>
                                        <?php echo htmlspecialchars(
                                            $review['name'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>
                                    </strong>
                                </p>

                                <p class="review-stars">
                                    <?php
                                    $reviewRating = (int) $review['rating'];
                                    echo str_repeat('★', $reviewRating);
                                    echo str_repeat('☆', 5 - $reviewRating);
                                    ?>
                                </p>

                                <p class="review-comment">
                                    <?php echo nl2br(htmlspecialchars(
                                        $review['comment'],
                                        ENT_QUOTES,
                                        'UTF-8'
                                    )); ?>
                                </p>

                                <small class="review-date">
                                    <?php echo htmlspecialchars(
                                        date('d M Y', strtotime($review['created_at'])),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ); ?>
                                </small>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>


                <!-- Review action -->
                <?php if (
                    isset($_SESSION['user_id']) &&
                    ($_SESSION['role'] ?? '') === 'customer'
                ): ?>

                    <?php if ($hasReviewed): ?>

                        <p class="already-reviewed">
                            You've reviewed this product.
                        </p>

                    <?php else: ?>

                        <details class="review-toggle">

                            <summary>Leave a Review</summary>

                            <div class="review-form-container">

                                <p class="review-prompt">
                                    Purchased this product?
                                    Share your experience below.
                                </p>

                                <form
                                    action="reviews/submit_review.php"
                                    method="POST"
                                    class="review-form"
                                >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?php echo htmlspecialchars(
                                            $_SESSION['csrf_token'],
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ); ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="product_id"
                                        value="<?php echo $id; ?>"
                                    >

                                    <label for="rating-<?php echo $id; ?>">
                                        Your rating
                                    </label>

                                    <select
                                        id="rating-<?php echo $id; ?>"
                                        name="rating"
                                        required
                                    >
                                        <option value="">Choose a rating</option>
                                        <option value="5">5 - Excellent</option>
                                        <option value="4">4 - Very good</option>
                                        <option value="3">3 - Good</option>
                                        <option value="2">2 - Fair</option>
                                        <option value="1">1 - Poor</option>
                                    </select>

                                    <label for="comment-<?php echo $id; ?>">
                                        Your review
                                    </label>

                                    <textarea
                                        id="comment-<?php echo $id; ?>"
                                        name="comment"
                                        rows="3"
                                        maxlength="2000"
                                        placeholder="Tell us about your experience..."
                                        required
                                    ></textarea>

                                    <button type="submit" class="add-cart-btn">
                                        Submit Review
                                    </button>

                                </form>

                            </div>

                        </details>

                    <?php endif; ?>

                <?php elseif (!isset($_SESSION['user_id'])): ?>

                    <p class="review-login">
                        <a href="login.php">Log in</a>
                        to give a review after purchasing this product.
                    </p>

                <?php endif; ?>

            </div>



			

        </div>

    </article>

<?php
    endforeach;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>DeeHandMade | Natural Handmade Products</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

       
		:root {
			--green: #4b3023;
			--light-green: #f5e9d9;
			--cream: #fffaf3;
			--text: #30261f;
			--muted: #7c746b;
			--border: #eadfce;
		}


        body {
            font-family: Arial, sans-serif;
            background: white;
            color: var(--text);
        }

        a {
            color: inherit;
            text-decoration: none;
        }

        /* NAVIGATION */

        .navbar {
            min-height: 76px;
            padding: 18px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            border-bottom: 1px solid var(--border);
        }

        .brand {
            font-family: Georgia, serif;
            font-size: 25px;
            font-weight: bold;
            color: var(--green);
            white-space: nowrap;
        }

        .nav-links {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 22px;
            font-size: 14px;
        }

        .nav-links a:hover {
            color: #8b6045;
        }

        .cart-link {
            font-weight: bold;
        }

        /* HERO */

        .hero {
            display: grid;
            grid-template-columns: 1fr 1fr;
            min-height: 340px;
        }

        .hero-logo {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px;
            background: white;
        }

        .hero-logo img {
            width: 100%;
            max-width: 420px;
            height: 310px;
            object-fit: contain;
        }

        .hero-content {
            background: var(--light-green);
            padding: 60px 10%;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .eyebrow {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: var(--green);
            margin-bottom: 20px;
        }

        .hero-content h1 {
            font-family: Georgia, serif;
            font-size: clamp(32px, 3.3vw, 46px);
            line-height: 1.08;
            font-weight: normal;
            margin-bottom: 20px;
            color: var(--green);
        }

        .hero-content p {
            font-size: 13px;
            line-height: 1.9;
            color: #786b60;
            margin-bottom: 16px;
        }

        .hero-tagline {
            font-size: 12px !important;
        }

        /* CATEGORY BANNERS */

        .essentials {
            padding: 55px 4% 35px;
        }

        .section-eyebrow {
            font-size: 10px;
            color: #6b3f2a;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 12px;
        }

        .section-heading {
            font-family: Georgia, serif;
            font-size: clamp(25px, 3vw, 36px);
            margin-bottom: 32px;
            color: var(--green);
        }

        .category-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 30px;
        }

        .category-card {
            display: block;
            overflow: hidden;
        }

        .category-card img {
            display: block;
            width: 100%;
            aspect-ratio: 1.55 / 1;
            object-fit: cover;
            transition: transform .3s ease;
        }

        .category-card:hover img {
            transform: scale(1.025);
        }

        .category-info {
            padding-top: 14px;
        }

        .category-title-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .category-title-row h3 {
            font-size: 20px;
            font-weight: normal;
        }

        .category-arrow {
            font-size: 18px;
        }

        .category-info p {
            font-size: 13px;
            margin-bottom: 7px;
        }

        .category-info small {
            font-size: 11px;
            color: var(--muted);
        }

        /* SEARCH */

        .shop-area {
            padding: 35px 4% 60px;
        }

        .shop-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 30px;
        }

        .shop-header h2 {
            font-family: Georgia, serif;
            font-size: 30px;
            color: var(--green);
        }

        .search-form {
            display: flex;
            gap: 8px;
        }

        .search-form input {
            width: 250px;
            max-width: 100%;
            padding: 12px;
            border: 1px solid #d9ddd4;
            border-radius: 3px;
        }

        .search-form button {
            padding: 12px 18px;
            border: none;
            background: var(--green);
            color: white;
            cursor: pointer;
        }

        .clear-search {
            font-size: 13px;
            align-self: center;
            color: var(--green);
        }

        /* PRODUCT SECTIONS */

        .product-section {
            padding: 30px 0 45px;
            scroll-margin-top: 25px;
        }

        .product-section h2 {
            font-family: Georgia, serif;
            font-size: 28px;
            color: var(--green);
            margin-bottom: 22px;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 24px;
        }

        .product-card {
            border: 1px solid var(--border);
            border-radius: 5px;
            overflow: hidden;
            background: white;
        }

        .product-image {
            background: var(--cream);
        }

        .product-image img {
            width: 100%;
            height: 210px;
            object-fit: cover;
            display: block;
        }

        .product-info {
            padding: 18px;
        }

        .product-info h3 {
            font-size: 17px;
            margin-bottom: 9px;
            font-weight: 600;
        }

        .product-description {
            font-size: 13px;
            line-height: 1.6;
            color: var(--muted);
            min-height: 40px;
            margin-bottom: 12px;
        }

        .product-price {
            font-size: 17px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .stock-info {
            color: var(--muted);
            font-size: 11px;
            margin-bottom: 14px;
        }

        .add-cart-btn {
            width: 100%;
            padding: 12px;
            border: 1px solid var(--green);
            background: var(--green);
            color: white;
            cursor: pointer;
            border-radius: 3px;
            font-size: 13px;
            transition: background .2s;
        }

        .add-cart-btn:hover {
            background: #6b3f2a;
        }

        /* QUANTITY CONTROL */

        .quantity-controller {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 25px;
            border: 1px solid #eadfce;
            border-radius: 4px;
            padding: 5px;
        }

        .quantity-controller form {
            margin: 0;
        }

        .quantity-btn {
            width: 35px;
            height: 35px;
            border: none;
            border-radius: 3px;
            background: var(--light-green);
            color: var(--green);
            font-size: 22px;
            cursor: pointer;
        }

        .quantity-btn:disabled {
            opacity: .4;
            cursor: not-allowed;
        }

        .quantity-number {
            font-weight: bold;
            font-size: 16px;
            min-width: 15px;
            text-align: center;
        }

        .added-message {
            text-align: center;
            color: var(--green);
            font-size: 11px;
            margin-top: 10px;
        }

        .empty-message {
            color: var(--muted);
            padding: 20px 0;
        }

        /* FOOTER */

        footer {
            background: var(--light-green);
            padding: 30px 5%;
            text-align: center;
            font-size: 12px;
            color: #786b60;
        }
		
		
		/* Product reviews spacing */
		.product-review-section {
			margin-top: 24px;
			padding-top: 18px;
			border-top: 1px solid #eadbce;
		}

		.product-review-section h4 {
			margin: 0 0 12px;
		}

		.product-review-section p {
			margin: 0 0 12px;
			line-height: 1.6;
		}

		.review-form {
			display: flex;
			flex-direction: column;
			gap: 10px;
		}

		.review-form label {
			margin-top: 4px;
		}

		.review-form select,
		.review-form textarea {
			width: 100%;
			box-sizing: border-box;
			font: inherit;
		}

		.review-form textarea {
			min-height: 100px;
			resize: vertical;
		}

		.review-form button {
			margin-top: 6px;
		}
		
			.review-notice {
			margin: 16px 0;
			padding: 14px 18px;
			border: 1px solid #eadbce;
			border-radius: 6px;
			background-color: #f8f1eb;
			color: #4b2e20;
			line-height: 1.5;
		}
			
/* Customer product reviews */
.product-review-section {
    margin-top: 18px;
    padding-top: 14px;
    border-top: 1px solid #e5ddd5;
}

.product-review-section h4 {
    margin-bottom: 10px;
}

.review-summary {
    margin-bottom: 12px;
    font-size: 14px;
}

.review-summary span,
.review-stars {
    color: #c58b20;
    letter-spacing: 2px;
}

.no-reviews {
    font-size: 13px;
    color: #777;
}

.customer-review {
    padding: 10px 0;
    border-bottom: 1px solid #eee5dc;
}

.review-author {
    margin-bottom: 4px;
}

.review-stars {
    margin: 3px 0;
    font-size: 14px;
}

.review-comment {
    font-size: 14px;
    line-height: 1.5;
    overflow-wrap: anywhere;
}

.review-date {
    display: block;
    margin-top: 6px;
    color: #777;
    font-size: 12px;
}

.review-notice {
    margin: 15px 0;
    padding: 12px;
    border-radius: 6px;
    background: #f4eee5;
    color: #4a392c;
}


/* Collapsible product review form */
.review-toggle {
    margin-top: 12px;
}

.review-toggle summary {
    display: inline-block;
    padding: 10px 14px;
    background: #4b2e20;
    color: #fff;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    list-style: none;
    transition: background 0.2s ease;
}

.review-toggle summary::-webkit-details-marker {
    display: none;
}

.review-toggle summary::after {
    content: " +";
    margin-left: 6px;
}

.review-toggle[open] summary::after {
    content: " −";
}

.review-toggle summary:hover {
    background: #65432f;
}

.review-form-container {
    margin-top: 14px;
}

.review-prompt {
    margin-bottom: 12px;
    font-size: 14px;
    line-height: 1.5;
}

.review-form {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.review-form label {
    margin-top: 5px;
    font-size: 14px;
    font-weight: 600;
}

.review-form select,
.review-form textarea {
    box-sizing: border-box;
    width: 100%;
    max-width: 100%;
    padding: 9px;
    font: inherit;
    font-size: 14px;
    border: 1px solid #d8c9be;
    border-radius: 5px;
}

.review-form textarea {
    resize: vertical;
}

.review-form button {
    width: 100%;
    margin-top: 8px;
}

.already-reviewed {
    margin-top: 12px;
    color: #4b2e20;
    font-size: 14px;
    font-weight: 600;
}

.review-login {
    margin-top: 12px;
    font-size: 14px;
}

		
				
		/* Individual horizontal product rows */
		.product-scroll-row {
			display: flex;
			flex-wrap: nowrap;
			gap: 24px;

			overflow-x: auto;
			overflow-y: hidden;

			padding: 12px 4px 20px;
			scroll-snap-type: x mandatory;
			-webkit-overflow-scrolling: touch;
		}

		.product-scroll-row .product-card {
			flex: 0 0 320px;
			min-width: 0;
			box-sizing: border-box;
			scroll-snap-align: start;
		}

		/* Scrollbar */
		.product-scroll-row::-webkit-scrollbar {
			height: 8px;
		}

		.product-scroll-row::-webkit-scrollbar-thumb {
			background: #b9a092;
			border-radius: 10px;
		}

		.product-scroll-row::-webkit-scrollbar-track {
			background: #f5eee8;
			border-radius: 10px;
		}
			

		/* Mobile */
		@media (max-width: 600px) {
			.product-scroll-row .product-card {
				flex-basis: 82%;
			}
		}



        /* RESPONSIVE DESIGN */

        @media (max-width: 950px) {
            .product-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .hero-content {
                padding: 40px 8%;
            }

            .category-grid {
                gap: 18px;
            }
        }

        @media (max-width: 650px) {
            .navbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .nav-links {
                gap: 15px;
            }

            .hero {
                grid-template-columns: 1fr;
            }

            .hero-logo {
                padding: 20px;
            }

            .hero-logo img {
                height: 250px;
            }

            .hero-content {
                padding: 40px 7%;
            }

            .category-grid {
                grid-template-columns: 1fr;
                gap: 35px;
            }

            .category-card img {
                aspect-ratio: 1.55 / 1;
            }

            .product-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .product-image img {
                height: 155px;
            }

            .product-info {
                padding: 12px;
            }

            .product-info h3 {
                font-size: 15px;
            }

            .shop-header {
                align-items: flex-start;
                flex-direction: column;
            }

            .search-form {
                width: 100%;
            }

            .search-form input {
                flex: 1;
                min-width: 0;
            }

            .quantity-controller {
                gap: 10px;
            }
        }

        @media (max-width: 380px) {
            .product-grid {
                grid-template-columns: 1fr;
            }

            .product-image img {
                height: 220px;
            }
        }
    </style>
</head>

<body>

<!-- NAVIGATION -->
<header class="navbar">

    <a href="main.php" class="brand">
        DeeHandMade
    </a>

    <nav class="nav-links">

        <a href="main.php">Home</a>

        <a href="main.php#teas">Teas</a>
        <a href="main.php#soaps">Soaps</a>
        <a href="main.php#oils">Oils</a>
		<a href="aboutUs.php">About Us</a>

        <a href="cart.php" class="cart-link">
            Cart (<?php echo $cartCount; ?>)
        </a>

        <?php if (isset($_SESSION["user_id"])): ?>

            <a href="profile.php">My Profile</a>
            <a href="logout.php">Logout</a>

        <?php else: ?>

            <a href="login.php">Login</a>
            <a href="register.php">Register</a>

        <?php endif; ?>

    </nav>

</header>


<!-- HERO SECTION -->
<section class="hero">

    <div class="hero-logo">
        <img
            src="images/logo.png"
            alt="DeeHandMade Soap natural wreath logo"
        >
    </div>

    <div class="hero-content">

        <span class="eyebrow">Made with love</span>

        <h1>
            With Love<br>
            From<br>
            Mother Nature
        </h1>

        <p>
            We create handcrafted wellness products using natural
            ingredients. Every product is carefully prepared to
            nourish your body, calm your mind and uplift your spirit.
        </p>

        <p class="hero-tagline">
            Natural ... Pure... Handmade...
        </p>

    </div>

</section>


<!-- CATEGORY BANNERS -->
<section class="essentials">

    <p class="section-eyebrow">
        Three ways to feel closer to nature
    </p>

    <h2 class="section-heading">
        Find your everyday essentials.
    </h2>

    <?php if ($search === ""): ?>

    <div class="category-grid">

        <a href="#teas" class="category-card">

            <img src="images/teabanner.png"
                 alt="DeeHandMade herbal teas">

            <div class="category-info">

                <div class="category-title-row">
                    <h3>Teas</h3>
                    <span class="category-arrow">↗</span>
                </div>

                <p>Steep a slower moment.</p>

                <small>
                    Loose-leaf blends, rooted in plants.
                </small>

            </div>
        </a>


        <a href="#soaps" class="category-card">

            <img src="images/soapbanner.png"
                 alt="DeeHandMade handmade soaps">

            <div class="category-info">

                <div class="category-title-row">
                    <h3>Soaps</h3>
                    <span class="category-arrow">↗</span>
                </div>

                <p>A little care, by hand.</p>

                <small>
                    Small-batch bars for everyday skin.
                </small>

            </div>
        </a>


        <a href="#oils" class="category-card">

            <img src="images/oilbanner1.png"
                 alt="DeeHandMade botanical oils">

            <div class="category-info">

                <div class="category-title-row">
                    <h3>Oils</h3>
                    <span class="category-arrow">↗</span>
                </div>

                <p>Nourish, drop by drop.</p>

                <small>
                    Botanical oils for body and bath.
                </small>

            </div>
        </a>

    </div>

    <?php endif; ?>

</section>


<!-- SHOP SECTION -->
<section class="shop-area">
       <?php
    $reviewMessages = [
        'submitted' => 'Thank you! Your review has been published.',
        'already_submitted' => 'You have already submitted a review for this product.',
        'not_eligible' => 'You can review this product after purchasing it and your payment is confirmed.',
        'invalid_input' => 'Please select a rating from 1 to 5 and enter a valid review.',
        'invalid_token' => 'Your session token is invalid. Please refresh the page and try again.',
        'error' => 'Sorry, we could not submit your review. Please try again.'
    ];

    $reviewStatus = $_GET['review'] ?? '';
    ?>

    <?php if (isset($reviewMessages[$reviewStatus])): ?>
        <div class="review-notice" role="status">
            <?php echo htmlspecialchars(
                $reviewMessages[$reviewStatus],
                ENT_QUOTES,
                'UTF-8'
            ); ?>
        </div>
    <?php endif; ?>
	
	
    <div class="shop-header">

        <div>
            <p class="section-eyebrow">
                <?php
                if (isset($_SESSION["user_id"])) {
                    echo "Welcome back, " .
                        htmlspecialchars($_SESSION["name"]);
                } else {
                    echo "Welcome to DeeHandMade";
                }
                ?>
            </p>

            <h2>
                <?php echo $search !== ""
                    ? "Search Results"
                    : "Our Collection"; ?>
            </h2>
        </div>

        <form method="GET" action="main.php"
              class="search-form">

            <input
                type="text"
                name="search"
                placeholder="Search our products..."
                value="<?php echo htmlspecialchars($search); ?>"
            >

            <button type="submit">Search</button>

            <?php if ($search !== ""): ?>
                <a href="main.php" class="clear-search">
                    Clear
                </a>
            <?php endif; ?>

        </form>

    </div>


    <?php if ($search !== ""): ?>

        <!-- SEARCH RESULTS -->

        <?php if (count($allProducts) > 0): ?>

            <div class="product-grid">
                <?php displayProducts($allProducts); ?>
            </div>

        <?php else: ?>

            <p class="empty-message">
                No products found. Try another search.
            </p>

        <?php endif; ?>


    <?php else: ?>

        <!-- TEAS -->

        <section class="product-section product-category" id="teas">

            <h2>Teas</h2>

            <?php if (count($teas) > 0): ?>

                <div class="product-scroll-row">
                    <?php displayProducts($teas); ?>
                </div>

            <?php else: ?>

                <p class="empty-message">
                    No teas are currently available.
                </p>

            <?php endif; ?>

        </section>


        <!-- SOAPS -->

        <section class="product-section product-category" id="soaps">

            <h2>Soaps</h2>

            <?php if (count($soaps) > 0): ?>

                <div class="product-scroll-row">
                    <?php displayProducts($soaps); ?>
                </div>

            <?php else: ?>

                <p class="empty-message">
                    No soaps are currently available.
                </p>

            <?php endif; ?>

        </section>


        <!-- OILS -->

        <section class="product-section product-category" id="oils">

            <h2>Oils</h2>

            <?php if (count($oils) > 0): ?>

                <div class="product-scroll-row">
                    <?php displayProducts($oils); ?>
                </div>

            <?php else: ?>

                <p class="empty-message">
                    No oils are currently available.
                </p>

            <?php endif; ?>

        </section>

    <?php endif; ?>

</section>


<!-- FOOTER -->
<footer>

    <p>
        DeeHandMade | Natural. Pure. Handmade.
    </p>

    <p style="margin-top:8px;">
        Made with love.
    </p>

</footer>

</body>
</html>
