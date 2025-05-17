<?php
// File: apply_voucher.php (ĐÃ SỬA ĐỂ TRẢ VỀ nguoidungVoucherId)
require_once '../../connect.php'; // Adjust path as needed
header('Content-Type: application/json');

$data = json_decode(file_get_contents("php://input"), true);
$userId = $data['userId'] ?? null;
$initialTotalAmount = isset($data['totalAmount']) ? (float)$data['totalAmount'] : 0;
$voucherId_input = $data['voucherId'] ?? null; // Đây là v.id mà client gửi lên để áp dụng
$hotelId = $data['hotelId'] ?? null;

if (!$userId || !$voucherId_input || $initialTotalAmount <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Thiếu thông tin người dùng, voucher, hoặc tổng tiền không hợp lệ.']);
    exit;
}

// Lấy thông tin voucher + kiểm tra người dùng đã lưu voucher và chưa dùng
// Quan trọng: Lấy nv.id (nguoidung_voucher.id) để trả về cho client
$sql = "SELECT v.*, nv.id as nguoidung_voucher_id_db 
        FROM nguoidung_voucher nv
        JOIN voucher v ON nv.voucher_id = v.id
        WHERE nv.nguoi_dung_id = ? AND v.id = ? AND (nv.da_dung = 0 OR nv.da_dung IS NULL)";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['status' => 'error', 'message' => 'Lỗi prepare SQL: ' . $conn->error]);
    exit;
}
// $voucherId_input ở đây chính là v.id (voucher.id) mà người dùng chọn để áp dụng
$stmt->bind_param("ii", $userId, $voucherId_input);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Voucher không hợp lệ, không tìm thấy cho người dùng này, hoặc đã được sử dụng.']);
    $stmt->close();
    exit;
}
$voucher_data = $result->fetch_assoc(); // $voucher_data chứa cả thông tin từ v.* và nv.id as nguoidung_voucher_id_db
$stmt->close();

// 1. Kiểm tra khách sạn
if ($voucher_data['khachsan_id'] !== null) {
    if ($hotelId === null || $voucher_data['khachsan_id'] != $hotelId) {
        echo json_encode(['status' => 'error', 'message' => 'Voucher không áp dụng cho khách sạn trong đơn hàng này.']);
        exit;
    }
}

// 2. Kiểm tra ngày và trạng thái
$now = date('Y-m-d H:i:s');
if ($voucher_data['trang_thai'] != 'dang_dien_ra' || $now < $voucher_data['ngay_bat_dau'] || $now > $voucher_data['ngay_ket_thuc']) {
    echo json_encode(['status' => 'error', 'message' => 'Voucher đã hết hạn, chưa đến ngày sử dụng, hoặc không còn hoạt động.']);
    exit;
}

// 3. Kiểm tra số lượng còn dùng (trên bảng voucher)
if (!is_null($voucher_data['so_luong_toi_da']) && $voucher_data['so_luong_da_dung'] >= $voucher_data['so_luong_toi_da']) {
    echo json_encode(['status' => 'error', 'message' => 'Voucher đã hết lượt sử dụng chung.']);
    exit;
}

// 4. Kiểm tra đơn hàng tối thiểu
if (!is_null($voucher_data['dieu_kien_don_hang_toi_thieu']) && $initialTotalAmount < $voucher_data['dieu_kien_don_hang_toi_thieu']) {
    echo json_encode(['status' => 'error', 'message' => 'Đơn hàng chưa đạt giá trị tối thiểu (' . number_format($voucher_data['dieu_kien_don_hang_toi_thieu']) . 'đ) để áp dụng voucher này.']);
    exit;
}

// 5. Tính toán giảm giá
$discount = 0;
if ($voucher_data['loai_giam'] == 'tien_mat') {
    $discount = floatval($voucher_data['gia_tri_giam']);
} else if ($voucher_data['loai_giam'] == 'phan_tram') {
    $discount = (floatval($voucher_data['gia_tri_giam']) / 100) * $initialTotalAmount;
}

$discount = min($discount, $initialTotalAmount);
$newTotal = $initialTotalAmount - $discount;

echo json_encode([
    'status' => 'success',
    'message' => 'Áp dụng voucher thành công!',
    'discount' => round($discount, 2),
    'newTotal' => round($newTotal, 2),
    'voucherName' => $voucher_data['ma_voucher'],
    'voucherId' => (int)$voucher_data['id'], // ID từ bảng voucher (v.id)
    'nguoidungVoucherId' => (int)$voucher_data['nguoidung_voucher_id_db'] // ID từ bảng nguoidung_voucher (nv.id) - ĐÃ THÊM
]);

$conn->close();
?>