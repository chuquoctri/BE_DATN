<?php
// ===== BẮT BUỘC BẬT ĐỂ DEBUG LỖI PHP =====
error_reporting(E_ALL);
ini_set('display_errors', 1); 
ini_set('log_errors', 1);    
// Thay đổi đường dẫn này tới file log lỗi PHP thực tế trên server của bạn nếu cần thiết
// Ví dụ cho XAMPP trên Windows:
// ini_set('error_log', 'C:/xampp/php/logs/php_error_log'); 
// Ví dụ cho Linux:
// ini_set('error_log', '/var/log/php_errors.log'); 

require_once '../../connect.php'; 

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
header('Content-Type: application/json; charset=UTF-8');

function getCurrentUser($conn) {
    error_log("QL_Voucher_API [AUTH_DEBUG]: --- Called getCurrentUser ---");
    $headers = [];
    if (function_exists('getallheaders')) { 
        $headers = getallheaders(); 
    } else { 
        foreach ($_SERVER as $name => $value) { 
            if (substr($name, 0, 5) == 'HTTP_') { 
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value; 
            } 
        } 
    }
    
    $authHeader = $headers['Authorization'] ?? '';
    error_log("QL_Voucher_API [AUTH_DEBUG]: Authorization Header received: '" . $authHeader . "'");

    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $user_id_str = $matches[1];
        error_log("QL_Voucher_API [AUTH_DEBUG]: User ID string from token: '" . $user_id_str . "'");
        
        if (!is_numeric($user_id_str)) { 
            error_log("QL_Voucher_API [AUTH_DEBUG]: User ID from token is NOT numeric."); 
            return null; 
        }
        $user_id = intval($user_id_str);
        error_log("QL_Voucher_API [AUTH_DEBUG]: User ID parsed as integer: " . $user_id);
        
        $stmt = $conn->prepare("SELECT id, role, khach_san_id FROM nguoi_dung WHERE id = ? AND (role = 'quan_ly' OR role = 'admin')");
        if ($stmt === false) { 
            error_log("QL_Voucher_API [AUTH_DEBUG]: Prepare statement failed for nguoi_dung: " . $conn->error); 
            return null; 
        }

        $stmt->bind_param("i", $user_id);
        if (!$stmt->execute()) { 
            error_log("QL_Voucher_API [AUTH_DEBUG]: Execute statement failed for nguoi_dung: " . $stmt->error); 
            $stmt->close(); 
            return null; 
        }
        
        $result = $stmt->get_result();
        if ($result->num_rows === 1) {
            $userData = $result->fetch_assoc();
            error_log("QL_Voucher_API [AUTH_DEBUG]: User found - ID: " . $userData['id'] . ", Role: " . $userData['role'] . ", KhachSanID: " . ($userData['khach_san_id'] ?? 'NULL'));
            
            if ($userData['role'] === 'quan_ly' && empty($userData['khach_san_id'])) {
                error_log("QL_Voucher_API [AUTH_DEBUG]: Manager user " . $userData['id'] . " has no khach_san_id assigned. Returning null.");
                $stmt->close(); 
                return null; 
            }
            $stmt->close();
            error_log("QL_Voucher_API [AUTH_DEBUG]: User authentication successful. Returning user data.");
            return $userData; 
        } else {
            error_log("QL_Voucher_API [AUTH_DEBUG]: User ID " . $user_id . " not found, or not 'quan_ly'/'admin', or multiple rows (num_rows: ".$result->num_rows.").");
        }
        $stmt->close();
    } else {
        error_log("QL_Voucher_API [AUTH_DEBUG]: Bearer token not found or invalid format in Authorization header.");
    }
    error_log("QL_Voucher_API [AUTH_DEBUG]: --- getCurrentUser returning null ---");
    return null;
}

if (!$conn) { 
    error_log("QL_Voucher_API [CRITICAL_ERROR]: Database connection failed!");
    http_response_code(503); 
    echo json_encode(["success" => false, "message" => "Lỗi kết nối cơ sở dữ liệu."]); 
    exit(); 
}

