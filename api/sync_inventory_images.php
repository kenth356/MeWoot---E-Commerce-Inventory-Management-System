<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';

$pdo = getDB();

try {
    // Update inventory images from supplier products by matching product name
    $stmt = $pdo->prepare("
        UPDATE inventory i
        JOIN supplier_products sp ON sp.product_name = i.name
        SET i.image_url = sp.product_image
        WHERE sp.product_image IS NOT NULL 
        AND sp.product_image != ''
        AND (i.image_url IS NULL OR i.image_url = '')
    ");
    $stmt->execute();
    $updated = $stmt->rowCount();
    
    echo json_encode([
        'success' => true,
        'message' => "Updated $updated inventory items with images from supplier products",
        'updated_count' => $updated
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>