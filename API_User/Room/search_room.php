<?php
// Kết nối database
require_once '../../connect.php';

// Thiết lập header JSON + CORS
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Nhận dữ liệu từ request
$data = json_decode(file_get_contents("php://input"), true);

// Lấy thông tin tìm kiếm từ request
$ngay_nhan = isset($data['ngay_nhan']) ? $data['ngay_nhan'] : null;
$ngay_tra = isset($data['ngay_tra']) ? $data['ngay_tra'] : null;
$gia_max = isset($data['gia_max']) ? floatval($data['gia_max']) : null;
$suc_chua = isset($data['suc_chua']) ? intval($data['suc_chua']) : null;
$ten_khach_san = isset($data['ten_khach_san']) ? $data['ten_khach_san'] : null;
$dia_chi = isset($data['dia_chi']) ? $data['dia_chi'] : null;
$tien_nghi_ids = isset($data['tien_nghi_ids']) ? $data['tien_nghi_ids'] : [];

// Bắt đầu câu truy vấn
$sql = "SELECT DISTINCT ks.id AS khach_san_id, ks.ten AS ten_khach_san, ks.dia_chi, ks.so_sao, ks.hinh_anh, ks.kinh_do, ks.vi_do,
               p.id AS phong_id, p.ten AS ten_phong, p.gia, p.suc_chua, p.mo_ta AS mo_ta_phong
        FROM khach_san ks
        JOIN phong p ON ks.id = p.khach_san_id
        WHERE 1=1";
$params = [];
$types = "";

// Lọc theo khách sạn
if (!empty($ten_khach_san)) {
    $sql .= " AND ks.ten LIKE ?";
    $params[] = "%" . $ten_khach_san . "%";
    $types .= "s";
}

// Lọc theo địa chỉ
if (!empty($dia_chi)) {
    $sql .= " AND LOWER(TRIM(ks.dia_chi)) LIKE ?";
    $params[] = "%" . strtolower($dia_chi) . "%";
    $types .= "s";
}

// Lọc theo giá tối đa
if (!empty($gia_max)) {
    $sql .= " AND p.gia <= ?";
    $params[] = $gia_max;
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

// Kiểm tra phòng có bị đặt trước hay không
if (!empty($ngay_nhan) && !empty($ngay_tra)) {
    $sql .= " AND p.id NOT IN (
        SELECT ctdp.phong_id 
        FROM chi_tiet_dat_phong ctdp
        JOIN dat_phong dp ON dp.id = ctdp.dat_phong_id
        WHERE (dp.ngay_nhan_phong <= ? AND dp.ngay_tra_phong >= ?)
    )";
    $params[] = $ngay_tra;
    $params[] = $ngay_nhan;
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
$khach_sans = [];
while ($row = $result->fetch_assoc()) {
    $ks_id = $row['khach_san_id'];

    if (!isset($khach_sans[$ks_id])) {
        $khach_sans[$ks_id] = [
            "id" => $ks_id,
            "ten" => $row["ten_khach_san"],
            "dia_chi" => $row["dia_chi"],
            "so_sao" => $row["so_sao"],
            "hinh_anh" => $row["hinh_anh"],
            "kinh_do" => $row["kinh_do"],
            "vi_do" => $row["vi_do"],
            "phongs" => []
        ];
    }

    $khach_sans[$ks_id]["phongs"][] = [
        "phong_id" => $row["phong_id"],
        "ten_phong" => $row["ten_phong"],
        "gia" => $row["gia"],
        "suc_chua" => $row["suc_chua"],
        "mo_ta" => $row["mo_ta_phong"]
    ];
}

// Trả về kết quả JSON
echo json_encode(["status" => "success", "data" => array_values($khach_sans)]);

// Đóng kết nối
$stmt->close();
$conn->close();
?>
