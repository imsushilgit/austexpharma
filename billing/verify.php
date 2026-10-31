<?php
require_once __DIR__ . '/../includes/core.php';

$invoiceNo = trim($_GET['invoice'] ?? '');

if (empty($invoiceNo)) {
    die("<h3 style='font-family:sans-serif; color:red; text-align:center; margin-top:50px;'>Invalid or Missing Invoice Reference.</h3>");
}

// Fetch all items for this invoice
$stmt = $pdo->prepare("SELECT * FROM invoices WHERE invoice_no = ?");
$stmt->execute([$invoiceNo]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch dispatch metadata if available
$dispStmt = $pdo->prepare("SELECT * FROM dispatch_records WHERE invoice_no = ? LIMIT 1");
$dispStmt->execute([$invoiceNo]);
$disp = $dispStmt->fetch(PDO::FETCH_ASSOC);

$totalQty = 0;
$totalVal = 0.00;
foreach($items as $i) {
    $totalQty += intval($i['total_qty']);
    $totalVal += floatval($i['total_value']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verification - Invoice <?= htmlspecialchars($invoiceNo); ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="pg-billing-verify">
    <div class="verify-card">
        <h2>Company Name Pharma - e-Invoice Verification</h2>
        
        <?php if (empty($items)): ?>
            <p style="color: #dc2626; font-weight: bold;">No records found for Invoice No: <?= htmlspecialchars($invoiceNo); ?></p>
        <?php else: ?>
            <div class="meta-grid">
                <div><strong>Invoice No:</strong> <?= htmlspecialchars($invoiceNo); ?></div>
                <div><strong>Date:</strong> <?= htmlspecialchars($items[0]['invoice_date']); ?></div>
                <div><strong>Party / Consignee:</strong> <?= htmlspecialchars($items[0]['partyname']); ?></div>
                <div><strong>Dispatch Status:</strong> <span class="badge"><?= htmlspecialchars($disp['status'] ?? 'Pending Dispatch'); ?></span></div>
                <?php if ($disp): ?>
                    <div><strong>Dispatch No:</strong> <?= htmlspecialchars($disp['dispatch_no']); ?></div>
                    <div><strong>Transporter:</strong> <?= htmlspecialchars($disp['transporter']); ?></div>
                <?php endif; ?>
            </div>

            <h3 style="font-size: 14px; color: navy; margin: 15px 0 5px 0;">Itemized Breakdown</h3>
            <table>
                <thead>
                    <tr>
                        <th>Product Description</th>
                        <th>Batch</th>
                        <th class="text-right">Qty</th>
                        <th class="text-right">MRP (₹)</th>
                        <th class="text-right">Total (₹)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($items as $it): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($it['product_name']); ?></strong></td>
                        <td style="font-family:monospace;"><?= htmlspecialchars($it['batch_no']); ?></td>
                        <td class="text-right"><?= $it['total_qty']; ?></td>
                        <td class="text-right"><?= number_format($it['mrp'], 2); ?></td>
                        <td class="text-right"><strong><?= number_format($it['total_value'], 2); ?></strong></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div style="display: flex; justify-content: space-between; margin-top: 15px; padding-top: 10px; border-top: 1px solid #cbd5e1; font-weight: bold; font-size: 14px;">
                <span>Total Quantity: <?= $totalQty; ?> Units</span>
                <span style="color: #065f46;">Grand Total: ₹ <?= number_format($totalVal, 2); ?></span>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>