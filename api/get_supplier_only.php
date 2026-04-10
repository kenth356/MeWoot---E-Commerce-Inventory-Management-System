<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once '../config/database.php';

$id = $_GET['id'] ?? 0;

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'Supplier ID required']);
    exit;
}

$pdo = getDB();

try {
    $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ?");
    $stmt->execute([$id]);
    $supplier = $stmt->fetch();
    
    if ($supplier) {
        echo json_encode(['success' => true, 'supplier' => $supplier]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Supplier not found']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>