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
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

try {
    $pdo = getDB();
    
    $sql = "SELECT 
                co.*,
                (SELECT COUNT(*) FROM customer_ordered_products WHERE order_id = co.id) as item_count
            FROM customer_details co 
            WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (co.order_number LIKE ? OR co.customer_name LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if (!empty($status) && $status !== 'all' && $status !== '') {
        $sql .= " AND co.status = ?";
        $params[] = $status;
    }
    
    if (!empty($dateFrom)) {
        $sql .= " AND DATE(co.created_at) >= ?";
        $params[] = $dateFrom;
    }
    
    if (!empty($dateTo)) {
        $sql .= " AND DATE(co.created_at) <= ?";
        $params[] = $dateTo;
    }
    
    $sql .= " ORDER BY co.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    
    foreach ($orders as &$order) {
        $itemStmt = $pdo->prepare("
            SELECT * FROM customer_ordered_products WHERE order_id = ?
        ");
        $itemStmt->execute([$order['id']]);
        $order['items'] = $itemStmt->fetchAll();
        
        $order['total_items'] = array_sum(array_column($order['items'], 'quantity'));
        $order['order_date_formatted'] = date('Y-m-d', strtotime($order['created_at']));
    }
    
    $statsStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_orders,
            COALESCE(SUM(CASE WHEN status IN ('shipped', 'delivered') THEN total_amount ELSE 0 END), 0) as total_revenue,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
            SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
            SUM(CASE WHEN status = 'shipped' THEN 1 ELSE 0 END) as shipped,
            SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
            SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
            SUM(CASE WHEN status = 'archived' THEN 1 ELSE 0 END) as archived,
            COUNT(DISTINCT customer_name) as unique_customers,
            SUM(CASE WHEN status IN ('shipped', 'delivered') THEN (SELECT COALESCE(SUM(quantity), 0) FROM customer_ordered_products WHERE order_id = customer_details.id) ELSE 0 END) as total_items_sold
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
            'processing' => (int)($stats['processing'] ?? 0),
            'shipped' => (int)($stats['shipped'] ?? 0),
            'delivered' => (int)($stats['delivered'] ?? 0),
            'completed' => (int)($stats['completed'] ?? 0),
            'cancelled' => (int)($stats['cancelled'] ?? 0),
            'archived' => (int)($stats['archived'] ?? 0),
            'unique_customers' => (int)($stats['unique_customers'] ?? 0),
            'total_items_sold' => (int)($stats['total_items_sold'] ?? 0)
        ]
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>