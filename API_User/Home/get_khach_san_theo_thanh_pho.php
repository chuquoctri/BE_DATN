<?php
// Nạp file kết nối cơ sở dữ liệu
require_once '../../connect.php';

// Thiết lập header cho phép gọi API từ bên ngoài (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Nhận tham số city_id từ URL
$city_id = isset($_GET['city_id']) ? intval($_GET['city_id']) : 0;

if ($city_id > 0) {
    // Câu lệnh SQL join bảng khach_san với thanh_pho để lấy tên thành phố
    $sql = "
        SELECT 
            ks.id,
            ks.ten,
            ks.thanh_pho_id,
            tp.ten AS ten_thanh_pho,
            ks.loai_cho_nghi_id,
            ks.dia_chi,
            ks.so_dien_thoai,
            ks.email,
            ks.kinh_do,
            ks.vi_do,
            ks.so_sao,
            ks.mo_ta,
            ks.hinh_anh,
            ks.ngay_tao,
            ks.ngay_cap_nhat
        FROM khach_san ks
        LEFT JOIN thanh_pho tp ON ks.thanh_pho_id = tp.id
        WHERE ks.thanh_pho_id = $city_id
    ";

    // Thực thi query
    $result = $conn->query($sql);

    // Kiểm tra có dữ liệu trả về không
    if ($result->num_rows > 0) {
        $hotels = array();
        while ($row = $result->fetch_assoc()) {
            $hotels[] = $row;
        }
        // Trả về dữ liệu dạng JSON
        echo json_encode(array("status" => "success", "data" => $hotels), JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(array("status" => "error", "message" => "Không có dữ liệu khách sạn."));
    }
} else {
    echo json_encode(array("status" => "error", "message" => "Tham số city_id không hợp lệ."));
}

// Đóng kết nối
$conn->close();
?>