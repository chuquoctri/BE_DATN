<?php
require_once __DIR__ . '/../../connect.php';

$inputData = array();
$returnData = array();

foreach ($_GET as $key => $value) {
    if (substr($key, 0, 4) == "vnp_") {
        $inputData[$key] = $value;
    }
}

$vnp_SecureHash = $inputData['vnp_SecureHash'];
unset($inputData['vnp_SecureHash']);
ksort($inputData);
$i = 0;
$hashData = "";
foreach ($inputData as $key => $value) {
    if ($i == 1) {
        $hashData = $hashData . '&' . urlencode($key) . "=" . urlencode($value);
    } else {
        $hashData = $hashData . urlencode($key) . "=" . urlencode($value);
        $i = 1;
    }
}

$secureHash = hash_hmac('sha512', $hashData, $vnp_HashSecret);
$vnpTranId = $inputData['vnp_TransactionNo'];
$vnp_BankCode = $inputData['vnp_BankCode'];
$vnp_Amount = $inputData['vnp_Amount']/100;

$Status = 0;
$orderId = $inputData['vnp_TxnRef'];

try {
    if ($secureHash == $vnp_SecureHash) {
        // Kiểm tra order trong database của bạn
        $order = NULL; // Thay bằng code truy vấn CSDL của bạn
        
        if ($order != NULL) {
            if($order["Amount"] == $vnp_Amount) {
                if ($order["Status"] != NULL && $order["Status"] == 0) {
                    if ($inputData['vnp_ResponseCode'] == '00' || $inputData['vnp_TransactionStatus'] == '00') {
                        $Status = 1; // Thanh toán thành công
                        // Cập nhật CSDL
                    } else {
                        $Status = 2; // Thanh toán thất bại
                    }
                    $returnData['RspCode'] = '00';
                    $returnData['Message'] = 'Confirm Success';
                } else {
                    $returnData['RspCode'] = '02';
                    $returnData['Message'] = 'Order already confirmed';
                }
            } else {
                $returnData['RspCode'] = '04';
                $returnData['Message'] = 'invalid amount';
            }
        } else {
            $returnData['RspCode'] = '01';
            $returnData['Message'] = 'Order not found';
        }
    } else {
        $returnData['RspCode'] = '97';
        $returnData['Message'] = 'Invalid signature';
    }
} catch (Exception $e) {
    $returnData['RspCode'] = '99';
    $returnData['Message'] = 'Unknow error';
}

echo json_encode($returnData);
?>