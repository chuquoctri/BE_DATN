<?php
// File: API_DATN/API_User/Voucher/get_available_vouchers.php
header("Content-Type: application/json; charset=UTF-8");
require_once '../../../connect.php'; // Đảm bảo đường dẫn chính xác

if (!isset($_GET['nguoi_dung_id'])) {
    echo json_encode(["status" => "error", "message" => "Thiếu ID người dùng."]);
    http_response_code(400);
    exit;
}

$nguoi_dung_id = (int)$_GET['nguoi_dung_id'];
$khach_san_id_param = isset($_GET['khach_san_id']) ? (int)$_GET['khach_san_id'] : null;
$tong_tien_tam_tinh_param = isset($_GET['tong_tien_tam_tinh']) ? (float)$_GET['tong_tien_tam_tinh'] : null;

$current_datetime = date('Y-m-d H:i:s');

// Xây dựng câu lệnh SQL
// Lấy cả voucher chung và voucher người dùng đã lưu (chưa dùng)
$sql = "SELECT
            v.id AS voucher_id,
            v.ma_voucher,
            v.hinh_anh,
            v.mo_ta,
            v.loai_giam,
            v.gia_tri_giam,
            v.ngay_bat_dau,
            v.ngay_ket_thuc,
            v.dieu_kien_don_hang_toi_thieu,
            v.khachsan_id AS voucher_ap_dung_cho_khachsan_id,
            v.so_luong_toi_da AS voucher_tong_so_luong,
            v.so_luong_da_dung AS voucher_tong_da_dung,
            uv.id AS nguoidung_voucher_id,
            uv.da_dung AS nguoidung_voucher_da_dung
        FROM
            voucher v
        LEFT JOIN
            nguoidung_voucher uv ON v.id = uv.voucher_id AND uv.nguoi_dung_id = ?
        WHERE
            v.trang_thai = 'dang_dien_ra'
            AND v.ngay_bat_dau <= ?
            AND v.ngay_ket_thuc >= ?
            AND (v.so_luong_toi_da IS NULL OR v.so_luong_da_dung < v.so_luong_toi_da) -- Voucher tổng còn lượt
            AND (
                -- Điều kiện 1: Voucher người dùng đã lưu và CHƯA SỬ DỤNG
                (uv.id IS NOT NULL AND uv.da_dung = 0)
                OR
                -- Điều kiện 2: Voucher chung (người dùng chưa lưu hoặc không có bản ghi riêng)
                -- và chúng ta sẽ kiểm tra ở PHP sau nếu có quy tắc mỗi người dùng chỉ dùng loại voucher chung 1 lần
                (uv.id IS NULL)
            )
        ";

$params = [$nguoi_dung_id, $current_datetime, $current_datetime];
$types = "iss";

// Thêm điều kiện lọc theo khách sạn
if ($khach_san_id_param !== null) {
    $sql .= " AND (v.khachsan_id IS NULL OR v.khachsan_id = ?)";
    $params[] = $khach_san_id_param;
    $types .= "i";
} else {
    // Nếu không có khach_san_id từ client, chỉ lấy voucher áp dụng cho tất cả khách sạn
    $sql .= " AND v.khachsan_id IS NULL";
}

// Thêm điều kiện lọc theo tổng tiền tạm tính (nếu có)
if ($tong_tien_tam_tinh_param !== null) {
    $sql .= " AND (v.dieu_kien_don_hang_toi_thieu IS NULL OR v.dieu_kien_don_hang_toi_thieu <= ?)";
    $params[] = $tong_tien_tam_tinh_param;
    $types .= "d";
}

$sql .= " ORDER BY v.ngay_ket_thuc ASC, v.gia_tri_giam DESC";

