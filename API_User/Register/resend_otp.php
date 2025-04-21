<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../../connect.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$mail = new PHPMailer(true);

try {
    $data = json_decode(file_get_contents("php://input"), true);
    $email = trim($data['email'] ?? '');

    if (empty($email)) {
        echo json_encode(["status" => "error", "message" => "Email không được để trống!"]);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "Email không hợp lệ!"]);
        exit;
    }

    $stmt = $conn->prepare("SELECT id FROM nguoi_dung WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();

    if ($stmt->num_rows === 0) {
        echo json_encode(["status" => "error", "message" => "Email không tồn tại!"]);
        exit;
    }

    // Tạo mã OTP ngẫu nhiên
    $otp = rand(100000, 999999);
    $stmt = $conn->prepare("UPDATE nguoi_dung SET otp = ? WHERE email = ?");
    $stmt->bind_param("ss", $otp, $email);
    $stmt->execute();

    // Cấu hình gửi email
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'chuquoctri03@gmail.com'; 
    $mail->Password = 'utww odbp tqmp fmmr';  
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port = 587;
    $mail->CharSet = 'UTF-8';
    $mail->setFrom('chuquoctri03@gmail.com', 'Hệ thống OTP');
    $mail->addAddress($email);
    $mail->isHTML(true);
    $mail->Subject = '=?UTF-8?B?' . base64_encode('Mã xác thực OTP') . '?=';
    $mail->Body = "Mã OTP của bạn là: <b>$otp</b>. Mã có hiệu lực trong 5 phút.";

    if ($mail->send()) {
        echo json_encode(["status" => "success", "message" => "OTP đã được gửi lại!"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Không thể gửi email."]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Lỗi: {$mail->ErrorInfo}"]);
}
?>