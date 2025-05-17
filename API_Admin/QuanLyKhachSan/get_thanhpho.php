<?php
require_once '../../connect.php';

// Thiết lập header CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

// Lấy tất cả thành phố
function get_all_cities($conn) {
    $sql = "SELECT * FROM thanh_pho ORDER BY id DESC";
    $result = $conn->query($sql);

    $cities = [];
    while ($row = $result->fetch_assoc()) {
        $cities[] = $row;
    }

    echo json_encode($cities);
}

// Xử lý request
switch ($method) {
    case 'GET':
        if (isset($_GET['action']) && $_GET['action'] === 'all') {
            get_all_cities($conn);
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
