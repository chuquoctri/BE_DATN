<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../../connect.php';

$data = json_decode(file_get_contents("php://input"), true);
$email = trim($data['email'] ?? '');
$otp = trim($data['otp'] ?? '');
$matKhauMoi = $data['mat_khau_moi'] ?? '';

if (empty($email) || empty($otp) || empty($matKhauMoi)) {
    echo json_encode(["status" => "error", "message" => "Email, OTP và mật khẩu mới không được để trống!"]);
    exit;
}

$stmt = $conn->prepare("SELECT otp FROM nguoi_dung WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$row || $row['otp'] !== $otp) {
    echo json_encode(["status" => "error", "message" => "Mã OTP không hợp lệ!"]);
    exit;
}

$hashedPassword = password_hash($matKhauMoi, PASSWORD_BCRYPT);
$stmt = $conn->prepare("UPDATE nguoi_dung SET mat_khau = ?, otp = NULL WHERE email = ?");
$stmt->bind_param("ss", $hashedPassword, $email);

if ($stmt->execute()) {
    echo json_encode(["status" => "success", "message" => "Mật khẩu đã được cập nhật!"]);
} else {
    echo json_encode(["status" => "error", "message" => "Lỗi cập nhật mật khẩu!"]);
}
?>
