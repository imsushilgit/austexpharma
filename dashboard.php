<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit();
}

require_once('includes/core.php');

// ============================================================
// 1. LOGGED-IN USER CONTEXT
// ============================================================
$stmtUser = $pdo->prepare("
    SELECT u.role, u.username, u.fullname, u.empcode AS u_empcode,
           e.empname, e.designation, e.department, e.hq, e.state, e.empcode AS e_empcode
    FROM users u
    LEFT JOIN employees e 
        ON u.empcode = e.empcode 
        AND e.resignation_date IS NULL
    WHERE u.id = ?
    LIMIT 1
");
$stmtUser->execute([$_SESSION['user_id']]);
$userMeta = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

$userRole     = $userMeta['role'] ?? 'Staff';
$empCode      = !empty($userMeta['u_empcode']) ? $userMeta['u_empcode'] : (!empty($userMeta['e_empcode']) ? $userMeta['e_empcode'] : ($userMeta['username'] ?? ''));
$empName      = !empty($userMeta['empname']) ? $userMeta['empname'] : (!empty($userMeta['fullname']) ? $userMeta['fullname'] : ($userMeta['username'] ?? 'User'));
$designation  = $userMeta['designation'] ?? 'MR';
$department   = strtoupper(trim($userMeta['department'] ?? 'SALES'));
$userHq       = $userMeta['hq'] ?? '';
$userState    = $userMeta['state'] ?? '';

// ============================================================
// 2. UNIVERSAL ROLE & DEPARTMENT SCOPE DETECTION
// ============================================================
$isAdmin    = ($userRole === 'Admin');
$isSales    = ($department === 'SALES' || $department === 'MARKETING' || empty($department));
$isDispatch = ($department === 'DISPATCH' || $department === 'LOGISTICS' || strtoupper($designation) === 'DISPATCH');
$isHR       = ($department === 'HR' || $department === 'ADMIN');
$isAccounts = ($department === 'ACCOUNTS' || $department === 'FINANCE');
$isPurchase = ($department === 'PURCHASE' || $department === 'PROCUREMENT');

$isManager = false;
$isMR      = false;

if ($isSales && !$isAdmin) {
    if (!empty($designation) && strtoupper(trim($designation)) !== 'MR') {
        $isManager = true;
    } else {
        $isMR = true;
    }
}

// ============================================================
// 3. DYNAMIC PAGE PERMISSIONS FETCH
// ============================================================
$allowedPages = [];
try {
    $permStmt = $pdo->prepare("SELECT page_name FROM page_permissions WHERE role = ? OR role = ?");
    $permStmt->execute([$userRole, $designation]);
    $rawPages = $permStmt->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rawPages as $p) {
        $allowedPages[] = strtolower(trim($p));
    }
} catch (Exception $e) {}

$useDynamicPerms = count($allowedPages) > 0;

function canAccess($page, $allowedPages, $isAdmin, $useDynamicPerms, $defaultAccess) {
    if ($isAdmin) return true;
    if ($useDynamicPerms) {
        return in_array(strtolower(trim($page)), $allowedPages);
    }
    return $defaultAccess;
}

// ============================================================
// 4. DATE RANGES (Indian FY: Apr – Mar)
// ============================================================
$currentYear  = (int)date('Y');
$currentMonth = (int)date('n');
$fyStartYear  = ($currentMonth >= 4) ? $currentYear : $currentYear - 1;
$fyStartDate  = sprintf('%d-04-01', $fyStartYear);
$fyEndDate    = sprintf('%d-03-31', $fyStartYear + 1);
$today        = date('Y-m-d');
$mtdStartDate = date('Y-m-01');
$fyLabel      = 'FY ' . $fyStartYear . '-' . substr($fyStartYear + 1, 2);
$monthLabel   = date('F Y');

// ============================================================
// 5. KPI + DATA FETCH (ROLE-AWARE)
// ============================================================
$primaryYTD = 0.0; $secondaryYTD = 0.0; $targetYTD = 0.0;
$primaryMTD = 0.0; $secondaryMTD = 0.0; $targetMTD = 0.0;
$recentInvoices = []; $recentCollections = [];
$topParties = []; $globalTopEmployees = []; $monthlyTrend = [];
$teamSize = 0; $partyCount = 0;
$dispatchPending = 0; $dispatchInvoiced = 0; $dispatchDispatched = 0;
$dispatchDelivered = 0; $dispatchHold = 0; $dispatchTotalCases = 0;
$topHQs = [];
$collectionTrend = [];

$myDoctors = 0; $myPendingBilling = 0; $myRejectedOrders = 0; $myPlacedOrders = 0;
$myLeaveTaken = 0; $myLeaveStatus = 'No recent'; $myExpenseYTD = 0;
$myDispatchPending = 0; $myDispatched = 0;

$totalEmployees = 0; $activeEmployees = 0; 
$pendingLeaves = 0; $pendingExpenses = 0; $pendingTourPlans = 0; 
$absentToday = 0; $presentToday = 0;

$wPendingOrders = 0; $wPendingBilling = 0;

$cteSQL = "
    WITH RECURSIVE Subordinates AS (
        SELECT empcode, empname, designation, 0 AS depth
        FROM employees
        WHERE empcode = ?
        UNION ALL
        SELECT e.empcode, e.empname, e.designation, s.depth + 1
        FROM employees e
        INNER JOIN Subordinates s
            ON (TRIM(e.reporting_manager) = TRIM(s.empcode) OR TRIM(e.reporting_manager) = TRIM(s.empname))
        WHERE s.depth < 6 AND UPPER(TRIM(s.designation)) != 'MR'
    )
";

