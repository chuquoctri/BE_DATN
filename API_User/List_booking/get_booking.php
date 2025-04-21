<?php
require_once '../../connect.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Lấy ID người dùng từ tham số GET
$nguoi_dung_id = isset($_GET['nguoi_dung_id']) ? intval($_GET['nguoi_dung_id']) : 0;

if ($nguoi_dung_id === 0) {
    echo json_encode(["status" => "error", "message" => "Thiếu ID người dùng."]);
    exit;
}

// Truy vấn danh sách đặt phòng của người dùng
$sql = "SELECT dp.id, dp.ngay_nhan_phong, dp.ngay_tra_phong, dp.tong_tien, dp.trang_thai, dp.ngay_tao, dp.ngay_cap_nhat,
               ks.ten AS ten_khach_san, p.ten AS ten_phong
        FROM dat_phong dp
        JOIN phong p ON dp.phong_id = p.id
        JOIN khach_san ks ON p.khach_san_id = ks.id
        WHERE dp.nguoi_dung_id = ?
        ORDER BY dp.ngay_tao DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $nguoi_dung_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
while ($row = $result->fetch_assoc()) {
    $data[] = $row;
}

echo json_encode([
    "status" => "success",
    "data" => $data
], JSON_UNESCAPED_UNICODE);

$conn->close();
?>
