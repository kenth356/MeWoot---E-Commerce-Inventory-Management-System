<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../config/database.php';

$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

// Get database connection
$pdo = getDB();

// Get orders
$result = getOrders($pdo, $status, $search);
echo json_encode($result);
?>