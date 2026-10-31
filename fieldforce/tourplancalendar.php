<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/core.php';

// --- User Context ---
$stmtUser = $pdo->prepare("SELECT u.role, u.username, e.empname, e.empcode 
    FROM users u LEFT JOIN employees e ON (u.username = e.empcode OR u.username = e.empname) AND e.resignation_date IS NULL 
    WHERE u.id = ? ORDER BY (e.empcode = u.username) DESC LIMIT 1");
$stmtUser->execute([$_SESSION['user_id']]);
$userMeta = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
$empCode  = $userMeta['empcode'] ?? $userMeta['username'] ?? '';

// ROLE SCOPE: MR sees own calendar; manager can pick anyone in their team; admin can pick anyone
$viewEmp = $ctx['empcode'] ?: $empCode;
if (!empty($_GET['emp']) && can_access_emp($ctx, $_GET['emp'])) { $viewEmp = trim($_GET['emp']); }
$teamEmps = [];
if (!$ctx['is_mr']) {
    list($__ph, $__codes) = scope_placeholders($ctx);
    $__q = $pdo->prepare("SELECT empcode, empname FROM employees WHERE empcode IN ($__ph) ORDER BY empname");
    $__q->execute($__codes);
    $teamEmps = $__q->fetchAll(PDO::FETCH_ASSOC);
}
$empQ = '&emp=' . urlencode($viewEmp);

// Month navigation
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('n');
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
if ($month < 1)  { $month = 12; $year--; }
if ($month > 12) { $month = 1;  $year++; }

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate   = date('Y-m-t', strtotime($startDate));

// Fetch tour plans for the month (adjust column names if different in your tour_plans table)
$stmt = $pdo->prepare("SELECT * FROM tour_plans WHERE empcode = ? AND plan_date BETWEEN ? AND ? ORDER BY plan_date");
$stmt->execute([$viewEmp, $startDate, $endDate]);
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Index by date
$planByDate = [];
foreach ($plans as $p) {
    $d = $p['plan_date'] ?? $p['tour_date'] ?? null;
    if ($d) $planByDate[$d][] = $p;
}

$pageTitle = "Tour Plan Calendar";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1><i class="fas fa-calendar-alt"></i> Tour Plan Calendar</h1>
        <p class="text-muted mb-0"><?= date('F Y', strtotime($startDate)) ?></p>
    </div>
    <div>
        <?php if (!empty($teamEmps)): ?>
        <form method="get" class="d-inline-block me-2">
            <input type="hidden" name="month" value="<?= $month ?>"><input type="hidden" name="year" value="<?= $year ?>">
            <select name="emp" class="form-select form-select-sm d-inline-block w-auto" onchange="this.form.submit()">
                <?php foreach ($teamEmps as $te): ?>
                <option value="<?= htmlspecialchars($te['empcode']) ?>" <?= $te['empcode'] === $viewEmp ? 'selected' : '' ?>><?= htmlspecialchars($te['empname']) ?> (<?= htmlspecialchars($te['empcode']) ?>)</option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
        <a href="?month=<?= $month-1 ?>&year=<?= $year ?><?= $empQ ?>" class="btn btn-outline-primary btn-sm">&laquo; Prev</a>
        <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?><?= $empQ ?>" class="btn btn-outline-secondary btn-sm">Today</a>
        <a href="?month=<?= $month+1 ?>&year=<?= $year ?><?= $empQ ?>" class="btn btn-outline-primary btn-sm">Next &raquo;</a>
        <a href="tourplan.php" class="btn btn-primary btn-sm ms-2"><i class="fas fa-edit"></i> Prepare Tour Plan</a>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body p-2">
        <div class="table-responsive">
            <table class="table table-bordered calendar-table mb-0">
                <thead class="table-light text-center">
                    <tr>
                        <th>Sun</th><th>Mon</th><th>Tue</th><th>Wed</th><th>Thu</th><th>Fri</th><th>Sat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $firstDay = date('w', strtotime($startDate)); // 0=Sun
                    $daysInMonth = date('t', strtotime($startDate));
                    $day = 1;
                    for ($row = 0; $row < 6; $row++):
                        if ($day > $daysInMonth) break;
                        echo "<tr>";
                        for ($col = 0; $col < 7; $col++):
                            if (($row === 0 && $col < $firstDay) || $day > $daysInMonth) {
                                echo "<td class='bg-light'></td>";
                            } else {
                                $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                                $isToday = ($dateStr === date('Y-m-d'));
                                $hasPlan = isset($planByDate[$dateStr]);
                                $cellClass = $isToday ? 'table-info' : ($hasPlan ? 'table-success' : '');
                                echo "<td class='$cellClass' style='height:90px;vertical-align:top;min-width:120px;'>";
                                echo "<div class='fw-bold'>$day</div>";
                                if ($hasPlan) {
                                    foreach ($planByDate[$dateStr] as $p) {
                                        $place = htmlspecialchars($p['place'] ?? $p['area'] ?? $p['hq'] ?? 'Visit');
                                        $type  = htmlspecialchars($p['visit_type'] ?? '');
                                        echo "<div class='small text-truncate' title='$place'>$place" . ($type ? " ($type)" : "") . "</div>";
                                    }
                                }
                                echo "</td>";
                                $day++;
                            }
                        endfor;
                        echo "</tr>";
                    endfor;
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
