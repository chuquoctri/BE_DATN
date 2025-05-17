<?php
// error_reporting(E_ALL); // Bật để debug
// ini_set('display_errors', 1);
// ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/your/php-error.log'); 

require_once '../../connect.php'; 

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS"); // Chủ yếu dùng GET, POST (cho assign), DELETE (cho remove)
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
header('Content-Type: application/json; charset=UTF-8');

function getCurrentUser($conn) {
    // ... (Hàm getCurrentUser giữ nguyên như các API trước)
    $headers = [];
    if (function_exists('getallheaders')) { $headers = getallheaders(); } 
    else { foreach ($_SERVER as $name => $value) { if (substr($name, 0, 5) == 'HTTP_') { $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value; } } }
    $authHeader = $headers['Authorization'] ?? '';
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $user_id_str = $matches[1];
        if (!is_numeric($user_id_str)) { /* error_log(...) */ return null; }
        $user_id = intval($user_id_str);
        $stmt = $conn->prepare("SELECT id, role, khach_san_id FROM nguoi_dung WHERE id = ? AND (role = 'quan_ly' OR role = 'admin')");
        if ($stmt === false) { /* error_log(...) */ return null; }
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) { /* error_log(...) */ $stmt->close(); return null; }
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $userData = $result->fetch_assoc();
            if ($userData['role'] === 'quan_ly' && empty($userData['khach_san_id'])) { /* error_log(...) */ $stmt->close(); return null; }
            $stmt->close(); return $userData; 
        }
        $stmt->close();
    }
    return null;
}

if (!$conn) { http_response_code(503); echo json_encode(["success" => false, "message" => "Lỗi kết nối CSDL."]); exit(); }

$user = getCurrentUser($conn);
if (!$user) { http_response_code(401); echo json_encode(["success" => false, "message" => "Xác thực thất bại."]); exit(); }

$hotel_id_cua_quan_ly = $user['khach_san_id'] ?? null;
if (empty($hotel_id_cua_quan_ly) && $user['role'] !== 'super_admin_global_access') { 
    http_response_code(403); 
    echo json_encode(["success" => false, "message" => "Không xác định được khách sạn để quản lý tiện nghi phòng."]); 
    exit();
}
if($hotel_id_cua_quan_ly) $hotel_id_cua_quan_ly = intval($hotel_id_cua_quan_ly);


