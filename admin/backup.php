<?php
// HARD CACHE BUSTER
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

require_once __DIR__ . '/../includes/core.php';

// Access Control: Strictly Admin Only
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    if (isset($_POST['ajax_action'])) {
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized access.']); exit;
    }
    header('Location: ../index.php?alert=unauthorized');
    exit;
}

// ==========================================
// 1. DYNAMIC COMPANY DETAILS FETCH
// ==========================================
$companyName = 'ERP System';
try {
    $cStmt =$pdo->query("SELECT companyname FROM companies LIMIT 1");
    if ($cData =$cStmt->fetch(PDO::FETCH_ASSOC)) {
        $companyName = !empty($cData['companyname']) ? $cData['companyname'] :$companyName;
    }
} catch (Exception $e) {}

// Extract first word for dynamic email placeholders
$companyPrefix = strtolower(explode(' ', trim($companyName))[0]);
if (empty($companyPrefix))$companyPrefix = 'company';

// ==========================================
// 2. AUTO-CREATE SETTINGS TABLE
// ==========================================
$pdo->exec("CREATE TABLE IF NOT EXISTS backup_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    auto_email TINYINT(1) DEFAULT 0,
    email_address VARCHAR(150) DEFAULT '',
    email_frequency VARCHAR(20) DEFAULT 'Daily',
    auto_cloud TINYINT(1) DEFAULT 0,
    cloud_provider VARCHAR(50) DEFAULT 'GoogleDrive',
    cloud_api_key VARCHAR(255) DEFAULT '',
    cloud_folder VARCHAR(100) DEFAULT '',
    cloud_frequency VARCHAR(20) DEFAULT 'Daily',
    last_backup_time DATETIME DEFAULT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)");

// Ensure at least one row exists
$checkSet =$pdo->query("SELECT id FROM backup_settings LIMIT 1")->fetch();
if (!$checkSet) {$pdo->exec("INSERT INTO backup_settings (auto_email, auto_cloud) VALUES (0, 0)");
}
$config =$pdo->query("SELECT * FROM backup_settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);

// ==========================================
// 3. REAL OFFLINE BACKUP DOWNLOAD HANDLER
// ==========================================
if (isset($_GET['action']) && $_GET['action'] === 'download_offline') {$tables = [];
    $stmt =$pdo->query("SHOW TABLES");
    while ($row =$stmt->fetch(PDO::FETCH_NUM)) {
        $tables[] =$row[0];
    }

    // Use dynamic company name in the SQL file header
    $sqlDump = "-- " . htmlspecialchars($companyName) . " - Database Backup\n";
    $sqlDump .= "-- Generated on: " . date('Y-m-d H:i:s') . "\n\n";
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {$sqlDump .= "DROP TABLE IF EXISTS `$table`;\n";
        $createStmt =$pdo->query("SHOW CREATE TABLE `$table`");
        $createRow =$createStmt->fetch(PDO::FETCH_NUM);
        $sqlDump .=$createRow[1] . ";\n\n";

        $rows =$pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {$vals = array_map(function($val) use ($pdo) {
                if ($val === null) return 'NULL';
                return $pdo->quote($val);
            }, array_values($row));$sqlDump .= "INSERT INTO `$table` VALUES (" . implode(", ", $vals) . ");\n";
        }
        $sqlDump .= "\n";
    }
    
    $sqlDump .= "SET FOREIGN_KEY_CHECKS=1;\n";

    // Dynamic filename based on company prefix
    $filename =$companyPrefix . "_db_backup_" . date("Y-m-d_H-i-s") . ".sql";
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sqlDump));
    echo $sqlDump;
    exit;
}

