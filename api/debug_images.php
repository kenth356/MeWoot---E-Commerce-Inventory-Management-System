<?php
header('Content-Type: text/html');

require_once '../config/database.php';

$pdo = getDB();

echo "<h1>Image Debugging</h1>";

// Check supplier_products table
echo "<h2>Supplier Products with Images:</h2>";
$stmt = $pdo->query("
    SELECT id, product_name, product_sku, product_image 
    FROM supplier_products 
    WHERE product_image IS NOT NULL AND product_image != ''
    LIMIT 10
");
$products = $stmt->fetchAll();
if (count($products) > 0) {
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>ID</th><th>Product Name</th><th>SKU</th><th>Image URL</th></tr>";
    foreach ($products as $p) {
        echo "<tr>";
        echo "<td>{$p['id']}</td>";
        echo "<td>{$p['product_name']}</td>";
        echo "<td>{$p['product_sku']}</td>";
        echo "<td><img src='{$p['product_image']}' width='50' height='50' style='object-fit:cover' onerror=\"this.src='https://placehold.co/50x50?text=No+Image'\"></td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:red'>No supplier products with images found!</p>";
}

// Check inventory table
echo "<h2>Inventory Items:</h2>";
$stmt2 = $pdo->query("
    SELECT id, name, sku, image_url 
    FROM inventory 
    LIMIT 10
");
$inventory = $stmt2->fetchAll();
if (count($inventory) > 0) {
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>ID</th><th>Name</th><th>SKU</th><th>Image URL</th></tr>";
    foreach ($inventory as $i) {
        echo "<tr>";
        echo "<td>{$i['id']}</td>";
        echo "<td>{$i['name']}</td>";
        echo "<td>{$i['sku']}</td>";
        echo "<td>";
        if (!empty($i['image_url'])) {
            echo "<img src='{$i['image_url']}' width='50' height='50' style='object-fit:cover'>";
        } else {
            echo "<span style='color:gray'>No image</span>";
        }
        echo "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No inventory items found</p>";
}

// Check for matching SKUs
echo "<h2>Matching SKUs between tables:</h2>";
$stmt3 = $pdo->query("
    SELECT i.id as inventory_id, i.name, i.sku, i.image_url as inventory_image,
           sp.id as supplier_id, sp.product_image as supplier_image
    FROM inventory i
    JOIN supplier_products sp ON sp.product_sku = i.sku
    LIMIT 10
");
$matches = $stmt3->fetchAll();
if (count($matches) > 0) {
    echo "<table border='1' cellpadding='8'>";
    echo "<tr><th>Inventory SKU</th><th>Inventory Image</th><th>Supplier Image</th><th>Status</th></tr>";
    foreach ($matches as $m) {
        $hasImage = !empty($m['supplier_image']) ? "Has image" : "No image";
        $needsUpdate = empty($m['inventory_image']) && !empty($m['supplier_image']) ? "NEEDS UPDATE" : "OK";
        echo "<tr>";
        echo "<td>{$m['sku']}</td>";
        echo "<td>" . (empty($m['inventory_image']) ? "<span style='color:red'>Empty</span>" : "<span style='color:green'>Has image</span>") . "</td>";
        echo "<td>" . (empty($m['supplier_image']) ? "<span style='color:red'>Empty</span>" : "<span style='color:green'>Has image</span>") . "</td>";
        echo "<td style='color:" . ($needsUpdate == "NEEDS UPDATE" ? "orange" : "green") . "'>$needsUpdate</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No matching SKUs found between inventory and supplier_products</p>";
}
?>