<?php
header("Content-Type: application/json; charset=UTF-8");

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once '../../connect.php';
require_once __DIR__ . '/../../vendor/autoload.php';

$mail = new PHPMailer(true);

try {
    // Nhận dữ liệu từ request JSON
    $data = json_decode(file_get_contents("php://input"), true);
    $hoTen = $data['ho_ten'] ?? '';
    $email = $data['email'] ?? '';
    $matKhau = isset($data['mat_khau']) ? password_hash($data['mat_khau'], PASSWORD_BCRYPT) : '';
    $soDienThoai = $data['so_dien_thoai'] ?? '';
    $diaChi = $data['dia_chi'] ?? '';
    $ngaySinh = $data['ngay_sinh'] ?? '';

    // Kiểm tra email có trống không
    if (empty($email)) {
        echo json_encode(["status" => "error", "message" => "Email không được để trống!"]);
        exit;
    }

    // Kiểm tra email hợp lệ
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(["status" => "error", "message" => "Email không hợp lệ!"]);
        exit;
    }

    // Kiểm tra email đã tồn tại chưa
    $stmt = $conn->prepare("SELECT da_xac_thuc FROM nguoi_dung WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $stmt->store_result();
    $stmt->bind_result($daXacThuc);
    $stmt->fetch();

    if ($stmt->num_rows > 0 && $daXacThuc) {
        echo json_encode(["status" => "error", "message" => "Email đã tồn tại!"]);
        exit;
    }

    // Tạo mã OTP ngẫu nhiên
    $otp = rand(100000, 999999);

    // Cấu hình SMTP
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'chuquoctri03@gmail.com'; 
    $mail->Password   = 'utww odbp tqmp fmmr';  
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';

    // Cấu hình người gửi và người nhận
    $mail->setFrom('your_email@gmail.com', 'Hệ thống OTP');
    $mail->addAddress($email);

    // Nội dung email
    $mail->isHTML(true);
    $mail->Subject = 'Mã xác thực OTP';
    $mail->Body    = "Mã OTP của bạn là: <b>$otp</b>. Mã có hiệu lực trong 5 phút.";

    // Gửi email
    if ($mail->send()) {
        if ($stmt->num_rows > 0) {
            // Nếu email đã tồn tại nhưng chưa xác thực, cập nhật OTP mới
            $stmt = $conn->prepare("UPDATE nguoi_dung SET otp = ? WHERE email = ?");
            $stmt->bind_param("ss", $otp, $email);
            $stmt->execute();
        } else {
            // Nếu email chưa tồn tại, thêm mới vào database
            $stmt = $conn->prepare("INSERT INTO nguoi_dung (ho_ten, email, mat_khau, so_dien_thoai, dia_chi, ngay_sinh, otp, trang_thai, da_xac_thuc) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, 'cho_xac_thuc', 0)");
            $stmt->bind_param("sssssss", $hoTen, $email, $matKhau, $soDienThoai, $diaChi, $ngaySinh, $otp);
            $stmt->execute();
        }
        echo json_encode(["status" => "success", "message" => "OTP đã được gửi!", "otp" => $otp]);
    } else {
        echo json_encode(["status" => "error", "message" => "Không thể gửi email."]);
    }
} catch (Exception $e) {
    echo json_encode(["status" => "error", "message" => "Lỗi: {$mail->ErrorInfo}"]);
}
?>
