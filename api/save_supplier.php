<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/database.php';

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'No data received']);
    exit;
}

if (empty($data['name'])) {
    echo json_encode(['success' => false, 'error' => 'Supplier name required']);
    exit;
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("INSERT INTO suppliers (name, category, status, address, lead_time, contact_person, phone, email, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
    $stmt->execute([
        $data['name'],
        $data['category'] ?? null,
        $data['status'] ?? 'active',
        $data['address'] ?? null,
        $data['lead_time'] ?? 7,
        $data['contact_person'] ?? null,
        $data['phone'] ?? null,
        $data['email'] ?? null
    ]);
    
    $supplierId = $pdo->lastInsertId();
    
    if (!empty($data['products'])) {
        $productStmt = $pdo->prepare("INSERT INTO supplier_products (supplier_id, product_name, product_sku, price, product_image) VALUES (?, ?, ?, ?, ?)");
        foreach ($data['products'] as $product) {
            if (!empty($product['product_name'])) {
                $productStmt->execute([
                    $supplierId,
                    $product['product_name'],
                    $product['product_sku'] ?? null,
                    $product['price'] ?? 0,
                    $product['product_image'] ?? null
                ]);
            }
        }
        
        $syncStmt = $pdo->prepare("
            INSERT INTO inventory (name, sku, category, stock, price, image_url) 
            SELECT sp.product_name, sp.product_sku, s.category, 0, sp.price, sp.product_image
            FROM supplier_products sp
            JOIN suppliers s ON s.id = sp.supplier_id
            WHERE sp.supplier_id = ? 
            AND sp.product_sku IS NOT NULL 
            AND sp.product_sku != ''
            AND NOT EXISTS (SELECT 1 FROM inventory WHERE sku = sp.product_sku)
        ");
        $syncStmt->execute([$supplierId]);
    }
    
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Supplier created', 'supplier_id' => $supplierId]);
    
} catch (Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>