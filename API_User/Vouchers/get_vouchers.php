<?php
require_once '../../connect.php'; // Đảm bảo đường dẫn này chính xác

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Lấy tham số từ request (nếu có)
// Nếu user_id không được cung cấp, da_luu sẽ luôn là 0, điều này chấp nhận được
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0; // Đặt là 0 nếu không có user_id, để bind_param không lỗi

try {
    // Câu truy vấn lấy voucher ĐANG DIỄN RA, kèm ảnh voucher (alias hinh_anh_voucher)
    // và thông tin khách sạn (nếu có)
    $sql = "SELECT 
                v.*,
                v.hinh_anh AS hinh_anh_voucher,
                ks.ten     AS ten_khachsan,
                ks.dia_chi AS dia_chi_khachsan,
                ks.hinh_anh AS hinh_anh_khachsan,
                IFNULL(
                    (SELECT 1 
                     FROM nguoidung_voucher nv 
                     WHERE nv.voucher_id = v.id 
                       AND nv.nguoi_dung_id = ?), 
                    0
                ) AS da_luu
            FROM voucher v
            LEFT JOIN khach_san ks 
                ON v.khachsan_id = ks.id
            WHERE 
                NOW() BETWEEN v.ngay_bat_dau AND v.ngay_ket_thuc
                -- AND v.trang_thai = 'dang_dien_ra' -- Bạn có thể thêm điều kiện này nếu cột trang_thai được cập nhật đáng tin cậy
                -- AND (v.so_luong_toi_da IS NULL OR v.so_luong_da_dung < v.so_luong_toi_da) -- Nếu muốn kiểm tra cả số lượng còn lại
            ORDER BY v.ngay_ket_thuc ASC"; // Ưu tiên hiển thị những mã sắp hết hạn trước

    $stmt = $conn->prepare($sql);
    // user_id được bind vào placeholder trong subquery của cột da_luu
    $stmt->bind_param("i", $user_id); 
    $stmt->execute();
    $result = $stmt->get_result();

    $vouchers = [];
    while ($row = $result->fetch_assoc()) {
        // Vì đã lọc ở SQL, tất cả voucher ở đây đều là 'Đang diễn ra'
        $row['trang_thai_hien_thi'] = 'Đang diễn ra';

        // Format giá trị giảm
        if ($row['loai_giam'] === 'phan_tram') {
            // Bỏ phần .00 nếu là số nguyên
            $gia_tri_giam = floatval($row['gia_tri_giam']);
            if ($gia_tri_giam == intval($gia_tri_giam)) {
                $row['gia_tri_giam_hien_thi'] = intval($gia_tri_giam) . '%';
            } else {
                $row['gia_tri_giam_hien_thi'] = rtrim(rtrim(number_format($gia_tri_giam, 2), '0'), '.') . '%';
            }
        } else {
            $row['gia_tri_giam_hien_thi'] = number_format($row['gia_tri_giam'], 0, ',', '.') . 'đ';
        }
        
        // Đảm bảo URL hình ảnh là đầy đủ nếu cần
        // Ví dụ: if ($row['hinh_anh_voucher'] && !filter_var($row['hinh_anh_voucher'], FILTER_VALIDATE_URL)) {
        // $row['hinh_anh_voucher'] = 'https://yourdomain.com/' . $row['hinh_anh_voucher'];
        // }
        // if ($row['hinh_anh_khachsan'] && !filter_var($row['hinh_anh_khachsan'], FILTER_VALIDATE_URL)) {
        // $row['hinh_anh_khachsan'] = 'https://yourdomain.com/' . $row['hinh_anh_khachsan'];
        // }

        $vouchers[] = $row;
    }

    echo json_encode([
        'status' => 'success',
        'data'   => $vouchers,
        'total'  => count($vouchers)
    ]);

} catch (Exception $e) {
    // Ghi log lỗi ở đây nếu cần thiết, ví dụ: error_log($e->getMessage());
    echo json_encode([
        'status'  => 'error',
        'message' => 'Lỗi khi lấy danh sách voucher: ' . $e->getMessage()
    ]);
} finally {
    if (isset($stmt)) {
        $stmt->close();
    }
    if (isset($conn)) {
        $conn->close();
    }
}
?>