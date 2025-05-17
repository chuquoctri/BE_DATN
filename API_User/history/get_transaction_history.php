<?php
require_once '../../connect.php'; // Đảm bảo đường dẫn kết nối CSDL là chính xác
// (Thêm file connect.php tương tự như các API trước)

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

// Lấy userId từ query parameter
$userId = $_GET['userId'] ?? null;

if (empty($userId) || !filter_var($userId, FILTER_VALIDATE_INT) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Thiếu hoặc userId không hợp lệ.']);
    exit;
}

$transactions = [];

try {
    // 1. Lấy các bản ghi thanh toán chính của người dùng
    $sql_payments = "SELECT
                        id AS thanh_toan_id,
                        nguoi_dung_id,
                        tong_tien_goc,
                        tien_giam_voucher,
                        tong_tien AS tong_tien_thanh_toan,
                        hinh_thuc,
                        trang_thai AS trang_thai_thanh_toan,
                        vnp_txn_ref,
                        ngay_tao AS ngay_tao_thanh_toan,
                        ngay_thanh_toan
                    FROM thanh_toan
                    WHERE nguoi_dung_id = ?
                    ORDER BY ngay_tao DESC";

    $stmt_payments = $conn->prepare($sql_payments);
    if (!$stmt_payments) {
        error_log("SQL Prepare Error (payments): " . $conn->error);
        throw new Exception("Lỗi khi chuẩn bị truy vấn thanh toán.");
    }
    $stmt_payments->bind_param("i", $userId);
    $stmt_payments->execute();
    $result_payments = $stmt_payments->get_result();

    while ($payment_row = $result_payments->fetch_assoc()) {
        $current_thanh_toan_id = $payment_row['thanh_toan_id'];
        $payment_item = $payment_row;
        $payment_item['chi_tiet_bookings'] = [];

        // 2. Với mỗi thanh toán, lấy chi tiết các đặt phòng liên quan
        $sql_details = "SELECT
                            dp.id as dat_phong_id,
                            dp.ngay_nhan_phong,
                            dp.ngay_tra_phong,
                            cttt.so_tien as so_tien_trong_thanh_toan_nay,
                            ks.ten as ten_khach_san
                            -- Bạn có thể thêm p.ten_phong nếu cần và bảng phong có cột đó
                        FROM chi_tiet_thanh_toan cttt
                        JOIN dat_phong dp ON cttt.dat_phong_id = dp.id
                        JOIN phong p ON dp.phong_id = p.id           -- Giả định có bảng 'phong'
                        JOIN khach_san ks ON p.khach_san_id = ks.id -- Giả định bảng 'phong' có khach_san_id
                        WHERE cttt.thanh_toan_id = ?";
        
        $stmt_details = $conn->prepare($sql_details);
        if (!$stmt_details) {
            error_log("SQL Prepare Error (details for payment $current_thanh_toan_id): " . $conn->error);
            // Có thể bỏ qua chi tiết nếu lỗi, hoặc ném exception tùy theo yêu cầu
            // For now, we'll add payment without details if this fails for some reason
            // throw new Exception("Lỗi khi chuẩn bị truy vấn chi tiết đặt phòng.");
            $payment_item['chi_tiet_bookings_error'] = $conn->error; // Log or show error for specific details
        } else {
            $stmt_details->bind_param("i", $current_thanh_toan_id);
            $stmt_details->execute();
            $result_details = $stmt_details->get_result();
            
            while ($detail_row = $result_details->fetch_assoc()) {
                $payment_item['chi_tiet_bookings'][] = $detail_row;
            }
            $stmt_details->close();
        }
        $transactions[] = $payment_item;
    }
    $stmt_payments->close();

    echo json_encode(['status' => 'success', 'data' => $transactions]);

} catch (Exception $e) {
    error_log("Exception in get_transaction_history.php: " . $e->getMessage() . " - UserID: " . $userId);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Đã có lỗi xảy ra ở phía máy chủ: ' . $e->getMessage()]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>