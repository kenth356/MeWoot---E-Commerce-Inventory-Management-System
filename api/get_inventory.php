<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }

require_once '../config/database.php';

$warehouseId = isset($_GET['warehouse_id']) ? intval($_GET['warehouse_id']) : null;
$category    = isset($_GET['category']) ? $_GET['category'] : '';
$search      = isset($_GET['search']) ? $_GET['search'] : '';

try {
    $pdo = getDB();

    if ($warehouseId && $warehouseId > 0) {
        // warehouse.html view — show per-warehouse stock
        $sql = "SELECT *, (stock * price) as total_value 
                FROM inventory 
                WHERE warehouse_id = ?";
        $params = [$warehouseId];

        if (!empty($category)) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        if (!empty($search)) {
            $sql .= " AND (name LIKE ? OR sku LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " ORDER BY name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {
        // inventory.html view — show one row per product, stock summed across all warehouses
        $sql = "SELECT 
                    MIN(id) as id,
                    name,
                    sku,
                    category,
                    SUM(stock) as stock,
                    MAX(price) as price,
                    MAX(image_url) as image_url,
                    MIN(created_at) as created_at,
                    MAX(updated_at) as updated_at,
                    (SUM(stock) * MAX(price)) as total_value,
                    warehouse_id
                FROM inventory
                WHERE 1=1";
        $params = [];

        if (!empty($category)) {
            $sql .= " AND category = ?";
            $params[] = $category;
        }
        if (!empty($search)) {
            $sql .= " AND (name LIKE ? OR sku LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " GROUP BY sku, name, category, warehouse_id ORDER BY name ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['success' => true, 'data' => $inventory]);

} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>