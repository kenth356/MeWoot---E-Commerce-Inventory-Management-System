<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../config/database.php';

$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$minPrice = $_GET['min_price'] ?? 0;
$maxPrice = $_GET['max_price'] ?? 0;
$sort = $_GET['sort'] ?? 'name';

try {
    $pdo = getDB();
    
    $sql = "SELECT 
                id,
                name,
                sku,
                category,
                stock,
                price,
                image_url,
                created_at,
                updated_at
            FROM inventory 
            WHERE stock > 0";
    $params = [];
    
    if (!empty($search)) {
        $sql .= " AND (name LIKE ? OR sku LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if (!empty($category)) {
        $sql .= " AND category = ?";
        $params[] = $category;
    }
    
    if ($minPrice > 0) {
        $sql .= " AND price >= ?";
        $params[] = $minPrice;
    }
    
    if ($maxPrice > 0) {
        $sql .= " AND price <= ?";
        $params[] = $maxPrice;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rawProducts = $stmt->fetchAll();

    $mergedProducts = [];
    foreach ($rawProducts as $product) {

        $key = strtolower(trim($product['name']));
        $key = preg_replace('/[^a-z0-9]/', '', $key);
        
        if (isset($mergedProducts[$key])) {
            $mergedProducts[$key]['stock'] = (int)$mergedProducts[$key]['stock'] + (int)$product['stock'];
            
            $currentPrice = (float)$mergedProducts[$key]['price'];
            $newPrice = (float)$product['price'];
            if ($newPrice > $currentPrice) {
                $mergedProducts[$key]['price'] = $product['price'];
            }
    
            if (empty($mergedProducts[$key]['image_url']) && !empty($product['image_url'])) {
                $mergedProducts[$key]['image_url'] = $product['image_url'];
            }

        } else {

            $mergedProducts[$key] = [
                'id' => $product['id'],
                'name' => $product['name'],
                'sku' => $product['sku'],
                'category' => $product['category'] ?? 'Uncategorized',
                'stock' => (int)$product['stock'],
                'price' => (float)$product['price'],
                'image_url' => $product['image_url'],
                'created_at' => $product['created_at'],
                'updated_at' => $product['updated_at']
            ];
        }
    }
    
  
    $products = array_values($mergedProducts);
    
  
    $products = array_filter($products, function($p) {
        return $p['stock'] > 0;
    });
    

    if ($sort === 'price_low') {
        usort($products, function($a, $b) {
            return $a['price'] - $b['price'];
        });
    } elseif ($sort === 'price_high') {
        usort($products, function($a, $b) {
            return $b['price'] - $a['price'];
        });
    } else {
        usort($products, function($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
    }
    
    // Get unique categories for filter (from merged products)
    $categories = array_unique(array_column($products, 'category'));
    $categories = array_filter($categories, function($cat) {
        return $cat && $cat !== 'Uncategorized';
    });
    sort($categories);
    
    echo json_encode([
        'success' => true,
        'products' => array_values($products),
        'categories' => array_values($categories),
        'count' => count($products),
        'raw_count' => count($rawProducts),
        'message' => 'Products merged by name in PHP'
    ]);
    
} catch(PDOException $e) {
    error_log("get_customer_products.php error: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}
?>