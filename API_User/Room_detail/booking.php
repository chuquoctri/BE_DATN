<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../../connect.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendEmailNotification($recipientEmail, $subject, $body) {
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'chuquoctri03@gmail.com';
        $mail->Password = 'utww odbp tqmp fmmr';  
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom('chuquoctri03@gmail.com', 'Hệ thống đặt phòng');
        $mail->addAddress($recipientEmail);
        $mail->isHTML(true);
        $mail->Subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $mail->Body = $body;

        return $mail->send();
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        return false;
    }
}

try {
    $data = json_decode(file_get_contents("php://input"), true);
    
    // Validate input data
    if (empty($data['nguoi_dung_id']) || empty($data['ngay_nhan_phong']) || 
        empty($data['ngay_tra_phong']) || empty($data['phong_id'])) {
        echo json_encode(["status" => "error", "message" => "Thiếu thông tin bắt buộc"]);
        exit;
    }
    
    $nguoi_dung_id = $data['nguoi_dung_id'];
    $ngay_nhan_phong = $data['ngay_nhan_phong'];
    $ngay_tra_phong = $data['ngay_tra_phong'];
    $phong_id = $data['phong_id'];
    $dich_vu = $data['dich_vu'] ?? []; // Sửa ở đây để dịch vụ là optional
    
    // Date validation
    if (strtotime($ngay_tra_phong) <= strtotime($ngay_nhan_phong)) {
        echo json_encode(["status" => "error", "message" => "Ngày trả phòng phải sau ngày nhận phòng"]);
        exit;
    }
    
    // Get user info
    $stmt = $conn->prepare("SELECT email, ho_ten FROM nguoi_dung WHERE id = ?");
    $stmt->bind_param("i", $nguoi_dung_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(["status" => "error", "message" => "Không tìm thấy thông tin người dùng"]);
        exit;
    }
    
    $user = $result->fetch_assoc();
    $user_email = $user['email'];
    $user_name = $user['ho_ten'];
    
    // Get room and hotel info
    $stmt = $conn->prepare("
        SELECT p.*, ks.ten as ten_khach_san, ks.email as email_khach_san 
        FROM phong p
        JOIN khach_san ks ON p.khach_san_id = ks.id
        WHERE p.id = ?
    ");
    $stmt->bind_param("i", $phong_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(["status" => "error", "message" => "Không tìm thấy thông tin phòng"]);
        exit;
    }
    
    $room_info = $result->fetch_assoc();
    $hotel_email = $room_info['email_khach_san'];
    $hotel_name = $room_info['ten_khach_san'];
    $room_name = $room_info['ten'];
    
    // Check room availability
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM dat_phong 
        WHERE phong_id = ? 
        AND trang_thai = 'confirmed'
        AND (
            (? BETWEEN ngay_nhan_phong AND ngay_tra_phong)
            OR (? BETWEEN ngay_nhan_phong AND ngay_tra_phong)
            OR (ngay_nhan_phong BETWEEN ? AND ?)
        )
    ");
    $stmt->bind_param("issss", $phong_id, $ngay_nhan_phong, $ngay_tra_phong, $ngay_nhan_phong, $ngay_tra_phong);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    if ($row['count'] > 0) {
        echo json_encode(["status" => "error", "message" => "Phòng đã được đặt trong khoảng thời gian này"]);
        exit;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Calculate total amount
        $tong_tien = 0;
        $so_ngay = (strtotime($ngay_tra_phong) - strtotime($ngay_nhan_phong)) / (60 * 60 * 24);
        $tong_tien += $room_info['gia'] * $so_ngay;
        
        // Process services if provided
        $dich_vu_details = [];
        if (!empty($dich_vu)) {
            foreach ($dich_vu as $dv) {
                $stmt = $conn->prepare("
                    SELECT dv.ten, dvk.gia 
                    FROM dich_vu_khach_san dvk
                    JOIN dich_vu dv ON dvk.dich_vu_id = dv.id
                    WHERE dvk.id = ?
                ");
                $stmt->bind_param("i", $dv['id']);
                $stmt->execute();
                $result = $stmt->get_result();
                $dich_vu_info = $result->fetch_assoc();
                
                $service_total = $dich_vu_info['gia'] * $dv['so_luong'];
                $tong_tien += $service_total;
                
                $dich_vu_details[] = [
                    'ten' => $dich_vu_info['ten'],
                    'so_luong' => $dv['so_luong'],
                    'gia' => $dich_vu_info['gia'],
                    'thanh_tien' => $service_total
                ];
            }
        }
        
        // Create booking
        $stmt = $conn->prepare("
            INSERT INTO dat_phong (nguoi_dung_id, phong_id, ngay_nhan_phong, ngay_tra_phong, tong_tien, trang_thai)
            VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param("iissd", $nguoi_dung_id, $phong_id, $ngay_nhan_phong, $ngay_tra_phong, $tong_tien);
        $stmt->execute();
        $dat_phong_id = $conn->insert_id;
        
        // Add services to booking if provided
        if (!empty($dich_vu)) {
            foreach ($dich_vu as $dv) {
                $stmt = $conn->prepare("
                    INSERT INTO dat_phong_dich_vu (dat_phong_id, dich_vu_khach_san_id, so_luong, gia)
                    VALUES (?, ?, ?, ?)
                ");
                
                $stmt_dv = $conn->prepare("SELECT gia FROM dich_vu_khach_san WHERE id = ?");
                $stmt_dv->bind_param("i", $dv['id']);
                $stmt_dv->execute();
                $result_dv = $stmt_dv->get_result();
                $dich_vu_info = $result_dv->fetch_assoc();
                
                $stmt->bind_param("iiid", $dat_phong_id, $dv['id'], $dv['so_luong'], $dich_vu_info['gia']);
                $stmt->execute();
            }
        }
        
        $conn->commit();
        
        // Send email to customer
        $customer_subject = "Đơn đặt phòng #$dat_phong_id đã được tiếp nhận";
        $customer_body = "Xin chào $user_name,<br><br>";
        $customer_body .= "Cảm ơn bạn đã đặt phòng tại $hotel_name!<br><br>";
        $customer_body .= "<strong>Thông tin đơn đặt:</strong><br>";
        $customer_body .= "Mã đơn đặt: #$dat_phong_id<br>";
        $customer_body .= "Khách sạn: $hotel_name<br>";
        $customer_body .= "Phòng: $room_name<br>";
        $customer_body .= "Ngày nhận phòng: $ngay_nhan_phong<br>";
        $customer_body .= "Ngày trả phòng: $ngay_tra_phong<br>";
        $customer_body .= "Tổng tiền: " . number_format($tong_tien, 2) . " VND<br>";
        $customer_body .= "Trạng thái: Đang chờ xác nhận<br><br>";
        $customer_body .= "Chúng tôi sẽ liên hệ với bạn trong thời gian sớm nhất.";
        
        sendEmailNotification($user_email, $customer_subject, $customer_body);
        
        // Send email to hotel
        $hotel_subject = "Có đơn đặt phòng mới #$dat_phong_id tại $hotel_name";
        $hotel_body = "Xin chào quản lý $hotel_name,<br><br>";
        $hotel_body .= "Bạn có một đơn đặt phòng mới cần xác nhận.<br><br>";
        $hotel_body .= "<strong>Thông tin đơn đặt:</strong><br>";
        $hotel_body .= "Mã đơn đặt: #$dat_phong_id<br>";
        $hotel_body .= "Khách hàng: $user_name<br>";
        $hotel_body .= "Email khách: $user_email<br>";
        $hotel_body .= "Phòng: $room_name<br>";
        $hotel_body .= "Ngày nhận phòng: $ngay_nhan_phong<br>";
        $hotel_body .= "Ngày trả phòng: $ngay_tra_phong<br>";
        $hotel_body .= "Tổng tiền: " . number_format($tong_tien, 2) . " VND<br><br>";
        
        if (!empty($dich_vu_details)) {
            $hotel_body .= "<strong>Dịch vụ đi kèm:</strong><br>";
            foreach ($dich_vu_details as $dv) {
                $hotel_body .= "- {$dv['ten']}: {$dv['so_luong']} x " . number_format($dv['gia'], 2) . " = " . number_format($dv['thanh_tien'], 2) . " VND<br>";
            }
        }
        
        $hotel_body .= "<br>Vui lòng kiểm tra và xác nhận đơn đặt phòng này trong hệ thống quản lý.";
        
        sendEmailNotification($hotel_email, $hotel_subject, $hotel_body);
        
        echo json_encode([
            "status" => "success", 
            "message" => "Đơn đặt phòng đã được tạo thành công",
            "dat_phong_id" => $dat_phong_id,
            "tong_tien" => $tong_tien
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Booking error: " . $e->getMessage());
        echo json_encode(["status" => "error", "message" => "Lỗi khi tạo đơn đặt phòng"]);
    }
} catch (Exception $e) {
    error_log("System error: " . $e->getMessage());
    echo json_encode(["status" => "error", "message" => "Lỗi hệ thống"]);
}
?>