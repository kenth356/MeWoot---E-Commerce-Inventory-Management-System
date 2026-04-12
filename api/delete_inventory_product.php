<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['id'])) {
    echo json_encode(['success' => false, 'error' => 'Product ID required']);
    exit;
}

$productId = intval($data['id']);
$imageUrl = null;

try {
    $pdo = getDB();
    $pdo->beginTransaction();
    
    // Get the image URL before deleting
    $stmt = $pdo->prepare("SELECT image_url FROM inventory WHERE id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    if ($product && $product['image_url']) {
        $imageUrl = $product['image_url'];
    }
    
    // Delete from inventory
    $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
    $stmt->execute([$productId]);
    
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Product not found']);
        exit;
    }
    
    // Also delete the image file if it exists
    if ($imageUrl) {
        $imagePath = __DIR__ . '/../' . $imageUrl;
        if (file_exists($imagePath)) {
            unlink($imagePath);
        }
    }
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Product deleted successfully']);
    
} catch(PDOException $e) {
    if (isset($pdo)) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>