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

// Thêm danh mục thành phố
function add_category($conn, $data) {
    if (!isset($data['ten'])) {
        echo json_encode(["status" => "error", "message" => "Thiếu trường bắt buộc: ten"]);
        return;
    }

    $ten = $data['ten'];

    $sql = "INSERT INTO danh_muc_thanh_pho (ten) VALUES (?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $ten);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Thêm danh mục thành phố thành công", "id" => $stmt->insert_id]);
    } else {
        echo json_encode(["status" => "error", "message" => "Lỗi khi thêm danh mục", "error" => $stmt->error]);
    }
}

// Cập nhật danh mục thành phố
function update_category($conn, $data) {
    if (!isset($data['id']) || !isset($data['ten'])) {
        echo json_encode(["status" => "error", "message" => "Thiếu trường bắt buộc: id hoặc ten"]);
        return;
    }

    $id = $data['id'];
    $ten = $data['ten'];

    $sql = "UPDATE danh_muc_thanh_pho SET ten = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('si', $ten, $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Cập nhật danh mục thành công"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Lỗi khi cập nhật danh mục", "error" => $stmt->error]);
    }
}

// Xóa danh mục thành phố
function delete_category($conn, $data) {
    if (!isset($data['id'])) {
        echo json_encode(["status" => "error", "message" => "Thiếu ID danh mục"]);
        return;
    }

    $id = $data['id'];
    
    // Kiểm tra xem danh mục có đang được sử dụng không
    $check_sql = "SELECT COUNT(*) as count FROM thanh_pho WHERE danh_muc_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->bind_param('i', $id);
    $check_stmt->execute();
    $result = $check_stmt->get_result()->fetch_assoc();
    
    if ($result['count'] > 0) {
        echo json_encode(["status" => "error", "message" => "Không thể xóa danh mục đang được sử dụng"]);
        return;
    }

    $sql = "DELETE FROM danh_muc_thanh_pho WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Xóa danh mục thành công"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Lỗi khi xóa danh mục", "error" => $stmt->error]);
    }
}

// Lấy toàn bộ danh mục thành phố
function get_all_categories($conn) {
    $sql = "SELECT * FROM danh_muc_thanh_pho ORDER BY ten ASC";
    $result = $conn->query($sql);

    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }

    echo json_encode($categories);
}

// Tìm kiếm danh mục thành phố
function search_category($conn) {
    $search = $_GET['search'] ?? '';
    $like = '%' . $search . '%';

    $sql = "SELECT * FROM danh_muc_thanh_pho WHERE ten LIKE ? ORDER BY ten ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $categories = [];
    while ($row = $result->fetch_assoc()) {
        $categories[] = $row;
    }

    echo json_encode($categories);
}

// Xử lý request
switch ($method) {
    case 'POST':
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'add':
                add_category($conn, $data);
                break;
            case 'update':
                update_category($conn, $data);
                break;
            case 'delete':
                delete_category($conn, $data);
                break;
            default:
                echo json_encode(["status" => "error", "message" => "Action không hợp lệ"]);
        }
        break;

    case 'GET':
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'search':
                search_category($conn);
                break;
            case 'all':
                get_all_categories($conn);
                break;
            default:
                echo json_encode(["status" => "error", "message" => "Action không hợp lệ"]);
        }
        break;

    case 'OPTIONS':
        http_response_code(200);
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Phương thức không được hỗ trợ"]);
}
?>