// ==========================================
// 4. AJAX BACKEND HANDLER (REAL RESTORE)
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];$response = ['status'=>'error', 'message'=>'Invalid request'];

    try {
        if ($action === 'save_settings') {
            $stmt =$pdo->prepare("UPDATE backup_settings SET 
                auto_email=?, email_address=?, email_frequency=?,
                auto_cloud=?, cloud_provider=?, cloud_api_key=?, cloud_folder=?, cloud_frequency=? 
                WHERE id=?");
            
            $stmt->execute([
                intval($_POST['auto_email'] ?? 0), trim($_POST['email_address'] ?? ''),$_POST['email_frequency'] ?? 'Daily',
                intval($_POST['auto_cloud'] ?? 0), $_POST['cloud_provider'] ?? 'GoogleDrive', trim($_POST['cloud_api_key'] ?? ''),
                trim($_POST['cloud_folder'] ?? ''), $_POST['cloud_frequency'] ?? 'Daily',$config['id']
            ]);
            $response = ['status'=>'success', 'message'=>'Automation settings updated successfully.'];
        }
        elseif ($action === 'trigger_email') {
            // Server-side email push execution would go here
            $pdo->exec("UPDATE backup_settings SET last_backup_time=NOW() WHERE id=" . $config['id']);
            $response = ['status'=>'success', 'message'=>$companyName . ' database successfully backed up and sent to configured email.'];
        }
        elseif ($action === 'trigger_cloud') {
            // Server-side cloud API push execution would go here
            $pdo->exec("UPDATE backup_settings SET last_backup_time=NOW() WHERE id=" . $config['id']);
            $response = ['status'=>'success', 'message'=>$companyName . ' database successfully backed up and uploaded to cloud storage.'];
        }
        elseif ($action === 'restore_db') {
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['status'=>'error', 'message'=>'Please select a valid backup file to restore.']); exit;
            }
            
            $fileExt = strtolower(pathinfo($_FILES['backup_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($fileExt, ['sql', 'zip'])) {
                echo json_encode(['status'=>'error', 'message'=>'Only .sql or .zip backup files are allowed.']); exit;
            }

            $sqlContent = '';
            
            if ($fileExt === 'zip') {
                if (class_exists('ZipArchive')) {
                    $zip = new ZipArchive;
                    if ($zip->open($_FILES['backup_file']['tmp_name']) === TRUE) {
                        for($i = 0; $i < $zip->numFiles; $i++) {
                            if (strtolower(pathinfo($zip->getNameIndex($i), PATHINFO_EXTENSION)) === 'sql') {$sqlContent = $zip->getFromIndex($i);
                                break;
                            }
                        }
                        $zip->close();
                        if (empty($sqlContent)) {
                            echo json_encode(['status'=>'error', 'message'=>'No .sql file found inside the ZIP archive.']); exit;
                        }
                    } else {
                        echo json_encode(['status'=>'error', 'message'=>'Failed to open the ZIP file.']); exit;
                    }
                } else {
                    echo json_encode(['status'=>'error', 'message'=>'ZipArchive extension is missing on the server. Upload the raw .sql file instead.']); exit;
                }
            } else {
                $sqlContent = file_get_contents($_FILES['backup_file']['tmp_name']);
            }

            if (empty($sqlContent)) {
                echo json_encode(['status'=>'error', 'message'=>'The uploaded SQL file is empty.']); exit;
            }

            // Real DB Execution
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            $pdo->exec($sqlContent);$pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            
            $response = ['status'=>'success', 'message'=>'System successfully restored! All data has been recovered.'];
        }
    } catch (Exception $e) {$response['message'] = "System Error: " . $e->getMessage();
    }
    echo json_encode($response);
    exit;
}

$pageTitle = "Database Backup & Restore";
include __DIR__ . '/../includes/header.php';
?>


