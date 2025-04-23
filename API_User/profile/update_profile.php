<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../../connect.php'; // Kết nối DB

// Nhận dữ liệu từ JSON
$data = json_decode(file_get_contents("php://input"), true);

$id = $data['id'] ?? null;
$hoTen = $data['ho_ten'] ?? null;
$matKhau = isset($data['mat_khau']) && $data['mat_khau'] !== '' ? password_hash($data['mat_khau'], PASSWORD_BCRYPT) : null;
$soDienThoai = $data['so_dien_thoai'] ?? null;
$diaChi = $data['dia_chi'] ?? null;
$ngaySinh = $data['ngay_sinh'] ?? null;
$anhDaiDien = $data['anh_dai_dien'] ?? null;
$trangThai = $data['trang_thai'] ?? null;

if (!$id) {
    echo json_encode(["status" => "error", "message" => "Thiếu ID người dùng"]);
    exit;
}

try {
    // Chuẩn bị truy vấn cập nhật
    $sql = "UPDATE nguoi_dung SET 
                ho_ten = ?, 
                so_dien_thoai = ?, 
                dia_chi = ?, 
                ngay_sinh = ?, 
                anh_dai_dien = ?, 
                trang_thai = ?";

    // Nếu có mật khẩu mới thì thêm vào truy vấn
    if ($matKhau !== null) {
        $sql .= ", mat_khau = ?";
    }

    $sql .= " WHERE id = ?";

    // Chuẩn bị bind param
    if ($matKhau !== null) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssssssi", $hoTen, $soDienThoai, $diaChi, $ngaySinh, $anhDaiDien, $trangThai, $matKhau, $id);
    } else {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssssssi", $hoTen, $soDienThoai, $diaChi, $ngaySinh, $anhDaiDien, $trangThai, $id);
    }

    if ($stmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Cập nhật người dùng thành công"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Không thể cập nhật người dùng"]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Lỗi: " . $e->getMessage()]);
}
?>
