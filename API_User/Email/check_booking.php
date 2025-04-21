<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../../connect.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendStatusChangeEmail($dat_phong_id, $new_status) {
    global $conn;
    
    try {
        // 1. Kiểm tra đơn đặt có tồn tại và trạng thái hiện tại
        $check_stmt = $conn->prepare("SELECT id, trang_thai FROM dat_phong WHERE id = ?");
        $check_stmt->bind_param("i", $dat_phong_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            throw new Exception("Đơn đặt không tồn tại trong hệ thống");
        }
        
        $current_booking = $check_result->fetch_assoc();
        if ($current_booking['trang_thai'] === $new_status) {
            throw new Exception("Đơn đặt đã ở trạng thái này rồi");
        }

        // 2. Cập nhật trạng thái (không cần cập nhật ngay_cap_nhat vì đã có ON UPDATE)
        $update_stmt = $conn->prepare("UPDATE dat_phong SET trang_thai = ? WHERE id = ?");
        $update_stmt->bind_param("si", $new_status, $dat_phong_id);
        $update_stmt->execute();
        
        if ($update_stmt->affected_rows === 0) {
            throw new Exception("Không thể cập nhật trạng thái đơn đặt");
        }

        // 3. Lấy thông tin đầy đủ để gửi email
        $stmt = $conn->prepare("
            SELECT dp.*, nd.email, nd.ho_ten, p.ten as ten_phong 
            FROM dat_phong dp
            JOIN nguoi_dung nd ON dp.nguoi_dung_id = nd.id
            JOIN phong p ON dp.phong_id = p.id
            WHERE dp.id = ?
        ");
        $stmt->bind_param("i", $dat_phong_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $booking = $result->fetch_assoc();

        // 4. Chuẩn bị nội dung email
        $status_info = [
            'pending' => ['text' => 'Đang chờ xác nhận', 'color' => 'orange'],
            'confirmed' => ['text' => 'Đã xác nhận', 'color' => 'green'],
            'cancelled' => ['text' => 'Đã hủy', 'color' => 'red']
        ];

        $subject = "Cập nhật trạng thái đơn đặt #{$booking['id']}";
        $body = "Xin chào {$booking['ho_ten']},<br><br>";
        $body .= "Đơn đặt phòng của bạn đã được cập nhật trạng thái:<br><br>";
        $body .= "<strong>Thông tin đơn đặt:</strong><br>";
        $body .= "Mã đơn: <strong>#{$booking['id']}</strong><br>";
        $body .= "Phòng: {$booking['ten_phong']}<br>";
        $body .= "Ngày nhận: " . date('d/m/Y', strtotime($booking['ngay_nhan_phong'])) . "<br>";
        $body .= "Ngày trả: " . date('d/m/Y', strtotime($booking['ngay_tra_phong'])) . "<br>";
        $body .= "Tổng tiền: " . number_format($booking['tong_tien'], 0, ',', '.') . " VND<br>";
        $body .= "Trạng thái: <span style='color:{$status_info[$new_status]['color']};font-weight:bold'>{$status_info[$new_status]['text']}</span><br><br>";
        
        if ($new_status == 'confirmed') {
            $body .= "✅ Đơn của bạn đã được xác nhận. Vui lòng thanh toán trong 24h.<br>";
        } elseif ($new_status == 'cancelled') {
            $body .= "❌ Đơn đặt phòng đã bị hủy, lý do số lượng phòng trống đã hết. Liên hệ hỗ trợ nếu cần.<br>";
        }
        
        $body .= "<br>Trân trọng,<br>Ban quản lý";

        // 5. Gửi email
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'chuquoctri03@gmail.com';
        $mail->Password = 'utww odbp tqmp fmmr';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom('chuquoctri03@gmail.com', 'Hệ thống đặt phòng');
        $mail->addAddress($booking['email']);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        
        if (!$mail->send()) {
            throw new Exception("Gửi email thất bại: " . $mail->ErrorInfo);
        }
        
        return true;
    } catch (Exception $e) {
        error_log("Lỗi hệ thống: " . $e->getMessage());
        throw $e;
    }
}

// Hàm xử lý đơn quá hạn
function processExpiredBookings() {
    global $conn;
    
    try {
        // Hủy đơn confirmed quá 24h
        $conn->query("UPDATE dat_phong SET trang_thai = 'cancelled' 
                     WHERE trang_thai = 'confirmed' 
                     AND TIMESTAMPDIFF(HOUR, ngay_cap_nhat, NOW()) > 24");
        
        // Xóa đơn pending quá 24h
        $conn->query("DELETE FROM dat_phong 
                      WHERE trang_thai = 'pending' 
                      AND TIMESTAMPDIFF(HOUR, ngay_tao, NOW()) > 24");
    } catch (Exception $e) {
        error_log("Lỗi xử lý đơn quá hạn: " . $e->getMessage());
    }
}

// Xử lý request
try {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (empty($data['dat_phong_id']) || empty($data['trang_thai'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Thiếu thông tin đơn đặt hoặc trạng thái']);
        exit;
    }

    $allowed_statuses = ['pending', 'confirmed', 'cancelled'];
    if (!in_array($data['trang_thai'], $allowed_statuses)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Trạng thái không hợp lệ']);
        exit;
    }

    processExpiredBookings();
    
    if (sendStatusChangeEmail($data['dat_phong_id'], $data['trang_thai'])) {
        echo json_encode(['status' => 'success', 'message' => 'Cập nhật thành công']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}