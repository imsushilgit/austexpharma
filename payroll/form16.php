<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
require_once __DIR__ . '/../includes/core.php';

// --- User Context ---
$stmtUser = $pdo->prepare("SELECT u.role, u.username, e.empname, e.empcode 
    FROM users u LEFT JOIN employees e ON (u.username = e.empcode OR u.username = e.empname) AND e.resignation_date IS NULL 
    WHERE u.id = ? ORDER BY (e.empcode = u.username) DESC LIMIT 1");
$stmtUser->execute([$_SESSION['user_id']]);
$userMeta = $stmtUser->fetch(PDO::FETCH_ASSOC) ?: [];
$isAdmin  = ($userMeta['role'] ?? '') === 'Admin';
$empCode  = $userMeta['empcode'] ?? $userMeta['username'] ?? '';
$empName  = $userMeta['empname'] ?? $userMeta['username'] ?? '';

$msg = ''; $msgType = 'success';

// Admin upload
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload') {
    $targetEmp  = trim($_POST['empcode'] ?? '');
    $fy         = trim($_POST['financial_year'] ?? '');
    $remarks    = trim($_POST['remarks'] ?? '');

    if ($targetEmp && $fy && isset($_FILES['form16_file']) && $_FILES['form16_file']['error'] === 0) {
        $ext = strtolower(pathinfo($_FILES['form16_file']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
            $msg = "Only PDF/JPG/PNG allowed."; $msgType = 'danger';
        } else {
            $dir = dirname(__DIR__) . '/uploads/form16/';
            if (!is_dir($dir)) mkdir($dir, 0755, true);
            $fileName = $targetEmp . '_' . $fy . '_' . time() . '.' . $ext;
            $path = $dir . $fileName;
            if (move_uploaded_file($_FILES['form16_file']['tmp_name'], $path)) {
                // Get empname
                $st = $pdo->prepare("SELECT empname FROM employees WHERE empcode = ?");
                $st->execute([$targetEmp]);
                $en = $st->fetchColumn() ?: $targetEmp;

                $pdo->prepare("INSERT INTO form16_documents (empcode, empname, financial_year, file_path, file_name, uploaded_by, remarks)
                    VALUES (?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE file_path=VALUES(file_path), file_name=VALUES(file_name), uploaded_by=VALUES(uploaded_by), uploaded_on=NOW(), remarks=VALUES(remarks)")
                    ->execute([$targetEmp, $en, $fy, 'uploads/form16/' . $fileName, $fileName, $empCode, $remarks]);
                $msg = "Form 16 uploaded successfully.";
            } else {
                $msg = "Upload failed."; $msgType = 'danger';
            }
        }
    }
}

// Fetch documents
if ($isAdmin) {
    $docs = $pdo->query("SELECT * FROM form16_documents ORDER BY financial_year DESC, empname")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT * FROM form16_documents WHERE empcode = ? ORDER BY financial_year DESC");
    $stmt->execute([$empCode]);
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = "Form 16";
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><i class="fas fa-file-invoice"></i> Form 16</h1>
    <p class="text-muted">Download your annual Form 16</p>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show"><?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($isAdmin): ?>
<div class="card shadow-sm mb-4">
    <div class="card-header bg-secondary text-white">Upload Form 16 (Admin)</div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="action" value="upload">
            <div class="col-md-3">
                <label class="form-label">Employee Code</label>
                <input type="text" name="empcode" class="form-control" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Financial Year</label>
                <input type="text" name="financial_year" class="form-control" placeholder="2025-26" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">File (PDF/JPG)</label>
                <input type="file" name="form16_file" class="form-control" accept=".pdf,.jpg,.jpeg,.png" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Remarks</label>
                <input type="text" name="remarks" class="form-control">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Upload</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm">
    <div class="card-body table-responsive">
        <table class="table table-bordered table-hover align-middle">
            <thead class="table-light">
                <tr>
                    <?php if ($isAdmin): ?><th>Employee</th><?php endif; ?>
                    <th>Financial Year</th>
                    <th>File</th>
                    <th>Uploaded On</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($docs)): ?>
                    <tr><td colspan="5" class="text-center text-muted">No Form 16 available.</td></tr>
                <?php else: foreach ($docs as $d): ?>
                    <tr>
                        <?php if ($isAdmin): ?>
                            <td><?= htmlspecialchars($d['empname']) ?> (<?= htmlspecialchars($d['empcode']) ?>)</td>
                        <?php endif; ?>
                        <td><?= htmlspecialchars($d['financial_year']) ?></td>
                        <td><?= htmlspecialchars($d['file_name']) ?></td>
                        <td><?= date('d-M-Y', strtotime($d['uploaded_on'])) ?></td>
                        <td>
                            <a href="<?= BASE_URL . ltrim(htmlspecialchars($d['file_path']), '/') ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                                <i class="fas fa-download"></i> Download
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
