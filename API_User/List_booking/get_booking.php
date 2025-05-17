<?php
require_once '../../connect.php'; // Đảm bảo file này tồn tại và kết nối DB ($conn)

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$nguoi_dung_id = isset($_GET['nguoi_dung_id']) ? intval($_GET['nguoi_dung_id']) : 0;

if ($nguoi_dung_id === 0) {
    echo json_encode(["status" => "error", "message" => "Thiếu ID người dùng."]);
    exit;
}

// Truy vấn danh sách đặt phòng chính của người dùng
// SỬA ĐỔI: Thêm điều kiện AND dp.trang_thai_thanh_toan != 'da_thanh_toan'
$sql_dat_phong = "SELECT 
                        dp.id, 
                        dp.ngay_nhan_phong, 
                        dp.ngay_tra_phong, 
                        dp.tong_tien, 
                        dp.trang_thai, 
                        dp.trang_thai_thanh_toan,
                        dp.ngay_tao, 
                        dp.ngay_cap_nhat,
                        ks.ten AS ten_khach_san,
                        p_main.ten AS ten_phong_chinh,
                        p_main.khach_san_id AS khach_san_id
                    FROM dat_phong dp
                    JOIN phong p_main ON dp.phong_id = p_main.id
                    JOIN khach_san ks ON p_main.khach_san_id = ks.id
                    WHERE dp.nguoi_dung_id = ? AND dp.trang_thai_thanh_toan != 'da_thanh_toan' 
                    ORDER BY dp.ngay_tao DESC";

$stmt_dat_phong = $conn->prepare($sql_dat_phong);
if (!$stmt_dat_phong) {
    echo json_encode(["status" => "error", "message" => "Lỗi chuẩn bị câu lệnh đặt phòng: " . $conn->error]);
    exit;
}
$stmt_dat_phong->bind_param("i", $nguoi_dung_id);
$stmt_dat_phong->execute();
$result_dat_phong = $stmt_dat_phong->get_result();

$danh_sach_dat_phong = [];

while ($row_dp = $result_dat_phong->fetch_assoc()) {
    $dat_phong_id_hien_tai = $row_dp['id'];
    $chi_tiet_don_hang = $row_dp; 

    // 1. Lấy chi tiết các phòng đã đặt từ bảng chi_tiet_dat_phong
    $sql_chi_tiet_phong = "SELECT 
                                ctdp.so_luong_phong, 
                                ctdp.gia AS gia_phong_luc_dat,
                                p_detail.ten AS ten_phong_chi_tiet,
                                p_detail.id AS phong_id_chi_tiet
                            FROM chi_tiet_dat_phong ctdp
                            JOIN phong p_detail ON ctdp.phong_id = p_detail.id
                            WHERE ctdp.dat_phong_id = ?";
    $stmt_chi_tiet_phong = $conn->prepare($sql_chi_tiet_phong);
    if (!$stmt_chi_tiet_phong) {
        error_log("Lỗi chuẩn bị câu lệnh chi tiết phòng: " . $conn->error);
        $chi_tiet_don_hang['chi_tiet_cac_phong'] = [];
    } else {
        $stmt_chi_tiet_phong->bind_param("i", $dat_phong_id_hien_tai);
        $stmt_chi_tiet_phong->execute();
        $result_chi_tiet_phong = $stmt_chi_tiet_phong->get_result();
        $cac_phong_da_dat = [];
        while ($row_ctp = $result_chi_tiet_phong->fetch_assoc()) {
            $cac_phong_da_dat[] = $row_ctp;
        }
        $chi_tiet_don_hang['chi_tiet_cac_phong'] = $cac_phong_da_dat;
        $stmt_chi_tiet_phong->close();
    }

    // 2. Lấy chi tiết các dịch vụ đã đặt
    $sql_dich_vu = "SELECT 
                        dpdv.so_luong, 
                        dpdv.gia AS gia_dich_vu_luc_dat,
                        dv.ten AS ten_dich_vu
                    FROM dat_phong_dich_vu dpdv
                    JOIN dich_vu_khach_san dvks ON dpdv.dich_vu_khach_san_id = dvks.id
                    JOIN dich_vu dv ON dvks.dich_vu_id = dv.id
                    WHERE dpdv.dat_phong_id = ?";
    $stmt_dich_vu = $conn->prepare($sql_dich_vu);
    if (!$stmt_dich_vu) {
        error_log("Lỗi chuẩn bị câu lệnh dịch vụ: " . $conn->error);
        $chi_tiet_don_hang['chi_tiet_cac_dich_vu'] = [];
    } else {
        $stmt_dich_vu->bind_param("i", $dat_phong_id_hien_tai);
        $stmt_dich_vu->execute();
        $result_dich_vu = $stmt_dich_vu->get_result();
        $cac_dich_vu_da_dat = [];
        while ($row_dv = $result_dich_vu->fetch_assoc()) {
            $cac_dich_vu_da_dat[] = $row_dv;
        }
        $chi_tiet_don_hang['chi_tiet_cac_dich_vu'] = $cac_dich_vu_da_dat;
        $stmt_dich_vu->close();
    }
    
    $danh_sach_dat_phong[] = $chi_tiet_don_hang;
}

$stmt_dat_phong->close();
$conn->close();

echo json_encode([
    "status" => "success",
    "data" => $danh_sach_dat_phong
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

?>