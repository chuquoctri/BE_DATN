<?php
// Kết nối database
require_once '../../connect.php';

// Thiết lập header cho phép gọi API từ bên ngoài (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Nhận phong_id từ request (GET hoặc POST)
$data = json_decode(file_get_contents("php://input"), true);
$phong_id = isset($data['phong_id']) ? intval($data['phong_id']) : (isset($_GET['phong_id']) ? intval($_GET['phong_id']) : null);

// Kiểm tra tham số đầu vào
if (!$phong_id) {
    echo json_encode(["status" => "error", "message" => "Invalid phong_id"]);
    exit;
}

// Chuẩn bị câu lệnh SQL để lấy hình ảnh của phòng theo phong_id
$sql = "SELECT id, phong_id, hinh_anh FROM hinh_anh_phong WHERE phong_id = ?";
$stmt = $conn->prepare($sql);

if ($stmt === false) {
    echo json_encode(["status" => "error", "message" => "Database query error"]);
    exit;
}

$stmt->bind_param("i", $phong_id);
$stmt->execute();
$result = $stmt->get_result();

$room_images = [];
while ($row = $result->fetch_assoc()) {
    $room_images[] = $row;
}

// Kiểm tra nếu không có hình ảnh nào
if (empty($room_images)) {
    echo json_encode(["status" => "success", "message" => "No images found", "data" => []]);
} else {
    echo json_encode(["status" => "success", "data" => $room_images]);
}

// Đóng kết nối
$stmt->close();
$conn->close();
?>
