<?php
// Để debug, bạn có thể bật các dòng này và kiểm tra file log lỗi PHP của server
error_reporting(E_ALL);
ini_set('display_errors', 1); // Bật để thấy lỗi PHP trực tiếp (tắt ở production)
// ini_set('log_errors', 1);    
// ini_set('error_log', '/path/to/your/php-error.log'); 

require_once '../../connect.php'; // Đảm bảo đường dẫn này chính xác!

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

function getCurrentUser($conn) {
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    } else { 
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headerKey = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$headerKey] = $value;
            }
        }
    }
    
    $authHeader = $headers['Authorization'] ?? '';
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $user_id_str = $matches[1];
        if (!is_numeric($user_id_str)) {
            return null;
        }
        $user_id = intval($user_id_str);

        $stmt = $conn->prepare("SELECT id, ho_ten, email, role, khach_san_id FROM nguoi_dung WHERE id = ? AND (role = 'quan_ly' OR role = 'admin')");
        if ($stmt === false) {
            error_log("API_AUTH getCurrentUser Prepare failed: " . $conn->error);
            return null;
        }
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) {
            error_log("API_AUTH getCurrentUser Execute failed: " . $stmt->error);
            $stmt->close();
            return null;
        }
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $userData = $result->fetch_assoc();
            if ($userData['role'] === 'quan_ly' && empty($userData['khach_san_id'])) {
                $stmt->close();
                return null; 
            }
            $stmt->close();
            return $userData; 
        }
        $stmt->close();
    }
    return null;
}

if (!$conn) {
    http_response_code(503); 
    echo json_encode(["success" => false, "message" => "Lỗi kết nối cơ sở dữ liệu."]);
    exit();
}

$user = getCurrentUser($conn);
if (!$user) {
    http_response_code(401); 
    echo json_encode(["success" => false, "message" => "Xác thực thất bại. Yêu cầu token hợp lệ cho quản lý/admin đã được gán khách sạn (nếu là quản lý)."]);
    exit();
}

$hotel_id = $user['khach_san_id'] ?? null;
if (empty($hotel_id)) { // Áp dụng cho cả admin và quan_ly theo logic này
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Không xác định được khách sạn để thực hiện thao tác trên phòng."]);
    exit();
}
$hotel_id = intval($hotel_id); // Đảm bảo $hotel_id là số nguyên

$method = $_SERVER['REQUEST_METHOD'];

