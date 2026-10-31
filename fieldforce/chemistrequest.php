<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/core.php';

// --- User Context ---
$stmtUser = $pdo->prepare("SELECT u.role, u.username, e.empname, e.designation, e.department, e.empcode 
    FROM users u LEFT JOIN employees e ON (u.username = e.empcode OR u.username = e.empname) AND e.resignation_date IS NULL 
    WHERE u.id = ? ORDER BY (e.empcode = u.username) DESC LIMIT 1");
$stmtUser->execute([$_SESSION['user_id']]);
$userMeta = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

$isAdmin   = ($userMeta['role'] ?? '') === 'Admin';
$empCode   = $userMeta['empcode'] ?? $userMeta['username'] ?? '';
$empName   = $userMeta['empname'] ?? $userMeta['username'] ?? '';
$designation = $userMeta['designation'] ?? 'MR';
$isMR      = (strtoupper(trim($designation)) === 'MR');
$canEntry  = $isAdmin || $isMR;

$msg = ''; $msgType = 'success';

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEntry) {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $pdo->prepare("DELETE FROM chemist_requests WHERE id = ? AND empcode = ? AND status = 'Pending'")
            ->execute([$id, $empCode]);
        $msg = "Request deleted successfully.";
    }
    elseif ($action === 'submit') {
        $request_type  = $_POST['request_type'] ?? 'Add';
        $chemist_name  = trim($_POST['chemist_name'] ?? '');
        $chemist_code  = trim($_POST['chemist_code'] ?? '');
        $address       = trim($_POST['address'] ?? '');
        $city          = trim($_POST['city'] ?? '');
        $district      = trim($_POST['district'] ?? '');
        $state         = trim($_POST['state'] ?? '');
        $pincode       = trim($_POST['pincode'] ?? '');
        $mobile        = trim($_POST['mobile'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $contact_person= trim($_POST['contact_person'] ?? '');
        $dl_no         = trim($_POST['dl_no'] ?? '');
        $gstin         = trim($_POST['gstin'] ?? '');
        $remarks       = trim($_POST['remarks'] ?? '');

        if ($chemist_name === '') {
            $msg = "Chemist name is required."; $msgType = 'danger';
        } else {
            $stmt = $pdo->prepare("INSERT INTO chemist_requests 
                (empcode, empname, request_type, chemist_name, chemist_code, address, city, district, state, pincode, mobile, email, contact_person, dl_no, gstin, remarks, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'Pending')");
            $stmt->execute([$empCode, $empName, $request_type, $chemist_name, $chemist_code, $address, $city, $district, $state, $pincode, $mobile, $email, $contact_person, $dl_no, $gstin, $remarks]);
            $msg = "Chemist request submitted successfully!";
        }
    }
}

// ROLE SCOPE: MR = own requests, manager = own + whole team, admin = all
$__p = [];
$stmt = $pdo->prepare("SELECT * FROM chemist_requests WHERE 1=1" . ($ctx['is_mr'] ? " AND empcode = ?" : scope_in($ctx, 'empcode', $__p)) . " ORDER BY requested_on DESC");
$stmt->execute($ctx['is_mr'] ? [$empCode] : $__p);
$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "Chemist Request";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header d-flex justify-content-between align-items-center">
    <div>
        <h1><i class="fas fa-clinic-medical"></i> Chemist Request</h1>
        <p class="text-muted mb-0">Add / Edit / Delete request for Chemist master</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#requestModal">
        <i class="fas fa-plus"></i> New Request
    </button>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
    <?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Request Type</th>
                        <th>Chemist Name</th>
                        <th>City / State</th>
                        <th>Mobile</th>
                        <th>Status</th>
                        <th>Requested On</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                        <tr><td colspan="8" class="text-center text-muted">No requests found.</td></tr>
                    <?php else: foreach ($requests as $i => $r): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><span class="badge bg-info"><?= htmlspecialchars($r['request_type']) ?></span></td>
                            <td><?= htmlspecialchars($r['chemist_name']) ?><?php if (!empty($r['empcode']) && $r['empcode'] !== ($ctx['empcode'] ?? '')): ?><br><small class="text-muted"><?= htmlspecialchars(($r['empname'] ?? '') . ' (' . $r['empcode'] . ')') ?></small><?php endif; ?></td>
                            <td><?= htmlspecialchars(($r['city'] ?? '') . ' / ' . ($r['state'] ?? '')) ?></td>
                            <td><?= htmlspecialchars($r['mobile'] ?? '-') ?></td>
                            <td>
                                <?php
                                $badge = match($r['status']) {
                                    'Approved' => 'success',
                                    'Rejected' => 'danger',
                                    'Cancelled'=> 'secondary',
                                    default    => 'warning'
                                };
                                ?>
                                <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($r['status']) ?></span>
                            </td>
                            <td><?= date('d-M-Y H:i', strtotime($r['requested_on'])) ?></td>
                            <td>
                                <?php if ($r['status'] === 'Pending'): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this request?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $r['id'] ?>">
                                    <button class="btn btn-sm btn-outline-danger"><i class="fas fa-trash"></i></button>
                                </form>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="requestModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="submit">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">New Chemist Request</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Request Type *</label>
                            <select name="request_type" class="form-select" required>
                                <option value="Add">Add New</option>
                                <option value="Edit">Edit Existing</option>
                                <option value="Delete">Delete Existing</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Chemist Name *</label>
                            <input type="text" name="chemist_name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Chemist Code (for Edit/Delete)</label>
                            <input type="text" name="chemist_code" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Mobile</label>
                            <input type="text" name="mobile" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contact Person</label>
                            <input type="text" name="contact_person" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">City</label>
                            <input type="text" name="city" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">District</label>
                            <input type="text" name="district" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">State</label>
                            <input type="text" name="state" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Pincode</label>
                            <input type="text" name="pincode" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">DL No</label>
                            <input type="text" name="dl_no" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">GSTIN</label>
                            <input type="text" name="gstin" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Remarks</label>
                            <textarea name="remarks" class="form-control" rows="2"></textarea>
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
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
