<?php
session_start();
require_once 'classes.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy'])) {
    $id = $_POST['product_id'];
    $_SESSION['cart'][$id] = [
        'name' => $_POST['product_name'],
        'price' => $_POST['product_price'],
        'qty' => $_POST['qty']
    ];
    header("Location: index.php");
    exit;
}

$page = new ShopPage("Головна | Послуги");
$page->renderAll();
?>