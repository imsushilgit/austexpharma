<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/core.php';

// --- User Context ---
$stmtUser = $pdo->prepare("SELECT u.id, u.role, u.username, e.* 
    FROM users u 
    LEFT JOIN employees e ON (u.username = e.empcode OR u.username = e.empname) AND e.resignation_date IS NULL 
    WHERE u.id = ? 
    ORDER BY (e.empcode = u.username) DESC LIMIT 1");
$stmtUser->execute([$_SESSION['user_id']]);
$user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];

$isAdmin = ($user['role'] ?? '') === 'Admin';
$empCode = $user['empcode'] ?? $user['username'] ?? '';
$empName = $user['empname'] ?? $user['username'] ?? '';

$msg = '';
$msgType = 'success';

// Handle Profile Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    $mobile      = trim($_POST['mobile'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $emergency   = trim($_POST['emergency_contact'] ?? '');
    $blood_group = trim($_POST['blood_group'] ?? '');
    $bank_name   = trim($_POST['bank_name'] ?? '');
    $account_no  = trim($_POST['account_no'] ?? '');
    $ifsc        = trim($_POST['ifsc'] ?? '');

    try {
        $stmt = $pdo->prepare("UPDATE employees SET 
            mobile = ?, email = ?, address = ?, 
            emergency_contact = ?, blood_group = ?,
            bank_name = ?, account_no = ?, ifsc_code = ?
            WHERE empcode = ?");
        $stmt->execute([$mobile, $email, $address, $emergency, $blood_group, $bank_name, $account_no, $ifsc, $empCode]);
        $msg = "Profile updated successfully!";
        // Refresh data
        $stmtUser->execute([$_SESSION['user_id']]);
        $user = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: $user;
    } catch (Exception $e) {
        $msg = "Error updating profile: " . $e->getMessage();
        $msgType = 'danger';
    }
}

$pageTitle = "My Profile";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-user-circle"></i> My Profile</h1>
    <p class="text-muted">View and update your personal details</p>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show">
    <?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card shadow-sm">
            <div class="card-body text-center">
                <div class="avatar-circle mb-3" style="width:100px;height:100px;background:#1F4E79;color:#fff;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:36px;font-weight:600;">
                    <?= strtoupper(substr($empName, 0, 1)) ?>
                </div>
                <h4 class="mb-1"><?= htmlspecialchars($empName) ?></h4>
                <p class="text-muted mb-1"><?= htmlspecialchars($user['designation'] ?? 'MR') ?></p>
                <p class="text-muted small"><?= htmlspecialchars($user['department'] ?? 'Sales') ?> | HQ: <?= htmlspecialchars($user['hq'] ?? '-') ?></p>
                <hr>
                <p class="mb-1"><strong>Emp Code:</strong> <?= htmlspecialchars($empCode) ?></p>
                <p class="mb-0"><strong>Reporting To:</strong> <?= htmlspecialchars($user['reporting_manager'] ?? '-') ?></p>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <div class="card shadow-sm">
            <div class="card-header bg-primary text-white">
                <strong>Personal & Bank Details</strong>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Mobile</label>
                            <input type="text" name="mobile" class="form-control" value="<?= htmlspecialchars($user['mobile'] ?? '') ?>" maxlength="15">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Emergency Contact</label>
                            <input type="text" name="emergency_contact" class="form-control" value="<?= htmlspecialchars($user['emergency_contact'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Blood Group</label>
                            <input type="text" name="blood_group" class="form-control" value="<?= htmlspecialchars($user['blood_group'] ?? '') ?>" placeholder="e.g. B+">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Bank Name</label>
                            <input type="text" name="bank_name" class="form-control" value="<?= htmlspecialchars($user['bank_name'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Account No</label>
                            <input type="text" name="account_no" class="form-control" value="<?= htmlspecialchars($user['account_no'] ?? '') ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">IFSC Code</label>
                            <input type="text" name="ifsc" class="form-control" value="<?= htmlspecialchars($user['ifsc_code'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Update Profile</button>
                        <a href="../auth/resetpassword.php" class="btn btn-outline-secondary ms-2"><i class="fas fa-key"></i> Change Password</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
