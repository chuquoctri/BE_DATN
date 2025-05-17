<?php
// error_reporting(E_ALL); // Bật để debug
// ini_set('display_errors', 1);
// ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/your/php-error.log'); 

require_once '../../connect.php'; 

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
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
        if (!is_numeric($user_id_str)) { error_log("QL_DVKS_API: User ID from token is not numeric: '$user_id_str'"); return null; }
        $user_id = intval($user_id_str);
        $stmt = $conn->prepare("SELECT id, role, khach_san_id FROM nguoi_dung WHERE id = ? AND (role = 'quan_ly' OR role = 'admin')");
        if ($stmt === false) { error_log("QL_DVKS_API getCurrentUser Prepare failed: " . $conn->error); return null; }
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) { error_log("QL_DVKS_API getCurrentUser Execute failed: " . $stmt->error); $stmt->close(); return null; }
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $userData = $result->fetch_assoc();
            if ($userData['role'] === 'quan_ly' && empty($userData['khach_san_id'])) {
                 error_log("QL_DVKS_API: Manager user " . $userData['id'] . " has no khach_san_id assigned.");
                 $stmt->close(); return null; 
            }
            $stmt->close();
            return $userData; 
        }
        $stmt->close();
    }
    return null;
}

if (!$conn) { http_response_code(503); echo json_encode(["success" => false, "message" => "Lỗi kết nối cơ sở dữ liệu."]); exit(); }

$user = getCurrentUser($conn);
if (!$user) { http_response_code(401); echo json_encode(["success" => false, "message" => "Xác thực thất bại."]); exit(); }

$hotel_id_cua_quan_ly = $user['khach_san_id'] ?? null;
if (empty($hotel_id_cua_quan_ly)) { 
    http_response_code(403); 
    echo json_encode(["success" => false, "message" => "Không xác định được khách sạn để quản lý dịch vụ. Người dùng cần được gán vào một khách sạn."]); 
    exit();
}
$hotel_id_cua_quan_ly = intval($hotel_id_cua_quan_ly);

$method = $_SERVER['REQUEST_METHOD'];

