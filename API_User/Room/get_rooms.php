<?php
// Kết nối database
require_once '../../connect.php';

// Thiết lập header cho phép gọi API từ bên ngoài (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Lấy khach_san_id từ request (GET hoặc POST)
$data = json_decode(file_get_contents("php://input"), true);
$khach_san_id = isset($data['khach_san_id']) ? intval($data['khach_san_id']) : (isset($_GET['khach_san_id']) ? intval($_GET['khach_san_id']) : null);

// Kiểm tra tham số đầu vào
if (!is_numeric($khach_san_id)) {
    echo json_encode(["status" => "error", "message" => "Invalid khach_san_id"]);
    exit;
}

// Truy vấn danh sách phòng theo khach_san_id
$sql = "SELECT * FROM phong WHERE khach_san_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $khach_san_id);
$stmt->execute();
$result = $stmt->get_result();

$rooms = [];
while ($row = $result->fetch_assoc()) {
    $rooms[] = $row;
}

// Trả kết quả dưới dạng JSON
echo json_encode(["status" => "success", "data" => $rooms]);

// Đóng kết nối
$stmt->close();
$conn->close();
?>