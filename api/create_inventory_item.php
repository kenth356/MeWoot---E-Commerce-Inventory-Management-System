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

if (!$input) {
    echo json_encode(['success' => false, 'error' => 'No data received']);
    exit();
}

if (!isset($input['warehouse_id']) || !isset($input['product_name']) || !isset($input['quantity']) || !isset($input['sku'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields: warehouse_id, product_name, quantity, sku']);
    exit();
}

try {
    $pdo = getDB();
    
    $warehouseNames = [
        1 => "Mariano's Delivery Hub",
        2 => "Hanubis Center", 
        3 => "Canto Warehouse Inc.",
        4 => "Gervas Logistics Facility"
    ];
    
   
    $price = isset($input['price']) ? floatval($input['price']) : 0;
    
    $checkSql = "SELECT id, stock FROM inventory WHERE name = :product_name AND warehouse_id = :warehouse_id";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([
        ':product_name' => $input['product_name'],
        ':warehouse_id' => $input['warehouse_id']
    ]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
       
        $newQuantity = $existing['stock'] + $input['quantity'];
        $updateSql = "UPDATE inventory SET stock = :stock, price = :price, updated_at = NOW() WHERE id = :id";
        $updateStmt = $pdo->prepare($updateSql);
        $updateStmt->execute([
            ':stock' => $newQuantity,
            ':price' => $price,
            ':id' => $existing['id']
        ]);
        
        echo json_encode([
            'success' => true,
            'inventory_id' => $existing['id'],
            'sku' => $input['sku'],
            'action' => 'updated',
            'message' => 'Inventory updated successfully'
        ]);
    } else {
        $sql = "INSERT INTO inventory (sku, name, stock, price, warehouse_id, warehouse_name, category, created_at) 
                VALUES (:sku, :name, :stock, :price, :warehouse_id, :warehouse_name, :category, NOW())";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':sku' => $input['sku'],
            ':name' => $input['product_name'],
            ':stock' => $input['quantity'],
            ':price' => $price,
            ':warehouse_id' => $input['warehouse_id'],
            ':warehouse_name' => $warehouseNames[$input['warehouse_id']],
            ':category' => $input['category'] ?? 'Received Items'
        ]);
        
        $inventoryId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'inventory_id' => $inventoryId,
            'sku' => $input['sku'],
            'action' => 'created',
            'message' => 'Inventory item created successfully'
        ]);
    }
    
} catch(PDOException $e) {
    error_log("Create inventory error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>