<?php
// File: vnpay_return_handler.php
header('Content-Type: text/html; charset=UTF-8');

// === Cấu hình VNPAY ===
$vnp_HashSecret = "GW3X067U08UFH4BVHGBWLK1JX89LJX6X"; // Chuỗi bí mật của bạn

// === PHẦN LOGIC PHP XỬ LÝ KẾT QUẢ VNPAY (GIỮ NGUYÊN NHƯ CỦA BẠN) ===
$queryParams = $_GET;
$vnp_SecureHash_received = $queryParams['vnp_SecureHash'] ?? '';

if (isset($queryParams['vnp_SecureHashType'])) {
    unset($queryParams['vnp_SecureHashType']);
}
if (isset($queryParams['vnp_SecureHash'])) {
    unset($queryParams['vnp_SecureHash']);
}
ksort($queryParams);

$hashDataString = "";
$i = 0;
foreach ($queryParams as $key => $value) {
    $valueString = is_array($value) ? json_encode($value) : (string)$value;
    if ($i == 1) {
        $hashDataString .= '&' . urlencode((string)$key) . "=" . urlencode($valueString);
    } else {
        $hashDataString .= urlencode((string)$key) . "=" . urlencode($valueString);
        $i = 1;
    }
}
$calculatedSecureHash = hash_hmac('sha512', $hashDataString, $vnp_HashSecret);

$outcome = 'failure'; // Mặc định
$orderId = $queryParams['vnp_TxnRef'] ?? null;
$responseCode = $queryParams['vnp_ResponseCode'] ?? '99';
$messageToUser = "Giao dịch không thành công hoặc đã bị hủy. Vui lòng thử lại."; // Thông báo mặc định

if (hash_equals($calculatedSecureHash, $vnp_SecureHash_received)) {
    if ($responseCode == '00') {
        // ================================================================================
        // === QUAN TRỌNG: BẠN CẦN THÊM LOGIC CẬP NHẬT DATABASE THỰC TẾ Ở ĐÂY ===
        // Ví dụ: updateOrderStatusInDB($orderId, 'completed', $queryParams);
        // Chỉ sau khi cập nhật DB thành công thì mới $outcome = 'success';
        // ================================================================================
        $outcome = 'success'; // Giả định cập nhật DB thành công cho ví dụ
        $messageToUser = "Thanh toán thành công cho đơn hàng " . htmlspecialchars($orderId) . "! Cảm ơn bạn đã tin tưởng dịch vụ của chúng tôi.";
    } else {
        // $messageToUser đã được đặt ở trên, có thể thêm chi tiết mã lỗi nếu muốn
        $messageToUser = "Thanh toán không thành công. Mã lỗi VNPAY: " . htmlspecialchars($responseCode) . ". Vui lòng thử lại hoặc liên hệ hỗ trợ.";
    }
} else {
    $outcome = 'invalid_signature';
    $messageToUser = "Lỗi xác thực chữ ký. Giao dịch không đáng tin cậy. Vui lòng liên hệ bộ phận hỗ trợ.";
    error_log("VNPay Invalid Signature (Return URL): Received=" . $vnp_SecureHash_received . ", Calculated=" . $calculatedSecureHash . ", Data=" . $hashDataString);
}

