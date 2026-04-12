<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/database.php';

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid data']);
    exit;
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();
    
    // Update supplier
    $stmt = $pdo->prepare("UPDATE suppliers SET name=?, category=?, status=?, address=?, lead_time=?, contact_person=?, phone=?, email=?, updated_at=NOW() WHERE id=?");
    $stmt->execute([
        $data['name'],
        $data['category'] ?? null,
        $data['status'] ?? 'active',
        $data['address'] ?? null,
        $data['lead_time'] ?? 7,
        $data['contact_person'] ?? null,
        $data['phone'] ?? null,
        $data['email'] ?? null,
        $data['id']
    ]);
    
    // Delete existing products
    $deleteStmt = $pdo->prepare("DELETE FROM supplier_products WHERE supplier_id = ?");
    $deleteStmt->execute([$data['id']]);
    
    // Insert updated products
    if (!empty($data['products'])) {
        $productStmt = $pdo->prepare("INSERT INTO supplier_products (supplier_id, product_name, product_sku, price, product_image) VALUES (?, ?, ?, ?, ?)");
        foreach ($data['products'] as $product) {
            if (!empty($product['product_name'])) {
                $productStmt->execute([
                    $data['id'],
                    $product['product_name'],
                    $product['product_sku'] ?? null,
                    $product['price'] ?? 0,
                    $product['product_image'] ?? null
                ]);
            }
        }
        
        // SYNC INVENTORY IMAGES - This is the key fix!
        $syncStmt = $pdo->prepare("
            UPDATE inventory i
            JOIN supplier_products sp ON (sp.product_name = i.name OR sp.product_sku = i.sku)
            SET i.image_url = sp.product_image
            WHERE sp.supplier_id = ? 
            AND sp.product_image IS NOT NULL 
            AND sp.product_image != ''
        ");
        $syncStmt->execute([$data['id']]);
        $syncedCount = $syncStmt->rowCount();
        error_log("Synced $syncedCount inventory items with new images for supplier {$data['id']}");
    }
    
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Supplier updated and inventory synced']);
    
} catch (Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>