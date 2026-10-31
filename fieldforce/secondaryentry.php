<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/core.php';

// --- User Context ---
$stmtUser = $pdo->prepare("SELECT u.role, u.username, e.empname, e.designation, e.department, e.empcode FROM users u LEFT JOIN employees e ON (u.username = e.empcode OR u.username = e.empname) AND e.resignation_date IS NULL WHERE u.id = ? ORDER BY (e.empcode = u.username) DESC LIMIT 1");
$stmtUser->execute([$_SESSION['user_id']]);
$userMeta = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

$isAdmin = ($userMeta['role'] ?? '') === 'Admin';
$empCode = $userMeta['empcode'] ?? $userMeta['username'] ?? '';
$designation = $userMeta['designation'] ?? 'MR';
$department = strtoupper(trim($userMeta['department'] ?? 'SALES'));

$isSales = ($department === 'SALES' || $department === 'MARKETING' || empty($department));
$isManager = ($isSales && !$isAdmin && strtoupper(trim($designation)) !== 'MR');
$isMR = ($isSales && !$isAdmin && strtoupper(trim($designation)) === 'MR');
$canEntry = ($isAdmin || $isManager || $isMR);

$teamCodes = [$empCode];
if ($isManager) {
    // ROLE SCOPE: full reporting line (code or name based), from core.php
    $teamCodes = scope_codes($ctx);
    if (empty($teamCodes)) $teamCodes = [$empCode];
}
$teamPh = implode(',', array_fill(0, count($teamCodes), '?'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEntry) {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)$_POST['id']; $pdo->prepare("DELETE FROM secondary_sales WHERE id=?")->execute([$id]);
        $_SESSION['msg'] = "Order deleted."; header("Location: secondaryentry.php"); exit();
    }
    
    $orderDate = trim($_POST['order_date']); $chemist = trim($_POST['chemist_name']); 
    $stockist = trim($_POST['stockist_name']); $product = trim($_POST['product_name']);
    $qty = (int)$_POST['qty']; $amount = (float)$_POST['amount'];
    $assignedEmp = trim($_POST['assigned_emp'] ?? $empCode); $sId = (int)($_POST['sec_id'] ?? 0);

    if (!$isAdmin && !in_array($assignedEmp, $teamCodes)) { die("Security Violation"); }

    if ($sId > 0) {
        $pdo->prepare("UPDATE secondary_sales SET order_date=?, chemist_name=?, stockist_name=?, product_name=?, qty=?, amount=?, empcode=? WHERE id=?")->execute([$orderDate, $chemist, $stockist, $product, $qty, $amount, $assignedEmp, $sId]);
        $_SESSION['msg'] = "Order updated!";
    } else {
        $pdo->prepare("INSERT INTO secondary_sales (empcode, order_date, chemist_name, stockist_name, product_name, qty, amount) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$assignedEmp, $orderDate, $chemist, $stockist, $product, $qty, $amount]);
        $_SESSION['msg'] = "Secondary POB Order placed!";
    }
    header("Location: secondaryentry.php"); exit();
}