// GET: Lấy danh sách dịch vụ của khách sạn, chi tiết một dịch vụ KS, hoặc danh sách dịch vụ gốc
if ($method === 'GET') {
    try {
        if (isset($_GET['id_dvks'])) { // Lấy chi tiết một dịch vụ khách sạn (để sửa giá)
            $dvks_id = filter_var($_GET['id_dvks'], FILTER_VALIDATE_INT);
            if (!$dvks_id || $dvks_id <= 0) throw new Exception("ID dịch vụ khách sạn không hợp lệ.", 400);

            $stmt = $conn->prepare("SELECT dvks.id as dvks_id, dvks.gia, dvks.dich_vu_id, dv.ten as ten_dich_vu, dv.hinh_anh as hinh_anh_dich_vu 
                                    FROM dich_vu_khach_san dvks
                                    JOIN dich_vu dv ON dvks.dich_vu_id = dv.id
                                    WHERE dvks.id = ? AND dvks.khach_san_id = ?");
            if(!$stmt) throw new Exception("Prepare failed (get DVKS detail): " . $conn->error, 500);
            $stmt->bind_param("ii", $dvks_id, $hotel_id_cua_quan_ly);
            if(!$stmt->execute()) throw new Exception("Execute failed (get DVKS detail): " . $stmt->error, 500);
            $result = $stmt->get_result();
            $service_hotel = $result->fetch_assoc();
            $stmt->close();

            if ($service_hotel) {
                echo json_encode(["success" => true, "data" => $service_hotel]);
            } else {
                throw new Exception("Dịch vụ khách sạn không tồn tại hoặc không thuộc khách sạn của bạn.", 404);
            }
        } elseif (isset($_GET['master_services'])) { // Lấy danh sách dịch vụ gốc từ bảng `dich_vu`
            // Tùy chọn: có thể lọc ra những dịch vụ chưa được thêm vào khách sạn này
            $stmt = $conn->prepare("SELECT id, ten, hinh_anh FROM dich_vu ORDER BY ten ASC");
            if(!$stmt) throw new Exception("Prepare failed (master services): " . $conn->error, 500);
            if(!$stmt->execute()) throw new Exception("Execute failed (master services): " . $stmt->error, 500);
            $result = $stmt->get_result();
            $master_services = [];
            while($row = $result->fetch_assoc()){
                $master_services[] = $row;
            }
            $stmt->close();
            echo json_encode(["success" => true, "data" => $master_services]);

        } else { // Lấy danh sách dịch vụ hiện tại của khách sạn
            $search = $_GET['search'] ?? '';
            // Phân trang có thể thêm sau nếu cần
            
            $sql = "SELECT dvks.id as dvks_id, dvks.gia, dvks.dich_vu_id, 
                           dv.ten as ten_dich_vu, dv.hinh_anh as hinh_anh_dich_vu
                    FROM dich_vu_khach_san dvks
                    JOIN dich_vu dv ON dvks.dich_vu_id = dv.id
                    WHERE dvks.khach_san_id = ?";
            $params = [$hotel_id_cua_quan_ly];
            $types = "i";

            if (!empty($search)) {
                $searchTerm = "%{$search}%";
                $sql .= " AND dv.ten LIKE ?";
                $params[] = $searchTerm; 
                $types .= "s";
            }
            $sql .= " ORDER BY dv.ten ASC";
            
            $stmt = $conn->prepare($sql);
            if(!$stmt) throw new Exception("Prepare failed (list hotel services): " . $conn->error, 500);
            $stmt->bind_param($types, ...$params);
            if(!$stmt->execute()) throw new Exception("Execute failed (list hotel services): " . $stmt->error, 500);
            
            $result = $stmt->get_result();
            $hotel_services = [];
            while ($row = $result->fetch_assoc()) {
                $hotel_services[] = $row;
            }
            $stmt->close();
            echo json_encode(["success" => true, "data" => $hotel_services]);
        }
    } catch (Exception $e) {
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("QL_DVKS_API GET Error: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
}
// POST: Thêm một dịch vụ (từ danh sách gốc) vào khách sạn với giá cụ thể
elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "Dữ liệu JSON không hợp lệ."]); exit();
    }

    $dich_vu_id = filter_var($data['dich_vu_id'] ?? null, FILTER_VALIDATE_INT);
    $gia = $data['gia'] ?? null;

    if (!$dich_vu_id || $dich_vu_id <= 0) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "ID dịch vụ gốc là bắt buộc."]); exit();
    }
    if (!isset($gia) || !is_numeric($gia) || floatval($gia) < 0) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "Giá dịch vụ không hợp lệ."]); exit();
    }
    $gia_float = floatval($gia);

    $conn->begin_transaction();
    try {
        // 1. Kiểm tra xem dịch vụ gốc có tồn tại không
        $stmt_check_dv = $conn->prepare("SELECT id FROM dich_vu WHERE id = ?");
        if(!$stmt_check_dv) throw new Exception("Prepare failed (check master service): " . $conn->error, 500);
        $stmt_check_dv->bind_param("i", $dich_vu_id);
        $stmt_check_dv->execute();
        if($stmt_check_dv->get_result()->num_rows === 0) {
            $stmt_check_dv->close();
            throw new Exception("Dịch vụ gốc không tồn tại.", 404);
        }
        $stmt_check_dv->close();

        // 2. Kiểm tra xem dịch vụ này đã được thêm vào khách sạn chưa
        $stmt_check_exist = $conn->prepare("SELECT id FROM dich_vu_khach_san WHERE khach_san_id = ? AND dich_vu_id = ?");
        if(!$stmt_check_exist) throw new Exception("Prepare failed (check existing hotel service): " . $conn->error, 500);
        $stmt_check_exist->bind_param("ii", $hotel_id_cua_quan_ly, $dich_vu_id);
        $stmt_check_exist->execute();
        if($stmt_check_exist->get_result()->num_rows > 0) {
            $stmt_check_exist->close();
            throw new Exception("Dịch vụ này đã được thêm vào khách sạn. Bạn có thể sửa giá.", 409); // 409 Conflict
        }
        $stmt_check_exist->close();

        // 3. Thêm vào dich_vu_khach_san
        $stmt_insert = $conn->prepare("INSERT INTO dich_vu_khach_san (khach_san_id, dich_vu_id, gia) VALUES (?, ?, ?)");
        if(!$stmt_insert) throw new Exception("Prepare failed (add hotel service): " . $conn->error, 500);
        $stmt_insert->bind_param("iid", $hotel_id_cua_quan_ly, $dich_vu_id, $gia_float);
        if(!$stmt_insert->execute()) throw new Exception("Execute failed (add hotel service): " . $stmt_insert->error, 500);
        
        $new_dvks_id = $conn->insert_id;
        $stmt_insert->close();
        $conn->commit();
        http_response_code(201);
        echo json_encode(["success" => true, "message" => "Thêm dịch vụ vào khách sạn thành công.", "id" => $new_dvks_id]);

    } catch (Exception $e) {
        $conn->rollback();
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("QL_DVKS_API POST Error: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Lỗi khi thêm dịch vụ: " . $e->getMessage()]);
    }
}

