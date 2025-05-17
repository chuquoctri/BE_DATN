<?php
// Hiển thị lỗi để debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Kết nối DB
require_once '../../connect.php';

// Thiết lập header
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Nhận dữ liệu đầu vào
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents("php://input"), true) ?? [];

// Xử lý request
try {
    switch ($method) {
        case 'POST':
            $action = $_GET['action'] ?? '';
            switch ($action) {
                case 'create':
                    add_city($conn, $input);
                    break;
                case 'update':
                    update_city($conn, $input);
                    break;
                case 'delete':
                    delete_city($conn, $input);
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "Invalid action for POST"]);
            }
            break;

        case 'GET':
            $action = $_GET['action'] ?? '';
            switch ($action) {
                case 'search':
                    search_city($conn);
                    break;
                case 'all':
                    get_all_cities($conn);
                    break;
                case 'categories':
                    get_categories($conn);
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(["status" => "error", "message" => "Invalid action for GET"]);
            }
            break;

        case 'OPTIONS':
            http_response_code(200);
            break;

        default:
            http_response_code(405);
            echo json_encode(["status" => "error", "message" => "Method not allowed"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => $e->getMessage()]);
}

// Các hàm xử lý
function add_city($conn, $data) {
    // Chuẩn hóa tên trường
    $data['danh_muc_id'] = $data['danhmuc_id'] ?? $data['danh_muc_id'] ?? null;
    
    if (empty($data['ten']) || empty($data['danh_muc_id'])) {
        throw new Exception("Tên và danh mục là bắt buộc");
    }

    $stmt = $conn->prepare("INSERT INTO thanh_pho (ten, hinh_anh, danh_muc_id) VALUES (?, ?, ?)");
    $stmt->bind_param('ssi', $data['ten'], $data['hinh_anh'], $data['danh_muc_id']);
    
    if (!$stmt->execute()) {
        throw new Exception("Lỗi khi thêm thành phố: " . $stmt->error);
    }
    
    echo json_encode([
        "status" => "success",
        "message" => "Thêm thành phố thành công",
        "id" => $stmt->insert_id
    ]);
}

function update_city($conn, $data) {
    if (empty($data['id'])) {
        throw new Exception("Thiếu ID thành phố");
    }
    
    // Chuẩn hóa tên trường
    $data['danh_muc_id'] = $data['danhmuc_id'] ?? $data['danh_muc_id'] ?? null;
    
    $stmt = $conn->prepare("UPDATE thanh_pho SET ten = ?, hinh_anh = ?, danh_muc_id = ? WHERE id = ?");
    $stmt->bind_param('ssii', $data['ten'], $data['hinh_anh'], $data['danh_muc_id'], $data['id']);
    
    if (!$stmt->execute()) {
        throw new Exception("Lỗi khi cập nhật thành phố: " . $stmt->error);
    }
    
    echo json_encode(["status" => "success", "message" => "Cập nhật thành phố thành công"]);
}

function delete_city($conn, $data) {
    if (empty($data['id'])) {
        throw new Exception("Thiếu ID thành phố");
    }
    
    $stmt = $conn->prepare("DELETE FROM thanh_pho WHERE id = ?");
    $stmt->bind_param('i', $data['id']);
    
    if (!$stmt->execute()) {
        throw new Exception("Lỗi khi xóa thành phố: " . $stmt->error);
    }
    
    echo json_encode(["status" => "success", "message" => "Xóa thành phố thành công"]);
}

// Các hàm GET giữ nguyên như cũ
function get_all_cities($conn) {
    $sql = "SELECT t.*, d.ten as danh_muc_ten FROM thanh_pho t LEFT JOIN danh_muc_thanh_pho d ON t.danh_muc_id = d.id ORDER BY t.id DESC";
    $result = $conn->query($sql);
    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
}

function search_city($conn) {
    $search = $_GET['search'] ?? '';
    $stmt = $conn->prepare("SELECT t.*, d.ten as danh_muc_ten FROM thanh_pho t LEFT JOIN danh_muc_thanh_pho d ON t.danh_muc_id = d.id WHERE t.ten LIKE ?");
    $like = "%$search%";
    $stmt->bind_param('s', $like);
    $stmt->execute();
    echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
}

function get_categories($conn) {
    $result = $conn->query("SELECT * FROM danh_muc_thanh_pho ORDER BY ten");
    echo json_encode($result->fetch_all(MYSQLI_ASSOC));
}
?>