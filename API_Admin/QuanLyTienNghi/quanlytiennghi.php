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

// Thêm tiện nghi
function add_amenity($conn, $data) {
    $requiredFields = ['ten', 'hinh_anh'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            echo json_encode(["status" => "error", "message" => "Missing field: $field"]);
            return;
        }
    }

    $ten = $data['ten'];
    $hinh_anh = $data['hinh_anh'];

    $sql = "INSERT INTO tien_nghi (ten, hinh_anh) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $ten, $hinh_anh);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Amenity added successfully.", "id" => $stmt->insert_id]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to add amenity.", "error" => $stmt->error]);
    }
}

// Cập nhật tiện nghi
function update_amenity($conn, $data) {
    if (!isset($data['id'])) {
        echo json_encode(["status" => "error", "message" => "Missing amenity ID."]);
        return;
    }

    $id = $data['id'];
    $ten = $data['ten'];
    $hinh_anh = $data['hinh_anh'];

    $sql = "UPDATE tien_nghi SET ten = ?, hinh_anh = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssi', $ten, $hinh_anh, $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Amenity updated successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to update amenity.", "error" => $stmt->error]);
    }
}

// Xóa tiện nghi
function delete_amenity($conn, $data) {
    if (!isset($data['id'])) {
        echo json_encode(["status" => "error", "message" => "Missing amenity ID."]);
        return;
    }

    $id = $data['id'];
    $sql = "DELETE FROM tien_nghi WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Amenity deleted successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to delete amenity.", "error" => $stmt->error]);
    }
}

// Lấy toàn bộ tiện nghi
function get_all_amenities($conn) {
    $sql = "SELECT * FROM tien_nghi ORDER BY id DESC";
    $result = $conn->query($sql);

    $amenities = [];
    while ($row = $result->fetch_assoc()) {
        $amenities[] = $row;
    }

    echo json_encode($amenities);
}

// Tìm kiếm tiện nghi
function search_amenity($conn) {
    $search = $_GET['search'] ?? '';
    $like = '%' . $search . '%';

    $sql = "SELECT * FROM tien_nghi WHERE ten LIKE ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $amenities = [];
    while ($row = $result->fetch_assoc()) {
        $amenities[] = $row;
    }

    echo json_encode($amenities);
}

// Xử lý request
switch ($method) {
    case 'POST':
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'add':
                add_amenity($conn, $data);
                break;
            case 'update':
                update_amenity($conn, $data);
                break;
            case 'delete':
                delete_amenity($conn, $data);
                break;
            default:
                echo json_encode(["status" => "error", "message" => "Invalid or missing action for POST."]);
        }
        break;

    case 'GET':
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'search':
                search_amenity($conn);
                break;
            case 'all':
                get_all_amenities($conn);
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