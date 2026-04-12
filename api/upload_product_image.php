<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get the old image URL if provided
$oldImage = isset($_POST['old_image']) ? $_POST['old_image'] : null;

$uploadDir = __DIR__ . '/../uploads/products/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (!isset($_FILES['product_image']) || $_FILES['product_image']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['product_image'];
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
$maxSize = 5 * 1024 * 1024;

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: JPG, PNG, GIF, WEBP, AVIF']);
    exit;
}

if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File too large. Max 5MB']);
    exit;
}

// Delete old image if it exists and is different from the new one
if ($oldImage && !empty($oldImage)) {
    $oldImagePath = __DIR__ . '/../' . $oldImage;
    if (file_exists($oldImagePath)) {
        unlink($oldImagePath);
        error_log("Deleted old image: " . $oldImage);
    }
}

$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = uniqid() . '_' . time() . '.' . $extension;
$filepath = $uploadDir . $filename;

if (move_uploaded_file($file['tmp_name'], $filepath)) {
    $imageUrl = 'uploads/products/' . $filename;
    echo json_encode(['success' => true, 'image_url' => $imageUrl]);
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
}
?>