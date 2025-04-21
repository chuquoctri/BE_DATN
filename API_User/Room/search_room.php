<?php
// Kết nối database
require_once '../../connect.php';

// Thiết lập header JSON + CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Nhận dữ liệu từ request
$data = json_decode(file_get_contents("php://input"), true);

// Lấy thông tin tìm kiếm từ request
$khach_san_id = isset($data['khach_san_id']) ? intval($data['khach_san_id']) : null;
$min_gia = isset($data['min_gia']) ? floatval($data['min_gia']) : null;
$max_gia = isset($data['max_gia']) ? floatval($data['max_gia']) : null;
$suc_chua = isset($data['suc_chua']) ? intval($data['suc_chua']) : null;
$tien_nghi_ids = isset($data['tien_nghi_ids']) ? $data['tien_nghi_ids'] : [];
$checkin = isset($data['checkin']) ? $data['checkin'] : null;
$checkout = isset($data['checkout']) ? $data['checkout'] : null;

// Bắt đầu câu truy vấn
$sql = "SELECT DISTINCT p.* FROM phong p WHERE 1=1";
$params = [];
$types = "";

// Lọc theo khách sạn
if (!empty($khach_san_id)) {
    $sql .= " AND p.khach_san_id = ?";
    $params[] = $khach_san_id;
    $types .= "i";
}

// Lọc theo khoảng giá
if (!empty($min_gia)) {
    $sql .= " AND p.gia >= ?";
    $params[] = $min_gia;
    $types .= "d";
}

if (!empty($max_gia)) {
    $sql .= " AND p.gia <= ?";
    $params[] = $max_gia;
    $types .= "d";
}

// Lọc theo sức chứa
if (!empty($suc_chua)) {
    $sql .= " AND p.suc_chua >= ?";
    $params[] = $suc_chua;
    $types .= "i";
}

// Lọc theo tiện nghi
if (!empty($tien_nghi_ids)) {
    $placeholders = implode(",", array_fill(0, count($tien_nghi_ids), "?"));
    $sql .= " AND p.id IN (
        SELECT DISTINCT tp.phong_id 
        FROM tien_nghi_phong tp 
        WHERE tp.tien_nghi_id IN ($placeholders)
    )";
    foreach ($tien_nghi_ids as $id) {
        $params[] = intval($id);
        $types .= "i";
    }
}

// Kiểm tra phòng có bị đặt trước hay không (SỬA LỖI Ở ĐÂY)
if (!empty($checkin) && !empty($checkout)) {
    $sql .= " AND p.id NOT IN (
        SELECT ctdp.phong_id 
        FROM chi_tiet_dat_phong ctdp
        JOIN dat_phong dp ON dp.id = ctdp.dat_phong_id
        WHERE (dp.ngay_nhan_phong <= ? AND dp.ngay_tra_phong >= ?)
    )";
    $params[] = $checkout;
    $params[] = $checkin;
    $types .= "ss";
}

// Chuẩn bị truy vấn
$stmt = $conn->prepare($sql);

// Nếu có tham số, bind vào câu lệnh
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

// Lấy dữ liệu
$rooms = [];
while ($row = $result->fetch_assoc()) {
    $rooms[] = $row;
}

// Trả về kết quả JSON
echo json_encode(["status" => "success", "data" => $rooms]);

// Đóng kết nối
$stmt->close();
$conn->close();
?>