// GET: Lấy danh sách phòng hoặc chi tiết một phòng (kèm hình ảnh)
if ($method === 'GET') {
    try {
        if (isset($_GET['id'])) {
            $phong_id_get = filter_var($_GET['id'], FILTER_VALIDATE_INT);
            if ($phong_id_get === false || $phong_id_get <= 0) {
                http_response_code(400); echo json_encode(["success" => false, "message" => "ID phòng không hợp lệ."]); exit();
            }

            $sql_phong = "SELECT p.id, p.ten, p.gia, p.so_luong, p.suc_chua, p.mo_ta FROM phong p WHERE p.id = ? AND p.khach_san_id = ?";
            $stmt_phong = $conn->prepare($sql_phong);
            if(!$stmt_phong) throw new Exception("Lỗi chuẩn bị truy vấn chi tiết phòng: " . $conn->error);
            $stmt_phong->bind_param("ii", $phong_id_get, $hotel_id);
            if(!$stmt_phong->execute()) throw new Exception("Lỗi thực thi truy vấn chi tiết phòng: " . $stmt_phong->error);
            
            $result_phong = $stmt_phong->get_result();
            $phong = $result_phong->fetch_assoc();
            $stmt_phong->close();

            if ($phong) {
                $stmt_images = $conn->prepare("SELECT id as hinh_anh_id, hinh_anh FROM hinh_anh_phong WHERE phong_id = ? ORDER BY id ASC");
                if(!$stmt_images) throw new Exception("Lỗi chuẩn bị truy vấn hình ảnh: " . $conn->error);
                $stmt_images->bind_param("i", $phong['id']);
                if(!$stmt_images->execute()) throw new Exception("Lỗi thực thi truy vấn hình ảnh: " . $stmt_images->error);

                $result_images = $stmt_images->get_result();
                $hinh_anh_list = [];
                while($img_row = $result_images->fetch_assoc()){
                    $hinh_anh_list[] = $img_row;
                }
                $stmt_images->close();
                $phong['hinh_anh_list'] = $hinh_anh_list;
                echo json_encode(["success" => true, "data" => $phong]);
            } else {
                http_response_code(404);
                echo json_encode(["success" => false, "message" => "Phòng không tìm thấy hoặc không thuộc khách sạn quản lý."]);
            }
            exit();
        }

        $search = $_GET['search'] ?? '';
        $page = isset($_GET['page']) && filter_var($_GET['page'], FILTER_VALIDATE_INT, ["options" => ["min_range"=>1]]) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) && filter_var($_GET['limit'], FILTER_VALIDATE_INT, ["options" => ["min_range"=>1]]) ? (int)$_GET['limit'] : 10;
        $offset = ($page - 1) * $limit;
        
        $conditions = " WHERE p.khach_san_id = ? ";
        $params = [$hotel_id];
        $types = "i";
        
        if (!empty($search)) {
            $searchTerm = "%{$search}%";
            $conditions .= " AND (p.ten LIKE ? OR p.mo_ta LIKE ?)";
            $params[] = $searchTerm; $params[] = $searchTerm; $types .= "ss";
        }
        
        $countQuerySql = "SELECT COUNT(p.id) as total FROM phong p" . $conditions;
        $stmt_count = $conn->prepare($countQuerySql);
        if(!$stmt_count) throw new Exception("Lỗi chuẩn bị đếm phòng: " . $conn->error);
        $stmt_count->bind_param($types, ...$params);
        if(!$stmt_count->execute()) throw new Exception("Lỗi thực thi đếm phòng: " . $stmt_count->error);
        $totalResult = $stmt_count->get_result()->fetch_assoc();
        $total = (int)$totalResult['total'];
        $stmt_count->close();

        $dataQuerySql = "SELECT p.id, p.ten, p.gia, p.so_luong, p.suc_chua, p.mo_ta, 
                               (SELECT GROUP_CONCAT(hap.hinh_anh ORDER BY hap.id ASC SEPARATOR '|||') 
                                FROM hinh_anh_phong hap WHERE hap.phong_id = p.id) as hinh_anh_concat 
                         FROM phong p" . $conditions . " ORDER BY p.id DESC LIMIT ? OFFSET ?";
        $params_data = $params; // Tạo bản sao params để không ảnh hưởng đến mảng gốc nếu cần dùng lại
        $params_data[] = $limit; $params_data[] = $offset; 
        $types_data = $types . "ii";
        
        $stmt_data = $conn->prepare($dataQuerySql);
        if(!$stmt_data) throw new Exception("Lỗi chuẩn bị lấy danh sách phòng: " . $conn->error);
        $stmt_data->bind_param($types_data, ...$params_data);
        if(!$stmt_data->execute()) throw new Exception("Lỗi thực thi lấy danh sách phòng: " . $stmt_data->error);
        
        $result = $stmt_data->get_result();
        $rooms = [];
        while ($row = $result->fetch_assoc()) {
            if (!empty($row['hinh_anh_concat'])) {
                $row['hinh_anh_list_urls'] = explode('|||', $row['hinh_anh_concat']);
            } else {
                $row['hinh_anh_list_urls'] = [];
            }
            unset($row['hinh_anh_concat']);
            $rooms[] = $row;
        }
        $stmt_data->close();
        
        echo json_encode([
            "success" => true, "data" => $rooms,
            "pagination" => ["total" => $total, "page" => $page, "limit" => $limit, "total_pages" => $total > 0 ? ceil($total / $limit) : 0]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        error_log("API_PHONG_GET_ERROR: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Lỗi máy chủ khi lấy dữ liệu phòng: " . $e->getMessage()]);
    }
}

