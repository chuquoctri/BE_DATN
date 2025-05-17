<?php
// File: create_payment_vnpay.php
require_once '../../connect.php'; // Kết nối CSDL
date_default_timezone_set('Asia/Ho_Chi_Minh');
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

$input = json_decode(file_get_contents("php://input"), true);

$userId = $input['userId'] ?? null;
// totalAmount là tổng tiền CUỐI CÙNG sau khi đã trừ voucher (nếu có)
$finalTotalAmount = isset($input['totalAmount']) ? (float)$input['totalAmount'] : 0;
$bookingsInput = $input['bookings'] ?? [];

// Thông tin voucher (tùy chọn, chỉ có nếu người dùng áp dụng voucher)
$originalTotalAmount = isset($input['originalAmount']) ? (float)$input['originalAmount'] : null;
$discountAmount = isset($input['discountAmount']) ? (float)$input['discountAmount'] : null;
$voucherId = isset($input['voucherId']) ? (int)$input['voucherId'] : null;
$nguoidungVoucherId = isset($input['nguoidungVoucherId']) ? (int)$input['nguoidungVoucherId'] : null;

// Kiểm tra dữ liệu đầu vào cơ bản
if (!$userId || $finalTotalAmount <= 0 || empty($bookingsInput)) {
    echo json_encode(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ (userId, totalAmount, bookings).']);
    exit;
}

// Nếu có voucher, originalAmount phải được cung cấp và lớn hơn hoặc bằng finalTotalAmount
if ($nguoidungVoucherId && ($originalTotalAmount === null || $originalTotalAmount < $finalTotalAmount)) {
    echo json_encode(['status' => 'error', 'message' => 'Thông tin voucher không hợp lệ (originalAmount).']);
    exit;
}

// Xác định tong_tien_goc và tien_giam_voucher cho CSDL
$db_tong_tien_goc = $originalTotalAmount ?? $finalTotalAmount;
$db_tien_giam_voucher = $discountAmount ?? 0.00;


$conn->begin_transaction();
$payment_database_id = 0;
$chiTietDonHangIdsStr = "";

try {
    // Sửa câu lệnh INSERT để bao gồm các trường mới liên quan đến voucher
    $sqlThanhToan = "INSERT INTO thanh_toan (nguoi_dung_id, tong_tien_goc, tien_giam_voucher, tong_tien, hinh_thuc, trang_thai, voucher_id, nguoidung_voucher_id) VALUES (?, ?, ?, ?, 'VNPay', 'pending', ?, ?)";
    $stmtThanhToan = $conn->prepare($sqlThanhToan);
    if (!$stmtThanhToan) throw new Exception("Lỗi chuẩn bị câu lệnh (thanh_toan): " . $conn->error);
    
    // bind_param: i d d d i i (userId, tong_tien_goc, tien_giam_voucher, tong_tien, voucher_id, nguoidung_voucher_id)
    $stmtThanhToan->bind_param("idddii", $userId, $db_tong_tien_goc, $db_tien_giam_voucher, $finalTotalAmount, $voucherId, $nguoidungVoucherId);
    $stmtThanhToan->execute();
    $payment_database_id = $conn->insert_id;

    if ($payment_database_id == 0) throw new Exception("Không thể tạo bản ghi thanh toán mới.");
    $stmtThanhToan->close();

    $stmtChiTiet = $conn->prepare("INSERT INTO chi_tiet_thanh_toan (thanh_toan_id, dat_phong_id, so_tien) VALUES (?, ?, ?)");
    if (!$stmtChiTiet) throw new Exception("Lỗi chuẩn bị câu lệnh (chi_tiet_thanh_toan): " . $conn->error);
    $bookingIdsForOrderInfo = [];
    foreach ($bookingsInput as $booking) {
        $datPhongId = $booking['id'] ?? null;
        // so_tien trong chi_tiet_thanh_toan nên là giá gốc của từng booking item, hoặc giá sau khi đã phân bổ giảm giá.
        // Hiện tại đang lấy bookingPrice trực tiếp. Nếu voucher áp dụng toàn đơn hàng, việc phân bổ giảm giá cho từng item có thể phức tạp.
        // Giả định bookingPrice là giá cuối của item đó.
        $bookingPrice = isset($booking['price']) ? (float)$booking['price'] : 0;
        if (!$datPhongId || $bookingPrice <= 0) throw new Exception("Chi tiết đặt phòng không hợp lệ.");
        $stmtChiTiet->bind_param("iid", $payment_database_id, $datPhongId, $bookingPrice);
        $stmtChiTiet->execute();
        $bookingIdsForOrderInfo[] = $datPhongId;
    }
    $stmtChiTiet->close();

    $chiTietDonHangIdsStr = implode(",", $bookingIdsForOrderInfo);
    $vnpTxnRef_value = (string)$payment_database_id;

    $stmtUpdateTxnRef = $conn->prepare("UPDATE thanh_toan SET vnp_txn_ref = ?, chi_tiet_don_hang_ids = ? WHERE id = ?");
    if (!$stmtUpdateTxnRef) throw new Exception("Lỗi chuẩn bị câu lệnh (cập nhật thanh_toan): " . $conn->error);
    $stmtUpdateTxnRef->bind_param("ssi", $vnpTxnRef_value, $chiTietDonHangIdsStr, $payment_database_id);
    $stmtUpdateTxnRef->execute();
    $stmtUpdateTxnRef->close();

    $conn->commit();
} catch (Exception $e) {
    $conn->rollback();
    error_log("Lỗi CSDL khi tạo thanh toán VNPAY (create_payment_vnpay.php): " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Lỗi khi khởi tạo thanh toán trong CSDL: ' . $e->getMessage()]);
    exit;
}

// === Cấu hình VNPAY với thông tin MỚI NHẤT ===
$vnp_TmnCode = "DZDWFVXQ"; // << MÃ WEBSITE MỚI NHẤT
$vnp_HashSecret = "GW3X067U08UFH4BVHGBWLK1JX89LJX6X"; // << CHUỖI BÍ MẬT MỚI NHẤT
$vnp_Url = "https://sandbox.vnpayment.vn/paymentv2/vpcpay.html";
$vnp_Returnurl = "https://d238-14-247-255-18.ngrok-free.app/API_DATN/API_User/Payment/vnpay_return_handler.php"; 

$vnp_TxnRef = (string)$payment_database_id; // Đây chính là $payment_database_id
$vnp_OrderInfo = "Thanh toan don hang " . $vnp_TxnRef;
$vnp_OrderType = "billpayment";
$vnp_Amount = $finalTotalAmount * 100; // Gửi tổng tiền cuối cùng cho VNPAY
$vnp_Locale = "vn";
$vnp_BankCode = ""; // Bỏ trống để khách chọn ngân hàng trên cổng VNPAY
$vnp_IpAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$vnp_CreateDate = date('YmdHis');

$inputData = array(
    "vnp_Version" => "2.1.0",
    "vnp_TmnCode" => $vnp_TmnCode,
    "vnp_Amount" => $vnp_Amount,
    "vnp_Command" => "pay",
    "vnp_CreateDate" => $vnp_CreateDate,
    "vnp_CurrCode" => "VND",
    "vnp_IpAddr" => $vnp_IpAddr,
    "vnp_Locale" => $vnp_Locale,
    "vnp_OrderInfo" => $vnp_OrderInfo,
    "vnp_OrderType" => $vnp_OrderType,
    "vnp_ReturnUrl" => $vnp_Returnurl,
    "vnp_TxnRef" => $vnp_TxnRef,
);
if (!empty($vnp_BankCode)) {
    $inputData['vnp_BankCode'] = $vnp_BankCode;
}

ksort($inputData);
$query = "";
$hashdata = "";
$i = 0;
foreach ($inputData as $key => $value) {
    if ($i == 1) {
        $hashdata .= '&' . urlencode((string)$key) . "=" . urlencode((string)$value);
    } else {
        $hashdata .= urlencode((string)$key) . "=" . urlencode((string)$value);
        $i = 1;
    }
    $query .= urlencode((string)$key) . "=" . urlencode((string)$value) . '&';
}

$vnp_Url_Payment = $vnp_Url . "?" . $query;
$vnpSecureHash = hash_hmac('sha512', $hashdata, $vnp_HashSecret);
$vnp_Url_Payment .= 'vnp_SecureHash=' . $vnpSecureHash;

echo json_encode([
    'status' => 'success',
    'payment_url' => $vnp_Url_Payment,
    'order_id' => $vnp_TxnRef // order_id trả về cho client là ID của bảng thanh_toan
]);
?>