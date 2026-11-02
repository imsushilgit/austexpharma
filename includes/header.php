<?php
// Fetch Company details dynamically for Header & Sidebar
$companyName = 'Company Name'; 
$logoType = 'none';
$logoPath = '';
$logoHtml = '';

try {
    if (isset($pdo)) {
        $compId = $_SESSION['companyid'] ?? 1;
        $stmtComp = $pdo->prepare("SELECT companyname, logotype, logopath, logohtml FROM companies WHERE id = ? LIMIT 1");
        $stmtComp->execute([$compId]);
        if ($cData = $stmtComp->fetch(PDO::FETCH_ASSOC)) {
            $companyName = !empty($cData['companyname']) ? $cData['companyname'] : $companyName;
            $logoType = $cData['logotype'] ?? 'none';
            $logoPath = $cData['logopath'] ?? '';
            $logoHtml = $cData['logohtml'] ?? '';
        }
    }
} catch (Exception $e) {}

// --- DYNAMIC NOTIFICATIONS LOGIC ---
$notifications = [];
$notiCount = 0;
try {
    if (isset($pdo) && isset($_SESSION['user_id'])) {
        $ctx = get_user_context($pdo);
        $isAdmin = (isset($_SESSION['user_role']) && strtoupper($_SESSION['user_role']) === 'ADMIN');
        $designation = strtoupper(trim($_SESSION['designation'] ?? ''));
        $isManager = (!$isAdmin && !empty($ctx['is_manager']));
        $myEmpCode = $ctx['empcode'] ?? ($_SESSION['empcode'] ?? '');
        // ROLE SCOPE: approvers are notified only about their own subordinates (admin: everyone)
        $__subs = $isAdmin ? [] : array_values($ctx['sub_codes'] ?? []);
        $__subIn = empty($__subs) ? '' : implode(',', array_fill(0, count($__subs), '?'));

        if ($isAdmin || $isManager) {
            if ($isAdmin) {
                $tpPending = (int)$pdo->query("SELECT COUNT(*) FROM tour_plans WHERE status = 'Pending'")->fetchColumn();
            } elseif ($__subIn !== '') {
                $tpStmt = $pdo->prepare("SELECT COUNT(*) FROM tour_plans WHERE status = 'Pending' AND empcode IN ($__subIn)");
                $tpStmt->execute($__subs);
                $tpPending = (int)$tpStmt->fetchColumn();
            } else { $tpPending = 0; }
            if ($tpPending > 0) {
                $notifications[] = ['icon' => 'fa-calendar-check', 'text' => "$tpPending Tour Plan(s) pending approval", 'time' => 'Action Required', 'link' => BASE_URL . 'fieldforce/tourplanapproval.php'];
                $notiCount += $tpPending;
            }

            if ($isAdmin) {
                $expPending = (int)$pdo->query("SELECT COUNT(*) FROM expenses WHERE status = 'Pending'")->fetchColumn();
            } elseif ($__subIn !== '') {
                $expStmt = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE status = 'Pending' AND empcode IN ($__subIn)");
                $expStmt->execute($__subs);
                $expPending = (int)$expStmt->fetchColumn();
            } else { $expPending = 0; }
            if ($expPending > 0) {
                $notifications[] = ['icon' => 'fa-file-invoice-dollar', 'text' => "$expPending Expense(s) pending approval", 'time' => 'Action Required', 'link' => BASE_URL . 'fieldforce/expenseapproval.php'];
                $notiCount += $expPending;
            }
        } else {
            if (!empty($myEmpCode)) {
                $myStmt = $pdo->prepare("SELECT tour_date, status, created_at FROM tour_plans WHERE empcode = ? ORDER BY id DESC LIMIT 3");
                $myStmt->execute([$myEmpCode]);
                foreach ($myStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $notifications[] = ['icon' => 'fa-calendar-day', 'text' => "Tour Plan (".date('d-M', strtotime($row['tour_date'])).") is ".$row['status'], 'time' => date('d M, h:i A', strtotime($row['created_at'])), 'link' => BASE_URL . 'fieldforce/tourplan.php'];
                    $notiCount++;
                }
            }
        }
    }
} catch (Exception $e) {}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        /* Transparent Logo Background Fix */
        .sidebar-brand {
            background-color: #ffffff !important;
            padding: 12px 10px;
            border-bottom: 1px solid #e2e8f0;
        }

        /* 3-Level Menu Styling */
        .nav-submenu-inner {
            position: relative;
            padding-left: 12px;
            margin-left: 20px;
            border-left: 1px solid rgba(255, 255, 255, 0.2); /* The vertical connecting line */
        }
        
        /* 2nd Level Items (Nodes with circles) */
        .nav-submenu-inner > a,
        .nav-submenu-inner > .nav-sub-group > .nav-sub-master {
            position: relative;
            padding-left: 15px !important;
            display: flex;
            align-items: center;
            justify-content: space-between;
            width: 100%;
            background: transparent;
            border: none;
            color: inherit;
            text-align: left;
            cursor: pointer;
            font-size: 13px;
            padding-top: 10px;
            padding-bottom: 10px;
        }

        /* The Hollow Circle Node */
        .nav-submenu-inner > a::before,
        .nav-submenu-inner > .nav-sub-group > .nav-sub-master::before {
            content: '';
            position: absolute;
            left: -16px; /* Positioned exactly over the left border */
            top: 50%;
            transform: translateY(-50%);
            width: 7px;
            height: 7px;
            border-radius: 50%;
            border: 1px solid rgba(255, 255, 255, 0.6);
            background-color: #0b1930; /* Should match your sidebar background to look hollow */
            z-index: 2;
        }

        /* 3rd Level Items (Indented, darker background, no circle) */
        .nav-sub-menu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease-out;
            background-color: rgba(0, 0, 0, 0.15); /* Darker inset effect */
            margin-left: -12px; /* Pull back to edge */
        }
        
        .nav-sub-menu a {
            padding-left: 45px !important;
            position: relative;
            font-size: 12.5px;
        }
        
        .nav-sub-menu a::before {
            display: none !important; /* Hide circle for 3rd level */
        }

        /* Sub-master chevron animation */
        .nav-sub-master .chevron {
            width: 14px;
            transition: transform 0.3s ease;
        }
        .nav-sub-master.open .chevron {
            transform: rotate(180deg);
        }
    </style>
    <title><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?> | <?= htmlspecialchars($companyName) ?></title>
    
    <?php if ($logoType === 'image' && !empty($logoPath)): ?>
        <link rel="icon" href="/erp/<?= htmlspecialchars($logoPath) ?>" type="image/png">
    <?php else: ?>
        <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 38 38'><circle cx='19' cy='19' r='18' fill='%23ffffff' stroke='%231e3a8a' stroke-width='2'/><g transform='translate(19, 19) scale(0.32) translate(-38, -36)'><rect x='20' y='6' width='27' height='58' fill='%231e3a8a'/><rect x='3' y='23' width='17' height='22' fill='%231e3a8a'/><rect x='29' y='6' width='18' height='54' fill='%23ffffff'/><rect x='39' y='6' width='11' height='50' fill='%23ffffff'/><rect x='39' y='23' width='26' height='22' fill='%23ffffff'/></g><rect x='32' y='12' width='8' height='18' fill='%23ffffff'/><rect x='36' y='14' width='4' height='10' fill='%23ffffff'/></svg>" type="image/svg+xml">
    <?php endif; ?>

    <!-- Using only lighter weights (300, 400) -->
    <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400&family=Inter:wght@300;400&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <?= asset_css('style.css') ?>
