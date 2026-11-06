
<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>About Us | DeeHandMade</title>

    <link rel="stylesheet" href="css/aboutUsStyle.css">
</head>

<body>

    <!-- About Page -->
    <main class="about-page">

        <section class="about-hero">
            <p class="about-label">GET TO KNOW US</p>
            <h1>About DeeHandMade</h1>
            <p>
                Welcome to DeeHandMade, your destination for
                teas, soaps and oils.
            </p>
        </section>

        <section class="about-content">

            <div class="about-story">
                <h2>Our Story</h2>

                <p>
                    DeeHandMade is an online shopping platform
                    offering teas, soaps and oils in one place.
                    Our website makes it easy for customers to
                    browse products, explore their options and
                    place orders online.
                </p>

                <p>
                    We aim to provide customers with a simple
                    and convenient shopping experience.
                </p>
            </div>

            <div class="about-products">
                <h2>What We Offer</h2>

                <div class="about-product-item">
                    <h3> <a href=main.php#teas>Teas</a></h3>
                    <p>Explore our selection of teas.</p>
                </div>

                <div class="about-product-item">
                    <h3> <a href=main.php#soaps>Soaps</a></h3>
                    <p>Discover our available soaps.</p>
                </div>

                <div class="about-product-item">
                    <<h3> <a href=main.php#oils>Oils</a></h3>
                    <p>Browse our available oils.</p>
                </div>
            </div>

        </section>

        <!-- Contact Section -->
        <section class="about-contact" id="contact">

            <h2>Contact Us</h2>

            <p>
                Have a question about a product or your order?
                We would love to hear from you.
            </p>

            <div class="contact-details">

                <p>
                    <strong>Email:</strong>
                    <a href="mailto:mahlangucarol.s@gmail.com">
                        mahlangucarol.s@example.com
                    </a>
                </p>

                <p>
                    <strong>Phone:</strong>
                    <a href="tel:+27000000000">
                        +27 778334521
                    </a>
                </p>

            </div>

            <a href="main.php" class="about-shop-btn">
                Continue Shopping
            </a>

        </section>

    </main>

</body>
</html>
