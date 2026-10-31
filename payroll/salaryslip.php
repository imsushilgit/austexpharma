<?php
require_once __DIR__ . '/../includes/core.php';

$ctx = require_page_access($pdo);
$isAdmin   = $ctx['is_admin'];
$isManager = $ctx['is_manager'];
$isMR      = $ctx['is_mr'];
$teamCodes = $ctx['team_codes'];
$empCode   = $ctx['empcode'];

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    die('Invalid payslip request.');
}

$stmt = $pdo->prepare("SELECT * FROM monthly_salary WHERE id = ?");
$stmt->execute([$id]);
$sal = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sal) {
    die('Salary record not found.');
}

// Access check
if ($isMR && $sal['empcode'] !== $empCode) {
    die('Access denied. You can only view your own payslip.');
}
if ($isManager && !in_array($sal['empcode'], $teamCodes) && $sal['empcode'] !== $empCode) {
    die('Access denied. This employee is not in your team.');
}

$components = json_decode($sal['components_json'] ?? '{}', true);
$earnings = $components['earnings'] ?? [];
$deductions = $components['deductions'] ?? [];

$monthName = date('F', mktime(0, 0, 0, $sal['salary_month'], 1));
$empInfo = $pdo->prepare("SELECT designation, hq, department, state FROM employees WHERE empcode = ?");
$empInfo->execute([$sal['empcode']]);
$emp = $empInfo->fetch(PDO::FETCH_ASSOC) ?: [];

// Company info (simple)
$companyName = 'Company Name ical';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip - <?= htmlspecialchars($sal['empname']) ?> - <?= $monthName ?> <?= $sal['salary_year'] ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="pg-payroll-salaryslip">
    <button class="print-btn" onclick="window.print()">Print / Save PDF</button>

    <div class="slip">
        <div class="header">
            <div>
                <h1><?= htmlspecialchars($companyName) ?></h1>
                <p>Salary Slip</p>
            </div>
            <div style="text-align:right;">
                <div style="font-size:16px; font-weight:700;"><?= $monthName ?> <?= $sal['salary_year'] ?></div>
                <div style="font-size:11px; opacity:0.85;">Status: <?= htmlspecialchars($sal['status']) ?></div>
            </div>
        </div>

        <div class="meta">
            <div>
                <label>Employee</label>
                <span><?= htmlspecialchars($sal['empname']) ?> (<?= htmlspecialchars($sal['empcode']) ?>)</span>
            </div>
            <div>
                <label>Designation / HQ</label>
                <span><?= htmlspecialchars($emp['designation'] ?? '-') ?> / <?= htmlspecialchars($emp['hq'] ?? '-') ?></span>
            </div>
            <div>
                <label>Paid Days / Present</label>
                <span><?= $sal['paid_days'] ?> / <?= $sal['present_days'] ?></span>
            </div>
        </div>

        <div class="body">
            <div class="section">
                <h3>Earnings</h3>
                <table>
                    <?php foreach ($earnings as $e): ?>
                    <tr>
                        <td><?= htmlspecialchars($e['name']) ?></td>
                        <td>₹ <?= number_format($e['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row">
                        <td>Gross Earnings</td>
                        <td>₹ <?= number_format($sal['gross_earning'], 2) ?></td>
                    </tr>
                </table>
            </div>
            <div class="section">
                <h3>Deductions</h3>
                <table>
                    <?php if (empty($deductions)): ?>
                        <tr><td colspan="2" style="color:#94a3b8;">No deductions</td></tr>
                    <?php else: ?>
                        <?php foreach ($deductions as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars($d['name']) ?></td>
                            <td>₹ <?= number_format($d['amount'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td>Total Deductions</td>
                        <td>₹ <?= number_format($sal['total_deduction'], 2) ?></td>
                    </tr>
                </table>
            </div>

            <div class="net-box">
                <div class="label">Net Salary Payable</div>
                <div class="amount">₹ <?= number_format($sal['net_salary'], 2) ?></div>
            </div>
        </div>

        <div class="footer">
            This is a computer-generated payslip and does not require a signature.<br>
            Generated on <?= date('d M Y, h:i A', strtotime($sal['generated_on'])) ?>
            <?php if ($sal['generated_by']): ?> by <?= htmlspecialchars($sal['generated_by']) ?><?php endif; ?>
        </div>
    </div>
</body>
</html>