try {
    $stmtGlobal = $pdo->prepare("SELECT ap.empcode, COALESCE(e.empname, ap.empcode) AS empname, SUM(i.total_value) AS val FROM invoices i INNER JOIN assignparties ap ON i.partyname = ap.partyname LEFT JOIN employees e ON e.empcode = ap.empcode WHERE i.invoice_date BETWEEN ? AND ? GROUP BY ap.empcode, e.empname ORDER BY val DESC LIMIT 8");
    $stmtGlobal->execute([$fyStartDate, $today]);
    $globalTopEmployees = $stmtGlobal->fetchAll(PDO::FETCH_ASSOC);

    if ($isAdmin) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(sales_target), 0) AS ytd, COALESCE(SUM(CASE WHEN target_month = ? AND target_year = ? THEN sales_target ELSE 0 END), 0) AS mtd FROM targets WHERE (target_year = ? AND target_month >= 4) OR (target_year = ? AND target_month <= 3)");
        $stmt->execute([$currentMonth, $currentYear, $fyStartYear, $fyStartYear + 1]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $targetYTD = (float)($row['ytd'] ?? 0); $targetMTD = (float)($row['mtd'] ?? 0);

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN total_value ELSE 0 END), 0) AS ytd, COALESCE(SUM(CASE WHEN invoice_date BETWEEN ? AND ? THEN total_value ELSE 0 END), 0) AS mtd FROM invoices WHERE invoice_date >= ?");
        $stmt->execute([$fyStartDate, $today, $mtdStartDate, $today, $fyStartDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $primaryYTD = (float)($row['ytd'] ?? 0); $primaryMTD = (float)($row['mtd'] ?? 0);

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN collection_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS ytd, COALESCE(SUM(CASE WHEN collection_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS mtd FROM collections WHERE collection_date >= ?");
        $stmt->execute([$fyStartDate, $today, $mtdStartDate, $today, $fyStartDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $secondaryYTD = (float)($row['ytd'] ?? 0); $secondaryMTD = (float)($row['mtd'] ?? 0);

        $teamSize   = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE resignation_date IS NULL OR resignation_date = '' OR resignation_date = '0000-00-00'")->fetchColumn();
        $partyCount = (int)$pdo->query("SELECT COUNT(*) FROM parties")->fetchColumn();
        $activeEmployees = $teamSize;

        $disp = $pdo->query("SELECT SUM(status = 'Invoiced') AS invoiced, SUM(status = 'Dispatched') AS dispatched, SUM(status = 'Delivered') AS delivered, SUM(status = 'Hold') AS hold, COALESCE(SUM(cases), 0) AS total_cases FROM dispatch_records")->fetch(PDO::FETCH_ASSOC);
        $dispatchInvoiced   = (int)($disp['invoiced'] ?? 0);
        $dispatchDispatched = (int)($disp['dispatched'] ?? 0);
        $dispatchDelivered  = (int)($disp['delivered'] ?? 0);
        $dispatchHold       = (int)($disp['hold'] ?? 0);
        $dispatchTotalCases = (int)($disp['total_cases'] ?? 0);

        $dispatchPending = (int)$pdo->query("SELECT COUNT(DISTINCT i.invoice_no) FROM invoices i LEFT JOIN dispatch_records d ON i.invoice_no = d.invoice_no AND d.status IN ('Dispatched','Delivered') WHERE d.id IS NULL AND i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetchColumn();

        $recentInvoices = $pdo->query("SELECT invoice_no, invoice_date, partyname, total_value FROM invoices ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        $recentCollections = $pdo->query("SELECT collection_date, partyname, amount, payment_mode FROM collections ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT partyname, SUM(total_value) AS val FROM invoices WHERE invoice_date BETWEEN ? AND ? GROUP BY partyname ORDER BY val DESC LIMIT 8");
        $stmt->execute([$fyStartDate, $today]); $topParties = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $monthlyTrend = $pdo->query("SELECT DATE_FORMAT(invoice_date, '%Y-%m') AS ym, SUM(total_value) AS val FROM invoices WHERE invoice_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym ORDER BY ym ASC")->fetchAll(PDO::FETCH_ASSOC);
        $collectionTrend = $pdo->query("SELECT DATE_FORMAT(collection_date, '%Y-%m') AS ym, SUM(amount) AS val FROM collections WHERE collection_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym ORDER BY ym ASC")->fetchAll(PDO::FETCH_ASSOC);
        $topHQs = $pdo->query("SELECT hq, COUNT(*) AS total FROM dispatch_records GROUP BY hq ORDER BY total DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);

        try { $pendingLeaves = (int)$pdo->query("SELECT COUNT(*) FROM leave_applications WHERE status='Pending'")->fetchColumn(); } catch(Exception $e){}
        try { $pendingExpenses = (int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE status='Pending'")->fetchColumn(); } catch(Exception $e){}
        try { $pendingTourPlans = (int)$pdo->query("SELECT COUNT(*) FROM tour_plans WHERE status='Pending'")->fetchColumn(); } catch(Exception $e){}
        try { $absentToday = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE att_date=CURDATE() AND status='Absent'")->fetchColumn(); } catch(Exception $e){}
        try { $presentToday = (int)$pdo->query("SELECT COUNT(*) FROM attendance WHERE att_date=CURDATE() AND status='Present'")->fetchColumn(); } catch(Exception $e){}
        try { $wPendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status IN ('Submitted','Manager_Approved','Pending_Billing')")->fetchColumn(); } catch(Exception $e){}
        try { $wPendingBilling = (int)$pdo->query("SELECT COUNT(*) FROM sales_orders WHERE status='Pending_Billing'")->fetchColumn(); } catch(Exception $e){}

    } elseif ($isManager || $isMR) {
        if ($isManager) {
            $stmt = $pdo->prepare($cteSQL . " SELECT DISTINCT empcode, empname FROM Subordinates");
            $stmt->execute([$empCode]);
            $teamRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $teamCodes = array_column($teamRows, 'empcode');
            $teamCodes = array_unique(array_filter($teamCodes));
            $teamSize  = count($teamCodes);
            if (empty($teamCodes)) { $teamCodes = [$empCode]; $teamSize = 1; }
        } else {
            $teamCodes = [$empCode];
            $teamSize = 1;
        }

        $ph = implode(',', array_fill(0, count($teamCodes), '?'));

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(sales_target), 0) AS ytd, COALESCE(SUM(CASE WHEN target_month = ? AND target_year = ? THEN sales_target ELSE 0 END), 0) AS mtd FROM targets WHERE ((target_year = ? AND target_month >= 4) OR (target_year = ? AND target_month <= 3)) AND empcode IN ($ph)");
        $stmt->execute(array_merge([$currentMonth, $currentYear, $fyStartYear, $fyStartYear + 1], $teamCodes));
        $row = $stmt->fetch(PDO::FETCH_ASSOC); $targetYTD = (float)($row['ytd'] ?? 0); $targetMTD = (float)($row['mtd'] ?? 0);

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN i.invoice_date BETWEEN ? AND ? THEN i.total_value ELSE 0 END), 0) AS ytd, COALESCE(SUM(CASE WHEN i.invoice_date BETWEEN ? AND ? THEN i.total_value ELSE 0 END), 0) AS mtd FROM invoices i INNER JOIN assignparties ap ON i.partyname = ap.partyname WHERE ap.empcode IN ($ph) AND i.invoice_date >= ?");
        $stmt->execute(array_merge([$fyStartDate, $today, $mtdStartDate, $today], $teamCodes, [$fyStartDate]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC); $primaryYTD = (float)($row['ytd'] ?? 0); $primaryMTD = (float)($row['mtd'] ?? 0);

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN collection_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS ytd, COALESCE(SUM(CASE WHEN collection_date BETWEEN ? AND ? THEN amount ELSE 0 END), 0) AS mtd FROM collections WHERE empcode IN ($ph) AND collection_date >= ?");
        $stmt->execute(array_merge([$fyStartDate, $today, $mtdStartDate, $today], $teamCodes, [$fyStartDate]));
        $row = $stmt->fetch(PDO::FETCH_ASSOC); $secondaryYTD = (float)($row['ytd'] ?? 0); $secondaryMTD = (float)($row['mtd'] ?? 0);

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT partyname) FROM assignparties WHERE empcode IN ($ph)");
        $stmt->execute($teamCodes); $partyCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT i.invoice_no, i.invoice_date, i.partyname, i.total_value FROM invoices i INNER JOIN assignparties ap ON i.partyname = ap.partyname WHERE ap.empcode IN ($ph) ORDER BY i.id DESC LIMIT 10");
        $stmt->execute($teamCodes); $recentInvoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT collection_date, partyname, amount, payment_mode FROM collections WHERE empcode IN ($ph) ORDER BY id DESC LIMIT 10");
        $stmt->execute($teamCodes); $recentCollections = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT i.partyname, SUM(i.total_value) AS val FROM invoices i INNER JOIN assignparties ap ON i.partyname = ap.partyname WHERE ap.empcode IN ($ph) AND i.invoice_date BETWEEN ? AND ? GROUP BY i.partyname ORDER BY val DESC LIMIT 8");
        $stmt->execute(array_merge($teamCodes, [$fyStartDate, $today])); $topParties = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT DATE_FORMAT(i.invoice_date, '%Y-%m') AS ym, SUM(i.total_value) AS val FROM invoices i INNER JOIN assignparties ap ON i.partyname = ap.partyname WHERE ap.empcode IN ($ph) AND i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym ORDER BY ym ASC");
        $stmt->execute($teamCodes); $monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT DATE_FORMAT(collection_date, '%Y-%m') AS ym, SUM(amount) AS val FROM collections WHERE empcode IN ($ph) AND collection_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY ym ORDER BY ym ASC");
        $stmt->execute($teamCodes); $collectionTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM doctors WHERE empcode IN ($ph)");
            $stmt->execute($teamCodes); $myDoctors = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT 
                SUM(CASE WHEN status='Pending_Billing' THEN 1 ELSE 0 END) as pb, 
                SUM(CASE WHEN status='Rejected' THEN 1 ELSE 0 END) as rej, 
                COUNT(*) as total_placed 
                FROM sales_orders WHERE empcode IN ($ph)");
            $stmt->execute($teamCodes);
            $ordRow = $stmt->fetch(PDO::FETCH_ASSOC);
            $myPendingBilling = (int)($ordRow['pb'] ?? 0);
            $myRejectedOrders = (int)($ordRow['rej'] ?? 0);
            $myPlacedOrders = (int)($ordRow['total_placed'] ?? 0);

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leave_applications WHERE status='Approved' AND empcode IN ($ph) AND (from_date BETWEEN ? AND ?)");
            $stmt->execute(array_merge($teamCodes, [$fyStartDate, $fyEndDate]));
            $myLeaveTaken = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT status FROM leave_applications WHERE empcode = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$empCode]); 
            $myLeaveStatus = $stmt->fetchColumn() ?: 'None';

            $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE empcode IN ($ph) AND expense_date BETWEEN ? AND ?");
            $stmt->execute(array_merge($teamCodes, [$fyStartDate, $fyEndDate]));
            $myExpenseYTD = (float)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT i.invoice_no) FROM invoices i INNER JOIN assignparties ap ON i.partyname = ap.partyname LEFT JOIN dispatch_records d ON i.invoice_no = d.invoice_no AND d.status IN ('Dispatched','Delivered') WHERE ap.empcode IN ($ph) AND d.id IS NULL AND i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
            $stmt->execute($teamCodes); $myDispatchPending = (int)$stmt->fetchColumn();

            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT d.invoice_no) FROM dispatch_records d INNER JOIN invoices i ON d.invoice_no = i.invoice_no INNER JOIN assignparties ap ON i.partyname = ap.partyname WHERE ap.empcode IN ($ph) AND d.status IN ('Dispatched', 'Delivered') AND i.invoice_date >= ?");
            $stmt->execute(array_merge($teamCodes, [$fyStartDate])); $myDispatched = (int)$stmt->fetchColumn();

            if ($isManager) {
                $stL = $pdo->prepare("SELECT COUNT(*) FROM leave_applications WHERE status='Pending' AND empcode IN ($ph)");
                $stL->execute($teamCodes); $pendingLeaves = (int)$stL->fetchColumn();
                $stE = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE status='Pending' AND empcode IN ($ph)");
                $stE->execute($teamCodes); $pendingExpenses = (int)$stE->fetchColumn();
                $stT = $pdo->prepare("SELECT COUNT(*) FROM tour_plans WHERE status='Pending' AND empcode IN ($ph)");
                $stT->execute($teamCodes); $pendingTourPlans = (int)$stT->fetchColumn();
            }
        } catch (Exception $e) {}
    }

    if ($isDispatch && !$isAdmin) {
        $disp = $pdo->query("SELECT SUM(status = 'Invoiced') AS invoiced, SUM(status = 'Dispatched') AS dispatched, SUM(status = 'Delivered') AS delivered, SUM(status = 'Hold') AS hold, COALESCE(SUM(cases), 0) AS total_cases FROM dispatch_records")->fetch(PDO::FETCH_ASSOC);
        $dispatchInvoiced   = (int)($disp['invoiced'] ?? 0);
        $dispatchDispatched = (int)($disp['dispatched'] ?? 0);
        $dispatchDelivered  = (int)($disp['delivered'] ?? 0);
        $dispatchHold       = (int)($disp['hold'] ?? 0);
        $dispatchTotalCases = (int)($disp['total_cases'] ?? 0);
        $dispatchPending = (int)$pdo->query("SELECT COUNT(DISTINCT i.invoice_no) FROM invoices i LEFT JOIN dispatch_records d ON i.invoice_no = d.invoice_no AND d.status IN ('Dispatched','Delivered') WHERE d.id IS NULL AND i.invoice_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)")->fetchColumn();
        $topHQs = $pdo->query("SELECT hq, COUNT(*) AS total FROM dispatch_records GROUP BY hq ORDER BY total DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
    }

} catch (Exception $e) {
    error_log("Dashboard KPI error: " . $e->getMessage());
}

$primaryAchPct   = $targetYTD > 0 ? round(($primaryYTD / $targetYTD) * 100, 1) : 0;
$mtdAchPct       = $targetMTD > 0 ? round(($primaryMTD / $targetMTD) * 100, 1) : 0;
$salesGap        = $primaryYTD - $secondaryYTD;
$collectionEff   = $primaryYTD > 0 ? round(($secondaryYTD / $primaryYTD) * 100, 1) : 0;

function achColor($pct) {
    if ($pct >= 100) return '#059669';
    if ($pct >= 80)  return '#d97706';
    if ($pct >= 50)  return '#2563eb';
    return '#dc2626';
}
function achBg($pct) {
    if ($pct >= 100) return '#ecfdf5';
    if ($pct >= 80)  return '#fffbeb';
    if ($pct >= 50)  return '#eff6ff';
    return '#fef2f2';
}
function fmtINR($n) {
    if ($n >= 10000000) return '₹' . number_format($n / 10000000, 2) . ' Cr';
    if ($n >= 100000)   return '₹' . number_format($n / 100000, 2) . ' L';
    return '₹' . number_format($n, 0);
}

$pageTitle = "Dashboard";
include __DIR__ . '/includes/header.php';
?>

<style>
/* ============================================================
   MODERN DASHBOARD CSS (Clean, SaaS-style)
   ============================================================ */
body.pg-dashboard-dashboard {
    --card-shadow: 0 2px 12px rgba(15, 23, 42, 0.06);
    --card-shadow-hover: 0 12px 32px rgba(15, 23, 42, 0.12);
    --radius: 16px;
    --radius-sm: 10px;
    --navy: #1e293b;       
    --navy-950: #0f172a;   
    --sky: #0ea5e9;        
    --line: #e2e8f0;       
}

body.pg-dashboard-dashboard { background: #f8fafc; color: var(--navy-950); font-family: 'Inter', sans-serif; }

/* Header & Context Banner */
body.pg-dashboard-dashboard .dash-header { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 24px; }
body.pg-dashboard-dashboard .context-banner {
    background: #ffffff; border: 1px solid var(--line); border-left: 5px solid var(--sky); border-radius: var(--radius-sm);
    padding: 18px 24px; display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; gap: 16px;
    font-size: 13px; flex: 1; box-shadow: var(--card-shadow);
}
body.pg-dashboard-dashboard .role-badge { background: var(--navy); color: #ffffff; padding: 6px 14px; border-radius: 20px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
body.pg-dashboard-dashboard .fy-pill { background: #f1f5f9; color: var(--navy); border: 1px solid var(--line); padding: 6px 16px; border-radius: 20px; font-weight: 600; font-size: 11.5px; }

/* Section Titles */
body.pg-dashboard-dashboard .ops-section-title {
    font-family: 'Sora', sans-serif; font-size: 14px; font-weight: 700; color: var(--navy-950); text-transform: uppercase; 
    margin: 32px 0 16px; padding-bottom: 10px; border-bottom: 2px solid var(--line); display: flex; justify-content: space-between; align-items: center; letter-spacing: 0.5px;
}
body.pg-dashboard-dashboard .ops-section-title .badge-sub { font-size: 10px; font-weight: 600; color: #64748b; background: #f1f5f9; padding: 4px 12px; border-radius: 12px; text-transform: none; letter-spacing: normal; }

/* Navigation Hub */
body.pg-dashboard-dashboard .nav-hub { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 14px; margin-bottom: 28px; }
body.pg-dashboard-dashboard .nav-tile {
    background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 18px 12px; text-decoration: none; text-align: center; transition: all 0.2s ease; box-shadow: 0 1px 3px rgba(0,0,0,0.02); display: flex; flex-direction: column; align-items: center; gap: 8px;
}
body.pg-dashboard-dashboard .nav-tile:hover { transform: translateY(-4px); box-shadow: var(--card-shadow-hover); border-color: var(--sky); }
body.pg-dashboard-dashboard .nav-tile .icon { font-size: 26px; line-height: 1; margin-bottom: 4px; }
body.pg-dashboard-dashboard .nav-tile .lbl { font-size: 11px; font-weight: 700; color: var(--navy-950); text-transform: uppercase; letter-spacing: 0.5px; }
body.pg-dashboard-dashboard .nav-tile .sub { font-size: 10px; color: #64748b; }
body.pg-dashboard-dashboard .nav-tile.sales { border-top: 3px solid #3b82f6; }
body.pg-dashboard-dashboard .nav-tile.hr { border-top: 3px solid #8b5cf6; }
body.pg-dashboard-dashboard .nav-tile.dispatch { border-top: 3px solid #f59e0b; }
body.pg-dashboard-dashboard .nav-tile.purchase { border-top: 3px solid #06b6d4; }
body.pg-dashboard-dashboard .nav-tile.admin { border-top: 3px solid #ef4444; }
body.pg-dashboard-dashboard .nav-tile.reports { border-top: 3px solid #ec4899; }

/* KPI Cards */
body.pg-dashboard-dashboard .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px; }
body.pg-dashboard-dashboard .kpi-card { background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius-sm); padding: 20px; position: relative; overflow: hidden; transition: all 0.3s; box-shadow: var(--card-shadow); }
body.pg-dashboard-dashboard .kpi-card:hover { box-shadow: var(--card-shadow-hover); transform: translateY(-3px); }
body.pg-dashboard-dashboard .kpi-card::before { content: ''; position: absolute; top: 0; left: 0; width: 4px; height: 100%; background: var(--accent, var(--sky)); border-radius: 4px 0 0 4px; }
body.pg-dashboard-dashboard .kpi-label { font-size: 11px; color: #64748b; text-transform: uppercase; font-weight: 700; margin-bottom: 8px; letter-spacing: 0.5px; }
body.pg-dashboard-dashboard .kpi-value { font-size: 24px; font-family: 'Sora', sans-serif; font-weight: 800; color: var(--navy-950); margin-bottom: 8px; }
body.pg-dashboard-dashboard .kpi-sub { font-size: 10.5px; font-weight: 600; display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 20px; }
body.pg-dashboard-dashboard .progress-bar { height: 6px; background: #f1f5f9; border-radius: 8px; margin-top: 12px; overflow: hidden; }
body.pg-dashboard-dashboard .progress-fill { height: 100%; border-radius: 8px; transition: width 1s ease-in-out; }

/* Quick Actions & Status Pills */
body.pg-dashboard-dashboard .quick-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
body.pg-dashboard-dashboard .btn-action { background: #ffffff; color: var(--navy-950); border: 1px solid var(--line); padding: 10px 18px; border-radius: var(--radius-sm); font-size: 11px; font-weight: 700; text-transform: uppercase; text-decoration: none; transition: all 0.2s; display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 1px 2px rgba(0,0,0,0.03); letter-spacing: 0.5px; }
body.pg-dashboard-dashboard .btn-action:hover { background: #f8fafc; border-color: var(--sky); transform: translateY(-2px); box-shadow: var(--card-shadow); }
body.pg-dashboard-dashboard .status-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(110px, 1fr)); gap: 14px; }
body.pg-dashboard-dashboard .status-pill { background: #ffffff; text-align: center; padding: 18px 12px; border-radius: var(--radius-sm); border: 1px solid var(--line); transition: all 0.2s; box-shadow: var(--card-shadow); }
body.pg-dashboard-dashboard .status-pill:hover { transform: translateY(-3px); box-shadow: var(--card-shadow-hover); border-color: var(--sky); }
body.pg-dashboard-dashboard .status-pill .s-num { font-size: 24px; font-weight: 800; font-family: 'Sora', sans-serif; }
body.pg-dashboard-dashboard .status-pill .s-lbl { font-size: 10px; text-transform: uppercase; color: #64748b; margin-top: 6px; font-weight: 700; letter-spacing: 0.5px; }

/* Dashboard Panels (Charts & Tables) */
body.pg-dashboard-dashboard .dash-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 24px; }
@media (max-width: 950px) { body.pg-dashboard-dashboard .dash-grid { grid-template-columns: 1fr; } }
body.pg-dashboard-dashboard .panel-card { background: #ffffff; border: 1px solid var(--line); border-radius: var(--radius); padding: 24px; box-shadow: var(--card-shadow); transition: box-shadow 0.3s; }
body.pg-dashboard-dashboard .panel-card:hover { box-shadow: var(--card-shadow-hover); }
body.pg-dashboard-dashboard .panel-title { font-family: 'Sora', sans-serif; font-size: 13px; font-weight: 700; color: var(--navy-950); text-transform: uppercase; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid var(--line); display: flex; justify-content: space-between; align-items: center; letter-spacing: 0.5px; }
body.pg-dashboard-dashboard .btn-viewall { font-size: 10px; color: var(--sky); font-weight: 700; text-decoration: none; text-transform: uppercase; padding: 4px 8px; border-radius: 6px; transition: 0.2s; }
body.pg-dashboard-dashboard .btn-viewall:hover { background: #f0f9ff; color: #0369a1; }
body.pg-dashboard-dashboard .chart-wrap { position: relative; height: 260px; width: 100%; }
body.pg-dashboard-dashboard .chart-wrap.sm { height: 220px; }

/* Compact Tables */
body.pg-dashboard-dashboard .compact-table { width: 100%; border-collapse: collapse; }
body.pg-dashboard-dashboard .compact-table th, body.pg-dashboard-dashboard .compact-table td { padding: 12px 10px; font-size: 12px; border-bottom: 1px solid #f1f5f9; }
body.pg-dashboard-dashboard .compact-table th { color: #64748b; font-weight: 700; text-transform: uppercase; font-size: 10px; background: #f8fafc; border-bottom: 2px solid var(--line); letter-spacing: 0.5px; }
body.pg-dashboard-dashboard .compact-table tbody tr:hover td { background: #f8fafc; }
body.pg-dashboard-dashboard .compact-table tbody tr:last-child td { border-bottom: none; }
body.pg-dashboard-dashboard .val-cell { font-family: 'Sora', sans-serif; text-align: right; font-weight: 700; color: #0f172a; }
body.pg-dashboard-dashboard .rank { display: inline-flex; align-items: center; justify-content: center; width: 24px; height: 24px; border-radius: 50%; font-size: 10.5px; font-weight: 700; color: #ffffff; }
body.pg-dashboard-dashboard .rank-1 { background: #f59e0b; }
body.pg-dashboard-dashboard .rank-2 { background: #94a3b8; }
body.pg-dashboard-dashboard .rank-3 { background: #b45309; }
body.pg-dashboard-dashboard .rank-n { background: #e2e8f0; color: #475569; }
body.pg-dashboard-dashboard .empty-state { text-align: center; padding: 40px 20px; font-size: 13px; color: #94a3b8; background: #f8fafc; border-radius: var(--radius-sm); border: 1px dashed var(--line); }

/* Remove old app overrides causing conflicts */
body.app .kpi-card, body.app .pulse-card, body.app .panel-card { border-style: solid !important; padding: 20px !important; }
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>

<!-- ===================== HEADER CONTEXT ===================== -->
<div class="dash-header">
    <div class="context-banner">
        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
            <span class="role-badge"><?= htmlspecialchars($isAdmin ? 'ADMIN' : strtoupper($designation)) ?></span>
            <strong style="color:var(--navy-950); font-size:15px;"><?= htmlspecialchars($empName) ?></strong>
            <?php if ($userHq): ?>
                <span style="color:#64748b; font-size:11px;">• <?= htmlspecialchars($userHq) ?><?= $userState ? ' / ' . htmlspecialchars($userState) : '' ?></span>
            <?php endif; ?>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
           
            <span class="fy-pill"><?= $fyLabel ?> • <?= date('d M Y') ?></span>
        </div>
    </div>
</div>

<!-- ===================== LIVE PULSE / ALERTS (Admin Only) ===================== -->
<?php if ($isAdmin): ?>
<div class="ops-section-title">
    <span>Live Operational Pulse</span>
    <span class="badge-sub">Real-time counters (Admin)</span>
</div>
<div class="pulse-strip">
    <a href="billing/orders.php" class="pulse-card">
        <div class="pulse-icon" style="background:#eff6ff; color:#2563eb;">🛒</div>
        <div class="pulse-info"><div class="p-num"><?= (int)$wPendingOrders ?></div><div class="p-lbl">Orders in Flow</div></div>
    </a>
    <a href="billing/billing.php" class="pulse-card">
        <div class="pulse-icon" style="background:#fff7ed; color:#ea580c;">🧾</div>
        <div class="pulse-info"><div class="p-num"><?= (int)$wPendingBilling ?></div><div class="p-lbl">Pending Billing</div></div>
    </a>
    <a href="hr/leaveapproval.php" class="pulse-card">
        <div class="pulse-icon" style="background:#fef3c7; color:#d97706;">🏖️</div>
        <div class="pulse-info"><div class="p-num"><?= (int)$pendingLeaves ?></div><div class="p-lbl">Leave Pending</div></div>
    </a>
    <a href="fieldforce/expenseapproval.php" class="pulse-card">
        <div class="pulse-icon" style="background:#fce7f3; color:#db2777;">💳</div>
        <div class="pulse-info"><div class="p-num"><?= (int)$pendingExpenses ?></div><div class="p-lbl">Expense Pending</div></div>
    </a>
    <a href="fieldforce/tourplanapproval.php" class="pulse-card">
        <div class="pulse-icon" style="background:#ede9fe; color:#7c3aed;">📅</div>
        <div class="pulse-info"><div class="p-num"><?= (int)$pendingTourPlans ?></div><div class="p-lbl">STP Pending</div></div>
    </a>
    <a href="hr/attendance.php" class="pulse-card">
        <div class="pulse-icon" style="background:#fef2f2; color:#dc2626;">⏱️</div>
        <div class="pulse-info"><div class="p-num"><?= (int)$absentToday ?></div><div class="p-lbl">Absent Today</div></div>
    </a>
    <a href="dispatch/dispatch.php" class="pulse-card">
        <div class="pulse-icon" style="background:#fef2f2; color:#dc2626;">🚚</div>
        <div class="pulse-info"><div class="p-num"><?= (int)$dispatchPending ?></div><div class="p-lbl">Dispatch Pending</div></div>
    </a>
</div>
<?php endif; ?>

<!-- ===================== PRIMARY KPI CARDS (Sales) ===================== -->
<?php if ($isSales || $isAdmin || $isAccounts): ?>
<div class="ops-section-title">
    <span>Sales & Collection KPIs</span>
    <span class="badge-sub"><?= $fyLabel ?> • Scope: <?= $viewContext ?? 'All Scope' ?></span>
</div>
<div class="kpi-grid">
    <div class="kpi-card" style="--accent:#3b82f6;">
        <div class="kpi-label">MTD Primary Sales</div>
        <div class="kpi-value"><?= fmtINR($primaryMTD) ?></div>
        <?php if ($targetMTD > 0): ?>
            <div class="kpi-sub" style="background:<?= achBg($mtdAchPct) ?>; color:<?= achColor($mtdAchPct) ?>;"><?= $mtdAchPct ?>% of MTD Target</div>
            <div class="progress-bar"><div class="progress-fill" style="width:<?= min(100, $mtdAchPct) ?>%; background:<?= achColor($mtdAchPct) ?>;"></div></div>
        <?php else: ?>
            <div class="kpi-sub" style="background:#eff6ff; color:#1d4ed8;"><?= $monthLabel ?></div>
        <?php endif; ?>
    </div>
    <div class="kpi-card" style="--accent:#10b981;">
        <div class="kpi-label">MTD Collections</div>
        <div class="kpi-value" style="color:#059669;"><?= fmtINR($secondaryMTD) ?></div>
        <div class="kpi-sub" style="background:#ecfdf5; color:#047857;">Money in this month</div>
    </div>
    <div class="kpi-card" style="--accent:#1e2a78;">
        <div class="kpi-label">YTD Primary Sales</div>
        <div class="kpi-value"><?= fmtINR($primaryYTD) ?></div>
        <div class="kpi-sub" style="background:<?= achBg($primaryAchPct) ?>; color:<?= achColor($primaryAchPct) ?>;"><?= $primaryAchPct ?>% of Target</div>
        <div class="progress-bar"><div class="progress-fill" style="width:<?= min(100, $primaryAchPct) ?>%; background:<?= achColor($primaryAchPct) ?>;"></div></div>
    </div>
    <div class="kpi-card" style="--accent:#059669;">
        <div class="kpi-label">YTD Collections</div>
        <div class="kpi-value" style="color:#059669;"><?= fmtINR($secondaryYTD) ?></div>
        <div class="kpi-sub" style="background:#ecfdf5; color:#047857;"><?= $collVsPrimary ?? 0 ?>% of Primary</div>
    </div>
    <div class="kpi-card" style="--accent:#c9982e;">
        <div class="kpi-label">YTD Sales Target</div>
        <div class="kpi-value" style="color:#b45309;"><?= fmtINR($targetYTD) ?></div>
        <div class="kpi-sub" style="background:#fffbeb; color:#b45309;"><?= $fyLabel ?></div>
    </div>
    <div class="kpi-card" style="--accent:<?= $salesGap >= 0 ? '#ef4444' : '#059669' ?>;">
        <div class="kpi-label"><?= $salesGap >= 0 ? 'Outstanding (YTD)' : 'Net Recovery (YTD)' ?></div>
        <div class="kpi-value" style="color:<?= $salesGap >= 0 ? '#dc2626' : '#059669' ?>;"><?= fmtINR(abs($salesGap)) ?></div>
        <div class="kpi-sub" style="background:<?= $salesGap >= 0 ? '#fef2f2' : '#ecfdf5' ?>; color:<?= $salesGap >= 0 ? '#b91c1c' : '#047857' ?>;"><?= $salesGap >= 0 ? 'Billing − Collections' : 'Collections > Billing' ?></div>
    </div>
</div>

<!-- Secondary metrics -->
<div class="metric-strip">
    <?php if ($isAdmin || $isManager): ?>
        <div class="metric-item"><div class="num"><?= number_format($teamSize) ?></div><div class="lbl"><?= $isAdmin ? 'Total Employees' : 'Team Size' ?></div></div>
    <?php endif; ?>
    <div class="metric-item"><div class="num"><?= number_format($partyCount) ?></div><div class="lbl"><?= $isMR ? 'My Parties' : 'Parties in Scope' ?></div></div>
    <div class="metric-item"><div class="num" style="color:<?= achColor($primaryAchPct) ?>"><?= $primaryAchPct ?>%</div><div class="lbl">Primary Achievement</div></div>
    <div class="metric-item"><div class="num" style="color:<?= $collectionEff >= 100 ? '#059669' : achColor($collectionEff) ?>"><?= $collectionEff ?>%</div><div class="lbl">Collection Efficiency</div></div>
    <?php if ($isAdmin && $dispatchPending > 0): ?>
        <div class="metric-item" style="border-color:#fecaca;"><div class="num" style="color:#dc2626;"><?= number_format($dispatchPending) ?></div><div class="lbl">Pending Dispatch</div></div>
    <?php endif; ?>
    <?php if ($isAdmin && $dispatchTotalCases > 0): ?>
        <div class="metric-item"><div class="num"><?= number_format($dispatchTotalCases) ?></div><div class="lbl">Cases Moved</div></div>
    <?php endif; ?>
    <div class="metric-item"><div class="num" style="color:#2563eb;"><?= $mtdAchPct ?>%</div><div class="lbl">MTD Achievement</div></div>
</div>
<?php endif; ?>

<!-- ===================== MY ACTIVITY & PIPELINE (MR & MANAGER) ===================== -->
<?php if (!$isAdmin && ($isMR || $isManager)): ?>
<div class="ops-section-title">
    <span>My Activity & Pipeline</span>
    <span class="badge-sub"><?= $isManager ? 'Team Data Rollup' : 'My Data' ?></span>
</div>
<div class="status-grid" style="margin-bottom:20px;">
    <!-- Field Data -->
    <a href="fieldforce/doctors.php" class="status-pill" style="background:#f8fafc; border-color:#e2e8f0; text-decoration:none;">
        <div class="s-num" style="color:var(--navy);"><?= number_format($myDoctors) ?></div>
        <div class="s-lbl">Total Doctors</div>
    </a>
    <a href="masters/parties.php" class="status-pill" style="background:#f8fafc; border-color:#e2e8f0; text-decoration:none;">
        <div class="s-num" style="color:var(--navy);"><?= number_format($partyCount) ?></div>
        <div class="s-lbl">My Parties</div>
    </a>
    
    <!-- Orders Data -->
    <a href="billing/orders.php" class="status-pill" style="background:#eff6ff; border-color:#bfdbfe; text-decoration:none;">
        <div class="s-num" style="color:#2563eb;"><?= number_format($myPlacedOrders) ?></div>
        <div class="s-lbl">Orders Placed</div>
    </a>
    <a href="billing/orders.php" class="status-pill" style="background:#fff7ed; border-color:#fed7aa; text-decoration:none;">
        <div class="s-num" style="color:#ea580c;"><?= number_format($myPendingBilling) ?></div>
        <div class="s-lbl">Pending Billing</div>
    </a>
    <a href="billing/orders.php" class="status-pill" style="background:#fef2f2; border-color:#fecaca; text-decoration:none;">
        <div class="s-num" style="color:#dc2626;"><?= number_format($myRejectedOrders) ?></div>
        <div class="s-lbl">Rejected Orders</div>
    </a>

    <!-- Dispatch Tracking -->
    <a href="sales/primary.php" class="status-pill" style="background:#ecfdf5; border-color:#a7f3d0; text-decoration:none;">
        <div class="s-num" style="color:#059669;"><?= number_format($myDispatched) ?></div>
        <div class="s-lbl">Dispatched</div>
    </a>
    <a href="sales/primary.php" class="status-pill" style="background:#fef3c7; border-color:#fde68a; text-decoration:none;">
        <div class="s-num" style="color:#d97706;"><?= number_format($myDispatchPending) ?></div>
        <div class="s-lbl">Pending Dispatch</div>
    </a>

    <!-- HR & Expenses -->
    <a href="hr/leave.php" class="status-pill" style="background:#f5f3ff; border-color:#ddd6fe; text-decoration:none;">
        <div class="s-num" style="color:#7c3aed;"><?= number_format($myLeaveTaken) ?></div>
        <div class="s-lbl">Leaves (YTD)</div>
    </a>
    <a href="hr/leave.php" class="status-pill" style="background:#f8fafc; border-color:#e2e8f0; text-decoration:none;">
        <div class="s-num" style="color:#475569; font-size:15px; margin-top:5px; margin-bottom:5px;"><?= htmlspecialchars($myLeaveStatus) ?></div>
        <div class="s-lbl">Recent Leave</div>
    </a>
    <a href="fieldforce/expense.php" class="status-pill" style="background:#fdf2f8; border-color:#fbcfe8; text-decoration:none;">
        <div class="s-num" style="color:#db2777; font-size:18px; line-height:22px;"><?= fmtINR($myExpenseYTD) ?></div>
        <div class="s-lbl">Expenses (YTD)</div>
    </a>
</div>
<?php endif; ?>

<!-- ===================== QUICK ACTIONS (Role & Access Restricted) ===================== -->
<div class="quick-actions">
    <?php if ($isAdmin): ?>
        <a href="sales/salesdata.php" class="btn-action">⬆ Upload Invoices</a>
        <a href="accounts/collections.php" class="btn-action green">💰 Add Collection</a>
        <a href="billing/orders.php" class="btn-action">🛒 Place Order</a>
        <a href="sales/targets.php" class="btn-action gold">🎯 Targets</a>
        <a href="dispatch/dispatch.php" class="btn-action red">🚚 Dispatch Console</a>
        <a href="reports.php" class="btn-action red">📑 All Reports</a>
    <?php else: ?>
        <?php if (canAccess('dailyvisit.php', $allowedPages, false, $useDynamicPerms, true)): ?>
            <a href="fieldforce/dailyvisit.php" class="btn-action">📝 Manage DCR</a>
        <?php endif; ?>
        <?php if (canAccess('doctors.php', $allowedPages, false, $useDynamicPerms, true)): ?>
            <a href="fieldforce/doctors.php" class="btn-action cyan">🩺 Manage Doctors</a>
        <?php endif; ?>
        <?php if (canAccess('leave.php', $allowedPages, false, $useDynamicPerms, true)): ?>
            <a href="hr/leave.php" class="btn-action violet">🏖️ Apply Leave</a>
        <?php endif; ?>
        <?php if (canAccess('stockstatement.php', $allowedPages, false, $useDynamicPerms, true)): ?>
            <a href="inventory/stockstatement.php" class="btn-action gold">📦 Stock Entry</a>
        <?php endif; ?>
        <?php if (canAccess('primary.php', $allowedPages, false, $useDynamicPerms, true)): ?>
            <a href="sales/primary.php" class="btn-action">📊 View Primary</a>
        <?php endif; ?>
        <?php if (canAccess('orders.php', $allowedPages, false, $useDynamicPerms, true)): ?>
            <a href="billing/orders.php" class="btn-action green">🛒 View Orders</a>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- ===================== UNIVERSAL NAV HUB (Role & Permission Restricted) ===================== -->
<div class="ops-section-title">
    <span>Command Navigation Hub</span>
    <span class="badge-sub">Authorized modules</span>
</div>
<div class="nav-hub">
    <!-- SALES MODULES -->
    <?php if (canAccess('primary.php', $allowedPages, $isAdmin, $useDynamicPerms, $isSales)): ?>
        <a href="sales/primary.php" class="nav-tile sales"><span class="icon">📊</span><span class="lbl">Primary</span><span class="sub"><?= $isAdmin ? 'Sales Report' : 'View Report' ?></span></a>
    <?php endif; ?>
    <?php if (canAccess('secondary.php', $allowedPages, $isAdmin, $useDynamicPerms, $isSales)): ?>
        <a href="sales/secondary.php" class="nav-tile sales"><span class="icon">📈</span><span class="lbl">Secondary</span><span class="sub"><?= $isAdmin ? 'Collections' : 'View Collections' ?></span></a>
    <?php endif; ?>
    <?php if (canAccess('collections.php', $allowedPages, $isAdmin, $useDynamicPerms, $isSales)): ?>
        <a href="accounts/collections.php" class="nav-tile sales"><span class="icon">💰</span><span class="lbl">Collections</span><span class="sub"><?= $isAdmin ? 'Add / View' : 'View Only' ?></span></a>
    <?php endif; ?>
    <?php if (canAccess('achievements.php', $allowedPages, $isAdmin, $useDynamicPerms, $isSales)): ?>
        <a href="sales/achievements.php" class="nav-tile sales"><span class="icon">🏆</span><span class="lbl">Achievements</span><span class="sub">Leaderboard</span></a>
    <?php endif; ?>
    <?php if (canAccess('salestrend.php', $allowedPages, $isAdmin, $useDynamicPerms, $isSales)): ?>
        <a href="sales/salestrend.php" class="nav-tile sales"><span class="icon">📉</span><span class="lbl">Trends</span><span class="sub">Sales Graph</span></a>
    <?php endif; ?>
    <?php if (canAccess('orders.php', $allowedPages, $isAdmin, $useDynamicPerms, $isSales)): ?>
        <a href="billing/orders.php" class="nav-tile sales"><span class="icon">🛒</span><span class="lbl">Orders</span><span class="sub"><?= $isAdmin ? 'Sales Orders' : 'View Pipeline' ?></span></a>
    <?php endif; ?>
    
    <!-- FIELD OPERATIONS -->
    <?php if (canAccess('doctors.php', $allowedPages, $isAdmin, $useDynamicPerms, $isSales)): ?>
        <a href="fieldforce/doctors.php" class="nav-tile sales"><span class="icon">🩺</span><span class="lbl">Doctors</span><span class="sub">Manage List</span></a>
    <?php endif; ?>
    <?php if (canAccess('tourplan.php', $allowedPages, $isAdmin, $useDynamicPerms, $isSales)): ?>
        <a href="fieldforce/tourplan.php" class="nav-tile sales"><span class="icon">📅</span><span class="lbl">Tour Plan</span><span class="sub"><?= $isAdmin ? 'STP' : 'View STP' ?></span></a>
    <?php endif; ?>
    <?php if (canAccess('dailyvisit.php', $allowedPages, $isAdmin, $useDynamicPerms, $isSales)): ?>
        <a href="fieldforce/dailyvisit.php" class="nav-tile sales"><span class="icon">📝</span><span class="lbl">DCR</span><span class="sub">Manage Visits</span></a>
    <?php endif; ?>
    <?php if (canAccess('secondaryentry.php', $allowedPages, $isAdmin, $useDynamicPerms, $isSales)): ?>
        <a href="fieldforce/secondaryentry.php" class="nav-tile sales"><span class="icon">🧪</span><span class="lbl">Sec. Entry</span><span class="sub"><?= $isAdmin ? 'Chemist POB' : 'View POB' ?></span></a>
    <?php endif; ?>
    <?php if (canAccess('stockstatement.php', $allowedPages, $isAdmin, $useDynamicPerms, $isSales)): ?>
        <a href="inventory/stockstatement.php" class="nav-tile sales"><span class="icon">📦</span><span class="lbl">Stock</span><span class="sub">Manage Entry</span></a>
    <?php endif; ?>

    <!-- HR & OPERATIONS -->
    <?php if (canAccess('leave.php', $allowedPages, $isAdmin, $useDynamicPerms, ($isSales || $isHR))): ?>
        <a href="hr/leave.php" class="nav-tile hr"><span class="icon">🏖️</span><span class="lbl">Leave</span><span class="sub">Manage App</span></a>
    <?php endif; ?>
    <?php if (canAccess('expense.php', $allowedPages, $isAdmin, $useDynamicPerms, ($isSales || $isHR))): ?>
        <a href="fieldforce/expense.php" class="nav-tile hr"><span class="icon">💳</span><span class="lbl">Expenses</span><span class="sub"><?= $isAdmin ? 'TA / DA' : 'View Claims' ?></span></a>
    <?php endif; ?>
    <?php if (canAccess('attendance.php', $allowedPages, $isAdmin, $useDynamicPerms, ($isSales || $isHR))): ?>
        <a href="hr/attendance.php" class="nav-tile hr"><span class="icon">⏱️</span><span class="lbl">Attendance</span><span class="sub">Daily</span></a>
    <?php endif; ?>

    <!-- MANAGER & ADMIN ONLY -->
    <?php if (canAccess('salesdata.php', $allowedPages, $isAdmin, $useDynamicPerms, false)): ?>
        <a href="sales/salesdata.php" class="nav-tile sales"><span class="icon">⬆</span><span class="lbl">Upload</span><span class="sub">Invoices</span></a>
    <?php endif; ?>
    <?php if (canAccess('targets.php', $allowedPages, $isAdmin, $useDynamicPerms, false)): ?>
        <a href="sales/targets.php" class="nav-tile sales"><span class="icon">🎯</span><span class="lbl">Targets</span><span class="sub">Set / Track</span></a>
    <?php endif; ?>
    <?php if (canAccess('billing.php', $allowedPages, $isAdmin, $useDynamicPerms, false)): ?>
        <a href="billing/billing.php" class="nav-tile sales"><span class="icon">🧾</span><span class="lbl">Billing</span><span class="sub">Pending Bills</span></a>
    <?php endif; ?>
    <?php if (canAccess('leaveapproval.php', $allowedPages, $isAdmin, $useDynamicPerms, false)): ?>
        <a href="hr/leaveapproval.php" class="nav-tile hr"><span class="icon">✅</span><span class="lbl">Leave Appr.</span><span class="sub">Approve</span></a>
    <?php endif; ?>
    <?php if (canAccess('expenseapproval.php', $allowedPages, $isAdmin, $useDynamicPerms, false)): ?>
        <a href="fieldforce/expenseapproval.php" class="nav-tile hr"><span class="icon">📋</span><span class="lbl">Exp. Appr.</span><span class="sub">Settle</span></a>
    <?php endif; ?>
    <?php if (canAccess('tourplanapproval.php', $allowedPages, $isAdmin, $useDynamicPerms, false)): ?>
        <a href="fieldforce/tourplanapproval.php" class="nav-tile hr"><span class="icon">🗓️</span><span class="lbl">STP Appr.</span><span class="sub">Authorize</span></a>
    <?php endif; ?>

    <!-- DISPATCH & PURCHASE -->
    <?php if (canAccess('dispatch.php', $allowedPages, $isAdmin, $useDynamicPerms, $isDispatch)): ?>
        <a href="dispatch/dispatch.php" class="nav-tile dispatch"><span class="icon">🚚</span><span class="lbl">Dispatch</span><span class="sub">Console</span></a>
    <?php endif; ?>
    <?php if (canAccess('purchase.php', $allowedPages, $isAdmin, $useDynamicPerms, $isPurchase)): ?>
        <a href="purchase.php" class="nav-tile purchase"><span class="icon">🛒</span><span class="lbl">Purchase</span><span class="sub">Orders</span></a>
    <?php endif; ?>
    <?php if (canAccess('vendors.php', $allowedPages, $isAdmin, $useDynamicPerms, $isPurchase)): ?>
        <a href="vendors.php" class="nav-tile purchase"><span class="icon">🏭</span><span class="lbl">Vendors</span><span class="sub">Suppliers</span></a>
    <?php endif; ?>

    <!-- ADMIN MASTER TABS -->
    <?php if ($isAdmin): ?>
        <a href="masters/parties.php" class="nav-tile dispatch"><span class="icon">👥</span><span class="lbl">Parties</span><span class="sub">Customers</span></a>
        <a href="hr/employees.php" class="nav-tile hr"><span class="icon">🧑‍💼</span><span class="lbl">Employees</span><span class="sub">Master</span></a>
        <a href="reports.php" class="nav-tile reports"><span class="icon">📑</span><span class="lbl">All Reports</span><span class="sub">Export Hub</span></a>
        <a href="admin/users.php" class="nav-tile admin"><span class="icon">🔐</span><span class="lbl">Users</span><span class="sub">Access</span></a>
        <a href="admin/settings.php" class="nav-tile admin"><span class="icon">⚙️</span><span class="lbl">Settings</span><span class="sub">Config</span></a>
    <?php endif; ?>
</div>

<!-- ===================== HR / PEOPLE KPIs (Admin & Manager) ===================== -->
<?php if ($isAdmin || $isManager): ?>
<div class="ops-section-title" style="color:#6d28d9; border-bottom-color:#ddd6fe;">
    <span>People & HR Snapshot</span>
    <span class="badge-sub" style="background:#f5f3ff; color:#6d28d9;">Workforce</span>
</div>
<div class="kpi-grid">
    <div class="kpi-card" style="--accent:#7c3aed;">
        <div class="kpi-label">Active Employees</div>
        <div class="kpi-value" style="color:#6d28d9;"><?= number_format($activeEmployees ?: $teamSize) ?></div>
        <div class="kpi-sub" style="background:#f5f3ff; color:#6d28d9;">On rolls</div>
    </div>
    <div class="kpi-card" style="--accent:#d97706;">
        <div class="kpi-label">Pending Leaves</div>
        <div class="kpi-value" style="color:#d97706;"><?= number_format($pendingLeaves) ?></div>
        <div class="kpi-sub" style="background:#fffbeb; color:#b45309;">Awaiting approval</div>
    </div>
    <div class="kpi-card" style="--accent:#db2777;">
        <div class="kpi-label">Pending Expenses</div>
        <div class="kpi-value" style="color:#db2777;"><?= number_format($pendingExpenses) ?></div>
        <div class="kpi-sub" style="background:#fdf2f8; color:#be185d;">TA/DA claims</div>
    </div>
    <div class="kpi-card" style="--accent:#7c3aed;">
        <div class="kpi-label">STP Pending</div>
        <div class="kpi-value" style="color:#7c3aed;"><?= number_format($pendingTourPlans) ?></div>
        <div class="kpi-sub" style="background:#f5f3ff; color:#6d28d9;">Tour plans</div>
    </div>
    <?php if ($isAdmin): ?>
    <div class="kpi-card" style="--accent:#dc2626;">
        <div class="kpi-label">Absent Today</div>
        <div class="kpi-value" style="color:#dc2626;"><?= number_format($absentToday) ?></div>
        <div class="kpi-sub" style="background:#fef2f2; color:#b91c1c;">Attendance</div>
    </div>
    <div class="kpi-card" style="--accent:#059669;">
        <div class="kpi-label">Present Today</div>
        <div class="kpi-value" style="color:#059669;"><?= number_format($presentToday) ?></div>
        <div class="kpi-sub" style="background:#ecfdf5; color:#047857;">Marked present</div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>


<!-- ===================== CHARTS — COLORFUL (TOP) ===================== -->
<?php if ($isSales || $isAdmin || $isAccounts): ?>
<div class="ops-section-title">
    <span>Performance Analytics</span>
    <span class="badge-sub"><?= $isAdmin ? 'Global Data' : 'My Analytics' ?></span>
</div>
<div class="dash-grid">
    <div class="panel-card">
        <div class="panel-title">
            <div class="title-left">Primary Sales Trend <span class="badge-sub">Last 6 Months</span></div>
        </div>
        <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
    </div>
    <div class="panel-card">
        <div class="panel-title">
            <div class="title-left">Target Achievement <span class="badge-sub"><?= $fyLabel ?></span></div>
        </div>
        <div class="chart-wrap sm"><canvas id="achChart"></canvas></div>
    </div>
</div>

<div class="dash-grid">
    <div class="panel-card">
        <div class="panel-title">
            <div class="title-left">Collections Trend <span class="badge-sub">Last 6 Months</span></div>
        </div>
        <div class="chart-wrap"><canvas id="collChart"></canvas></div>
    </div>
    <div class="panel-card">
        <div class="panel-title">
            <div class="title-left">Primary vs Collections <span class="badge-sub">YTD Comparison</span></div>
        </div>
        <div class="chart-wrap sm"><canvas id="compareChart"></canvas></div>
    </div>
</div>
<?php endif; ?>

<!-- ===================== TOP LISTS (SIDE-BY-SIDE) ===================== -->
<?php if ($isSales || $isAdmin): ?>
<div class="dash-grid">
    <!-- LEFT: Top Parties (YTD) -> For Current Scope (My/Team/All) -->
    <div class="panel-card">
        <div class="panel-title">
            <div class="title-left">Top Parties (YTD) <span class="badge-sub">By Primary Value</span></div>
            <div class="panel-actions">
                <a href="sales/primary.php" class="btn-viewall">View All →</a>
            </div>
        </div>
        <?php if (empty($topParties)): ?>
            <div class="empty-state">No party data for this scope.</div>
        <?php else: ?>
            <table class="compact-table">
                <thead><tr><th style="width:28px;">#</th><th>Party</th><th style="text-align:right;">Value</th></tr></thead>
                <tbody>
                    <?php foreach ($topParties as $i => $p): ?>
                    <tr>
                        <td><span class="rank rank-<?= $i < 3 ? ($i+1) : 'n' ?>"><?= $i+1 ?></span></td>
                        <td title="<?= htmlspecialchars($p['partyname']) ?>"><?= htmlspecialchars($p['partyname']) ?></td>
                        <td class="val-cell"><?= fmtINR($p['val']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- RIGHT: Company Top Performers -> Global Data (For All Viewers) -->
    <div class="panel-card">
        <div class="panel-title">
            <div class="title-left">Top Performers (Company) <span class="badge-sub">Primary Sales (YTD)</span></div>
            <div class="panel-actions">
                <a href="sales/achievements.php" class="btn-viewall">View All →</a>
            </div>
        </div>
        <?php if (empty($globalTopEmployees)): ?>
            <div class="empty-state">No performance data yet.</div>
        <?php else: ?>
            <table class="compact-table">
                <thead><tr><th style="width:28px;">#</th><th>Employee</th><th style="text-align:right;">Value</th></tr></thead>
                <tbody>
                    <?php foreach ($globalTopEmployees as $i => $e): ?>
                    <tr>
                        <td><span class="rank rank-<?= $i < 3 ? ($i+1) : 'n' ?>"><?= $i+1 ?></span></td>
                        <td>
                            <strong><?= htmlspecialchars($e['empname'] ?? $e['empcode']) ?></strong>
                            <div style="font-size:9px; color:#94a3b8;"><?= htmlspecialchars($e['empcode'] ?? '') ?></div>
                        </td>
                        <td class="val-cell"><?= fmtINR($e['val']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ===================== RECENT ACTIVITY ===================== -->
<?php if ($isSales || $isAdmin || $isAccounts): ?>
<div class="ops-section-title">
    <span>Recent Activity Feed</span>
    <span class="badge-sub">Latest transactions</span>
</div>
<div class="dash-grid">
    <div class="panel-card">
        <div class="panel-title">
            <div class="title-left">Recent Primary Invoices <span class="badge-sub">Latest 10</span></div>
            <div class="panel-actions">
                <a href="sales/primary.php" class="btn-viewall">View All →</a>
            </div>
        </div>
        <?php if (empty($recentInvoices)): ?>
            <div class="empty-state">No recent invoices for this scope.</div>
        <?php else: ?>
            <table class="compact-table">
                <thead><tr><th>Date</th><th>Invoice</th><th>Party</th><th style="text-align:right;">Value</th></tr></thead>
                <tbody>
                    <?php foreach ($recentInvoices as $inv): ?>
                    <tr>
                        <td><?= date('d-M-y', strtotime($inv['invoice_date'])) ?></td>
                        <td style="color:var(--navy); font-weight:600;"><?= htmlspecialchars($inv['invoice_no']) ?></td>
                        <td title="<?= htmlspecialchars($inv['partyname']) ?>"><?= htmlspecialchars($inv['partyname']) ?></td>
                        <td class="val-cell"><?= fmtINR($inv['total_value']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <div class="panel-card">
        <div class="panel-title">
            <div class="title-left">Recent Collections <span class="badge-sub">Latest 10</span></div>
            <div class="panel-actions">
                <a href="accounts/collections.php" class="btn-viewall">View All →</a>
            </div>
        </div>
        <?php if (empty($recentCollections)): ?>
            <div class="empty-state">No recent collections for this scope.</div>
        <?php else: ?>
            <table class="compact-table">
                <thead><tr><th>Date</th><th>Party</th><th>Mode</th><th style="text-align:right;">Amount</th></tr></thead>
                <tbody>
                    <?php foreach ($recentCollections as $col): ?>
                    <tr>
                        <td><?= date('d-M-y', strtotime($col['collection_date'])) ?></td>
                        <td title="<?= htmlspecialchars($col['partyname']) ?>"><?= htmlspecialchars($col['partyname']) ?></td>
                        <td style="color:#64748b;"><?= htmlspecialchars($col['payment_mode'] ?: '—') ?></td>
                        <td class="val-cell" style="color:#1d4ed8;"><?= fmtINR($col['amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<script>
// Color palette for charts
const chartColors = {
    primary: '#1e2a78', sky: '#00aeea', emerald: '#10b981', amber: '#f59e0b',
    rose: '#e11d48', violet: '#8b5cf6', cyan: '#06b6d4', orange: '#ea580c'
};

const trendLabels = <?= json_encode(array_column($monthlyTrend, 'ym')) ?>;
const trendValues = <?= json_encode(array_map('floatval', array_column($monthlyTrend, 'val'))) ?>;
const collLabels  = <?= json_encode(array_column($collectionTrend, 'ym')) ?>;
const collValues  = <?= json_encode(array_map('floatval', array_column($collectionTrend, 'val'))) ?>;

function fmtMonth(l) {
    if (!l) return '';
    const [y, m] = l.split('-');
    return new Date(y, m - 1).toLocaleString('en', { month: 'short', year: '2-digit' });
}

// 1. Primary Sales Trend (gradient line)
if (document.getElementById('trendChart')) {
    const ctx = document.getElementById('trendChart').getContext('2d');
    const grad = ctx.createLinearGradient(0, 0, 0, 240);
    grad.addColorStop(0, 'rgba(30,42,120,0.35)');
    grad.addColorStop(1, 'rgba(30,42,120,0.02)');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: trendLabels.map(fmtMonth),
            datasets: [{
                label: 'Primary Sales (₹)', data: trendValues, borderColor: chartColors.primary,
                backgroundColor: grad, borderWidth: 3, fill: true, tension: 0.4,
                pointBackgroundColor: chartColors.sky, pointBorderColor: '#fff', pointBorderWidth: 2, pointRadius: 5
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f1a4d', callbacks: { label: ctx => '₹ ' + ctx.parsed.y.toLocaleString('en-IN') } } },
            scales: { y: { ticks: { callback: v => '₹' + (v >= 100000 ? (v/100000).toFixed(1)+'L' : v.toLocaleString()) }, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } }
        }
    });
}

// 2. Target Achievement Doughnut
const achPct = <?= $primaryAchPct ?>;
const remaining = Math.max(0, 100 - achPct);
if (document.getElementById('achChart')) {
    new Chart(document.getElementById('achChart'), {
        type: 'doughnut',
        data: {
            labels: ['Achieved', 'Remaining'],
            datasets: [{ data: [Math.min(achPct, 100), remaining], backgroundColor: ['<?= achColor($primaryAchPct) ?>', '#e2e8f0'], borderWidth: 0, cutout: '72%' }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { enabled: false } } },
        plugins: [{
            id: 'centerText',
            afterDraw(chart) {
                const { ctx, chartArea: { width, height } } = chart;
                ctx.save();
                ctx.font = 'bold 24px Sora, sans-serif'; ctx.fillStyle = '<?= achColor($primaryAchPct) ?>';
                ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(achPct + '%', width / 2, height / 2 - 8);
                ctx.font = '11px Inter, sans-serif'; ctx.fillStyle = '#64748b'; ctx.fillText('of Target', width / 2, height / 2 + 14);
                ctx.restore();
            }
        }]
    });
}

// 3. Collections Trend
if (document.getElementById('collChart') && collValues.length) {
    const ctx2 = document.getElementById('collChart').getContext('2d');
    const grad2 = ctx2.createLinearGradient(0, 0, 0, 240);
    grad2.addColorStop(0, 'rgba(16,185,129,0.4)');
    grad2.addColorStop(1, 'rgba(16,185,129,0.02)');
    new Chart(ctx2, {
        type: 'line',
        data: {
            labels: collLabels.map(fmtMonth),
            datasets: [{ label: 'Collections (₹)', data: collValues, borderColor: chartColors.emerald, backgroundColor: grad2, borderWidth: 3, fill: true, tension: 0.4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#064e3b', callbacks: { label: ctx => '₹ ' + ctx.parsed.y.toLocaleString('en-IN') } } }, scales: { y: { ticks: { callback: v => '₹' + (v >= 100000 ? (v/100000).toFixed(1)+'L' : v.toLocaleString()) }, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } } }
    });
}

// 4. Primary vs Collections (bar)
if (document.getElementById('compareChart')) {
    new Chart(document.getElementById('compareChart'), {
        type: 'bar',
        data: {
            labels: ['Primary Sales', 'Collections', 'Outstanding'],
            datasets: [{ data: [<?= $primaryYTD ?>, <?= $secondaryYTD ?>, <?= max(0, $salesGap) ?>], backgroundColor: ['rgba(30,42,120,0.85)', 'rgba(16,185,129,0.85)', 'rgba(239,68,68,0.85)'], borderRadius: 8 }]
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0f1a4d', callbacks: { label: ctx => '₹ ' + ctx.parsed.y.toLocaleString('en-IN') } } }, scales: { y: { ticks: { callback: v => '₹' + (v >= 100000 ? (v/100000).toFixed(1)+'L' : v.toLocaleString()) }, grid: { color: '#f1f5f9' } }, x: { grid: { display: false } } } }
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>