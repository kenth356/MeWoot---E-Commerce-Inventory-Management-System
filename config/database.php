<?php
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
        
        $stmt = $pdo->prepare("
            INSERT INTO orders (
                order_reference, supplier_name, supplier_category, priority, 
                delivery_terminal, warehouse_name, warehouse_id, subtotal, priority_fee, terminal_fee, 
                total_amount, lead_time_min, lead_time_max, items_count, order_date, status, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'pending', NOW())
        ");
        
        $warehouseId = null;
        $warehouseName = $orderData['deliveryWarehouse'] ?? $orderData['warehouseName'] ?? null;
        
        $warehouseMap = [
            "Mariano's Delivery Hub" => 1,
            "Hanubis Center" => 2,
            "Canto Warehouse Inc." => 3,
            "Gervas Logistics Facility" => 4
        ];
        
        if ($warehouseName && isset($warehouseMap[$warehouseName])) {
            $warehouseId = $warehouseMap[$warehouseName];
        }
        
        $stmt->execute([
            $orderData['orderReference'],
            $orderData['supplier'],
            $orderData['supplierCategory'] ?? null,
            $orderData['priority'],
            $orderData['deliveryTerminal'] ?? $orderData['deliveryWarehouse'],
            $warehouseName,
            $warehouseId,
            $orderData['subtotal'],
            $orderData['priorityFee'],
            $orderData['terminalFee'] ?? 0,
            $orderData['total'],
            $orderData['leadTimeMin'],
            $orderData['leadTimeMax'],
            array_sum(array_column($orderData['items'], 'quantity'))
        ]);
        
        $orderId = $pdo->lastInsertId();
        
        $itemStmt = $pdo->prepare("
            INSERT INTO order_items (order_id, item_name, item_sku, quantity, price, item_image) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
                
        foreach ($orderData['items'] as $item) {
            $itemStmt->execute([
                $orderId,
                $item['name'],
                $item['sku'] ?? '',
                $item['quantity'],
                $item['price'],
                $item['image'] ?? null
            ]);
        }
        
        $pdo->commit();
        return ['success' => true, 'order_id' => $orderId, 'order_reference' => $orderData['orderReference']];
        
    } catch(PDOException $e) {
        $pdo->rollBack();
        error_log("Save order error: " . $e->getMessage());
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
        $stmt = $pdo->prepare("SELECT item_name, item_sku, quantity, price, item_image FROM order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $items;
    } catch(PDOException $e) {
        error_log("getOrderItems error: " . $e->getMessage());
        return [];
    }
}

function updateOrderStatus($pdo, $orderId, $status) {
    try {
        $allowedStatuses = ['pending', 'delivered', 'archived', 'cancelled'];
        if (!in_array($status, $allowedStatuses)) {
            return ['success' => false, 'error' => 'Invalid status'];
        }
        
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$status, $orderId]);
        
        if ($stmt->rowCount() > 0) {
            return ['success' => true, 'message' => 'Order status updated'];
        } else {
            return ['success' => false, 'error' => 'Order not found or no changes made'];
        }
    } catch(PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function deleteOrder($pdo, $orderId) {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        
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

function updateInventoryStock($pdo, $items, $warehouseId = null, $warehouseName = null) {
    try {
        if (!$warehouseId) {
            error_log("updateInventoryStock called without warehouse_id!");
            return ['success' => false, 'error' => 'Warehouse ID is required to update inventory'];
        }
        
        $warehouseNames = [
            1 => "Mariano's Delivery Hub",
            2 => "Hanubis Center", 
            3 => "Canto Warehouse Inc.",
            4 => "Gervas Logistics Facility"
        ];
        
        $warehouseNameToUse = $warehouseName ?: ($warehouseNames[$warehouseId] ?? "Warehouse $warehouseId");
        
        foreach ($items as $item) {
            $sku      = $item['item_sku'];
            $quantity = (int)$item['quantity'];
            $name     = $item['item_name'];
            $price    = (float)$item['price'];
            $image    = $item['item_image'] ?? null;

            if (empty($sku) || empty($name)) {
                error_log("Skipping item with empty sku or name: " . json_encode($item));
                continue;
            }

            $categoryStmt = $pdo->prepare("
                SELECT s.category FROM supplier_products sp
                JOIN suppliers s ON s.id = sp.supplier_id
                WHERE sp.product_sku = ? OR sp.product_name = ?
                LIMIT 1
            ");
            $categoryStmt->execute([$sku, $name]);
            $categoryResult = $categoryStmt->fetch(PDO::FETCH_ASSOC);
            $category = $categoryResult ? $categoryResult['category'] : 'Uncategorized';

            $checkStmt = $pdo->prepare("
                SELECT id, stock, price, sku FROM inventory 
                WHERE warehouse_id = ? AND (sku = ? OR name = ?)
                LIMIT 1
            ");
            $checkStmt->execute([$warehouseId, $sku, $name]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $newStock = $existing['stock'] + $quantity;
                $newPrice = ($price > 0) ? $price : $existing['price'];
                
                $updateStmt = $pdo->prepare("
                    UPDATE inventory 
                    SET stock = ?, 
                        price = ?,
                        image_url = COALESCE(NULLIF(image_url, ''), ?),
                        category = CASE 
                            WHEN (category = 'Uncategorized' OR category IS NULL) AND ? != 'Uncategorized' 
                            THEN ? 
                            ELSE category 
                        END,
                        updated_at = NOW()
                    WHERE id = ?
                ");
                $updateStmt->execute([
                    $newStock, 
                    $newPrice, 
                    $image,
                    $category, $category,
                    $existing['id']
                ]);
                
                error_log("Updated inventory for {$name} in warehouse {$warehouseId}: stock {$existing['stock']} → {$newStock}");
            } else {
                $insertStmt = $pdo->prepare("
                    INSERT INTO inventory (name, sku, category, stock, price, image_url, warehouse_id, warehouse_name, created_at, updated_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $insertStmt->execute([
                    $name, 
                    $sku, 
                    $category, 
                    $quantity, 
                    $price, 
                    $image, 
                    $warehouseId, 
                    $warehouseNameToUse
                ]);
                
                error_log("Created new inventory entry for {$name} in warehouse {$warehouseId} with stock {$quantity}");
            }
        }

        return ['success' => true];

    } catch (Exception $e) {
        error_log("updateInventoryStock error: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function addInventoryItem($pdo, $itemData) {
    try {
        $category = $itemData['category'];
        if (empty($category) || $category === 'Uncategorized') {
            $categoryStmt = $pdo->prepare("
                SELECT s.category 
                FROM supplier_products sp
                JOIN suppliers s ON s.id = sp.supplier_id
                WHERE sp.product_sku = ? OR sp.product_name = ?
                LIMIT 1
            ");
            $categoryStmt->execute([$itemData['sku'], $itemData['name']]);
            $result = $categoryStmt->fetch();
            if ($result && $result['category']) {
                $category = $result['category'];
            }
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO inventory (name, sku, category, stock, price, image_url, warehouse_id, warehouse_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            stock = stock + VALUES(stock),
            price = VALUES(price),
            image_url = COALESCE(image_url, VALUES(image_url)),
            category = CASE 
                WHEN category = 'Uncategorized' AND VALUES(category) != 'Uncategorized' 
                THEN VALUES(category) 
                ELSE category 
            END
        ");
        
        $stmt->execute([
            $itemData['name'],
            $itemData['sku'],
            $category,
            $itemData['stock'],
            $itemData['price'],
            $itemData['image_url'] ?? null,
            $itemData['warehouse_id'] ?? null,
            $itemData['warehouse_name'] ?? null
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