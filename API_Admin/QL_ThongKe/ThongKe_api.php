<?php
// Để debug, bạn có thể bật các dòng này và kiểm tra file log lỗi PHP của server
// error_reporting(E_ALL);
// ini_set('display_errors', 1);
// ini_set('log_errors', 1);    
// ini_set('error_log', '/path/to/your/php-error.log'); 

require_once '../../connect.php'; // Đảm bảo đường dẫn này chính xác!

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

header('Content-Type: application/json; charset=UTF-8'); // Chỉ đặt sau khi xử lý OPTIONS

function getCurrentUser($conn) {
    $headers = [];
    if (function_exists('getallheaders')) { $headers = getallheaders(); } 
    else { foreach ($_SERVER as $name => $value) { if (substr($name, 0, 5) == 'HTTP_') { $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value; } } }
    $authHeader = $headers['Authorization'] ?? '';
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $user_id_str = $matches[1];
        if (!is_numeric($user_id_str)) { error_log("ThongKe API: User ID from token is not numeric: '$user_id_str'"); return null; }
        $user_id = intval($user_id_str);
        $stmt = $conn->prepare("SELECT id, role, khach_san_id FROM nguoi_dung WHERE id = ? AND (role = 'quan_ly' OR role = 'admin')");
        if ($stmt === false) { error_log("ThongKe API getCurrentUser Prepare failed: " . $conn->error); return null; }
        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) { error_log("ThongKe API getCurrentUser Execute failed: " . $stmt->error); $stmt->close(); return null; }
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $userData = $result->fetch_assoc();
            if ($userData['role'] === 'quan_ly' && empty($userData['khach_san_id'])) {
                error_log("ThongKe API: Manager user " . $userData['id'] . " has no khach_san_id assigned.");
                $stmt->close(); return null; 
            }
            $stmt->close();
            return $userData; 
        }
        $stmt->close();
        error_log("ThongKe API: User ID " . $user_id . " not found or not manager/admin.");
    } else {
        error_log("ThongKe API: Bearer token not found or invalid format in Authorization header: " . $authHeader);
    }
    return null;
}

if (!$conn) { http_response_code(503); echo json_encode(["success" => false, "message" => "Lỗi kết nối cơ sở dữ liệu."]); exit(); }

$user = getCurrentUser($conn);
if (!$user) { http_response_code(401); echo json_encode(["success" => false, "message" => "Xác thực thất bại. Yêu cầu token hợp lệ."]); exit(); }

$hotel_id_cua_quan_ly = $user['khach_san_id'] ?? null;
if (empty($hotel_id_cua_quan_ly) && $user['role'] !== 'super_admin_global_access') { 
    http_response_code(403); echo json_encode(["success" => false, "message" => "Không xác định được khách sạn để xem thống kê. Người dùng quản lý cần được gán khách sạn, hoặc admin cần quyền truy cập toàn cục."]); exit();
}
if ($hotel_id_cua_quan_ly) { $hotel_id_cua_quan_ly = intval($hotel_id_cua_quan_ly); }


$action = $_GET['action'] ?? '';
$start_date_str = $_GET['start_date'] ?? null; 
$end_date_str = $_GET['end_date'] ?? null;   
$year_param = isset($_GET['year']) && filter_var($_GET['year'], FILTER_VALIDATE_INT) ? (int)$_GET['year'] : date('Y'); // Mặc định năm hiện tại

// Xây dựng điều kiện ngày tháng và params một cách linh hoạt
function buildDateConditionAndParams($table_alias, $date_column_name, $start_date_str, $end_date_str) {
    $conditions = "";
    $params = [];
    $types = "";
    if ($start_date_str && $end_date_str) {
        if (preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $start_date_str) && 
            preg_match("/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/", $end_date_str)) {
            $conditions = " AND {$table_alias}.{$date_column_name} BETWEEN ? AND ? ";
            $params = [$start_date_str, $end_date_str];
            $types = "ss";
        } else {
            error_log("ThongKe_api: Invalid date format received: Start: $start_date_str, End: $end_date_str");
            // Có thể throw Exception hoặc trả về lỗi nếu muốn ngày tháng là bắt buộc khi có
        }
    }
    return ['conditions' => $conditions, 'params' => $params, 'types' => $types];
}

$date_filter_tt = buildDateConditionAndParams('tt', 'ngay_thanh_toan', $start_date_str, $end_date_str);
$date_filter_dp_tao = buildDateConditionAndParams('dp', 'ngay_tao', $start_date_str, $end_date_str);


