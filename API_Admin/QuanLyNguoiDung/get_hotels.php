<?php
require_once '../../connect.php';
// Thiết lập các header cho phép truy cập từ các nguồn khác
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Kiểm tra phương thức HTTP
$method = $_SERVER['REQUEST_METHOD'];

$sql = "SELECT id, ten FROM khach_san ORDER BY ten ASC";
$result = $conn->query($sql);

$hotels = [];
while ($row = $result->fetch_assoc()) {
    $hotels[] = $row;
}

echo json_encode($hotels);
?>
