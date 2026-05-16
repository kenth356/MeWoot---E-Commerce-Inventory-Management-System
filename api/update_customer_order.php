<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: PUT, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['order_id'])) {
    echo json_encode(['success' => false, 'error' => 'Order ID required']);
    exit();
}

if (!isset($input['status'])) {
    echo json_encode(['success' => false, 'error' => 'Status is required']);
    exit();
}

$validStatuses = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
if (!in_array($input['status'], $validStatuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid status. Allowed: ' . implode(', ', $validStatuses)]);
    exit();
}

function deductStock($pdo, $orderId, $warehouseId) {
    $itemStmt = $pdo->prepare("SELECT * FROM customer_ordered_products WHERE order_id = ?");
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $qtyToDeduct = intval($item['quantity']);
        $productName = trim($item['product_name']);

        $rowsStmt = $pdo->prepare("
            SELECT id, stock
            FROM inventory
            WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))
              AND stock > 0
            ORDER BY
                CASE WHEN warehouse_id = ? THEN 0 ELSE 1 END,
                stock DESC
        ");
        $rowsStmt->execute([$productName, $warehouseId]);
        $rows = $rowsStmt->fetchAll(PDO::FETCH_ASSOC);

        $remaining = $qtyToDeduct;
        foreach ($rows as $row) {
            if ($remaining <= 0) break;
            $deduct = min($remaining, intval($row['stock']));
            $pdo->prepare("UPDATE inventory SET stock = stock - ?, updated_at = NOW() WHERE id = ?")
                ->execute([$deduct, $row['id']]);
            $remaining -= $deduct;
        }

        if ($remaining > 0 && empty($rows)) {
            $pdo->prepare("UPDATE inventory SET stock = stock - ?, updated_at = NOW() WHERE id = ?")
                ->execute([$qtyToDeduct, $item['product_id']]);
        } elseif ($remaining > 0) {
            error_log("Warning: Not enough stock for '{$productName}' (order {$orderId}). Short by {$remaining} units.");
        }
    }
}

function restoreStock($pdo, $orderId) {
    $itemStmt = $pdo->prepare("SELECT * FROM customer_ordered_products WHERE order_id = ?");
    $itemStmt->execute([$orderId]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($items as $item) {
        $invStmt = $pdo->prepare("
            SELECT id FROM inventory
            WHERE id = ?
            LIMIT 1
        ");
        $invStmt->execute([$item['product_id']]);
        $invRow = $invStmt->fetch(PDO::FETCH_ASSOC);

        if (!$invRow) {
            $invStmt2 = $pdo->prepare("
                SELECT id FROM inventory
                WHERE LOWER(TRIM(name)) = LOWER(TRIM(?))
                LIMIT 1
            ");
            $invStmt2->execute([$item['product_name']]);
            $invRow = $invStmt2->fetch(PDO::FETCH_ASSOC);
        }

        if ($invRow) {
            $pdo->prepare("UPDATE inventory SET stock = stock + ?, updated_at = NOW() WHERE id = ?")
                ->execute([$item['quantity'], $invRow['id']]);
            error_log("Stock restored for '{$item['product_name']}' (order {$orderId}): +{$item['quantity']}");
        }
    }
}

try {
    $pdo = getDB();
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT status, warehouse_id, order_number FROM customer_details WHERE id = ?");
    $stmt->execute([$input['order_id']]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit();
    }

    $oldStatus  = $order['status'];
    $newStatus  = $input['status'];
    $warehouseId = $order['warehouse_id'];

    $stockDeductedStatuses = ['shipped', 'delivered'];


    if ($newStatus === 'shipped' && !in_array($oldStatus, $stockDeductedStatuses)) {
        deductStock($pdo, $input['order_id'], $warehouseId);
    }

    if (in_array($oldStatus, $stockDeductedStatuses) && !in_array($newStatus, $stockDeductedStatuses)) {
        restoreStock($pdo, $input['order_id']);
    }

    $updateStmt = $pdo->prepare("UPDATE customer_details SET status = ?, updated_at = NOW() WHERE id = ?");
    $updateStmt->execute([$newStatus, $input['order_id']]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "Order status updated to {$newStatus}",
        'old_status' => $oldStatus,
        'new_status' => $newStatus
    ]);

} catch(PDOException $e) {
    if (isset($pdo)) $pdo->rollBack();
    error_log("Update customer order error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>