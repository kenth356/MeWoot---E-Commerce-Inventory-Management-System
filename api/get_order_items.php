<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../config/database.php';

$orderId = $_GET['order_id'] ?? 0;

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Order ID required']);
    exit;
}

$pdo = getDB();

try {
    $stmt = $pdo->prepare("SELECT item_name, item_sku, quantity, price, item_image FROM order_items WHERE order_id = ?");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();
    
    echo json_encode(['success' => true, 'items' => $items]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>