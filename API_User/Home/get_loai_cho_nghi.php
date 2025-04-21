<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$servername = "localhost";  // Thay bằng thông tin của bạn
$username = "root";         // Thay bằng thông tin của bạn
$password = "";              // Thay bằng thông tin của bạn
$dbname = "datn";            // Thay bằng thông tin của bạn

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode(array(
        'status' => 'error',
        'message' => 'Kết nối database thất bại: ' . $conn->connect_error
    )));
}

$sql = "SELECT id, ten, hinh_anh FROM loai_cho_nghi";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    $loaiChoNghi = array();

    while($row = $result->fetch_assoc()) {
        $loaiChoNghi[] = array(
            'id' => $row['id'],
            'ten' => $row['ten'],
            'hinh_anh' => $row['hinh_anh']
        );
    }

    echo json_encode(array(
        'status' => 'success',
        'data' => $loaiChoNghi
    ));
} else {
    echo json_encode(array(
        'status' => 'error',
        'message' => 'Không có dữ liệu nào!'
    ));
}

$conn->close();
?>