try {
    $response_data = [];
    
    if ($action === 'tong_quan_doanh_thu') {
        $sql = "SELECT 
                    SUM(tt.tong_tien) AS tong_doanh_thu_thuc_nhan, 
                    SUM(tt.tien_giam_voucher) AS tong_giam_voucher,
                    SUM(tt.tong_tien_goc) AS tong_doanh_thu_goc 
                FROM thanh_toan tt
                JOIN chi_tiet_thanh_toan cttt ON tt.id = cttt.thanh_toan_id
                JOIN dat_phong dp ON cttt.dat_phong_id = dp.id
                JOIN phong p ON dp.phong_id = p.id
                WHERE tt.trang_thai = 'completed' AND p.khach_san_id = ?" . $date_filter_tt['conditions'];
        
        $final_params = array_merge([$hotel_id_cua_quan_ly], $date_filter_tt['params']);
        $final_types = "i" . $date_filter_tt['types'];
        
        $stmt = $conn->prepare($sql);
        if(!$stmt) throw new Exception("Prepare failed (tong_quan_doanh_thu): " . $conn->error, 500);
        $stmt->bind_param($final_types, ...$final_params);
        if(!$stmt->execute()) throw new Exception("Execute failed (tong_quan_doanh_thu): " . $stmt->error, 500);
        
        $result = $stmt->get_result()->fetch_assoc();
        $response_data = [
            'tong_doanh_thu_thuc_nhan' => $result['tong_doanh_thu_thuc_nhan'] ?? 0,
            'tong_doanh_thu_goc' => $result['tong_doanh_thu_goc'] ?? 0,
            'tong_giam_voucher' => $result['tong_giam_voucher'] ?? 0,
        ];
        $stmt->close();
    } 
    elseif ($action === 'doanh_thu_theo_thang') {
        $sql = "SELECT 
                    MONTH(tt.ngay_thanh_toan) AS thang, 
                    YEAR(tt.ngay_thanh_toan) AS nam, 
                    SUM(tt.tong_tien) AS doanh_thu_thuc_nhan,
                    SUM(tt.tong_tien_goc) AS doanh_thu_goc,
                    SUM(tt.tien_giam_voucher) AS giam_voucher
                FROM thanh_toan tt
                JOIN chi_tiet_thanh_toan cttt ON tt.id = cttt.thanh_toan_id
                JOIN dat_phong dp ON cttt.dat_phong_id = dp.id
                JOIN phong p ON dp.phong_id = p.id
                WHERE tt.trang_thai = 'completed' AND p.khach_san_id = ? AND YEAR(tt.ngay_thanh_toan) = ?
                {$date_filter_tt['conditions']}
                GROUP BY YEAR(tt.ngay_thanh_toan), MONTH(tt.ngay_thanh_toan)
                ORDER BY nam, thang ASC";

        $final_params = array_merge([$hotel_id_cua_quan_ly, $year_param], $date_filter_tt['params']);
        $final_types = "ii" . $date_filter_tt['types'];
        
        $stmt = $conn->prepare($sql);
        if(!$stmt) throw new Exception("Prepare failed (doanh_thu_theo_thang): " . $conn->error, 500);
        $stmt->bind_param($final_types, ...$final_params);
        if(!$stmt->execute()) throw new Exception("Execute failed (doanh_thu_theo_thang): " . $stmt->error, 500);
        
        $result = $stmt->get_result();
        $monthly_revenue = []; while($row = $result->fetch_assoc()){ $monthly_revenue[] = $row; }
        $response_data = $monthly_revenue;
        $stmt->close();
    }
    elseif ($action === 'thong_ke_dat_phong') {
        $final_params_dp = array_merge([$hotel_id_cua_quan_ly], $date_filter_dp_tao['params']);
        $final_types_dp = "i" . $date_filter_dp_tao['types'];

        $sql_total = "SELECT COUNT(dp.id) AS tong_luot_dat FROM dat_phong dp JOIN phong p ON dp.phong_id = p.id WHERE p.khach_san_id = ? {$date_filter_dp_tao['conditions']}";
        $stmt_total = $conn->prepare($sql_total);
        if(!$stmt_total) throw new Exception("Prepare failed (total bookings): " . $conn->error, 500);
        $stmt_total->bind_param($final_types_dp, ...$final_params_dp);
        $stmt_total->execute(); $total_bookings = $stmt_total->get_result()->fetch_assoc()['tong_luot_dat'] ?? 0; $stmt_total->close();

        $sql_status = "SELECT dp.trang_thai, COUNT(dp.id) AS so_luong FROM dat_phong dp JOIN phong p ON dp.phong_id = p.id WHERE p.khach_san_id = ? {$date_filter_dp_tao['conditions']} GROUP BY dp.trang_thai";
        $stmt_status = $conn->prepare($sql_status);
        if(!$stmt_status) throw new Exception("Prepare failed (booking status): " . $conn->error, 500);
        $stmt_status->bind_param($final_types_dp, ...$final_params_dp);
        $stmt_status->execute(); $result_status = $stmt_status->get_result(); $bookings_by_status = []; while($row = $result_status->fetch_assoc()){ $bookings_by_status[] = $row; } $stmt_status->close();
        
        $sql_payment_status = "SELECT dp.trang_thai_thanh_toan, COUNT(dp.id) AS so_luong FROM dat_phong dp JOIN phong p ON dp.phong_id = p.id WHERE p.khach_san_id = ? {$date_filter_dp_tao['conditions']} GROUP BY dp.trang_thai_thanh_toan";
        $stmt_payment_status = $conn->prepare($sql_payment_status);
        if(!$stmt_payment_status) throw new Exception("Prepare failed (payment status): " . $conn->error, 500);
        $stmt_payment_status->bind_param($final_types_dp, ...$final_params_dp);
        $stmt_payment_status->execute(); $result_payment_status = $stmt_payment_status->get_result(); $bookings_by_payment_status = []; while($row = $result_payment_status->fetch_assoc()){ $bookings_by_payment_status[] = $row; } $stmt_payment_status->close();

        $response_data = ['tong_luot_dat' => $total_bookings, 'theo_trang_thai_dat_phong' => $bookings_by_status, 'theo_trang_thai_thanh_toan' => $bookings_by_payment_status ];
    }
    elseif ($action === 'thong_ke_dich_vu') {
        $final_params_dv = array_merge([$hotel_id_cua_quan_ly], $date_filter_dp_tao['params']);
        $final_types_dv = "i" . $date_filter_dp_tao['types'];

        $sql_dv_summary = "SELECT SUM(dpdv.so_luong) AS tong_so_luong_dv_dat, SUM(dpdv.so_luong * dpdv.gia) AS tong_doanh_thu_dv FROM dat_phong_dich_vu dpdv JOIN dat_phong dp ON dpdv.dat_phong_id = dp.id JOIN phong p ON dp.phong_id = p.id WHERE p.khach_san_id = ? {$date_filter_dp_tao['conditions']}";
        $stmt_dv_summary = $conn->prepare($sql_dv_summary);
        if(!$stmt_dv_summary) throw new Exception("Prepare failed (dv summary): " . $conn->error, 500);
        $stmt_dv_summary->bind_param($final_types_dv, ...$final_params_dv);
        if(!$stmt_dv_summary->execute()) throw new Exception("Execute failed (dv summary): " . $stmt_dv_summary->error, 500);
        $dv_summary_result = $stmt_dv_summary->get_result()->fetch_assoc();
        $stmt_dv_summary->close();

        // Sử dụng bảng `dich_vu` (alias dv) và cột `ten` để lấy tên dịch vụ
        $sql_top_dv = "SELECT 
                           COALESCE(dv.ten, CONCAT('ID Dịch Vụ: ', dvks.dich_vu_id)) as ten_hien_thi_dich_vu,
                           dvks.dich_vu_id,
                           SUM(dpdv.so_luong) AS so_luong_dat
                       FROM dat_phong_dich_vu dpdv
                       JOIN dich_vu_khach_san dvks ON dpdv.dich_vu_khach_san_id = dvks.id
                       LEFT JOIN dich_vu dv ON dvks.dich_vu_id = dv.id -- JOIN với bảng `dich_vu` của bạn
                       JOIN dat_phong dp ON dpdv.dat_phong_id = dp.id
                       JOIN phong p ON dp.phong_id = p.id
                       WHERE p.khach_san_id = ? {$date_filter_dp_tao['conditions']}
                       GROUP BY dvks.dich_vu_id, dv.ten 
                       ORDER BY so_luong_dat DESC
                       LIMIT 5";
        $stmt_top_dv = $conn->prepare($sql_top_dv);
        if(!$stmt_top_dv) throw new Exception("Prepare failed (top services): " . $conn->error, 500);
        $stmt_top_dv->bind_param($final_types_dv, ...$final_params_dv);
        if(!$stmt_top_dv->execute()) throw new Exception("Execute failed (top_services): " . $stmt_top_dv->error, 500);
        $result_top_dv = $stmt_top_dv->get_result();
        $top_dich_vu_list = [];
        while($row = $result_top_dv->fetch_assoc()){ $top_dich_vu_list[] = $row; }
        $stmt_top_dv->close();
        
        $response_data = [
            'tong_so_luong_dich_vu_dat' => $dv_summary_result['tong_so_luong_dv_dat'] ?? 0,
            'tong_doanh_thu_dich_vu' => $dv_summary_result['tong_doanh_thu_dv'] ?? 0,
            'dich_vu_dat_nhieu_nhat' => $top_dich_vu_list 
        ];
    }
    elseif ($action === 'thong_ke_voucher') {
        $final_params_tt = array_merge([$hotel_id_cua_quan_ly], $date_filter_tt['params']);
        $final_types_tt = "i" . $date_filter_tt['types'];

        $sql_voucher_summary = "SELECT COUNT(DISTINCT tt.id) AS so_giao_dich_dung_voucher, SUM(tt.tien_giam_voucher) AS tong_tien_giam_voucher FROM thanh_toan tt JOIN chi_tiet_thanh_toan cttt ON tt.id = cttt.thanh_toan_id JOIN dat_phong dp ON cttt.dat_phong_id = dp.id JOIN phong p ON dp.phong_id = p.id WHERE tt.trang_thai = 'completed' AND tt.voucher_id IS NOT NULL AND p.khach_san_id = ? {$date_filter_tt['conditions']}";
        $stmt_voucher_summary = $conn->prepare($sql_voucher_summary);
        if(!$stmt_voucher_summary) throw new Exception("Prepare failed (voucher summary): " . $conn->error, 500);
        $stmt_voucher_summary->bind_param($final_types_tt, ...$final_params_tt);
        if(!$stmt_voucher_summary->execute()) throw new Exception("Execute failed (voucher summary): " . $stmt_voucher_summary->error, 500);
        $voucher_summary_result = $stmt_voucher_summary->get_result()->fetch_assoc();
        $stmt_voucher_summary->close();

        // Thêm hotel_id thứ hai cho điều kiện (v.khachsan_id IS NULL OR v.khachsan_id = ?)
        $final_params_top_voucher = array_merge([$hotel_id_cua_quan_ly, $hotel_id_cua_quan_ly], $date_filter_tt['params']);
        $final_types_top_voucher = "ii" . $date_filter_tt['types'];

        $sql_top_vouchers = "SELECT v.ma_voucher, v.mo_ta AS mo_ta_voucher, COUNT(DISTINCT tt.id) AS luot_su_dung FROM thanh_toan tt JOIN voucher v ON tt.voucher_id = v.id JOIN chi_tiet_thanh_toan cttt ON tt.id = cttt.thanh_toan_id JOIN dat_phong dp ON cttt.dat_phong_id = dp.id JOIN phong p ON dp.phong_id = p.id WHERE tt.trang_thai = 'completed' AND tt.voucher_id IS NOT NULL AND p.khach_san_id = ? AND (v.khachsan_id IS NULL OR v.khachsan_id = ?) {$date_filter_tt['conditions']} GROUP BY v.id, v.ma_voucher, v.mo_ta ORDER BY luot_su_dung DESC LIMIT 5";
        $stmt_top_vouchers = $conn->prepare($sql_top_vouchers);
        if(!$stmt_top_vouchers) throw new Exception("Prepare failed (top vouchers): " . $conn->error, 500);
        $stmt_top_vouchers->bind_param($final_types_top_voucher, ...$final_params_top_voucher);
        if(!$stmt_top_vouchers->execute()) throw new Exception("Execute failed (top vouchers): " . $stmt_top_vouchers->error, 500);
        $result_top_vouchers = $stmt_top_vouchers->get_result();
        $top_vouchers_list = []; while($row = $result_top_vouchers->fetch_assoc()){ $top_vouchers_list[] = $row; }
        $stmt_top_vouchers->close();

        $response_data = [
            'so_giao_dich_dung_voucher' => $voucher_summary_result['so_giao_dich_dung_voucher'] ?? 0,
            'tong_tien_giam_voucher' => $voucher_summary_result['tong_tien_giam_voucher'] ?? 0,
            'top_voucher_su_dung' => $top_vouchers_list
        ];
    }
    else {
        throw new Exception("Action không hợp lệ hoặc chưa được hỗ trợ.", 400);
    }

    echo json_encode(["success" => true, "data" => $response_data]);

} catch (Exception $e) {
    $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
    http_response_code($responseCode);
    error_log("API_ThongKe_ERROR (Action: " . $action . "): " . $e->getMessage() . " File: " . $e->getFile() . " Line: " . $e->getLine());
    echo json_encode(["success" => false, "message" => "Lỗi xử lý yêu cầu: " . $e->getMessage()]);
}

$conn->close();
?>