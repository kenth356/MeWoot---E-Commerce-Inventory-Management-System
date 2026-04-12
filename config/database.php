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
                $item['image'] ?? null
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
        return $stmt->fetchAll();
    } catch(PDOException $e) {
        return [];
    }
}

function updateOrderStatus($pdo, $orderId, $status) {
    try {
        $stmt = $pdo->prepare("SELECT status FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $currentOrder = $stmt->fetch();
        
        if (!$currentOrder) {
            return ['success' => false, 'error' => 'Order not found'];
        }
        
        $oldStatus = $currentOrder['status'];
        
        $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
        $stmt->execute([$status, $orderId]);
        
        if ($oldStatus === 'pending' && $status === 'delivered') {
            $items = getOrderItems($pdo, $orderId);
            if (!empty($items)) {
                $inventoryResult = updateInventoryStock($pdo, $items);
                if (!$inventoryResult['success']) {
                    error_log("Inventory update failed: " . $inventoryResult['error']);
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

function updateInventoryStock($pdo, $items) {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("UPDATE inventory SET stock = stock + ? WHERE sku = ?");
        
        foreach ($items as $item) {
            $sku = $item['item_sku'];
            $quantity = $item['quantity'];
            $name = $item['item_name'];
            $price = $item['price'];
            $image = $item['item_image'] ?? null;
            
            // First, try to get the category from supplier_products
            $categoryStmt = $pdo->prepare("
                SELECT s.category 
                FROM supplier_products sp
                JOIN suppliers s ON s.id = sp.supplier_id
                WHERE sp.product_sku = ? OR sp.product_name = ?
                LIMIT 1
            ");
            $categoryStmt->execute([$sku, $name]);
            $categoryResult = $categoryStmt->fetch();
            $category = $categoryResult ? $categoryResult['category'] : 'Uncategorized';
            
            $stmt->execute([$quantity, $sku]);
            
            if ($stmt->rowCount() === 0) {
                // Product doesn't exist, insert it with correct category
                $insertStmt = $pdo->prepare("
                    INSERT INTO inventory (name, sku, category, stock, price, image_url) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $insertStmt->execute([
                    $name,
                    $sku,
                    $category,
                    $quantity,
                    $price,
                    $image
                ]);
            } else {
                // Product exists, update category if it's 'Uncategorized' and we found a real category
                if ($category !== 'Uncategorized') {
                    $updateCategoryStmt = $pdo->prepare("
                        UPDATE inventory SET category = ? WHERE sku = ? AND (category = 'Uncategorized' OR category IS NULL)
                    ");
                    $updateCategoryStmt->execute([$category, $sku]);
                }
                
                // Update image if not already set
                $updateImageStmt = $pdo->prepare("
                    UPDATE inventory SET image_url = COALESCE(image_url, ?) WHERE sku = ? AND (image_url IS NULL OR image_url = '')
                ");
                $updateImageStmt->execute([$image, $sku]);
            }
        }
        
        $pdo->commit();
        return ['success' => true, 'message' => 'Inventory updated successfully'];
        
    } catch(Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function addInventoryItem($pdo, $itemData) {
    try {
        // If category is missing or Uncategorized, try to find it from supplier_products
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
            INSERT INTO inventory (name, sku, category, stock, price, image_url)
            VALUES (?, ?, ?, ?, ?, ?)
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