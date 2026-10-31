<?php
/**
 * AUTH HELPER - Role Based Access Control
 * Include this after core.php / session is started
 * 
 * Roles:
 *   - Admin  → Full access, all data
 *   - Manager (ASM & above) → Operational pages + Team data + Write
 *   - MR     → Operational pages + Own data + View only
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// -------------------------------------------------
// 1. USER CONTEXT
// -------------------------------------------------
function get_user_context(PDO $pdo): array
{
    static $context = null;
    if ($context !== null) {
        return $context;
    }

    $context = [
        'user_id'     => $_SESSION['user_id'] ?? null,
        'username'    => $_SESSION['username'] ?? '',
        'fullname'    => $_SESSION['fullname'] ?? '',
        'role'        => $_SESSION['user_role'] ?? 'Staff',
        'empcode'     => '',
        'empname'     => '',
        'designation' => 'MR',
        'hq'          => '',
        'state'       => '',
        'is_admin'    => false,
        'is_manager'  => false,
        'is_mr'       => false,
        'can_write'   => false,
        'team_codes'  => [],
        'view_scope'  => 'self', // self | team | all
    ];

    if (empty($context['user_id'])) {
        return $context;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT u.role, u.username, u.fullname,
                   e.empcode, e.empname, e.designation, e.hq, e.state
            FROM users u
            LEFT JOIN employees e ON (
                u.username = e.empcode 
                OR u.username = e.empname 
                OR u.fullname = e.empname
            )
            WHERE u.id = ?
            LIMIT 1
        ");
        $stmt->execute([$context['user_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $context['role']        = $row['role'] ?? 'Staff';
            $context['username']    = $row['username'] ?? $context['username'];
            $context['fullname']    = $row['fullname'] ?? $context['fullname'];
            $context['empcode']     = $row['empcode'] ?? $row['username'] ?? '';
            $context['empname']     = $row['empname'] ?? $row['fullname'] ?? '';
            $context['designation'] = trim($row['designation'] ?? 'MR');
            $context['hq']          = $row['hq'] ?? '';
            $context['state']       = $row['state'] ?? '';
        }
    } catch (Exception $e) {
        // silent
    }

    // ---- Role flags ----
    $context['is_admin'] = (strtoupper($context['role']) === 'ADMIN');

    if ($context['is_admin']) {
        $context['is_manager'] = false;
        $context['is_mr']      = false;
        $context['can_write']  = true;
        $context['view_scope'] = 'all';
    } else {
        $desig = strtoupper($context['designation']);
        if ($desig === 'MR' || $desig === '' || $desig === 'MEDICAL REPRESENTATIVE') {
            $context['is_mr']      = true;
            $context['is_manager'] = false;
            $context['can_write']  = false;          // MR = View only
            $context['view_scope'] = 'self';
        } else {
            // ASM, RSM, ZSM, Manager, etc.
            $context['is_mr']      = false;
            $context['is_manager'] = true;
            $context['can_write']  = true;           // Manager = Write allowed
            $context['view_scope'] = 'team';
        }
    }

    // ---- Build team codes (for Manager & Admin) ----
    if ($context['is_manager'] || $context['is_admin']) {
        $context['team_codes'] = get_team_empcodes($pdo, $context['empcode'], $context['empname']);
    } else {
        // MR → only self
        $context['team_codes'] = array_filter([$context['empcode']]);
    }

    // Fallback
    if (empty($context['team_codes']) && !empty($context['empcode'])) {
        $context['team_codes'] = [$context['empcode']];
    }

    return $context;
}

/**
 * Recursive team (self + all subordinates)
 */
function get_team_empcodes(PDO $pdo, string $empcode, string $empname): array
{
    if (empty($empcode) && empty($empname)) {
        return [];
    }

    try {
        $sql = "
            WITH RECURSIVE Subordinates AS (
                SELECT empcode, empname, 0 AS depth
                FROM employees
                WHERE empcode = ? OR empname = ?
                UNION ALL
                SELECT e.empcode, e.empname, s.depth + 1
                FROM employees e
                INNER JOIN Subordinates s
                    ON TRIM(e.reporting_manager) = TRIM(s.empcode)
                    OR TRIM(e.reporting_manager) = TRIM(s.empname)
                WHERE s.depth < 8
            )
            SELECT DISTINCT empcode FROM Subordinates WHERE empcode IS NOT NULL AND empcode != ''
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$empcode, $empname]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        return array_filter([$empcode]);
    }
}

