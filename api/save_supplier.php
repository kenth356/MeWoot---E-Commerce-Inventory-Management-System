<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, PUT, OPTIONS');
require_once '../config/database.php';

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['name'])) {
    echo json_encode(['success' => false, 'error' => 'Supplier name required']);
    exit;
}

$pdo = getDB();

try {
    if (isset($data['id']) && $data['id']) {
        // UPDATE existing supplier (for editing)
        $stmt = $pdo->prepare("
            UPDATE suppliers SET 
                name = ?, category = ?, contact_person = ?, email = ?, 
                phone = ?, address = ?, lead_time = ?, min_order_qty = ?,
                payment_terms = ?, status = ?, notes = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $data['name'],
            $data['category'],
            $data['contact_person'],
            $data['email'],
            $data['phone'],
            $data['address'],
            $data['lead_time'],
            $data['min_order_qty'],
            $data['payment_terms'],
            $data['status'],
            $data['notes'],
            $data['id']
        ]);
        echo json_encode(['success' => true, 'message' => 'Supplier updated']);
    } else {
        // No INSERT allowed here - suppliers only created via ordering.html
        echo json_encode(['success' => false, 'error' => 'Suppliers can only be created through order creation']);
    }
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>