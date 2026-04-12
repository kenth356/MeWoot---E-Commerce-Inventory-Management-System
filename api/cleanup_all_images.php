<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once '../config/database.php';

$pdo = getDB();
$uploadDir = __DIR__ . '/../uploads/products/';
$deleted = 0;
$kept = 0;

// Get all images currently referenced in the database
$stmt = $pdo->query("
    SELECT DISTINCT product_image FROM supplier_products WHERE product_image IS NOT NULL AND product_image != ''
    UNION
    SELECT DISTINCT image_url FROM inventory WHERE image_url IS NOT NULL AND image_url != ''
    UNION
    SELECT DISTINCT item_image FROM order_items WHERE item_image IS NOT NULL AND item_image != ''
");
$usedImages = $stmt->fetchAll(PDO::FETCH_COLUMN);
$usedImages = array_unique($usedImages);

// Scan the uploads folder
if (file_exists($uploadDir)) {
    $files = scandir($uploadDir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        $imageUrl = 'uploads/products/' . $file;
        if (!in_array($imageUrl, $usedImages)) {
            if (unlink($uploadDir . $file)) {
                $deleted++;
            }
        } else {
            $kept++;
        }
    }
}

echo json_encode([
    'success' => true,
    'message' => "Deleted $deleted unused images, kept $kept used images",
    'deleted' => $deleted,
    'kept' => $kept
]);
?>