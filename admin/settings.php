<?php
// HARD CACHE BUSTER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/../includes/core.php';

// Access Control
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    if (isset($_POST['ajax_action'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access. Admins only."]);
        exit();
    }
    header("Location: ../dashboard/dashboard.php?alert=unauthorized");
    exit();
}

$companyid = 1; 

// ==========================================
// 1. AUTO-SCHEMA FIXER (Dynamic Columns)
// ==========================================
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS companies (
        id INT AUTO_INCREMENT PRIMARY KEY,
        companyname VARCHAR(255) NOT NULL,
        address VARCHAR(255), email VARCHAR(100), phone VARCHAR(50),
        gstin VARCHAR(50), dlno VARCHAR(100), foodlicno VARCHAR(100)
    ) ENGINE=InnoDB;");

    $existingColumns = $pdo->query("SHOW COLUMNS FROM companies")->fetchAll(PDO::FETCH_COLUMN);
    $newColumns = [
        'regoffice' => 'VARCHAR(255) AFTER address',
        'hooffice' => 'VARCHAR(255) AFTER regoffice',
        'city' => 'VARCHAR(100) AFTER hooffice',
        'pincode' => 'VARCHAR(20) AFTER city',
        'website' => 'VARCHAR(100) AFTER email',
        'invoiceterms' => 'TEXT AFTER foodlicno',
        'passwordexpirydays' => 'INT DEFAULT 90',
        'warningwindow' => 'INT DEFAULT 5',
        'applicensekey' => 'VARCHAR(255)',
        'applicenseexpiry' => 'DATE',
        'logotype' => "ENUM('image', 'html') DEFAULT 'image'",
        'logopath' => 'VARCHAR(255)',
        'logohtml' => 'TEXT',
        'aboutus' => 'TEXT',
        'businesshours' => 'VARCHAR(100) DEFAULT "Mon - Sat, 9:00 AM - 6:00 PM"',
        'establishedyear' => 'VARCHAR(4) DEFAULT "2010"',
        'linkedinlink' => 'VARCHAR(255)',
        'supportemail' => 'VARCHAR(100)'
    ];
    
    foreach ($newColumns as $col => $def) {
        if (!in_array($col, $existingColumns)) {
            $pdo->exec("ALTER TABLE companies ADD COLUMN $col $def");
        }
    }
    
    $checkRow = $pdo->prepare("SELECT id FROM companies WHERE id = ?");
    $checkRow->execute([$companyid]);
    if (!$checkRow->fetch()) {
        $pdo->exec("INSERT INTO companies (id, companyname) VALUES ($companyid, 'My Company')");
    }
} catch (PDOException $e) {}

