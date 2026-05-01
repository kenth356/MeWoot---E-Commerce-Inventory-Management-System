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

if (!isset($input['warehouse_id']) || !isset($input['product_name']) || !isset($input['quantity'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields: warehouse_id, product_name, quantity']);
    exit();
}

try {
    $pdo = getDB();

    $checkSql = "SELECT id, stock FROM inventory WHERE name = :product_name AND warehouse_id = :warehouse_id";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([
        ':product_name' => $input['product_name'],
        ':warehouse_id' => $input['warehouse_id']
    ]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $newStock = max(0, $existing['stock'] + $input['quantity']);
        $price = isset($input['price']) ? floatval($input['price']) : null;

        if ($price !== null && $price > 0) {
    $sql = "UPDATE inventory SET stock = :stock, price = :price, updated_at = NOW()
            WHERE name = :product_name AND warehouse_id = :warehouse_id
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':stock' => $newStock,
        ':price' => $price,
        ':product_name' => $input['product_name'],
        ':warehouse_id' => $input['warehouse_id']
    ]);
} else {
    $sql = "UPDATE inventory SET stock = :stock, updated_at = NOW()
            WHERE name = :product_name AND warehouse_id = :warehouse_id
            LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':stock' => $newStock,
        ':product_name' => $input['product_name'],
        ':warehouse_id' => $input['warehouse_id']
    ]);
}

        echo json_encode([
            'success' => true,
            'message' => 'Inventory updated',
            'action' => 'updated',
            'old_quantity' => $existing['stock'],
            'new_quantity' => $newStock
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'error' => 'Product not found in this warehouse',
            'product_name' => $input['product_name'],
            'warehouse_id' => $input['warehouse_id']
        ]);
    }

} catch (PDOException $e) {
    error_log("Update inventory error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>