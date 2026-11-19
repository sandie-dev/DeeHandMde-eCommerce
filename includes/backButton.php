
<?php
$currentPage = basename($_SERVER["PHP_SELF"]);

$hideBackButton = [
    "main.php",
    "login.php",
    "register.php"
];

if (!in_array($currentPage, $hideBackButton, true)):
?>

<style>
   .back-button-container {
    position: fixed;
    bottom: 25px;
    right: 25px;
    z-index: 9999;
}

    .back-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;

    background-color: #6f4e37 !important;
    color: #ffffff !important;

    border: 1px solid #6f4e37 !important;
    border-radius: 30px;

    padding: 12px 24px;

    font-size: 14px;
    font-weight: 600;

    cursor: pointer;

    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.22);

    transition: all 0.3s ease;
}

   .back-button:hover {
    background-color: #4e3526 !important;
    border-color: #4e3526 !important;
    transform: translateY(-3px);
}
@media (max-width: 600px) {
    .back-button-container {
        bottom: 18px;
        right: 15px;
    }

    .back-button {
        padding: 11px 19px;
        font-size: 13px;
    }
}

</style>

<div class="back-button-container">
    <button
        type="button"
        class="back-button"
        onclick="goBack()"
    >
        &#8592; Back
    </button>
</div>

<script>
function goBack() {
    if (
        document.referrer &&
        new URL(document.referrer).origin === window.location.origin
    ) {
        window.history.back();
    } else {
        window.location.href = "main.php";
    }
}
</script>

<?php endif; ?>
