<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . '/../../connect.php';

// Kiểm tra kết nối
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed: ' . $conn->connect_error]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);
if (json_last_error() !== JSON_ERROR_NONE || !$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON data']);
    exit;
}

// Validate
$errors = [];
if (!isset($data['amount']) || !is_numeric($data['amount']) || $data['amount'] <= 0) {
    $errors[] = 'Invalid amount';
}
if (!isset($data['bookingIds']) || !is_array($data['bookingIds']) || empty($data['bookingIds'])) {
    $errors[] = 'Invalid booking IDs';
}
if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

// VNPay config
$vnp_TmnCode = "8EOODV9E";
$vnp_HashSecret = "EXNKZPY43XB2YLYRZTJSMQB6OGRBB7K5";
$vnp_Url = "https://sandbox.vnpayment.vn/paymentv2/vpcpay.html";
$vnp_Returnurl = 'https://take-exhibit-creates-theft.trycloudflare.com/vnpay_return.php';

$vnp_TxnRef = date("YmdHis") . rand(1000, 9999);
$vnp_OrderInfo = 'Thanh toán đơn đặt phòng';
$vnp_Amount = (int)($data['amount'] * 100);
$vnp_IpAddr = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$vnp_CreateDate = date('YmdHis');
$vnp_ExpireDate = date('YmdHis', strtotime('+15 minutes'));

$inputData = [  
    "vnp_Version" => "2.1.0",
    "vnp_TmnCode" => $vnp_TmnCode,
    "vnp_Amount" => $vnp_Amount,
    "vnp_Command" => "pay",
    "vnp_CreateDate" => $vnp_CreateDate,
    "vnp_CurrCode" => "VND",
    "vnp_IpAddr" => $vnp_IpAddr,
    "vnp_Locale" => "vn",
    "vnp_OrderInfo" => $vnp_OrderInfo,
    "vnp_OrderType" => "other",
    "vnp_ReturnUrl" => $vnp_Returnurl,
    "vnp_TxnRef" => $vnp_TxnRef,
    "vnp_ExpireDate" => $vnp_ExpireDate
];

ksort($inputData);
$hashData = http_build_query($inputData, '', '&', PHP_QUERY_RFC3986);
$vnpSecureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);
$vnp_Url .= "?" . $hashData . "&vnp_SecureHash=" . $vnpSecureHash;

// Lưu database
try {
    $bookingIds = implode(',', array_map('intval', $data['bookingIds']));
    $stmt = $conn->prepare("INSERT INTO payments (transaction_ref, amount, booking_ids, status, created_at) VALUES (?, ?, ?, 'pending', NOW())");
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    $stmt->bind_param("sis", $vnp_TxnRef, $vnp_Amount, $bookingIds);
    if (!$stmt->execute()) {
        throw new Exception("Execute failed: " . $stmt->error);
    }

    echo json_encode([
        'success' => true,
        'paymentUrl' => $vnp_Url,
        'transactionRef' => $vnp_TxnRef,
        'amount' => $data['amount']
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    error_log('Payment error: ' . $e->getMessage());
}