// ==========================================
// 2. AJAX BACKEND HANDLER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'syncsettings') {
    header('Content-Type: application/json');
    
    try {
        $companyname = trim($_POST['companyname'] ?? '');
        if (empty($companyname)) throw new Exception("Company Full Name is required.");

        $website = trim($_POST['website'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $regoffice = trim($_POST['regoffice'] ?? '');
        $hooffice = trim($_POST['hooffice'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $pincode = trim($_POST['pincode'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $supportemail = trim($_POST['supportemail'] ?? '');
        $linkedinlink = trim($_POST['linkedinlink'] ?? '');
        $aboutus = trim($_POST['aboutus'] ?? '');
        $businesshours = trim($_POST['businesshours'] ?? '');
        $establishedyear = trim($_POST['establishedyear'] ?? '');
        
        $gstin = trim($_POST['gstin'] ?? '');
        $dlno = trim($_POST['dlno'] ?? '');
        $foodlicno = trim($_POST['foodlicno'] ?? '');
        $invoiceterms = trim($_POST['invoiceterms'] ?? '');
        
        $passwordexpirydays = (int)($_POST['passwordexpirydays'] ?? 90);
        $warningwindow = (int)($_POST['warningwindow'] ?? 5);
        $applicensekey = trim($_POST['applicensekey'] ?? '');
        $applicenseexpiry = !empty($_POST['applicenseexpiry']) ? trim($_POST['applicenseexpiry']) : null;

        $logotype = $_POST['logotype'] ?? 'image';
        $logohtml = $_POST['logohtml'] ?? '';
        $logopath = $_POST['existinglogopath'] ?? '';

        // Handle Image Upload
        if ($logotype === 'image' && isset($_FILES['logoupload']) && $_FILES['logoupload']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = dirname(__DIR__) . '/uploads/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
            
            $fileExt = strtolower(pathinfo($_FILES['logoupload']['name'], PATHINFO_EXTENSION));
            $allowedExt = ['jpg', 'jpeg', 'png', 'gif'];
            
            if (in_array($fileExt, $allowedExt)) {
                $fileName = 'logo_' . time() . '.' . $fileExt;
                if (move_uploaded_file($_FILES['logoupload']['tmp_name'], $uploadDir . $fileName)) {
                    $logopath = 'uploads/' . $fileName;
                }
            } else {
                throw new Exception("Invalid image format. Only JPG, PNG, and GIF are allowed.");
            }
        }

        $stmt = $pdo->prepare("
            UPDATE companies SET 
            companyname=?, website=?, address=?, regoffice=?, hooffice=?, city=?, pincode=?, email=?, phone=?, supportemail=?, linkedinlink=?, aboutus=?, businesshours=?, establishedyear=?,
            gstin=?, dlno=?, foodlicno=?, invoiceterms=?, 
            passwordexpirydays=?, warningwindow=?, applicensekey=?, applicenseexpiry=?, logotype=?, logopath=?, logohtml=? 
            WHERE id=?
        ");
        $stmt->execute([
            $companyname, $website, $address, $regoffice, $hooffice, $city, $pincode, $email, $phone, $supportemail, $linkedinlink, $aboutus, $businesshours, $establishedyear,
            $gstin, $dlno, $foodlicno, $invoiceterms,
            $passwordexpirydays, $warningwindow, $applicensekey, $applicenseexpiry, $logotype, $logopath, $logohtml,
            $companyid
        ]);
        
        echo json_encode(["status" => "success", "message" => "Company settings updated successfully."]);
    } catch (Exception $e) {
        echo json_encode(["status" => "error", "message" => $e->getMessage()]);
    }
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
$stmt->execute([$companyid]);
$c = $stmt->fetch();

$pageTitle = "Company Settings";
include __DIR__ . '/../includes/header.php';
?>


<div class="settings-wrapper">
    <form id="settingsForm" onsubmit="submitSettings(event)">
        <input type="hidden" name="ajax_action" value="syncsettings">
        
        <div class="grid-layout">
            
            <!-- LEFT COLUMN: Main Configurations -->
            <div style="display: flex; flex-direction: column; gap: 15px;">
                
                <div class="panel-box">
                    <div class="panel-title">1. Profile & Contact Details</div>
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Entity Full Name *</label>
                            <input type="text" name="companyname" required value="<?= htmlspecialchars($c['companyname'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Established Year</label>
                            <input type="text" name="establishedyear" value="<?= htmlspecialchars($c['establishedyear'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Primary Phone</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($c['phone'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Official Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($c['email'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Support / NOC Email</label>
                            <input type="email" name="supportemail" value="<?= htmlspecialchars($c['supportemail'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Website</label>
                            <input type="text" name="website" value="<?= htmlspecialchars($c['website'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>LinkedIn URL</label>
                            <input type="text" name="linkedinlink" value="<?= htmlspecialchars($c['linkedinlink'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 4;">
                            <label>About Us / Company Bio</label>
                            <textarea name="aboutus" style="height: 40px; resize: vertical;"><?= htmlspecialchars($c['aboutus'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="panel-box">
                    <div class="panel-title">2. Network & Logistics</div>
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                        <div class="form-group" style="grid-column: span 4;">
                            <label>Registered Address (HQ)</label>
                            <input type="text" name="address" value="<?= htmlspecialchars($c['address'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Zonal Office</label>
                            <input type="text" name="regoffice" value="<?= htmlspecialchars($c['regoffice'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Dispatch Hub</label>
                            <input type="text" name="hooffice" value="<?= htmlspecialchars($c['hooffice'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>City</label>
                            <input type="text" name="city" value="<?= htmlspecialchars($c['city'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Pincode</label>
                            <input type="text" name="pincode" value="<?= htmlspecialchars($c['pincode'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Business Hours</label>
                            <input type="text" name="businesshours" value="<?= htmlspecialchars($c['businesshours'] ?? '') ?>">
                        </div>
                    </div>
                </div>

                <div class="panel-box">
                    <div class="panel-title">3. Statutory & Tax configuration</div>
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr 1fr 1fr;">
                        <div class="form-group">
                            <label>GSTIN ID</label>
                            <input type="text" name="gstin" style="text-transform: uppercase;" value="<?= htmlspecialchars($c['gstin'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Drug License No</label>
                            <input type="text" name="dlno" value="<?= htmlspecialchars($c['dlno'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>FSSAI Registration</label>
                            <input type="text" name="foodlicno" value="<?= htmlspecialchars($c['foodlicno'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 4;">
                            <label>Global Invoice Terms (Printed on Bills)</label>
                            <textarea name="invoiceterms" style="height: 50px; font-family: monospace;"><?= htmlspecialchars($c['invoiceterms'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>

            <!-- RIGHT COLUMN: System & Media -->
            <div style="display: flex; flex-direction: column; gap: 15px;">
                
                <div class="panel-box">
                    <div class="panel-title">4. Branding / Logo</div>
                    <div style="display: flex; gap: 15px; margin-bottom: 10px;">
                        <label style="font-size: 9px; font-weight: bold; cursor: pointer; color: #1e2a78;">
                            <input type="radio" name="logotype" value="image" <?= (($c['logotype'] ?? 'image') === 'image') ? 'checked' : '' ?> onchange="toggleLogo()"> Image Upload
                        </label>
                        <label style="font-size: 9px; font-weight: bold; cursor: pointer; color: #1e2a78;">
                            <input type="radio" name="logotype" value="html" <?= (($c['logotype'] ?? '') === 'html') ? 'checked' : '' ?> onchange="toggleLogo()"> HTML Block
                        </label>
                    </div>

                    <div id="imgSection" style="display: <?= (($c['logotype'] ?? 'image') === 'image') ? 'block' : 'none' ?>;">
                        <div style="text-align: center; background: #f8fafc; border: 1px dashed #cbd5e1; padding: 10px; margin-bottom: 8px; border-radius: 4px;">
                            <?php if(!empty($c['logopath'])): ?>
                                <img src="<?= BASE_URL . ltrim(htmlspecialchars($c['logopath']), '/') ?>" style="max-height: 50px; max-width: 100%; object-fit: contain;">
                            <?php else: ?>
                                <span style="font-size: 9px; color: #94a3b8;">No Image</span>
                            <?php endif; ?>
                        </div>
                        <input type="file" name="logoupload" accept="image/*" style="font-size: 9px;">
                        <input type="hidden" name="existinglogopath" value="<?= htmlspecialchars($c['logopath'] ?? '') ?>">
                    </div>

                    <div id="htmlSection" style="display: <?= (($c['logotype'] ?? '') === 'html') ? 'block' : 'none' ?>;">
                        <div class="form-group">
                            <textarea name="logohtml" style="height: 80px; font-family: monospace;" placeholder="<h1>Brand</h1>"><?= htmlspecialchars($c['logohtml'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="panel-box">
                    <div class="panel-title">5. Core System License</div>
                    <div class="form-group" style="margin-bottom: 8px;">
                        <label>Software License Key</label>
                        <input type="text" name="applicensekey" style="font-family: monospace; font-weight: bold;" value="<?= htmlspecialchars($c['applicensekey'] ?? '') ?>">
                    </div>
                    <div class="form-group" style="margin-bottom: 8px;">
                        <label>License Expiration</label>
                        <input type="date" name="applicenseexpiry" value="<?= htmlspecialchars($c['applicenseexpiry'] ?? '') ?>">
                    </div>
                    <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                        <div class="form-group">
                            <label>Pwd Expiry (Days)</label>
                            <input type="number" name="passwordexpirydays" value="<?= htmlspecialchars($c['passwordexpirydays'] ?? '90') ?>">
                        </div>
                        <div class="form-group">
                            <label>Warn Window</label>
                            <input type="number" name="warningwindow" value="<?= htmlspecialchars($c['warningwindow'] ?? '5') ?>">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" id="btnSubmit">Save Global Configurations</button>
            </div>
        </div>
    </form>
</div>

<script>
    function toggleLogo() {
        const type = document.querySelector('input[name="logotype"]:checked').value;
        document.getElementById('imgSection').style.display = (type === 'image') ? 'block' : 'none';
        document.getElementById('htmlSection').style.display = (type === 'html') ? 'block' : 'none';
    }

    function submitSettings(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSubmit');
        btn.innerText = 'Synchronizing...';
        btn.disabled = true;

        const formData = new FormData(document.getElementById('settingsForm'));

        fetch(window.location.href, { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                    setTimeout(() => location.reload(), 1000);
                } else {
                    if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
                }
            })
            .catch(() => {
                if (typeof AppAlert !== 'undefined') AppAlert.error('Network connection error.');
            })
            .finally(() => {
                btn.innerText = 'Save Global Configurations';
                btn.disabled = false;
            });
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>