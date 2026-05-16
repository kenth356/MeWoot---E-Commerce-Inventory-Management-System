<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once '../config/database.php';

$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';
$category = $_GET['category'] ?? '';

$pdo = getDB();

try {
    $sql = "SELECT s.*, 
            (SELECT COUNT(*) FROM supplier_products WHERE supplier_id = s.id) as product_count,
            (SELECT MAX(order_date) FROM orders WHERE supplier_name = s.name) as last_order_date
            FROM suppliers s WHERE 1=1";
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (s.name LIKE ? OR s.contact_person LIKE ? OR s.email LIKE ? OR s.phone LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if (!empty($status)) {
        $sql .= " AND s.status = ?";
        $params[] = $status;
    }
    
    if (!empty($category)) {
        $sql .= " AND s.category = ?";
        $params[] = $category;
    }
    
    $sql .= " ORDER BY s.name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $suppliers = $stmt->fetchAll();
    
    $statsStmt = $pdo->query("
        SELECT 
            COUNT(*) as total_suppliers,
            SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_suppliers,
            COUNT(DISTINCT category) as unique_categories,
            AVG(lead_time) as avg_lead_time
        FROM suppliers
    ");
    $stats = $statsStmt->fetch();
    
    echo json_encode([
        'success' => true,
        'suppliers' => $suppliers,
        'stats' => [
            'total_suppliers' => (int)($stats['total_suppliers'] ?? 0),
            'active_suppliers' => (int)($stats['active_suppliers'] ?? 0),
            'unique_categories' => (int)($stats['unique_categories'] ?? 0),
            'avg_lead_time' => round($stats['avg_lead_time'] ?? 0)
        ]
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>