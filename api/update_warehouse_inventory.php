<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'No data received']);
    exit;
}

$warehouseId = $input['warehouse_id'] ?? null;
$productSku = $input['sku'] ?? null;
$productName = $input['name'] ?? null;
$quantity = $input['quantity'] ?? 0;
$price = $input['price'] ?? 0;
$category = $input['category'] ?? 'Uncategorized';

if (!$warehouseId || !$productSku) {
    echo json_encode(['success' => false, 'error' => 'Warehouse ID and Product SKU are required']);
    exit;
}

try {
    $pdo = getDB();
    
    $stmt = $pdo->prepare("SELECT * FROM inventory WHERE sku = ? AND warehouse_id = ?");
    $stmt->execute([$productSku, $warehouseId]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        $newStock = $existing['stock'] + $quantity;
        $updateStmt = $pdo->prepare("UPDATE inventory SET stock = ?, updated_at = NOW() WHERE sku = ? AND warehouse_id = ?");
        $updateStmt->execute([$newStock, $productSku, $warehouseId]);
        echo json_encode(['success' => true, 'message' => "Added {$quantity} units to warehouse {$warehouseId}"]);
    } else {
        $insertStmt = $pdo->prepare("
            INSERT INTO inventory (sku, name, category, stock, price, warehouse_id, created_at, updated_at) 
            VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $insertStmt->execute([$productSku, $productName, $category, $quantity, $price, $warehouseId]);
        echo json_encode(['success' => true, 'message' => "New product added to warehouse {$warehouseId}"]);
    }
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>