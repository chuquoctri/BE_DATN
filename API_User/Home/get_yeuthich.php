<?php
require_once '../../connect.php';

header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");

// Kiểm tra phương thức request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["status" => "error", "message" => "Chỉ hỗ trợ phương thức GET"]);
    exit();
}

// Lấy user_id từ query parameter
$user_id = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;

if ($user_id <= 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "ID người dùng không hợp lệ"]);
    exit();
}

try {
    // Câu lệnh SQL join các bảng
    $sql = "
        SELECT 
            ks.id, 
            ks.ten, 
            ks.dia_chi, 
            ks.so_sao,
            ks.hinh_anh,
            kyt.ngay_tao AS ngay_yeu_thich
        FROM khach_san_yeu_thich kyt
        JOIN khach_san ks ON kyt.khach_san_id = ks.id
        WHERE kyt.nguoi_dung_id = :user_id
        ORDER BY kyt.ngay_tao DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($result) > 0) {
        echo json_encode([
            "status" => "success",
            "data" => $result,
            "count" => count($result)
        ], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode([
            "status" => "success",
            "data" => [],
            "message" => "Người dùng chưa có khách sạn yêu thích nào"
        ]);
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Lỗi truy vấn: " . $e->getMessage()]);
}
?>