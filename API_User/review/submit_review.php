<?php
require_once '../../connect.php'; // Điều chỉnh đường dẫn nếu cần
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

$data = json_decode(file_get_contents("php://input"), true);

$userId = $data['userId'] ?? null;
$khach_san_id = $data['khachSanId'] ?? null; // ID khách sạn được đánh giá
$so_sao = $data['soSao'] ?? null;
$binh_luan = $data['binhLuan'] ?? ''; // binh_luan có thể là chuỗi rỗng
$quyen_danh_gia_id = $data['quyenDanhGiaId'] ?? null; // ID của bản ghi trong bảng quyen_danh_gia

// Kiểm tra dữ liệu đầu vào
if (empty($userId) || !filter_var($userId, FILTER_VALIDATE_INT) ||
    empty($khach_san_id) || !filter_var($khach_san_id, FILTER_VALIDATE_INT) ||
    !isset($so_sao) || !filter_var($so_sao, FILTER_VALIDATE_INT, ["options" => ["min_range" => 1, "max_range" => 5]]) ||
    empty($quyen_danh_gia_id) || !filter_var($quyen_danh_gia_id, FILTER_VALIDATE_INT)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Dữ liệu không hợp lệ. Vui lòng kiểm tra các trường userId, khachSanId, soSao (1-5), và quyenDanhGiaId.']);
    exit;
}

$conn->begin_transaction();
try {
    // 1. Kiểm tra quyền đánh giá có hợp lệ, thuộc người dùng, và chưa được sử dụng không
    $sqlCheckPermission = "SELECT id, ma_dat_phong_id FROM quyen_danh_gia
                           WHERE id = ? AND nguoi_dung_id = ? AND khach_san_id = ? AND da_danh_gia = FALSE FOR UPDATE";
                           // FOR UPDATE để lock bản ghi này, tránh race condition
    $stmtCheck = $conn->prepare($sqlCheckPermission);
    if (!$stmtCheck) throw new Exception("Lỗi chuẩn bị (kiểm tra quyền): " . $conn->error);

    $stmtCheck->bind_param("iii", $quyen_danh_gia_id, $userId, $khach_san_id);
    $stmtCheck->execute();
    $resultCheck = $stmtCheck->get_result();
    $permission_data = $resultCheck->fetch_assoc();
    $stmtCheck->close();

    if (!$permission_data) {
        throw new Exception("Bạn không có quyền đánh giá cho lượt này, khách sạn này hoặc đã đánh giá rồi.");
    }
    // $ma_dat_phong_id_for_review = $permission_data['ma_dat_phong_id']; // Có thể dùng để log hoặc liên kết nếu cần

    // 2. Thêm đánh giá vào bảng danh_gia_khach_san
    $sqlInsertReview = "INSERT INTO danh_gia_khach_san (khach_san_id, nguoi_dung_id, so_sao, binh_luan)
                        VALUES (?, ?, ?, ?)";
    $stmtInsert = $conn->prepare($sqlInsertReview);
    if (!$stmtInsert) throw new Exception("Lỗi chuẩn bị (lưu đánh giá): " . $conn->error);

    $stmtInsert->bind_param("iiis", $khach_san_id, $userId, $so_sao, $binh_luan);
    if (!$stmtInsert->execute()) {
        throw new Exception("Lỗi khi lưu đánh giá: " . $stmtInsert->error);
    }
    $new_review_id = $conn->insert_id;
    $stmtInsert->close();

    // 3. Cập nhật trạng thái da_danh_gia = TRUE trong bảng quyen_danh_gia
    $sqlUpdatePermission = "UPDATE quyen_danh_gia SET da_danh_gia = TRUE WHERE id = ?";
    $stmtUpdate = $conn->prepare($sqlUpdatePermission);
    if (!$stmtUpdate) throw new Exception("Lỗi chuẩn bị (cập nhật quyền): " . $conn->error);

    $stmtUpdate->bind_param("i", $quyen_danh_gia_id);
    if (!$stmtUpdate->execute()) {
        // Nếu không cập nhật được quyền, có thể xem xét rollback việc insert đánh giá
        throw new Exception("Lỗi cập nhật quyền đánh giá: " . $stmtUpdate->error);
    }
    $stmtUpdate->close();

    $conn->commit();
    echo json_encode(['status' => 'success', 'message' => 'Cảm ơn bạn đã gửi đánh giá!', 'review_id' => $new_review_id]);

} catch (Exception $e) {
    $conn->rollback();
    // Không nên trả về $e->getMessage() trực tiếp cho client trong môi trường production vì lý do bảo mật
    error_log("Lỗi submit_review.php: " . $e->getMessage() . " Input: " . json_encode($data)); // Ghi log chi tiết
    http_response_code(500); // Internal Server Error
    echo json_encode(['status' => 'error', 'message' => 'Đã xảy ra lỗi trong quá trình xử lý. Vui lòng thử lại.']);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}
?>