<?php
// api/register.php
// Set CORS headers
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed. Use POST.']);
    exit();
}

// Include database configuration
require_once '../config/database.php';

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON input']);
    exit();
}

// Extract and sanitize input
$full_name = isset($input['full_name']) ? trim($input['full_name']) : '';
$email = isset($input['email']) ? strtolower(trim($input['email'])) : '';
$password = isset($input['password']) ? $input['password'] : '';
$confirm = isset($input['confirm_password']) ? $input['confirm_password'] : '';

// Validation
if (empty($full_name) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'All fields are required.']);
    exit();
}

if ($password !== $confirm) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
    exit();
}

if (strlen($password) < 8) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
    exit();
}

// Hash the password
$password_hash = password_hash($password, PASSWORD_DEFAULT);

try {
    $pdo = getDB();
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    
    if ($stmt->rowCount() > 0) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Email is already registered.']);
        exit();
    }
    
    // Insert new user
    $stmt = $pdo->prepare("INSERT INTO users (full_name, email, password_hash, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->execute([$full_name, $email, $password_hash]);
    
    http_response_code(201);
    echo json_encode(['success' => true, 'message' => 'Account created successfully!']);
    
} catch(PDOException $e) {
    // Log error (you might want to log this to a file)
    error_log("Registration error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error. Please try again.']);
    exit();
}
?>