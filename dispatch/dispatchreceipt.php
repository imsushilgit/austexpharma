<?php
session_start();
require_once __DIR__ . '/../includes/core.php';

// Secure: Reading from POST to prevent URL tampering
$dispatchId = (int)($_POST['id'] ?? 0);

if (!$dispatchId) {
    die("<div style='padding:20px; font-family:sans-serif; color:red;'><strong>Access Denied:</strong> Valid Dispatch Reference required via secure POST.</div>");
}

// 1. Fetch Dispatch details
$stmt = $pdo->prepare("SELECT * FROM dispatch_records WHERE id = ?");
$stmt->execute([$dispatchId]);
$disp = $stmt->fetch();

if (!$disp) {
    die("<div style='padding:20px; font-family:sans-serif; color:red;'>Dispatch record not found.</div>");
}

// ROLE SCOPE: only admin, dispatch staff, or the owner's reporting line may open this receipt
if (!is_ops_user($ctx, ['DISPATCH','LOGISTICS','WAREHOUSE','BILLING','ACCOUNTS']) && !can_access_emp($ctx, $disp['empcode'] ?? '')) {
    die("<div style='padding:20px; font-family:sans-serif; color:red;'><strong>Access Denied:</strong> This dispatch does not belong to your team.</div>");
}

// Ensure safe defaults for status
$status = ucfirst(trim($disp['status'] ?: 'Pending'));

// 2. Fetch Employee (MR) details
$mrStmt = $pdo->prepare("SELECT mobile, email, reporting_manager FROM employees WHERE empcode = ? LIMIT 1");
$mrStmt->execute([$disp['empcode']]);
$mrData = $mrStmt->fetch(PDO::FETCH_ASSOC) ?: [];

$mrMobile = $mrData['mobile'] ?? '-';
$mrEmail = $mrData['email'] ?? '-';
$mrManager = $mrData['reporting_manager'] ?? '-';

// 3. Fetch Party / Consignee details
$partyStmt = $pdo->prepare("SELECT contact, division FROM parties WHERE partyname = ? LIMIT 1");
$partyStmt->execute([$disp['partyname']]);
$partyData = $partyStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$partyContact = $partyData['contact'] ?? '-';
$partyDivision = $partyData['division'] ?? 'Pharma Division';

// 4. Fetch Itemized Invoice Records linked to this dispatch
$invStmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_no = ?");
$invStmt->execute([$disp['invoice_no']]);
$invoiceItems = $invStmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals from invoice items
$totalBilledQty = 0;
$totalFreeQty = 0;
$grandTotalValue = 0.00;
$invoiceDate = !empty($invoiceItems) ? $invoiceItems[0]['invoice_date'] : $disp['dispatch_date'];

foreach ($invoiceItems as $item) {
    $totalBilledQty += (int)$item['qty'];
    $totalFreeQty += (int)$item['free_qty'];
    $grandTotalValue += (float)$item['total_value'];
}

// Generate Acknowledgement Details
$createdAtTime = (!empty($disp['created_at']) && $disp['created_at'] !== '0000-00-00 00:00:00') ? strtotime($disp['created_at']) : time();
$ackNo = "ACK-" . date('Ymd', $createdAtTime) . "-" . str_pad($disp['id'], 4, '0', STR_PAD_LEFT);
$ackDate = date('d-M-Y h:i A', $createdAtTime);

// Safe Dispatch Date string without 00:00:00
$safeDispatchDate = !empty($disp['dispatch_date']) ? substr($disp['dispatch_date'], 0, 10) : date('Y-m-d');

