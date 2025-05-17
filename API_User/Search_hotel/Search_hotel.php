<?php
require_once '../../connect.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);

// Lấy các tham số
$min_gia = isset($data['min_gia']) ? floatval($data['min_gia']) : null;
$max_gia = isset($data['max_gia']) ? floatval($data['max_gia']) : null;
$suc_chua = isset($data['suc_chua']) ? intval($data['suc_chua']) : null;
$tien_nghi_ids = isset($data['tien_nghi_ids']) ? $data['tien_nghi_ids'] : [];
$checkin = isset($data['checkin']) ? $data['checkin'] : null;
$checkout = isset($data['checkout']) ? $data['checkout'] : null;
$so_sao = isset($data['so_sao']) ? intval($data['so_sao']) : null;
$dia_chi = isset($data['dia_chi']) ? trim($data['dia_chi']) : null;
$ten_khach_san = isset($data['ten_khach_san']) ? trim($data['ten_khach_san']) : null;

// Query cơ bản
$sql = "SELECT DISTINCT ks.* FROM khach_san ks";

// Danh sách join và điều kiện
$joins = [];
$conditions = [];

// Thêm join với bảng phòng nếu có điều kiện liên quan đến phòng
if (!empty($min_gia) || !empty($max_gia) || !empty($suc_chua) || !empty($tien_nghi_ids) || (!empty($checkin) && !empty($checkout))) {
    $joins[] = "JOIN phong p ON ks.id = p.khach_san_id";
}

// Điều kiện giá phòng
if (!empty($min_gia)) {
    $conditions[] = "p.gia >= $min_gia";
}

if (!empty($max_gia)) {
    $conditions[] = "p.gia <= $max_gia";
}

// Điều kiện sức chứa
if (!empty($suc_chua)) {
    $conditions[] = "p.suc_chua >= $suc_chua";
}

// Điều kiện số sao
if (!empty($so_sao)) {
    $conditions[] = "ks.so_sao = $so_sao";
}

// Điều kiện địa chỉ
if (!empty($dia_chi)) {
    $search_dia_chi = $conn->real_escape_string($dia_chi);
    $conditions[] = "(ks.dia_chi LIKE '%$search_dia_chi%' OR LOWER(ks.dia_chi) LIKE '%".strtolower($search_dia_chi)."%')";
}

// Điều kiện tên khách sạn
if (!empty($ten_khach_san)) {
    $search_ten = $conn->real_escape_string($ten_khach_san);
    $conditions[] = "(ks.ten LIKE '%$search_ten%' OR LOWER(ks.ten) LIKE '%".strtolower($search_ten)."%')";
}

// Điều kiện tiện nghi
if (!empty($tien_nghi_ids)) {
    $placeholders = implode(",", array_map('intval', $tien_nghi_ids));
    $joins[] = "JOIN tien_nghi_phong tnp ON p.id = tnp.phong_id";
    $conditions[] = "tnp.tien_nghi_id IN ($placeholders)";
    // Đảm bảo phòng có tất cả tiện nghi được chọn
    $conditions[] = "(SELECT COUNT(DISTINCT tien_nghi_id) FROM tien_nghi_phong WHERE phong_id = p.id AND tien_nghi_id IN ($placeholders)) = ".count($tien_nghi_ids);
}

// Điều kiện ngày checkin/checkout
if (!empty($checkin) && !empty($checkout)) {
    $checkin_escaped = $conn->real_escape_string($checkin);
    $checkout_escaped = $conn->real_escape_string($checkout);
    $conditions[] = "p.id NOT IN (
        SELECT ctdp.phong_id
        FROM chi_tiet_dat_phong ctdp
        JOIN dat_phong dp ON dp.id = ctdp.dat_phong_id
        WHERE dp.trang_thai = 'confirmed'
        AND NOT (
            dp.ngay_tra_phong <= '$checkin_escaped' OR dp.ngay_nhan_phong >= '$checkout_escaped'
        )
    )";
}

// Xây dựng query hoàn chỉnh
if (!empty($joins)) {
    $sql .= " " . implode(" ", $joins);
}

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

// Thực thi query
$result = $conn->query($sql);

if (!$result) {
    echo json_encode([
        "status" => "error",
        "message" => "Query error: " . $conn->error,
        "query" => $sql
    ]);
    exit;
}

$khach_sans = [];
while ($row = $result->fetch_assoc()) {
    $khach_sans[] = $row;
}

// Trả về kết quả
echo json_encode([
    "status" => "success",
    "data" => $khach_sans,
    "query" => $sql // Có thể bỏ trong môi trường production
]);

$conn->close();
?>