try {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new Exception("Lỗi chuẩn bị câu lệnh SQL: " . $conn->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $available_vouchers = [];
    $processed_voucher_ids = []; // Để xử lý trường hợp voucher chung và voucher đã lưu cùng loại

    while ($row = $result->fetch_assoc()) {
        $voucher_id = $row['voucher_id'];
        $nguoidung_voucher_id = $row['nguoidung_voucher_id']; // ID của bản ghi trong nguoidung_voucher (nếu người dùng đã lưu)

        // Nếu đây là voucher người dùng đã lưu (có nguoidung_voucher_id) và chưa dùng
        if ($nguoidung_voucher_id !== null && $row['nguoidung_voucher_da_dung'] == 0) {
            if (in_array($voucher_id, $processed_voucher_ids)) { // Đã xử lý voucher này từ 1 bản ghi khác (không nên xảy ra nếu cấu trúc DB đúng)
                continue;
            }
            $processed_voucher_ids[] = $voucher_id; // Đánh dấu đã xử lý
        }
        // Nếu là voucher chung (nguoidung_voucher_id is NULL)
        elseif ($nguoidung_voucher_id === null) {
            if (in_array($voucher_id, $processed_voucher_ids)) {
                // Voucher này đã được thêm dưới dạng "voucher người dùng đã lưu", bỏ qua bản ghi chung
                continue;
            }
            // Kiểm tra xem người dùng này đã từng sử dụng LOẠI voucher chung này chưa
            // (Giả định có quy tắc: mỗi người dùng chỉ được dùng MỘT LẦN cho MỖI LOẠI voucher chung)
            // Bạn có thể cần thêm một cột trong bảng `voucher` để đánh dấu "giới hạn 1 lần/người dùng cho voucher chung"
            $stmt_check_general_usage = $conn->prepare("SELECT 1 FROM nguoidung_voucher WHERE nguoi_dung_id = ? AND voucher_id = ? AND da_dung = 1 LIMIT 1");
            $stmt_check_general_usage->bind_param("ii", $nguoi_dung_id, $voucher_id);
            $stmt_check_general_usage->execute();
            $usage_result = $stmt_check_general_usage->get_result();
            if ($usage_result->num_rows > 0) {
                $stmt_check_general_usage->close();
                continue; // Đã dùng loại voucher chung này rồi, bỏ qua
            }
            $stmt_check_general_usage->close();
            // Đánh dấu đã xử lý để không thêm lại nếu có dòng khác (ít khả năng)
            $processed_voucher_ids[] = $voucher_id;
        } else { // Voucher người dùng đã lưu nhưng đã dùng (uv.da_dung = 1), đã được lọc bởi SQL, nhưng check lại cho chắc
            continue;
        }


        $gia_tri_giam_thuc_te = null;
        if ($tong_tien_tam_tinh_param !== null) {
            if ($row['loai_giam'] == 'phan_tram') {
                $gia_tri_giam_thuc_te = ($tong_tien_tam_tinh_param * $row['gia_tri_giam']) / 100;
            } elseif ($row['loai_giam'] == 'tien_mat') {
                $gia_tri_giam_thuc_te = $row['gia_tri_giam'];
            }
            if ($gia_tri_giam_thuc_te !== null) {
                $gia_tri_giam_thuc_te = min($gia_tri_giam_thuc_te, $tong_tien_tam_tinh_param);
                 // Không để số tiền giảm làm tổng tiền âm
                $gia_tri_giam_thuc_te = max(0, $gia_tri_giam_thuc_te);
            }
        }

        $available_vouchers[] = [
            "voucher_id" => (int)$row['voucher_id'],
            "nguoidung_voucher_id" => $nguoidung_voucher_id ? (int)$nguoidung_voucher_id : null,
            "ma_voucher" => $row['ma_voucher'],
            "hinh_anh" => $row['hinh_anh'],
            "mo_ta" => $row['mo_ta'],
            "loai_giam" => $row['loai_giam'],
            "gia_tri_giam_display" => $row['loai_giam'] == 'phan_tram' ? $row['gia_tri_giam'] . "%" : number_format($row['gia_tri_giam']) . " VND",
            "gia_tri_giam_raw" => (float)$row['gia_tri_giam'],
            "gia_tri_giam_du_kien" => $gia_tri_giam_thuc_te !== null ? (float)$gia_tri_giam_thuc_te : null,
            "ngay_bat_dau" => $row['ngay_bat_dau'],
            "ngay_ket_thuc" => $row['ngay_ket_thuc'],
            "dieu_kien_don_hang_toi_thieu" => $row['dieu_kien_don_hang_toi_thieu'] !== null ? (float)$row['dieu_kien_don_hang_toi_thieu'] : null,
            "ap_dung_cho_khachsan_id" => $row['voucher_ap_dung_cho_khachsan_id'] ? (int)$row['voucher_ap_dung_cho_khachsan_id'] : null,
            "is_user_saved" => $nguoidung_voucher_id !== null // Để client biết đây là voucher người dùng đã lưu
        ];
    }

    if (count($available_vouchers) > 0) {
        echo json_encode(["status" => "success", "data" => $available_vouchers]);
    } else {
        echo json_encode(["status" => "success", "message" => "Không tìm thấy voucher phù hợp.", "data" => []]);
    }

    $stmt->close();

} catch (Exception $e) {
    error_log("Get available vouchers error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    echo json_encode(["status" => "error", "message" => "Lỗi hệ thống: " . $e->getMessage()]);
    http_response_code(500);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}
?>