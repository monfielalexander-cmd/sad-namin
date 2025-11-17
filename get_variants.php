<?php
header('Content-Type: application/json');

$conn = new mysqli("localhost", "root", "", "hardware_db");
if ($conn->connect_error) {
    die(json_encode(['error' => 'Connection failed']));
}

$product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;

if ($product_id <= 0) {
    echo json_encode([]);
    exit();
}

$variants = $conn->query("SELECT id, size, stock, price_modifier FROM product_variants WHERE product_id = $product_id ORDER BY size ASC");

$result = [];
if ($variants && $variants->num_rows > 0) {
    while ($row = $variants->fetch_assoc()) {
        $result[] = $row;
    }
}

echo json_encode($result);
$conn->close();
?>
