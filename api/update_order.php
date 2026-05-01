<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'Order ID required']);
    exit;
}

if (!isset($data['status'])) {
    echo json_encode(['success' => false, 'error' => 'Status is required']);
    exit;
}

// Get database connection
$pdo = getDB();

// Update order status (inventory update happens inside this function)
$result = updateOrderStatus($pdo, $data['id'], $data['status']);
echo json_encode($result);
?>