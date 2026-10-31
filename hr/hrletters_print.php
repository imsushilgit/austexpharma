<?php 
if (!isset($L)) die('Invalid Letter Request');

// Fetch Dynamic Company Details
$cData = [];
try {
    if (isset($pdo)) {
        $cStmt = $pdo->query("SELECT * FROM companies LIMIT 1");
        if ($row = $cStmt->fetch(PDO::FETCH_ASSOC)) {
            $cData = $row;
        }
    }
} catch(Exception $e) {}

$cName = !empty($cData['companyname']) ? trim($cData['companyname']) : 'Company Name';
$addressParts = [];
if (!empty($cData['address'])) $addressParts[] = trim($cData['address']);
if (!empty($cData['city'])) $addressParts[] = trim($cData['city']);
if (!empty($cData['state'])) $addressParts[] = trim($cData['state']) . (!empty($cData['pincode']) ? ' - '.$cData['pincode'] : '');
$cFullAddress = !empty($addressParts) ? implode(', ', $addressParts) : 'Corporate Office';
$cLocation = !empty($cData['city']) ? trim($cData['city']) : 'Head Office';

$logotype = $cData['logotype'] ?? 'none';
$logopath = $cData['logopath'] ?? '';
$logohtml = $cData['logohtml'] ?? '';

$nameParts = explode(' ', $cName, 2);
$logoWord1 = $nameParts[0] ?: 'Company';
$logoWord2 = $nameParts[1] ?? 'Name';

$template = $L['template_style'] ?? 'Modern';
$isClassic = ($template === 'Classic');
$isMinimal = ($template === 'Minimal');

// ==========================================
// Fetch Dynamic TA/DA from tada_master
// ==========================================
$tada = ['da_hq' => 0, 'da_exhq' => 0, 'da_os' => 0, 'ta_local' => 0, 'ta_os' => 0];
try {
    // Check if tada_master exists and fetch
    $tadaStmt = $pdo->prepare("SELECT * FROM tada_master WHERE designation = ? LIMIT 1");
    $targetDesignation = $L['new_designation'] ?: $L['designation'];
    $tadaStmt->execute([$targetDesignation]);
    if ($tRow = $tadaStmt->fetch(PDO::FETCH_ASSOC)) {
        $tada = $tRow;
    }
} catch (Exception $e) {}

// Construct Allowance String
if ($tada['da_hq'] > 0 || $tada['ta_local'] > 0) {
    $allowanceText = "<b>TA (Local/OS):</b> ₹{$tada['ta_local']} / ₹{$tada['ta_os']} &nbsp; | &nbsp; <b>DA (HQ/Ex-HQ/OS):</b> ₹{$tada['da_hq']} / ₹{$tada['da_exhq']} / ₹{$tada['da_os']} per day.";
} else {
    $allowanceText = "<b>TA:</b> Actual TA. <b>DA:</b> Actual DA per day (As per prevailing company policy).";
}


// Helper Function: Number to Words
function numberToWords($num) {
    $num = floor($num);
    if($num == 0) return "Zero Rupees Only";
    $ones = ["", "One", "Two", "Three", "Four", "Five", "Six", "Seven", "Eight", "Nine", "Ten", "Eleven", "Twelve", "Thirteen", "Fourteen", "Fifteen", "Sixteen", "Seventeen", "Eighteen", "Nineteen"];
    $tens = ["", "", "Twenty", "Thirty", "Forty", "Fifty", "Sixty", "Seventy", "Eighty", "Ninety"];
    
    function convertToWords($n, $ones, $tens) {
        if($n < 20) return $ones[$n];
        if($n < 100) return $tens[floor($n/10)] . ($n%10 ? " ".$ones[$n%10] : "");
        if($n < 1000) return $ones[floor($n/100)] . " Hundred " . ($n%100 ? convertToWords($n%100, $ones, $tens) : "");
        if($n < 100000) return convertToWords(floor($n/1000), $ones, $tens) . " Thousand " . ($n%1000 ? convertToWords($n%1000, $ones, $tens) : "");
        if($n < 10000000) return convertToWords(floor($n/100000), $ones, $tens) . " Lakh " . ($n%100000 ? convertToWords($n%100000, $ones, $tens) : "");
        return convertToWords(floor($n/10000000), $ones, $tens) . " Crore " . ($n%10000000 ? convertToWords($n%10000000, $ones, $tens) : "");
    }
    return trim(convertToWords($num, $ones, $tens)) . " Rupees Only";
}

