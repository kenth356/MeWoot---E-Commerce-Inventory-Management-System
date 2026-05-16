<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

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

$pdo = getDB();

if ($data['status'] === 'delivered') {
    $orderStmt = $pdo->prepare("SELECT id, warehouse_id, warehouse_name FROM orders WHERE id = ?");
    $orderStmt->execute([$data['id']]);
    $order = $orderStmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $warehouseId   = $order['warehouse_id'];
    $warehouseName = $order['warehouse_name'];

    $itemsStmt = $pdo->prepare("SELECT item_name, item_sku, quantity, price, item_image FROM order_items WHERE order_id = ?");
    $itemsStmt->execute([$data['id']]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($items) && $warehouseId) {
        $invResult = updateInventoryStock($pdo, $items, $warehouseId, $warehouseName);
        if (!$invResult['success']) {
            error_log("Inventory update failed for order {$data['id']}: " . ($invResult['error'] ?? 'unknown'));
        }
    }
}

$result = updateOrderStatus($pdo, $data['id'], $data['status']);
echo json_encode($result);
?>