<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Only POST method allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['name']) || !isset($input['sku']) || !isset($input['category']) || !isset($input['stock']) || !isset($input['price'])) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit();
}

$pdo = getDB();

$result = addInventoryItem($pdo, $input);
echo json_encode($result);
?>