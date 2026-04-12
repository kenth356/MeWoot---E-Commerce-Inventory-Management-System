<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
require_once '../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['supplier_id']) || !isset($data['product_name'])) {
    echo json_encode(['success' => false, 'error' => 'Supplier ID and product name required']);
    exit;
}

$pdo = getDB();

try {
    $stmt = $pdo->prepare("
        INSERT INTO supplier_products (supplier_id, product_name, product_sku, price, min_order_qty)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $data['supplier_id'],
        $data['product_name'],
        $data['product_sku'] ?? '',
        $data['price'] ?? 0,
        $data['min_order_qty'] ?? 1
    ]);
    
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>