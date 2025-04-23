<?php
require_once '../../connect.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers");

$data = json_decode(file_get_contents("php://input"), true);

$user_id = isset($data['user_id']) ? intval($data['user_id']) : null;
$voucher_id = isset($data['voucher_id']) ? intval($data['voucher_id']) : null;

if (!$user_id || !$voucher_id) {
    echo json_encode(['status' => 'error', 'message' => 'Thiếu thông tin user_id hoặc voucher_id']);
    exit;
}

try {
    // Kiểm tra voucher có tồn tại và hợp lệ không
    $check_voucher = $conn->prepare("
        SELECT id FROM voucher 
        WHERE id = ? 
        AND trang_thai IN ('dang_dien_ra', 'sap_dien_ra')
        AND ngay_ket_thuc > NOW()
    ");
    $check_voucher->bind_param("i", $voucher_id);
    $check_voucher->execute();
    
    if ($check_voucher->get_result()->num_rows === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Voucher không hợp lệ hoặc đã hết hạn']);
        exit;
    }
    
    // Kiểm tra đã lưu voucher chưa
    $check_saved = $conn->prepare("
        SELECT id FROM nguoidung_voucher 
        WHERE nguoi_dung_id = ? AND voucher_id = ?
    ");
    $check_saved->bind_param("ii", $user_id, $voucher_id);
    $check_saved->execute();
    
    if ($check_saved->get_result()->num_rows > 0) {
        echo json_encode(['status' => 'error', 'message' => 'Bạn đã lưu voucher này rồi']);
        exit;
    }
    
    // Lưu voucher
    $save_voucher = $conn->prepare("
        INSERT INTO nguoidung_voucher (nguoi_dung_id, voucher_id, thoi_gian_luu)
        VALUES (?, ?, NOW())
    ");
    $save_voucher->bind_param("ii", $user_id, $voucher_id);
    
    if ($save_voucher->execute()) {
        echo json_encode(['status' => 'success', 'message' => 'Lưu voucher thành công']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Lỗi khi lưu voucher']);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Lỗi hệ thống: ' . $e->getMessage()
    ]);
}
?>