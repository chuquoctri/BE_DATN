<?php
require_once '../../connect.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Nhận dữ liệu từ POST request
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['booking_ids']) || empty($data['booking_ids'])) {
    echo json_encode(["status" => "error", "message" => "Thiếu ID đặt phòng cần xóa."]);
    exit;
}

$bookingIds = $data['booking_ids'];
$placeholders = implode(',', array_fill(0, count($bookingIds), '?'));
$types = str_repeat('i', count($bookingIds));

// Kiểm tra xem các booking có phải của người dùng này không (nếu cần bảo mật)
// Ở đây giả sử đã kiểm tra ở frontend

// Xóa các booking
$sql = "DELETE FROM dat_phong WHERE id IN ($placeholders)";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$bookingIds);
$success = $stmt->execute();

if ($success) {
    echo json_encode([
        "status" => "success",
        "message" => "Xóa đặt phòng thành công.",
        "deleted_count" => $stmt->affected_rows
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Lỗi khi xóa đặt phòng: " . $conn->error
    ]);
}

$conn->close();
?>