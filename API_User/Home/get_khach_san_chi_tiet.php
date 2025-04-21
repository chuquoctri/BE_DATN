<?php
// Nạp file kết nối cơ sở dữ liệu
require_once '../../connect.php';

// Thiết lập header cho phép gọi API từ bên ngoài (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Lấy id của khách sạn từ request
$hotelId = isset($_GET['hotel_id']) ? $_GET['hotel_id'] : die(json_encode(array("status" => "error", "message" => "Missing hotel_id parameter.")));

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
    WHERE ks.id = ?
";

// Chuẩn bị câu lệnh
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $hotelId);
$stmt->execute();
$result = $stmt->get_result();

// Kiểm tra có dữ liệu trả về không
if ($result->num_rows > 0) {
    $hotel = $result->fetch_assoc();
    // Trả về dữ liệu dạng JSON
    echo json_encode(array("status" => "success", "data" => $hotel), JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(array("status" => "error", "message" => "Không tìm thấy khách sạn."));
}

// Đóng kết nối
$conn->close();
?>