// PUT: Cập nhật giá dịch vụ của khách sạn
elseif ($method === 'PUT') {
    $dvks_id_to_update = filter_var($_GET['id_dvks'] ?? null, FILTER_VALIDATE_INT);
    if (!$dvks_id_to_update || $dvks_id_to_update <= 0) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "ID dịch vụ khách sạn là bắt buộc và phải hợp lệ."]); exit();
    }

    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE || !isset($data['gia'])) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "Dữ liệu JSON không hợp lệ hoặc thiếu giá."]); exit();
    }
    if (!is_numeric($data['gia']) || floatval($data['gia']) < 0) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "Giá dịch vụ không hợp lệ."]); exit();
    }
    $new_gia = floatval($data['gia']);

    $conn->begin_transaction();
    try {
        // Kiểm tra dịch vụ khách sạn có tồn tại và thuộc khách sạn của quản lý không
        $stmt_check = $conn->prepare("SELECT id FROM dich_vu_khach_san WHERE id = ? AND khach_san_id = ?");
        if(!$stmt_check) throw new Exception("Prepare failed (check DVKS for update): " . $conn->error, 500);
        $stmt_check->bind_param("ii", $dvks_id_to_update, $hotel_id_cua_quan_ly);
        if(!$stmt_check->execute()) throw new Exception("Execute failed (check DVKS for update): " . $stmt_check->error, 500);
        if($stmt_check->get_result()->num_rows === 0) {
            $stmt_check->close();
            throw new Exception("Dịch vụ khách sạn không tồn tại hoặc bạn không có quyền sửa.", 404);
        }
        $stmt_check->close();

        $stmt_update = $conn->prepare("UPDATE dich_vu_khach_san SET gia = ? WHERE id = ? AND khach_san_id = ?");
        if(!$stmt_update) throw new Exception("Prepare failed (update DVKS price): " . $conn->error, 500);
        $stmt_update->bind_param("dii", $new_gia, $dvks_id_to_update, $hotel_id_cua_quan_ly);
        if(!$stmt_update->execute()) throw new Exception("Execute failed (update DVKS price): " . $stmt_update->error, 500);

        if($stmt_update->affected_rows > 0){
            echo json_encode(["success" => true, "message" => "Cập nhật giá dịch vụ thành công."]);
        } else {
            echo json_encode(["success" => true, "message" => "Không có thay đổi nào được thực hiện (giá có thể giống hệt)."]);
        }
        $stmt_update->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("QL_DVKS_API PUT Error: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Lỗi khi cập nhật giá dịch vụ: " . $e->getMessage()]);
    }
}

// DELETE: Xóa một dịch vụ khỏi danh sách cung cấp của khách sạn
elseif ($method === 'DELETE') {
    $dvks_id_to_delete = filter_var($_GET['id_dvks'] ?? null, FILTER_VALIDATE_INT);
    if (!$dvks_id_to_delete || $dvks_id_to_delete <= 0) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "ID dịch vụ khách sạn là bắt buộc."]); exit();
    }

    $conn->begin_transaction();
    try {
        // Cần kiểm tra xem dịch vụ này có đang được sử dụng trong bảng dat_phong_dich_vu không.
        // Nếu có, có thể không cho xóa hoặc có cảnh báo. Tạm thời bỏ qua check này cho đơn giản.
        // $stmt_check_usage = $conn->prepare("SELECT COUNT(*) as count FROM dat_phong_dich_vu WHERE dich_vu_khach_san_id = ?");
        // ...

        $stmt_delete = $conn->prepare("DELETE FROM dich_vu_khach_san WHERE id = ? AND khach_san_id = ?");
        if(!$stmt_delete) throw new Exception("Prepare failed (delete DVKS): " . $conn->error, 500);
        $stmt_delete->bind_param("ii", $dvks_id_to_delete, $hotel_id_cua_quan_ly);
        if(!$stmt_delete->execute()) throw new Exception("Execute failed (delete DVKS): " . $stmt_delete->error, 500);

        if($stmt_delete->affected_rows > 0){
            echo json_encode(["success" => true, "message" => "Xóa dịch vụ khỏi khách sạn thành công."]);
        } else {
            throw new Exception("Dịch vụ không tồn tại trong khách sạn của bạn hoặc đã được xóa.", 404);
        }
        $stmt_delete->close();
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("QL_DVKS_API DELETE Error: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Lỗi khi xóa dịch vụ: " . $e->getMessage()]);
    }
}
else {
    http_response_code(405); 
    echo json_encode(["success" => false, "message" => "Phương thức không được hỗ trợ."]);
}

$conn->close();
?>