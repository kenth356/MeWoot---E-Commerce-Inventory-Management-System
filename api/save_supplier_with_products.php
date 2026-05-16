<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, OPTIONS');
require_once '../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['supplier'])) {
    echo json_encode(['success' => false, 'error' => 'Supplier data required']);
    exit;
}

$supplier = $data['supplier'];
$products = $data['products'] ?? [];

$pdo = getDB();

try {
    $pdo->beginTransaction();
    
    if (isset($supplier['id']) && $supplier['id']) {
        $stmt = $pdo->prepare("
            UPDATE suppliers SET 
                name = ?, category = ?, contact_person = ?, email = ?, 
                phone = ?, address = ?, lead_time = ?, min_order_qty = ?,
                payment_terms = ?, status = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $supplier['name'],
            $supplier['category'],
            $supplier['contact_person'],
            $supplier['email'],
            $supplier['phone'],
            $supplier['address'],
            $supplier['lead_time'],
            $supplier['min_order_qty'],
            $supplier['payment_terms'],
            $supplier['status'],
            $supplier['notes'],
            $supplier['id']
        ]);
        $supplierId = $supplier['id'];
        
        $deleteStmt = $pdo->prepare("DELETE FROM supplier_products WHERE supplier_id = ?");
        $deleteStmt->execute([$supplierId]);
        
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO suppliers (name, category, contact_person, email, phone, address, lead_time, min_order_qty, payment_terms, status, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $supplier['name'],
            $supplier['category'],
            $supplier['contact_person'],
            $supplier['email'],
            $supplier['phone'],
            $supplier['address'],
            $supplier['lead_time'],
            $supplier['min_order_qty'],
            $supplier['payment_terms'],
            $supplier['status'],
            $supplier['notes']
        ]);
        $supplierId = $pdo->lastInsertId();
    }
    
    if (!empty($products)) {
        $productStmt = $pdo->prepare("
            INSERT INTO supplier_products (supplier_id, product_name, product_sku, price, min_order_qty)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        foreach ($products as $product) {
            if (!empty($product['name'])) {
                $productStmt->execute([
                    $supplierId,
                    $product['name'],
                    $product['sku'] ?? '',
                    $product['price'] ?? 0,
                    $product['min_qty'] ?? 1
                ]);
            }
        }
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true, 
        'message' => isset($supplier['id']) ? 'Supplier updated' : 'Supplier created',
        'supplier_id' => $supplierId,
        'products_count' => count($products)
    ]);
    
} catch(PDOException $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>