<?php
require_once '../../connect.php';

// Bật hiển thị lỗi (Debug)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Thiết lập header phản hồi JSON
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Nhận dữ liệu từ request
$data = json_decode(file_get_contents("php://input"), true);

// Kiểm tra nếu không nhận được email hoặc OTP
if (!isset($data['email']) || !isset($data['otp'])) {
    echo json_encode(["status" => "error", "message" => "Thiếu email hoặc mã OTP!"]);
    die();
}

$email = trim($data['email']);
$otp = trim($data['otp']);

// Debug: Ghi log dữ liệu nhận được
error_log("Email nhận: " . $email);
error_log("OTP nhận: " . $otp);

// Kiểm tra mã OTP trong database
$sql = "SELECT otp, da_xac_thuc FROM nguoi_dung WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();

if (!$row) {
    echo json_encode(["status" => "error", "message" => "Email không tồn tại hoặc chưa đăng ký!"]);
    die();
}

// Debug: In mã OTP từ database ra log
error_log("OTP trong database: " . $row['otp']);
error_log("Trạng thái xác thực: " . $row['da_xac_thuc']);

if ($row['otp'] === $otp) {
    // Cập nhật trạng thái tài khoản
    $updateSql = "UPDATE nguoi_dung 
                  SET da_xac_thuc = 1, 
                      trang_thai = 'da_xac_thuc', 
                      otp = NULL, 
                      ngay_cap_nhat = NOW() 
                  WHERE email = ?";
    $updateStmt = $conn->prepare($updateSql);
    $updateStmt->bind_param("s", $email);

    if ($updateStmt->execute()) {
        echo json_encode(["status" => "success", "message" => "Xác thực thành công!"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Lỗi cập nhật trạng thái tài khoản!"]);
    }
} else {
    echo json_encode(["status" => "error", "message" => "Mã xác thực không đúng!"]);
}

die();
?>
