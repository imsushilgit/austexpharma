// ==========================================
// 1. SYSTEM PATHS & URL CONFIGURATION
// ==========================================

define('BASE_PATH', dirname(__DIR__)); 

// FORCE BASE_URL to Root for Render deployment
if (!defined('BASE_URL')) {
    define('BASE_URL', '/');
}

// SERVER DIRECTORY CONSTANTS (For PHP includes, requires, and uploads)
define('DIR_ACCOUNTS',   BASE_PATH . '/accounts/');
define('DIR_ADMIN',      BASE_PATH . '/admin/');
define('DIR_AUTH',       BASE_PATH . '/auth/');
define('DIR_BILLING',    BASE_PATH . '/billing/');
define('DIR_DASHBOARD',  BASE_PATH . '/dashboard/');
define('DIR_DISPATCH',   BASE_PATH . '/dispatch/');
define('DIR_DOCS',       BASE_PATH . '/docs/');
define('DIR_FIELDFORCE', BASE_PATH . '/fieldforce/');
define('DIR_HR',         BASE_PATH . '/hr/');
define('DIR_INCLUDES',   BASE_PATH . '/includes/');
define('DIR_INVENTORY',  BASE_PATH . '/inventory/');
define('DIR_LOGS',       BASE_PATH . '/logs/');
define('DIR_MASTERS',    BASE_PATH . '/masters/');
define('DIR_PAYROLL',    BASE_PATH . '/payroll/');
define('DIR_SALES',      BASE_PATH . '/sales/');
define('DIR_UPLOADS',    BASE_PATH . '/uploads/');

// ==========================================
// CSS ASSET HELPER
// Outputs a <link> to assets/css/<file> with a ?v=<mtime> cache-buster,
// so edited stylesheets are always picked up by the browser.
// ==========================================
if (!function_exists('asset_css')) {
    function asset_css($file) {
        $v = @filemtime(BASE_PATH . '/assets/css/' . $file) ?: 1;
        return '<link rel="stylesheet" href="' . BASE_URL . 'assets/css/' . htmlspecialchars($file, ENT_QUOTES) . '?v=' . $v . '">';
    }
}

// FRONT-END URL CONSTANTS (For HTML hrefs, redirects, and image sources)
define('URL_AUTH',       BASE_URL . 'auth/');
define('URL_DASHBOARD',  BASE_URL); 
define('URL_UPLOADS',    BASE_URL . 'uploads/');
define('URL_ASSETS',     BASE_URL . 'assets/');

// ==========================================
// 2. ERROR HANDLING
// ==========================================
error_reporting(E_ALL);
ini_set('display_errors', 1); 
ini_set('log_errors', 1);
ini_set('error_log', BASE_PATH . '/system_errors.log'); // Saves error log in root folder

// ==========================================
// 3. SECURE SESSIONS
// ==========================================
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 86400);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Global CSRF Token Generation
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==========================================
// 4. DATABASE CONNECTION
// ==========================================
// Clever Cloud Database Credentials
$host = getenv('MYSQL_ADDON_HOST')     ?: 'b25h4ybf83w6efsr40pu-mysql.services.clever-cloud.com'; 
$db   = getenv('MYSQL_ADDON_DB')       ?: 'b25h4ybf83w6efsr40pu'; 
$user = getenv('MYSQL_ADDON_USER')     ?: 'ukalsqdvw8dgdsdy';
$pass = getenv('MYSQL_ADDON_PASSWORD') ?: 'D7WI0Pu7XwQ5P4SqwvHK';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    error_log("Database Connection Failed: " . $e->getMessage());
    die("Database Connection Failed: " . htmlspecialchars($e->getMessage()));
}

// ==========================================
// 5. GLOBAL USER CONTEXT & RBAC ENGINE
// ==========================================

function verify_csrf() {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            http_response_code(419);
            die("Error 419: CSRF token validation failed. Please refresh and try again.");
        }
    }
}

