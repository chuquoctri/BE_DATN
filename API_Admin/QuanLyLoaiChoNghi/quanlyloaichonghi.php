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

// Thêm loại chỗ nghỉ
function add_accommodation_type($conn, $data) {
    if (!isset($data['ten'])) {
        echo json_encode(["status" => "error", "message" => "Missing required field: ten"]);
        return;
    }

    $ten = $data['ten'];
    $hinh_anh = $data['hinh_anh'] ?? null;

    $sql = "INSERT INTO loai_cho_nghi (ten, hinh_anh) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $ten, $hinh_anh);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Accommodation type added successfully.", "id" => $stmt->insert_id]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to add accommodation type.", "error" => $stmt->error]);
    }
}

// Cập nhật loại chỗ nghỉ
function update_accommodation_type($conn, $data) {
    if (!isset($data['id'])) {
        echo json_encode(["status" => "error", "message" => "Missing accommodation type ID."]);
        return;
    }

    $id = $data['id'];
    $ten = $data['ten'];
    $hinh_anh = $data['hinh_anh'] ?? null;

    $sql = "UPDATE loai_cho_nghi SET ten = ?, hinh_anh = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssi', $ten, $hinh_anh, $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Accommodation type updated successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to update accommodation type.", "error" => $stmt->error]);
    }
}

// Xóa loại chỗ nghỉ
function delete_accommodation_type($conn, $data) {
    if (!isset($data['id'])) {
        echo json_encode(["status" => "error", "message" => "Missing accommodation type ID."]);
        return;
    }

    $id = $data['id'];
    $sql = "DELETE FROM loai_cho_nghi WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Accommodation type deleted successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to delete accommodation type.", "error" => $stmt->error]);
    }
}

// Lấy toàn bộ loại chỗ nghỉ
function get_all_accommodation_types($conn) {
    $sql = "SELECT * FROM loai_cho_nghi ORDER BY id DESC";
    $result = $conn->query($sql);

    $types = [];
    while ($row = $result->fetch_assoc()) {
        $types[] = $row;
    }

    echo json_encode($types);
}

// Tìm kiếm loại chỗ nghỉ
function search_accommodation_type($conn) {
    $search = $_GET['search'] ?? '';
    $like = '%' . $search . '%';

    $sql = "SELECT * FROM loai_cho_nghi WHERE ten LIKE ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $types = [];
    while ($row = $result->fetch_assoc()) {
        $types[] = $row;
    }

    echo json_encode($types);
}

// Xử lý request
switch ($method) {
    case 'POST':
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'add':
                add_accommodation_type($conn, $data);
                break;
            case 'update':
                update_accommodation_type($conn, $data);
                break;
            case 'delete':
                delete_accommodation_type($conn, $data);
                break;
            default:
                echo json_encode(["status" => "error", "message" => "Invalid or missing action for POST."]);
        }
        break;

    case 'GET':
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'search':
                search_accommodation_type($conn);
                break;
            case 'all':
                get_all_accommodation_types($conn);
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