// Get Logged-in User Name for Authorized Signatory
$loggedUserName = $_SESSION['username'] ?? $_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Administrator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Dispatch Acknowledgement - <?= htmlspecialchars($disp['dispatch_no']); ?></title>
  <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="pg-dispatch-dispatchreceipt">
  
  <div class="no-print" style="width:100%; max-width:297mm; display:flex; justify-content:flex-end; margin-bottom:10px;">
    <button onclick="window.print()" style="padding:10px 20px; font-weight:bold; font-size:13px; cursor:pointer; background: navy; color: #fff; border: none; border-radius: 4px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
      🖨️ Print Acknowledgement
    </button>
  </div>

  <div class="invoice-wrapper">
    <div class="document-border">
      
      <!-- TOP STRIP WITH LIVE LOCAL IP QR CODE -->
      <div class="border-b" style="display: flex; justify-content: space-between; align-items: center; padding: 4px 10px; background:navy; color: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
          <span class="font-bold" style="font-size: 11px; color: #fff;">OFFICIAL E-INVOICE ACKNOWLEDGEMENT</span>
          
          <!-- Live QR Code pointing to local network IP -->
          <div class="qr-container">
              <img src="https://api.qrserver.com/v1/create-qr-code/?size=50x50&data=http://192.168.29.46/dispatch/verify.php?invoice=<?= urlencode($disp['invoice_no']); ?>" alt="e-Invoice QR">
              <div class="qr-label">VERIFY</div>
          </div>

          <span class="font-bold" style="font-size: 11px; color: #fff;">Division: <?= htmlspecialchars($partyDivision); ?></span>
      </div>

      <!-- HEADER: 3-COLUMN LAYOUT -->
      <div class="flex-row border-b">
        
        <!-- COLUMN 1: Company Info -->
        <div class="w-34 border-r p-5 text-left">
          <div class="entity-title">Company Name</div>
          <div class="company-tagline">Providing Quality Healthcare Solutions</div>
          <div style="line-height: 1.4; color: #1e293b; font-size: 11px; margin-bottom: 6px;">
            Flat No-133/1646 Transport Nagar, SBI Bank<br>
            Patna, 800026, Bihar
          </div>
          <table style="width:100%; border:none; text-align:left;">
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:1px 0; width: 65px;"><span class="label">GSTIN:</span></td>
                  <td style="border:none; text-align:left; padding:1px 0;"><span class="value" style="color: navy;">10AAOCA8311K1ZS</span></td>
              </tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:1px 0;"><span class="label">PHONE:</span></td>
                  <td style="border:none; text-align:left; padding:1px 0;"><span class="value">6203127989</span></td>
              </tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:1px 0;"><span class="label">D.L. NO:</span></td>
                  <td style="border:none; text-align:left; padding:1px 0;"><span class="value">BR-PAT-104264</span></td>
              </tr>
          </table>
        </div>

        <!-- COLUMN 2: Dispatch & Acknowledgment Details (MID) -->
        <div class="w-33 border-r p-5 text-left" style="background: #f8fafc; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
          <table style="width:100%; border:none; text-align:left;">
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:2px 0; width: 85px;"><span class="label">Dispatch No:</span></td>
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="value" style="font-size:13px; color:#2aa6e4;"><?= htmlspecialchars($disp['dispatch_no']); ?></span></td>
              </tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="label">Invoice No:</span></td>
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="value" style="color:navy;"><?= htmlspecialchars($disp['invoice_no']); ?></span></td>
              </tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="label">Dispatch Dt:</span></td>
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="value"><?= date('d-M-Y', strtotime($safeDispatchDate)); ?></span></td>
              </tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="label">Ack No:</span></td>
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="value" style="font-family:monospace; color:#b45309;"><?= $ackNo; ?></span></td>
              </tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="label">Ack Date:</span></td>
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="value" style="font-size:10px;"><?= $ackDate; ?></span></td>
              </tr>
              <tr style="border:none;"><td colspan="2" style="border:none; border-bottom: 1px dashed #cbd5e1; padding-top: 2px;"></td></tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:3px 0;"><span class="label">M.R. Name:</span></td>
                  <td style="border:none; text-align:left; padding:3px 0;"><span class="value" style="color:navy;"><?= htmlspecialchars($disp['empname']); ?></span></td>
              </tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="label">M.R. HQ:</span></td>
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="value"><?= htmlspecialchars($disp['hq']); ?></span></td>
              </tr>
          </table>
        </div>

        <!-- COLUMN 3: Party Details (RIGHT) -->
        <div class="w-33 p-5 text-left">
          <div class="label" style="margin-bottom: 4px; font-size: 11px; color:navy;">Consignee / Party Details:</div>
          <div class="entity-title"><?= htmlspecialchars($disp['partyname']); ?></div>
          <table style="width:100%; border:none; text-align:left; margin-top: 6px;">
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:2px 0; width: 65px;"><span class="label">Area/City:</span></td>
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="value"><?= htmlspecialchars($disp['area']); ?></span></td>
              </tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="label">State:</span></td>
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="value"><?= htmlspecialchars($disp['state']); ?></span></td>
              </tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="label">Phone:</span></td>
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="value"><?= htmlspecialchars($partyContact); ?></span></td>
              </tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="label">Transporter:</span></td>
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="value" style="color:#2aa6e4;"><?= htmlspecialchars($disp['transporter']); ?></span></td>
              </tr>
              <tr style="border:none;">
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="label">Cases:</span></td>
                  <td style="border:none; text-align:left; padding:2px 0;"><span class="value" style="font-size:12px; color:navy;"><?= htmlspecialchars($disp['cases']); ?> Box / Cases</span></td>
              </tr>
          </table>
        </div>
      </div>

      <!-- ITEM GRID (LANDSCAPE OPTIMIZED) -->
      <table style="border: none; border-bottom: 1px solid navy;">
        <thead>
          <tr>
            <th style="border-left:none; width:4%;">Sl.</th>
            <th style="width:32%; text-align:left;">Product Description</th>
            <th style="width:14%; text-align:left;">Batch No.</th>
            <th style="width:8%; text-align:center;">Billed Qty</th>
            <th style="width:8%; text-align:center;">Free Qty</th>
            <th style="width:9%; text-align:center;">Total Qty</th>
            <th style="width:11%; text-align:right;">MRP (₹)</th>
            <th style="border-right:none; width:14%; text-align:right;">Total Value (₹)</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($invoiceItems)): ?>
          <tr>
            <td colspan="8" style="text-align:center; padding:15px; color:#4b5563;">No linked invoice line items found in database for Invoice No: <?= htmlspecialchars($disp['invoice_no']); ?></td>
          </tr>
          <?php else: ?>
              <?php foreach ($invoiceItems as $idx => $it): ?>
              <tr>
                <td style="border-left:none;"><?= $idx + 1; ?></td>
                <td class="text-left font-bold" style="color: navy;"><?= htmlspecialchars($it['product_name']); ?></td>
                <td class="text-left font-bold" style="font-family:monospace;"><?= htmlspecialchars($it['batch_no']); ?></td>
                <td class="font-bold" style="color: navy;"><?= $it['qty']; ?></td>
                <td><?= $it['free_qty']; ?></td>
                <td class="font-bold"><?= $it['total_qty']; ?></td>
                <td class="text-right tabular-nums"><?= number_format($it['mrp'], 2); ?></td>
                <td style="border-right:none;" class="text-right tabular-nums font-bold"><?= number_format($it['total_value'], 2); ?></td>
              </tr>
              <?php endforeach; ?>
          <?php endif; ?>
          
          <!-- Spacer rows if items are few -->
          <?php for($i = count($invoiceItems); $i < 5; $i++): ?>
          <tr>
              <td style="border-left:none; color:transparent;">-</td><td></td><td></td><td></td><td></td><td></td><td></td><td style="border-right:none;"></td>
          </tr>
          <?php endfor; ?>
          
          <!-- Grid Totals Row -->
          <tr style="background: #f8fafc; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
              <td colspan="3" class="text-right" style="border-left:none; color: navy;">Summary Totals:</td>
              <td style="color: navy; font-size:12px;"><?= $totalBilledQty; ?></td>
              <td style="color: navy; font-size:12px;"><?= $totalFreeQty; ?></td>
              <td style="color: navy; font-size:12px;"><?= $totalBilledQty + $totalFreeQty; ?></td>
              <td></td>
              <td style="border-right:none; text-align:right; font-size:13px; color:#065f46;" class="tabular-nums">₹ <?= number_format($grandTotalValue, 2); ?></td>
          </tr>
        </tbody>
      </table>

      <!-- WAREHOUSE OPERATIONS AUDIT TRAIL -->
      <table class="audit-table" style="border-top:none; border-bottom:1px solid navy;">
        <tr>
          <th>Picked By (Warehouse Team)</th>
          <th>Matched By (Verification)</th>
          <th>Packed By (Packaging)</th>
          <th>Dispatched By (Logistics Lead)</th>
        </tr>
        <tr>
          <td style="height: 28px;"><?= htmlspecialchars($disp['picked_by'] ?: '-'); ?></td>
          <td><?= htmlspecialchars($disp['matched_by'] ?: '-'); ?></td>
          <td><?= htmlspecialchars($disp['packed_by'] ?: '-'); ?></td>
          <td><?= htmlspecialchars($disp['dispatched_by'] ?: '-'); ?></td>
        </tr>
      </table>

      <!-- FOOTER AREA: STAMP & SIGNATURE -->
      <div class="flex-row">
        <!-- Left: Dynamic Database Stamp -->
        <div style="width: 50%; padding: 10px; display:flex; flex-direction:column; justify-content:center; align-items:center;" class="border-r">
            
            <div class="stamp-box stamp-<?= $status ?>">
                <div class="stamp-title"><?= $status ?></div>
                <div class="stamp-sub">CASES / BOXES: <?= htmlspecialchars($disp['cases']); ?></div>
                <div class="stamp-sub">DATE: <?= date('d/m/Y', strtotime($safeDispatchDate)); ?></div>
            </div>

            <div style="font-size: 9px; color: #4b5563; margin-top: 3px; text-align: center;">
                Transporter Handover: <strong><?= htmlspecialchars($disp['transporter']); ?></strong>
                <?php if(!empty($disp['hold_reason'])): ?>
                    <br><span style="color:#b91c1c; font-weight:bold;">Status Remark: <?= htmlspecialchars($disp['hold_reason']); ?></span>
                <?php endif; ?>
            </div>
        </div>

        <!-- Right: Signature -->
        <div style="width: 50%; display: flex; flex-direction: column; justify-content: flex-end; text-align: center; padding: 10px;">
            <div class="font-bold" style="font-size: 12px; margin-bottom: 20px; color: navy;">For Company Name</div>
            <div class="label" style="border-top: 1px dashed navy; display: inline-block; padding-top: 4px; width: 60%; margin: 0 auto; font-size: 11px; text-transform: uppercase; color: navy; font-weight: bold;">
                <?= htmlspecialchars($loggedUserName); ?>
            </div>
            <div style="font-size: 9px; color: #4b5563; margin-top: 2px;">Authorized Signatory</div>
        </div>
      </div>
      
      <div style="text-align:center; padding: 5px; font-size: 10px; color: #fff; background: navy; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
          This is a Computer Generated e-Invoice Dispatch Acknowledgement. Subject to Patna Jurisdiction.
      </div>
    </div>
  </div>
</body>
</html>