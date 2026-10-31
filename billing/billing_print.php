<?php if (!isset($inv)) die('Invalid'); ?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8">
<title>Tax Invoice <?= htmlspecialchars($inv['invoice_no']) ?></title>
<link rel="stylesheet" href="../assets/css/style.css"></head><body class="pg-billing-billing-print">
<button class="print-btn" onclick="window.print()">Print / PDF</button>
<div class="hdr">
  <div>
    <h1>Austex Pharmaceutical Pvt. Ltd.</h1>
    <div class="sub">Tax Invoice (Computer Generated)</div>
  </div>
  <div style="text-align:right">
    <div><b><?= htmlspecialchars($inv['invoice_type']) ?></b></div>
    <div>No: <b><?= htmlspecialchars($inv['invoice_no']) ?></b></div>
    <div>Date: <?= date('d/m/Y', strtotime($inv['invoice_date'])) ?></div>
    <?php if ($inv['order_no']): ?><div>Order: <?= htmlspecialchars($inv['order_no']) ?></div><?php endif; ?>
  </div>
</div>
<div class="meta">
  <div class="box">
    <b>Bill To</b><br>
    <?= htmlspecialchars($inv['party_name']) ?><br>
    <?= nl2br(htmlspecialchars($inv['party_address'] ?? '')) ?><br>
    <?php if ($inv['party_gstin']): ?>GSTIN: <?= htmlspecialchars($inv['party_gstin']) ?><br><?php endif; ?>
    State / POS: <?= htmlspecialchars($inv['party_state'] ?? $inv['place_of_supply'] ?? '') ?>
  </div>
  <div class="box">
    <b>Dispatch</b><br>
    Transporter: <?= htmlspecialchars($inv['transporter_name'] ?? '—') ?><br>
    LR: <?= htmlspecialchars($inv['lr_no'] ?? '—') ?> &nbsp; Vehicle: <?= htmlspecialchars($inv['vehicle_no'] ?? '—') ?><br>
    Payment: <?= htmlspecialchars($inv['payment_mode'] ?? 'Credit') ?> (Due <?= (int)$inv['due_days'] ?> days)<br>
    MR: <?= htmlspecialchars($inv['empname'] ?? '') ?> (<?= htmlspecialchars($inv['empcode'] ?? '') ?>)
  </div>
</div>
<table>
<thead>
<tr>
<th>#</th><th>Product</th><th>HSN</th><th>Batch</th><th>Exp</th><th>MRP</th>
<th>Qty</th><th>Free</th><th>Rate</th><th>Disc%</th><th>GST%</th><th class="num">Taxable</th>
</tr>
</thead>
<tbody>
<?php $i=1; foreach ($items as $it): ?>
<tr>
  <td><?= $i++ ?></td>
  <td><?= htmlspecialchars($it['product_name']) ?></td>
  <td><?= htmlspecialchars($it['hsn'] ?? '') ?></td>
  <td><?= htmlspecialchars($it['batch_no'] ?? '') ?></td>
  <td><?= $it['expiry'] ? date('m/Y', strtotime($it['expiry'])) : '' ?></td>
  <td class="num"><?= number_format($it['mrp'],2) ?></td>
  <td class="num"><?= number_format($it['qty'],2) ?></td>
  <td class="num"><?= number_format($it['free_qty'],2) ?></td>
  <td class="num"><?= number_format($it['rate'],2) ?></td>
  <td class="num"><?= number_format($it['disc_pct'],2) ?></td>
  <td class="num"><?= number_format($it['gst_pct'],2) ?></td>
  <td class="num"><?= number_format($it['taxable'],2) ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
<table class="totals">
<tr><td>Taxable Value</td><td class="num"><?= number_format($inv['taxable_value'],2) ?></td></tr>
<tr><td>CGST</td><td class="num"><?= number_format($inv['cgst_amount'],2) ?></td></tr>
<tr><td>SGST</td><td class="num"><?= number_format($inv['sgst_amount'],2) ?></td></tr>
<tr><td>IGST</td><td class="num"><?= number_format($inv['igst_amount'],2) ?></td></tr>
<tr><td>Round Off</td><td class="num"><?= number_format($inv['round_off'],2) ?></td></tr>
<tr class="grand"><td>Grand Total</td><td class="num">₹ <?= number_format($inv['grand_total'],2) ?></td></tr>
</table>
<?php if ($inv['remarks']): ?><p><b>Remarks:</b> <?= htmlspecialchars($inv['remarks']) ?></p><?php endif; ?>
<div class="sign">
  <div>Receiver Signature</div>
  <div>For Austex Pharmaceutical Pvt. Ltd.<br><small><?= htmlspecialchars($inv['created_by'] ?? '') ?></small></div>
</div>
<p style="margin-top:16px;font-size:9px;color:#666;text-align:center">This is a computer-generated invoice.</p>
</body></html>
