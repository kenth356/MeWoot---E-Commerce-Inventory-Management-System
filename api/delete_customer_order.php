<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['order_id'])) {
    echo json_encode(['success' => false, 'error' => 'Order ID required']);
    exit();
}

try {
    $pdo = getDB();

    $stmt = $pdo->prepare("SELECT status, order_number FROM customer_details WHERE id = ?");
    $stmt->execute([$input['order_id']]);
    $order = $stmt->fetch();
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit();
    }
    
    if ($order['status'] === 'delivered') {
        $itemStmt = $pdo->prepare("SELECT * FROM customer_ordered_products WHERE order_id = ?");
        $itemStmt->execute([$input['order_id']]);
        $items = $itemStmt->fetchAll();
        
        foreach ($items as $item) {
            $inventoryStmt = $pdo->prepare("
                SELECT id, stock FROM inventory 
                WHERE id = ? OR (name = ? OR sku = ?)
                LIMIT 1
            ");
            $inventoryStmt->execute([$item['product_id'], $item['product_name'], $item['product_sku']]);
            $inventoryItem = $inventoryStmt->fetch();
            
            if ($inventoryItem) {
                $newStock = $inventoryItem['stock'] + $item['quantity'];
                $updateStmt = $pdo->prepare("UPDATE inventory SET stock = ?, updated_at = NOW() WHERE id = ?");
                $updateStmt->execute([$newStock, $inventoryItem['id']]);
            }
        }
    }
    
    $deleteItems = $pdo->prepare("DELETE FROM customer_ordered_products WHERE order_id = ?");
    $deleteItems->execute([$input['order_id']]);

    $deleteOrder = $pdo->prepare("DELETE FROM customer_details WHERE id = ?");
    $deleteOrder->execute([$input['order_id']]);
    
    echo json_encode(['success' => true, 'message' => 'Order deleted successfully']);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>