<?php
// --- PHẦN 1: CÀI ĐẶT BAN ĐẦU VÀ XỬ LÝ CORS ---

// Bật hiển thị lỗi chi tiết khi đang phát triển. TẮT ở môi trường production.
error_reporting(E_ALL);
ini_set('display_errors', 1); // Hiển thị lỗi ra trình duyệt nếu có (giúp debug CORS nếu PHP lỗi sớm)
// ini_set('log_errors', 1); // Nên bật để ghi log lỗi
// ini_set('error_log', '/path/to/your/php-error.log'); // Thay bằng đường dẫn file log thực tế

// CORS Headers - PHẢI được đặt trước mọi output khác
header("Access-Control-Allow-Origin: *"); // Cho phép tất cả, hoặc chỉ định: http://127.0.0.1:3000
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization"); // Cho phép các header này

// Xử lý Preflight Request (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200); // Trả về 200 OK cho preflight
    // error_log("OPTIONS request handled successfully."); // Ghi log nếu muốn theo dõi
    exit(); // Dừng script ngay lập tức, không xử lý gì thêm
}

// Header Content-Type cho các response dữ liệu thực sự (sau khi OPTIONS đã được xử lý)
header('Content-Type: application/json; charset=UTF-8');

// --- PHẦN 2: REQUIRE CÁC FILE CẦN THIẾT ---
require_once '../../connect.php'; // Đường dẫn đến file kết nối CSDL
require_once __DIR__ . '/../../vendor/autoload.php'; // Đường dẫn đến PHPMailer autoload

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// --- PHẦN 3: CÁC HÀM HỖ TRỢ ---

// Hàm lấy thông tin người dùng hiện tại (quan_ly hoặc admin)
function getCurrentUser($conn) {
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else { 
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headerKey = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerKey] = $value;
            }
        }
    }
    
    $authHeader = $headers['Authorization'] ?? '';
    // error_log("QL_DatPhong_API Auth Header: " . $authHeader); // Debug

    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $user_id_str = $matches[1];
        if (!is_numeric($user_id_str)) {
            // error_log("QL_DatPhong_API User ID from token is not numeric: " . $user_id_str);
            return null;
        }
        $user_id = intval($user_id_str);

        $stmt = $conn->prepare("SELECT id, ho_ten, email, role, khach_san_id FROM nguoi_dung WHERE id = ? AND (role = 'quan_ly' OR role = 'admin')");
        if ($stmt === false) {
            // error_log("QL_DatPhong_API getCurrentUser Prepare failed: " . $conn->error);
            return null;
        }
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) {
            // error_log("QL_DatPhong_API getCurrentUser Execute failed: " . $stmt->error);
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $userData = $result->fetch_assoc();
            if ($userData['role'] === 'quan_ly' && empty($userData['khach_san_id'])) {
                // error_log("QL_DatPhong_API Manager user " . $userData['id'] . " has no khach_san_id assigned.");
                $stmt->close();
                return null; 
            }
            $stmt->close();
            return $userData; 
        }
        $stmt->close();
        // error_log("QL_DatPhong_API User ID " . $user_id . " not found or not manager/admin.");
    } else {
        // error_log("QL_DatPhong_API Bearer token not found or invalid format.");
    }
    return null;
}

