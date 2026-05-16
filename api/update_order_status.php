<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['orderRef']) || !isset($input['status'])) {
    echo json_encode(['success' => false, 'error' => 'Missing order reference or status']);
    exit();
}

try {
    $pdo = getDB();
    
    $checkSql = "SELECT id, status, warehouse_id FROM orders WHERE order_reference = :orderRef";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([':orderRef' => $input['orderRef']]);
    $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found: ' . $input['orderRef']]);
        exit();
    }
    
    $sql = "UPDATE orders SET status = :status WHERE order_reference = :orderRef";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':status' => $input['status'],
        ':orderRef' => $input['orderRef']
    ]);
    
    error_log("Order {$input['orderRef']} status changed from {$order['status']} to {$input['status']}");
    
    echo json_encode([
        'success' => true, 
        'message' => 'Order status updated',
        'old_status' => $order['status'],
        'new_status' => $input['status'],
        'warehouse_id' => $order['warehouse_id']
    ]);
    
} catch(PDOException $e) {
    error_log("Update order status error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>