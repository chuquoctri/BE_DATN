<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');


$servername = "localhost";
$username = "root";
$password = "";
$dbname = "datn";



$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die(json_encode([
        'status' => 'error',
        'message' => 'Kết nối thất bại: ' . $conn->connect_error
    ]));
}

$danh_muc_id = isset($_GET['danh_muc_id']) ? intval($_GET['danh_muc_id']) : 0;

if ($danh_muc_id <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Thiếu danh_muc_id'
    ]);
    exit();
}

$sql = "SELECT id, ten, hinh_anh FROM thanh_pho WHERE danh_muc_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $danh_muc_id);
$stmt->execute();
$result = $stmt->get_result();

$data = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $data[] = $row;
    }
}

echo json_encode([
    'status' => 'success',
    'data' => $data
]);

$stmt->close();
$conn->close();
?>
