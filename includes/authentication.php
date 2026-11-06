<?php

session_start();

function requireLogin()
{
    if (!isset($_SESSION["user_id"])) {
        header("Location: ../login.php");
        exit;
    }
}

function requireAdmin()
{
    requireLogin();

    if ($_SESSION["role"] !== "admin") {
        header("Location: ../main.php");
        exit;
    }
}

function requireCustomer()
{
    requireLogin();

    if ($_SESSION["role"] !== "customer") {
        header("Location: ../admin.php");
        exit;
    }
}

?>