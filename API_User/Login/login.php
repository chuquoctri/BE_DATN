<?php
header("Content-Type: application/json; charset=UTF-8");
require_once '../../connect.php';

$data = json_decode(file_get_contents("php://input"), true);
$email = trim($data['email'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($email) || empty($password)) {
    echo json_encode(["status" => "error", "message" => "Email và mật khẩu không được để trống!"]);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(["status" => "error", "message" => "Email không hợp lệ!"]);
    exit;
}

$stmt = $conn->prepare("SELECT id, ho_ten, email, mat_khau FROM nguoi_dung WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows === 0) {
    echo json_encode(["status" => "error", "message" => "Email hoặc mật khẩu không chính xác!"]);
    exit;
}

$stmt->bind_result($id, $ho_ten, $email, $hashed_password);
$stmt->fetch();

if (!password_verify($password, $hashed_password)) {
    echo json_encode(["status" => "error", "message" => "Email hoặc mật khẩu không chính xác!"]);
    exit;
}

echo json_encode([
    "status" => "success",
    "message" => "Đăng nhập thành công!",
    "data" => [
        "id" => $id,
        "name" => $ho_ten,
        "email" => $email
    ]
]);
?>