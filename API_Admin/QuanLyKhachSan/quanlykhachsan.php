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

// Thêm khách sạn
function add_hotel($conn, $data) {
    $requiredFields = ['ten', 'thanh_pho_id', 'loai_cho_nghi_id', 'dia_chi', 'so_dien_thoai', 'email', 'so_sao', 'mo_ta', 'hinh_anh'];
    foreach ($requiredFields as $field) {
        if (!isset($data[$field])) {
            echo json_encode(["status" => "error", "message" => "Missing field: $field"]);
            return;
        }
    }

    $ten = $data['ten'];
    $thanh_pho_id = $data['thanh_pho_id'];
    $loai_cho_nghi_id = $data['loai_cho_nghi_id'];
    $dia_chi = $data['dia_chi'];
    $so_dien_thoai = $data['so_dien_thoai'];
    $email = $data['email'];
    $kinh_do = $data['kinh_do'] ?? null;
    $vi_do = $data['vi_do'] ?? null;
    $so_sao = $data['so_sao'];
    $mo_ta = $data['mo_ta'];
    $hinh_anh = $data['hinh_anh'];

    $sql = "INSERT INTO khach_san (ten, thanh_pho_id, loai_cho_nghi_id, dia_chi, so_dien_thoai, email, kinh_do, vi_do, so_sao, mo_ta, hinh_anh)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('siisssddiss', $ten, $thanh_pho_id, $loai_cho_nghi_id, $dia_chi, $so_dien_thoai, $email, $kinh_do, $vi_do, $so_sao, $mo_ta, $hinh_anh);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Hotel added successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to add hotel.", "error" => $stmt->error]);
    }
}

// Cập nhật khách sạn
function update_hotel($conn, $data) {
    if (!isset($data['id'])) {
        echo json_encode(["status" => "error", "message" => "Missing hotel ID."]);
        return;
    }

    $id = $data['id'];
    $ten = $data['ten'];
    $thanh_pho_id = $data['thanh_pho_id'];
    $loai_cho_nghi_id = $data['loai_cho_nghi_id'];
    $dia_chi = $data['dia_chi'];
    $so_dien_thoai = $data['so_dien_thoai'];
    $email = $data['email'];
    $kinh_do = $data['kinh_do'] ?? null;
    $vi_do = $data['vi_do'] ?? null;
    $so_sao = $data['so_sao'];
    $mo_ta = $data['mo_ta'];
    $hinh_anh = $data['hinh_anh'];

    $sql = "UPDATE khach_san SET ten = ?, thanh_pho_id = ?, loai_cho_nghi_id = ?, dia_chi = ?, so_dien_thoai = ?, email = ?, kinh_do = ?, vi_do = ?, so_sao = ?, mo_ta = ?, hinh_anh = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('siisssddissi', $ten, $thanh_pho_id, $loai_cho_nghi_id, $dia_chi, $so_dien_thoai, $email, $kinh_do, $vi_do, $so_sao, $mo_ta, $hinh_anh, $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Hotel updated successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to update hotel.", "error" => $stmt->error]);
    }
}

// Xóa khách sạn
function delete_hotel($conn, $data) {
    if (!isset($data['id'])) {
        echo json_encode(["status" => "error", "message" => "Missing hotel ID."]);
        return;
    }

    $id = $data['id'];
    $sql = "DELETE FROM khach_san WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Hotel deleted successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to delete hotel.", "error" => $stmt->error]);
    }
}

// Tìm kiếm khách sạn
function search_hotel($conn) {
    $search = $_GET['search'] ?? '';
    $like = '%' . $search . '%';

    $sql = "SELECT * FROM khach_san WHERE ten LIKE ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $hotels = [];
    while ($row = $result->fetch_assoc()) {
        $hotels[] = $row;
    }

    echo json_encode($hotels);
}

// Lấy toàn bộ khách sạn
function get_all_hotels($conn) {
    $sql = "SELECT * FROM khach_san ORDER BY ngay_tao DESC";
    $result = $conn->query($sql);

    $hotels = [];
    while ($row = $result->fetch_assoc()) {
        $hotels[] = $row;
    }

    echo json_encode($hotels);
}

// Xử lý request
switch ($method) {
    case 'POST':
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'add':
                add_hotel($conn, $data);
                break;
            case 'update':
                update_hotel($conn, $data);
                break;
            case 'delete':
                delete_hotel($conn, $data);
                break;
            default:
                echo json_encode(["status" => "error", "message" => "Invalid or missing action for POST."]);
        }
        break;

    case 'GET':
        $action = $_GET['action'] ?? '';
        switch ($action) {
            case 'search':
                search_hotel($conn);
                break;
            case 'all':
                get_all_hotels($conn);
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