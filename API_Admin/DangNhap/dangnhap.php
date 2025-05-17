<?php
require_once '../../connect.php';

// Thiết lập header
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header('Content-Type: application/json');

// Xử lý OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Chỉ xử lý POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit();
}

// Nhận và xử lý dữ liệu đầu vào
$data = json_decode(file_get_contents("php://input"), true);

// Validate dữ liệu đầu vào
if (empty($data['email']) || empty($data['password'])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Email và mật khẩu là bắt buộc"]);
    exit();
}

$email = trim($data['email']);
$password = $data['password'];

try {
    // Truy vấn database
    $stmt = $conn->prepare("SELECT id, ho_ten, email, mat_khau, role, trang_thai FROM nguoi_dung WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    // Kiểm tra tài khoản tồn tại
    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["success" => false, "message" => "Tài khoản không tồn tại"]);
        exit();
    }

    $user = $result->fetch_assoc();

    // Kiểm tra trạng thái tài khoản
    if ($user['trang_thai'] !== 'da_xac_thuc') {
        http_response_code(403);
        echo json_encode(["success" => false, "message" => "Tài khoản chưa được xác thực hoặc đã bị khóa"]);
        exit();
    }

    // Xử lý cả mật khẩu chưa mã hóa (tạm thời) và đã mã hóa
    $passwordMatch = false;
    
    // Kiểm tra nếu mật khẩu chưa mã hóa (chỉ dùng để migrate dữ liệu cũ)
    if ($user['mat_khau'] === $password) {
        $passwordMatch = true;
        // Tự động mã hóa mật khẩu cũ
        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare("UPDATE nguoi_dung SET mat_khau = ? WHERE id = ?");
        $updateStmt->bind_param("si", $hashedPassword, $user['id']);
        $updateStmt->execute();
    } 
    // Kiểm tra mật khẩu đã mã hóa
    else if (password_verify($password, $user['mat_khau'])) {
        $passwordMatch = true;
    }

    if ($passwordMatch) {
        // Tạo response thành công
        $response = [
            "success" => true,
            "user" => [
                "id" => $user['id'],
                "ho_ten" => $user['ho_ten'],
                "email" => $user['email'],
                "role" => $user['role']
            ]
        ];
        
        // Thêm thông tin admin nếu là admin
        if ($user['role'] === 'admin') {
            $response['user']['is_admin'] = true;
        }
        
        echo json_encode($response);
    } else {
        http_response_code(401);
        echo json_encode(["success" => false, "message" => "Sai mật khẩu"]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Lỗi server: " . $e->getMessage()]);
} finally {
    $conn->close();
}
?>