// POST: Thêm phòng mới (kèm hình ảnh)
elseif ($method === 'POST') {
    $conn->begin_transaction();
    try {
        $data = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Dữ liệu JSON không hợp lệ.", 400);
        }
        
        $requiredFields = ['ten', 'gia', 'so_luong', 'suc_chua'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || ($data[$field] === '' && !is_numeric($data[$field]))) {
                 throw new Exception("Trường '$field' là bắt buộc và không được để trống.", 400);
            }
        }
        if (!is_numeric($data['gia']) || floatval($data['gia']) < 0 ||
            !is_numeric($data['so_luong']) || intval($data['so_luong']) < 0 ||
            !is_numeric($data['suc_chua']) || intval($data['suc_chua']) <= 0) {
            throw new Exception("Giá, số lượng, sức chứa phải là số hợp lệ và không âm (sức chứa > 0).", 400);
        }
        
        $stmt_phong = $conn->prepare("INSERT INTO phong (khach_san_id, ten, gia, so_luong, suc_chua, mo_ta) VALUES (?, ?, ?, ?, ?, ?)");
        if(!$stmt_phong) throw new Exception("Lỗi chuẩn bị thêm phòng: " . $conn->error);
        
        // SỬA LỖI BIND_PARAM: Gán giá trị vào biến trước khi bind
        $param_ten = $data['ten'];
        $param_gia = floatval($data['gia']);
        $param_so_luong = intval($data['so_luong']);
        $param_suc_chua = intval($data['suc_chua']);
        $param_mo_ta = $data['mo_ta'] ?? null;

        $stmt_phong->bind_param("isdiis", 
            $hotel_id, 
            $param_ten, 
            $param_gia, 
            $param_so_luong, 
            $param_suc_chua, 
            $param_mo_ta
        );
        
        if (!$stmt_phong->execute()) throw new Exception("Không thể thêm phòng: " . $stmt_phong->error);
        $new_phong_id = $conn->insert_id;
        if ($new_phong_id == 0) throw new Exception("Không thể lấy ID phòng vừa thêm.");
        $stmt_phong->close();

        $hinh_anh_urls = $data['hinh_anh_urls'] ?? [];
        if (!empty($hinh_anh_urls) && is_array($hinh_anh_urls)) {
            $stmt_img = $conn->prepare("INSERT INTO hinh_anh_phong (phong_id, hinh_anh) VALUES (?, ?)");
            if (!$stmt_img) throw new Exception("Lỗi chuẩn bị chèn ảnh: ".$conn->error);
            foreach ($hinh_anh_urls as $url) {
                $trimmed_url = trim($url);
                if (!empty($trimmed_url) && filter_var($trimmed_url, FILTER_VALIDATE_URL)) {
                    $stmt_img->bind_param("is", $new_phong_id, $trimmed_url);
                    if (!$stmt_img->execute()) {
                        error_log("API_PHONG_POST_IMG_ERROR: Could not insert URL '$trimmed_url': " . $stmt_img->error);
                    }
                }
            }
            $stmt_img->close();
        }
        
        $conn->commit();
        http_response_code(201); 
        echo json_encode(["success" => true, "message" => "Thêm phòng và hình ảnh thành công.", "id" => $new_phong_id]);

    } catch (Exception $e) {
        $conn->rollback();
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("API_PHONG_POST_EXCEPTION: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Lỗi khi thêm phòng: " . $e->getMessage()]);
    }
}

