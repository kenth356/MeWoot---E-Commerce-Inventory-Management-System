<?php
// Turn off all error reporting for clean JSON output
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

// Get parameters
$category = isset($_GET['category']) ? $_GET['category'] : 'all';
$stockLevel = isset($_GET['stockLevel']) ? $_GET['stockLevel'] : 'all';
$status = isset($_GET['status']) ? $_GET['status'] : 'all';
$search = isset($_GET['search']) ? $_GET['search'] : '';

// Get arrays if provided
$categories = isset($_GET['categories']) ? (array)$_GET['categories'] : [];
$stockLevels = isset($_GET['stockLevels']) ? (array)$_GET['stockLevels'] : [];
$statuses = isset($_GET['statuses']) ? (array)$_GET['statuses'] : [];

try {
    $pdo = getDB();
    
    $sql = "SELECT * FROM inventory WHERE 1=1";
    $params = [];
    
    // Handle multiple categories
    if (!empty($categories)) {
        $placeholders = implode(',', array_fill(0, count($categories), '?'));
        $sql .= " AND category IN ($placeholders)";
        $params = array_merge($params, $categories);
    } elseif ($category !== 'all') {
        $sql .= " AND category = ?";
        $params[] = $category;
    }
    
    // Search filter
    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR sku LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    // Handle multiple stock levels
    if (!empty($stockLevels)) {
        $stockConditions = [];
        foreach ($stockLevels as $level) {
            if ($level === 'low') {
                $stockConditions[] = "(stock > 0 AND stock <= 50)";
            } elseif ($level === 'normal') {
                $stockConditions[] = "(stock > 50 AND stock <= 500)";
            } elseif ($level === 'high') {
                $stockConditions[] = "(stock > 500)";
            }
        }
        if (!empty($stockConditions)) {
            $sql .= " AND (" . implode(' OR ', $stockConditions) . ")";
        }
    } elseif ($stockLevel !== 'all') {
        if ($stockLevel === 'low') {
            $sql .= " AND stock > 0 AND stock <= 50";
        } elseif ($stockLevel === 'normal') {
            $sql .= " AND stock > 50 AND stock <= 500";
        } elseif ($stockLevel === 'high') {
            $sql .= " AND stock > 500";
        }
    }
    
    // Handle multiple statuses
    if (!empty($statuses)) {
        $statusConditions = [];
        foreach ($statuses as $stat) {
            if ($stat === 'In Stock') {
                $statusConditions[] = "stock > 50";
            } elseif ($stat === 'Low Stock') {
                $statusConditions[] = "(stock > 0 AND stock <= 50)";
            } elseif ($stat === 'No Stock') {
                $statusConditions[] = "stock = 0";
            }
        }
        if (!empty($statusConditions)) {
            $sql .= " AND (" . implode(' OR ', $statusConditions) . ")";
        }
    } elseif ($status !== 'all') {
        if ($status === 'In Stock') {
            $sql .= " AND stock > 50";
        } elseif ($status === 'Low Stock') {
            $sql .= " AND stock > 0 AND stock <= 50";
        } elseif ($status === 'No Stock') {
            $sql .= " AND stock = 0";
        }
    }
    
    $sql .= " ORDER BY name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $inventory = $stmt->fetchAll();
    
    // Add status field to each item
    foreach ($inventory as &$item) {
        if ($item['stock'] == 0) {
            $item['status'] = 'No Stock';
        } elseif ($item['stock'] <= 50) {
            $item['status'] = 'Low Stock';
        } else {
            $item['status'] = 'In Stock';
        }
    }
    
    // Get statistics
    $statsSql = "SELECT 
        COUNT(*) as total_items,
        SUM(stock) as total_units,
        SUM(stock * price) as total_value,
        SUM(CASE WHEN stock > 0 AND stock <= 50 THEN 1 ELSE 0 END) as low_stock_count,
        SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) as out_of_stock_count
        FROM inventory";
    $statsStmt = $pdo->query($statsSql);
    $stats = $statsStmt->fetch();
    
    $response = [
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
    
    echo json_encode($response);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch(Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
?>