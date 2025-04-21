<?php
// Kết nối database
require_once '../../connect.php';

// Thiết lập header cho phép gọi API từ bên ngoài (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Lấy dữ liệu từ request
$data = json_decode(file_get_contents("php://input"), true); // Nhận JSON

$nguoi_dung_id = isset($data['nguoi_dung_id']) ? intval($data['nguoi_dung_id']) : (isset($_GET['nguoi_dung_id']) ? intval($_GET['nguoi_dung_id']) : null);
$khach_san_id = isset($data['khach_san_id']) ? intval($data['khach_san_id']) : (isset($_GET['khach_san_id']) ? intval($_GET['khach_san_id']) : null);

// Kiểm tra tham số đầu vào
if (!is_numeric($nguoi_dung_id) || !is_numeric($khach_san_id)) {
    echo json_encode(["status" => "error", "message" => "Invalid nguoi_dung_id or khach_san_id"]);
    exit;
}

// Kiểm tra xem khách sạn có được người dùng yêu thích hay không
$sql_check = "SELECT * FROM yeu_thich_khach_san WHERE nguoi_dung_id = ? AND khach_san_id = ?";
$stmt_check = $conn->prepare($sql_check);
$stmt_check->bind_param("ii", $nguoi_dung_id, $khach_san_id);
$stmt_check->execute();
$result_check = $stmt_check->get_result();

if ($result_check->num_rows > 0) {
    // Nếu đã yêu thích
    echo json_encode(["status" => "favorite"]);
} else {
    // Nếu chưa yêu thích
    echo json_encode(["status" => "not_favorite"]);
}

// Đóng kết nối
$stmt_check->close();
$conn->close();
?>