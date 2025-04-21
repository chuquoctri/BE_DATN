<?php
// Kết nối database
require_once '../../connect.php';

// Thiết lập header cho phép gọi API từ bên ngoài (CORS)
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Lấy dữ liệu từ request
$data = json_decode(file_get_contents("php://input"), true); // Nhận JSON

// In ra dữ liệu nhận được để debug
error_log(print_r($data, true));

$nguoi_dung_id = isset($data['nguoi_dung_id']) ? intval($data['nguoi_dung_id']) : (isset($_POST['nguoi_dung_id']) ? intval($_POST['nguoi_dung_id']) : null);
$khach_san_id = isset($data['khach_san_id']) ? intval($data['khach_san_id']) : (isset($_POST['khach_san_id']) ? intval($_POST['khach_san_id']) : null);

// In ra giá trị của nguoi_dung_id và khach_san_id để debug
error_log("nguoi_dung_id: " . var_export($nguoi_dung_id, true));
error_log("khach_san_id: " . var_export($khach_san_id, true));

// Kiểm tra tham số đầu vào
if (!is_numeric($nguoi_dung_id) || !is_numeric($khach_san_id)) {
    echo json_encode(["status" => "error", "message" => "Invalid nguoi_dung_id or khach_san_id"]);
    exit;
}

// Kiểm tra nếu nguoi_dung_id tồn tại trong bảng nguoi_dung
$sql_check_user = "SELECT id FROM nguoi_dung WHERE id = ?";
$stmt_check_user = $conn->prepare($sql_check_user);
$stmt_check_user->bind_param("i", $nguoi_dung_id);
$stmt_check_user->execute();
$stmt_check_user->store_result();

if ($stmt_check_user->num_rows > 0) {
    // Kiểm tra xem khách sạn đã được yêu thích chưa
    $sql_check = "SELECT * FROM yeu_thich_khach_san WHERE nguoi_dung_id = ? AND khach_san_id = ?";
    $stmt_check = $conn->prepare($sql_check);
    $stmt_check->bind_param("ii", $nguoi_dung_id, $khach_san_id);
    $stmt_check->execute();
    $result_check = $stmt_check->get_result();

    if ($result_check->num_rows > 0) {
        // Nếu đã yêu thích, xóa khỏi danh sách yêu thích
        $sql_delete = "DELETE FROM yeu_thich_khach_san WHERE nguoi_dung_id = ? AND khach_san_id = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("ii", $nguoi_dung_id, $khach_san_id);
        if ($stmt_delete->execute()) {
            echo json_encode(["status" => "success", "message" => "Removed from favorites"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Error removing from favorites"]);
        }
        $stmt_delete->close();
    } else {
        // Nếu chưa yêu thích, thêm vào danh sách
        $sql_insert = "INSERT INTO yeu_thich_khach_san (nguoi_dung_id, khach_san_id) VALUES (?, ?)";
        $stmt_insert = $conn->prepare($sql_insert);
        $stmt_insert->bind_param("ii", $nguoi_dung_id, $khach_san_id);
        if ($stmt_insert->execute()) {
            echo json_encode(["status" => "success", "message" => "Added to favorites"]);
        } else {
            echo json_encode(["status" => "error", "message" => "Error adding to favorites"]);
        }
        $stmt_insert->close();
    }

    // Đóng kết nối
    $stmt_check->close();
} else {
    echo json_encode(["status" => "error", "message" => "Invalid user ID."]);
}

$stmt_check_user->close();
$conn->close();
?>