// PUT: Cập nhật thông tin phòng (kèm hình ảnh)
elseif ($method === 'PUT') {
    $room_id_to_update = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$room_id_to_update || $room_id_to_update <= 0) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "ID phòng là bắt buộc và phải hợp lệ trên URL."]); exit();
    }

    $conn->begin_transaction();
    try {
        $stmt_check = $conn->prepare("SELECT id FROM phong WHERE id = ? AND khach_san_id = ?");
        if(!$stmt_check) throw new Exception("Lỗi chuẩn bị kiểm tra phòng (PUT): " . $conn->error);
        $stmt_check->bind_param("ii", $room_id_to_update, $hotel_id);
        if(!$stmt_check->execute()) throw new Exception("Lỗi thực thi kiểm tra phòng (PUT): " . $stmt_check->error);
        $result_check = $stmt_check->get_result();
        if ($result_check->num_rows === 0) {
            $stmt_check->close(); 
            throw new Exception("Phòng không tồn tại hoặc bạn không có quyền cập nhật phòng này.", 404);
        }
        $stmt_check->close();
        
        $data = json_decode(file_get_contents("php://input"), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Dữ liệu JSON không hợp lệ.", 400);
        }
        
        // Xây dựng động câu lệnh UPDATE và params
        $updateFields = [];
        $updateParams = [];
        $updateTypes = "";

        if(isset($data['ten'])){
            $updateFields[] = "ten = ?";
            $updateParams[] = $data['ten'];
            $updateTypes .= "s";
        }
        if(isset($data['gia'])){
            if (!is_numeric($data['gia']) || floatval($data['gia']) < 0) throw new Exception("Giá không hợp lệ.", 400);
            $updateFields[] = "gia = ?";
            $updateParams[] = floatval($data['gia']);
            $updateTypes .= "d";
        }
        if(isset($data['so_luong'])){
            if (!is_numeric($data['so_luong']) || intval($data['so_luong']) < 0) throw new Exception("Số lượng không hợp lệ.", 400);
            $updateFields[] = "so_luong = ?";
            $updateParams[] = intval($data['so_luong']);
            $updateTypes .= "i";
        }
        if(isset($data['suc_chua'])){
            if (!is_numeric($data['suc_chua']) || intval($data['suc_chua']) <= 0) throw new Exception("Sức chứa không hợp lệ.", 400);
            $updateFields[] = "suc_chua = ?";
            $updateParams[] = intval($data['suc_chua']);
            $updateTypes .= "i";
        }
        if(isset($data['mo_ta'])){ // Cho phép mô tả là chuỗi rỗng, nhưng nếu gửi thì cập nhật
            $updateFields[] = "mo_ta = ?";
            $updateParams[] = $data['mo_ta'];
            $updateTypes .= "s";
        }

        if (!empty($updateFields)) {
            $sql_update_phong = "UPDATE phong SET " . implode(', ', $updateFields) . " WHERE id = ? AND khach_san_id = ?";
            $updateParams[] = $room_id_to_update;
            $updateParams[] = $hotel_id; // Thêm hotel_id vào điều kiện WHERE để tăng bảo mật
            $updateTypes .= "ii";

            $stmt_update = $conn->prepare($sql_update_phong);
            if (!$stmt_update) throw new Exception("Lỗi chuẩn bị cập nhật phòng: ".$conn->error);
            $stmt_update->bind_param($updateTypes, ...$updateParams); // Splat operator cần PHP 5.6+
            if (!$stmt_update->execute()) throw new Exception("Không thể cập nhật phòng: " . $stmt_update->error);
            $stmt_update->close();
        }

        if (isset($data['hinh_anh_urls']) && is_array($data['hinh_anh_urls'])) {
            $hinh_anh_urls = $data['hinh_anh_urls'];
            $stmt_delete_img = $conn->prepare("DELETE FROM hinh_anh_phong WHERE phong_id = ?");
            // Không cần check khach_san_id ở đây vì phong_id đã được xác thực thuộc về khách sạn
            if (!$stmt_delete_img) throw new Exception("Lỗi chuẩn bị xóa ảnh cũ: ".$conn->error);
            $stmt_delete_img->bind_param("i", $room_id_to_update);
            if (!$stmt_delete_img->execute()) throw new Exception("Lỗi thực thi xóa ảnh cũ: ".$stmt_delete_img->error);
            $stmt_delete_img->close();

            if (!empty($hinh_anh_urls)) {
                $stmt_insert_img = $conn->prepare("INSERT INTO hinh_anh_phong (phong_id, hinh_anh) VALUES (?, ?)");
                if (!$stmt_insert_img) throw new Exception("Lỗi chuẩn bị thêm ảnh mới: ".$conn->error);
                foreach ($hinh_anh_urls as $url) {
                    $trimmed_url = trim($url);
                    if (!empty($trimmed_url) && filter_var($trimmed_url, FILTER_VALIDATE_URL)) {
                        $stmt_insert_img->bind_param("is", $room_id_to_update, $trimmed_url);
                         if (!$stmt_insert_img->execute()) {
                            error_log("API_PHONG_PUT_IMG_ERROR: Could not insert URL '$trimmed_url': " . $stmt_insert_img->error);
                        }
                    }
                }
                $stmt_insert_img->close();
            }
        }
        
        $conn->commit();
        echo json_encode(["success" => true, "message" => "Cập nhật phòng thành công."]);

    } catch (Exception $e) {
        $conn->rollback();
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("API_PHONG_PUT_EXCEPTION: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Lỗi khi cập nhật phòng: " . $e->getMessage()]);
    }
}

