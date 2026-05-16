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

$data = json_decode(file_get_contents('php://input'), true);

if (!$data) {
    echo json_encode(['success' => false, 'error' => 'No data received']);
    exit;
}

if (empty($data['customer_name']) || empty($data['customer_address']) || empty($data['items']) || count($data['items']) === 0) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields: customer name, address, and at least one item']);
    exit;
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    $orderNumber = 'CUST-' . date('Ymd') . '-' . rand(1000, 9999);

    $warehouseId = isset($data['warehouse_id']) ? intval($data['warehouse_id']) : null;

    $stmt = $pdo->prepare("
        INSERT INTO customer_details (order_number, customer_name, customer_email, customer_phone, customer_address, notes, total_amount, status, warehouse_id, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
    ");

    $stmt->execute([
        $orderNumber,
        $data['customer_name'],
        $data['customer_email'] ?? null,
        $data['customer_phone'] ?? null,
        $data['customer_address'],
        $data['notes'] ?? null,
        $data['total_amount'],
        $warehouseId
    ]);

    $orderId = $pdo->lastInsertId();

    $itemStmt = $pdo->prepare("
        INSERT INTO customer_ordered_products (order_id, product_id, product_name, product_sku, quantity, unit_price, subtotal, product_image)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($data['items'] as $item) {
        $itemStmt->execute([
            $orderId,
            $item['product_id'],
            $item['product_name'],
            $item['product_sku'] ?? '',
            $item['quantity'],
            $item['unit_price'],
            $item['subtotal'],
            $item['product_image'] ?? null
        ]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Order placed successfully!',
        'order_number' => $orderNumber,
        'order_id' => $orderId
    ]);

} catch(PDOException $e) {
    if (isset($pdo)) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>