<?php
// config/database.php
$db_config = [
    'host' => 'localhost',
    'user' => 'root',
    'password' => '',
    'database' => 'mewoot_db'
];

function getDB() {
    global $db_config;
    try {
        $pdo = new PDO(
            "mysql:host={$db_config['host']};dbname={$db_config['database']};charset=utf8mb4",
            $db_config['user'],
            $db_config['password']
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    } catch(PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]);
        exit;
    }
}

function saveOrder($orderData, $pdo) {
    try {
        $pdo->beginTransaction();
        
        // Insert order
        $stmt = $pdo->prepare("
            INSERT INTO orders (
                order_reference, supplier_name, supplier_category, priority, 
                delivery_terminal, subtotal, priority_fee, terminal_fee, 
                total_amount, lead_time_min, lead_time_max, items_count, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
        ");
        
        $stmt->execute([
            $orderData['orderReference'],
            $orderData['supplier'],
            $orderData['supplierCategory'] ?? null,
            $orderData['priority'],
            $orderData['deliveryTerminal'],
            $orderData['subtotal'],
            $orderData['priorityFee'],
            $orderData['terminalFee'],
            $orderData['total'],
            $orderData['leadTimeMin'],
            $orderData['leadTimeMax'],
            count($orderData['items'])
        ]);
        
        $orderId = $pdo->lastInsertId();
        
        // Insert order items with image
        $itemStmt = $pdo->prepare("
            INSERT INTO order_items (order_id, item_name, item_sku, quantity, price, item_image) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
                
        foreach ($orderData['items'] as $item) {
            $itemStmt->execute([
                $orderId,
                $item['name'],
                $item['sku'],
                $item['quantity'],
                $item['price'],
                $item['image'] ?? null  // Add this line
            ]);
        }
        
        $pdo->commit();
        return ['success' => true, 'order_id' => $orderId, 'order_reference' => $orderData['orderReference']];
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getOrders($pdo, $status = '', $search = '') {
    try {
        $sql = "SELECT * FROM orders WHERE 1=1";
        $params = [];
        
        if (!empty($status) && $status !== 'All Statuses') {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        
        if (!empty($search)) {
            $sql .= " AND (order_reference LIKE ? OR supplier_name LIKE ? OR supplier_category LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        $sql .= " ORDER BY order_date DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();
        
        // Get counts
        $countStmt = $pdo->query("
            SELECT
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
            FROM orders
        ");
        $counts = $countStmt->fetch();
        
        return [
            'success' => true,
            'orders' => $orders,
            'counts' => [
                'total_orders' => $counts['total_orders'] ?? 0,
                'pending' => $counts['pending'] ?? 0,
                'delivered' => $counts['delivered'] ?? 0,
                'archived' => $counts['archived'] ?? 0,
                'cancelled' => $counts['cancelled'] ?? 0
            ]
        ];
        
    } catch(PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getOrderItems($pdo, $orderId) {
    try {
        $stmt = $pdo->prepare("SELECT item_name, item_sku, quantity, price FROM order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        return $stmt->fetchAll();
    } catch(PDOException $e) {
        return [];
    }
}

function updateOrderStatus($pdo, $orderId, $status) {
    try {
        // Get current status before updating
        $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $currentOrder = $stmt->fetch();
        
        if (!$currentOrder) {
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        $oldStatus = $currentOrder['status'];
        
        // Update the status
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$status, $orderId]);
        
        // If status changed from 'pending' to 'delivered', update inventory
        if ($oldStatus === 'pending' && $status === 'delivered') {
            $items = getOrderItems($pdo, $orderId);
            if (!empty($items)) {
                $inventoryResult = updateInventoryStock($pdo, $items);
                if (!$inventoryResult['success']) {
                    error_log("Inventory update failed: " . $inventoryResult['error']);
                    // Don't fail the status update, just log the error
                }
            }
        }
        
        return ['success' => true];
    } catch(PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function deleteOrder($pdo, $orderId) {
    try {
        $pdo->beginTransaction();
        
        // First delete order items
        $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        
        // Then delete the order
        $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        $pdo->commit();
        return ['success' => true];
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getInventory($pdo, $category = 'all', $stockLevel = 'all', $status = 'all', $search = '') {
    try {
        $sql = "SELECT * FROM inventory WHERE 1=1";
        $params = [];
        
        if ($category !== 'all') {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        
        if (!empty($search)) {
            $sql .= " AND (name LIKE ? OR sku LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        
        if ($stockLevel !== 'all') {
            if ($stockLevel === 'low') {
                $sql .= " AND stock > 0 AND stock <= 50";
            } elseif ($stockLevel === 'normal') {
                $sql .= " AND stock > 50 AND stock <= 500";
            } elseif ($stockLevel === 'high') {
                $sql .= " AND stock > 500";
            }
        }
        
        if ($status !== 'all') {
            if ($status === 'In Stock') {
                $sql .= " AND stock > 0";
            } elseif ($status === 'No Stock') {
                $sql .= " AND stock = 0";
            }
        }
        
        $sql .= " ORDER BY name ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inventory = $stmt->fetchAll();
        
        foreach ($inventory as &$item) {
            if ($item['stock'] == 0) {
                $item['status'] = 'No Stock';
            } elseif ($item['stock'] <= 50) {
                $item['status'] = 'Low Stock';
            } else {
                $item['status'] = 'In Stock';
            }
        }
        
        $statsSql = "SELECT 
            COUNT(*) as total_items,
            SUM(stock) as total_units,
            SUM(stock * price) as total_value,
            SUM(CASE WHEN stock > 0 AND stock <= 50 THEN 1 ELSE 0 END) as low_stock_count,
            SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) as out_of_stock_count
            FROM inventory";
        $statsStmt = $pdo->query($statsSql);
        $stats = $statsStmt->fetch();
        
        return [
            'success' => true,
            'data' => $inventory,
            'stats' => [
                'total_items' => (int)($stats['total_items'] ?? 0),
                'total_units' => (int)($stats['total_units'] ?? 0),
                'total_value' => (float)($stats['total_value'] ?? 0),
                'low_stock_count' => (int)($stats['low_stock_count'] ?? 0),
                'out_of_stock_count' => (int)($stats['out_of_stock_count'] ?? 0)
            ]
        ];
        
    } catch(PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function updateInventoryStock($pdo, $items) {
    try {
        $pdo->beginTransaction();
        
        // Stock INCREASES when orders are delivered (receiving inventory)
        $stmt = $pdo->prepare("UPDATE inventory SET stock = stock + ? WHERE sku = ?");
        
        foreach ($items as $item) {
            // Use the correct column names from getOrderItems
            $sku = $item['item_sku'];
            $quantity = $item['quantity'];
            $name = $item['item_name'];
            $price = $item['price'];
            
            $stmt->execute([$quantity, $sku]);
            
            if ($stmt->rowCount() === 0) {
                // Product doesn't exist, insert it
                $insertStmt = $pdo->prepare("
                    INSERT INTO inventory (name, sku, category, stock, price) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                $insertStmt->execute([
                    $name,
                    $sku,
                    'Uncategorized',
                    $quantity,
                    $price
                ]);
            }
        }
        
        $pdo->commit();
        return ['success' => true, 'message' => 'Inventory increased successfully'];
        
    } catch(Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function addInventoryItem($pdo, $itemData) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO inventory (name, sku, category, stock, price, image_url)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            stock = stock + VALUES(stock),
            price = VALUES(price)
        ");
        
        $stmt->execute([
            $itemData['name'],
            $itemData['sku'],
            $itemData['category'],
            $itemData['stock'],
            $itemData['price'],
            $itemData['image_url'] ?? null
        ]);
        
        return ['success' => true, 'id' => $pdo->lastInsertId()];
        
    } catch(PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function deleteInventoryItem($pdo, $id) {
    try {
        $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
        $stmt->execute([$id]);
        return ['success' => true];
    } catch(PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>