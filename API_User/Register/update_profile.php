<?php
require_once '../../connect.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Nhận dữ liệu từ request
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['email'], $data['ho_ten'], $data['so_dien_thoai'], $data['dia_chi'], $data['ngay_sinh'])) {
    echo json_encode(["status" => "error", "message" => "Thiếu dữ liệu đầu vào!"]);
    die();
}

$email = trim($data['email']);
$ho_ten = trim($data['ho_ten']);
$so_dien_thoai = trim($data['so_dien_thoai']);
$dia_chi = trim($data['dia_chi']);
$ngay_sinh = trim($data['ngay_sinh']);

// Kiểm tra xem email có tồn tại không
$sqlCheck = "SELECT * FROM nguoi_dung WHERE email = ?";
$stmtCheck = $conn->prepare($sqlCheck);
$stmtCheck->bind_param("s", $email);
$stmtCheck->execute();
$resultCheck = $stmtCheck->get_result();

if ($resultCheck->num_rows == 0) {
    echo json_encode(["status" => "error", "message" => "Người dùng không tồn tại!"]);
    die();
}

// Cập nhật thông tin
$sqlUpdate = "UPDATE nguoi_dung SET ho_ten = ?, so_dien_thoai = ?, dia_chi = ?, ngay_sinh = ?, ngay_cap_nhat = NOW() WHERE email = ?";
$stmtUpdate = $conn->prepare($sqlUpdate);
$stmtUpdate->bind_param("sssss", $ho_ten, $so_dien_thoai, $dia_chi, $ngay_sinh, $email);

if ($stmtUpdate->execute()) {
    echo json_encode(["status" => "success", "message" => "Cập nhật thông tin thành công!"]);
} else {
    echo json_encode(["status" => "error", "message" => "Lỗi khi cập nhật thông tin!"]);
}

die();
?>
