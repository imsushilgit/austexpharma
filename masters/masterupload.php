<?php
// CRASH DETECTOR
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json');
        echo json_encode(["status" => "error", "message" => "CRITICAL CRASH: " . $err['message']]);
        exit;
    }
});

@set_time_limit(300); 
@ini_set('memory_limit', '1024M');
@ini_set('display_errors', 0); 
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action']) || isset($_GET['action'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access."]); exit();
    }
    header("Location: ../auth/login.php"); exit();
}

require_once __DIR__ . '/../includes/core.php';

function getStatePrefix($stateName) {
    $map = ['ANDAMAN AND NICOBAR ISLANDS' => 'AN', 'ANDHRA PRADESH' => 'AP', 'ARUNACHAL PRADESH' => 'AR', 'ASSAM' => 'AS', 'BIHAR' => 'BR', 'CHANDIGARH' => 'CH', 'CHHATTISGARH' => 'CG', 'DADRA AND NAGAR HAVELI' => 'DN', 'DAMAN AND DIU' => 'DD', 'DELHI' => 'DL', 'GOA' => 'GA', 'GUJARAT' => 'GJ', 'HARYANA' => 'HR', 'HIMACHAL PRADESH' => 'HP', 'JAMMU AND KASHMIR' => 'JK', 'JHARKHAND' => 'JH', 'KARNATAKA' => 'KA', 'KERALA' => 'KL', 'LADAKH' => 'LA', 'LAKSHADWEEP' => 'LD', 'MADHYA PRADESH' => 'MP', 'MAHARASHTRA' => 'MH', 'MANIPUR' => 'MN', 'MEGHALAYA' => 'ML', 'MIZORAM' => 'MZ', 'NAGALAND' => 'NL', 'ODISHA' => 'OR', 'PUDUCHERRY' => 'PY', 'PUNJAB' => 'PB', 'RAJASTHAN' => 'RJ', 'SIKKIM' => 'SK', 'TAMIL NADU' => 'TN', 'TELANGANA' => 'TG', 'TRIPURA' => 'TR', 'UTTAR PRADESH' => 'UP', 'UTTARAKHAND' => 'UK', 'WEST BENGAL' => 'WB'];
    $upper = strtoupper(trim($stateName));
    return isset($map[$upper]) ? $map[$upper] : strtoupper(substr($upper, 0, 2));
}

function parseInvoiceDate($raw) {
    $raw = trim($raw);
    if ($raw === '' || $raw === '0000-00-00' || $raw === '00-00-0000') return null;
    if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/', $raw, $m)) {
        $y = (int)$m[1]; $mo = (int)$m[2]; $d = (int)$m[3];
        if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d); return null;
    }
    if (preg_match('/^(\d{1,2})[-\/](\d{1,2})[-\/](\d{4})$/', $raw, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d); return null;
    }
    $ts = strtotime(str_replace('/', '-', $raw));
    if ($ts === false) return null;
    $formatted = date('Y-m-d', $ts);
    if ($formatted === '1970-01-01' && strpos($raw, '1970') === false) return null;
    return $formatted;
}

function parseMonthYearToDate($raw) {
    $raw = trim($raw);
    if (empty($raw)) return null;
    if (preg_match('/^([a-zA-Z]+)$/', $raw, $m)) {
        $raw = $m[1] . ' ' . date('Y');
    }
    $ts = strtotime("1 " . $raw);
    if ($ts === false) return null;
    return date('Y-m-t', $ts); 
}

function getFinancialYearMonths($startYear = null) {
    if (!$startYear) {
        $currentMonth = (int)date('n');
        $currentYear = (int)date('Y');
        $startYear = ($currentMonth >= 4) ? $currentYear : $currentYear - 1;
    }
    $months = [];
    for ($i = 0; $i < 12; $i++) {
        $m = ($i + 3) % 12 + 1;
        $y = ($m >= 4) ? $startYear : $startYear + 1;
        $months[] = [ 'month' => $m, 'year' => $y ];
    }
    return $months;
}

function evaluateExpression($expr, $variables = []) {
    $expr = trim($expr);
    if (strpos($expr, '=') === 0) { $expr = substr($expr, 1); }
    $expr = str_replace(',', '', $expr);
    foreach ($variables as $key => $value) {
        if (empty($key)) continue;
        $expr = preg_replace('/\b' . preg_quote($key, '/') . '\b/i', (float)$value, $expr);
    }
    $expr = preg_replace('/[^0-9\+\-\*\/\.\(\)]/', '', $expr);
    if ($expr === '') return 0.00;
    try {
        $res = @eval("return ({$expr});");
        return $res !== false ? (float)$res : 0.00;
    } catch (Throwable $e) { return 0.00; }
}