// DELETE: Xóa phòng (kèm hình ảnh)
elseif ($method === 'DELETE') {
    $room_id_to_delete = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$room_id_to_delete || $room_id_to_delete <= 0) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "ID phòng là bắt buộc và phải hợp lệ trên URL."]); exit();
    }

    $conn->begin_transaction();
    try {
        $stmt_check = $conn->prepare("SELECT id FROM phong WHERE id = ? AND khach_san_id = ?");
        if(!$stmt_check) throw new Exception("Lỗi chuẩn bị kiểm tra phòng (DELETE): " . $conn->error);
        $stmt_check->bind_param("ii", $room_id_to_delete, $hotel_id);
        if(!$stmt_check->execute()) throw new Exception("Lỗi thực thi kiểm tra phòng (DELETE): " . $stmt_check->error);
        $result_check = $stmt_check->get_result();
        if ($result_check->num_rows === 0) {
             $stmt_check->close();
             throw new Exception("Phòng không tồn tại hoặc bạn không có quyền xóa phòng này.", 404);
        }
        $stmt_check->close();
        
        $stmt_delete_img = $conn->prepare("DELETE FROM hinh_anh_phong WHERE phong_id = ?");
        if (!$stmt_delete_img) throw new Exception("Lỗi chuẩn bị xóa ảnh: ".$conn->error);
        $stmt_delete_img->bind_param("i", $room_id_to_delete);
        if (!$stmt_delete_img->execute()) throw new Exception("Lỗi thực thi xóa ảnh: ".$stmt_delete_img->error);
        $stmt_delete_img->close();

        $stmt_delete_phong = $conn->prepare("DELETE FROM phong WHERE id = ?");
        if (!$stmt_delete_phong) throw new Exception("Lỗi chuẩn bị xóa phòng: ".$conn->error);
        $stmt_delete_phong->bind_param("i", $room_id_to_delete);
        if (!$stmt_delete_phong->execute()) throw new Exception("Không thể xóa phòng: " . $stmt_delete_phong->error);
        
        $affected_rows = $stmt_delete_phong->affected_rows;
        $stmt_delete_phong->close();

        if ($affected_rows > 0) {
            $conn->commit();
            echo json_encode(["success" => true, "message" => "Xóa phòng và hình ảnh liên quan thành công."]);
        } else {
             throw new Exception("Không tìm thấy phòng để xóa hoặc đã được xóa.");
        }
    } catch (Exception $e) {
        $conn->rollback();
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("API_PHONG_DELETE_EXCEPTION: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Lỗi khi xóa phòng: " . $e->getMessage()]);
    }
}
else {
    http_response_code(405); 
    echo json_encode(["success" => false, "message" => "Phương thức không được hỗ trợ."]);
}

$conn->close();
?>