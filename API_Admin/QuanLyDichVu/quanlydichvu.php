<?php
// Hiển thị lỗi để debug (chỉ dùng khi phát triển)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Kết nối DB
require_once '../../connect.php';

// Thiết lập header CORS và JSON
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Xác định method
$method = $_SERVER['REQUEST_METHOD'];

// Đọc dữ liệu JSON từ request
$data = json_decode(file_get_contents("php://input"), true);

// Thêm dịch vụ
function add_service($conn, $data) {
    $requiredFields = ['ten', 'hinh_anh'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            echo json_encode(["status" => "error", "message" => "Missing field: $field"]);
            return;
        }
    }

    $ten = $data['ten'];
    $hinh_anh = $data['hinh_anh'];

    $sql = "INSERT INTO dich_vu (ten, hinh_anh) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $ten, $hinh_anh);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Service added successfully.", "id" => $stmt->insert_id]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to add service.", "error" => $stmt->error]);
    }
}

// Cập nhật dịch vụ
function update_service($conn, $data) {
    if (!isset($data['id'])) {
        echo json_encode(["status" => "error", "message" => "Missing service ID."]);
        return;
    }

    $id = $data['id'];
    $ten = $data['ten'];
    $hinh_anh = $data['hinh_anh'];

    $sql = "UPDATE dich_vu SET ten = ?, hinh_anh = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssi', $ten, $hinh_anh, $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Service updated successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to update service.", "error" => $stmt->error]);
    }
}

// Xóa dịch vụ
function delete_service($conn, $data) {
    if (!isset($data['id'])) {
        echo json_encode(["status" => "error", "message" => "Missing service ID."]);
        return;
    }

    $id = $data['id'];
    $sql = "DELETE FROM dich_vu WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Service deleted successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to delete service.", "error" => $stmt->error]);
    }
}

// Lấy toàn bộ dịch vụ
function get_all_services($conn) {
    $sql = "SELECT * FROM dich_vu ORDER BY id DESC";
    $result = $conn->query($sql);

    $services = [];
    while ($row = $result->fetch_assoc()) {
        $services[] = $row;
    }

    echo json_encode($services);
}

// Tìm kiếm dịch vụ
function search_service($conn) {
    $search = $_GET['search'] ?? '';
    $like = '%' . $search . '%';

    $sql = "SELECT * FROM dich_vu WHERE ten LIKE ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $services = [];
    while ($row = $result->fetch_assoc()) {
        $services[] = $row;
    }

    echo json_encode($services);
}

// Xử lý request
switch ($method) {
    case 'POST':
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'add':
                add_service($conn, $data);
                break;
            case 'update':
                update_service($conn, $data);
                break;
            case 'delete':
                delete_service($conn, $data);
                break;
            default:
                echo json_encode(["status" => "error", "message" => "Invalid or missing action for POST."]);
        }
        break;

    case 'GET':
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'search':
                search_service($conn);
                break;
            case 'all':
                get_all_services($conn);
                break;
            default:
                echo json_encode(["status" => "error", "message" => "Invalid or missing action for GET."]);
        }
        break;

    case 'OPTIONS':
        http_response_code(200);
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Method not allowed."]);
}
?>