if (isset($_GET['action']) && $_GET['action'] === 'template') {
    $type = $_GET['type'] ?? '';
    $fill = $_GET['fill'] ?? 'blank';
    
    $templates = [
        'users' => ['Username', 'Full Name', 'Email', 'Plain Text Password', 'Role (Admin/Staff)'],
        'divisions' => ['Division Name'],
        'departments' => ['Department Name'],
        'designations' => ['Department Name', 'Designation Name'],
        'countries' => ['Country Name'],
        'states' => ['Country Name', 'State Name'],
        'districts' => ['Country Name', 'State Name', 'District Name'],
        'hqs' => ['Country Name', 'State Name', 'District Name', 'HQ Name'],
        'cities' => ['Country Name', 'State Name', 'District Name', 'HQ Name', 'City Name'],
        'geography_cascade' => ['Country', 'State', 'District', 'Headquarters', 'City/Area'],
        'employees' => ['Division', 'Empl Code (Leave Blank for Auto)', 'Employee Name', 'Marital Status', 'Anniversary Date', 'Department', 'Designation', 'Mobile. No', 'Email', 'Gender', 'Joining Date', 'Birth Date', 'Resign Date', 'HQ', 'Reporting Manager (Enter Emp Code)', 'State', 'Bank Name', 'Bank Account No.', 'Bank IFSC Code', 'Aadhar Card No.', 'Pancard No', 'DL No.', 'Education', 'Address'],
        'parties' => ['Division', 'State', 'Party Code (Leave Blank for Auto)', 'Party Name', 'HQ', 'Area', 'Contact'],
        'assignparties' => ['Employee Code', 'Party Name', 'Percentage'],
        'transporters' => ['Transporter Name', 'Service Area', 'Phone'],
        'senders' => ['Sender Name', 'Mobile'],
        'invoices' => ['Sl No.', 'Party Name', 'Invoice Number', 'Invoice Date', 'Product Name', 'Batch No.', 'Qty', 'Free Qty', 'Total Qty', 'MRP', 'Total Value'],
        'products' => ['Product Name', 'producttype', 'Status'],
        'product_batches' => ['Product Name', 'Batch No', 'MRP (₹)', 'Expiry Date (DD-MM-YYYY)'],
        'targets' => ['Division Name', 'Employee Code', 'Employee Name', 'Department', 'Designation', 'Headquarters', 'Financial Year (e.g., 2026-27)', 'Yearly Target (Total Value)'],
        
        // NEW RADIO BUTTON TEMPLATES
        'collections_mr' => ['Sl No.', 'Employee Code', 'Employee Name', 'Collection Month-Year', 'Amount', 'Remarks'],
        'collections_party' => ['Sl No.', 'Party Name', 'Collection Date (DD-MM-YYYY)', 'Amount', 'Payment Mode', 'Reference No', 'Remarks']
    ];

    if (array_key_exists($type, $templates)) {
        $filename = ($fill === 'existing') ? "Export_{$type}_" . date('Ymd') . ".csv" : "Template_" . ucfirst($type) . ".csv";
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $output = fopen('php://output', 'w');
        fputcsv($output, $templates[$type]);
        
        if ($fill === 'existing') {
            $sql = null;
            switch ($type) {
                case 'users': $sql = "SELECT username, fullname, email, '' as pwd, role FROM users"; break;
                case 'divisions': $sql = "SELECT divname FROM divisions"; break;
                case 'departments': $sql = "SELECT deptname FROM departments"; break;
                case 'designations': $sql = "SELECT dp.deptname, d.designame FROM designations d JOIN departments dp ON d.deptid = dp.deptid"; break;
                case 'countries': $sql = "SELECT countryname FROM countries"; break;
                case 'states': $sql = "SELECT c.countryname, s.statename FROM states s JOIN countries c ON s.countryid = c.id"; break;
                case 'districts': $sql = "SELECT c.countryname, s.statename, d.districtname FROM districts d JOIN states s ON d.stateid = s.id JOIN countries c ON s.countryid = c.id"; break;
                case 'hqs': $sql = "SELECT c.countryname, s.statename, d.districtname, h.hqname FROM hqs h JOIN districts d ON h.districtid = d.id JOIN states s ON d.stateid = s.id JOIN countries c ON s.countryid = c.id"; break;
                case 'cities': $sql = "SELECT c.countryname, s.statename, d.districtname, h.hqname, ci.cityname FROM cities ci JOIN hqs h ON ci.hqid = h.id JOIN districts d ON h.districtid = d.id JOIN states s ON d.stateid = s.id JOIN countries c ON s.countryid = c.id"; break;
                case 'geography_cascade': $sql = "SELECT c.countryname, s.statename, d.districtname, h.hqname, ci.cityname FROM cities ci JOIN hqs h ON ci.hqid = h.id JOIN districts d ON h.districtid = d.id JOIN states s ON d.stateid = s.id JOIN countries c ON s.countryid = c.id"; break;
                case 'employees': $sql = "SELECT division, empcode, empname, marital_status, DATE_FORMAT(anniversary_date, '%d-%m-%Y'), department, designation, mobile, email, gender, DATE_FORMAT(joining_date, '%d-%m-%Y'), DATE_FORMAT(dob, '%d-%m-%Y'), DATE_FORMAT(resignation_date, '%d-%m-%Y'), hq, reporting_manager, state, bank_name, bank_account, ifsc_code, aadhar_no, pan_no, dl_no, education, address FROM employees"; break;
                case 'parties': $sql = "SELECT division, state, partycode, partyname, hq, area, contact FROM parties"; break;
                case 'assignparties': $sql = "SELECT empcode, partyname, percentage FROM assignparties"; break;
                case 'transporters': $sql = "SELECT transportername, servicearea, phone FROM transporters"; break;
                case 'senders': $sql = "SELECT sendername, mobile FROM senders"; break;
                case 'invoices': $pdo->exec("SET @row_number:=0"); $sql = "SELECT @row_number:=@row_number+1 AS sl_no, partyname, invoice_no, invoice_date, product_name, batch_no, qty, free_qty, total_qty, mrp, total_value FROM invoices"; break;
                case 'products': $sql = "SELECT product_name, producttype, status FROM products"; break;
                case 'product_batches': $sql = "SELECT product_name, batch_no, mrp, DATE_FORMAT(expiry_date, '%d-%m-%Y') FROM product_batches"; break;
                case 'targets': $sql = "SELECT e.division, t.empcode, e.empname, e.department, e.designation, e.hq, CONCAT(IF(t.target_month <= 3, t.target_year - 1, t.target_year), '-', SUBSTRING(IF(t.target_month <= 3, t.target_year, t.target_year + 1), 3, 2)) AS fy, SUM(t.sales_target) FROM targets t LEFT JOIN employees e ON t.empcode = e.empcode GROUP BY t.empcode, fy"; break;
                
                case 'collections_mr': 
                    $pdo->exec("SET @row_number:=0"); 
                    $sql = "SELECT @row_number:=@row_number+1 AS sl_no, c.empcode, e.empname, DATE_FORMAT(c.collection_date, '%M-%Y'), c.amount, c.remarks FROM collections c LEFT JOIN employees e ON c.empcode = e.empcode WHERE c.partyname = 'Direct Collection'"; 
                    break;
                case 'collections_party': 
                    $pdo->exec("SET @row_number:=0"); 
                    $sql = "SELECT @row_number:=@row_number+1 AS sl_no, partyname, DATE_FORMAT(collection_date, '%d-%m-%Y'), amount, payment_mode, reference_no, remarks FROM collections WHERE partyname != 'Direct Collection'"; 
                    break;
            }
            if ($sql) {
                try { $stmt = $pdo->query($sql); while ($row = $stmt->fetch(PDO::FETCH_NUM)) { fputcsv($output, $row); } } catch (PDOException $e) { }
            }
        }
        fclose($output); exit();
    }
    exit('Invalid Template Type');
}

