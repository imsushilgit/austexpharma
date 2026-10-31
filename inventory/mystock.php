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
$canView  = true; // all logged in users can see own stock

$msg = ''; $msgType = 'success';

// Handle Distribution entry by MR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'distribute') {
    $product_name = trim($_POST['product_name'] ?? '');
    $product_type = $_POST['product_type'] ?? 'Sample';
    $batch_no     = trim($_POST['batch_no'] ?? '');
    $quantity     = (float)($_POST['quantity'] ?? 0);
    $party_type   = $_POST['party_type'] ?? 'Doctor';
    $party_name   = trim($_POST['party_name'] ?? '');
    $txn_date     = $_POST['txn_date'] ?: date('Y-m-d');
    $remarks      = trim($_POST['remarks'] ?? '');

    if ($product_name && $quantity > 0 && $party_name) {
        try {
            $pdo->beginTransaction();

            // Insert transaction
            $stmt = $pdo->prepare("INSERT INTO mr_sample_transactions 
                (empcode, empname, product_name, product_type, batch_no, txn_type, quantity, party_type, party_name, txn_date, remarks, created_by)
                VALUES (?,?,?,?,?,'Distribution',?,?,?,?,?,?)");
            $stmt->execute([$empCode, $empName, $product_name, $product_type, $batch_no, $quantity, $party_type, $party_name, $txn_date, $remarks, $empCode]);

            // Reduce stock
            $pdo->prepare("UPDATE mr_sample_stock SET quantity = quantity - ? 
                WHERE empcode = ? AND product_name = ? AND (batch_no = ? OR (batch_no IS NULL AND ? = '')) AND quantity >= ?")
                ->execute([$quantity, $empCode, $product_name, $batch_no, $batch_no, $quantity]);

            $pdo->commit();
            $msg = "Distribution recorded successfully.";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "Error: " . $e->getMessage(); $msgType = 'danger';
        }
    } else {
        $msg = "Please fill required fields."; $msgType = 'danger';
    }
}

// Current Stock
$stmt = $pdo->prepare("SELECT * FROM mr_sample_stock WHERE empcode = ? AND quantity > 0 ORDER BY product_name");
$stmt->execute([$empCode]);
$stock = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recent Transactions
$stmt = $pdo->prepare("SELECT * FROM mr_sample_transactions WHERE empcode = ? ORDER BY txn_date DESC, id DESC LIMIT 50");
$stmt->execute([$empCode]);
$txns = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "My Sample / Gift Stock";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-boxes"></i> My Sample / Gift Stock</h1>
    <p class="text-muted">View allocated stock and record distributions</p>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show"><?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-7 mb-4">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white d-flex justify-content-between">
                <strong>Current Balance</strong>
                <button class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#distModal">
                    <i class="fas fa-share"></i> Record Distribution
                </button>
            </div>
            <div class="card-body table-responsive">
                <table class="table table-sm table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Product</th>
                            <th>Type</th>
                            <th>Batch</th>
                            <th class="text-end">Qty</th>
                            <th>Expiry</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stock)): ?>
                            <tr><td colspan="5" class="text-center text-muted">No stock allocated.</td></tr>
                        <?php else: foreach ($stock as $s): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['product_name']) ?></td>
                                <td><span class="badge bg-secondary"><?= htmlspecialchars($s['product_type']) ?></span></td>
                                <td><?= htmlspecialchars($s['batch_no'] ?: '-') ?></td>
                                <td class="text-end fw-bold"><?= number_format($s['quantity'], 2) ?></td>
                                <td><?= $s['expiry_date'] ? date('d-M-Y', strtotime($s['expiry_date'])) : '-' ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5 mb-4">
        <div class="card shadow-sm">
            <div class="card-header"><strong>Recent Transactions</strong></div>
            <div class="card-body table-responsive" style="max-height:420px;overflow-y:auto;">
                <table class="table table-sm table-hover">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Product</th>
                            <th class="text-end">Qty</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($txns as $t): ?>
                            <tr>
                                <td><?= date('d-M', strtotime($t['txn_date'])) ?></td>
                                <td>
                                    <span class="badge bg-<?= $t['txn_type']==='Receipt'?'success':($t['txn_type']==='Distribution'?'info':'secondary') ?>">
                                        <?= htmlspecialchars($t['txn_type']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?= htmlspecialchars($t['product_name']) ?>
                                    <?php if ($t['party_name']): ?>
                                        <br><small class="text-muted">→ <?= htmlspecialchars($t['party_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><?= number_format($t['quantity'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Distribution Modal -->
<div class="modal fade" id="distModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="distribute">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title">Record Distribution</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Product *</label>
                    <select name="product_name" class="form-select" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($stock as $s): ?>
                            <option value="<?= htmlspecialchars($s['product_name']) ?>">
                                <?= htmlspecialchars($s['product_name']) ?> (Bal: <?= $s['quantity'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label">Type</label>
                        <select name="product_type" class="form-select">
                            <option value="Sample">Sample</option>
                            <option value="Gift">Gift</option>
                            <option value="Promotional">Promotional</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Quantity *</label>
                        <input type="number" step="0.01" name="quantity" class="form-control" required min="0.01">
                    </div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-6">
                        <label class="form-label">Party Type</label>
                        <select name="party_type" class="form-select">
                            <option value="Doctor">Doctor</option>
                            <option value="Chemist">Chemist</option>
                            <option value="Stockist">Stockist</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Date</label>
                        <input type="date" name="txn_date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <label class="form-label">Party Name *</label>
                    <input type="text" name="party_name" class="form-control" required>
                </div>
                <div class="mt-3">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-info text-white">Save Distribution</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