function load_hierarchy($pdo) {
    static $h = null;
    if ($h !== null) return $h;
    $h = ['byCode' => [], 'children' => []];
    try {
        $rows = $pdo->query("SELECT empcode, empname, designation, reporting_manager, hq, state, resignation_date FROM employees")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { return $h; }

    $nameToCode = [];
    foreach ($rows as $r) {
        $code = trim((string)$r['empcode']);
        if ($code === '') continue;
        $h['byCode'][$code] = $r;
        $nameToCode[strtolower(trim((string)$r['empname']))][] = $code;
    }
    foreach ($h['byCode'] as $code => $r) {
        $mgr = trim((string)($r['reporting_manager'] ?? ''));
        if ($mgr === '') continue;
        $parents = [];
        if (isset($h['byCode'][$mgr])) {
            $parents[] = $mgr;
        } elseif (isset($nameToCode[strtolower($mgr)])) {
            $parents = $nameToCode[strtolower($mgr)];
        }
        foreach ($parents as $p) {
            if ($p !== $code) $h['children'][$p][] = $code;
        }
    }
    return $h;
}

function get_team_codes($pdo, $empcode) {
    $empcode = trim((string)$empcode);
    if ($empcode === '') return [];
    $h = load_hierarchy($pdo);
    $seen = [$empcode => true];
    $queue = [$empcode];
    while ($queue) {
        $cur = array_shift($queue);
        foreach ($h['children'][$cur] ?? [] as $c) {
            if (!isset($seen[$c])) { $seen[$c] = true; $queue[] = $c; }
        }
    }
    return array_keys($seen);
}

function get_user_context($pdo) {
    static $cache = [];
    $cacheKey = $_SESSION['user_id'] ?? 0;
    if (isset($cache[$cacheKey])) return $cache[$cacheKey];

    $role = strtoupper($_SESSION['user_role'] ?? 'STAFF');
    $isAdmin = ($role === 'ADMIN' || $role === 'SUPERADMIN');

    $empcode     = $_SESSION['empcode'] ?? $_SESSION['username'] ?? '';
    $empname     = $_SESSION['fullname'] ?? '';
    $designation = $_SESSION['designation'] ?? '';
    $hq          = '';
    $state       = '';
    $department  = '';
    $hasEmpRow   = false;

    if (!empty($_SESSION['user_id'])) {
        try {
            $st = $pdo->prepare("SELECT role, username, fullname, empcode FROM users WHERE id = ? LIMIT 1");
            $st->execute([$_SESSION['user_id']]);
            $u = $st->fetch(PDO::FETCH_ASSOC);
            if ($u) {
                $role = strtoupper($u['role'] ?? $role);
                $isAdmin = ($role === 'ADMIN' || $role === 'SUPERADMIN');
                $cands = array_values(array_unique(array_filter([trim((string)$u['empcode']), trim((string)$u['username'])])));
                $emp = null;
                foreach ($cands as $c) {
                    $q = $pdo->prepare("SELECT empcode, empname, designation, department, hq, state FROM employees WHERE empcode = ? LIMIT 1");
                    $q->execute([$c]);
                    if ($emp = $q->fetch(PDO::FETCH_ASSOC)) break;
                }
                if (!$emp && !empty($u['fullname'])) {
                    $q = $pdo->prepare("SELECT empcode, empname, designation, department, hq, state FROM employees WHERE empname = ? LIMIT 1");
                    $q->execute([$u['fullname']]);
                    $emp = $q->fetch(PDO::FETCH_ASSOC);
                }
                if ($emp) {
                    $hasEmpRow   = true;
                    $empcode     = $emp['empcode'];
                    $empname     = $emp['empname'];
                    $designation = $emp['designation'] ?: ($isAdmin ? 'Admin' : 'MR');
                    $department  = $emp['department'] ?? '';
                    $hq          = $emp['hq'] ?? '';
                    $state       = $emp['state'] ?? '';
                } else {
                    $empcode = $u['empcode'] ?: $u['username'];
                    $empname = $u['fullname'] ?: $empname;
                    if ($designation === '') $designation = $isAdmin ? 'Admin' : 'MR';
                }
            }
        } catch (Exception $e) {}
    }

    $teamCodes = [];
    $subCodes  = [];
    if ($isAdmin) {
        $h = load_hierarchy($pdo);
        $teamCodes = array_keys($h['byCode']);
        if ($empcode !== '' && !in_array($empcode, $teamCodes, true)) $teamCodes[] = $empcode;
    } else {
        $teamCodes = get_team_codes($pdo, $empcode);
        if (empty($teamCodes) && $empcode !== '') $teamCodes = [$empcode];
        $subCodes = array_values(array_diff($teamCodes, [$empcode]));
    }

    $desigUpper = strtoupper(trim($designation));
    $isMrDesig  = in_array($desigUpper, ['', 'MR', 'MEDICAL REPRESENTATIVE', 'STAFF', 'EXECUTIVE', 'FIELD EXECUTIVE'], true);
    $isManager  = $isAdmin || !empty($subCodes) || str_contains($role, 'MANAGER') || !$isMrDesig;
    $isMR       = !$isAdmin && !$isManager;

    $ctx = [
        'user_id'     => $_SESSION['user_id'] ?? 0,
        'username'    => $_SESSION['username'] ?? '',
        'fullname'    => $_SESSION['fullname'] ?? $empname,
        'empcode'     => $empcode,
        'empname'     => $empname ?: ($_SESSION['fullname'] ?? ''),
        'designation' => $designation,
        'hq'          => $hq,
        'state'       => $state,
        'department'  => $department,
        'user_role'   => $_SESSION['user_role'] ?? 'Staff',
        'is_admin'    => $isAdmin,
        'is_manager'  => $isManager,
        'is_mr'       => $isMR,
        'can_write'   => true,
        'team_codes'  => $teamCodes,
        'sub_codes'   => $subCodes,
        'view_scope'  => $isAdmin ? 'all' : ($isMR ? 'self' : 'team'),
    ];
    return $cache[$cacheKey] = $ctx;
}

function scope_codes($ctx) {
    $codes = $ctx['team_codes'] ?? [];
    if (empty($codes) && !empty($ctx['empcode'])) $codes = [$ctx['empcode']];
    return array_values(array_unique($codes));
}

function is_ops_user($ctx, array $depts = ['DISPATCH','LOGISTICS','WAREHOUSE','BILLING','ACCOUNTS','FINANCE']) {
    if (!empty($ctx['is_admin'])) return true;
    return in_array(strtoupper(trim($ctx['department'] ?? '')), $depts, true);
}

function can_access_emp($ctx, $code) {
    if (!empty($ctx['is_admin'])) return true;
    return in_array(trim((string)$code), scope_codes($ctx), true);
}

function require_emp_access($ctx, $code) {
    if (!can_access_emp($ctx, $code)) block_access();
}

function scope_in($ctx, $col, array &$params) {
    if (!empty($ctx['is_admin'])) return '';
    $codes = scope_codes($ctx);
    if (empty($codes)) $codes = ['__NONE__'];
    foreach ($codes as $c) $params[] = $c;
    return " AND $col IN (" . implode(',', array_fill(0, count($codes), '?')) . ")";
}

function scope_party_in($ctx, $partyCol, array &$params) {
    if (!empty($ctx['is_admin'])) return '';
    $codes = scope_codes($ctx);
    if (empty($codes)) $codes = ['__NONE__'];
    foreach ($codes as $c) $params[] = $c;
    return " AND $partyCol IN (SELECT ap_s.partyname FROM assignparties ap_s WHERE ap_s.empcode IN (" . implode(',', array_fill(0, count($codes), '?')) . "))";
}

function scope_cond($ctx, $col, array &$params) {
    $x = scope_in($ctx, $col, $params);
    return $x === '' ? '' : substr($x, 5);
}

function scope_party_cond($ctx, $partyCol, array &$params) {
    $x = scope_party_in($ctx, $partyCol, $params);
    return $x === '' ? '' : substr($x, 5);
}

function can_access_party($pdo, $ctx, $party) {
    if (!empty($ctx['is_admin'])) return true;
    $p = [trim((string)$party)];
    $sql = "SELECT 1 FROM assignparties WHERE partyname = ?" . scope_in($ctx, 'empcode', $p) . " LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute($p);
    return (bool)$st->fetchColumn();
}

function scope_filter_rows($ctx, array $rows, $key = 'empcode') {
    if (!empty($ctx['is_admin'])) return $rows;
    $allowed = array_flip(scope_codes($ctx));
    return array_values(array_filter($rows, function ($r) use ($allowed, $key) {
        return isset($allowed[trim((string)($r[$key] ?? ''))]);
    }));
}

function scope_filter_parties($pdo, $ctx, array $rows, $key = 'partyname', array $opsDepts = ['ACCOUNTS','FINANCE','BILLING','DISPATCH','LOGISTICS']) {
    if (is_ops_user($ctx, $opsDepts)) return $rows;
    list($ph, $codes) = scope_placeholders($ctx);
    $st = $pdo->prepare("SELECT DISTINCT partyname FROM assignparties WHERE empcode IN ($ph)");
    $st->execute($codes);
    $ok = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pn) $ok[strtolower(trim($pn))] = true;
    return array_values(array_filter($rows, function ($r) use ($ok, $key) {
        return isset($ok[strtolower(trim((string)($r[$key] ?? '')))]);
    }));
}

