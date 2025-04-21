<?php
// Nạp file kết nối cơ sở dữ liệu
require_once '../../connect.php';

// Thiết lập header cho phép gọi API từ bên ngoài (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Lấy id của khách sạn từ request
$hotelId = isset($_GET['hotel_id']) ? $_GET['hotel_id'] : die(json_encode(array("status" => "error", "message" => "Missing hotel_id parameter.")));

// Câu lệnh SQL để lấy thông tin dịch vụ của khách sạn
$sql = "
    SELECT 
        dv.id,
        dv.ten,
        dv.hinh_anh,
        dvks.gia
    FROM dich_vu_khach_san dvks
    JOIN dich_vu dv ON dvks.dich_vu_id = dv.id
    WHERE dvks.khach_san_id = ?
";

// Chuẩn bị câu lệnh
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $hotelId);
$stmt->execute();
$result = $stmt->get_result();

// Kiểm tra có dữ liệu trả về không
if ($result->num_rows > 0) {
    $services = array();
    while ($row = $result->fetch_assoc()) {
        $services[] = $row;
    }
    // Trả về dữ liệu dạng JSON
    echo json_encode(array("status" => "success", "data" => $services), JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(array("status" => "error", "message" => "Không tìm thấy dịch vụ cho khách sạn này."));
}

// Đóng kết nối
$conn->close();
?>