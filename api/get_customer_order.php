<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

try {
    $pdo = getDB();
    
    $sql = "SELECT co.*, 
            (SELECT COALESCE(SUM(quantity), 0) FROM customer_ordered_products WHERE order_id = co.id) as item_count
            FROM customer_details co WHERE 1=1";
    $params = [];
    
    if (!empty($status)) {
        $sql .= " AND co.status = ?";
        $params[] = $status;
    }
    
    if (!empty($search)) {
        $sql .= " AND (co.order_number LIKE ? OR co.customer_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $sql .= " ORDER BY co.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    
    // Get order items for each order
    foreach ($orders as &$order) {
        $itemStmt = $pdo->prepare("SELECT * FROM customer_ordered_products WHERE order_id = ?");
        $itemStmt->execute([$order['id']]);
        $order['items'] = $itemStmt->fetchAll();
    }
    
    // Get stats
    $statsStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_orders,
            SUM(total_amount) as total_revenue,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing
        FROM customer_details
    ");
    $stats = $statsStmt->fetch();
    
    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'stats' => [
            'total_orders' => (int)($stats['total_orders'] ?? 0),
            'total_revenue' => (float)($stats['total_revenue'] ?? 0),
            'pending' => (int)($stats['pending'] ?? 0),
            'delivered' => (int)($stats['delivered'] ?? 0),
            'cancelled' => (int)($stats['cancelled'] ?? 0),
            'shipped' => (int)($stats['shipped'] ?? 0),
            'processing' => (int)($stats['processing'] ?? 0)
        ]
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>