$user = getCurrentUser($conn);
if (!$user) { 
    http_response_code(401); 
    echo json_encode(["success" => false, "message" => "Xác thực thất bại."]); 
    exit(); 
}

$hotel_id_cua_quan_ly = $user['khach_san_id'] ?? null;
if (empty($hotel_id_cua_quan_ly) && $user['role'] !== 'super_admin_global_access') { 
    http_response_code(403); 
    echo json_encode(["success" => false, "message" => "Không xác định được khách sạn để quản lý voucher. Người dùng cần được gán vào một khách sạn."]); 
    exit();
}
if ($hotel_id_cua_quan_ly) {
    $hotel_id_cua_quan_ly = intval($hotel_id_cua_quan_ly);
}

$method = $_SERVER['REQUEST_METHOD'];

// GET: Lấy danh sách voucher (CHỈ CỦA KHÁCH SẠN) hoặc chi tiết một voucher (CHỈ CỦA KHÁCH SẠN)
if ($method === 'GET') {
    try {
        if (isset($_GET['id'])) { 
            $voucher_id = filter_var($_GET['id'], FILTER_VALIDATE_INT);
            if (!$voucher_id || $voucher_id <=0) throw new Exception("ID voucher không hợp lệ.", 400);

            $stmt = $conn->prepare("SELECT * FROM voucher WHERE id = ? AND khachsan_id = ?"); // Chỉ lấy voucher của KS này
            if(!$stmt) throw new Exception("Prepare failed (get voucher detail): " . $conn->error, 500);
            $stmt->bind_param("ii", $voucher_id, $hotel_id_cua_quan_ly);
            if(!$stmt->execute()) throw new Exception("Execute failed (get voucher detail): " . $stmt->error, 500);
            $result = $stmt->get_result();
            $voucher = $result->fetch_assoc();
            $stmt->close();

            if ($voucher) {
                echo json_encode(["success" => true, "data" => $voucher]);
            } else {
                throw new Exception("Voucher không tồn tại hoặc không thuộc khách sạn của bạn.", 404);
            }
        } else { 
            $search = $_GET['search'] ?? '';
            $page = isset($_GET['page']) && filter_var($_GET['page'], FILTER_VALIDATE_INT, ["options" => ["min_range"=>1]]) ? (int)$_GET['page'] : 1;
            $limit = isset($_GET['limit']) && filter_var($_GET['limit'], FILTER_VALIDATE_INT, ["options" => ["min_range"=>1]]) ? (int)$_GET['limit'] : 10;
            $offset = ($page - 1) * $limit;

            $conditions = " WHERE v.khachsan_id = ? "; // Chỉ lấy voucher của KS này
            $params = [$hotel_id_cua_quan_ly];
            $types = "i";

            if (!empty($search)) {
                $searchTerm = "%{$search}%";
                $conditions .= " AND (v.ma_voucher LIKE ? OR v.mo_ta LIKE ?) ";
                $params[] = $searchTerm; $params[] = $searchTerm; $types .= "ss";
            }

            $countQuerySql = "SELECT COUNT(v.id) as total FROM voucher v" . $conditions;
            $stmt_count = $conn->prepare($countQuerySql);
            if(!$stmt_count) throw new Exception("Prepare failed (count vouchers): " . $conn->error, 500);
            $stmt_count->bind_param($types, ...$params);
            if(!$stmt_count->execute()) throw new Exception("Execute failed (count vouchers): " . $stmt_count->error, 500);
            $totalResult = $stmt_count->get_result()->fetch_assoc();
            $total = (int)$totalResult['total'];
            $stmt_count->close();

            $dataQuerySql = "SELECT v.* FROM voucher v" . $conditions . " ORDER BY v.ngay_tao DESC LIMIT ? OFFSET ?";
            $params_data = $params;
            $params_data[] = $limit; $params_data[] = $offset; 
            $types_data = $types . "ii";
            
            $stmt_data = $conn->prepare($dataQuerySql);
            if(!$stmt_data) throw new Exception("Prepare failed (list vouchers): " . $conn->error, 500);
            $stmt_data->bind_param($types_data, ...$params_data);
            if(!$stmt_data->execute()) throw new Exception("Execute failed (list vouchers): " . $stmt_data->error, 500);
            
            $result_data = $stmt_data->get_result();
            $vouchers = [];
            while ($row = $result_data->fetch_assoc()) { $vouchers[] = $row; }
            $stmt_data->close();
            
            echo json_encode(["success" => true, "data" => $vouchers, "pagination" => ["total" => $total, "page" => $page, "limit" => $limit, "total_pages" => $total > 0 ? ceil($total / $limit) : 0]]);
        }
    } catch (Exception $e) {
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("QL_Voucher_API GET Error: " . $e->getMessage());
        echo json_encode(["success" => false, "message" => "Lỗi khi lấy dữ liệu voucher: " . $e->getMessage()]);
    }
}
// POST: Thêm voucher mới
elseif ($method === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400); echo json_encode(["success" => false, "message" => "Dữ liệu JSON không hợp lệ."]); exit();
    }

    $required = ['ma_voucher', 'loai_giam', 'gia_tri_giam', 'ngay_bat_dau', 'ngay_ket_thuc'];
    foreach ($required as $field) {
        if (!isset($data[$field]) || ($data[$field] === '' && !($field === 'gia_tri_giam' && is_numeric($data[$field]) && $data[$field] == 0))) {
            http_response_code(400); echo json_encode(["success" => false, "message" => "Trường '$field' là bắt buộc."]); exit();
        }
    }
    if (!in_array($data['loai_giam'], ['phan_tram', 'tien_mat'])) { http_response_code(400); echo json_encode(["success" => false, "message" => "Loại giảm giá không hợp lệ."]); exit(); }
    if (!is_numeric($data['gia_tri_giam']) || floatval($data['gia_tri_giam']) < 0) { http_response_code(400); echo json_encode(["success" => false, "message" => "Giá trị giảm không hợp lệ."]); exit(); }
    if ($data['loai_giam'] === 'phan_tram' && (floatval($data['gia_tri_giam']) > 100)) { http_response_code(400); echo json_encode(["success" => false, "message" => "Giá trị giảm phần trăm không thể lớn hơn 100."]); exit(); }
    if (isset($data['so_luong_toi_da']) && $data['so_luong_toi_da'] !== null && $data['so_luong_toi_da'] !== '' && (!is_numeric($data['so_luong_toi_da']) || intval($data['so_luong_toi_da']) < 0) ) { http_response_code(400); echo json_encode(["success" => false, "message" => "Số lượng tối đa không hợp lệ."]); exit(); }
    if (isset($data['dieu_kien_don_hang_toi_thieu']) && $data['dieu_kien_don_hang_toi_thieu'] !== null && $data['dieu_kien_don_hang_toi_thieu'] !== '' && (!is_numeric($data['dieu_kien_don_hang_toi_thieu']) || floatval($data['dieu_kien_don_hang_toi_thieu']) < 0) ) { http_response_code(400); echo json_encode(["success" => false, "message" => "Điều kiện đơn hàng tối thiểu không hợp lệ."]); exit(); }
    if (strtotime($data['ngay_ket_thuc']) <= strtotime($data['ngay_bat_dau'])) { http_response_code(400); echo json_encode(["success" => false, "message" => "Ngày kết thúc phải sau ngày bắt đầu."]); exit(); }

    $trang_thai_input = $data['trang_thai'] ?? null;
    $current_time_ts = time(); $ngay_bat_dau_ts = strtotime($data['ngay_bat_dau']); $ngay_ket_thuc_ts = strtotime($data['ngay_ket_thuc']);
    $calculated_trang_thai = 'da_ket_thuc'; 
    if ($current_time_ts < $ngay_bat_dau_ts) { $calculated_trang_thai = 'sap_dien_ra'; } 
    elseif ($current_time_ts <= $ngay_ket_thuc_ts) { $calculated_trang_thai = 'dang_dien_ra'; } 
    $trang_thai_final = (isset($trang_thai_input) && in_array($trang_thai_input, ['dang_dien_ra', 'sap_dien_ra', 'da_ket_thuc'])) ? $trang_thai_input : $calculated_trang_thai;

    $conn->begin_transaction();
    try {
        $sql = "INSERT INTO voucher (khachsan_id, ma_voucher, hinh_anh, mo_ta, loai_giam, gia_tri_giam, ngay_bat_dau, ngay_ket_thuc, trang_thai, so_luong_toi_da, dieu_kien_don_hang_toi_thieu, so_luong_da_dung) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)";
        $stmt = $conn->prepare($sql);
        if(!$stmt) throw new Exception("Lỗi Prepare SQL (Thêm Voucher): " . $conn->error, 500);

        $v_khachsan_id_bind = $hotel_id_cua_quan_ly;
        $v_ma_voucher_bind = $data['ma_voucher'];
        $v_hinh_anh_bind = $data['hinh_anh'] ?? null;
        $v_mo_ta_bind = $data['mo_ta'] ?? null;
        $v_loai_giam_bind = $data['loai_giam'];
        $v_gia_tri_giam_bind = floatval($data['gia_tri_giam']);
        $v_ngay_bat_dau_bind = $data['ngay_bat_dau']; 
        $v_ngay_ket_thuc_bind = $data['ngay_ket_thuc'];
        $v_trang_thai_bind = $trang_thai_final;
        $v_so_luong_toi_da_bind = (isset($data['so_luong_toi_da']) && ($data['so_luong_toi_da'] !== '' && $data['so_luong_toi_da'] !== null)) ? intval($data['so_luong_toi_da']) : null;
        $v_dieu_kien_dh_toi_thieu_bind = (isset($data['dieu_kien_don_hang_toi_thieu']) && ($data['dieu_kien_don_hang_toi_thieu'] !== '' && $data['dieu_kien_don_hang_toi_thieu'] !== null)) ? floatval($data['dieu_kien_don_hang_toi_thieu']) : null;
        
        $types_string = "issssdssidi"; 
        
        error_log("QL_Voucher_API POST bind_param DEBUG: Line " . (__LINE__ + 7) ); // Dòng log ngay trước bind_param
        error_log("Types: '" . $types_string . "' (Length: " . strlen($types_string) . ")");
        $log_vars_array = [
            $v_khachsan_id_bind, $v_ma_voucher_bind, $v_hinh_anh_bind, $v_mo_ta_bind, $v_loai_giam_bind, 
            $v_gia_tri_giam_bind, $v_ngay_bat_dau_bind, $v_ngay_ket_thuc_bind, $v_trang_thai_bind,
            $v_so_luong_toi_da_bind, $v_dieu_kien_dh_toi_thieu_bind
        ];
        error_log("Number of vars for bind_param: " . count($log_vars_array));
        error_log("Vars for bind_param: " . print_r($log_vars_array, true));


        $stmt->bind_param(
            $types_string, 
            $v_khachsan_id_bind, $v_ma_voucher_bind, $v_hinh_anh_bind, $v_mo_ta_bind, $v_loai_giam_bind, 
            $v_gia_tri_giam_bind, $v_ngay_bat_dau_bind, $v_ngay_ket_thuc_bind, $v_trang_thai_bind,
            $v_so_luong_toi_da_bind, $v_dieu_kien_dh_toi_thieu_bind
        );

        if(!$stmt->execute()){
            throw new Exception("Lỗi Execute SQL (Thêm Voucher): " . $stmt->error, 500);
        }
        $new_voucher_id = $conn->insert_id;
        if($new_voucher_id == 0 && $conn->errno == 0) { // Check if insert_id is 0 but no actual SQL error
             error_log("QL_Voucher_API POST Warning: insert_id is 0, but no mysqli error. Check table auto_increment.");
             // Consider this a success if no SQL error, though unusual. Or throw specific exception.
        } elseif($new_voucher_id == 0 && $conn->errno != 0) {
            throw new Exception("Thêm voucher không thành công, không nhận được ID mới (DB Error: " . $conn->error . ").", 500);
        }

        $stmt->close();
        $conn->commit();
        http_response_code(201);
        echo json_encode(["success" => true, "message" => "Thêm voucher thành công.", "id" => $new_voucher_id]);

    } catch (Exception $e) {
        $conn->rollback();
        $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500;
        http_response_code($responseCode);
        error_log("QL_Voucher_API POST Exception: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
        echo json_encode(["success" => false, "message" => "Lỗi khi thêm voucher: " . $e->getMessage()]);
    }
}