function scope_placeholders($ctx) {
    $codes = scope_codes($ctx);
    if (empty($codes)) $codes = ['__NONE__'];
    return [implode(',', array_fill(0, count($codes), '?')), $codes];
}

function require_page_access($pdo) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: " . URL_AUTH . "login.php");
        exit();
    }

    $ctx = get_user_context($pdo);
    $currentPage = basename($_SERVER['PHP_SELF']);

    if ($currentPage === 'dashboard.php') {
        $ctx['can_write'] = true;
        return $ctx;
    }

    $adminOnlyPages = ['users.php','permissions.php','settings.php','backup.php','systemlogs.php',
                       'schemaviewer.php','masterupload.php','cop.php','brand.php'];
    if (!$ctx['is_admin'] && in_array($currentPage, $adminOnlyPages, true)) {
        block_access();
    }

    try {
        $stmt = $pdo->prepare("SELECT can_view, can_write FROM page_permissions WHERE role_name = ? AND page_name = ? LIMIT 1");
        $stmt->execute([$ctx['empcode'], $currentPage]);
        $perms = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$perms && !empty($ctx['designation'])) {
            $stmt->execute([$ctx['designation'], $currentPage]);
            $perms = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$perms && !empty($ctx['user_role'])) {
            $stmt->execute([$ctx['user_role'], $currentPage]);
            $perms = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if ($perms) {
            if (isset($perms['can_view']) && (int)$perms['can_view'] === 0) {
                block_access();
            }
            $ctx['can_write'] = isset($perms['can_write']) && (int)$perms['can_write'] === 1;
        } else {
            if ($ctx['is_admin']) {
                $ctx['can_write'] = true;
            } else {
                $ctx['can_write'] = false; 
            }
        }
    } catch (Exception $e) {
        if ($ctx['is_admin']) {
            $ctx['can_write'] = true;
        }
    }

    return $ctx;
}

function block_access() {
    if ((!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') || isset($_POST['ajax_action'])) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['status' => 'error', 'message' => 'Access Denied: Your role lacks permission for this action or page.']);
        exit();
    }
    
    echo "<script>
        alert('🛑 ACCESS DENIED\\n\\nYou do not have permission to view or access this page. Please contact your Administrator if you need access.');
        window.location.href = '" . URL_DASHBOARD . "dashboard.php';
    </script>";
    exit();
}

function user_can_write($ctx) {
    return $ctx['can_write'] ?? true;
}

$currentScript = basename($_SERVER['PHP_SELF']);
if ($currentScript !== 'login.php' && $currentScript !== 'logout.php') {
    if (!isset($ctx)) {
        $ctx = require_page_access($pdo);
    }
}
?>