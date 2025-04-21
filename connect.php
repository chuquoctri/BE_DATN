<?php
// Cấu hình thông tin kết nối
$servername = "localhost";  // Tên server hoặc địa chỉ IP của máy chủ MySQL
$username = "root";         // Tên người dùng MySQL
$password = "";             // Mật khẩu cho người dùng MySQL
$dbname = "datn";  // Tên cơ sở dữ liệu bạn muốn kết nối

// Tạo kết nối với cơ sở dữ liệu
$conn = new mysqli($servername, $username, $password, $dbname);

// Kiểm tra kết nối
if ($conn->connect_error) {
    die("Kết nối thất bại: " . $conn->connect_error);
}

// Nếu kết nối thành công, không cần thực hiện hành động nào
?>
