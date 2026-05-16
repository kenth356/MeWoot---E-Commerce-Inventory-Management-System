<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';

$pdo = getDB();

try {
    $results = [];
    
    $stmt1 = $pdo->prepare("
        UPDATE inventory i
        JOIN supplier_products sp ON (sp.product_name = i.name OR sp.product_sku = i.sku)
        SET i.image_url = sp.product_image
        WHERE sp.product_image IS NOT NULL AND sp.product_image != ''
    ");
    $stmt1->execute();
    $results['inventory_updated'] = $stmt1->rowCount();
    
    $stmt2 = $pdo->prepare("
        UPDATE order_items oi
        JOIN supplier_products sp ON (sp.product_name = oi.item_name OR sp.product_sku = oi.item_sku)
        SET oi.item_image = sp.product_image
        WHERE sp.product_image IS NOT NULL AND sp.product_image != ''
    ");
    $stmt2->execute();
    $results['order_items_updated'] = $stmt2->rowCount();
    
    $stmt3 = $pdo->query("
        SELECT name, sku, image_url 
        FROM inventory 
        WHERE image_url IS NULL OR image_url = ''
        LIMIT 10
    ");
    $missingImages = $stmt3->fetchAll();
    
    echo json_encode([
        'success' => true,
        'message' => 'Image sync completed',
        'results' => $results,
        'still_missing' => count($missingImages),
        'missing_examples' => $missingImages
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>