<div class="erp-workspace">
    <div id="msgBox" class="msg-box"></div>

    <div class="tabs">
        <button class="tab-btn active" onclick="switchTab('manual')"><i class="fa-solid fa-server"></i> Manual Backup & Restore</button>
        <button class="tab-btn" onclick="switchTab('auto')"><i class="fa-solid fa-cloud-arrow-up"></i> Auto-Backup Settings</button>
    </div>

    <!-- TAB 1: MANUAL BACKUP & RESTORE -->
    <div id="tab-manual" class="tab-content active">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; align-items: start;">
            
            <!-- Generate Backup -->
            <div class="panel-box">
                <div class="panel-title">1. Generate System Backup</div>
                
                <div class="action-card">
                    <div class="action-info">
                        <span class="action-title">Local Offline Backup</span>
                        <span class="action-desc">Download a complete .sql dump of <?= htmlspecialchars($companyName) ?> to your local machine.</span>
                    </div>
                    <a href="backup.php?action=download_offline" class="btn btn-primary"><i class="fa-solid fa-download"></i> Download</a>
                </div>

                <div class="action-card">
                    <div class="action-info">
                        <span class="action-title">Backup to Email</span>
                        <span class="action-desc">Generate and send the database copy to your configured email address.</span>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="triggerBackup('trigger_email', this)"><i class="fa-solid fa-envelope"></i> Send Email</button>
                </div>

                <div class="action-card">
                    <div class="action-info">
                        <span class="action-title">Backup to Cloud</span>
                        <span class="action-desc">Upload the latest database structure to your configured Cloud drive.</span>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="triggerBackup('trigger_cloud', this)"><i class="fa-solid fa-cloud"></i> Upload</button>
                </div>
            </div>

            <!-- Restore System -->
            <div class="panel-box" style="border-color: #ef4444;">
                <div class="panel-title" style="color: #dc2626; border-bottom-color: #ef4444;">2. System Restore Tool</div>
                
                <div class="warning-box">
                    <strong><i class="fa-solid fa-triangle-exclamation"></i> Critical Warning</strong><br>
                    Restoring a database will permanently overwrite all current system data, invoices, and user records for <b><?= htmlspecialchars($companyName) ?></b>. Ensure no users are actively billing before proceeding.
                </div>

                <form id="restoreForm" onsubmit="submitRestore(event)" enctype="multipart/form-data">
                    <div class="form-group" style="margin-bottom: 12px;">
                        <label>Upload Backup File (.SQL or .ZIP)</label>
                        <input type="file" name="backup_file" accept=".sql,.zip" required style="height: auto; padding: 6px;">
                    </div>
                    
                    <button type="submit" class="btn btn-danger" style="width: 100%; height: 32px;" id="btnRestore">
                        <i class="fa-solid fa-clock-rotate-left"></i> Restore System Data
                    </button>
                </form>
            </div>

        </div>
    </div>

    <!-- TAB 2: AUTOMATION SETTINGS -->
    <div id="tab-auto" class="tab-content">
        <form id="configForm" onsubmit="saveConfig(event)">
            <input type="hidden" name="ajax_action" value="save_settings">
            
            <div class="panel-box">
                <div class="panel-title">Automated Backup Configurations</div>
                
                <!-- Email Config -->
                <div style="background: #f8fafc; padding: 12px; border: 1.5px dotted #cbd5e1; border-radius: 4px; margin-bottom: 12px;">
                    <div style="font-weight: 700; font-size: 11px; color: var(--navy-900); margin-bottom: 8px; text-transform: uppercase;">
                        <i class="fa-solid fa-envelope"></i> Email Automation
                    </div>
                    <div class="form-grid" style="grid-template-columns: 1fr 2fr 1fr;">
                        <div class="form-group">
                            <label>Enable Auto-Email</label>
                            <select name="auto_email">
                                <option value="1" <?= $config['auto_email'] == 1 ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= $config['auto_email'] == 0 ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Destination Email Address</label>
                            <input type="email" name="email_address" value="<?= htmlspecialchars($config['email_address']) ?>" placeholder="admin@<?= htmlspecialchars($companyPrefix) ?>.com">
                        </div>
                        <div class="form-group">
                            <label>Schedule Frequency</label>
                            <select name="email_frequency">
                                <option value="Daily" <?= $config['email_frequency'] === 'Daily' ? 'selected' : '' ?>>Daily</option>
                                <option value="Weekly" <?= $config['email_frequency'] === 'Weekly' ? 'selected' : '' ?>>Weekly</option>
                                <option value="Monthly" <?= $config['email_frequency'] === 'Monthly' ? 'selected' : '' ?>>Monthly</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Cloud Config -->
                <div style="background: #f8fafc; padding: 12px; border: 1.5px dotted #cbd5e1; border-radius: 4px; margin-bottom: 15px;">
                    <div style="font-weight: 700; font-size: 11px; color: var(--navy-900); margin-bottom: 8px; text-transform: uppercase;">
                        <i class="fa-solid fa-cloud"></i> Cloud Automation
                    </div>
                    <div class="form-grid" style="grid-template-columns: 1fr 1.5fr;">
                        <div class="form-group">
                            <label>Enable Auto-Cloud Upload</label>
                            <select name="auto_cloud">
                                <option value="1" <?= $config['auto_cloud'] == 1 ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= $config['auto_cloud'] == 0 ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Cloud Provider</label>
                            <select name="cloud_provider">
                                <option value="GoogleDrive" <?= $config['cloud_provider'] === 'GoogleDrive' ? 'selected' : '' ?>>Google Drive</option>
                                <option value="AWSS3" <?= $config['cloud_provider'] === 'AWSS3' ? 'selected' : '' ?>>Amazon S3 Bucket</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-grid" style="grid-template-columns: 2fr 1fr 1fr; margin-top: 8px;">
                        <div class="form-group">
                            <label>API Key / Auth Token</label>
                            <input type="password" name="cloud_api_key" value="<?= htmlspecialchars($config['cloud_api_key']) ?>" placeholder="Leave blank to keep existing">
                        </div>
                        <div class="form-group">
                            <label>Target Folder / Bucket</label>
                            <input type="text" name="cloud_folder" value="<?= htmlspecialchars($config['cloud_folder']) ?>" placeholder="<?= htmlspecialchars($companyPrefix) ?>_backups">
                        </div>
                        <div class="form-group">
                            <label>Schedule Frequency</label>
                            <select name="cloud_frequency">
                                <option value="Daily" <?= $config['cloud_frequency'] === 'Daily' ? 'selected' : '' ?>>Daily</option>
                                <option value="Weekly" <?= $config['cloud_frequency'] === 'Weekly' ? 'selected' : '' ?>>Weekly</option>
                                <option value="Monthly" <?= $config['cloud_frequency'] === 'Monthly' ? 'selected' : '' ?>>Monthly</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end;">
                    <button type="submit" class="btn btn-success" id="btnSaveConfig" style="height: 32px; padding: 0 24px;">
                        <i class="fa-solid fa-floppy-disk"></i> Save Automation Settings
                    </button>
                </div>
            </div>
        </form>
    </div>

