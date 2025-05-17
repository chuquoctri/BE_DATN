<?php
// --- DÀNH CHO GỠ LỖI TRONG MÔI TRƯỜNG PHÁT TRIỂN ---
// Hiển thị tất cả các lỗi PHP (xóa hoặc bình luận lại trên production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// ----------------------------------------------------

require_once '../../connect.php'; // Đảm bảo đường dẫn kết nối CSDL là chính xác

// --- KIỂM TRA ĐỐI TƯỢNG KẾT NỐI CSDL NGAY SAU KHI INCLUDE ---
// Biến $conn được giả định là khởi tạo trong connect.php
if (!isset($conn)) {
    // Ghi log nếu $conn không được định nghĩa sau khi require_once
    error_log("Lỗi nghiêm trọng: Biến \$conn không tồn tại sau khi require_once '../../connect.php'. Kiểm tra kỹ file connect.php.");
    // Đảm bảo header Content-Type được thiết lập trước khi echo JSON
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        // Các header CORS cần thiết nếu bạn muốn client nhận được lỗi này
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS"); // Thêm POST nếu cần
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
    }
    http_response_code(500); // Lỗi máy chủ nội bộ
    echo json_encode(['status' => 'error', 'message' => 'Lỗi cấu hình máy chủ: Kết nối CSDL không được khởi tạo. Vui lòng liên hệ quản trị viên.']);
    exit;
}

if (!$conn instanceof mysqli) {
    // Ghi log nếu $conn không phải là đối tượng mysqli
    $conn_type = gettype($conn);
    error_log("Lỗi nghiêm trọng: Biến \$conn không phải là đối tượng mysqli sau khi require_once '../../connect.php'. Actual type: " . $conn_type . ". Kiểm tra kỹ file connect.php.");
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
    }
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Lỗi cấu hình máy chủ: Đối tượng kết nối CSDL không hợp lệ. Vui lòng liên hệ quản trị viên.']);
    exit;
}

if ($conn->connect_error) {
    // Ghi log lỗi kết nối CSDL
    error_log("Lỗi kết nối CSDL (từ connect.php, kiểm tra trong get_pending_reviews.php): " . $conn->connect_errno . " - " . $conn->connect_error);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
    }
    http_response_code(500); // Lỗi máy chủ nội bộ
    echo json_encode(['status' => 'error', 'message' => 'Không thể kết nối đến cơ sở dữ liệu. Mã lỗi: ' . $conn->connect_errno]);
    exit;
}
// Thiết lập charset cho kết nối để đảm bảo dữ liệu tiếng Việt được xử lý đúng
if (!$conn->set_charset("utf8mb4")) {
    error_log("Lỗi khi thiết lập charset utf8mb4: " . $conn->error);
}
// --- KẾT THÚC KIỂM TRA KẾT NỐI CSDL ---

// Các header chính cho phản hồi JSON và CORS
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Allow-Methods: GET, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization");
}

// Xử lý pre-flight request cho CORS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Lấy userId từ query parameter của URL
$userId = $_GET['userId'] ?? null;

// Kiểm tra tính hợp lệ của userId
if (empty($userId) || !filter_var($userId, FILTER_VALIDATE_INT) || (int)$userId <= 0) {
    http_response_code(400); // Bad Request
    echo json_encode(['status' => 'error', 'message' => 'Thiếu hoặc userId không hợp lệ.']);
    exit;
}

try {
    // Câu lệnh SQL để lấy các quyền đánh giá đang chờ
    // ĐÃ SỬA: ks.ten AS ten_khach_san để sử dụng đúng cột 'ten' từ bảng 'khach_san'
    $sql = "SELECT
                qd.id as quyen_danh_gia_id,
                qd.khach_san_id,
                qd.ma_dat_phong_id,
                ks.ten AS ten_khach_san, -- SỬA Ở ĐÂY: Sử dụng cột 'ten' từ bảng 'khach_san' và đặt bí danh là 'ten_khach_san'
                ks.hinh_anh AS anh_dai_dien_ks,
                dp.ngay_nhan_phong,
                dp.ngay_tra_phong,
                qd.ngay_tao as ngay_co_quyen_danh_gia
            FROM quyen_danh_gia qd
            JOIN khach_san ks ON qd.khach_san_id = ks.id
            JOIN dat_phong dp ON qd.ma_dat_phong_id = dp.id
            WHERE qd.nguoi_dung_id = ? AND qd.da_danh_gia = FALSE
            ORDER BY dp.ngay_tra_phong DESC, qd.ngay_tao DESC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        error_log("SQL Prepare Error in get_pending_reviews.php (Dòng " . __LINE__ . "): " . $conn->errno . " - " . $conn->error . " - UserID: " . $userId);
        throw new Exception("Lỗi khi chuẩn bị truy vấn dữ liệu từ máy chủ. Mã lỗi SQLP-" . $conn->errno);
    }

    if (!$stmt->bind_param("i", $userId)) {
        error_log("SQL Bind Param Error in get_pending_reviews.php (Dòng " . __LINE__ . "): " . $stmt->errno . " - " . $stmt->error . " - UserID: " . $userId);
        throw new Exception("Lỗi khi liên kết tham số truy vấn. Mã lỗi SQLB-" . $stmt->errno);
    }

    if (!$stmt->execute()) {
        error_log("SQL Execute Error in get_pending_reviews.php (Dòng " . __LINE__ . "): " . $stmt->errno . " - " . $stmt->error . " - UserID: " . $userId);
        throw new Exception("Lỗi khi thực thi truy vấn dữ liệu. Mã lỗi SQLE-" . $stmt->errno);
    }

    $result = $stmt->get_result();
    if ($result === false) {
        error_log("SQL Get Result Error in get_pending_reviews.php (Dòng " . __LINE__ . "): " . $stmt->errno . " - " . $stmt->error . " - UserID: " . $userId);
        throw new Exception("Lỗi khi lấy kết quả truy vấn. Mã lỗi SQLG-" . $stmt->errno);
    }

    $pending_reviews = [];
    while ($row = $result->fetch_assoc()) {
        // Xử lý URL hình ảnh nếu cần thiết
        // if (!empty($row['anh_dai_dien_ks']) && !filter_var($row['anh_dai_dien_ks'], FILTER_VALIDATE_URL)) {
        //     $baseUrl = 'https://yourdomain.com/images/hotels/'; // Thay bằng URL thực tế của bạn
        //     $row['anh_dai_dien_ks'] = $baseUrl . ltrim($row['anh_dai_dien_ks'], '/');
        // }
        $pending_reviews[] = $row;
    }
    $stmt->close();

    echo json_encode(['status' => 'success', 'data' => $pending_reviews]);

} catch (Exception $e) {
    error_log("Caught Exception in get_pending_reviews.php (Dòng " . $e->getLine() . " trong file " . basename($e->getFile()) . "): " . $e->getMessage() . " - UserID: " . $userId);
    
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli && $conn->thread_id) {
        $conn->close();
    }
}
?>