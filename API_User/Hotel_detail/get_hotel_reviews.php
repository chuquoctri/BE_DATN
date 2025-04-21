<?php
// Nạp file kết nối cơ sở dữ liệu
require_once '../../connect.php';

// Thiết lập header cho phép gọi API từ bên ngoài (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Lấy id của khách sạn từ request
$hotelId = isset($_GET['hotel_id']) ? $_GET['hotel_id'] : die(json_encode(array("status" => "error", "message" => "Missing hotel_id parameter.")));

// Câu lệnh SQL để lấy thông tin đánh giá khách sạn và tên người dùng
$sql = "
    SELECT 
        dg.id,
        dg.khach_san_id,
        dg.nguoi_dung_id,
        nd.ho_ten AS ten_nguoi_dung,
        dg.so_sao,
        dg.binh_luan,
        dg.ngay_danh_gia
    FROM danh_gia_khach_san dg
    JOIN nguoi_dung nd ON dg.nguoi_dung_id = nd.id
    WHERE dg.khach_san_id = ?
";

// Chuẩn bị câu lệnh
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $hotelId);
$stmt->execute();
$result = $stmt->get_result();

// Kiểm tra có dữ liệu trả về không
if ($result->num_rows > 0) {
    $reviews = array();
    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }
    // Trả về dữ liệu dạng JSON
    echo json_encode(array("status" => "success", "data" => $reviews), JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(array("status" => "error", "message" => "Không tìm thấy đánh giá cho khách sạn này."));
}

// Đóng kết nối
$conn->close();
?>