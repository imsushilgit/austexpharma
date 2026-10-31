<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/core.php';

// --- User Context & Permissions ---
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

// --- Fetch Team Codes ---
$teamCodes = [$empCode];
if ($isManager) {
    // ROLE SCOPE: full reporting line (code or name based), from core.php
    $teamCodes = scope_codes($ctx);
    if (empty($teamCodes)) $teamCodes = [$empCode];
}
$teamPh = implode(',', array_fill(0, count($teamCodes), '?'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEntry) {
    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $id = (int)$_POST['id']; $pdo->prepare("DELETE FROM stock_statements WHERE id=?")->execute([$id]);
        $_SESSION['msg'] = "Stock line deleted."; header("Location: stockstatement.php"); exit();
    }
    $stateDate = trim($_POST['statement_date']); $party = trim($_POST['party_name']); $prod = trim($_POST['product_name']);
    $qty = (int)$_POST['closing_qty']; $assignedEmp = trim($_POST['assigned_emp'] ?? $empCode); $sId = (int)($_POST['stock_id'] ?? 0);

    if (!$isAdmin && !in_array($assignedEmp, $teamCodes)) { die("Security Violation"); }

    if ($sId > 0) {
        $pdo->prepare("UPDATE stock_statements SET statement_date=?, party_name=?, product_name=?, closing_qty=?, empcode=? WHERE id=?")->execute([$stateDate, $party, $prod, $qty, $assignedEmp, $sId]);
        $_SESSION['msg'] = "Stock updated!";
    } else {
        $pdo->prepare("INSERT INTO stock_statements (empcode, statement_date, party_name, product_name, closing_qty) VALUES (?, ?, ?, ?, ?)")->execute([$assignedEmp, $stateDate, $party, $prod, $qty]);
        $_SESSION['msg'] = "Stock recorded!";
    }
    header("Location: stockstatement.php"); exit();
}

$pageTitle = "Closing Stock Statement";
include __DIR__ . '/../includes/header.php';
?>
<div style="padding: 20px;">
    <div style="display: flex; justify-content: space-between; margin-bottom: 20px; align-items:center;">
        <h2 style="font-family:'Sora', sans-serif; color:#1e2a78; margin:0;">📦 Stock Statements</h2>
        <?php if($canEntry): ?><button onclick="document.getElementById('stockModal').style.display='block'" style="background:#1d4ed8; color:#fff; border:none; padding:8px 16px; border-radius:5px; font-weight:bold; cursor:pointer;">+ Add Stock Line</button><?php endif; ?>
    </div>
    <?php if(isset($_SESSION['msg'])): ?>
        <div style="background:#eff6ff; color:#1e40af; padding:10px; border-radius:5px; margin-bottom:15px; border:1px solid #bfdbfe;"><?= $_SESSION['msg']; unset($_SESSION['msg']); ?></div>
    <?php endif; ?>
    <table style="width:100%; border-collapse:collapse; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.1);">
        <thead style="background:#f8fafc; border-bottom:2px solid #e2e8f0; font-size:12px; color:#64748b;">
            <tr><th style="padding:12px; text-align:left;">Date</th><th style="padding:12px; text-align:left;">Employee</th><th style="padding:12px; text-align:left;">Stockist Party</th><th style="padding:12px; text-align:left;">Product Name</th><th style="padding:12px; text-align:right;">Closing Qty</th><?php if($canEntry): ?><th style="padding:12px; text-align:right;">Actions</th><?php endif; ?></tr>
        </thead>
        <tbody style="font-size:13px; color:#334155;">
            <?php
            if ($isAdmin) { $stocks = $pdo->query("SELECT s.*, e.empname FROM stock_statements s LEFT JOIN employees e ON s.empcode = e.empcode ORDER BY s.statement_date DESC")->fetchAll(PDO::FETCH_ASSOC); } 
            else { $stmt = $pdo->prepare("SELECT s.*, e.empname FROM stock_statements s LEFT JOIN employees e ON s.empcode = e.empcode WHERE s.empcode IN ($teamPh) ORDER BY s.statement_date DESC"); $stmt->execute($teamCodes); $stocks = $stmt->fetchAll(PDO::FETCH_ASSOC); }
            foreach($stocks as $s): ?>
            <tr style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:12px; font-weight:bold;"><?= date('d-M-Y', strtotime($s['statement_date'])) ?></td>
                <td style="padding:12px; color:#1e2a78;"><?= htmlspecialchars($s['empname'] ?? $s['empcode']) ?></td>
                <td style="padding:12px;"><?= htmlspecialchars($s['party_name']) ?></td>
                <td style="padding:12px;"><?= htmlspecialchars($s['product_name']) ?></td>
                <td style="padding:12px; text-align:right; font-family:monospace; font-weight:bold; font-size:14px;"><?= number_format($s['closing_qty']) ?></td>
                <?php if($canEntry): ?>
                <td style="padding:12px; text-align:right;">
                    <button onclick='editStock(<?= json_encode($s) ?>)' style="background:#eef2ff; color:#4f46e5; border:1px solid #c7d2fe; padding:4px 8px; border-radius:4px; cursor:pointer;">Edit</button>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $s['id'] ?>"><button type="submit" style="background:#fef2f2; color:#dc2626; border:1px solid #fecaca; padding:4px 8px; border-radius:4px; cursor:pointer;">Del</button></form>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if($canEntry): ?>
<div id="stockModal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center;">
    <div style="background:#fff; width:400px; padding:25px; border-radius:8px; margin: 50px auto;">
        <h3 id="modalTitle" style="margin-top:0; color:#1e2a78; font-family:'Sora', sans-serif;">Add Stock Entry</h3>
        <form method="POST">
            <input type="hidden" name="stock_id" id="stock_id" value="0">
            <div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Closing Date</label><input type="date" name="statement_date" id="statement_date" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            <div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Stockist / Party Name</label><input type="text" name="party_name" id="party_name" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            <div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Product Name</label><input type="text" name="product_name" id="product_name" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            <div style="margin-bottom:12px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Closing Quantity (Boxes/Units)</label><input type="number" name="closing_qty" id="closing_qty" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div>
            <?php if($isAdmin || $isManager): ?><div style="margin-bottom:15px;"><label style="font-size:11px; font-weight:bold; color:#64748b;">Log for Team Member (EmpCode)</label><input type="text" name="assigned_emp" id="assigned_emp" value="<?= $empCode ?>" required style="width:100%; padding:8px; border:1px solid #cbd5e1; border-radius:4px;"></div><?php else: ?><input type="hidden" name="assigned_emp" value="<?= $empCode ?>"><?php endif; ?>
            <div style="display:flex; justify-content:flex-end; gap:10px;"><button type="button" onclick="document.getElementById('stockModal').style.display='none'" style="padding:8px 15px; border:1px solid #cbd5e1; background:#f8fafc; cursor:pointer; border-radius:4px;">Cancel</button><button type="submit" style="padding:8px 15px; background:#1e2a78; color:#fff; border:none; cursor:pointer; border-radius:4px; font-weight:bold;">Save Stock</button></div>
        </form>
    </div>
</div>
<script>
function editStock(s) { document.getElementById('modalTitle').innerText='Edit Stock'; document.getElementById('stock_id').value=s.id; document.getElementById('statement_date').value=s.statement_date; document.getElementById('party_name').value=s.party_name; document.getElementById('product_name').value=s.product_name; document.getElementById('closing_qty').value=s.closing_qty; if(document.getElementById('assigned_emp')){document.getElementById('assigned_emp').value=s.empcode;} document.getElementById('stockModal').style.display='block'; }
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>