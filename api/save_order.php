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

// Log received data for debugging
error_log("Save order received: " . print_r($input, true));

try {
    $pdo = getDB();
    $pdo->beginTransaction();
    
    $orderRef = $input['orderReference'];
    $supplierName = $input['supplier'];
    $supplierCategory = $input['supplierCategory'] ?? '';
    $subtotal = $input['subtotal'];
    $priorityFee = $input['priorityFee'];
    $total = $input['total'];
    $priority = $input['priority'];
    $warehouseId = $input['warehouseId'] ?? null;  // Make sure this is captured
    $leadTimeMin = $input['leadTimeMin'];
    $leadTimeMax = $input['leadTimeMax'];
    $status = 'pending';
    
    // Fix the datetime format
    $orderDate = $input['orderDate'];
    $orderDateMySQL = date('Y-m-d H:i:s', strtotime($orderDate));
    
    // Get warehouse name based on warehouse_id
    $warehouseName = "";
    $deliveryTerminal = "";
    
    if ($warehouseId == 1) {
        $warehouseName = "Mariano's Delivery Hub";
        $deliveryTerminal = "Mariano's Delivery Hub - Bulakan, Bulacan";
    } else if ($warehouseId == 2) {
        $warehouseName = "Hanubis Center";
        $deliveryTerminal = "Hanubis Center - Mexico, Pampanga";
    } else if ($warehouseId == 3) {
        $warehouseName = "Canto Warehouse Inc.";
        $deliveryTerminal = "Canto Warehouse Inc. - Chinatown, Manila";
    } else if ($warehouseId == 4) {
        $warehouseName = "Gervas Logistics Facility";
        $deliveryTerminal = "Gervas Logistics Facility - Negros Occidental";
    }
    
    // Calculate items count
    $itemsCount = count($input['items']);
    
    error_log("Saving order: $orderRef, warehouse_id: $warehouseId, warehouse_name: $warehouseName");
    
    // Insert into orders table
    $sql = "INSERT INTO orders (
        order_reference, 
        supplier_name, 
        supplier_category, 
        priority,
        delivery_terminal,
        warehouse_name,
        warehouse_id,
        subtotal,
        priority_fee,
        terminal_fee,
        total_amount,
        lead_time_min,
        lead_time_max,
        items_count,
        order_date,
        status,
        created_at
    ) VALUES (
        :order_reference, 
        :supplier_name, 
        :supplier_category, 
        :priority,
        :delivery_terminal,
        :warehouse_name,
        :warehouse_id,
        :subtotal,
        :priority_fee,
        :terminal_fee,
        :total_amount,
        :lead_time_min,
        :lead_time_max,
        :items_count,
        :order_date,
        :status,
        NOW()
    )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':order_reference' => $orderRef,
        ':supplier_name' => $supplierName,
        ':supplier_category' => $supplierCategory,
        ':priority' => $priority,
        ':delivery_terminal' => $deliveryTerminal,
        ':warehouse_name' => $warehouseName,
        ':warehouse_id' => $warehouseId,
        ':subtotal' => $subtotal,
        ':priority_fee' => $priorityFee,
        ':terminal_fee' => 0,
        ':total_amount' => $total,
        ':lead_time_min' => $leadTimeMin,
        ':lead_time_max' => $leadTimeMax,
        ':items_count' => $itemsCount,
        ':order_date' => $orderDateMySQL,
        ':status' => $status
    ]);
    
    $orderId = $pdo->lastInsertId();
    
    // Insert order items
    $items = $input['items'];
    $itemsSql = "INSERT INTO order_items (
        order_id,
        item_name,
        item_sku,
        quantity,
        price,
        item_image
    ) VALUES (
        :order_id,
        :item_name,
        :item_sku,
        :quantity,
        :price,
        :item_image
    )";
    
    $itemsStmt = $pdo->prepare($itemsSql);
    
    foreach ($items as $item) {
        $itemsStmt->execute([
            ':order_id' => $orderId,
            ':item_name' => $item['name'],
            ':item_sku' => $item['sku'] ?? '',
            ':quantity' => $item['quantity'],
            ':price' => $item['price'],
            ':item_image' => $item['image'] ?? null
        ]);
    }
    
    $pdo->commit();
    
    error_log("Order saved successfully with ID: $orderId, warehouse_id: $warehouseId");
    
    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'order_reference' => $orderRef,
        'warehouse_id' => $warehouseId,
        'warehouse_name' => $warehouseName,
        'items_saved' => count($items),
        'message' => 'Order saved successfully'
    ]);
    
} catch(PDOException $e) {
    if ($pdo) $pdo->rollBack();
    error_log("Save order error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>