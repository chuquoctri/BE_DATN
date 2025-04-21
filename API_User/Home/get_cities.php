<?php
// Nạp file kết nối cơ sở dữ liệu
require_once '../../connect.php';

// Thiết lập header cho phép gọi API từ bên ngoài (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Câu lệnh SQL lấy toàn bộ dữ liệu từ bảng thanh_pho
$sql = "SELECT id, ten, hinh_anh, danh_muc_id FROM thanh_pho";

// Thực thi query
$result = $conn->query($sql);

// Kiểm tra có dữ liệu trả về không
if ($result->num_rows > 0) {
    $cities = array();
    while ($row = $result->fetch_assoc()) {
        // Đưa từng dòng dữ liệu vào mảng kết quả
        $cities[] = $row;
    }
    // Trả về dữ liệu dạng JSON
    echo json_encode(array("status" => "success", "data" => $cities), JSON_UNESCAPED_UNICODE);
} else {
    // Không có dữ liệu
    echo json_encode(array("status" => "error", "message" => "Không có dữ liệu thành phố."));
}

// Đóng kết nối
$conn->close();
?>
