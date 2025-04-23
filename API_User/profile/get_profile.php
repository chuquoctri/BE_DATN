<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../../connect.php'; // Kết nối CSDL

// Nhận id từ query string
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo json_encode([
        "status" => "error",
        "message" => "ID không hợp lệ!"
    ]);
    exit;
}

try {
    $stmt = $conn->prepare("SELECT id, ho_ten, email, so_dien_thoai, dia_chi, ngay_sinh, anh_dai_dien, da_xac_thuc, ngay_tao, ngay_cap_nhat, trang_thai 
                            FROM nguoi_dung 
                            WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($user = $result->fetch_assoc()) {
        echo json_encode([
            "status" => "success",
            "data" => $user
        ]);
    } else {
        echo json_encode([
            "status" => "error",
            "message" => "Không tìm thấy người dùng với ID = $id"
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi: " . $e->getMessage()
    ]);
}
?>