// PUT: Cập nhật voucher
elseif ($method === 'PUT') {
    $voucher_id_to_update = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$voucher_id_to_update || $voucher_id_to_update <= 0) { http_response_code(400); echo json_encode(["success" => false, "message" => "ID voucher là bắt buộc và phải hợp lệ."]); exit(); }
    $data = json_decode(file_get_contents("php://input"), true);
    if (json_last_error() !== JSON_ERROR_NONE) { http_response_code(400); echo json_encode(["success" => false, "message" => "Dữ liệu JSON không hợp lệ."]); exit(); }

    $conn->begin_transaction();
    try {
        $stmt_check = $conn->prepare("SELECT id, ngay_bat_dau as existing_ngay_bat_dau, ngay_ket_thuc as existing_ngay_ket_thuc, trang_thai as existing_trang_thai, loai_giam as existing_loai_giam FROM voucher WHERE id = ? AND khachsan_id = ?");
        if(!$stmt_check) throw new Exception("Prepare failed (check voucher PUT): " . $conn->error, 500);
        $stmt_check->bind_param("ii", $voucher_id_to_update, $hotel_id_cua_quan_ly);
        if(!$stmt_check->execute()) throw new Exception("Execute failed (check voucher PUT): " . $stmt_check->error, 500);
        $result_check = $stmt_check->get_result();
        if ($result_check->num_rows === 0) { $stmt_check->close(); throw new Exception("Voucher không tồn tại hoặc bạn không có quyền sửa.", 404); }
        $existing_voucher_data = $result_check->fetch_assoc(); $stmt_check->close();

        $update_fields_sql_parts = []; $bind_params_values = []; $bind_params_types = "";
        
        $add_param_for_update = function($field_sql_name, $value, $type) use (&$update_fields_sql_parts, &$bind_params_values, &$bind_params_types) {
            $update_fields_sql_parts[] = $field_sql_name . " = ?";
            $bind_params_values[] = $value;
            $bind_params_types .= $type;
        };

        if(isset($data['ma_voucher'])){ $add_param_for_update("ma_voucher", $data['ma_voucher'], "s"); }
        if(array_key_exists('hinh_anh', $data)){ $add_param_for_update("hinh_anh", $data['hinh_anh'], "s"); } // Allow null
        if(array_key_exists('mo_ta', $data)){ $add_param_for_update("mo_ta", $data['mo_ta'], "s"); } // Allow null
        
        $current_loai_giam = $data['loai_giam'] ?? $existing_voucher_data['existing_loai_giam'];
        if(isset($data['loai_giam'])){ 
            if (!in_array($data['loai_giam'], ['phan_tram', 'tien_mat'])) throw new Exception("Loại giảm giá không hợp lệ.", 400);
            $add_param_for_update("loai_giam", $data['loai_giam'], "s"); 
        }
        if(isset($data['gia_tri_giam'])){ 
            if (!is_numeric($data['gia_tri_giam']) || floatval($data['gia_tri_giam']) < 0) throw new Exception("Giá trị giảm không hợp lệ.", 400);
            if ($current_loai_giam === 'phan_tram' && floatval($data['gia_tri_giam']) > 100) throw new Exception("Giá trị giảm phần trăm không thể lớn hơn 100.", 400);
            $add_param_for_update("gia_tri_giam", floatval($data['gia_tri_giam']), "d"); 
        }
        
        $ngay_bat_dau_update = $data['ngay_bat_dau'] ?? null;
        $ngay_ket_thuc_update = $data['ngay_ket_thuc'] ?? null;
        if($ngay_bat_dau_update){ $add_param_for_update("ngay_bat_dau", $ngay_bat_dau_update, "s"); }
        if($ngay_ket_thuc_update){ $add_param_for_update("ngay_ket_thuc", $ngay_ket_thuc_update, "s"); }

        $final_trang_thai = $data['trang_thai'] ?? $existing_voucher_data['existing_trang_thai'];
        $nbd_ts_for_status = $ngay_bat_dau_update ? strtotime($ngay_bat_dau_update) : strtotime($existing_voucher_data['existing_ngay_bat_dau']);
        $nkt_ts_for_status = $ngay_ket_thuc_update ? strtotime($ngay_ket_thuc_update) : strtotime($existing_voucher_data['existing_ngay_ket_thuc']);

        if ($ngay_bat_dau_update || $ngay_ket_thuc_update || isset($data['trang_thai'])) { 
            if ($nkt_ts_for_status <= $nbd_ts_for_status) throw new Exception("Ngày kết thúc phải sau ngày bắt đầu.", 400);
            $current_time = time();
            $calculated_status_based_on_dates = 'da_ket_thuc';
            if ($current_time < $nbd_ts_for_status) $calculated_status_based_on_dates = 'sap_dien_ra';
            elseif ($current_time <= $nkt_ts_for_status) $calculated_status_based_on_dates = 'dang_dien_ra';
            
            $final_trang_thai = isset($data['trang_thai']) && in_array($data['trang_thai'], ['dang_dien_ra', 'sap_dien_ra', 'da_ket_thuc']) ? $data['trang_thai'] : $calculated_status_based_on_dates;
             if ($final_trang_thai !== $existing_voucher_data['existing_trang_thai'] || isset($data['trang_thai'])){ // Only add if changed or explicitly set
                 $add_param_for_update("trang_thai", $final_trang_thai, "s");
             }
        }

        if(array_key_exists('so_luong_toi_da', $data)){ $sltd = $data['so_luong_toi_da']; if ($sltd !== null && $sltd !=='' && (!is_numeric($sltd) || intval($sltd) < 0)) throw new Exception("Số lượng tối đa không hợp lệ.", 400); $add_param_for_update("so_luong_toi_da", ($sltd === null || $sltd === '' ? null : intval($sltd)), "i"); }
        if(array_key_exists('dieu_kien_don_hang_toi_thieu', $data)){ $dkdhtt = $data['dieu_kien_don_hang_toi_thieu']; if ($dkdhtt !== null && $dkdhtt !=='' && (!is_numeric($dkdhtt) || floatval($dkdhtt) < 0)) throw new Exception("Điều kiện đơn hàng tối thiểu không hợp lệ.", 400); $add_param_for_update("dieu_kien_don_hang_toi_thieu", ($dkdhtt === null || $dkdhtt === '' ? null : floatval($dkdhtt)), "d"); }

        if(empty($update_fields_sql_parts)) throw new Exception("Không có trường thông tin hợp lệ nào để cập nhật.", 400);

        $sql = "UPDATE voucher SET " . implode(', ', $update_fields_sql_parts) . " WHERE id = ? AND khachsan_id = ?";
        $bind_params_values[] = $voucher_id_to_update; $bind_params_types .= "i";
        $bind_params_values[] = $hotel_id_cua_quan_ly; $bind_params_types .= "i";

        $stmt_update = $conn->prepare($sql);
        if(!$stmt_update) throw new Exception("Prepare failed (update voucher): " . $conn->error, 500);
        $stmt_update->bind_param($bind_params_types, ...$bind_params_values);
        if(!$stmt_update->execute()) throw new Exception("Execute failed (update voucher): " . $stmt_update->error, 500);
        
        if($stmt_update->affected_rows > 0){ echo json_encode(["success" => true, "message" => "Cập nhật voucher thành công."]);
        } else { echo json_encode(["success" => true, "message" => "Không có thay đổi nào được thực hiện (dữ liệu có thể giống hệt dữ liệu hiện tại)."]); }
        $stmt_update->close(); $conn->commit();
    } catch (Exception $e) { $conn->rollback(); $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500; http_response_code($responseCode); error_log("QL_Voucher_API PUT Error: " . $e->getMessage()); echo json_encode(["success" => false, "message" => "Lỗi khi cập nhật voucher: " . $e->getMessage()]); }
}

