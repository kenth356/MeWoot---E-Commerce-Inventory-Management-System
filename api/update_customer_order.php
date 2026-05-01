<?php
// api/update_customer_order.php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, POST, OPTIONS');
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

if (!isset($input['status'])) {
    echo json_encode(['success' => false, 'error' => 'Status is required']);
    exit();
}

$validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
if (!in_array($input['status'], $validStatuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status. Allowed: pending, processing, shipped, delivered, cancelled']);
    exit();
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();
    
    // Get current order status and details
    $stmt = $pdo->prepare("SELECT status, order_number FROM customer_details WHERE id = ?");
    $stmt->execute([$input['order_id']]);
    $order = $stmt->fetch();
    
    if (!$order) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit();
    }
    
    $oldStatus = $order['status'];
    $newStatus = $input['status'];
    
    // Update order status
    $updateStmt = $pdo->prepare("UPDATE customer_details SET status = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$newStatus, $input['order_id']]);
    
    // NOTE: Stock is already deducted at order placement (save_customer_order.php).
    // Do NOT deduct again when marking as delivered.
    
    // If changing from delivered back to pending/shipped/processing (restore), add stock back
    if ($oldStatus === 'delivered' && ($newStatus === 'pending' || $newStatus === 'shipped' || $newStatus === 'processing')) {
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
                $updateInventoryStmt = $pdo->prepare("UPDATE inventory SET stock = ?, updated_at = NOW() WHERE id = ?");
                $updateInventoryStmt->execute([$newStock, $inventoryItem['id']]);
                error_log("Stock restored for {$item['product_name']}: {$inventoryItem['stock']} → {$newStock}");
            }
        }
    }
    
    // If changing to cancelled from any active status, restore stock
    // (stock was deducted at order placement, so we restore on any cancellation)
    if ($newStatus === 'cancelled' && in_array($oldStatus, ['pending', 'processing', 'shipped', 'delivered'])) {
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
                $updateInventoryStmt = $pdo->prepare("UPDATE inventory SET stock = ?, updated_at = NOW() WHERE id = ?");
                $updateInventoryStmt->execute([$newStock, $inventoryItem['id']]);
                error_log("Stock restored for cancelled order {$item['product_name']}: {$inventoryItem['stock']} → {$newStock}");
            }
        }
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => "Order status updated to {$newStatus}",
        'old_status' => $oldStatus,
        'new_status' => $newStatus
    ]);
    
} catch(PDOException $e) {
    if (isset($pdo)) $pdo->rollBack();
    error_log("Update customer order error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>