</head>
<?php $__pgClass = 'pg-' . trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(basename(dirname($_SERVER['SCRIPT_NAME'])) . '-' . basename($_SERVER['SCRIPT_NAME'], '.php'))), '-'); ?>
<body class="app <?= $__pgClass ?>">

<div class="app-layout">
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="main-wrapper">
        <header class="top-header">
            <div class="header-left">
                <button class="btn-toggle" id="sidebarToggle" title="Toggle Sidebar">
                    <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"></line><line x1="3" y1="6" x2="21" y2="6"></line><line x1="3" y1="18" x2="21" y2="18"></line></svg>
                </button>
                <h2 class="page-title"><?= htmlspecialchars($pageTitle ?? 'Overview') ?> - <?= htmlspecialchars($companyName) ?></h2>
            </div>
            
            <div class="upper-menu">
                <?php 
                $userNameDisplay = 'User';
                $userEmpCode = 'N/A';
                $userEmail = 'Not Provided';
                $userMobile = 'Not Provided';
                $userHq = '';
                
                if (isset($_SESSION['user_id'])) {
                    try {
                        $userStmt = $pdo->prepare("
                            SELECT e.empcode, e.empname, e.email, e.mobile, e.hq 
                            FROM users u 
                            LEFT JOIN employees e ON (u.empcode = e.empcode OR u.username = e.empcode)
                            WHERE u.id = ? 
                            LIMIT 1
                        ");
                        $userStmt->execute([$_SESSION['user_id']]);
                        if ($row = $userStmt->fetch(PDO::FETCH_ASSOC)) {
                            $userHq = trim((string)($row['hq'] ?? ''));
                            if (!empty(trim((string)($row['empname'] ?? '')))) { $userNameDisplay = trim($row['empname']); }
                            if (!empty(trim((string)($row['empcode'] ?? '')))) { $userEmpCode = trim($row['empcode']); }
                            if (!empty(trim((string)($row['email'] ?? '')))) { $userEmail = trim($row['email']); }
                            if (!empty(trim((string)($row['mobile'] ?? '')))) { $userMobile = trim($row['mobile']); }
                        }
                    } catch (Exception $e) {}
                }
                ?>

                <!-- FULLSCREEN TOGGLE -->
                <button class="icon-btn" id="fullScreenBtn" title="Toggle Fullscreen">
                    <i class="fa-solid fa-expand"></i>
                </button>

                <!-- NOTIFICATION BELL -->
                <div class="notify-dropdown">
                    <button class="icon-btn" id="notifyBtn" title="Notifications">
                        <i class="fa-regular fa-bell"></i>
                        <?php if($notiCount > 0): ?>
                            <span class="badge-dot"><?= $notiCount > 9 ? '9+' : $notiCount ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notify-content" id="notifyContent">
                        <div class="notify-header">
                            <span>Notifications</span>
                        </div>
                        <div class="notify-items">
                            <?php if (empty($notifications)): ?>
                                <div class="notify-empty">No new notifications.</div>
                            <?php else: ?>
                                <?php foreach($notifications as $notif): ?>
                                    <a href="<?= htmlspecialchars($notif['link']) ?>">
                                        <i class="fa-solid <?= htmlspecialchars($notif['icon']) ?>" style="color:#ffffff; margin-right:6px;"></i> 
                                        <?= htmlspecialchars($notif['text']) ?>
                                        <span class="time"><?= htmlspecialchars($notif['time']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- PURE WHITE LOCATION BADGE -->
                <?php if (!empty($userHq)): ?>
                    <span class="role-badge" style="background:#ffffff; color:navy; border:none; padding:4px 10px;">
                        <i class="fa-solid fa-location-dot"></i> <?= htmlspecialchars($userHq); ?>
                    </span>
                <?php endif; ?>

                <span class="role-badge"><?= htmlspecialchars((string)($ctx['designation'] ?? $_SESSION['user_role'] ?? 'Staff')); ?></span>
                
                <!-- USER PROFILE NAVY DROPDOWN -->
                <div class="profile-dropdown">
                    <div class="user-profile-trigger" id="profileDropdownBtn" title="Profile Menu">
                        <div class="avatar-circle">
                            <i class="fa-regular fa-user"></i>
                        </div>
                    </div>
                    <div class="profile-dropdown-content" id="profileDropdownContent">
                        <!-- Employee Info Panel -->
                        <div class="profile-header-card">
                            <div class="profile-name"><?= htmlspecialchars($userNameDisplay); ?></div>
                            <div class="profile-code">ID: <?= htmlspecialchars($userEmpCode); ?></div>
                            <div class="profile-contact"><i class="fa-regular fa-envelope"></i> <?= htmlspecialchars($userEmail); ?></div>
                            <div class="profile-contact"><i class="fa-solid fa-phone"></i> <?= htmlspecialchars($userMobile); ?></div>
                        </div>
                        
                        <!-- Action Links -->
                        <a href="changepassword.php" class="menu-link"><i class="fa-solid fa-lock"></i> Change Password</a>
                        <a href="<?= BASE_URL ?>auth/logout.php" class="menu-link text-light-danger"><i class="fa-solid fa-arrow-right-from-bracket"></i> Secure Logout</a>
                    </div>
                </div>
            </div>
        </header>

        <main class="workspace">

        <script>
            document.addEventListener('DOMContentLoaded', () => {
                // Sidebar Toggle Logic
                const toggleBtn = document.getElementById('sidebarToggle');
                const sidebar = document.querySelector('.sidebar');
                const overlay = document.getElementById('sidebarOverlay');

                toggleBtn.addEventListener('click', () => {
                    if (window.innerWidth <= 900) { sidebar.classList.toggle('mobile-open'); overlay.classList.toggle('active'); } 
                    else { sidebar.classList.toggle('collapsed'); }
                });

                overlay.addEventListener('click', () => {
                    sidebar.classList.remove('mobile-open'); overlay.classList.remove('active');
                });

                // Menu Accordion Logic
                document.querySelectorAll('.nav-master').forEach(master => {
                    master.addEventListener('click', () => {
                        const submenu = master.nextElementSibling;
                        const isOpen = master.classList.contains('open');
                        
                        document.querySelectorAll('.nav-master').forEach(btn => {
                            btn.classList.remove('open');
                            if (btn.nextElementSibling) btn.nextElementSibling.style.maxHeight = null;
                        });
                        
                        if (!isOpen && submenu) {
                            master.classList.add('open');
                            submenu.style.maxHeight = "2000px"; 
                        }
                    });
                });

                // Profile & Notification Dropdown Logic
                const profileBtn = document.getElementById('profileDropdownBtn');
                const profileContent = document.getElementById('profileDropdownContent');
                const notifyBtn = document.getElementById('notifyBtn');
                const notifyContent = document.getElementById('notifyContent');
                
                function closeAllDropdowns() {
                    if(profileContent) profileContent.classList.remove('show');
                    if(notifyContent) notifyContent.classList.remove('show');
                }

                if (profileBtn) {
                    profileBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const isShowing = profileContent.classList.contains('show');
                        closeAllDropdowns();
                        if (!isShowing) profileContent.classList.add('show');
                    });
                }
                
                if (notifyBtn) {
                    notifyBtn.addEventListener('click', (e) => {
                        e.stopPropagation();
                        const isShowing = notifyContent.classList.contains('show');
                        closeAllDropdowns();
                        if (!isShowing) notifyContent.classList.add('show');
                    });
                }
                
                document.addEventListener('click', () => {
                    closeAllDropdowns();
                });

                // Full-Screen Logic
                const fsBtn = document.getElementById('fullScreenBtn');
                if (fsBtn) {
                    fsBtn.addEventListener('click', () => {
                        if (!document.fullscreenElement) {
                            document.documentElement.requestFullscreen().catch(err => {
                                console.log("Error attempting to enable fullscreen:", err.message);
                            });
                            fsBtn.innerHTML = '<i class="fa-solid fa-compress"></i>';
                        } else {
                            if (document.exitFullscreen) {
                                document.exitFullscreen();
                                fsBtn.innerHTML = '<i class="fa-solid fa-expand"></i>';
                            }
                        }
                    });
                }
            });
        </script>