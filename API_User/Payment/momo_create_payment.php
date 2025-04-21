<?php
header('Content-Type: application/json');

// Lấy dữ liệu từ client gửi lên
$data = json_decode(file_get_contents("php://input"), true);

// Kiểm tra dữ liệu đầu vào
if (!isset($data['amount']) || !isset($data['userId'])) {
    echo json_encode(['error' => 'Thiếu thông tin']);
    exit;
}

// Cấu hình thông tin tài khoản MoMo (Test credentials)
$endpoint = "https://test-payment.momo.vn/v2/gateway/api/create";
$partnerCode = "MOMO";
$accessKey = "F8BBA842ECF85";
$secretKey = "K951B6PE1waDMi640xX08PD3vg6EkVlz";

// Tạo các thông tin cần thiết
$orderId = time() . "";
$requestId = time() . "";
$orderInfo = "Thanh toán đặt phòng khách sạn";
$amount = $data['amount'];

// Đặt lại redirect URL dùng để test (nếu bạn dùng ngrok, đổi domain ở đây)
$redirectUrl = "https://your-ngrok-domain.ngrok.io/thankyou.html";
$ipnUrl = "https://your-ngrok-domain.ngrok.io/momo-ipn.php"; 

$extraData = "";

// Tạo chữ ký
$rawHash = "accessKey=$accessKey&amount=$amount&extraData=$extraData&ipnUrl=$ipnUrl&orderId=$orderId&orderInfo=$orderInfo&partnerCode=$partnerCode&redirectUrl=$redirectUrl&requestId=$requestId&requestType=captureWallet";
$signature = hash_hmac("sha256", $rawHash, $secretKey);

// Tạo body request
$data_request = array(
    'partnerCode' => $partnerCode,
    'accessKey' => $accessKey,
    'requestId' => $requestId,
    'amount' => $amount,
    'orderId' => $orderId,
    'orderInfo' => $orderInfo,
    'redirectUrl' => $redirectUrl,
    'ipnUrl' => $ipnUrl,
    'extraData' => $extraData,
    'requestType' => "captureWallet",
    'signature' => $signature,
    'lang' => 'vi'
);

// Gửi request đến MoMo
$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data_request));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    echo json_encode(['error' => 'Lỗi CURL: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}

curl_close($ch);

$result = json_decode($response, true);

// Trả về kết quả
if (isset($result['payUrl'])) {
    echo json_encode(['payUrl' => $result['payUrl']]);
} else {
    echo json_encode(['error' => 'Không thể tạo thanh toán MoMo', 'debug' => $result]);
}
