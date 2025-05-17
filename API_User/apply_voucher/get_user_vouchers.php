<?php
// File: get_user_vouchers.php (ĐÃ SỬA)
require_once '../../connect.php'; // Đảm bảo đường dẫn này chính xác

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

$input = json_decode(file_get_contents("php://input"), true);
$userId = $input['userId'] ?? null;
$hotelId = $input['hotelId'] ?? null;

if (!$userId) {
    echo json_encode(['status' => 'error', 'message' => 'User ID is required.']);
    exit;
}

$vouchers = [];
$now = date('Y-m-d H:i:s');

// SỬA CÂU SQL: Thêm nv.id AS nguoidung_voucher_id_db
$sql = "SELECT v.id, v.ma_voucher, v.hinh_anh, v.mo_ta, v.loai_giam, v.gia_tri_giam,
               v.ngay_bat_dau, v.ngay_ket_thuc, v.khachsan_id, v.trang_thai,
               v.so_luong_toi_da, v.so_luong_da_dung, v.dieu_kien_don_hang_toi_thieu,
               nv.id AS nguoidung_voucher_id_from_db  -- Lấy ID từ bảng nguoidung_voucher
        FROM nguoidung_voucher nv
        JOIN voucher v ON nv.voucher_id = v.id
        WHERE nv.nguoi_dung_id = ?
          AND (nv.da_dung = 0 OR nv.da_dung IS NULL)
          AND v.trang_thai = 'dang_dien_ra'
          AND v.ngay_bat_dau <= ?
          AND v.ngay_ket_thuc >= ?
          AND (v.so_luong_toi_da IS NULL OR v.so_luong_da_dung < v.so_luong_toi_da)";

$stmt = null;

if ($hotelId !== null) {
    $sql .= " AND (v.khachsan_id = ? OR v.khachsan_id IS NULL)";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("issi", $userId, $now, $now, $hotelId);
    }
} else {
    $sql .= " AND v.khachsan_id IS NULL";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("iss", $userId, $now, $now);
    }
}

if ($stmt) {
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $row['id'] = (int)$row['id']; // v.id
            $row['gia_tri_giam'] = (float)$row['gia_tri_giam'];
            $row['khachsan_id'] = $row['khachsan_id'] ? (int)$row['khachsan_id'] : null;
            $row['so_luong_toi_da'] = $row['so_luong_toi_da'] ? (int)$row['so_luong_toi_da'] : null;
            $row['so_luong_da_dung'] = (int)$row['so_luong_da_dung'];
            $row['dieu_kien_don_hang_toi_thieu'] = $row['dieu_kien_don_hang_toi_thieu'] ? (float)$row['dieu_kien_don_hang_toi_thieu'] : 0;
            // THÊM TRƯỜNG `nguoidungVoucherId` VÀO KẾT QUẢ TRẢ VỀ CHO CLIENT
            $row['nguoidungVoucherId'] = isset($row['nguoidung_voucher_id_from_db']) ? (int)$row['nguoidung_voucher_id_from_db'] : null;
            unset($row['nguoidung_voucher_id_from_db']); // Xóa alias gốc nếu muốn
            $vouchers[] = $row;
        }
        echo json_encode(['status' => 'success', 'data' => $vouchers]);
    } else {
        error_log("SQL execute failed: " . $stmt->error);
        echo json_encode(['status' => 'error', 'message' => 'Lỗi thực thi truy vấn: ' . $stmt->error]);
    }
    $stmt->close();
} else {
    error_log("SQL prepare failed: " . $conn->error);
    echo json_encode(['status' => 'error', 'message' => 'Lỗi chuẩn bị truy vấn: ' . $conn->error]);
}

$conn->close();
?>