$dataForReactNative = [
    'vnpay_event' => 'payment_result',
    'outcome' => $outcome,
    'message' => $messageToUser, // Thông điệp đã được chuẩn bị
    'orderId' => $orderId,
    'responseCode' => $responseCode,
    'vnp_Amount' => isset($queryParams['vnp_Amount']) ? ((int)$queryParams['vnp_Amount'] / 100) : null,
    'vnp_BankCode' => $queryParams['vnp_BankCode'] ?? null,
    'vnp_TransactionNo' => $queryParams['vnp_TransactionNo'] ?? null,
    'vnp_PayDate' => $queryParams['vnp_PayDate'] ?? null,
    'vnp_OrderInfo' => $queryParams['vnp_OrderInfo'] ?? null,
];
// === KẾT THÚC PHẦN LOGIC PHP ===
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no" />
    <title>Kết Quả Thanh Toán</title>
    <style>
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "Noto Sans", sans-serif;
            background-color: #f8f9fa; /* Màu nền sáng, nhẹ nhàng */
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
            color: #212529; /* Màu chữ chính */
            overflow: hidden;
        }
        .status-wrapper { /* Thêm một lớp bọc để dễ dàng tạo hiệu ứng */
            opacity: 0;
            transform: scale(0.95);
            animation: fadeInScaleUp 0.5s 0.1s ease-out forwards; /* Delay nhẹ để tránh giật cục */
        }
        .status-container {
            padding: 30px 25px;
            background-color: #ffffff;
            border-radius: 12px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.07);
            max-width: 90%;
            width: 330px;
            box-sizing: border-box;
        }

        @keyframes fadeInScaleUp {
            to {
                opacity: 1;
                transform: scale(1);
            }
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .status-icon-placeholder { /* Placeholder cho icon và loader */
            width: 60px;
            height: 60px;
            margin: 0 auto 20px auto;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .status-icon-placeholder .loader {
            border: 4px solid #e9ecef;
            border-top-color: #007bff; /* Màu primary */
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
        }
        .status-icon-placeholder .icon-display {
            font-size: 48px; /* Kích thước icon text */
            line-height: 1;
        }
        .icon-success-color { color: #28a745; }
        .icon-failure-color { color: #dc3545; }

        .status-title {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 10px;
        }
        .status-message {
            font-size: 15px;
            color: #495057;
            line-height: 1.6;
            margin-bottom: 25px;
            word-wrap: break-word;
        }
        .status-note {
            font-size: 12px;
            color: #6c757d;
            margin-top: 15px;
        }
    </style>
</head>
<body>
    <div class="status-wrapper">
        <div class="status-container">
            <div id="iconPlaceholder" class="status-icon-placeholder">
                <div class="loader"></div> </div>
            <h1 id="statusTitle" class="status-title">Đang xử lý giao dịch...</h1>
            <p id="statusMessage" class="status-message">Chúng tôi đang kiểm tra thông tin thanh toán của bạn. Vui lòng chờ trong giây lát.</p>
            <p class="status-note">Ứng dụng sẽ tự động cập nhật kết quả.</p>
        </div>
    </div>

    <script type="text/javascript">
        const paymentData = <?php echo json_encode($dataForReactNative, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        function updatePageElements() {
            const iconPlaceholder = document.getElementById('iconPlaceholder');
            const titleElement = document.getElementById('statusTitle');
            const messageElement = document.getElementById('statusMessage');

            // Xóa loader hiện tại
            iconPlaceholder.innerHTML = ''; 
            let iconDisplay = document.createElement('span');
            iconDisplay.className = 'icon-display';

            if (paymentData.outcome === 'success') {
                iconDisplay.innerHTML = '&#10004;'; // Checkmark
                iconDisplay.classList.add('icon-success-color');
                titleElement.textContent = 'Thanh Toán Thành Công!';
                titleElement.style.color = '#28a745';
            } else { // 'failure' or 'invalid_signature'
                iconDisplay.innerHTML = '&#10008;'; // Cross mark
                iconDisplay.classList.add('icon-failure-color');
                titleElement.textContent = 'Thanh Toán Thất Bại';
                titleElement.style.color = '#dc3545';
            }
            iconPlaceholder.appendChild(iconDisplay);
            messageElement.textContent = paymentData.message; // Cập nhật message từ PHP
        }
        
        function sendDataToReactNative() {
            updatePageElements(); // Cập nhật giao diện HTML trước khi gửi postMessage

            if (window.ReactNativeWebView && window.ReactNativeWebView.postMessage) {
                console.log("PHP Return URL: Posting message to RN:", JSON.stringify(paymentData));
                window.ReactNativeWebView.postMessage(JSON.stringify(paymentData));
            } else {
                console.error("PHP Return URL: ReactNativeWebView.postMessage is NOT available. Data:", paymentData);
                // document.body.innerHTML = '<p style="text-align:center;color:red;">Lỗi: Không thể giao tiếp với ứng dụng.</p>';
            }
        }
        // Chạy khi DOM đã sẵn sàng để tránh lỗi không tìm thấy element
        document.addEventListener("DOMContentLoaded", sendDataToReactNative);
    </script>
</body>
</html>
<?php
exit();
?>