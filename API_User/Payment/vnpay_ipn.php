<?php
// File: vnpay_ipn.php
require_once '../../connect.php'; // Đảm bảo đường dẫn kết nối CSDL là chính xác
date_default_timezone_set('Asia/Ho_Chi_Minh');

// Hàm ghi log, bạn đã có sẵn
$log_file = __DIR__ . '/vnpay_ipn_debug_log.txt';
function write_log($message) {
    global $log_file;
    $timestamp = date("Y-m-d H:i:s");
    file_put_contents($log_file, "[{$timestamp}] " . $message . "\n", FILE_APPEND);
}

write_log("------ IPN REQUEST RECEIVED ------");
write_log("GET Data: " . json_encode($_GET));

// === Cấu hình VNPAY với thông tin MỚI NHẤT ===
$vnp_HashSecret = "GW3X067U08UFH4BVHGBWLK1JX89LJX6X"; // << CHUỖI BÍ MẬT MỚI NHẤT (Sử dụng chuỗi bí mật của bạn)

$inputData = array();
$returnData = array();

// Lấy dữ liệu từ VNPAY gửi sang
foreach ($_GET as $key => $value) {
    if (substr($key, 0, 4) == "vnp_") {
        $inputData[$key] = $value;
    }
}

$vnp_SecureHash_received = $inputData['vnp_SecureHash'] ?? '';

if (isset($inputData['vnp_SecureHashType'])) {
    unset($inputData['vnp_SecureHashType']);
}
if (isset($inputData['vnp_SecureHash'])) {
    unset($inputData['vnp_SecureHash']);
}
ksort($inputData);

$hashDataString = "";
$i = 0;
foreach ($inputData as $key => $value) {
    if ($i == 1) {
        $hashDataString .= '&' . urlencode((string)$key) . "=" . urlencode((string)$value);
    } else {
        $hashDataString .= urlencode((string)$key) . "=" . urlencode((string)$value);
        $i = 1;
    }
}

$calculatedSecureHash = hash_hmac('sha512', $hashDataString, $vnp_HashSecret);
write_log("Calculated Hash: " . $calculatedSecureHash);
write_log("Received Hash: " . $vnp_SecureHash_received);

