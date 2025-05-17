<?php
require_once '../../connect.php';

// Thiết lập header CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

$method = $_SERVER['REQUEST_METHOD'];

// Hàm thêm người dùng
function add_user($conn) {
    $ho_ten = $_POST['ho_ten'];
    $email = $_POST['email'];
    $mat_khau = $_POST['mat_khau'];
    $so_dien_thoai = $_POST['so_dien_thoai'] ?? NULL;
    $dia_chi = $_POST['dia_chi'] ?? NULL;
    $ngay_sinh = $_POST['ngay_sinh'] ?? NULL;
    $anh_dai_dien = $_POST['anh_dai_dien'] ?? NULL;
    $role = $_POST['role'];
    $otp = $_POST['otp'] ?? NULL;
    $khach_san_id = NULL;

    // Xử lý theo role
    if ($role === 'admin' || $role === 'quan_ly') {
        $da_xac_thuc = 1;
        $trang_thai = 'da_xac_thuc';
    } else {
        $da_xac_thuc = $_POST['da_xac_thuc'] ?? 0;
        $trang_thai = $_POST['trang_thai'] ?? 'chua_xac_thuc';
    }

    if ($role === 'quan_ly') {
        $khach_san_id = $_POST['khach_san_id'] ?? NULL;
    }

    $sql = "INSERT INTO nguoi_dung (ho_ten, email, mat_khau, so_dien_thoai, dia_chi, ngay_sinh, anh_dai_dien, da_xac_thuc, otp, trang_thai, role, khach_san_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('sssssssssssi', $ho_ten, $email, $mat_khau, $so_dien_thoai, $dia_chi, $ngay_sinh, $anh_dai_dien, $da_xac_thuc, $otp, $trang_thai, $role, $khach_san_id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "User added successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to add user."]);
    }
}

// Hàm cập nhật người dùng
function update_user($conn) {
    $id = $_POST['id'];
    $ho_ten = $_POST['ho_ten'];
    $email = $_POST['email'];
    $mat_khau = $_POST['mat_khau'];
    $so_dien_thoai = $_POST['so_dien_thoai'] ?? NULL;
    $dia_chi = $_POST['dia_chi'] ?? NULL;
    $ngay_sinh = $_POST['ngay_sinh'] ?? NULL;
    $anh_dai_dien = $_POST['anh_dai_dien'] ?? NULL;
    $da_xac_thuc = $_POST['da_xac_thuc'] ?? 0;
    $otp = $_POST['otp'] ?? NULL;
    $trang_thai = $_POST['trang_thai'] ?? 'chua_xac_thuc';
    $role = $_POST['role'];
    $khach_san_id = $_POST['khach_san_id'] ?? NULL;

    $sql = "UPDATE nguoi_dung SET ho_ten = ?, email = ?, mat_khau = ?, so_dien_thoai = ?, dia_chi = ?, ngay_sinh = ?, anh_dai_dien = ?, da_xac_thuc = ?, otp = ?, trang_thai = ?, role = ?, khach_san_id = ? WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ssssssssssssi', $ho_ten, $email, $mat_khau, $so_dien_thoai, $dia_chi, $ngay_sinh, $anh_dai_dien, $da_xac_thuc, $otp, $trang_thai, $role, $khach_san_id, $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "User updated successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to update user."]);
    }
}

// Hàm xóa người dùng
function delete_user($conn) {
    $id = $_POST['id'];

    $sql = "DELETE FROM nguoi_dung WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $id);

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "User deleted successfully."]);
    } else {
        echo json_encode(["status" => "error", "message" => "Failed to delete user."]);
    }
}

// Tìm kiếm người dùng
function search_user($conn) {
    $search_term = $_GET['search'] ?? '';
    $like_term = '%' . $search_term . '%';

    $sql = "SELECT * FROM nguoi_dung WHERE ho_ten LIKE ? OR email LIKE ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ss', $like_term, $like_term);
    $stmt->execute();
    $result = $stmt->get_result();

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    echo json_encode($users);
}

// Lấy toàn bộ người dùng
function get_all_users($conn) {
    $sql = "SELECT * FROM nguoi_dung";
    $result = $conn->query($sql);

    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    echo json_encode($users);
}

// Lấy danh sách khách sạn
function get_hotels($conn) {
    $sql = "SELECT id, ten FROM khach_san ORDER BY ten ASC";
    $result = $conn->query($sql);

    $hotels = [];
    while ($row = $result->fetch_assoc()) {
        $hotels[] = $row;
    }

    echo json_encode($hotels);
}

// Điều hướng xử lý API
switch ($method) {
    case 'POST':
        if (isset($_GET['action'])) {
            $action = $_GET['action'];
            switch ($action) {
                case 'add':
                    add_user($conn);
                    break;
                case 'update':
                    update_user($conn);
                    break;
                case 'delete':
                    delete_user($conn);
                    break;
                default:
                    echo json_encode(["status" => "error", "message" => "Invalid action."]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "Action parameter missing."]);
        }
        break;

    case 'GET':
        if (isset($_GET['action'])) {
            $action = $_GET['action'];
            switch ($action) {
                case 'search':
                    search_user($conn);
                    break;
                case 'all':
                    get_all_users($conn);
                    break;
                case 'get_hotels':
                    get_hotels($conn);
                    break;
                default:
                    echo json_encode(["status" => "error", "message" => "Invalid action."]);
            }
        } else {
            echo json_encode(["status" => "error", "message" => "Action parameter missing."]);
        }
        break;

    case 'OPTIONS':
        http_response_code(200);
        break;

    default:
        echo json_encode(["status" => "error", "message" => "Method not allowed."]);
        break;
}
?>
