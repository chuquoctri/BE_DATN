<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../../connect.php'; // Kết nối CSDL

// Nhận dữ liệu từ request
$data = json_decode(file_get_contents("php://input"), true);
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "ID không hợp lệ!"
    ]);
    exit;
}

try {
    // Kiểm tra người dùng tồn tại
    $stmt = $conn->prepare("SELECT id, mat_khau FROM nguoi_dung WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if (!$user = $result->fetch_assoc()) {
        echo json_encode([
            "status" => "error",
            "message" => "Không tìm thấy người dùng với ID = $id"
        ]);
        exit;
    }

    // Xử lý đổi mật khẩu nếu có
    if (!empty($data['mat_khau_moi'])) {
        if (empty($data['mat_khau_cu'])) {
            echo json_encode([
                "status" => "error",
                "message" => "Vui lòng nhập mật khẩu cũ"
            ]);
            exit;
        }
        
        // Kiểm tra mật khẩu cũ
        if (!password_verify($data['mat_khau_cu'], $user['mat_khau'])) {
            echo json_encode([
                "status" => "error",
                "message" => "Mật khẩu cũ không chính xác"
            ]);
            exit;
        }
        
        // Mã hóa mật khẩu mới
        $newPasswordHash = password_hash($data['mat_khau_moi'], PASSWORD_DEFAULT);
        $updatePassword = $conn->prepare("UPDATE nguoi_dung SET mat_khau = ? WHERE id = ?");
        $updatePassword->bind_param("si", $newPasswordHash, $id);
        $updatePassword->execute();
    }

    // Xây dựng câu lệnh SQL cập nhật thông tin
    $updateFields = [];
    $params = [];
    $types = '';
    
    if (!empty($data['ho_ten'])) {
        $updateFields[] = "ho_ten = ?";
        $params[] = $data['ho_ten'];
        $types .= 's';
    }
    
    if (!empty($data['so_dien_thoai'])) {
        $updateFields[] = "so_dien_thoai = ?";
        $params[] = $data['so_dien_thoai'];
        $types .= 's';
    }
    
    if (!empty($data['dia_chi'])) {
        $updateFields[] = "dia_chi = ?";
        $params[] = $data['dia_chi'];
        $types .= 's';
    }
    
    if (!empty($data['ngay_sinh'])) {
        $updateFields[] = "ngay_sinh = ?";
        $params[] = $data['ngay_sinh'];
        $types .= 's';
    }

    // Nếu có trường nào cần cập nhật
    if (!empty($updateFields)) {
        $sql = "UPDATE nguoi_dung SET " . implode(', ', $updateFields) . " WHERE id = ?";
        $types .= 'i';
        $params[] = $id;
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
    }

    // Lấy lại thông tin người dùng sau khi cập nhật
    $stmt = $conn->prepare("SELECT id, ho_ten, email, so_dien_thoai, dia_chi, ngay_sinh, anh_dai_dien 
                            FROM nguoi_dung 
                            WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $updatedUser = $result->fetch_assoc();

    echo json_encode([
        "status" => "success",
        "message" => "Cập nhật thông tin thành công",
        "data" => $updatedUser
    ]);

} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi: " . $e->getMessage()
    ]);
}
?>