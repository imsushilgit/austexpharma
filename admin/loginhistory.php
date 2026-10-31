<?php
session_start();
require_once __DIR__ . '/../includes/core.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Main Filters
$deptFilter    = trim($_GET['department'] ?? '');
$desigFilter   = trim($_GET['designation'] ?? '');
$fromDate      = trim($_GET['from_date'] ?? '');
$toDate        = trim($_GET['to_date'] ?? '');
$monthFilter   = trim($_GET['month'] ?? '');
$statusFilter  = trim($_GET['status'] ?? '');
$empFilter     = trim($_GET['employee'] ?? '');

// Sorting Logic
$sortKey = $_GET['sort'] ?? 'login_time';
$sortDir = (isset($_GET['dir']) && strtoupper($_GET['dir']) === 'ASC') ? 'ASC' : 'DESC';

$allowedSorts = [
    'empcode' => 'e.empcode',
    'empname' => 'e.empname',
    'department' => 'e.department',
    'designation' => 'e.designation',
    'login_time' => 'ds.login_time'
];
$orderSql = $allowedSorts[$sortKey] ?? 'ds.login_time';

// ========== MAIN QUERY ==========
$sql = "SELECT 
            ds.*,
            e.empcode,
            e.empname,
            e.hq,
            e.department,
            e.designation
        FROM device_sessions ds
        LEFT JOIN users u ON ds.username = u.username
        LEFT JOIN employees e ON u.empcode = e.empcode
        WHERE 1=1";

$params = [];
// ROLE SCOPE: admin = everyone, manager = team, MR = self
$sql .= scope_in($ctx, 'e.empcode', $params);

if ($empFilter !== '') {
    $sql .= " AND (e.empname LIKE ? OR e.empcode LIKE ? OR ds.username LIKE ?)";
    $params[] = "%$empFilter%";
    $params[] = "%$empFilter%";
    $params[] = "%$empFilter%";
}
if ($deptFilter !== '') {
    $sql .= " AND e.department = ?";
    $params[] = $deptFilter;
}
if ($desigFilter !== '') {
    $sql .= " AND e.designation = ?";
    $params[] = $desigFilter;
}
if ($fromDate !== '') {
    $sql .= " AND DATE(ds.login_time) >= ?";
    $params[] = $fromDate;
}
if ($toDate !== '') {
    $sql .= " AND DATE(ds.login_time) <= ?";
    $params[] = $toDate;
}
if ($monthFilter !== '') {
    $sql .= " AND DATE_FORMAT(ds.login_time, '%Y-%m') = ?";
    $params[] = $monthFilter;
}
if ($statusFilter !== '') {
    $sql .= " AND ds.status = ?";
    $params[] = $statusFilter;
}

// Append Sorting
$sql .= " ORDER BY $orderSql $sortDir LIMIT 100";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $sessions = [];
}

// DEPENDENT DROPDOWNS LOGIC
$departments = [];
$designations = [];
try {
    $departments = $pdo->query("SELECT deptname FROM departments ORDER BY deptname")->fetchAll(PDO::FETCH_COLUMN);
    
    $desigSql = "SELECT designame FROM designations";
    $desigParams = [];
    
    if ($deptFilter !== '') {
        $desigSql .= " WHERE deptid = (SELECT deptid FROM departments WHERE deptname = ? LIMIT 1)";
        $desigParams[] = $deptFilter;
    }
    $desigSql .= " ORDER BY designame";
    
    $stmtDesig = $pdo->prepare($desigSql);
    $stmtDesig->execute($desigParams);
    $designations = $stmtDesig->fetchAll(PDO::FETCH_COLUMN);
    
    if ($desigFilter !== '' && !in_array($desigFilter, $designations)) {
        $desigFilter = ''; 
    }
} catch (Exception $e) {}

// Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Login_History_' . date('Y-m-d') . '.csv');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Emp Code','Employee Name','HQ','Department','Designation','Username','Device','Login Location','Login Time','Logout Time','Duration','Status']);
    
    foreach ($sessions as $row) {
        $duration = '-';
        if (!empty($row['logout_time'])) {
            $diff = strtotime($row['logout_time']) - strtotime($row['login_time']);
            $duration = $diff < 60 ? $diff.'s' : ($diff < 3600 ? round($diff/60).'m' : round($diff/3600,1).'h');
        }
        fputcsv($output, [
            $row['empcode'] ?? '',
            $row['empname'] ?? '',
            $row['hq'] ?? '',
            $row['department'] ?? '',
            $row['designation'] ?? '',
            $row['username'],
            trim(($row['device_name'] ?? '') . ' ' . ($row['device_model'] ?? '')),
            $row['login_location'] ?? '',
            $row['login_time'],
            $row['logout_time'] ?? '',
            $duration,
            $row['status']
        ]);
    }
    fclose($output);
    exit;
}

function getSortLink($column, $label) {
    global $sortKey, $sortDir;
    $params = $_GET;
    $params['sort'] = $column;
    $params['dir'] = ($sortKey === $column && $sortDir === 'ASC') ? 'DESC' : 'ASC';
    
    $icon = 'fa-sort';
    if ($sortKey === $column) {
        $icon = $sortDir === 'ASC' ? 'fa-sort-up' : 'fa-sort-down';
    }
    
    $url = '?' . http_build_query($params);
    return "<a href=\"$url\" class=\"sort-link\">$label <i class=\"fa-solid $icon\"></i></a>";
}

$pageTitle = "Login History";
include __DIR__ . '/../includes/header.php'; 
?>


<form method="GET" class="filter-wrapper" id="historyForm">
    <input type="hidden" name="sort" value="<?= htmlspecialchars($sortKey) ?>">
    <input type="hidden" name="dir" value="<?= htmlspecialchars($sortDir) ?>">

    <select name="department" onchange="document.getElementById('designation').value=''; this.form.submit()">
        <option value="">All Departments</option>
        <?php foreach ($departments as $dept): ?>
            <option value="<?= htmlspecialchars($dept) ?>" <?= $deptFilter === $dept ? 'selected' : '' ?>><?= htmlspecialchars($dept) ?></option>
        <?php endforeach; ?>
    </select>

    <select name="designation" id="designation" onchange="this.form.submit()">
        <option value="">All Designations</option>
        <?php foreach ($designations as $desig): ?>
            <option value="<?= htmlspecialchars($desig) ?>" <?= $desigFilter === $desig ? 'selected' : '' ?>><?= htmlspecialchars($desig) ?></option>
        <?php endforeach; ?>
    </select>

    <input type="text" name="employee" placeholder="Code / Name" value="<?= htmlspecialchars($empFilter) ?>" style="width:120px;" autocomplete="off">

    <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" title="From" onchange="this.form.submit()">
    <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" title="To" onchange="this.form.submit()">
    <input type="month" name="month" value="<?= htmlspecialchars($monthFilter) ?>" title="Month" onchange="this.form.submit()">

    <select name="status" onchange="this.form.submit()">
        <option value="">All Status</option>
        <option value="Active" <?= $statusFilter === 'Active' ? 'selected' : '' ?>>Active</option>
        <option value="Completed" <?= $statusFilter === 'Completed' ? 'selected' : '' ?>>Completed</option>
    </select>

    <button type="submit" class="btn-filter"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <a href="loginhistory.php" class="btn-clear"><i class="fa-solid fa-rotate-left"></i> Clear</a>

    <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'excel'])) ?>" class="btn-export">
        <i class="fa-solid fa-file-excel"></i> Export
    </a>
</form>