// ==========================================
// Salary Structure Calculation Logic
// ==========================================
$yearlyCTC = floatval($L['new_salary'] ?? 0);
$monthlyCTC = $yearlyCTC / 12;
$basic = $monthlyCTC / 2;
$hra = $basic / 2;

$pfEmployer = 0; $esicEmployee = 0; $esicEmployer = 0;
$grossSalary = 0; $allowance = 0;

for($i=0; $i<10; $i++){
    $grossSalary = $monthlyCTC - $pfEmployer - $esicEmployer;
    $allowance = $grossSalary - $basic - $hra;
    $pfWage = $basic + $allowance;
    
    $calculatedPF = ($pfWage * 12) / 100;
    if($calculatedPF > 1800) $calculatedPF = 1800;

    $calculatedEmployeeESIC = 0;
    $calculatedEmployerESIC = 0;
    if($grossSalary <= 21000){
        $calculatedEmployeeESIC = ($grossSalary * 0.75) / 100;
        $calculatedEmployerESIC = ($grossSalary * 3.25) / 100;
    }
    $pfEmployer = $calculatedPF;
    $esicEmployee = $calculatedEmployeeESIC;
    $esicEmployer = $calculatedEmployerESIC;
}

$pfEmployee = $pfEmployer; 
$grossSalary = $monthlyCTC - $pfEmployer - $esicEmployer;
$allowance = $grossSalary - $basic - $hra;
$totalDeduction = $pfEmployee + $esicEmployee;
$netSalary = $grossSalary - $totalDeduction;
$totalEmployerContribution = $pfEmployer + $esicEmployer;