// Hàm cập nhật trạng thái đơn đặt và gửi email thông báo
function updateBookingStatusAndNotify($conn, $dat_phong_id, $new_status, $manager_hotel_id) {
    // 1. Kiểm tra đơn đặt có tồn tại, thuộc khách sạn của quản lý và trạng thái hiện tại
    $check_stmt = $conn->prepare("
        SELECT dp.id, dp.trang_thai, p.khach_san_id 
        FROM dat_phong dp
        JOIN phong p ON dp.phong_id = p.id
        WHERE dp.id = ?
    ");
    if (!$check_stmt) throw new Exception("Lỗi CSDL: Không thể chuẩn bị kiểm tra đơn đặt.", 500);
    $check_stmt->bind_param("i", $dat_phong_id);
    if (!$check_stmt->execute()) throw new Exception("Lỗi CSDL: Không thể thực thi kiểm tra đơn đặt.", 500);
    $check_result = $check_stmt->get_result();
    
    if ($check_result->num_rows === 0) {
        throw new Exception("Đơn đặt không tồn tại trong hệ thống.", 404);
    }
    
    $current_booking_info = $check_result->fetch_assoc();
    $check_stmt->close();

    if ($current_booking_info['khach_san_id'] != $manager_hotel_id) {
        throw new Exception("Bạn không có quyền cập nhật trạng thái cho đơn đặt này.", 403);
    }
    if ($current_booking_info['trang_thai'] === $new_status) {
        return ["success" => true, "message" => "Đơn đặt đã ở trạng thái này.", "already_in_status" => true];
    }

    // 2. Cập nhật trạng thái
    $update_stmt = $conn->prepare("UPDATE dat_phong SET trang_thai = ? WHERE id = ?");
    if (!$update_stmt) throw new Exception("Lỗi CSDL: Không thể chuẩn bị cập nhật trạng thái.", 500);
    $update_stmt->bind_param("si", $new_status, $dat_phong_id);
    if (!$update_stmt->execute()) throw new Exception("Lỗi CSDL: Không thể cập nhật trạng thái đơn đặt.", 500);
    
    $affected_rows = $update_stmt->affected_rows;
    $update_stmt->close();
    if ($affected_rows === 0 && $current_booking_info['trang_thai'] !== $new_status) {
        throw new Exception("Cập nhật trạng thái không thành công (không có dòng nào được thay đổi).", 500);
    }

    // 3. Lấy thông tin đầy đủ để gửi email
    $stmt_details = $conn->prepare("
        SELECT dp.*, nd.email as nguoi_dung_email, nd.ho_ten as nguoi_dung_ho_ten, p.ten as ten_phong 
        FROM dat_phong dp
        JOIN nguoi_dung nd ON dp.nguoi_dung_id = nd.id
        JOIN phong p ON dp.phong_id = p.id
        WHERE dp.id = ?
    ");
    if (!$stmt_details) throw new Exception("Lỗi CSDL: Không thể chuẩn bị lấy chi tiết đơn.", 500);
    $stmt_details->bind_param("i", $dat_phong_id);
    if (!$stmt_details->execute()) throw new Exception("Lỗi CSDL: Không thể thực thi lấy chi tiết đơn.", 500);
    $result_details = $stmt_details->get_result();
    $booking_details = $result_details->fetch_assoc();
    $stmt_details->close();

    if (!$booking_details) {
        throw new Exception("Không tìm thấy chi tiết đơn đặt sau khi cập nhật.", 500);
    }
    
    // 4. Chuẩn bị và gửi email
    $status_map_display = [
        'pending'   => ['text' => 'Đang chờ xử lý', 'color' => '#FFA500'],
        'confirmed' => ['text' => 'Đã xác nhận', 'color' => '#28a745'],
        'cancelled' => ['text' => 'Đã hủy', 'color' => '#dc3545']
    ];
    $display_status_text = $status_map_display[$new_status]['text'] ?? ucfirst($new_status);
    $display_status_color = $status_map_display[$new_status]['color'] ?? '#000000';

    $subject = "Cập nhật trạng thái đơn đặt phòng #{$booking_details['id']}";
    $body = "<p>Xin chào {$booking_details['nguoi_dung_ho_ten']},</p>";
    $body .= "<p>Đơn đặt phòng của bạn tại khách sạn đã được cập nhật trạng thái:</p>";
    $body .= "<ul>";
    $body .= "<li>Mã đơn hàng: <strong>#{$booking_details['id']}</strong></li>";
    $body .= "<li>Phòng: {$booking_details['ten_phong']}</li>";
    $body .= "<li>Ngày nhận phòng: " . date("d/m/Y", strtotime($booking_details['ngay_nhan_phong'])) . "</li>";
    $body .= "<li>Ngày trả phòng: " . date("d/m/Y", strtotime($booking_details['ngay_tra_phong'])) . "</li>";
    $body .= "<li>Tổng tiền: " . number_format($booking_details['tong_tien'], 0, ',', '.') . " VNĐ</li>";
    $body .= "<li>Trạng thái mới: <strong style='color:{$display_status_color};'>{$display_status_text}</strong></li>";
    $body .= "</ul>";
    // Thêm thông báo tùy chỉnh theo trạng thái
    if ($new_status == 'confirmed') {
        $body .= "<p>Cảm ơn bạn đã đặt phòng! Đơn của bạn đã được xác nhận. Vui lòng kiểm tra email thường xuyên để nhận thông tin chi tiết.</p>";
    } elseif ($new_status == 'cancelled') {
        $body .= "<p>Rất tiếc, đơn đặt phòng của bạn đã bị hủy. Vui lòng liên hệ hỗ trợ nếu có thắc mắc.</p>";
    }
    $body .= "<p>Trân trọng,<br>Ban quản lý khách sạn.</p>";
    
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com'; // Thay bằng SMTP Host của bạn
        $mail->SMTPAuth = true;
        $mail->Username = 'chuquoctri03@gmail.com'; // Thay bằng email của bạn
        $mail->Password = 'utww odbp tqmp fmmr';   // Thay bằng mật khẩu ứng dụng hoặc mật khẩu email
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->CharSet = 'UTF-8';
        $mail->setFrom('no-reply@yourhoteldomain.com', 'Hệ Thống Đặt Phòng Khách Sạn'); // Nên dùng email domain của bạn
        $mail->addAddress($booking_details['nguoi_dung_email'], $booking_details['nguoi_dung_ho_ten']);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
    } catch (Exception $mail_e) {
        // Ghi log lỗi gửi mail nhưng không throw Exception để không rollback việc cập nhật DB
        error_log("Lỗi gửi email thông báo cho đơn đặt #{$dat_phong_id}: " . $mail_e->getMessage());
        // Trả về thông báo cho client biết cập nhật DB thành công nhưng mail lỗi
        return ["success" => true, "message" => "Cập nhật trạng thái thành công, nhưng gửi email thông báo thất bại.", "email_error" => $mail_e->getMessage()];
    }
    return ["success" => true, "message" => "Cập nhật trạng thái và gửi email thành công."];
}

// Hàm này bạn có thể giữ lại để dùng cho một endpoint riêng hoặc cron job
function processExpiredBookings($conn, $hotel_id) {
    // Chỉ xử lý đơn của khách sạn hiện tại
    try {
        // Hủy đơn confirmed quá 24h chưa thanh toán (giả sử có cột ngay_xac_nhan hoặc dùng ngay_cap_nhat)
        $stmt_cancel = $conn->prepare("UPDATE dat_phong dp JOIN phong p ON dp.phong_id = p.id 
                                     SET dp.trang_thai = 'cancelled' 
                                     WHERE p.khach_san_id = ? AND dp.trang_thai = 'confirmed' 
                                     AND dp.trang_thai_thanh_toan = 'chua_thanh_toan'
                                     AND TIMESTAMPDIFF(HOUR, dp.ngay_cap_nhat, NOW()) > 24");
        if($stmt_cancel) {
            $stmt_cancel->bind_param("i", $hotel_id);
            $stmt_cancel->execute();
            $stmt_cancel->close();
        }
        
        // Xóa đơn pending quá 24h
        $stmt_delete = $conn->prepare("DELETE dp FROM dat_phong dp JOIN phong p ON dp.phong_id = p.id
                                      WHERE p.khach_san_id = ? AND dp.trang_thai = 'pending' 
                                      AND TIMESTAMPDIFF(HOUR, dp.ngay_tao, NOW()) > 24");
        if($stmt_delete) {
            $stmt_delete->bind_param("i", $hotel_id);
            $stmt_delete->execute();
            $stmt_delete->close();
        }
    } catch (Exception $e) {
        error_log("Lỗi xử lý đơn quá hạn cho khách sạn $hotel_id: " . $e->getMessage());
    }
}


// --- PHẦN 4: XỬ LÝ REQUEST CHÍNH ---

if (!$conn) {
    http_response_code(503); 
    echo json_encode(["success" => false, "message" => "Lỗi kết nối cơ sở dữ liệu."]);
    exit();
}

$user = getCurrentUser($conn); // Đã gọi 1 lần, giờ dùng lại biến $user
if (!$user) {
    http_response_code(401); 
    echo json_encode(["success" => false, "message" => "Xác thực thất bại. Yêu cầu token hợp lệ cho quản lý/admin."]);
    exit();
}

$hotel_id_cua_quan_ly = $user['khach_san_id'] ?? null;
// Kiểm tra này rất quan trọng cho vai trò 'quan_ly'
if ($user['role'] === 'quan_ly' && empty($hotel_id_cua_quan_ly)) {
    http_response_code(403); 
    echo json_encode(["success" => false, "message" => "Quản lý chưa được gán khách sạn."]);
    exit();
}
// Nếu là admin, $hotel_id_cua_quan_ly có thể null nếu admin quản lý toàn hệ thống.
// Các query cần điều chỉnh nếu admin không có hotel_id mà vẫn muốn xem/sửa đơn (ví dụ: bỏ `WHERE p.khach_san_id = ?`)
// Hiện tại, code đang giả định cả admin và quản lý đều thao tác trên một hotel_id cụ thể.
if (empty($hotel_id_cua_quan_ly) && $user['role'] !== 'super_admin_global_access') { // ví dụ một role admin toàn cục
    http_response_code(403); 
    echo json_encode(["success" => false, "message" => "Không xác định được phạm vi khách sạn để thao tác."]);
    exit();
}
$hotel_id_cua_quan_ly = intval($hotel_id_cua_quan_ly);


$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    try {
        // Lấy chi tiết một đơn đặt nếu có `id` trong query string
        if (isset($_GET['id'])) {
            $dat_phong_id_get = filter_var($_GET['id'], FILTER_VALIDATE_INT);
            if ($dat_phong_id_get === false || $dat_phong_id_get <= 0) {
                throw new Exception("ID đơn đặt không hợp lệ.", 400);
            }

            $sql_dp = "SELECT dp.id, dp.ngay_nhan_phong, dp.ngay_tra_phong, dp.tong_tien, dp.trang_thai, dp.trang_thai_thanh_toan, dp.ngay_tao,
                              nd.ho_ten as ten_nguoi_dat, nd.email as email_nguoi_dat,
                              p.ten as ten_phong, p.id as phong_id_val, p.khach_san_id
                       FROM dat_phong dp
                       JOIN nguoi_dung nd ON dp.nguoi_dung_id = nd.id
                       JOIN phong p ON dp.phong_id = p.id
                       WHERE dp.id = ? AND p.khach_san_id = ?";
            $stmt_dp = $conn->prepare($sql_dp);
            if(!$stmt_dp) throw new Exception("Lỗi chuẩn bị truy vấn chi tiết đơn đặt: " . $conn->error, 500);
            $stmt_dp->bind_param("ii", $dat_phong_id_get, $hotel_id_cua_quan_ly);
            if(!$stmt_dp->execute()) throw new Exception("Lỗi thực thi truy vấn chi tiết đơn đặt: " . $stmt_dp->error, 500);
            
            $result_dp = $stmt_dp->get_result();
            $booking_detail = $result_dp->fetch_assoc();
            $stmt_dp->close();

            if ($booking_detail) {
                echo json_encode(["success" => true, "data" => $booking_detail]);
            } else {
                throw new Exception("Đơn đặt không tìm thấy hoặc không thuộc khách sạn quản lý.", 404);
            }
            exit();
        }

        // Lấy danh sách đơn đặt phòng
        $search_ten_nguoi_dung = $_GET['search'] ?? '';
        $page = isset($_GET['page']) && filter_var($_GET['page'], FILTER_VALIDATE_INT, ["options" => ["min_range"=>1]]) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && filter_var($_GET['limit'], FILTER_VALIDATE_INT, ["options" => ["min_range"=>1]]) ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;

        $conditions = " WHERE p.khach_san_id = ? ";
        $params = [$hotel_id_cua_quan_ly];
        $types = "i";

        if (!empty($search_ten_nguoi_dung)) {
            $searchTerm = "%{$search_ten_nguoi_dung}%";
            $conditions .= " AND nd.ho_ten LIKE ? ";
            $params[] = $searchTerm; 
            $types .= "s";
        }
        
        $countQuerySql = "SELECT COUNT(dp.id) as total 
                          FROM dat_phong dp
                          JOIN phong p ON dp.phong_id = p.id
                          JOIN nguoi_dung nd ON dp.nguoi_dung_id = nd.id" . $conditions;
        $stmt_count = $conn->prepare($countQuerySql);
        if(!$stmt_count) throw new Exception("Lỗi chuẩn bị đếm đơn đặt: " . $conn->error, 500);
        $stmt_count->bind_param($types, ...$params);
        if(!$stmt_count->execute()) throw new Exception("Lỗi thực thi đếm đơn đặt: " . $stmt_count->error, 500);
        $totalResult = $stmt_count->get_result()->fetch_assoc();
        $total = (int)$totalResult['total'];
        $stmt_count->close();

        $dataQuerySql = "SELECT dp.id, dp.ngay_nhan_phong, dp.ngay_tra_phong, dp.tong_tien, dp.trang_thai, dp.trang_thai_thanh_toan, dp.ngay_tao,
                                nd.ho_ten as ten_nguoi_dat, p.ten as ten_phong
                         FROM dat_phong dp
                         JOIN nguoi_dung nd ON dp.nguoi_dung_id = nd.id
                         JOIN phong p ON dp.phong_id = p.id" 
                         . $conditions . " ORDER BY dp.ngay_tao DESC LIMIT ? OFFSET ?";
        
        $params_data = $params; // Lấy lại params đã có (chứa hotel_id và search_term nếu có)
        $params_data[] = $limit; 
        $params_data[] = $offset; 
        $types_data = $types . "ii";
        
        $stmt_data = $conn->prepare($dataQuerySql);
        if(!$stmt_data) throw new Exception("Lỗi chuẩn bị lấy danh sách đơn đặt: " . $conn->error, 500);
        $stmt_data->bind_param($types_data, ...$params_data);
        if(!$stmt_data->execute()) throw new Exception("Lỗi thực thi lấy danh sách đơn đặt: " . $stmt_data->error, 500);
        
        $result_data = $stmt_data->get_result();
        $bookings = [];
        while ($row = $result_data->fetch_assoc()) {
            $bookings[] = $row;
        }
        $stmt_data->close();
        
        echo json_encode([
            "success" => true, "data" => $bookings,
            "pagination" => ["total" => $total, "page" => $page, "limit" => $limit, "total_pages" => $total > 0 ? ceil($total / $limit) : 0]
        ]);

    } catch (Exception $e) {
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("API_QL_DatPhong_GET_ERROR: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Lỗi máy chủ khi lấy danh sách đơn đặt: " . $e->getMessage()]);
    }
} 
// Sử dụng PUT để cập nhật trạng thái một đơn đặt cụ thể
elseif ($method === 'PUT') { 
    $dat_phong_id_to_update = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$dat_phong_id_to_update || $dat_phong_id_to_update <= 0) {
        http_response_code(400);
        echo json_encode(["success" => false, "message" => "ID đơn đặt phòng là bắt buộc và phải hợp lệ trên URL."]);
        exit();
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE || empty($data['trang_thai'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Thiếu trạng thái mới hoặc dữ liệu JSON không hợp lệ.']);
        exit;
    }

    $new_status = $data['trang_thai'];
    $allowed_statuses = ['pending', 'confirmed', 'cancelled'];
    if (!in_array($new_status, $allowed_statuses)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Trạng thái mới không hợp lệ.']);
        exit;
    }
    
    $conn->begin_transaction();
    try {
        $result_update = updateBookingStatusAndNotify($conn, $dat_phong_id_to_update, $new_status, $hotel_id_cua_quan_ly);
        $conn->commit();
        echo json_encode($result_update); 
    
    } catch (Exception $e) {
        $conn->rollback();
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("API_QL_DatPhong_PUT_ERROR: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => "Lỗi khi cập nhật trạng thái đơn đặt: " . $e->getMessage()]);
    }
}
else {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Phương thức không được hỗ trợ."]);
}

$conn->close();
?>