$pageTitle = "Secondary Sales (POB)";
include __DIR__ . '/../includes/header.php';
?>
<div style="padding: 20px;">
    <div style="display: flex; justify-content: space-between; margin-bottom: 20px; align-items:center;">
        <h2 style="font-family:'Sora', sans-serif; color:#1e2a78; margin:0;">📈 Secondary Sales (POB)</h2>
        <?php if($canEntry): ?><button onclick="document.getElementById('secModal').style.display='block'" style="background:#059669; color:#fff; border:none; padding:8px 16px; border-radius:5px; font-weight:bold; cursor:pointer;">+ Add POB Order</button><?php endif; ?>
    </div>
    <?php if(isset($_SESSION['msg'])): ?>
        <div style="background:#d1fae5; color:#065f46; padding:10px; border-radius:5px; margin-bottom:15px; border:1px solid #a7f3d0;"><?= $_SESSION['msg']; unset($_SESSION['msg']); ?></div>
    <?php endif; ?>
    <table style="width:100%; border-collapse:collapse; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
        <thead style="background:#f8fafc; border-bottom:2px solid #e2e8f0; font-size:12px; color:#64748b;">
            <tr><th style="padding:12px; text-align:left;">Date</th><th style="padding:12px; text-align:left;">Employee</th><th style="padding:12px; text-align:left;">Chemist (Buyer)</th><th style="padding:12px; text-align:left;">Stockist (Supplier)</th><th style="padding:12px; text-align:left;">Product</th><th style="padding:12px; text-align:right;">Qty</th><th style="padding:12px; text-align:right;">Value (₹)</th><?php if($canEntry): ?><th style="padding:12px; text-align:right;">Actions</th><?php endif; ?></tr>
        </thead>
        <tbody style="font-size:13px; color:#334155;">
            <?php
            if ($isAdmin) { $secs = $pdo->query("SELECT s.*, e.empname FROM secondary_sales s LEFT JOIN employees e ON s.empcode = e.empcode ORDER BY s.order_date DESC")->fetchAll(PDO::FETCH_ASSOC); } 
            else { $stmt = $pdo->prepare("SELECT s.*, e.empname FROM secondary_sales s LEFT JOIN employees e ON s.empcode = e.empcode WHERE s.empcode IN ($teamPh) ORDER BY s.order_date DESC"); $stmt->execute($teamCodes); $secs = $stmt->fetchAll(PDO::FETCH_ASSOC); }
            foreach($secs as $s): ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:12px; font-weight:bold;"><?= date('d-M-Y', strtotime($s['order_date'])) ?></td>
                <td style="padding:12px; color:#1e2a78;"><?= htmlspecialchars($s['empname'] ?? $s['empcode']) ?></td>
                <td style="padding:12px;"><?= htmlspecialchars($s['chemist_name']) ?></td>
                <td style="padding:12px;"><?= htmlspecialchars($s['stockist_name']) ?></td>
                <td style="padding:12px;"><?= htmlspecialchars($s['product_name']) ?></td>
                <td style="padding:12px; text-align:right;"><?= $s['qty'] ?></td>
                <td style="padding:12px; text-align:right; font-weight:bold; color:#059669;"><?= number_format($s['amount'], 2) ?></td>
                <?php if($canEntry): ?>
                <td style="padding:12px; text-align:right;">
                    <button onclick='editSec(<?= json_encode($s) ?>)' style="background:#eef2ff; color:#4f46e5; border:1px solid #c7d2fe; padding:4px 8px; border-radius:4px; cursor:pointer;">Edit</button>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button type="submit" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; padding:4px 8px; border-radius:4px; cursor:pointer;">Del</button></form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if($canEntry): ?>
<div id="secModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
    <div style="background:#fff; width:450px; padding:25px; border-radius:8px; margin: 50px auto;">
        <h3 id="modalTitle" style="margin-top:0; color:#1e2a78; font-family:'Sora', sans-serif;">Add Secondary Order (POB)</h3>
        <form method="POST">
            <input type="hidden" name="sec_id" id="sec_id" value="0">
            <div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Order Date</label><input type="date" name="order_date" id="order_date" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            <div style="display:flex; gap:10px;">
                <div style="margin-bottom:12px; flex:1;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Chemist / Retailer</label><input type="text" name="chemist_name" id="chemist_name" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
                <div style="margin-bottom:12px; flex:1;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Stockist (Supplier)</label><input type="text" name="stockist_name" id="stockist_name" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            </div>
            <div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Product Ordered</label><input type="text" name="product_name" id="product_name" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            <div style="display:flex; gap:10px;">
                <div style="margin-bottom:12px; flex:1;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Quantity</label><input type="number" name="qty" id="qty" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
                <div style="margin-bottom:12px; flex:1;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Total Value (₹)</label><input type="number" step="0.01" name="amount" id="amount" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            </div>
            <?php if($isAdmin || $isManager): ?><div style="margin-bottom:15px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Log for Team Member (EmpCode)</label><input type="text" name="assigned_emp" id="assigned_emp" value="<?= $empCode ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div><?php else: ?><input type="hidden" name="assigned_emp" value="<?= $empCode ?>"><?php endif; ?>
            <div style="display:flex; justify-content:flex-end; gap:10px;"><button type="button" onclick="document.getElementById('secModal').style.display='none'" style="padding:8px 15px; border:1px solid #cbd5e1; background:#f8fafc; cursor:pointer; border-radius:4px;">Cancel</button><button type="submit" style="padding:8px 15px; background:#1e2a78; color:#fff; border:none; cursor:pointer; border-radius:4px; font-weight:bold;">Save Order</button></div>
        </form>
    </div>
</div>
<script>
function editSec(s) { document.getElementById('modalTitle').innerText='Edit Order'; document.getElementById('sec_id').value=s.id; document.getElementById('order_date').value=s.order_date; document.getElementById('chemist_name').value=s.chemist_name; document.getElementById('stockist_name').value=s.stockist_name; document.getElementById('product_name').value=s.product_name; document.getElementById('qty').value=s.qty; document.getElementById('amount').value=s.amount; if(document.getElementById('assigned_emp')){document.getElementById('assigned_emp').value=s.empcode;} document.getElementById('secModal').style.display='block'; }
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>