// -------------------------------------------------
// 2. PAGE ACCESS MATRIX
// -------------------------------------------------
function get_allowed_pages(array $ctx): array
{
    // Pages everyone who is logged-in can see (operational)
    $operational = [
        'index.php',
        'dispatch.php',
        'dispatchreceipt.php',
        'salesdata.php',
        'primary.php',
        'primarysales.php',
        'secondary.php',
        'secondarysales.php',
        'collections.php',
        'outstanding.php',
        'achievements.php',
        'targets.php',
        'salestrend.php',
        // HR & Payroll
        'attendance.php',
        'attendancereport.php',
        'holiday.php',
        'leave.php',
        'leavebalance.php',
        'leavetypes.php',
        'salary.php',
        'salaryslip.php',
        'fullfinal.php',
        'advance.php',
        'shift.php',
        'reimbursement.php',
        'appraisal.php',
        'hrletters.php',
        'assets.php',
        'training.php',
        'orders.php',
        'partyrates.php',
        'billing.php',
        'stock.php',
    ];

    // Admin-only pages
    $adminOnly = [
        'employees.php',
        'master.php',
        'geography.php',
        'parties.php',
        'assignparty.php',
        'transporters.php',
        'senders.php',
        'products.php',
        'masterupload.php',
        'core.php',
        'cop.php',
        'settings.php',
        'users.php',
        'salarystructure.php',
        'verify.php',
    ];

    if ($ctx['is_admin']) {
        return array_merge($operational, $adminOnly);
    }

    // Manager & MR → only operational pages
    return $operational;
}

/**
 * Check if current user can access a page
 */
function can_access_page(string $page, array $ctx): bool
{
    $page = basename($page);
    $allowed = get_allowed_pages($ctx);
    return in_array($page, $allowed, true);
}

/**
 * Call this at the top of every protected page
 */
function require_page_access(PDO $pdo, string $currentPage = null): array
{
    if (empty($_SESSION['user_id'])) {
        if (isset($_POST['ajax_action'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized. Please login.']);
            exit;
        }
        header('Location: ' . BASE_URL . 'auth/login.php');
        exit;
    }

    $ctx = get_user_context($pdo);
    $page = $currentPage ?? basename($_SERVER['PHP_SELF']);

    // Locked users
    if (strtoupper($ctx['role']) === 'LOCKED') {
        session_destroy();
        header('Location: ' . BASE_URL . 'auth/login.php?msg=account_locked');
        exit;
    }

    if (!can_access_page($page, $ctx)) {
        if (isset($_POST['ajax_action'])) {
            header('Content-Type: application/json');
            echo json_encode(['status' => 'error', 'message' => 'Access denied for your role.']);
            exit;
        }
        // Redirect to dashboard with message
        header('Location: ' . BASE_URL . 'index.php?access=denied');
        exit;
    }

    return $ctx;
}

/**
 * Helper: Can current user write (Add/Edit/Delete)?
 */
function user_can_write(array $ctx): bool
{
    return !empty($ctx['can_write']);
}

/**
 * Helper: Get SQL IN clause placeholders + params for team filtering
 * Usage:
 *   [$placeholders, $params] = get_team_filter_params($ctx);
 *   $sql = "SELECT * FROM table WHERE empcode IN ($placeholders)";
 */
function get_team_filter_params(array $ctx): array
{
    if ($ctx['view_scope'] === 'all' || $ctx['is_admin']) {
        return ['', []]; // no filter needed
    }

    $codes = $ctx['team_codes'];
    if (empty($codes)) {
        $codes = ['__NONE__']; // force empty result
    }

    $placeholders = implode(',', array_fill(0, count($codes), '?'));
    return [$placeholders, $codes];
}
