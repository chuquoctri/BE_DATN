<?php
require_once '../../connect.php'; // Điều chỉnh đường dẫn cho đúng

header('Content-Type: application/json; charset=utf-8');
header("Access-Control-Allow-Origin: *");
// Cho phép cả POST và DELETE, tùy thuộc vào phương thức bạn quyết định dùng ở client
// POST thường dễ dùng hơn với fetch API khi gửi JSON body
header("Access-Control-Allow-Methods: POST, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

// Xử lý pre-flight request cho CORS
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Sử dụng POST để nhận dữ liệu JSON từ client
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['success' => false, 'message' => 'Phương thức không được hỗ trợ. Chỉ chấp nhận POST.']);
    exit;
}

// Lấy dữ liệu JSON từ request body
$input = json_decode(file_get_contents('php://input'), true);

$userId = $input['nguoi_dung_id'] ?? null;
$hotelId = $input['khach_san_id'] ?? null;

// Validate input
if (empty($userId) || !filter_var($userId, FILTER_VALIDATE_INT) || (int)$userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Thiếu hoặc ID người dùng không hợp lệ.']);
    exit;
}

if (empty($hotelId) || !filter_var($hotelId, FILTER_VALIDATE_INT) || (int)$hotelId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Thiếu hoặc ID khách sạn không hợp lệ.']);
    exit;
}

try {
    // Câu lệnh SQL để xóa mục yêu thích dựa trên nguoi_dung_id và khach_san_id
    $sql = "DELETE FROM yeu_thich_khach_san WHERE nguoi_dung_id = ? AND khach_san_id = ?";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log("SQL Prepare Error (remove_favorite): " . $conn->error);
        throw new Exception("Lỗi server khi chuẩn bị câu lệnh.");
    }

    $stmt->bind_param("ii", $userId, $hotelId);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            // Xóa thành công
            echo json_encode(['success' => true, 'message' => 'Đã xóa khỏi danh sách yêu thích.']);
        } else {
            // Không có dòng nào bị ảnh hưởng (có thể do mục đó không tồn tại hoặc đã bị xóa)
            // Bạn có thể trả về lỗi 404 hoặc vẫn là success nhưng với thông báo khác
            http_response_code(404); // Not Found
            echo json_encode(['success' => false, 'message' => 'Không tìm thấy mục yêu thích này để xóa.']);
        }
    } else {
        error_log("SQL Execute Error (remove_favorite): " . $stmt->error);
        throw new Exception("Lỗi server khi thực thi xóa.");
    }

    $stmt->close();

} catch (Exception $e) {
    error_log("Exception in remove_favorite.php: " . $e->getMessage() . " - UserID: $userId, HotelID: $hotelId");
    http_response_code(500); // Internal Server Error
    // Trong môi trường production, không nên hiển thị $e->getMessage() trực tiếp cho client
    echo json_encode(['success' => false, 'message' => 'Đã có lỗi xảy ra ở phía máy chủ. Vui lòng thử lại sau.']);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>