// DELETE: Xóa voucher
elseif ($method === 'DELETE') {
    // ... (Giữ nguyên code DELETE từ phiên bản trước) ...
    $voucher_id_to_delete = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$voucher_id_to_delete || $voucher_id_to_delete <= 0) { http_response_code(400); echo json_encode(["success" => false, "message" => "ID voucher là bắt buộc và phải hợp lệ."]); exit(); }
    $conn->begin_transaction();
    try {
        $stmt_check_usage = $conn->prepare("SELECT so_luong_da_dung FROM voucher WHERE id = ? AND khachsan_id = ?");
        if(!$stmt_check_usage) throw new Exception("Prepare failed (check usage): " . $conn->error, 500); $stmt_check_usage->bind_param("ii", $voucher_id_to_delete, $hotel_id_cua_quan_ly); if(!$stmt_check_usage->execute()) throw new Exception("Execute failed (check usage): " . $stmt_check_usage->error, 500); $result_usage = $stmt_check_usage->get_result(); if($result_usage->num_rows === 0){ $stmt_check_usage->close(); throw new Exception("Voucher không tồn tại hoặc bạn không có quyền xóa.", 404); } $voucher_usage = $result_usage->fetch_assoc(); $stmt_check_usage->close();
        if ($voucher_usage['so_luong_da_dung'] > 0) { throw new Exception("Không thể xóa voucher đã được sử dụng. Cân nhắc đổi trạng thái.", 403); }
        $stmt_delete = $conn->prepare("DELETE FROM voucher WHERE id = ? AND khachsan_id = ?");
        if(!$stmt_delete) throw new Exception("Prepare failed (delete voucher): " . $conn->error, 500); $stmt_delete->bind_param("ii", $voucher_id_to_delete, $hotel_id_cua_quan_ly); if(!$stmt_delete->execute()) throw new Exception("Execute failed (delete voucher): " . $stmt_delete->error, 500);
        if($stmt_delete->affected_rows > 0){ echo json_encode(["success" => true, "message" => "Xóa voucher thành công."]);
        } else { throw new Exception("Không tìm thấy voucher để xóa hoặc không thuộc KS của bạn.", 404); }
        $stmt_delete->close(); $conn->commit();
    } catch (Exception $e) { $conn->rollback(); $responseCode = ($e->getCode() >= 400 && $e->getCode() < 600 && $e->getCode() != 0) ? $e->getCode() : 500; http_response_code($responseCode); error_log("QL_Voucher_API DELETE Error: " . $e->getMessage()); echo json_encode(["success" => false, "message" => "Lỗi khi xóa voucher: " . $e->getMessage()]); }
}
else {
    http_response_code(405); 
    echo json_encode(["success" => false, "message" => "Phương thức không được hỗ trợ."]);
}

$conn->close();
?>