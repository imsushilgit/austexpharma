<?php
// Expects: $pdo, $ff, $earnings, $deductions, $net, $ref, $secureAadhaar, inr()
if (!isset($ff)) die('Invalid letter request');

// Fetch dynamic company info
$company = [
    'companyname' => 'Company Name',
    'city' => '',
    'pincode' => '',
    'email' => '',
    'website' => '',
    'logotype' => 'none',
    'logopath' => '',
    'logohtml' => ''
];

try {
    if (isset($pdo)) {
        $stmtComp = $pdo->query("SELECT * FROM companies ORDER BY id ASC LIMIT 1");
        if ($cData = $stmtComp->fetch(PDO::FETCH_ASSOC)) {
            $company = array_merge($company, $cData);
        }
    }
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>F&F Settlement - <?= htmlspecialchars($ff['empname']) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="pg-payroll-fullfinal-letter">
<button class="print-btn" onclick="window.print()">Print / Save PDF</button>

<div class="print-box">
  <div class="brand-header" style="display:flex; justify-content:space-between; align-items:flex-start;">
    <div class="brand-title-row">
      
      <!-- DYNAMIC LOGO HANDLING -->
      <div style="margin-bottom: 10px;">
          <?php if ($company['logotype'] === 'image' && !empty($company['logopath'])): ?>
              <img src="<?= BASE_URL . ltrim(htmlspecialchars($company['logopath']), '/') ?>" alt="Logo" style="max-height: 50px; object-fit: contain;">
          <?php elseif ($company['logotype'] === 'html' && !empty($company['logohtml'])): ?>
              <?= $company['logohtml'] ?>
          <?php else: ?>
              <h2 style="margin: 0; color: var(--nv); font-weight: 700;"><?= htmlspecialchars($company['companyname']) ?></h2>
          <?php endif; ?>
      </div>

      <!-- DYNAMIC COMPANY DETAILS -->
      <div style="font-size: 11px; color: var(--muted); line-height: 1.4;">
          <?php if(!empty($company['address'])) echo htmlspecialchars($company['address']) . '<br>'; ?>
          <?php if(!empty($company['city'])) echo htmlspecialchars($company['city']) . ' - ' . htmlspecialchars($company['pincode']) . '<br>'; ?>
          <?php if(!empty($company['phone'])) echo 'Ph: ' . htmlspecialchars($company['phone']) . ' | '; ?>
          <?php if(!empty($company['email'])) echo 'Email: ' . htmlspecialchars($company['email']); ?>
      </div>

    </div>
    <div class="meta-box" style="text-align: right;">
      <b>Ref: <?= htmlspecialchars($ref) ?></b><br>
      Date: <?= date('d/m/Y') ?>
    </div>
  </div>

  <div class="ltit"><h2>Full & Final Settlement</h2></div>

  <p style="font-size:12px; line-height:1.6; margin-bottom:12px; text-align:justify;">
    We hereby confirm the seamless and complete final account settlement for
    <b class="highlight"><?= htmlspecialchars($ff['empname']) ?></b>
    (Code: <b class="highlight"><?= htmlspecialchars($ff['empcode']) ?></b>),
    successfully concluding all financial records.
  </p>

  <div class="ig">
    <div class="ir"><span>Division</span><span><?= htmlspecialchars($ff['division'] ?: '—') ?></span></div>
    <div class="ir"><span>Department</span><span><?= htmlspecialchars($ff['department'] ?: '—') ?></span></div>
    <div class="ir"><span>Designation</span><span><?= htmlspecialchars($ff['designation'] ?: '—') ?></span></div>
    <div class="ir"><span>Headquarter</span><span><?= htmlspecialchars($ff['hq'] ?: '—') ?></span></div>
    <div class="ir"><span>Joining Date</span><span><?= $ff['joining_date'] ? date('d/m/Y', strtotime($ff['joining_date'])) : '—' ?></span></div>
    <div class="ir"><span>Last Working Date</span><span><?= $ff['last_working_date'] ? date('d/m/Y', strtotime($ff['last_working_date'])) : '—' ?></span></div>
    <div class="ir"><span>Experience</span><span><?= htmlspecialchars($ff['experience'] ?: '—') ?></span></div>
    <div class="ir"><span>Reason</span><span><?= htmlspecialchars($ff['reason'] ?: '—') ?></span></div>
    <div class="ir"><span>State</span><span><?= htmlspecialchars($ff['state'] ?: '—') ?></span></div>
    <div class="ir"><span>Notice Period</span><span><?= htmlspecialchars($ff['notice_period'] ?: '—') ?></span></div>
  </div>

  <div class="ig">
    <div class="ir"><span>Last Paid Salary</span><span><?= htmlspecialchars($ff['last_paid_sal_month'] ?: '—') ?> (<?= inr($ff['last_paid_sal_amount']) ?>)</span></div>
    <div class="ir"><span>Last Paid Expense</span><span><?= htmlspecialchars($ff['last_paid_exp_month'] ?: '—') ?> (<?= inr($ff['last_paid_exp_amount']) ?>)</span></div>
  </div>

  <b style="font-size:11px; color: var(--nv); text-transform: uppercase;">Payment & Identity Details</b>
  <div class="ig-3col" style="margin-top:6px;">
    <div class="ir-block">
      <div class="ir"><span>Bank Name</span><span><?= htmlspecialchars($ff['bank_name'] ?: '—') ?></span></div>
      <div class="ir" style="margin-top:4px;"><span>PAN</span><span><?= htmlspecialchars(strtoupper($ff['pan'] ?: '—')) ?></span></div>
    </div>
    <div class="ir-block">
      <div class="ir"><span>A/C No</span><span><?= htmlspecialchars($ff['account_no'] ?: '—') ?></span></div>
      <div class="ir" style="margin-top:4px;"><span>Aadhaar</span><span><?= htmlspecialchars($secureAadhaar) ?></span></div>
    </div>
    <div class="ir-block">
      <div class="ir"><span>IFSC Code</span><span><?= htmlspecialchars(strtoupper($ff['ifsc'] ?: '—')) ?></span></div>
    </div>
  </div>

  <div class="g2">
    <div>
      <b style="font-size:11px; color: var(--nv); display:block; margin-bottom:4px; text-transform: uppercase;">Earnings</b>
      <table>
        <thead><tr><th>Month</th><th>Description</th><th style="text-align:right">Amount</th></tr></thead>
        <tbody>
          <?php if (empty($earnings)): ?>
            <tr><td colspan="3" style="text-align:center;color:#94a3b8;">None</td></tr>
          <?php else: foreach ($earnings as $e): ?>
            <tr>
              <td><?= htmlspecialchars($e['month'] ?? '—') ?></td>
              <td><strong><?= htmlspecialchars($e['desc'] ?? '') ?></strong></td>
              <td style="text-align:right; font-family: monospace; font-size:11px;"><?= inr($e['amount'] ?? 0) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          <?php if ($ff['leave_encashment'] > 0): ?>
            <tr><td>—</td><td><strong>Leave Encashment</strong></td><td style="text-align:right; font-family: monospace; font-size:11px;"><?= inr($ff['leave_encashment']) ?></td></tr>
          <?php endif; ?>
          <?php if ($ff['gratuity'] > 0): ?>
            <tr><td>—</td><td><strong>Gratuity</strong></td><td style="text-align:right; font-family: monospace; font-size:11px;"><?= inr($ff['gratuity']) ?></td></tr>
          <?php endif; ?>
          <?php if ($ff['pending_salary'] > 0): ?>
            <tr><td>—</td><td><strong>Pending Salary (auto)</strong></td><td style="text-align:right; font-family: monospace; font-size:11px;"><?= inr($ff['pending_salary']) ?></td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot style="background: var(--panel);"><tr><td colspan="2"><b>Total Earnings</b></td><td style="text-align:right; color: var(--nv);"><b><?= inr($ff['total_earnings']) ?></b></td></tr></tfoot>
      </table>
    </div>
    <div>
      <b style="font-size:11px; color: var(--nv); display:block; margin-bottom:4px; text-transform: uppercase;">Deductions</b>
      <table>
        <thead><tr><th>Month</th><th>Description</th><th style="text-align:right">Amount</th></tr></thead>
        <tbody>
          <?php if (empty($deductions)): ?>
            <tr><td colspan="3" style="text-align:center;color:#94a3b8;">None</td></tr>
          <?php else: foreach ($deductions as $d): ?>
            <tr>
              <td><?= htmlspecialchars($d['month'] ?? '—') ?></td>
              <td><strong><?= htmlspecialchars($d['desc'] ?? '') ?></strong></td>
              <td style="text-align:right; font-family: monospace; font-size:11px; color: #dc2626;"><?= inr($d['amount'] ?? 0) ?></td>
            </tr>
          <?php endforeach; endif; ?>
          <?php if ($ff['advance_recovery'] > 0): ?>
            <tr><td>—</td><td><strong>Advance Recovery</strong></td><td style="text-align:right; font-family: monospace; font-size:11px; color: #dc2626;"><?= inr($ff['advance_recovery']) ?></td></tr>
          <?php endif; ?>
          <?php if ($ff['other_recovery'] > 0): ?>
            <tr><td>—</td><td><strong>Other Recovery</strong></td><td style="text-align:right; font-family: monospace; font-size:11px; color: #dc2626;"><?= inr($ff['other_recovery']) ?></td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot style="background: var(--panel);"><tr><td colspan="2"><b>Total Deductions</b></td><td style="text-align:right; color: #dc2626;"><b><?= inr($ff['total_deductions']) ?></b></td></tr></tfoot>
      </table>
    </div>
  </div>

  <div class="net">
    <div style="font-weight:700; font-size:12px; letter-spacing: 0.5px;">NET SETTLEMENT PAYABLE</div>
    <div style="font-size:10px; text-transform:uppercase; font-weight: 600; opacity:0.9;"><?= a2w($net) ?></div>
    <div style="font-size:18px; font-weight:800; color: var(--sky); font-family: monospace;"><?= inr($net) ?></div>
  </div>

  <p style="font-size:11px; text-align:justify; line-height:1.6; margin-top:12px; color: var(--muted);">
    The net amount will be credited to the employee's bank account as per company policy.
    This document serves as a full and final discharge of all dues between the employee and <?= htmlspecialchars($company['companyname']) ?>.
  </p>

  <div class="sign-block">
    <div class="sign-col left">
      <div class="s-line"></div><br>
      <b><?= htmlspecialchars($ff['prepared_by'] ?: 'HR Department') ?></b><br>HR Department
    </div>
    <div class="sign-col right">
      <div class="stamp-center"><?= htmlspecialchars(substr($company['companyname'], 0, 15)) ?><br>HRD<br>APPROVED</div>
      <div class="s-line"></div><br>
      <b><?= htmlspecialchars($ff['approved_by'] ?: 'Authorized Signatory') ?></b><br><?= htmlspecialchars($company['companyname']) ?>
    </div>
  </div>
</div>
</body>
</html>