if ($yearlyCTC <= 0) {
    $basic = 0; $hra = 0; $allowance = 0; $pfEmployee = 0; $pfEmployer = 0;
    $esicEmployee = 0; $esicEmployer = 0; $grossSalary = 0; $netSalary = 0; $totalDeduction = 0; $totalEmployerContribution = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><title><?= htmlspecialchars($type) ?> Letter - <?= htmlspecialchars($L['empname']) ?></title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="pg-hr-hrletters-print tpl-<?= $isClassic ? 'classic' : ($isMinimal ? 'minimal' : 'modern') ?>">
<button class="print-btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save PDF</button>

<div class="print-box">
  
  <!-- DYNAMIC HEADER -->
  <div class="brand-header">
    <div style="text-align: <?= $isClassic ? 'center' : 'left' ?>;">
        <?php if ($logotype === 'image' && !empty($logopath)): ?>
            <img src="/erp/<?= htmlspecialchars($logopath) ?>" style="max-height: 50px;">
        <?php elseif ($logotype === 'html' && !empty($logohtml)): ?>
            <?= $logohtml ?>
        <?php else: ?>
            <div class="css-logo-box">
                <div class="css-logo-wrapper">
                    <div class="css-logo-circle">
                        <div class="css-logo-cross"><div class="css-logo-t-blue"></div><div class="css-logo-divider"></div><div class="css-logo-t-sky"></div></div>
                        <div class="css-logo-whitediv"></div>
                    </div>
                    <div class="css-logo-right-cut"></div>
                </div>
                <div class="css-logo-text">
                    <span class="css-logo-austex"><?= htmlspecialchars($logoWord1) ?></span>
                    <span class="css-logo-pharma"><?= htmlspecialchars($logoWord2) ?></span>
                </div>
            </div>
        <?php endif; ?>
        <div class="sub">Human Resources Division</div>
        <?php if($isClassic): ?>
            <div style="font-size: 10px; color: #64748b; margin-top: 4px;"><?= htmlspecialchars($cFullAddress) ?></div>
        <?php endif; ?>
    </div>
    <div class="meta-box">
      <b>Ref: <?= htmlspecialchars($ref) ?></b><br>
      Date: <?= htmlspecialchars(is_string($date) && strpos($date,'-') ? date('d-M-Y', strtotime($date)) : $date) ?>
    </div>
  </div>

  <div style="margin-bottom: 20px;">
      <b>To,</b><br>
      <span style="font-size: 14px; font-weight: bold; color: var(--nv);"><?= htmlspecialchars($L['empname']) ?></span><br>
      <b>Emp Code:</b> <?= htmlspecialchars($L['empcode']) ?><br>
      <b>Department:</b> <?= htmlspecialchars($L['department'] ?: 'Sales & Marketing') ?><br>
      <b>Headquarter:</b> <?= htmlspecialchars($L['hq'] ?: 'N/A') ?>
  </div>

  <h2><?= htmlspecialchars($type) ?> Letter</h2>

  <!-- OFFER OR APPOINTMENT -->
  <?php if ($type === 'Offer' || $type === 'Appointment'): ?>
      <p>Dear <b><?= htmlspecialchars($L['empname']) ?></b>,</p>
      
      <p>As per your interview had with us, we are pleased to offer you the position of <b><?= htmlspecialchars($L['designation'] ?: '—') ?></b> at <b><?= htmlspecialchars($cName) ?></b>. You will be based at <b><?= htmlspecialchars($L['work_location'] ?: $L['hq'] ?: '—') ?></b>.</p>
      
      <p>Your offer of appointment and all terms & conditions are effective from <b><?= $L['joining_date'] ? date('d-M-Y', strtotime($L['joining_date'])) : '—' ?></b>.</p>
      
      <p><b><?= htmlspecialchars($cName) ?></b> is a leading global innovative company committed to enriching lives worldwide. Our vision is to discover possibilities and make lives better across the globe. If you join us, you will become part of a fast-paced and dedicated team that works together to provide our customers with the highest possible level of products and services.</p>
      
      <p>As a member of our organization, we ask for your commitment to deliver outstanding quality and results that exceed expectations. In addition, we expect your personal accountability in all the products, actions, advice, and results that you provide as a representative of our organization. In return, we are committed to provide you with every opportunity to learn, grow, and stretch to the highest level of your ability and potential.</p>
      
      <p>We are confident that you will find this new opportunity both challenging and rewarding.</p>

      <p><b>Terms & Conditions:</b></p>
      <ul class="clause-list">
          <li><b>Transfer:</b> Your job is transferable. The Company has the right to transfer you anywhere in the country and abroad. You cannot deny it. If you fail to join as per the instruction given by the company, your association with us will be terminated automatically, and you will not have any right to oppose it.</li>
          <li><b>Training & Probation:</b> Please note that there will be a training period of 6 months which will be started from your date of joining. If you complete your training as per the company guidelines, then you will be on a probation period of <?= (int)$L['probation_months'] ?> months.</li>
          <li><b>Resignation:</b> You need to give <?= (int)$L['notice_days'] ?> days (Prior Notice) of your resignation; else you will not be eligible to demand any claim i.e., Salary / Expense etc.</li>
          <li><b>Settlement:</b> Final settlement period will be of 4-6 months from the date of Asset handover, NOC Submission to the concerned of the company, and your final claim submission.</li>
          <li>The Company has every right to take legal action against you if you don’t handover the company property in proper condition to the concerned employee of the company or if you try to damage the asset of the company or try to harm or disturb any of the working employees.</li>
          <li>Any financial transaction with external parties by you has nothing to do with the company. During parting with the organization, you have to provide NOC. If any issues regarding this arise, then the company has all the right to take appropriate action including legal against you.</li>
          <li><b>Confirmation:</b> In order to be eligible for confirmation you have to achieve consecutive 6 months target/performance metrics.</li>
          <li><b>Increments / Bonus:</b> Is subjected to achievement of target and doesn’t depend on the duration of your employment.</li>
          <li><b>Manager Reporting:</b> You will be directly reporting to <?= htmlspecialchars($L['reporting_to'] ?: 'your assigned manager') ?>.</li>
          <li><b>Allowances:</b> <?= $allowanceText ?></li>
      </ul>

      <p><b>Note:</b> Detailed salary structure is given below in the annexure. Other terms & conditions will be shared in the appointment letter, which will be issued after receipt of all documents.</p>

  <!-- INCREMENT / PROMOTION -->
  <?php elseif ($type === 'Increment' || $type === 'Promotion'): ?>
      <p>Dear <b><?= htmlspecialchars($L['empname']) ?></b>,</p>
      <p>We are delighted to inform you of the following positive adjustments to your employment profile, effective <b><?= $L['effective_date'] ? date('d-M-Y', strtotime($L['effective_date'])) : 'Immediately' ?></b>.</p>
      <p>In recognition of your continued dedication, hard work, and significant contributions to the growth of the company during the recent performance review cycle, management has approved the following <?= strtolower($type) ?>:</p>
      
      <div class="emp-card">
          <table>
              <tr><td>Letter Type</td><td><?= htmlspecialchars($type) ?></td></tr>
              <tr><td>Current Designation</td><td><span style="font-weight:normal; text-decoration:line-through;"><?= htmlspecialchars($L['current_designation'] ?: '—') ?></span></td></tr>
              <tr><td>New Designation</td><td><span style="color:var(--green);"><?= htmlspecialchars($L['new_designation'] ?: $L['designation'] ?: '—') ?></span></td></tr>
              <?php if ($L['increment_pct'] > 0): ?><tr><td>Hike Percentage</td><td><?= number_format($L['increment_pct'], 2) ?> %</td></tr><?php endif; ?>
          </table>
      </div>
      
      <p>With this <?= strtolower($type) ?>, we expect you to shoulder increased responsibilities and continue delivering high-quality results. All other standard terms and conditions of your employment contract remain unchanged. The updated annexure for your revised compensation is attached below.</p>
      <p>We wish you continued success and a bright career with us.</p>

  <!-- DEMOTION -->
  <?php elseif ($type === 'Demotion'): ?>
      <p>Dear <b><?= htmlspecialchars($L['empname']) ?></b>,</p>
      <p>This letter is to officially notify you regarding a change in your employment status effective <b><?= $L['effective_date'] ? date('d-M-Y', strtotime($L['effective_date'])) : 'Immediately' ?></b>.</p>
      <p>Following recent performance evaluations and management reviews, it has been observed that your performance has not met the required benchmarks for your current role. Consequently, management has decided to reassign your profile.</p>
      
      <div class="emp-card">
          <table>
              <tr><td>Previous Designation</td><td><?= htmlspecialchars($L['current_designation'] ?: '—') ?></td></tr>
              <tr><td>Reassigned Designation</td><td><span style="color:red;"><?= htmlspecialchars($L['new_designation'] ?: $L['designation'] ?: '—') ?></span></td></tr>
          </table>
      </div>

      <p>We believe this reassignment will provide you with an opportunity to improve your performance in a capacity better aligned with your current capabilities. Your performance will be closely monitored over the next quarter. The revised salary structure is attached below.</p>
      <p>All other terms of your employment agreement remain unchanged.</p>

  <!-- TRANSFER -->
  <?php elseif ($type === 'Transfer'): ?>
      <p>Dear <b><?= htmlspecialchars($L['empname']) ?></b>,</p>
      <p>This is to formally notify you that due to evolving business requirements and organizational restructuring, you are being transferred to a new geographical territory/branch.</p>
      <p>This transfer will be effective from <b><?= $L['effective_date'] ? date('d-M-Y', strtotime($L['effective_date'])) : 'Immediately' ?></b>.</p>
      
      <div class="emp-card">
          <table>
              <tr><td>Designation</td><td><?= htmlspecialchars($L['designation'] ?: '—') ?></td></tr>
              <tr><td>Current Headquarter (HQ)</td><td><span style="font-weight:normal; text-decoration:line-through;"><?= htmlspecialchars($L['current_hq'] ?: '—') ?></span></td></tr>
              <tr><td>New Assigned Headquarter (HQ)</td><td><span style="color:var(--green);"><?= htmlspecialchars($L['new_hq'] ?: $L['hq'] ?: '—') ?></span></td></tr>
              <tr><td>New Reporting Manager</td><td><?= htmlspecialchars($L['reporting_to'] ?: '—') ?></td></tr>
              <tr><td>Transition/Notice Period</td><td><?= (int)$L['notice_days'] ?> Day(s)</td></tr>
          </table>
      </div>

      <p>You are requested to hand over your current duties and report to your new Headquarter by the effective date mentioned above. Your compensation (attached below) and core employment conditions remain completely unchanged.</p>
      <p>We expect your full cooperation during this transition phase.</p>

  <?php endif; ?>

  <?php if (!empty($L['body_extra'])): ?>
      <p><b>Additional Notes / Clauses:</b><br><?= nl2br(htmlspecialchars($L['body_extra'])) ?></p>
  <?php endif; ?>

  <!-- ============================================== -->
  <!-- SALARY STRUCTURE ANNEXURE -->
  <!-- ============================================== -->
  <h3 style="text-align: center; margin: 30px 0 10px 0; color: var(--nv); text-decoration: underline; text-transform: uppercase;">Annexure: Salary Structure</h3>
  
  <table class="sal-table">
      <colgroup>
          <col style="width:30px">
          <col style="width:25%">
          <col style="width:18%">
          <col style="width:30px">
          <col style="width:25%">
          <col style="width:16%">
          <col style="width:16%">
      </colgroup>
      
      <tr>
          <th rowspan="7" class="vertical">E<br>A<br>R<br>N<br>I<br>N<br>G<br>S</th>
          <th style="text-align: left;">Income Head</th>
          <th class="right">Amount</th>
          <th rowspan="7" class="vertical">D<br>E<br>D<br>U<br>C<br>T<br>I<br>O<br>N<br>S</th>
          <th style="text-align: left;">Deduction Head</th>
          <th class="center">Employee</th>
          <th class="center">Employer</th>
      </tr>
      
      <tr>
          <td>Basic Salary</td>
          <td class="right amount">₹ <?= number_format($basic, 2) ?></td>
          <td>Provident Fund (PF)</td>
          <td class="right amount">₹ <?= number_format($pfEmployee, 2) ?></td>
          <td class="right amount">₹ <?= number_format($pfEmployer, 2) ?></td>
      </tr>
      
      <tr>
          <td>House Rent Allowance</td>
          <td class="right amount">₹ <?= number_format($hra, 2) ?></td>
          <td>E.S.I.C.</td>
          <td class="right amount">₹ <?= number_format($esicEmployee, 2) ?></td>
          <td class="right amount">₹ <?= number_format($esicEmployer, 2) ?></td>
      </tr>
      
      <tr>
          <td>Other Allowance</td>
          <td class="right amount">₹ <?= number_format($allowance, 2) ?></td>
          <td>Other Deduction</td>
          <td class="right amount">₹ 0.00</td>
          <td class="center">—</td>
      </tr>
      
      <tr>
          <td>&nbsp;</td>
          <td>&nbsp;</td>
          <td>Professional Tax</td>
          <td class="right amount">₹ 0.00</td>
          <td class="center">—</td>
      </tr>
      
      <tr class="total-row">
          <td>Gross Salary</td>
          <td class="right amount">₹ <?= number_format($grossSalary, 2) ?></td>
          <td>Total Deduction</td>
          <td class="right amount">₹ <?= number_format($totalDeduction, 2) ?></td>
          <td class="right amount">₹ <?= number_format($totalEmployerContribution, 2) ?></td>
      </tr>
      
      <tr class="total-row">
          <td>Monthly CTC</td>
          <td class="right amount">₹ <?= number_format($monthlyCTC, 2) ?></td>
          <td>Net Salary</td>
          <td colspan="2" class="right">
              <span class="amount">₹ <?= number_format($netSalary, 2) ?></span>
              <span class="words"><?= numberToWords($netSalary) ?></span>
          </td>
      </tr>
      
      <tr class="net-row">
          <td colspan="2"><strong>YEARLY CTC</strong></td>
          <td colspan="5">
              <span class="amount">₹ <?= number_format($yearlyCTC, 2) ?></span>
              <span class="words"><?= numberToWords($yearlyCTC) ?></span>
          </td>
      </tr>
  </table>

  <!-- SIGNATURE BLOCK -->
  <div style="margin-top: 30px;">
      <p>Thanking you,</p>
      <p>For <b><?= htmlspecialchars($cName) ?></b></p>
  </div>
  
  <div class="sign">
    <div class="col" style="text-align: left;">
        <div class="line" style="width: 200px;">
            <?= htmlspecialchars($L['prepared_by'] ?: 'HR Department') ?><br>
            <span style="font-weight:normal; font-size:10px;">HRD HEAD SIGNATURE</span>
        </div>
    </div>
  </div>

  <?php if ($type === 'Offer' || $type === 'Appointment'): ?>
  <div style="margin-top: 40px; border-top: 1px dashed #cbd5e1; padding-top: 20px; page-break-inside: avoid;">
      <p>With the signature below, I accept this offer for employment.</p>
      <div style="display: flex; justify-content: space-between; margin-top: 40px;">
          <div style="border-top: 1px solid #1e293b; width: 250px; padding-top: 4px; font-weight: bold;">Name: </div>
          <div style="border-top: 1px solid #1e293b; width: 200px; padding-top: 4px; font-weight: bold;">Date: </div>
      </div>
  </div>
  <?php endif; ?>

</div>

<!-- Auto-Print Script -->
<script>
    window.onload = function() {
        setTimeout(function() {
            window.print();
        }, 500);
    }
</script>

</body>
</html>