$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET') {
    try {
        if ($action === 'get_rooms') { // Lấy danh sách phòng của khách sạn
            $stmt = $conn->prepare("SELECT id, ten FROM phong WHERE khach_san_id = ? ORDER BY ten ASC");
            if(!$stmt) throw new Exception("Prepare failed (get_rooms): " . $conn->error, 500);
            $stmt->bind_param("i", $hotel_id_cua_quan_ly);
            if(!$stmt->execute()) throw new Exception("Execute failed (get_rooms): " . $stmt->error, 500);
            $result = $stmt->get_result();
            $rooms = [];
            while($row = $result->fetch_assoc()){ $rooms[] = $row; }
            $stmt->close();
            echo json_encode(["success" => true, "data" => $rooms]);

        } elseif ($action === 'get_master_amenities') { // Lấy danh sách tiện nghi gốc
            $stmt = $conn->prepare("SELECT id, ten, hinh_anh FROM tien_nghi ORDER BY ten ASC");
            if(!$stmt) throw new Exception("Prepare failed (master_amenities): " . $conn->error, 500);
            if(!$stmt->execute()) throw new Exception("Execute failed (master_amenities): " . $stmt->error, 500);
            $result = $stmt->get_result();
            $amenities = [];
            while($row = $result->fetch_assoc()){ $amenities[] = $row; }
            $stmt->close();
            echo json_encode(["success" => true, "data" => $amenities]);

        } elseif ($action === 'get_room_amenities' && isset($_GET['phong_id'])) { // Lấy tiện nghi của 1 phòng cụ thể
            $phong_id = filter_var($_GET['phong_id'], FILTER_VALIDATE_INT);
            if (!$phong_id || $phong_id <= 0) throw new Exception("ID phòng không hợp lệ.", 400);

            // Kiểm tra phòng này có thuộc khách sạn của quản lý không
            $stmt_check_room = $conn->prepare("SELECT id FROM phong WHERE id = ? AND khach_san_id = ?");
            if(!$stmt_check_room) throw new Exception("Prepare failed (check room for amenities): ".$conn->error, 500);
            $stmt_check_room->bind_param("ii", $phong_id, $hotel_id_cua_quan_ly);
            $stmt_check_room->execute();
            if($stmt_check_room->get_result()->num_rows === 0) {
                $stmt_check_room->close();
                throw new Exception("Phòng không tồn tại hoặc không thuộc khách sạn của bạn.", 404);
            }
            $stmt_check_room->close();

            $stmt = $conn->prepare("SELECT tn.id, tn.ten, tn.hinh_anh 
                                    FROM tien_nghi_phong tnp
                                    JOIN tien_nghi tn ON tnp.tien_nghi_id = tn.id
                                    WHERE tnp.phong_id = ?");
            if(!$stmt) throw new Exception("Prepare failed (get_room_amenities): " . $conn->error, 500);
            $stmt->bind_param("i", $phong_id);
            if(!$stmt->execute()) throw new Exception("Execute failed (get_room_amenities): " . $stmt->error, 500);
            $result = $stmt->get_result();
            $room_amenities = [];
            while($row = $result->fetch_assoc()){ $room_amenities[] = $row; }
            $stmt->close();
            echo json_encode(["success" => true, "data" => $room_amenities]);
        } else {
            throw new Exception("Action không hợp lệ cho GET request.", 400);
        }
    } catch (Exception $e) {
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("QL_TienNghiPhong_API GET Error: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
}
// POST: Gán một tiện nghi cho phòng
elseif ($method === 'POST' && $action === 'assign_amenity') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "Dữ liệu JSON không hợp lệ."]); exit();
    }

    $phong_id = filter_var($data['phong_id'] ?? null, FILTER_VALIDATE_INT);
    $tien_nghi_id = filter_var($data['tien_nghi_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$phong_id || $phong_id <= 0 || !$tien_nghi_id || $tien_nghi_id <= 0) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "ID phòng và ID tiện nghi là bắt buộc và phải hợp lệ."]); exit();
    }

    $conn->begin_transaction();
    try {
        // 1. Kiểm tra phòng này có thuộc khách sạn của quản lý không
        $stmt_check_room = $conn->prepare("SELECT id FROM phong WHERE id = ? AND khach_san_id = ?");
        if(!$stmt_check_room) throw new Exception("Prepare failed (check room for assign): ".$conn->error, 500);
        $stmt_check_room->bind_param("ii", $phong_id, $hotel_id_cua_quan_ly);
        $stmt_check_room->execute();
        if($stmt_check_room->get_result()->num_rows === 0) {
            $stmt_check_room->close();
            throw new Exception("Phòng không tồn tại hoặc không thuộc khách sạn của bạn.", 403);
        }
        $stmt_check_room->close();

        // 2. Kiểm tra tiện nghi gốc có tồn tại không (tùy chọn, nhưng nên có)
        $stmt_check_tn = $conn->prepare("SELECT id FROM tien_nghi WHERE id = ?");
        if(!$stmt_check_tn) throw new Exception("Prepare failed (check master amenity): ".$conn->error, 500);
        $stmt_check_tn->bind_param("i", $tien_nghi_id);
        $stmt_check_tn->execute();
        if($stmt_check_tn->get_result()->num_rows === 0) {
            $stmt_check_tn->close();
            throw new Exception("Tiện nghi gốc không tồn tại.", 404);
        }
        $stmt_check_tn->close();

        // 3. Kiểm tra xem tiện nghi đã được gán cho phòng này chưa
        $stmt_check_exist = $conn->prepare("SELECT phong_id FROM tien_nghi_phong WHERE phong_id = ? AND tien_nghi_id = ?");
        if(!$stmt_check_exist) throw new Exception("Prepare failed (check existing assignment): ".$conn->error, 500);
        $stmt_check_exist->bind_param("ii", $phong_id, $tien_nghi_id);
        $stmt_check_exist->execute();
        if($stmt_check_exist->get_result()->num_rows > 0) {
            $stmt_check_exist->close();
            throw new Exception("Tiện nghi này đã được gán cho phòng.", 409); // Conflict
        }
        $stmt_check_exist->close();

        // 4. Gán tiện nghi
        $stmt_assign = $conn->prepare("INSERT INTO tien_nghi_phong (phong_id, tien_nghi_id) VALUES (?, ?)");
        if(!$stmt_assign) throw new Exception("Prepare failed (assign amenity): " . $conn->error, 500);
        $stmt_assign->bind_param("ii", $phong_id, $tien_nghi_id);
        if(!$stmt_assign->execute()) throw new Exception("Execute failed (assign amenity): " . $stmt_assign->error, 500);
        
        $stmt_assign->close();
        $conn->commit();
        http_response_code(201);
        echo json_encode(["success" => true, "message" => "Gán tiện nghi cho phòng thành công."]);

    } catch (Exception $e) {
        $conn->rollback();
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("QL_TienNghiPhong_API POST Error: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Lỗi khi gán tiện nghi: " . $e->getMessage()]);
    }
}
// DELETE: Gỡ bỏ một tiện nghi khỏi phòng
elseif ($method === 'DELETE' && $action === 'remove_amenity') {
    // Nhận params từ query string cho DELETE
    $phong_id = filter_var($_GET['phong_id'] ?? null, FILTER_VALIDATE_INT);
    $tien_nghi_id = filter_var($_GET['tien_nghi_id'] ?? null, FILTER_VALIDATE_INT);

    if (!$phong_id || $phong_id <= 0 || !$tien_nghi_id || $tien_nghi_id <= 0) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "ID phòng và ID tiện nghi là bắt buộc."]); exit();
    }
    
    $conn->begin_transaction();
    try {
        // 1. Kiểm tra phòng này có thuộc khách sạn của quản lý không
        $stmt_check_room = $conn->prepare("SELECT id FROM phong WHERE id = ? AND khach_san_id = ?");
        if(!$stmt_check_room) throw new Exception("Prepare failed (check room for remove): ".$conn->error, 500);
        $stmt_check_room->bind_param("ii", $phong_id, $hotel_id_cua_quan_ly);
        $stmt_check_room->execute();
        if($stmt_check_room->get_result()->num_rows === 0) {
            $stmt_check_room->close();
            throw new Exception("Phòng không tồn tại hoặc không thuộc khách sạn của bạn.", 403);
        }
        $stmt_check_room->close();

        // 2. Gỡ bỏ tiện nghi
        $stmt_remove = $conn->prepare("DELETE FROM tien_nghi_phong WHERE phong_id = ? AND tien_nghi_id = ?");
        if(!$stmt_remove) throw new Exception("Prepare failed (remove amenity): " . $conn->error, 500);
        $stmt_remove->bind_param("ii", $phong_id, $tien_nghi_id);
        if(!$stmt_remove->execute()) throw new Exception("Execute failed (remove amenity): " . $stmt_remove->error, 500);

        if($stmt_remove->affected_rows > 0){
            echo json_encode(["success" => true, "message" => "Gỡ bỏ tiện nghi khỏi phòng thành công."]);
        } else {
            throw new Exception("Tiện nghi không được gán cho phòng này hoặc đã được gỡ bỏ.", 404);
        }
        $stmt_remove->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("QL_TienNghiPhong_API DELETE Error: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Lỗi khi gỡ bỏ tiện nghi: " . $e->getMessage()]);
    }
}
else {
    http_response_code(405); 
    echo json_encode(["success" => false, "message" => "Phương thức hoặc action không được hỗ trợ."]);
}

$conn->close();
?>