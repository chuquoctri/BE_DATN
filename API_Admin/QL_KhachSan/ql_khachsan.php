<?php
require_once '../../connect.php';

// Thiết lập header
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Xử lý OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Lấy thông tin người dùng từ token hoặc session
function getCurrentUser($conn) {
    // Trong thực tế, bạn sẽ lấy từ JWT token hoặc session
    // Ở đây giả sử user_id được gửi qua header Authorization
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $token = $matches[1];
        // Giải mã token để lấy user_id (trong thực tế)
        // Ở đây giả sử token chính là user_id cho đơn giản
        $user_id = $token;
        
        $stmt = $conn->prepare("SELECT * FROM nguoi_dung WHERE id = ? AND role = 'quan_ly'");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            return $result->fetch_assoc();
        }
    }
    
    return null;
}

// Lấy thông tin khách sạn của quản lý
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $user = getCurrentUser($conn);
        
        if (!$user) {
            http_response_code(401);
            echo json_encode(["success" => false, "message" => "Unauthorized or not a manager"]);
            exit();
        }
        
        if (empty($user['khach_san_id'])) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Manager is not assigned to any hotel"]);
            exit();
        }
        
        $stmt = $conn->prepare("SELECT * FROM khach_san WHERE id = ?");
        $stmt->bind_param("i", $user['khach_san_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $hotel = $result->fetch_assoc();
            echo json_encode(["success" => true, "data" => $hotel]);
        } else {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Hotel not found"]);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
}

// Cập nhật thông tin khách sạn
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    try {
        $user = getCurrentUser($conn);
        
        if (!$user) {
            http_response_code(401);
            echo json_encode(["success" => false, "message" => "Unauthorized or not a manager"]);
            exit();
        }
        
        if (empty($user['khach_san_id'])) {
            http_response_code(404);
            echo json_encode(["success" => false, "message" => "Manager is not assigned to any hotel"]);
            exit();
        }
        
        $data = json_decode(file_get_contents("php://input"), true);
        
        // Validate input data
        $allowedFields = ['ten', 'dia_chi', 'so_dien_thoai', 'email', 'kinh_do', 'vi_do', 'so_sao', 'mo_ta', 'hinh_anh'];
        $updateData = array_intersect_key($data, array_flip($allowedFields));
        
        if (empty($updateData)) {
            http_response_code(400);
            echo json_encode(["success" => false, "message" => "No valid fields to update"]);
            exit();
        }
        
        // Build SQL query
        $setClause = [];
        $params = [];
        $types = '';
        
        foreach ($updateData as $field => $value) {
            $setClause[] = "$field = ?";
            $params[] = $value;
            $types .= is_int($value) ? 'i' : 's';
        }
        
        $params[] = $user['khach_san_id'];
        $types .= 'i';
        
        $sql = "UPDATE khach_san SET " . implode(', ', $setClause) . " WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "message" => "Hotel updated successfully"]);
        } else {
            throw new Exception("Failed to update hotel");
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => $e->getMessage()]);
    }
}

$conn->close();
?>