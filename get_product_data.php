<?php
session_start();

// Redirect if not admin
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$conn = new mysqli("localhost", "root", "", "hardware_db");
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit();
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Product ID required']);
    exit();
}

$product_id = intval($_GET['id']);

// Fetch product data
$product_query = $conn->query("SELECT * FROM products_ko WHERE id = $product_id AND archive = 0");

if (!$product_query || $product_query->num_rows == 0) {
    http_response_code(404);
    echo json_encode(['error' => 'Product not found']);
    exit();
}

$product = $product_query->fetch_assoc();

// Fetch variants
$variants = [];
// Include last stock change timestamp and last change amount per variant
$variants_query = $conn->query("SELECT pv.*, 
    (SELECT MAX(created_at) FROM stock_log sl WHERE sl.variant_id = pv.id) AS last_stock_update,
    (SELECT sl2.change_amount FROM stock_log sl2 WHERE sl2.variant_id = pv.id ORDER BY sl2.created_at DESC LIMIT 1) AS last_change_amount
    FROM product_variants pv WHERE product_id = $product_id");

if ($variants_query) {
    while ($variant = $variants_query->fetch_assoc()) {
        $final_price = $product['price'] + $variant['price_modifier'];
        $variants[] = [
            'id' => $variant['id'],
            'size' => $variant['size'],
            'stock' => $variant['stock'],
            'price_modifier' => $variant['price_modifier'],
            'final_price' => $final_price,
            'image' => $variant['image'] ?? '',
            'last_stock_update' => $variant['last_stock_update'] ?? null,
            'last_change_amount' => isset($variant['last_change_amount']) ? intval($variant['last_change_amount']) : null
        ];
    }
}

$response = [
    'id' => $product['id'],
    'name' => $product['name'],
    'category' => $product['category'],
    'price' => $product['price'],
    'stock' => $product['stock'],
    'image' => $product['image'],
    'variants' => $variants
];

header('Content-Type: application/json');
echo json_encode($response);

$conn->close();
?>
