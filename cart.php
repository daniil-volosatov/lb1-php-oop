<?php
session_start();
require_once 'classes.php';


$page = new CartPage("Мій кошик");
$page->renderAll();
?>