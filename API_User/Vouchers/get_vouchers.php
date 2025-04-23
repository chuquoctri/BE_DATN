<?php
require_once '../../connect.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Lấy tham số từ request (nếu có)
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : null;

try {
    // Câu truy vấn lấy tất cả voucher, kèm ảnh voucher (alias hinh_anh_voucher)
    $sql = "SELECT 
                v.*,
                v.hinh_anh AS hinh_anh_voucher,
                ks.ten            AS ten_khachsan,
                ks.dia_chi        AS dia_chi_khachsan,
                ks.hinh_anh       AS hinh_anh_khachsan,
                IFNULL(
                    (SELECT 1 
                     FROM nguoidung_voucher nv 
                     WHERE nv.voucher_id = v.id 
                       AND nv.nguoi_dung_id = ?), 
                    0
                ) AS da_luu
            FROM voucher v
            LEFT JOIN khach_san ks 
              ON v.khachsan_id = ks.id";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $vouchers = [];
    while ($row = $result->fetch_assoc()) {
        // Tính trạng thái hiển thị dựa trên ngày
        $now        = time();
        $start_time = strtotime($row['ngay_bat_dau']);
        $end_time   = strtotime($row['ngay_ket_thuc']);

        if ($now < $start_time) {
            $row['trang_thai_hien_thi'] = 'Sắp diễn ra';
        } elseif ($now > $end_time) {
            $row['trang_thai_hien_thi'] = 'Đã kết thúc';
        } else {
            $row['trang_thai_hien_thi'] = 'Đang diễn ra';
        }

        // Format giá trị giảm
        if ($row['loai_giam'] === 'phan_tram') {
            $row['gia_tri_giam_hien_thi'] = $row['gia_tri_giam'] . '%';
        } else {
            $row['gia_tri_giam_hien_thi'] = number_format($row['gia_tri_giam']) . 'đ';
        }

        $vouchers[] = $row;
    }

    echo json_encode([
        'status' => 'success',
        'data'   => $vouchers,
        'total'  => count($vouchers)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'status'  => 'error',
        'message' => 'Lỗi khi lấy danh sách voucher: ' . $e->getMessage()
    ]);
}