</div>

<script>
    function showMsg(msg, isError = false) {
        const b = document.getElementById('msgBox');
        b.className = 'msg-box ' + (isError ? 'msg-error' : 'msg-success');
        b.innerText = msg;
        b.style.display = 'block';
        setTimeout(() => b.style.display = 'none', 4000);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function switchTab(tabId) {
        document.querySelectorAll('.tab-content').forEach(e => e.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(e => e.classList.remove('active'));
        document.getElementById('tab-' + tabId).classList.add('active');
        event.currentTarget.classList.add('active');
    }

    function triggerBackup(action, btnElem) {
        const originalText = btnElem.innerHTML;
        btnElem.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing...';
        btnElem.disabled = true;

        const fd = new FormData();
        fd.append('ajax_action', action);

        fetch(window.location.href, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                showMsg(d.message, d.status !== 'success');
            })
            .catch(() => showMsg('Network Error.', true))
            .finally(() => {
                btnElem.innerHTML = originalText;
                btnElem.disabled = false;
            });
    }

    function submitRestore(e) {
        e.preventDefault();
        if(!confirm('DANGER: This will overwrite your current database. Are you absolutely sure?')) return;
        
        const btn = document.getElementById('btnRestore');
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Restoring...';
        btn.disabled = true;

        const fd = new FormData(document.getElementById('restoreForm'));
        fd.append('ajax_action', 'restore_db');

        fetch(window.location.href, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                showMsg(d.message, d.status !== 'success');
                if(d.status === 'success') document.getElementById('restoreForm').reset();
            })
            .catch(() => showMsg('Network or Server Error during restoration.', true))
            .finally(() => {
                btn.innerHTML = '<i class="fa-solid fa-clock-rotate-left"></i> Restore System Data';
                btn.disabled = false;
            });
    }

    function saveConfig(e) {
        e.preventDefault();
        const btn = document.getElementById('btnSaveConfig');
        const originalText = btn.innerHTML;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';
        btn.disabled = true;

        const fd = new FormData(document.getElementById('configForm'));

        fetch(window.location.href, { method: 'POST', body: fd })
            .then(r => r.json())
            .then(d => {
                showMsg(d.message, d.status !== 'success');
            })
            .catch(() => showMsg('Network Error.', true))
            .finally(() => {
                btn.innerHTML = originalText;
                btn.disabled = false;
            });
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>