<?php
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *"); // Cho phép truy cập từ mọi nguồn (thay đổi cho production)
header("Access-Control-Allow-Methods: GET");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Giả sử file connect.php nằm ở 3 cấp thư mục cha so với file API này
// ../../../ -> api_datn/ (nếu file này ở api_datn/API_User/Room/check_room_availability.php)
require_once '../../connect.php'; // Đảm bảo file này tồn tại và kết nối DB ($conn)

$response = ["status" => "error", "message" => "Đã xảy ra lỗi không xác định."];

try {
    if (!$conn) {
        throw new Exception("Lỗi kết nối cơ sở dữ liệu.");
    }

    $phong_id = isset($_GET['phong_id']) ? (int)$_GET['phong_id'] : 0;
    $ngay_nhan_phong_str = isset($_GET['ngay_nhan_phong']) ? $_GET['ngay_nhan_phong'] : '';
    $ngay_tra_phong_str = isset($_GET['ngay_tra_phong']) ? $_GET['ngay_tra_phong'] : '';

    if ($phong_id <= 0) {
        throw new Exception("ID phòng không hợp lệ.");
    }
    if (empty($ngay_nhan_phong_str) || empty($ngay_tra_phong_str)) {
        throw new Exception("Ngày nhận phòng và ngày trả phòng là bắt buộc.");
    }

    $date_regex = '/^\d{4}-\d{2}-\d{2}$/';
    if (!preg_match($date_regex, $ngay_nhan_phong_str) || !preg_match($date_regex, $ngay_tra_phong_str)) {
        throw new Exception("Định dạng ngày không hợp lệ. Yêu cầu YYYY-MM-DD.");
    }
    
    // Chuyển đổi sang đối tượng DateTime để so sánh an toàn
    try {
        $ngay_nhan_dt = new DateTime($ngay_nhan_phong_str);
        $ngay_tra_dt = new DateTime($ngay_tra_phong_str);
    } catch (Exception $dateEx) {
        throw new Exception("Giá trị ngày không hợp lệ.");
    }


    if ($ngay_tra_dt <= $ngay_nhan_dt) {
        throw new Exception("Ngày trả phòng phải sau ngày nhận phòng.");
    }

    // 1. Lấy tổng số lượng phòng hiện có của loại phòng này (total stock)
    $total_stock_of_type = 0;
    $stmt_total = $conn->prepare("SELECT so_luong FROM phong WHERE id = ?");
    if (!$stmt_total) {
        throw new Exception("Lỗi chuẩn bị lấy tổng số phòng: " . $conn->error);
    }
    $stmt_total->bind_param("i", $phong_id);
    if (!$stmt_total->execute()) {
        throw new Exception("Lỗi thực thi lấy tổng số phòng: " . $stmt_total->error);
    }
    $result_total = $stmt_total->get_result();
    if ($row_total = $result_total->fetch_assoc()) {
        $total_stock_of_type = (int)$row_total['so_luong'];
    } else {
        throw new Exception("Không tìm thấy phòng với ID cung cấp.");
    }
    $stmt_total->close();

    // 2. Đếm số lượng phòng đã được đặt và đang giữ chỗ trong khoảng thời gian yêu cầu
    $booked_quantity = 0;
    // Các trạng thái không còn giữ phòng (đã hoàn thành, đã hủy, thất bại)
    // Bạn cần tùy chỉnh danh sách này cho phù hợp với các trạng thái trong hệ thống của bạn
    $non_holding_statuses_str = "'cancelled', 'failed', 'rejected', 'completed'"; 
                                // Nếu trạng thái 'pending' cũng cần tính là giữ chỗ thì không thêm vào đây.
                                // 'pending' thường giữ chỗ, nên không nằm trong danh sách này.

    // Logic kiểm tra chồng chéo: ngày_nhan_moi < ngay_tra_cu VÀ ngay_tra_moi > ngay_nhan_cu
    // $ngay_nhan_phong_str và $ngay_tra_phong_str là ngày khách muốn đặt
    $sql_booked = "
        SELECT SUM(ctdp.so_luong_phong) AS booked_rooms
        FROM chi_tiet_dat_phong ctdp
        JOIN dat_phong dp ON ctdp.dat_phong_id = dp.id
        WHERE ctdp.phong_id = ?
        AND dp.trang_thai NOT IN ($non_holding_statuses_str) 
        AND dp.ngay_nhan_phong < ?  -- Ngày trả phòng khách muốn đặt
        AND dp.ngay_tra_phong > ?   -- Ngày nhận phòng khách muốn đặt
    ";
    
    $stmt_booked = $conn->prepare($sql_booked);
    if (!$stmt_booked) {
        throw new Exception("Lỗi chuẩn bị đếm số phòng đã đặt: " . $conn->error);
    }
    $stmt_booked->bind_param("iss", $phong_id, $ngay_tra_phong_str, $ngay_nhan_phong_str);
    
    if (!$stmt_booked->execute()) {
        throw new Exception("Lỗi thực thi đếm số phòng đã đặt: " . $stmt_booked->error);
    }
    $result_booked = $stmt_booked->get_result();
    if ($row_booked = $result_booked->fetch_assoc()) {
        $booked_quantity = (int)($row_booked['booked_rooms'] ?? 0);
    }
    $stmt_booked->close();

    // 3. Tính số phòng còn trống
    $available_rooms = max(0, $total_stock_of_type - $booked_quantity);

    $response = [
        "status" => "success",
        "data" => [
            "available_rooms" => $available_rooms,
            "total_stock_of_type" => $total_stock_of_type 
        ]
    ];

} catch (Exception $e) {
    $response["message"] = $e->getMessage();
    if (!headers_sent()) { // Chỉ đặt http_response_code nếu chưa có output nào được gửi
        http_response_code(400); // Bad Request cho lỗi logic hoặc input
    }
     error_log("API check_room_availability error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());

} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
    echo json_encode($response);
}
?>