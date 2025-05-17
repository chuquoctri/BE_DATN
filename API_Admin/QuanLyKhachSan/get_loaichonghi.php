<?php
require_once '../../connect.php';

// Thiết lập header CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

// Lấy tất cả loại chỗ nghỉ
function get_all_accommodation_types($conn) {
    $sql = "SELECT * FROM loai_cho_nghi ORDER BY id DESC";
    $result = $conn->query($sql);

    $types = [];
    while ($row = $result->fetch_assoc()) {
        $types[] = $row;
    }

    echo json_encode($types);
}

// Xử lý request
switch ($method) {
    case 'GET':
        if (isset($_GET['action']) && $_GET['action'] === 'all') {
            get_all_accommodation_types($conn);
        } else {
            echo json_encode(["status" => "error", "message" => "Missing or invalid action parameter."]);
        }
        break;

    case 'OPTIONS':
        http_response_code(200);
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Method not allowed."]);
}
?>
