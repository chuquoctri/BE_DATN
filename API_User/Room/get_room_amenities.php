<?php
// Kết nối cơ sở dữ liệu
require_once '../../connect.php'; // Bao gồm file kết nối

// Thiết lập header cho phép gọi API từ bên ngoài (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Lấy tham số từ request
$phong_id = isset($_GET['phong_id']) ? intval($_GET['phong_id']) : null;

// Kiểm tra tham số đầu vào
if (!is_numeric($phong_id)) {
    echo json_encode(["status" => "error", "message" => "Invalid phong_id"]);
    exit;
}

try {
    // Truy vấn để lấy thông tin tiện nghi theo phòng
    $sql = "
        SELECT tn.ten, tn.hinh_anh 
        FROM tien_nghi tn
        INNER JOIN tien_nghi_phong tnp ON tn.id = tnp.tien_nghi_id
        WHERE tnp.phong_id = ?
    ";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $phong_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $facilities = [];
    while ($row = $result->fetch_assoc()) {
        $facilities[] = $row;
    }

    // Trả về kết quả
    echo json_encode(["status" => "success", "data" => $facilities]);

} catch (Exception $e) {
    // Trả về lỗi nếu có ngoại lệ
    echo json_encode(["status" => "error", "message" => "Lỗi khi lấy dữ liệu: " . $e->getMessage()]);
}

// Đóng kết nối
$stmt->close();
$conn->close();
?>
