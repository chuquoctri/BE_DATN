<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../../connect.php'; // Đảm bảo file này tồn tại và kết nối DB ($conn)
require_once __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

function sendEmailNotification($recipientEmail, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'chuquoctri03@gmail.com'; // Cân nhắc dùng biến môi trường
        $mail->Password = 'utww odbp tqmp fmmr';   // Cân nhắc dùng biến môi trường/App Password
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
        error_log("Email sending error: " . $mail->ErrorInfo . " | Exception: " . $e->getMessage());
        return false;
    }
}

try {
    $data = json_decode(file_get_contents("php://input"), true);

    if (empty($data['nguoi_dung_id']) || empty($data['ngay_nhan_phong']) ||
        empty($data['ngay_tra_phong']) || !is_array($data['phongs']) || empty($data['phongs'])) {
        echo json_encode(["status" => "error", "message" => "Thiếu thông tin bắt buộc hoặc danh sách phòng không hợp lệ"]);
        exit;
    }

    foreach ($data['phongs'] as $phong_item_check) {
        if (!isset($phong_item_check['phong_id']) || !isset($phong_item_check['so_luong'])) {
            echo json_encode(["status" => "error", "message" => "Mỗi phòng trong danh sách phải có 'phong_id' và 'so_luong'."]);
            exit;
        }
        if (!is_numeric($phong_item_check['phong_id']) || !is_numeric($phong_item_check['so_luong']) || $phong_item_check['so_luong'] <= 0 || $phong_item_check['phong_id'] <= 0) {
            echo json_encode(["status" => "error", "message" => "Giá trị 'phong_id' (>0) và 'so_luong' (>0) không hợp lệ."]);
            exit;
        }
    }

    $nguoi_dung_id = (int) $data['nguoi_dung_id'];
    $ngay_nhan_phong = $data['ngay_nhan_phong'];
    $ngay_tra_phong = $data['ngay_tra_phong'];
    $phongs_dat = $data['phongs'];
    $dich_vu_dat = $data['dich_vu'] ?? [];

    if (strtotime($ngay_tra_phong) <= strtotime($ngay_nhan_phong)) {
        echo json_encode(["status" => "error", "message" => "Ngày trả phòng phải sau ngày nhận phòng"]);
        exit;
    }

    $stmt_user = $conn->prepare("SELECT email, ho_ten FROM nguoi_dung WHERE id = ?");
    if (!$stmt_user) { throw new Exception("Lỗi chuẩn bị câu lệnh người dùng: " . $conn->error); }
    $stmt_user->bind_param("i", $nguoi_dung_id);
    $stmt_user->execute();
    $result_user = $stmt_user->get_result();
    if ($result_user->num_rows === 0) { echo json_encode(["status" => "error", "message" => "Không tìm thấy thông tin người dùng"]); exit; }
    $user = $result_user->fetch_assoc();
    $user_email = $user['email'];
    $user_name = $user['ho_ten'];
    $stmt_user->close();

    $tong_tien_don_hang = 0;
    $chi_tiet_dat_phong_luu_db = [];
    $phongs_da_dat_email = [];
    $primary_phong_id_for_dat_phong = (int) $phongs_dat[0]['phong_id'];

    // Kiểm tra nếu primary_phong_id không hợp lệ (ví dụ client gửi 0 dù đã validate ở trên)
    // Nếu cột phong_id trong dat_phong không cho phép NULL/0 và không có default, đây có thể là vấn đề.
    // Để an toàn, nếu bạn muốn đảm bảo ID phòng chính phải > 0:
    if ($primary_phong_id_for_dat_phong <= 0) {
         throw new Exception("ID phòng chính không hợp lệ (nhận giá trị <= 0).");
    }

    $hotel_email_for_notification = null;
    $hotel_name_for_notification = null;

    if (!$conn->begin_transaction()) { throw new Exception("Không thể bắt đầu transaction: " . $conn->error); }

    try {
        foreach ($phongs_dat as $phong_item) {
            $phong_id = (int) $phong_item['phong_id'];
            $so_luong_dat = (int) $phong_item['so_luong'];

            // Đã kiểm tra $phong_id > 0 và $so_luong_dat > 0 ở đầu

            $stmt_phong = $conn->prepare("
                SELECT p.ten, p.gia, p.so_luong as so_luong_phong_hien_co, ks.ten as ten_khach_san, ks.email as email_khach_san
                FROM phong p
                JOIN khach_san ks ON p.khach_san_id = ks.id
                WHERE p.id = ? FOR UPDATE
            ");
            if (!$stmt_phong) { throw new Exception("Lỗi chuẩn bị câu lệnh phòng: " . $conn->error); }
            $stmt_phong->bind_param("i", $phong_id);
            $stmt_phong->execute();
            $result_phong = $stmt_phong->get_result();
            if ($result_phong->num_rows === 0) { throw new Exception("Không tìm thấy thông tin phòng có ID: $phong_id"); }
            $room_info = $result_phong->fetch_assoc();
            $stmt_phong->close();

            $hotel_email_for_notification = $room_info['email_khach_san'];
            $hotel_name_for_notification = $room_info['ten_khach_san'];
            $room_name = $room_info['ten'];
            $gia_phong_ngay = (float) $room_info['gia'];
            $so_luong_phong_hien_co = (int) $room_info['so_luong_phong_hien_co'];

            $exclusive_ngay_tra_phong = date('Y-m-d', strtotime($ngay_tra_phong));
            $exclusive_ngay_nhan_phong = date('Y-m-d', strtotime($ngay_nhan_phong));

            $stmt_check_v2 = $conn->prepare("
                SELECT SUM(ctdp.so_luong_phong) AS booked_quantity
                FROM chi_tiet_dat_phong ctdp
                JOIN dat_phong dp ON ctdp.dat_phong_id = dp.id
                WHERE ctdp.phong_id = ?
                AND dp.trang_thai NOT IN ('cancelled', 'failed', 'rejected', 'completed')
                AND dp.ngay_nhan_phong < ?
                AND dp.ngay_tra_phong > ?
            ");
            if (!$stmt_check_v2) { throw new Exception("Lỗi chuẩn bị câu lệnh kiểm tra phòng v2: " . $conn->error); }
            $stmt_check_v2->bind_param("iss", $phong_id, $exclusive_ngay_tra_phong, $exclusive_ngay_nhan_phong);
            $stmt_check_v2->execute();
            $result_check = $stmt_check_v2->get_result();
            $row_check = $result_check->fetch_assoc();
            $booked_quantity = (int) ($row_check['booked_quantity'] ?? 0);
            $stmt_check_v2->close();

            if (($booked_quantity + $so_luong_dat) > $so_luong_phong_hien_co) {
                throw new Exception("Phòng '$room_name' (ID: $phong_id) không còn đủ số lượng trống. Chỉ còn " . max(0, $so_luong_phong_hien_co - $booked_quantity) . " phòng trống.");
            }

            $so_ngay_tinh = (strtotime($ngay_tra_phong) - strtotime($ngay_nhan_phong)) / (60 * 60 * 24);
            if ($so_ngay_tinh <=0) $so_ngay_tinh = 1;

            $tong_tien_phong = $gia_phong_ngay * $so_ngay_tinh * $so_luong_dat;
            $tong_tien_don_hang += $tong_tien_phong;

            $chi_tiet_dat_phong_luu_db[] = ['phong_id' => $phong_id, 'so_luong_phong' => $so_luong_dat, 'gia' => $gia_phong_ngay];
            $phongs_da_dat_email[] = ['ten_phong' => $room_name, 'so_luong' => $so_luong_dat, 'gia_phong_ngay' => $gia_phong_ngay, 'tong_tien_phong' => $tong_tien_phong];
        }

        $stmt_dat_phong = $conn->prepare("
            INSERT INTO dat_phong (nguoi_dung_id, phong_id, ngay_nhan_phong, ngay_tra_phong, tong_tien, trang_thai, trang_thai_thanh_toan)
            VALUES (?, ?, ?, ?, ?, 'pending', 'chua_thanh_toan')
        ");
        if (!$stmt_dat_phong) { throw new Exception("Lỗi chuẩn bị câu lệnh tạo đơn đặt phòng: " . $conn->error); }
        
        // DÒNG 239 (hoặc tương tự) - ĐẢM BẢO CHUỖI KIỂU LÀ "iissd" (KHÔNG CÓ KHOẢNG TRẮNG)
        $stmt_dat_phong->bind_param("iissd",
            $nguoi_dung_id,
            $primary_phong_id_for_dat_phong,
            $ngay_nhan_phong,
            $ngay_tra_phong,
            $tong_tien_don_hang
        );
        if (!$stmt_dat_phong->execute()) { throw new Exception("Lỗi thực thi tạo đơn đặt phòng: " . $stmt_dat_phong->error); }
        $dat_phong_id = $conn->insert_id;
        $stmt_dat_phong->close();

        $stmt_chi_tiet = $conn->prepare("
            INSERT INTO chi_tiet_dat_phong (dat_phong_id, phong_id, so_luong_phong, gia)
            VALUES (?, ?, ?, ?)
        ");
        if (!$stmt_chi_tiet) { throw new Exception("Lỗi chuẩn bị câu lệnh chi tiết đặt phòng: " . $conn->error); }
        foreach ($chi_tiet_dat_phong_luu_db as $item) {
            $stmt_chi_tiet->bind_param("iiid", $dat_phong_id, $item['phong_id'], $item['so_luong_phong'], $item['gia']);
            if (!$stmt_chi_tiet->execute()) { throw new Exception("Lỗi thực thi chi tiết đặt phòng: " . $stmt_chi_tiet->error); }
        }
        $stmt_chi_tiet->close();

        $dich_vu_details_email = [];
        if (!empty($dich_vu_dat)) {
            $tong_tien_dich_vu_them = 0;
            foreach ($dich_vu_dat as $dv_item) {
                if (!isset($dv_item['id']) || !isset($dv_item['so_luong']) || !is_numeric($dv_item['id']) || !is_numeric($dv_item['so_luong']) || $dv_item['so_luong'] <= 0 || $dv_item['id'] <= 0) {
                    // Có thể log lỗi hoặc bỏ qua dịch vụ không hợp lệ thay vì throw Exception toàn bộ đơn
                    error_log("Dữ liệu dịch vụ không hợp lệ: " . json_encode($dv_item));
                    continue; 
                }
                $dich_vu_id_ks = (int) $dv_item['id'];
                $so_luong_dv = (int) $dv_item['so_luong'];

                $stmt_dv = $conn->prepare("
                    SELECT dv.ten as ten_dich_vu, dvk.gia as gia_dich_vu
                    FROM dich_vu_khach_san dvk
                    JOIN dich_vu dv ON dvk.dich_vu_id = dv.id
                    WHERE dvk.id = ?
                ");
                if (!$stmt_dv) { throw new Exception("Lỗi chuẩn bị câu lệnh dịch vụ: " . $conn->error); }
                $stmt_dv->bind_param("i", $dich_vu_id_ks);
                $stmt_dv->execute();
                $result_dv = $stmt_dv->get_result();
                $dich_vu_info = $result_dv->fetch_assoc();
                $stmt_dv->close();

                if ($dich_vu_info) {
                    $gia_dv = (float) $dich_vu_info['gia_dich_vu'];
                    $service_total_item = $gia_dv * $so_luong_dv;
                    $tong_tien_dich_vu_them += $service_total_item;

                    $stmt_dat_phong_dich_vu = $conn->prepare("
                        INSERT INTO dat_phong_dich_vu (dat_phong_id, dich_vu_khach_san_id, so_luong, gia)
                        VALUES (?, ?, ?, ?)
                    ");
                    if (!$stmt_dat_phong_dich_vu) { throw new Exception("Lỗi chuẩn bị câu lệnh đặt dịch vụ phòng: " . $conn->error); }
                    $stmt_dat_phong_dich_vu->bind_param("iiid", $dat_phong_id, $dich_vu_id_ks, $so_luong_dv, $gia_dv);
                    if (!$stmt_dat_phong_dich_vu->execute()) { throw new Exception("Lỗi thực thi đặt dịch vụ phòng: " . $stmt_dat_phong_dich_vu->error); }
                    $stmt_dat_phong_dich_vu->close();
                    $dich_vu_details_email[] = ['ten' => $dich_vu_info['ten_dich_vu'], 'so_luong' => $so_luong_dv, 'gia' => $gia_dv, 'thanh_tien' => $service_total_item];
                } else {
                    error_log("Không tìm thấy dịch vụ với ID (dich_vu_khach_san.id): $dich_vu_id_ks");
                }
            }
            if ($tong_tien_dich_vu_them > 0) {
                $tong_tien_don_hang += $tong_tien_dich_vu_them;
                $stmt_update_tong_tien = $conn->prepare("UPDATE dat_phong SET tong_tien = ? WHERE id = ?");
                if (!$stmt_update_tong_tien) { throw new Exception("Lỗi chuẩn bị cập nhật tổng tiền: " . $conn->error); }
                $stmt_update_tong_tien->bind_param("di", $tong_tien_don_hang, $dat_phong_id);
                if (!$stmt_update_tong_tien->execute()) { throw new Exception("Lỗi thực thi cập nhật tổng tiền: " . $stmt_update_tong_tien->error); }
                $stmt_update_tong_tien->close();
            }
        }

        $conn->commit();

        $email_ngay_nhan = date("d/m/Y", strtotime($ngay_nhan_phong));
        $email_ngay_tra = date("d/m/Y", strtotime($ngay_tra_phong));
        $email_tong_tien = number_format($tong_tien_don_hang, 0, ',', '.') . " VND";

        $customer_subject = "Xác nhận yêu cầu đặt phòng #$dat_phong_id";
        $customer_body = "Xin chào $user_name,<br><br>Cảm ơn bạn đã đặt phòng tại {$hotel_name_for_notification}!<br>Yêu cầu đặt phòng của bạn với mã <strong>#$dat_phong_id</strong> đã được hệ thống tiếp nhận và đang chờ xử lý.<br><br><strong>Thông tin đơn đặt:</strong><br>Khách sạn: $hotel_name_for_notification<br>Các phòng đã đặt:<br>";
        foreach ($phongs_da_dat_email as $phong_e) { $customer_body .= "- {$phong_e['ten_phong']} (Số lượng: {$phong_e['so_luong']})<br>"; }
        $customer_body .= "Ngày nhận phòng: $email_ngay_nhan<br>Ngày trả phòng: $email_ngay_tra<br>";
        if (!empty($dich_vu_details_email)) {
            $customer_body .= "Dịch vụ đi kèm:<br>";
            foreach ($dich_vu_details_email as $dv_e) { $customer_body .= "- {$dv_e['ten']} (Số lượng: {$dv_e['so_luong']})<br>"; }
        }
        $customer_body .= "Tổng tiền dự kiến: $email_tong_tien<br>Trạng thái: Đang chờ xác nhận (Pending)<br><br>Chúng tôi sẽ liên hệ với bạn để xác nhận đơn đặt phòng trong thời gian sớm nhất. Bạn cũng có thể theo dõi trạng thái đơn hàng trong mục 'Đơn đặt của tôi'.<br>Trân trọng,<br>Hệ thống đặt phòng.";
        sendEmailNotification($user_email, $customer_subject, $customer_body);

        if ($hotel_email_for_notification) {
            $hotel_subject = "Đơn đặt phòng mới #$dat_phong_id từ khách hàng $user_name";
            $hotel_body = "Xin chào Quản lý khách sạn $hotel_name_for_notification,<br><br>Bạn có một đơn đặt phòng mới cần được xem xét và xác nhận.<br><br><strong>Thông tin chi tiết:</strong><br>Mã đơn đặt: #$dat_phong_id<br>Khách hàng: $user_name (Email: $user_email)<br>Ngày nhận phòng: $email_ngay_nhan<br>Ngày trả phòng: $email_ngay_tra<br>Danh sách phòng đặt:<br>";
            foreach ($phongs_da_dat_email as $phong_e) { $hotel_body .= "- {$phong_e['ten_phong']} (Số lượng: {$phong_e['so_luong']}, Đơn giá: " . number_format($phong_e['gia_phong_ngay'], 0, ',', '.') . " VND)<br>"; }
            if (!empty($dich_vu_details_email)) {
                $hotel_body .= "Dịch vụ đi kèm:<br>";
                foreach ($dich_vu_details_email as $dv_e) { $hotel_body .= "- {$dv_e['ten']} (Số lượng: {$dv_e['so_luong']}, Đơn giá: " . number_format($dv_e['gia'], 0, ',', '.') . " VND)<br>"; }
            }
            $hotel_body .= "Tổng tiền: $email_tong_tien<br><br>Vui lòng truy cập hệ thống quản lý của khách sạn để xử lý đơn đặt phòng này.<br>Trân trọng.";
            sendEmailNotification($hotel_email_for_notification, $hotel_subject, $hotel_body);
        }

        echo json_encode(["status" => "success", "message" => "Đơn đặt phòng đã được tạo thành công và đang chờ xử lý.", "dat_phong_id" => $dat_phong_id, "tong_tien" => $tong_tien_don_hang]);

    } catch (Exception $e) { // Bắt Exception từ logic nghiệp vụ, DB query
        $conn->rollback();
        error_log("Booking processing error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\nStack trace: " . $e->getTraceAsString());
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }

} catch (Throwable $e) { // <<< THAY ĐỔI: Bắt Throwable để bắt cả Error và Exception
    // Log lỗi nghiêm trọng hơn ở đây
    if (isset($conn) && $conn->connect_errno === 0 && $conn->in_transaction) { // Kiểm tra xem có đang trong transaction không
         $conn->rollback(); // Cố gắng rollback nếu có lỗi ngoài transaction try-catch
    }
    error_log("System error/PHP Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine() . "\nStack trace: " . $e->getTraceAsString());
    // Trả về lỗi JSON chung chung cho client
    if (!headers_sent()) { // Đảm bảo header chưa được gửi
        // header("Content-Type: application/json; charset=UTF-8"); // Đã set ở đầu file
        http_response_code(500); // Internal Server Error
    }
    echo json_encode(["status" => "error", "message" => "Lỗi hệ thống nghiêm trọng. Vui lòng thử lại sau."]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>