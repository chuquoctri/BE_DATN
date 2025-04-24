<?php
require_once '../../connect.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

$data = json_decode(file_get_contents("php://input"), true);

// Lấy thông tin tìm kiếm từ request
$ten_khach_san = isset($data['ten_khach_san']) ? "%" . $data['ten_khach_san'] . "%" : null;
$thanh_pho_id = isset($data['thanh_pho_id']) ? intval($data['thanh_pho_id']) : null;
$so_sao = isset($data['so_sao']) ? intval($data['so_sao']) : null;
$min_gia = isset($data['min_gia']) ? floatval($data['min_gia']) : null;
$max_gia = isset($data['max_gia']) ? floatval($data['max_gia']) : null;
$suc_chua = isset($data['suc_chua']) ? intval($data['suc_chua']) : null;
$checkin = isset($data['checkin']) ? $data['checkin'] : null;
$checkout = isset($data['checkout']) ? $data['checkout'] : null;
$dich_vu_ids = isset($data['dich_vu_ids']) ? $data['dich_vu_ids'] : [];

$sql = "SELECT DISTINCT ks.* 
        FROM khach_san ks
        JOIN phong p ON ks.id = p.khach_san_id
        WHERE 1=1";

$params = [];
$types = "";

// Lọc theo tên khách sạn
if (!empty($ten_khach_san)) {
    $sql .= " AND ks.ten LIKE ?";
    $params[] = $ten_khach_san;
    $types .= "s";
}

// Lọc theo thành phố
if (!empty($thanh_pho_id)) {
    $sql .= " AND ks.thanh_pho_id = ?";
    $params[] = $thanh_pho_id;
    $types .= "i";
}

// Lọc theo số sao
if (!empty($so_sao)) {
    $sql .= " AND ks.so_sao = ?";
    $params[] = $so_sao;
    $types .= "i";
}

// Lọc theo khoảng giá phòng
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

// Kiểm tra phòng có trống trong khoảng ngày check-in/out
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

// Lọc theo dịch vụ khách sạn
if (!empty($dich_vu_ids)) {
    $placeholders = implode(",", array_fill(0, count($dich_vu_ids), "?"));
    $sql .= " AND ks.id IN (
        SELECT DISTINCT dk.khach_san_id 
        FROM dichvu_khachsan dk 
        WHERE dk.dich_vu_id IN ($placeholders)
    )";
    foreach ($dich_vu_ids as $id) {
        $params[] = intval($id);
        $types .= "i";
    }
}

// Chuẩn bị câu truy vấn
$stmt = $conn->prepare($sql);

// Bind parameters nếu có
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();

$khach_sans = [];
while ($row = $result->fetch_assoc()) {
    $khach_sans[] = $row;
}

echo json_encode(["status" => "success", "data" => $khach_sans]);

$stmt->close();
$conn->close();
?>
