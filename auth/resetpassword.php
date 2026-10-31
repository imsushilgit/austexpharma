<?php
session_start();

// Security Check: Only logged-in users can change their password
if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['ajax_action'])) {
        echo json_encode(["status" => "error", "message" => "Unauthorized access."]);
        exit();
    }
    header("Location: login.php");
    exit();
}

require_once __DIR__ . '/../includes/core.php';

// ==========================================
// BACKEND AJAX HANDLER
// ==========================================
if (isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    $action = $_POST['ajax_action'];
    $response = ['status' => 'error', 'message' => 'Invalid action'];

    try {
        if ($action === 'change_password') {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $user_id = $_SESSION['user_id'];

            if (empty($current_password) or empty($new_password) or empty($confirm_password)) {
                echo json_encode(["status" => "error", "message" => "All fields are mandatory."]);
                exit();
            }

            if ($new_password !== $confirm_password) {
                echo json_encode(["status" => "error", "message" => "New password and Confirm password do not match."]);
                exit();
            }

            // Verify current password from database
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user or !password_verify($current_password, $user['password'])) {
                echo json_encode(["status" => "error", "message" => "Your current password is incorrect."]);
                exit();
            }

            // Hash the new password and update
            $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
            $updateStmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $updateStmt->execute([$new_hash, $user_id]);

            $response = ["status" => "success", "message" => "Your password has been successfully updated!"];
        }
    } catch (PDOException $e) {
        $response['message'] = "Database error. Please try again.";
    }

    echo json_encode($response);
    exit();
}

$pageTitle = "Reset Account Password";
include __DIR__ . '/../includes/header.php';
?>


<div class="reset-container">
    <div class="panel-box">
        <div class="panel-title">Change Account Password</div>
        
        <div class="user-info">
            Logged in as: <span><?= htmlspecialchars($_SESSION['username'] ?? 'User') ?></span>
        </div>

        <form id="resetPasswordForm" onsubmit="submitPasswordChange(event)" class="compact-form">
            <div class="form-group">
                <label>Current Password</label>
                <input type="password" name="current_password" id="current_password" class="form-control" required placeholder="Enter current password">
            </div>
            
            <div class="form-group">
                <label>New Password</label>
                <input type="password" name="new_password" id="new_password" class="form-control" required placeholder="Enter new password">
            </div>
            
            <div class="form-group">
                <label>Confirm New Password</label>
                <input type="password" name="confirm_password" id="confirm_password" class="form-control" required placeholder="Re-enter new password">
            </div>

            <button type="submit" class="btn-submit" id="btnSubmit">Update Password</button>
        </form>
    </div>
</div>

<script>
    function submitPasswordChange(e) {
        e.preventDefault();
        
        const newPass = document.getElementById('new_password').value;
        const confPass = document.getElementById('confirm_password').value;
        
        if (newPass !== confPass) {
            if (typeof AppAlert !== 'undefined') {
                AppAlert.error("New password and confirm password do not match!");
            } else {
                alert("New password and confirm password do not match!");
            }
            return;
        }

        const formData = new FormData(document.getElementById('resetPasswordForm'));
        formData.append('ajax_action', 'change_password');

        const btn = document.getElementById('btnSubmit');
        btn.innerText = 'Updating...';
        btn.disabled = true;

        fetch(window.location.href, { method: 'POST', body: formData })
        .then(res => res.json())
        .then(data => {
            if (data.status === 'success') {
                if (typeof AppAlert !== 'undefined') AppAlert.success(data.message);
                document.getElementById('resetPasswordForm').reset();
            } else {
                if (typeof AppAlert !== 'undefined') AppAlert.error(data.message);
            }
        })
        .catch(() => {
            if (typeof AppAlert !== 'undefined') AppAlert.error('Network error occurred.');
        })
        .finally(() => {
            btn.innerText = 'Update Password';
            btn.disabled = false;
        });
    }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>