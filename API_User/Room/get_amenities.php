<?php
require_once '../../connect.php';

function respond($status, $data = null, $message = null) {
    echo json_encode(['status' => $status, 'data' => $data, 'message' => $message]);
    exit;
}

$query = "SELECT id, ten, hinh_anh FROM tien_nghi";
$result = $conn->query($query);

if ($result->num_rows > 0) {
    $amenities = [];
    while ($row = $result->fetch_assoc()) {
        $amenities[] = $row;
    }
    respond('success', $amenities);
} else {
    respond('error', null, 'No amenities found');
}
?>