if (hash_equals($calculatedSecureHash, $vnp_SecureHash_received)) {
    write_log("IPN Signature VALID.");
    $payment_database_id = $inputData['vnp_TxnRef'] ?? null;
    $vnp_ResponseCode = $inputData['vnp_ResponseCode'] ?? '99';
    $vnp_TransactionNo = $inputData['vnp_TransactionNo'] ?? null;
    $vnp_Amount_from_vnpay = isset($inputData['vnp_Amount']) ? ((int)$inputData['vnp_Amount'] / 100) : null;
    $vnp_PayDate_str = $inputData['vnp_PayDate'] ?? null;

    write_log("Payment DB ID (vnp_TxnRef): " . ($payment_database_id ?? 'NULL'));
    write_log("VNPAY Response Code: " . $vnp_ResponseCode);
    write_log("VNPAY Amount Received: " . ($vnp_Amount_from_vnpay ?? 'NULL'));

    $conn->begin_transaction();
    try {
        $stmtCheck = $conn->prepare("SELECT id, nguoi_dung_id, tong_tien, trang_thai, voucher_id, nguoidung_voucher_id FROM thanh_toan WHERE id = ?");
        if (!$stmtCheck) throw new Exception("Lỗi chuẩn bị (kiểm tra thanh toán): " . $conn->error);
        $stmtCheck->bind_param("i", $payment_database_id);
        $stmtCheck->execute();
        $resultCheck = $stmtCheck->get_result();
        $paymentRecord = $resultCheck->fetch_assoc();
        $stmtCheck->close();

        if ($paymentRecord) {
            write_log("Payment record found in DB: " . json_encode($paymentRecord));

            if ($vnp_ResponseCode == '00' && (abs((float)$paymentRecord['tong_tien'] - (float)$vnp_Amount_from_vnpay) > 0.001)) {
                write_log("AMOUNT MISMATCH. DB: {$paymentRecord['tong_tien']}, VNPAY: {$vnp_Amount_from_vnpay}");
                $returnData['RspCode'] = '04';
                $returnData['Message'] = 'Invalid amount';
                if ($paymentRecord['trang_thai'] == 'pending') {
                    $stmtFail = $conn->prepare("UPDATE thanh_toan SET trang_thai = 'failed', vnp_response_code = 'AMT_MISMATCH', vnp_transaction_no = ? WHERE id = ?");
                    if ($stmtFail) {
                        $stmtFail->bind_param("si", $vnp_TransactionNo, $payment_database_id);
                        $stmtFail->execute();
                        if ($stmtFail->affected_rows > 0) write_log("Updated thanh_toan to failed due to amount mismatch.");
                        $stmtFail->close();
                    }
                }
            } elseif ($paymentRecord['trang_thai'] == 'completed') {
                write_log("Payment already marked as completed.");
                $returnData['RspCode'] = '02';
                $returnData['Message'] = 'Order already confirmed';
            } elseif ($paymentRecord['trang_thai'] == 'pending') {
                write_log("Payment status is pending. Processing update...");
                if ($vnp_ResponseCode == '00') {
                    write_log("VNPAY reported SUCCESS (00).");
                    $sqlNgayThanhToan = null;
                    if ($vnp_PayDate_str) {
                        $dateTime = DateTime::createFromFormat('YmdHis', $vnp_PayDate_str);
                        if ($dateTime) $sqlNgayThanhToan = $dateTime->format('Y-m-d H:i:s');
                    }
                    if (!$sqlNgayThanhToan) $sqlNgayThanhToan = date('Y-m-d H:i:s');
                    write_log("Ngày thanh toán sẽ được lưu vào CSDL: " . $sqlNgayThanhToan);

                    $stmtUpdatePayment = $conn->prepare("UPDATE thanh_toan SET trang_thai = 'completed', ngay_thanh_toan = ?, vnp_response_code = ?, vnp_transaction_no = ? WHERE id = ?");
                    if (!$stmtUpdatePayment) throw new Exception("Lỗi chuẩn bị (cập nhật thanh_toan): " . $conn->error);
                    $stmtUpdatePayment->bind_param("sssi", $sqlNgayThanhToan, $vnp_ResponseCode, $vnp_TransactionNo, $payment_database_id);
                    $stmtUpdatePayment->execute();
                    $affected_payment_rows = $stmtUpdatePayment->affected_rows;
                    write_log("Updated thanh_toan: " . $affected_payment_rows . " row(s).");
                    $stmtUpdatePayment->close();

                    if ($affected_payment_rows > 0) {
                        $stmtUpdateBooking = $conn->prepare(
                            "UPDATE dat_phong dp 
                             JOIN chi_tiet_thanh_toan ctt ON dp.id = ctt.dat_phong_id 
                             SET dp.trang_thai_thanh_toan = 'da_thanh_toan' 
                             WHERE ctt.thanh_toan_id = ?"
                        );
                        if (!$stmtUpdateBooking) throw new Exception("Lỗi chuẩn bị (cập nhật dat_phong): " . $conn->error);
                        $stmtUpdateBooking->bind_param("i", $payment_database_id);
                        $stmtUpdateBooking->execute();
                        write_log("Updated dat_phong: " . $stmtUpdateBooking->affected_rows . " row(s).");
                        $stmtUpdateBooking->close();

                        $nguoidungVoucherIdToUpdate = $paymentRecord['nguoidung_voucher_id'] ?? null;
                        $voucherIdToUpdate = $paymentRecord['voucher_id'] ?? null;

                        if ($nguoidungVoucherIdToUpdate && $voucherIdToUpdate) {
                            write_log("Processing voucher: nguoidung_voucher_id={$nguoidungVoucherIdToUpdate}, voucher_id={$voucherIdToUpdate}");
                            $stmtUpdateNguoiDungVoucher = $conn->prepare("UPDATE nguoidung_voucher SET da_dung = 1, thoi_gian_dung = ? WHERE id = ? AND nguoi_dung_id = ? AND voucher_id = ? AND (da_dung = 0 OR da_dung IS NULL)");
                            if (!$stmtUpdateNguoiDungVoucher) throw new Exception("Lỗi chuẩn bị (cập nhật nguoidung_voucher): " . $conn->error);
                            $stmtUpdateNguoiDungVoucher->bind_param("siii", $sqlNgayThanhToan, $nguoidungVoucherIdToUpdate, $paymentRecord['nguoi_dung_id'], $voucherIdToUpdate);
                            $stmtUpdateNguoiDungVoucher->execute();
                            $affected_nd_voucher_rows = $stmtUpdateNguoiDungVoucher->affected_rows;
                            write_log("Updated nguoidung_voucher (id: {$nguoidungVoucherIdToUpdate}): {$affected_nd_voucher_rows} row(s).");
                            $stmtUpdateNguoiDungVoucher->close();

                            if ($affected_nd_voucher_rows > 0) {
                                $stmtUpdateVoucher = $conn->prepare("UPDATE voucher SET so_luong_da_dung = so_luong_da_dung + 1 WHERE id = ? AND (so_luong_toi_da IS NULL OR so_luong_da_dung < so_luong_toi_da)");
                                if (!$stmtUpdateVoucher) throw new Exception("Lỗi chuẩn bị (cập nhật voucher): " . $conn->error);
                                $stmtUpdateVoucher->bind_param("i", $voucherIdToUpdate);
                                $stmtUpdateVoucher->execute();
                                write_log("Updated voucher (id: {$voucherIdToUpdate}) so_luong_da_dung: " . $stmtUpdateVoucher->affected_rows . " row(s).");
                                $stmtUpdateVoucher->close();
                            }
                        }

                        // ==================================================================
                        // === BẮT ĐẦU PHẦN TẠO QUYỀN ĐÁNH GIÁ (ĐÃ CẬP NHẬT SQL) ===
                        // ==================================================================
                        write_log("Bắt đầu tạo quyền đánh giá cho payment_id: {$payment_database_id}");

                        // CẬP NHẬT SQL: JOIN thêm bảng phong để lấy khach_san_id
                        $sqlGetBookingsForReview = "SELECT dp.id AS ma_dat_phong_id, p.khach_san_id
                                                    FROM chi_tiet_thanh_toan ctt
                                                    JOIN dat_phong dp ON ctt.dat_phong_id = dp.id
                                                    JOIN phong p ON dp.phong_id = p.id 
                                                    WHERE ctt.thanh_toan_id = ?";
                        $stmtGetBookings = $conn->prepare($sqlGetBookingsForReview);
                        if (!$stmtGetBookings) {
                            throw new Exception("Lỗi chuẩn bị (lấy chi tiết đặt phòng để tạo quyền đánh giá): " . $conn->error);
                        }
                        $stmtGetBookings->bind_param("i", $payment_database_id);
                        $stmtGetBookings->execute();
                        $bookingsResult = $stmtGetBookings->get_result();
                        
                        $bookings_to_grant_review = [];
                        while($bookingRow = $bookingsResult->fetch_assoc()){
                            $bookings_to_grant_review[] = $bookingRow;
                        }
                        $stmtGetBookings->close();

                        if (!empty($bookings_to_grant_review)) {
                            $sqlInsertQuyenDanhGia = "INSERT INTO quyen_danh_gia (nguoi_dung_id, khach_san_id, ma_dat_phong_id) VALUES (?, ?, ?)";
                            $stmtInsertQuyen = $conn->prepare($sqlInsertQuyenDanhGia);
                            if (!$stmtInsertQuyen) {
                                throw new Exception("Lỗi chuẩn bị (tạo quyền đánh giá): " . $conn->error);
                            }

                            $nguoiDungThanhToanId = $paymentRecord['nguoi_dung_id'];

                            foreach ($bookings_to_grant_review as $booking_info) {
                                $khachSanIdForReview = $booking_info['khach_san_id'];
                                $maDatPhongIdForReview = $booking_info['ma_dat_phong_id'];

                                if ($khachSanIdForReview && $maDatPhongIdForReview) {
                                    $checkExistSql = "SELECT id FROM quyen_danh_gia WHERE nguoi_dung_id = ? AND khach_san_id = ? AND ma_dat_phong_id = ?";
                                    $stmtCheckExist = $conn->prepare($checkExistSql);
                                    if (!$stmtCheckExist) throw new Exception("Lỗi chuẩn bị (kiểm tra quyền đánh giá tồn tại): " . $conn->error);
                                    
                                    $stmtCheckExist->bind_param("iii", $nguoiDungThanhToanId, $khachSanIdForReview, $maDatPhongIdForReview);
                                    $stmtCheckExist->execute();
                                    $stmtCheckExist->store_result();

                                    if ($stmtCheckExist->num_rows == 0) {
                                        $stmtInsertQuyen->bind_param("iii", $nguoiDungThanhToanId, $khachSanIdForReview, $maDatPhongIdForReview);
                                        if ($stmtInsertQuyen->execute()) {
                                            write_log("Đã tạo quyền đánh giá cho ma_dat_phong_id: {$maDatPhongIdForReview}, khach_san_id: {$khachSanIdForReview}, nguoi_dung_id: {$nguoiDungThanhToanId}");
                                        } else {
                                            write_log("LỖI khi tạo quyền đánh giá cho ma_dat_phong_id {$maDatPhongIdForReview}: " . $stmtInsertQuyen->error);
                                        }
                                    } else {
                                        write_log("Quyền đánh giá đã tồn tại cho ma_dat_phong_id: {$maDatPhongIdForReview}, khach_san_id: {$khachSanIdForReview}, nguoi_dung_id: {$nguoiDungThanhToanId}. Bỏ qua.");
                                    }
                                    $stmtCheckExist->close();
                                } else {
                                    write_log("Bỏ qua tạo quyền đánh giá do thiếu khachSanId hoặc maDatPhongId cho một booking trong payment_id: {$payment_database_id}. Booking Info: " . json_encode($booking_info));
                                }
                            }
                            $stmtInsertQuyen->close();
                        } else {
                            write_log("Không tìm thấy đặt phòng nào hợp lệ để tạo quyền đánh giá cho payment_id: {$payment_database_id}");
                        }
                        // ==================================================================
                        // === KẾT THÚC PHẦN TẠO QUYỀN ĐÁNH GIÁ ===
                        // ==================================================================
                    }
                    
                    $returnData['RspCode'] = '00';
                    $returnData['Message'] = 'Confirm Success';

                } else {
                    write_log("VNPAY reported FAILURE ({$vnp_ResponseCode}).");
                    $stmtUpdatePaymentFail = $conn->prepare("UPDATE thanh_toan SET trang_thai = 'failed', vnp_response_code = ?, vnp_transaction_no = ? WHERE id = ?");
                    if (!$stmtUpdatePaymentFail) throw new Exception("Lỗi chuẩn bị (cập nhật thanh_toan thất bại): " . $conn->error);
                    $stmtUpdatePaymentFail->bind_param("ssi", $vnp_ResponseCode, $vnp_TransactionNo, $payment_database_id);
                    $stmtUpdatePaymentFail->execute();
                    write_log("Updated thanh_toan to failed: " . $stmtUpdatePaymentFail->affected_rows . " row(s).");
                    $stmtUpdatePaymentFail->close();

                    $returnData['RspCode'] = '00'; 
                    $returnData['Message'] = 'Confirm Success (transaction on VNPAY side failed with code ' . $vnp_ResponseCode . ')';
                }
            } else {
                write_log("Payment status is NOT pending ('{$paymentRecord['trang_thai']}'). No update performed by IPN.");
                if ($paymentRecord['trang_thai'] == 'completed') {
                    $returnData['RspCode'] = '02'; 
                    $returnData['Message'] = 'Order already confirmed';
                } elseif ($paymentRecord['trang_thai'] == 'failed') {
                    $returnData['RspCode'] = '00'; 
                    $returnData['Message'] = 'Confirm Success (transaction previously failed and recorded)';
                } else {
                    $returnData['RspCode'] = '99'; 
                    $returnData['Message'] = 'Order status not eligible for update (' . $paymentRecord['trang_thai'] . ')';
                }
            }
        } else {
            write_log("Payment record NOT FOUND in DB for ID (vnp_TxnRef): " . ($payment_database_id ?? 'NULL'));
            $returnData['RspCode'] = '01';
            $returnData['Message'] = 'Order not found';
        }
        $conn->commit();
        write_log("Transaction CSDL đã được commit.");
    } catch (Exception $e) {
        $conn->rollback();
        write_log("DATABASE EXCEPTION KHI XỬ LÝ IPN: " . $e->getMessage() . " - Input Data: " . json_encode($inputData));
        $returnData['RspCode'] = '99'; 
        $returnData['Message'] = 'System Error on Merchant Side';
    }
} else {
    write_log("Chữ ký IPN KHÔNG HỢP LỆ.");
    $returnData['RspCode'] = '97';
    $returnData['Message'] = 'Invalid Signature';
}

write_log("Phản hồi cuối cùng gửi cho VNPAY: " . json_encode($returnData));
write_log("------ KẾT THÚC XỬ LÝ YÊU CẦU IPN ------\n");

echo json_encode($returnData);
?>