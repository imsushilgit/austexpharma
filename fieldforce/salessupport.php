<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/core.php';

// --- User Context ---
$stmtUser = $pdo->prepare("SELECT u.role, u.username, e.empname, e.designation, e.empcode 
    FROM users u LEFT JOIN employees e ON (u.username = e.empcode OR u.username = e.empname) AND e.resignation_date IS NULL 
    WHERE u.id = ? ORDER BY (e.empcode = u.username) DESC LIMIT 1");
$stmtUser->execute([$_SESSION['user_id']]);
$userMeta = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

$isAdmin  = ($userMeta['role'] ?? '') === 'Admin';
$empCode  = $userMeta['empcode'] ?? $userMeta['username'] ?? '';
$empName  = $userMeta['empname'] ?? $userMeta['username'] ?? '';
$canEntry = $isAdmin || (strtoupper(trim($userMeta['designation'] ?? '')) === 'MR');

$msg = ''; $msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEntry) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM sales_support_requests WHERE id=? AND empcode=? AND status='Pending'")
            ->execute([$id, $empCode]);
        $msg = "Request deleted.";
    }
    elseif ($action === 'submit') {
        $support_for  = $_POST['support_for'] ?? 'Chemist';
        $party_name   = trim($_POST['party_name'] ?? '');
        $party_code   = trim($_POST['party_code'] ?? '');
        $product_name = trim($_POST['product_name'] ?? '');
        $support_type = trim($_POST['support_type'] ?? '');
        $quantity     = (float)($_POST['quantity'] ?? 0);
        $amount       = (float)($_POST['amount'] ?? 0);
        $from_date    = $_POST['from_date'] ?: null;
        $to_date      = $_POST['to_date'] ?: null;
        $reason       = trim($_POST['reason'] ?? '');

        if ($party_name === '' || $product_name === '') {
            $msg = "Party Name and Product are required."; $msgType = 'danger';
        } else {
            $stmt = $pdo->prepare("INSERT INTO sales_support_requests 
                (empcode, empname, support_for, party_name, party_code, product_name, support_type, quantity, amount, from_date, to_date, reason, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,'Pending')");
            $stmt->execute([$empCode, $empName, $support_for, $party_name, $party_code, $product_name, $support_type, $quantity, $amount, $from_date, $to_date, $reason]);
            $msg = "Sales Support request submitted successfully!";
        }
    }
}

// ROLE SCOPE: MR = own requests, manager = own + whole team, admin = all
$__p = [];
$stmt = $pdo->prepare("SELECT * FROM sales_support_requests WHERE 1=1" . ($ctx['is_mr'] ? " AND empcode = ?" : scope_in($ctx, 'empcode', $__p)) . " ORDER BY requested_on DESC");
$stmt->execute($ctx['is_mr'] ? [$empCode] : $__p);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Products for dropdown (optional)
$products = $pdo->query("SELECT id, product_name FROM products ORDER BY product_name LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Sales Support Request";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1><i class="fas fa-hands-helping"></i> Sales Support Request</h1>
        <p class="text-muted mb-0">Request product support for Stockist / Chemist / Doctor</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#supportModal">
        <i class="fas fa-plus"></i> New Request
    </button>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show"><?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Support For</th>
                    <th>Party</th>
                    <th>Product</th>
                    <th>Type</th>
                    <th>Qty / Amount</th>
                    <th>Period</th>
                    <th>Status</th>
                    <th>Requested</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($requests)): ?>
                    <tr><td colspan="10" class="text-center text-muted">No requests found.</td></tr>
                <?php else: foreach ($requests as $i => $r): ?>
                    <tr>
                        <td><?= $i+1 ?></td>
                        <td><?= htmlspecialchars($r['support_for']) ?></td>
                        <td><?= htmlspecialchars($r['party_name']) ?><?php if (!empty($r['empcode']) && $r['empcode'] !== ($ctx['empcode'] ?? '')): ?><br><small class="text-muted"><?= htmlspecialchars(($r['empname'] ?? '') . ' (' . $r['empcode'] . ')') ?></small><?php endif; ?></td>
                        <td><?= htmlspecialchars($r['product_name']) ?></td>
                        <td><?= htmlspecialchars($r['support_type'] ?? '-') ?></td>
                        <td><?= $r['quantity'] ?> / ₹<?= number_format($r['amount'], 2) ?></td>
                        <td>
                            <?php if ($r['from_date']): ?>
                                <?= date('d-M-y', strtotime($r['from_date'])) ?> to <?= $r['to_date'] ? date('d-M-y', strtotime($r['to_date'])) : '-' ?>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td>
                            <span class="badge bg-<?= $r['status']==='Approved'?'success':($r['status']==='Rejected'?'danger':'warning') ?>">
                                <?= htmlspecialchars($r['status']) ?>
                            </span>
                        </td>
                        <td><?= date('d-M-Y', strtotime($r['requested_on'])) ?></td>
                        <td>
                            <?php if ($r['status'] === 'Pending'): ?>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="supportModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="submit">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">New Sales Support Request</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Support For *</label>
                        <select name="support_for" class="form-select" required>
                            <option value="Stockist">Stockist</option>
                            <option value="Chemist">Chemist</option>
                            <option value="Doctor">Doctor</option>
                        </select>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label">Party Name *</label>
                        <input type="text" name="party_name" class="form-control" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Party Code</label>
                        <input type="text" name="party_code" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Product *</label>
                        <input type="text" name="product_name" class="form-control" list="productList" required>
                        <datalist id="productList">
                            <?php foreach ($products as $p): ?>
                                <option value="<?= htmlspecialchars($p['product_name']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Support Type</label>
                        <input type="text" name="support_type" class="form-control" placeholder="Discount / Scheme / Sample">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" step="0.01" name="quantity" class="form-control" value="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Amount (₹)</label>
                        <input type="number" step="0.01" name="amount" class="form-control" value="0">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">From Date</label>
                        <input type="date" name="from_date" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">To Date</label>
                        <input type="date" name="to_date" class="form-control">
                    </div>
                    <div class="col-md-12">
                        <label class="form-label">Reason / Remarks</label>
                        <textarea name="reason" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Submit Request</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
