<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$status = isset($_GET['status']) ? $_GET['status'] : '';
$search = isset($_GET['search']) ? $_GET['search'] : '';

try {
    $pdo = getDB();
    
    // Build the SQL query with search functionality
    $sql = "SELECT o.* FROM orders o WHERE 1=1";
    $params = [];
    
    if (!empty($status) && $status !== 'all') {
        $sql .= " AND o.status = :status";
        $params[':status'] = $status;
    }
    
    if (!empty($search)) {
        $sql .= " AND (o.order_reference LIKE :search OR o.supplier_name LIKE :search OR o.supplier_category LIKE :search)";
        $params[':search'] = "%$search%";
    }
    
    $sql .= " ORDER BY o.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fetch items for each order
    foreach ($orders as &$order) {
        $itemsSql = "SELECT 
                        item_name,
                        item_sku,
                        quantity,
                        price,
                        (quantity * price) as total_price,
                        item_image
                    FROM order_items 
                    WHERE order_id = :order_id";
        $itemsStmt = $pdo->prepare($itemsSql);
        $itemsStmt->execute([':order_id' => $order['id']]);
        $order['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get counts for all orders (not just filtered ones)
    $countSql = "SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived
                FROM orders";
    $countStmt = $pdo->query($countSql);
    $counts = $countStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true, 
        'orders' => $orders, 
        'counts' => [
            'total_orders' => (int)($counts['total_orders'] ?? 0),
            'pending' => (int)($counts['pending'] ?? 0),
            'delivered' => (int)($counts['delivered'] ?? 0),
            'cancelled' => (int)($counts['cancelled'] ?? 0),
            'archived' => (int)($counts['archived'] ?? 0)
        ]
    ]);
    
} catch(PDOException $e) {
    error_log("get_orders.php error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>