<div class="data-card">
    <div class="table-responsive">
        <?php if (count($sessions) > 0): ?>
        <table class="erp-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= getSortLink('empcode', 'Emp Code') ?></th>
                    <th><?= getSortLink('empname', 'Employee Name') ?></th>
                    <th>HQ</th>
                    <th><?= getSortLink('department', 'Department') ?></th>
                    <th><?= getSortLink('designation', 'Designation') ?></th>
                    <th>Device Details</th>
                    <th>Area / Location</th>
                    <th><?= getSortLink('login_time', 'Login Time') ?></th>
                    <th>Logout Time</th>
                    <th>Duration</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sessions as $i => $row): ?>
                    <?php
                        $duration = '-';
                        if (!empty($row['logout_time'])) {
                            $diff = strtotime($row['logout_time']) - strtotime($row['login_time']);
                            $duration = $diff < 60 ? $diff.'s' : ($diff < 3600 ? round($diff/60).'m' : round($diff/3600,1).'h');
                        }

                        $rawLoc = $row['login_location'] ?? '-';
                        $displayLoc = htmlspecialchars($rawLoc);
                        
                        // Extract Lat Long to trigger reverse geocoding script
                        if (strpos($rawLoc, 'Lat:') !== false) {
                            preg_match('/Lat:\s*([0-9\.-]+),\s*Lng:\s*([0-9\.-]+)/', $rawLoc, $matches);
                            if (count($matches) === 3) {
                                $lat = $matches[1];
                                $lng = $matches[2];
                                $displayLoc = "<a href=\"https://www.google.com/maps?q={$lat},{$lng}\" target=\"_blank\" class=\"loc-gps geo-decode\" data-lat=\"{$lat}\" data-lng=\"{$lng}\" title=\"Open Map\">
                                    <i class=\"fa-solid fa-location-dot\"></i> <span class=\"loc-name\"><i class=\"fa-solid fa-spinner spinner-small\"></i> Fetching Area...</span>
                                </a>";
                            } else {
                                $displayLoc = "<span class=\"loc-gps\"><i class=\"fa-solid fa-location-crosshairs\"></i> " . htmlspecialchars($rawLoc) . "</span>";
                            }
                        } elseif (strpos($rawLoc, 'IP:') !== false) {
                            $displayLoc = "<span class=\"loc-ip\"><i class=\"fa-solid fa-network-wired\"></i> " . htmlspecialchars($rawLoc) . "</span>";
                        }
                    ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td style="font-weight: 600;"><?= htmlspecialchars($row['empcode'] ?? '-') ?></td>
                        <td style="font-weight: 600;"><?= htmlspecialchars($row['empname'] ?? $row['username']) ?></td>
                        <td><?= htmlspecialchars($row['hq'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['department'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($row['designation'] ?? '-') ?></td>
                        <td><?= htmlspecialchars(trim(($row['device_name'] ?? '') . ' ' . ($row['device_model'] ?? ''))) ?></td>
                        <td><?= $displayLoc ?></td>
                        <td><?= date('d-M-y H:i', strtotime($row['login_time'])) ?></td>
                        <td><?= $row['logout_time'] ? date('d-M-y H:i', strtotime($row['logout_time'])) : '-' ?></td>
                        <td><?= $duration ?></td>
                        <td>
                            <?php if ($row['status'] === 'Active'): ?>
                                <span class="status-badge status-active">Active</span>
                            <?php else: ?>
                                <span class="status-badge status-completed">Completed</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <div class="empty-state">
                No login history found matching the selected criteria.
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Asynchronous Live Area Fetching logic using free Client API
    document.addEventListener('DOMContentLoaded', () => {
        const geoNodes = document.querySelectorAll('.geo-decode');
        geoNodes.forEach(node => {
            const lat = node.getAttribute('data-lat');
            const lng = node.getAttribute('data-lng');
            const nameSpan = node.querySelector('.loc-name');
            
            fetch(`https://api.bigdatacloud.net/data/reverse-geocode-client?latitude=${lat}&longitude=${lng}&localityLanguage=en`)
                .then(response => response.json())
                .then(data => {
                    let area = data.locality || data.city || data.principalSubdivision || 'Unknown Location';
                    let state = data.principalSubdivision ? ', ' + data.principalSubdivision : '';
                    nameSpan.textContent = area + state;
                })
                .catch(() => {
                    nameSpan.textContent = `${lat}, ${lng}`; // Fallback to raw coords if API fails
                });
        });
    });
</script>

    </main>
    </div>
</div>
</body>
</html>