if (isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'upload') {
    header('Content-Type: application/json');
    $type = $_POST['master_type'] ?? '';
    $mode = $_POST['upload_mode'] ?? 'add_only';
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["status" => "error", "message" => "Please select a valid CSV/XLS file."]); exit();
    }

    $fileTmpPath = $_FILES['csv_file']['tmp_name'];
    $successCount = 0; $skippedCount = 0; $updatedCount = 0; $deletedCount = 0; $errorCount = 0; $errors = [];

    if (($handle = fopen($fileTmpPath, "r")) !== FALSE) {
        $headers = fgetcsv($handle, 1000, ","); 
        
        $csvData = [];
        while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
            $csvData[] = $data;
        }
        fclose($handle);

        if ($type === 'invoices' && $mode !== 'delete') {
            $aggregated = [];
            foreach ($csvData as $data) {
                if (count($data) < 11) continue;
                $inv_no = trim($data[2] ?? ''); $prod_name = trim($data[4] ?? ''); $batch = trim($data[5] ?? '');
                if (empty($inv_no) || empty($prod_name)) continue;

                $key = $inv_no . '|||' . $prod_name . '|||' . $batch;
                if (!isset($aggregated[$key])) {
                    $data[6] = is_numeric($data[6]) ? $data[6] : intval(preg_replace('/[^\d.-]/', '', trim($data[6] ?? '0')));
                    $data[7] = is_numeric($data[7]) ? $data[7] : intval(preg_replace('/[^\d.-]/', '', trim($data[7] ?? '0')));
                    $data[8] = is_numeric($data[8]) ? $data[8] : intval(preg_replace('/[^\d.-]/', '', trim($data[8] ?? '0')));
                    $data[10] = is_numeric($data[10]) ? $data[10] : floatval(preg_replace('/[^\d.-]/', '', trim($data[10] ?? '0')));
                    $aggregated[$key] = $data;
                } else {
                    $aggregated[$key][6] += is_numeric($data[6]) ? $data[6] : intval(preg_replace('/[^\d.-]/', '', trim($data[6] ?? '0')));
                    $aggregated[$key][7] += is_numeric($data[7]) ? $data[7] : intval(preg_replace('/[^\d.-]/', '', trim($data[7] ?? '0')));
                    $aggregated[$key][8] += is_numeric($data[8]) ? $data[8] : intval(preg_replace('/[^\d.-]/', '', trim($data[8] ?? '0')));
                    $aggregated[$key][10] += is_numeric($data[10]) ? $data[10] : floatval(preg_replace('/[^\d.-]/', '', trim($data[10] ?? '0')));
                }
            }
            $csvData = array_values($aggregated);
        }

        $rowNum = 1;
        $caches = ['party'=>[], 'prod'=>[], 'batch'=>[], 'assign'=>[], 'country'=>[], 'state'=>[], 'district'=>[], 'hq'=>[]];
        
        $stmts = []; 
        $qPrep = function($sql) use ($pdo, &$stmts) {
            $hash = md5($sql);
            if (!isset($stmts[$hash])) { $stmts[$hash] = $pdo->prepare($sql); }
            return $stmts[$hash];
        };

        try { $pdo->beginTransaction(); } catch (Exception $ex) {}

        foreach ($csvData as $data) {
            $rowNum++;
            $isSkipped = false; $isUpdated = false; $isDeleted = false;
            
            try {
                switch ($type) {
                    case 'targets':
                        $empCode = trim($data[1] ?? '');
                        if (empty($empCode)) throw new Exception("Employee identification missing.");
                        $checkEmp = $qPrep("SELECT COUNT(*) FROM employees WHERE empcode = ?"); $checkEmp->execute([$empCode]);
                        if ($checkEmp->fetchColumn() == 0) { throw new Exception("Validation Error: Employee Code '{$empCode}' does not exist in Employee Master. Target rejected."); }

                        $fyValStr = trim($data[6] ?? ''); $startYear = null;
                        if (preg_match('/^(\d{4})[-\/](\d{2,4})$/', $fyValStr, $m)) { $startYear = (int)$m[1]; } 
                        elseif (preg_match('/^(\d{4})$/', $fyValStr, $m)) { $startYear = (int)$m[1]; } 
                        else { $startYear = ((int)date('n') >= 4) ? (int)date('Y') : (int)date('Y') - 1; }

                        $yearlyValueStr = trim($data[7] ?? '0');
                        $yearlyValue = (strpos($yearlyValueStr, '=') === 0) ? evaluateExpression($yearlyValueStr) : floatval(preg_replace('/[^\d.-]/', '', $yearlyValueStr));

                        if ($yearlyValue <= 0 && $mode !== 'delete') { throw new Exception("Target amount check skipped."); }

                        $factorAprJun = round(($yearlyValue * 0.25) / 3, 2); $factorJulSep = round(($yearlyValue * 0.275) / 3, 2);
                        $factorOctDec = round(($yearlyValue * 0.25) / 3, 2); $factorJanMar = round(($yearlyValue * 0.225) / 3, 2);
                        $totalComputed = ($factorAprJun * 3) + ($factorJulSep * 3) + ($factorOctDec * 3) + ($factorJanMar * 3);
                        $roundingOffset = round($yearlyValue - $totalComputed, 2);
                        $fyMonths = getFinancialYearMonths($startYear);

                        foreach ($fyMonths as $index => $monthObj) {
                            $month = $monthObj['month']; $year = $monthObj['year'];
                            if ($month >= 4 && $month <= 6) { $targetVal = $factorAprJun; } elseif ($month >= 7 && $month <= 9) { $targetVal = $factorJulSep; } elseif ($month >= 10 && $month <= 12) { $targetVal = $factorOctDec; } else { $targetVal = $factorJanMar; }
                            if ($index === 11) { $targetVal += $roundingOffset; }

                            if ($mode === 'delete') {
                                $stmtT = $qPrep("DELETE FROM targets WHERE empcode = ? AND target_month = ? AND target_year = ?"); $stmtT->execute([$empCode, $month, $year]);
                                if ($stmtT->rowCount() > 0) { $deletedCount++; } else { $skippedCount++; }
                            } else {
                                $stmt = $qPrep("INSERT INTO targets (empcode, target_month, target_year, sales_target, collection_target) VALUES (?, ?, ?, ?, 0.00) ON DUPLICATE KEY UPDATE sales_target = VALUES(sales_target)");
                                $stmt->execute([$empCode, $month, $year, $targetVal]);
                                if ($stmt->rowCount() == 1) { $successCount++; } elseif ($stmt->rowCount() == 2) { $updatedCount++; } else { $skippedCount++; }
                            }
                        }
                        break;

                    case 'collections_mr':
                        // TEMPLATE: ['Sl No.', 'Employee Code', 'Employee Name', 'Collection Month-Year', 'Amount', 'Remarks']
                        if (count($data) < 5) throw new Exception("Incomplete columns for MR Wise Collection");
                        
                        $emp = trim($data[1] ?? '');
                        $monthYearStr = trim($data[3] ?? '');
                        
                        $amtRaw = trim($data[4] ?? '0');
                        $amt = is_numeric($amtRaw) ? floatval($amtRaw) : floatval(preg_replace('/[^\d.-]/', '', $amtRaw));

                        if (empty($emp)) throw new Exception("Employee Code is mandatory.");
                        if ($amt < 0 && $mode !== 'delete') throw new Exception("Amount cannot be negative");
                        if ($amtRaw === '') throw new Exception("Amount column is blank");
                        
                        $cdate = parseMonthYearToDate($monthYearStr);
                        if (!$cdate && $mode !== 'delete') throw new Exception("Invalid 'Collection Month-Year' format (Use 'Month-YYYY', e.g., 'April-2026').");

                        $checkEmp = $qPrep("SELECT id FROM employees WHERE empcode = ?");
                        $checkEmp->execute([$emp]);
                        if (!$checkEmp->fetchColumn() && $mode !== 'delete') throw new Exception("Validation Error: Employee Code '{$emp}' does not exist.");

                        $party = 'Direct Collection'; 
                        $remarks = trim($data[5] ?? '');

                        if ($mode === 'delete') {
                            $stmt = $qPrep("DELETE FROM collections WHERE empcode=? AND collection_date=? AND amount=? AND partyname='Direct Collection'"); 
                            $stmt->execute([$emp, $cdate, $amt]);
                            if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else {
                            $qPrep("INSERT INTO collections (partyname, empcode, collection_date, amount, payment_mode, reference_no, remarks) VALUES (?, ?, ?, ?, '', '', ?)")->execute([$party, $emp, $cdate, $amt, $remarks]);
                        }
                        break;

                    case 'collections_party':
                        // TEMPLATE: ['Sl No.', 'Party Name', 'Collection Date (DD-MM-YYYY)', 'Amount', 'Payment Mode', 'Reference No', 'Remarks']
                        if (count($data) < 4) throw new Exception("Incomplete columns for Party Wise Collection");
                        
                        $party = trim($data[1] ?? ''); 
                        $cdateRaw = trim($data[2] ?? ''); 
                        $amtRaw = trim($data[3] ?? '0');
                        $amt = is_numeric($amtRaw) ? floatval($amtRaw) : floatval(preg_replace('/[^\d.-]/', '', $amtRaw));

                        if (empty($party)) throw new Exception("Party Name is mandatory for Party Wise upload.");
                        if ($amt < 0 && $mode !== 'delete') throw new Exception("Amount cannot be negative");
                        if ($amtRaw === '') throw new Exception("Amount column is blank");
                        
                        $cdate = parseInvoiceDate($cdateRaw);
                        if (!$cdate && $mode !== 'delete') throw new Exception("Invalid Collection Date format");
                        
                        $pLower = strtolower($party);
                        if (!isset($caches['assign'][$pLower])) {
                            $stmtA = $qPrep("SELECT empcode FROM assignparties WHERE TRIM(LOWER(partyname)) = TRIM(LOWER(?)) LIMIT 1"); $stmtA->execute([$party]);
                            $caches['assign'][$pLower] = $stmtA->fetchColumn();
                        }
                        $emp = $caches['assign'][$pLower];

                        if (!$emp && $mode !== 'delete') throw new Exception("Validation Error: Party '{$party}' is not mapped to any active Employee in assignment records.");

                        if ($mode === 'delete') {
                            $ref = trim($data[5] ?? '');
                            if($ref) { $stmt = $qPrep("DELETE FROM collections WHERE reference_no=?"); $stmt->execute([$ref]); } 
                            else { $stmt = $qPrep("DELETE FROM collections WHERE partyname=? AND collection_date=? AND amount=?"); $stmt->execute([$party, $cdate, $amt]); }
                            if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else {
                            $qPrep("INSERT INTO collections (partyname, empcode, collection_date, amount, payment_mode, reference_no, remarks) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$party, $emp, $cdate, $amt, trim($data[4] ?? ''), trim($data[5] ?? ''), trim($data[6] ?? '')]);
                        }
                        break;

                    case 'invoices':
                        if (count($data) < 11) throw new Exception("Incomplete columns (Requires 11)");
                        $partyname = trim($data[1] ?? ''); $inv_no = trim($data[2] ?? ''); $inv_date_raw = trim($data[3] ?? '');
                        $prod_name = trim($data[4] ?? ''); $batch = trim($data[5] ?? '');
                        $qty = is_numeric($data[6]) ? $data[6] : intval(preg_replace('/[^\d.-]/', '', trim($data[6] ?? '0')));
                        $free_qty = is_numeric($data[7]) ? $data[7] : intval(preg_replace('/[^\d.-]/', '', trim($data[7] ?? '0')));
                        $total_qty = is_numeric($data[8]) ? $data[8] : intval(preg_replace('/[^\d.-]/', '', trim($data[8] ?? '0')));
                        $mrp = is_numeric($data[9]) ? $data[9] : floatval(preg_replace('/[^\d.-]/', '', trim($data[9] ?? '0')));
                        $total_val = is_numeric($data[10]) ? $data[10] : floatval(preg_replace('/[^\d.-]/', '', trim($data[10] ?? '0')));
                        
                        if (empty($inv_no) || empty($prod_name) || empty($partyname)) throw new Exception("Party Name, Invoice Number, and Product Name required");
                        $inv_date = parseInvoiceDate($inv_date_raw);
                        if ($inv_date === null && $mode !== 'delete') throw new Exception("Invalid date format");

                        if ($mode !== 'delete') {
                            $pLower = strtolower($partyname);
                            if (!isset($caches['party'][$pLower])) {
                                $stmtP = $qPrep("SELECT id FROM parties WHERE partyname = ?"); $stmtP->execute([$partyname]);
                                if (!$stmtP->fetchColumn()) { $qPrep("INSERT INTO parties (division, state, partycode, partyname, hq, area, contact) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute(['General', 'Bihar', '', $partyname, 'Patna', 'General', '']); }
                                $caches['party'][$pLower] = true;
                            }
                            $prLower = strtolower($prod_name);
                            if (!isset($caches['prod'][$prLower])) {
                                $stmtPr = $qPrep("SELECT id FROM products WHERE product_name = ?"); $stmtPr->execute([$prod_name]);
                                if (!$stmtPr->fetchColumn()) { $qPrep("INSERT INTO products (product_name, producttype) VALUES (?, 'General')")->execute([$prod_name]); }
                                $caches['prod'][$prLower] = true;
                            }
                            $bKey = $prLower . '_' . strtolower($batch);
                            if (!isset($caches['batch'][$bKey])) {
                                $stmtB = $qPrep("SELECT id FROM product_batches WHERE product_name = ? AND batch_no = ?"); $stmtB->execute([$prod_name, $batch]);
                                if (!$stmtB->fetchColumn()) { $qPrep("INSERT INTO product_batches (product_name, batch_no, mrp) VALUES (?, ?, ?)")->execute([$prod_name, $batch, $mrp]); }
                                $caches['batch'][$bKey] = true;
                            }
                        }

                        if ($mode === 'delete') {
                            $stmtI = $qPrep("DELETE FROM invoices WHERE invoice_no = ? AND product_name = ? AND batch_no = ?"); $stmtI->execute([$inv_no, $prod_name, $batch]);
                            if ($stmtI->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else {
                            $stmtI = $qPrep("INSERT INTO invoices (partyname, invoice_no, invoice_date, product_name, batch_no, qty, free_qty, total_qty, mrp, total_value) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE partyname = VALUES(partyname), invoice_date = VALUES(invoice_date), qty = VALUES(qty), free_qty = VALUES(free_qty), total_qty = VALUES(total_qty), mrp = VALUES(mrp), total_value = VALUES(total_value)");
                            $stmtI->execute([$partyname, $inv_no, $inv_date, $prod_name, $batch, $qty, $free_qty, $total_qty, $mrp, $total_val]);
                            $affected = $stmtI->rowCount();
                            if ($affected == 1) { /* count handled outside loop */ } elseif ($affected == 2) { $isUpdated = true; } else { $isSkipped = true; }
                        }
                        break;
                    
                    case 'users':
                        $uname = trim($data[0] ?? ''); if (empty($uname)) throw new Exception("Username blank");
                        if ($mode === 'delete') { 
                            $stmt = $qPrep("DELETE FROM users WHERE username = ? AND id != ?"); $stmt->execute([$uname, $_SESSION['user_id']]); 
                            if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else { 
                            $check = $qPrep("SELECT id FROM users WHERE username = ?"); $check->execute([$uname]); 
                            if ($check->fetchColumn()) { 
                                if ($mode === 'add_update') { $stmt = $qPrep("UPDATE users SET fullname=?, email=?, role=? WHERE username=?"); $stmt->execute([trim($data[1] ?? ''), trim($data[2] ?? ''), trim($data[4] ?? ''), $uname]); if ($stmt->rowCount() > 0) $isUpdated = true; else $isSkipped = true; } else { $isSkipped = true; } 
                            } else { 
                                $hash = password_hash(trim($data[3] ?? ''), PASSWORD_DEFAULT); $qPrep("INSERT INTO users (username, fullname, email, password, role) VALUES (?, ?, ?, ?, ?)")->execute([$uname, trim($data[1] ?? ''), trim($data[2] ?? ''), $hash, trim($data[4] ?? '')]); 
                            } 
                        }
                        break;
                    
                    case 'divisions':
                    case 'departments':
                        $name = trim($data[0] ?? ''); if(empty($name)) throw new Exception("Name blank");
                        $col = $type === 'divisions' ? 'divname' : 'deptname';
                        if ($mode === 'delete') { $stmt = $qPrep("DELETE FROM {$type} WHERE {$col}=?"); $stmt->execute([$name]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else { $stmt = $qPrep("INSERT IGNORE INTO {$type} ({$col}) VALUES (?)"); $stmt->execute([$name]); if ($stmt->rowCount() == 0) $isSkipped = true; }
                        break;

                    case 'countries':
                        $countryName = trim($data[0] ?? ''); if (empty($countryName)) throw new Exception("Country Name is blank");
                        if ($mode === 'delete') { $stmt = $qPrep("DELETE FROM countries WHERE countryname = ?"); $stmt->execute([$countryName]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else { $stmt = $qPrep("INSERT IGNORE INTO countries (countryname) VALUES (?)"); $stmt->execute([$countryName]); if ($stmt->rowCount() == 0) $isSkipped = true; }
                        break;

                    case 'states':
                        if (count($data) < 2) throw new Exception("Missing state mapping columns");
                        $countryName = trim($data[0] ?? ''); $stateName = trim($data[1] ?? '');
                        if (empty($countryName)) $countryName = 'India'; if (empty($stateName)) throw new Exception("State Name required");
                        $cLower = strtolower($countryName);
                        if (!isset($caches['country'][$cLower])) { $stmtC = $qPrep("SELECT id FROM countries WHERE TRIM(LOWER(countryname)) = ?"); $stmtC->execute([$cLower]); $cId = $stmtC->fetchColumn(); if (!$cId) { $qPrep("INSERT INTO countries (countryname) VALUES (?)")->execute([$countryName]); $cId = $pdo->lastInsertId(); } $caches['country'][$cLower] = $cId; }
                        $cId = $caches['country'][$cLower];

                        $stmtCheck = $qPrep("SELECT id FROM states WHERE countryid = ? AND TRIM(LOWER(statename)) = TRIM(LOWER(?))"); $stmtCheck->execute([$cId, $stateName]); $exists = $stmtCheck->fetchColumn();
                        if ($mode === 'delete') {
                            if ($exists) { $stmt = $qPrep("DELETE FROM states WHERE countryid = ? AND TRIM(LOWER(statename)) = TRIM(LOWER(?))"); $stmt->execute([$cId, $stateName]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true; } else { $isSkipped = true; }
                        } else { if ($exists) { $isSkipped = true; } else { $qPrep("INSERT INTO states (countryid, statename) VALUES (?, ?)")->execute([$cId, $stateName]); } }
                        break;

                    case 'districts':
                        if (count($data) < 3) throw new Exception("Missing District columns");
                        $countryName = trim($data[0] ?? ''); $stateName = trim($data[1] ?? ''); $distName = trim($data[2] ?? '');
                        if (empty($countryName)) $countryName = 'India'; if (empty($stateName) || empty($distName)) throw new Exception("State Name and District Name are mandatory.");
                        $cLower = strtolower($countryName);
                        if (!isset($caches['country'][$cLower])) { $stmtC = $qPrep("SELECT id FROM countries WHERE TRIM(LOWER(countryname)) = ?"); $stmtC->execute([$cLower]); $cId = $stmtC->fetchColumn(); if (!$cId) { $qPrep("INSERT INTO countries (countryname) VALUES (?)")->execute([$countryName]); $cId = $pdo->lastInsertId(); } $caches['country'][$cLower] = $cId; }
                        $cId = $caches['country'][$cLower];

                        $sLower = $cId . '_' . strtolower($stateName);
                        if (!isset($caches['state'][$sLower])) { $stmtS = $qPrep("SELECT id FROM states WHERE countryid = ? AND TRIM(LOWER(statename)) = TRIM(LOWER(?))"); $stmtS->execute([$cId, $stateName]); $sId = $stmtS->fetchColumn(); if (!$sId) { $qPrep("INSERT INTO states (countryid, statename) VALUES (?, ?)")->execute([$cId, $stateName]); $sId = $pdo->lastInsertId(); } $caches['state'][$sLower] = $sId; }
                        $sId = $caches['state'][$sLower];

                        $stmtCheck = $qPrep("SELECT id FROM districts WHERE stateid = ? AND TRIM(LOWER(districtname)) = TRIM(LOWER(?))"); $stmtCheck->execute([$sId, $distName]); $exists = $stmtCheck->fetchColumn();
                        if ($mode === 'delete') {
                            if ($exists) { $stmt = $qPrep("DELETE FROM districts WHERE stateid = ? AND TRIM(LOWER(districtname)) = TRIM(LOWER(?))"); $stmt->execute([$sId, $distName]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true; } else { $isSkipped = true; }
                        } else { if ($exists) { $isSkipped = true; } else { $qPrep("INSERT INTO districts (stateid, districtname) VALUES (?, ?)")->execute([$sId, $distName]); } }
                        break;

                    case 'hqs':
                        if (count($data) < 4) throw new Exception("Missing HQ columns");
                        $countryName = trim($data[0] ?? ''); $stateName = trim($data[1] ?? ''); $distName = trim($data[2] ?? ''); $hqName = trim($data[3] ?? '');
                        if (empty($countryName)) $countryName = 'India'; if (empty($stateName) || empty($distName) || empty($hqName)) throw new Exception("Inputs cannot be empty.");
                        $cLower = strtolower($countryName);
                        if (!isset($caches['country'][$cLower])) { $stmtC = $qPrep("SELECT id FROM countries WHERE TRIM(LOWER(countryname)) = ?"); $stmtC->execute([$cLower]); $cId = $stmtC->fetchColumn(); if (!$cId) { $qPrep("INSERT INTO countries (countryname) VALUES (?)")->execute([$countryName]); $cId = $pdo->lastInsertId(); } $caches['country'][$cLower] = $cId; }
                        $cId = $caches['country'][$cLower];

                        $sLower = $cId . '_' . strtolower($stateName);
                        if (!isset($caches['state'][$sLower])) { $stmtS = $qPrep("SELECT id FROM states WHERE countryid = ? AND TRIM(LOWER(statename)) = TRIM(LOWER(?))"); $stmtS->execute([$cId, $stateName]); $sId = $stmtS->fetchColumn(); if (!$sId) { $qPrep("INSERT INTO states (countryid, statename) VALUES (?, ?)")->execute([$cId, $stateName]); $sId = $pdo->lastInsertId(); } $caches['state'][$sLower] = $sId; }
                        $sId = $caches['state'][$sLower];

                        $dKey = $sId . '_' . strtolower($distName);
                        if (!isset($caches['district'][$dKey])) { $stmtD = $qPrep("SELECT id FROM districts WHERE stateid = ? AND TRIM(LOWER(districtname)) = ?"); $stmtD->execute([$sId, strtolower($distName)]); $distId = $stmtD->fetchColumn(); if (!$distId) { $qPrep("INSERT INTO districts (stateid, districtname) VALUES (?, ?)")->execute([$sId, $distName]); $distId = $pdo->lastInsertId(); } $caches['district'][$dKey] = $distId; }
                        $distId = $caches['district'][$dKey];

                        $stmtCheckHQ = $qPrep("SELECT id FROM hqs WHERE districtid = ? AND TRIM(LOWER(hqname)) = TRIM(LOWER(?))"); $stmtCheckHQ->execute([$distId, $hqName]); $existsHQ = $stmtCheckHQ->fetchColumn();
                        if ($mode === 'delete') {
                            if ($existsHQ) { $stmt = $qPrep("DELETE FROM hqs WHERE districtid = ? AND TRIM(LOWER(hqname)) = TRIM(LOWER(?))"); $stmt->execute([$distId, $hqName]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true; } else { $isSkipped = true; }
                        } else { if ($existsHQ) { $isSkipped = true; } else { $qPrep("INSERT INTO hqs (districtid, hqname) VALUES (?, ?)")->execute([$distId, $hqName]); } }
                        break;

                    case 'cities':
                    case 'geography_cascade':
                        if (count($data) < 5) throw new Exception("Missing cascade hierarchy columns");
                        $countryName = trim($data[0] ?? ''); $stateName = trim($data[1] ?? ''); $distName = trim($data[2] ?? ''); $hqName = trim($data[3] ?? ''); $cityName = trim($data[4] ?? '');
                        if (empty($countryName)) $countryName = 'India'; if (empty($stateName) || empty($distName) || empty($hqName) || empty($cityName)) throw new Exception("All Geography chain parameters are mandatory.");
                        $cLower = strtolower($countryName);
                        if (!isset($caches['country'][$cLower])) { $stmtC = $qPrep("SELECT id FROM countries WHERE TRIM(LOWER(countryname)) = ?"); $stmtC->execute([$cLower]); $cId = $stmtC->fetchColumn(); if (!$cId) { $qPrep("INSERT INTO countries (countryname) VALUES (?)")->execute([$countryName]); $cId = $pdo->lastInsertId(); } $caches['country'][$cLower] = $cId; }
                        $cId = $caches['country'][$cLower];

                        $sLower = $cId . '_' . strtolower($stateName);
                        if (!isset($caches['state'][$sLower])) { $stmtS = $qPrep("SELECT id FROM states WHERE countryid = ? AND TRIM(LOWER(statename)) = TRIM(LOWER(?))"); $stmtS->execute([$cId, $stateName]); $sId = $stmtS->fetchColumn(); if (!$sId) { $qPrep("INSERT INTO states (countryid, statename) VALUES (?, ?)")->execute([$cId, $stateName]); $sId = $pdo->lastInsertId(); } $caches['state'][$sLower] = $sId; }
                        $sId = $caches['state'][$sLower];

                        $dKey = $sId . '_' . strtolower($distName);
                        if (!isset($caches['district'][$dKey])) { $stmtD = $qPrep("SELECT id FROM districts WHERE stateid = ? AND TRIM(LOWER(districtname)) = ?"); $stmtD->execute([$sId, strtolower($distName)]); $distId = $stmtD->fetchColumn(); if (!$distId) { $qPrep("INSERT INTO districts (stateid, districtname) VALUES (?, ?)")->execute([$sId, $distName]); $distId = $pdo->lastInsertId(); } $caches['district'][$dKey] = $distId; }
                        $distId = $caches['district'][$dKey];

                        $hqKey = $distId . '_' . strtolower($hqName);
                        if (!isset($caches['hq'][$hqKey])) { $stmtH = $qPrep("SELECT id FROM hqs WHERE districtid = ? AND TRIM(LOWER(hqname)) = ?"); $stmtH->execute([$distId, strtolower($hqName)]); $hqId = $stmtH->fetchColumn(); if (!$hqId) { $qPrep("INSERT INTO hqs (districtid, hqname) VALUES (?, ?)")->execute([$distId, $hqName]); $hqId = $pdo->lastInsertId(); } $caches['hq'][$hqKey] = $hqId; }
                        $hqId = $caches['hq'][$hqKey];

                        $stmtCheckCity = $qPrep("SELECT id FROM cities WHERE hqid = ? AND TRIM(LOWER(cityname)) = TRIM(LOWER(?))"); $stmtCheckCity->execute([$hqId, $cityName]); $existsCity = $stmtCheckCity->fetchColumn();
                        if ($mode === 'delete') {
                            if ($existsCity) { $stmt = $qPrep("DELETE FROM cities WHERE hqid = ? AND TRIM(LOWER(cityname)) = TRIM(LOWER(?))"); $stmt->execute([$hqId, $cityName]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true; } else { $isSkipped = true; }
                        } else { if ($existsCity) { $isSkipped = true; } else { $qPrep("INSERT INTO cities (hqid, cityname) VALUES (?, ?)")->execute([$hqId, $cityName]); } }
                        break;

                    case 'designations':
                        if (count($data) < 2) throw new Exception("Incomplete columns");
                        $deptName = trim($data[0] ?? ''); $desigName = trim($data[1] ?? '');
                        if ($mode === 'delete') { 
                            $stmt = $qPrep("DELETE FROM designations WHERE designame=?"); $stmt->execute([$desigName]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else { 
                            $stmtD = $qPrep("SELECT deptid FROM departments WHERE deptname = ?"); $stmtD->execute([$deptName]); $deptid = $stmtD->fetchColumn(); 
                            if (!$deptid) throw new Exception("Department not found"); 
                            $check = $qPrep("SELECT desigid FROM designations WHERE designame = ?"); $check->execute([$desigName]); 
                            if ($check->fetchColumn()) { 
                                if($mode === 'add_update') { $stmt = $qPrep("UPDATE designations SET deptid=? WHERE designame=?"); $stmt->execute([$deptid, $desigName]); if ($stmt->rowCount() > 0) $isUpdated = true; else $isSkipped = true; } else { $isSkipped = true; } 
                            } else { $qPrep("INSERT INTO designations (deptid, designame) VALUES (?, ?)")->execute([$deptid, $desigName]); } 
                        }
                        break;
                        
                    case 'employees':
                        // 24 Fields Check
                        if (count($data) < 24) throw new Exception("Incomplete columns (Requires exactly 24)");
                        
                        $empcode = trim($data[1] ?? ''); 
                        
                        // Auto-generate Employee Code if blank
                        if (empty($empcode)) {
                            if ($mode === 'delete') throw new Exception("Employee Code is required for deletion");
                            
                            $stmtLast = $pdo->query("SELECT empcode FROM employees WHERE empcode LIKE 'E%' ORDER BY LENGTH(empcode) DESC, empcode DESC LIMIT 1");
                            $lastCode = $stmtLast->fetchColumn();
                            if ($lastCode && preg_match('/^E(\d+)$/', $lastCode, $m)) {
                                $empcode = 'E' . str_pad(intval($m[1]) + 1, strlen($m[1]), '0', STR_PAD_LEFT);
                            } else {
                                $empcode = 'E00001';
                            }
                        }
                        
                        $annivDate = parseInvoiceDate(trim($data[4] ?? ''));
                        $joinDate  = parseInvoiceDate(trim($data[10] ?? ''));
                        $dobDate   = parseInvoiceDate(trim($data[11] ?? ''));
                        $resigDate = parseInvoiceDate(trim($data[12] ?? ''));

                        // SUPER SMART LOGIC: Auto-resolve Name to Code for Reporting Manager
                        $reporting_manager = trim($data[14] ?? '');
                        if (!empty($reporting_manager) && $mode !== 'delete') {
                            $checkCode = $qPrep("SELECT empcode FROM employees WHERE empcode = ?");
                            $checkCode->execute([$reporting_manager]);
                            if (!$checkCode->fetchColumn()) {
                                // Code match nahi hua, ab check karo ki kya yeh Name hai?
                                $checkName = $qPrep("SELECT empcode FROM employees WHERE TRIM(LOWER(empname)) = TRIM(LOWER(?)) AND (resignation_date IS NULL OR resignation_date = '0000-00-00' OR resignation_date = '')");
                                $checkName->execute([$reporting_manager]);
                                $matchedCodes = $checkName->fetchAll(PDO::FETCH_COLUMN);
                                
                                if (count($matchedCodes) == 1) {
                                    // Exactly 1 Active Manager mila, automatic Code convert!
                                    $reporting_manager = $matchedCodes[0];
                                } elseif (count($matchedCodes) > 1) {
                                    // Name Clash (Multiple log same naam ke hain)
                                    throw new Exception("Hierarchy Error: Multiple active managers named '{$reporting_manager}' found. Please use exact Employee Code instead of name.");
                                }
                            }
                        }

                        if ($mode === 'delete') { 
                            $stmt = $qPrep("DELETE FROM employees WHERE empcode = ?"); $stmt->execute([$empcode]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else { 
                            $check = $qPrep("SELECT id FROM employees WHERE empcode = ?"); $check->execute([$empcode]); 
                            if ($check->fetchColumn()) { 
                                if ($mode === 'add_update') { 
                                    $stmt = $qPrep("UPDATE employees SET division=?, empname=?, marital_status=?, anniversary_date=?, department=?, designation=?, mobile=?, email=?, gender=?, joining_date=?, dob=?, resignation_date=?, hq=?, reporting_manager=?, state=?, bank_name=?, bank_account=?, ifsc_code=?, aadhar_no=?, pan_no=?, dl_no=?, education=?, address=? WHERE empcode=?");
                                    $stmt->execute([
                                        trim($data[0] ?? ''), trim($data[2] ?? ''), trim($data[3] ?? ''), $annivDate, 
                                        trim($data[5] ?? ''), trim($data[6] ?? ''), trim($data[7] ?? ''), trim($data[8] ?? ''), trim($data[9] ?? ''), 
                                        $joinDate, $dobDate, $resigDate, trim($data[13] ?? ''), $reporting_manager, trim($data[15] ?? ''), 
                                        trim($data[16] ?? ''), trim($data[17] ?? ''), trim($data[18] ?? ''), trim($data[19] ?? ''), 
                                        trim($data[20] ?? ''), trim($data[21] ?? ''), trim($data[22] ?? ''), trim($data[23] ?? ''), $empcode
                                    ]); 
                                    if ($stmt->rowCount() > 0) $isUpdated = true; else $isSkipped = true;
                                } else { $isSkipped = true; } 
                            } else { 
                                $stmt = $qPrep("INSERT INTO employees (division, empcode, empname, marital_status, anniversary_date, department, designation, mobile, email, gender, joining_date, dob, resignation_date, hq, reporting_manager, state, bank_name, bank_account, ifsc_code, aadhar_no, pan_no, dl_no, education, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                                $stmt->execute([
                                    trim($data[0] ?? ''), $empcode, trim($data[2] ?? ''), trim($data[3] ?? ''), $annivDate, 
                                    trim($data[5] ?? ''), trim($data[6] ?? ''), trim($data[7] ?? ''), trim($data[8] ?? ''), trim($data[9] ?? ''), 
                                    $joinDate, $dobDate, $resigDate, trim($data[13] ?? ''), $reporting_manager, trim($data[15] ?? ''), 
                                    trim($data[16] ?? ''), trim($data[17] ?? ''), trim($data[18] ?? ''), trim($data[19] ?? ''), 
                                    trim($data[20] ?? ''), trim($data[21] ?? ''), trim($data[22] ?? ''), trim($data[23] ?? '')
                                ]); 
                            } 
                        }
                        break;
                        
                    case 'parties':
                        if (count($data) < 7) throw new Exception("Incomplete columns");
                        $partycode = trim($data[2] ?? ''); $state = trim($data[1] ?? '');
                        if ($mode === 'delete') { 
                            if (empty($partycode)) throw new Exception("Party Code required for deletion"); 
                            $stmt = $qPrep("DELETE FROM parties WHERE partycode = ?"); $stmt->execute([$partycode]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else { 
                            $exists = false; if (!empty($partycode)) { $check = $qPrep("SELECT id FROM parties WHERE partycode = ?"); $check->execute([$partycode]); $exists = $check->fetchColumn(); } 
                            if ($exists) { 
                                if ($mode === 'add_update') { $stmt = $qPrep("UPDATE parties SET division=?, state=?, partyname=?, hq=?, area=?, contact=? WHERE partycode=?"); $stmt->execute([trim($data[0] ?? ''), $state, trim($data[3] ?? ''), trim($data[4] ?? ''), trim($data[5] ?? ''), trim($data[6] ?? ''), $partycode]); if ($stmt->rowCount() > 0) $isUpdated = true; else $isSkipped = true; } else { $isSkipped = true; } 
                            } else { 
                                if (empty($partycode)) { 
                                    if(empty($state)) throw new Exception("State required to auto-generate code"); 
                                    $prefix = getStatePrefix($state); $stmt = $qPrep("SELECT partycode FROM parties WHERE partycode LIKE ? ORDER BY LENGTH(partycode) DESC, partycode DESC LIMIT 1"); $stmt->execute([$prefix . '%']); $lastCode = $stmt->fetchColumn(); 
                                    $partycode = ($lastCode && preg_match('/^'.$prefix.'(\d+)$/', $lastCode, $m)) ? $prefix . str_pad(intval($m[1]) + 1, 4, '0', STR_PAD_LEFT) : $prefix . '0001'; 
                                } 
                                $qPrep("INSERT INTO parties (division, state, partycode, partyname, hq, area, contact) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([trim($data[0] ?? ''), $state, $partycode, trim($data[3] ?? ''), trim($data[4] ?? ''), trim($data[5] ?? ''), trim($data[6] ?? '')]); 
                            } 
                        }
                        break;
                        
                    case 'assignparties':
                        if (count($data) < 3) throw new Exception("Incomplete columns");
                        $empcode = trim($data[0] ?? ''); $pname = trim($data[1] ?? '');
                        
                        $checkEmp = $qPrep("SELECT id FROM employees WHERE empcode = ?"); $checkEmp->execute([$empcode]);
                        if (!$checkEmp->fetchColumn() && $mode !== 'delete') { throw new Exception("Validation Error: Employee '{$empcode}' does not exist."); }

                        if ($mode === 'delete') { 
                            $stmt = $qPrep("DELETE FROM assignparties WHERE empcode=? AND partyname=?"); $stmt->execute([$empcode,$pname]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else { 
                            $check = $qPrep("SELECT id FROM assignparties WHERE empcode = ? AND partyname = ?"); $check->execute([$empcode, $pname]); 
                            if ($check->fetchColumn()) { 
                                if($mode === 'add_update') { $stmt = $qPrep("UPDATE assignparties SET percentage=? WHERE empcode=? AND partyname=?"); $stmt->execute([intval(trim($data[2] ?? '0')), $empcode,$pname]); if ($stmt->rowCount() > 0) $isUpdated = true; else $isSkipped = true; } else { $isSkipped = true; } 
                            } else { $qPrep("INSERT INTO assignparties (empcode, partyname, percentage) VALUES (?, ?, ?)")->execute([$empcode, $pname, intval(trim($data[2] ?? '0'))]); } 
                        }
                        break;
                        
                    case 'transporters':
                        if (count($data) < 3) throw new Exception("Incomplete columns");
                        $tname = trim($data[0] ?? '');
                        if ($mode === 'delete') { 
                            $stmt = $qPrep("DELETE FROM transporters WHERE transportername=?"); $stmt->execute([$tname]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else { 
                            $check = $qPrep("SELECT id FROM transporters WHERE transportername = ?"); $check->execute([$tname]); 
                            if ($check->fetchColumn()) { 
                                if($mode === 'add_update') { $stmt = $qPrep("UPDATE transporters SET servicearea=?, phone=? WHERE transportername=?"); $stmt->execute([trim($data[1] ?? ''), trim($data[2] ?? ''),$tname]); if ($stmt->rowCount() > 0) $isUpdated = true; else $isSkipped = true; } else { $isSkipped = true; } 
                            } else { $qPrep("INSERT INTO transporters (transportername, servicearea, phone) VALUES (?, ?, ?)")->execute([$tname, trim($data[1] ?? ''), trim($data[2] ?? '')]); } 
                        }
                        break;
                        
                    case 'senders':
                        if (count($data) < 2) throw new Exception("Incomplete columns");
                        $sname = trim($data[0] ?? '');
                        if ($mode === 'delete') { 
                            $stmt = $qPrep("DELETE FROM senders WHERE sendername=?"); $stmt->execute([$sname]); if ($stmt->rowCount() > 0) $isDeleted = true; else $isSkipped = true;
                        } else { 
                            $check = $qPrep("SELECT id FROM senders WHERE sendername = ?"); $check->execute([$sname]); 
                            if ($check->fetchColumn()) { 
                                if($mode === 'add_update') { $stmt = $qPrep("UPDATE senders SET mobile=? WHERE sendername=?"); $stmt->execute([trim($data[1] ?? ''),$sname]); if ($stmt->rowCount() > 0) $isUpdated = true; else $isSkipped = true; } else { $isSkipped = true; } 
                            } else { $qPrep("INSERT INTO senders (sendername, mobile) VALUES (?, ?)")->execute([$sname, trim($data[1] ?? '')]); } 
                        }
                        break;
                }
                
                if (!in_array($type, ['targets', 'collections_mr', 'collections_party'])) {
                    if ($isSkipped) { $skippedCount++; } elseif ($isDeleted) { $deletedCount++; } elseif ($isUpdated) { $updatedCount++; } else { $successCount++; }
                }

            } catch (Throwable $e) { 
                $errorCount++;
                if (count($errors) < 50) $errors[] = "Row {$rowNum}: " . $e->getMessage();
            }
        }
        
        try { 
            if ($pdo->inTransaction()) { $pdo->commit(); } 
            $userNameSession = $_SESSION['fullname'] ?? 'System User';
            $qPrep("INSERT INTO upload_logs (entity_name, uploaded_by, uploaded_on) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE uploaded_by = VALUES(uploaded_by), uploaded_on = NOW()")->execute([$type, $userNameSession]);
        } catch (Exception $e) { }

        $msg = "Inserted/Processed: {$successCount}. Updated: {$updatedCount}. Deleted: {$deletedCount}. Skipped: {$skippedCount}.";
        if ($errorCount > 0) $msg .= " Errors/Crashes: {$errorCount}.";
        
        echo json_encode(["status" => "success", "message" => $msg, "errors" => $errors]);
        exit();
    } else {
        echo json_encode(["status" => "error", "message" => "File unreadable."]); exit();
    }
}

$uploadLogs = [];
try { $uploadLogs = $pdo->query("SELECT entity_name, uploaded_by, DATE_FORMAT(uploaded_on, '%d/%m/%Y') as uploaded_on FROM upload_logs")->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC); } catch (Exception $e) { }

$entitiesMapping = [
    'states'            => ['label' => 'States', 'log_key' => 'states'],
    'cities'            => ['label' => 'Cities / Delivery Areas', 'log_key' => 'cities'],
    'districts'         => ['label' => 'Districts', 'log_key' => 'districts'],
    'hqs'               => ['label' => 'Headquarters (HQs)', 'log_key' => 'hqs'],
    'countries'         => ['label' => 'Countries', 'log_key' => 'countries'],
    'geography_cascade' => ['label' => 'Full Geography Cascade (CSV)', 'log_key' => 'geography_cascade'],
    'divisions'         => ['label' => 'Divisions', 'log_key' => 'divisions'],
    'departments'       => ['label' => 'Departments', 'log_key' => 'departments'],
    'designations'      => ['label' => 'Designations', 'log_key' => 'designations'],
    'employees'         => ['label' => 'Employees (MRs)', 'log_key' => 'employees'],
    'parties'           => ['label' => 'Parties / Clients', 'log_key' => 'parties'],
    'assignparties'     => ['label' => 'Employee-Party Mapping', 'log_key' => 'assignparties'],
    'transporters'      => ['label' => 'Transporters', 'log_key' => 'transporters'],
    'senders'           => ['label' => 'Dispatch Senders', 'log_key' => 'senders'],
    'invoices'          => ['label' => 'Invoices / Sales Data', 'log_key' => 'invoices'],
    'products'          => ['label' => 'Product Master', 'log_key' => 'products'],
    'product_batches'   => ['label' => 'Product Batches', 'log_key' => 'product_batches'],
    'targets'           => ['label' => 'Yearly Targets', 'log_key' => 'targets'],
    'collections_mr'    => ['label' => 'Payment Collections (MR Wise)', 'log_key' => 'collections_mr'],
    'collections_party' => ['label' => 'Payment Collections (Party Wise)', 'log_key' => 'collections_party'],
    'users'             => ['label' => 'System Users', 'log_key' => 'users']
];

$pageTitle = "Master Upload Configuration Studio";
include __DIR__ . '/../includes/header.php';
?>


<div class="upload-container">
    <div class="page-main-header">Master Upload</div>
    <form id="bulkUploadForm">
        <table class="ledger-table">
            <thead>
                <tr>
                    <th style="width: 25px; text-align: center;"></th>
                    <th style="width: 280px;">Name</th>
                    <th>Last Uploaded By</th>
                    <th style="width: 150px;">Last Uploaded On</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($entitiesMapping as $key => $opt): 
                    $by = htmlspecialchars($uploadLogs[$key]['uploaded_by'] ?? '');
                    $on = htmlspecialchars($uploadLogs[$key]['uploaded_on'] ?? '');
                ?>
                    <tr id="row_<?= $key ?>" onclick="selectUploadEntity('<?= $key ?>')">
                        <td style="text-align: center;">
                            <input type="radio" name="master_type" value="<?= $key ?>" id="radio_<?= $key ?>" class="radio-input" onclick="event.stopPropagation(); selectUploadEntity('<?= $key ?>')">
                        </td>
                        <td><a href="javascript:void(0)" class="lbl-link"><?= htmlspecialchars($opt['label']) ?></a></td>
                        <td style="color: #2563eb;" id="by_<?= $key ?>"><?= $by ?></td>
                        <td style="color: #475569;" id="on_<?= $key ?>"><?= $on ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="upload-options-row">
            <span style="font-weight: 700; color: #1e2a78; margin-right: 5px;">Execution Mode:</span>
            <label><input type="radio" name="upload_mode" value="add_only" checked> New / Addition</label>
            <label><input type="radio" name="upload_mode" value="add_update"> Details Updation</label>
            <label><input type="radio" name="upload_mode" value="delete"> Bulk Deletion</label>
        </div>

        <div class="file-input-section">
            <span style="width: 70px; display: inline-block;">Select File</span>
            <label class="custom-file-btn" for="csvFile">Choose File</label>
            <input type="file" name="csv_file" id="csvFile" accept=".csv" style="display: none;" onchange="handleFileSelection(this)">
            <span id="fileNameBadge" style="font-weight: normal; color: #475569; margin-left: 5px;">No file chosen</span>
        </div>
        <div class="notic-warn">Note: Upload only *.csv format file</div>

        <div class="action-btn-row">
            <button type="button" class="ctrl-btn" onclick="checkFileData()">Check Data</button>
            <button type="button" class="ctrl-btn" id="btnSaveData" onclick="submitUpload(event)">Save Data</button>
            <button type="button" class="ctrl-btn" onclick="resetUploadForm()">Decline</button>
            <button type="button" class="ctrl-btn" onclick="downloadMasterTemplate()">Download Template</button>
        </div>
    </form>
    <div id="errorConsole" class="error-console">
        <h4>Processing Exceptions Log</h4>
        <ul id="errorList"></ul>
    </div>
</div>

<script>
    let activeEntity = null;
    function selectUploadEntity(entityKey) {
        if (activeEntity) { const prevRow = document.getElementById(`row_${activeEntity}`); if (prevRow) prevRow.classList.remove('row-selected'); }
        activeEntity = entityKey;
        const radio = document.getElementById(`radio_${entityKey}`); if (radio) radio.checked = true;
        const nextRow = document.getElementById(`row_${entityKey}`); if (nextRow) nextRow.classList.add('row-selected');
    }
    function handleFileSelection(input) {
        const badge = document.getElementById('fileNameBadge');
        if (input.files && input.files[0]) {
            badge.innerText = input.files[0].name; badge.style.color = '#1e3a8a'; badge.style.fontWeight = 'bold';
        } else {
            badge.innerText = "No file chosen"; badge.style.color = '#475569'; badge.style.fontWeight = 'normal';
        }
    }
    function resetUploadForm() {
        document.getElementById('bulkUploadForm').reset();
        document.getElementById('fileNameBadge').innerText = "No file chosen"; document.getElementById('fileNameBadge').style.color = '#475569'; document.getElementById('fileNameBadge').style.fontWeight = 'normal';
        if (activeEntity) { const activeRow = document.getElementById(`row_${activeEntity}`); if (activeRow) activeRow.classList.remove('row-selected'); activeEntity = null; }
        document.getElementById('errorConsole').style.display = 'none'; document.getElementById('errorList').innerHTML = '';
    }
    function downloadMasterTemplate() {
        if (!activeEntity) { alert("Validation Fail: Please select a Master entity first."); return; }
        const mode = document.querySelector('input[name="upload_mode"]:checked').value;
        const fill = (mode === 'add_update' || mode === 'delete') ? 'existing' : 'blank';
        window.location.href = `masterupload.php?action=template&type=${activeEntity}&fill=${fill}`;
    }
    function checkFileData() {
        if (!activeEntity) { alert("Verification Failed: Please select a Master row."); return; }
        if (!document.getElementById('csvFile').value) { alert("Verification Failed: Please choose a valid file first."); return; }
        alert("Success: Checked! Vrified correctly.");
    }
    function escapeHtml(t) { return String(t||'').replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;").replace(/'/g,"&#039;"); }
    
    function submitUpload(e) {
        e.preventDefault();
        const fileInput = document.getElementById('csvFile');
        const btnSave = document.getElementById('btnSaveData');
        const errBlock = document.getElementById('errorConsole');
        const errList = document.getElementById('errorList');
        const uploadMode = document.querySelector('input[name="upload_mode"]:checked').value;

        if (!activeEntity) { alert("Validation Fail: Please select a Master Entity."); return; }
        if (!fileInput.value) { alert("Validation Fail: Choose a file."); return; }

        btnSave.disabled = true; btnSave.innerText = "Processing..."; errBlock.style.display = 'none'; errList.innerHTML = '';

        const fd = new FormData();
        fd.append('csv_file', fileInput.files[0]); fd.append('master_type', activeEntity); fd.append('upload_mode', uploadMode); fd.append('ajax_action', 'upload');

        fetch('', { method: 'POST', body: fd })
        .then(async res => {
            const txt = await res.text();
            try { return JSON.parse(txt); } catch(err) { throw new Error(txt); }
        })
        .then(data => {
            btnSave.disabled = false; btnSave.innerText = "Save Data";
            if (data.status === 'success') {
                alert("Transaction Complete!\n\n" + data.message.replace(/<\/?[^>]+(>|$)/g, ""));
                const today = new Date(); const dd = String(today.getDate()).padStart(2, '0'); const mm = String(today.getMonth() + 1).padStart(2, '0'); const yyyy = today.getFullYear();
                const userSessionName = "<?= htmlspecialchars($_SESSION['fullname'] ?? 'System User') ?>"; const dateText = `${dd}/${mm}/${yyyy}`;
                const targetByEl = document.getElementById(`by_${activeEntity}`); const targetOnEl = document.getElementById(`on_${activeEntity}`);
                if (targetByEl) targetByEl.innerText = userSessionName; if (targetOnEl) targetOnEl.innerText = dateText;
                resetUploadForm();
            } else { alert("Import Failed!\n\nReason: " + data.message); }
            if (data.errors && data.errors.length > 0) { errBlock.style.display = 'block'; errList.innerHTML = data.errors.map(err => `<li>${escapeHtml(err)}</li>`).join(''); }
        })
        .catch(err => {
            btnSave.disabled = false; btnSave.innerText = "Save Data";
            alert("Network Error / Server Crash!\n\n" + err.message);
            errBlock.style.display = 'block'; errList.innerHTML = `<li><strong>Dynamic Parser Fail:</strong><br>${escapeHtml(err.message)}</li>`; errBlock.scrollIntoView({ behavior: 'smooth' });
        });
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>