<?php
require_once '../../connect.php';

header('Content-Type: application/json');

// Lấy ID người dùng
$nguoi_dung_id = $_GET['nguoi_dung_id'] ?? null;

if (!$nguoi_dung_id) {
    echo json_encode(['success' => false, 'message' => 'Thiếu nguoi_dung_id']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT ks.id, ks.ten, ks.dia_chi, ks.so_sao, ks.mo_ta, ks.hinh_anh,
               ks.kinh_do, ks.vi_do, ks.so_dien_thoai, ks.email,
               ks.ngay_tao, ks.ngay_cap_nhat
        FROM yeu_thich_khach_san kys
        JOIN khach_san ks ON kys.khach_san_id = ks.id
        WHERE kys.nguoi_dung_id = ?
    ");

    // Gán tham số
    $stmt->bind_param("i", $nguoi_dung_id);
    $stmt->execute();

    // Lấy kết quả
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
