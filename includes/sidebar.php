<?php 
$currentPage = basename($_SERVER['PHP_SELF']); 

$ctx = [];
if (isset($pdo) && function_exists('get_user_context')) {
    $ctx = get_user_context($pdo);
} else {
    $ctx = [
        'is_admin'   => (isset($_SESSION['user_role']) && strtoupper($_SESSION['user_role']) === 'ADMIN'),
        'is_manager' => false,
        'is_mr'      => true,
        'can_write'  => false,
        'empcode'    => $_SESSION['empcode'] ?? '',
        'designation'=> $_SESSION['designation'] ?? '',
        'user_role'  => $_SESSION['user_role'] ?? 'Staff',
    ];
}

$isAdmin   = !empty($ctx['is_admin']);
$isManager = !empty($ctx['is_manager']);
$isMR      = !empty($ctx['is_mr']);

$department = strtoupper(trim($_SESSION['department'] ?? ''));
$isHR       = $isAdmin || ($department === 'HR' || $department === 'ADMIN');

$sidebarPerms = [];
if (!$isAdmin && isset($pdo)) {
    try {
        $st = $pdo->prepare("SELECT page_name FROM page_permissions WHERE role_name IN (?, ?, ?) AND can_view = 1");
        $st->execute([$ctx['empcode'], $ctx['designation'], $ctx['user_role']]);
        $allowedPages = $st->fetchAll(PDO::FETCH_COLUMN);
        foreach ($allowedPages as $ap) {
            $sidebarPerms[strtolower(trim($ap))] = true;
        }
    } catch (Exception $e) {}
}

function showLink($pageFile, $isAdmin, $sidebarPerms) {
    if ($isAdmin || $pageFile === 'dashboard.php') return true;
    return isset($sidebarPerms[strtolower($pageFile)]);
}

$companyNameDisplay = $companyName ?? 'Company Name';
$logoTypeDisplay = $logoType ?? 'none';
$logoPathDisplay = $logoPath ?? '';
$logoHtmlDisplay = $logoHtml ?? '';
?>
<aside class="sidebar">
    <!-- GUARANTEED WHITE LOGO CONTAINER -->
    <div class="sidebar-brand" style="background-color: #ffffff !important; padding: 12px 10px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: center; align-items: center; min-height: 55px;">
        <?php if ($logoTypeDisplay === 'image' && !empty($logoPathDisplay)): ?>
            <div style="height: 44px; display:flex; align-items:center; justify-content:center; flex-shrink: 0; width: 100%;">
                <img src="<?= BASE_URL . ltrim(htmlspecialchars($logoPathDisplay), '/') ?>" alt="Logo" style="max-width: 100%; max-height: 100%; object-fit: contain;">
            </div>
        <?php elseif ($logoTypeDisplay === 'html' && !empty($logoHtmlDisplay)): ?>
            <div style="flex-shrink:0; display:flex; align-items:center; width: 100%;">
                <?= $logoHtmlDisplay ?>
            </div>
        <?php else: ?>
            <h3 class="brand-text" style="color: #1e3a8a; margin: 0;">
                <span class="name" style="font-weight: 600;"><?= htmlspecialchars($companyNameDisplay) ?></span><br>
                <span class="sub" style="color: #00aeea; font-size: 9px; text-transform: uppercase; letter-spacing: 0.08em;">Platform</span>
            </h3>
        <?php endif; ?>
    </div>

    <!-- MENU SEARCH -->
    <div class="sidebar-search-wrap">
        <div class="sidebar-search">
            <svg class="search-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="7"></circle>
                <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input type="text" id="menuSearch" placeholder="Search menu..." autocomplete="off" spellcheck="false">
            <button type="button" id="menuSearchClear" class="search-clear" title="Clear" style="display:none;">&times;</button>
        </div>
        <div id="menuSearchResults" class="menu-search-results" style="display:none;"></div>
    </div>
    
    <nav class="sidebar-nav" id="sidebarNav">
        
        <!-- CORE OPERATIONS -->
        <div class="nav-group" data-group="Core Operations">
            <button class="nav-master" type="button">
                <span>Core Operations</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="nav-submenu">
                <div class="nav-submenu-inner">
                    <?php if (showLink('dashboard.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>dashboard.php" data-keywords="dashboard home overview" class="<?= $currentPage == 'dashboard.php' ? 'active' : '' ?>">Dashboard</a> <?php endif; ?>
                    
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Billing & Orders <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('orders.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>billing/orders.php" data-keywords="order invoice approval" class="<?= $currentPage == 'orders.php' ? 'active' : '' ?>">Order Management</a> <?php endif; ?>
                            <?php if (showLink('partyrates.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>billing/partyrates.php" data-keywords="party rate ptr price" class="<?= $currentPage == 'partyrates.php' ? 'active' : '' ?>">Party Rates</a> <?php endif; ?>
                            <?php if (showLink('billing.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>billing/billing.php" data-keywords="billing invoice tax" class="<?= $currentPage == 'billing.php' ? 'active' : '' ?>">Billing / Invoice</a> <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Inventory & Ledger <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('dispatch.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>dispatch/dispatch.php" data-keywords="dispatch delivery" class="<?= $currentPage == 'dispatch.php' ? 'active' : '' ?>">Dispatch</a> <?php endif; ?>
                            <?php if (showLink('stock.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>inventory/stock.php" data-keywords="stock inventory batch" class="<?= $currentPage == 'stock.php' ? 'active' : '' ?>">Stock</a> <?php endif; ?>
                            <?php if (showLink('partyledger.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>accounts/partyledger.php" data-keywords="ledger collection" class="<?= $currentPage == 'partyledger.php' ? 'active' : '' ?>">Party Ledger</a> <?php endif; ?>
                            <?php if (showLink('collections.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>accounts/collections.php" data-keywords="collections receipt" class="<?= $currentPage == 'collections.php' ? 'active' : '' ?>">Collections</a> <?php endif; ?>
                            <?php if (showLink('outstanding.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>accounts/outstanding.php" data-keywords="outstanding due balance" class="<?= $currentPage == 'outstanding.php' ? 'active' : '' ?>">Outstanding</a> <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Sales Data & Reports <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('salesdata.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/salesdata.php" class="<?= $currentPage == 'salesdata.php' ? 'active' : '' ?>">Sales Data</a> <?php endif; ?>
                            <?php if (showLink('primary.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/primary.php" class="<?= $currentPage == 'primary.php' ? 'active' : '' ?>">Primary</a> <?php endif; ?>
                            <?php if (showLink('primarysales.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/primarysales.php" class="<?= $currentPage == 'primarysales.php' ? 'active' : '' ?>">Primary Sales Vs Ach.</a> <?php endif; ?>
                            <?php if (showLink('secondary.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/secondary.php" class="<?= $currentPage == 'secondary.php' ? 'active' : '' ?>">Secondary</a> <?php endif; ?>
                            <?php if (showLink('secondarysales.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/secondarysales.php" class="<?= $currentPage == 'secondarysales.php' ? 'active' : '' ?>">Secondary Sales vs Ach.</a> <?php endif; ?>
                            <?php if (showLink('achievements.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/achievements.php" class="<?= $currentPage == 'achievements.php' ? 'active' : '' ?>">Achievements</a> <?php endif; ?>
                            <?php if (showLink('targets.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/targets.php" class="<?= $currentPage == 'targets.php' ? 'active' : '' ?>">Monthly Targets</a> <?php endif; ?>
                            <?php if (showLink('salestrend.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>sales/salestrend.php" class="<?= $currentPage == 'salestrend.php' ? 'active' : '' ?>">Sales Trend</a> <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isAdmin || $isManager || $isMR): ?>
        <!-- FIELD FORCE -->
        <div class="nav-group" data-group="Field Force">
            <button class="nav-master" type="button">
                <span>Field Force</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="nav-submenu">
                <div class="nav-submenu-inner">
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Doctors & Chemists <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('doctors.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/doctors.php" class="<?= $currentPage == 'doctors.php' ? 'active' : '' ?>">Doctors</a> <?php endif; ?>
                            <?php if (($isAdmin || $isManager) && showLink('doctorrequestapproval.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/doctorrequestapproval.php" class="<?= $currentPage == 'doctorrequestapproval.php' ? 'active' : '' ?>">Doctor Approvals</a> <?php endif; ?>
                            <?php if (showLink('chemistrequest.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/chemistrequest.php" class="<?= $currentPage == 'chemistrequest.php' ? 'active' : '' ?>">Chemist Request</a> <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Tour Plan <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('tourplan.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/tourplan.php" class="<?= $currentPage == 'tourplan.php' ? 'active' : '' ?>">Tour Plan</a> <?php endif; ?>
                            <?php if (showLink('tourplancalendar.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/tourplancalendar.php" class="<?= $currentPage == 'tourplancalendar.php' ? 'active' : '' ?>">Tour Calendar</a> <?php endif; ?>
                            <?php if (($isAdmin || $isManager) && showLink('tourplanapproval.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/tourplanapproval.php" class="<?= $currentPage == 'tourplanapproval.php' ? 'active' : '' ?>">Tour Plan Approvals</a> <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Daily Operations <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('dailyvisit.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/dailyvisit.php" class="<?= $currentPage == 'dailyvisit.php' ? 'active' : '' ?>">Daily Visit Report</a> <?php endif; ?>
                            <?php if (showLink('secondaryentry.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/secondaryentry.php" class="<?= $currentPage == 'secondaryentry.php' ? 'active' : '' ?>">Secondary Entry</a> <?php endif; ?>
                            <?php if (showLink('salessupport.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/salessupport.php" class="<?= $currentPage == 'salessupport.php' ? 'active' : '' ?>">Sales Support</a> <?php endif; ?>
                            <?php if (showLink('routemap.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/routemap.php" class="<?= $currentPage == 'routemap.php' ? 'active' : '' ?>">Route Map</a> <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Inventory <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('mystock.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>inventory/mystock.php" class="<?= $currentPage == 'mystock.php' ? 'active' : '' ?>">My Sample Stock</a> <?php endif; ?>
                            <?php if (showLink('stockstatement.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>inventory/stockstatement.php" class="<?= $currentPage == 'stockstatement.php' ? 'active' : '' ?>">Stock Statement</a> <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Expense Claim <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('expense.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/expense.php" class="<?= $currentPage == 'expense.php' ? 'active' : '' ?>">Expense Claims</a> <?php endif; ?>
                            <?php if (($isAdmin || $isManager) && showLink('expenseapproval.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>fieldforce/expenseapproval.php" class="<?= $currentPage == 'expenseapproval.php' ? 'active' : '' ?>">Expense Approvals</a> <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- HR & PAYROLL -->
        <div class="nav-group" data-group="HR & Payroll">
            <button class="nav-master" type="button">
                <span>HR & Payroll</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="nav-submenu">
                <div class="nav-submenu-inner">
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Profile & Notification <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('profile.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/profile.php" class="<?= $currentPage == 'profile.php' ? 'active' : '' ?>">My Profile</a> <?php endif; ?>
                            <?php if (showLink('notifications.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>dashboard/notifications.php" class="<?= $currentPage == 'notifications.php' ? 'active' : '' ?>">Notifications</a> <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Attendance & Leave <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('attendance.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/attendance.php" class="<?= $currentPage == 'attendance.php' ? 'active' : '' ?>">Daily Attendance</a> <?php endif; ?>
                            <?php if (showLink('attendancereport.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/attendancereport.php" class="<?= $currentPage == 'attendancereport.php' ? 'active' : '' ?>">Attendance Report</a> <?php endif; ?>
                            <?php if (showLink('holiday.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/holiday.php" class="<?= $currentPage == 'holiday.php' ? 'active' : '' ?>">Holiday Calendar</a> <?php endif; ?>
                            <?php if (showLink('leave.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/leave.php" class="<?= $currentPage == 'leave.php' ? 'active' : '' ?>">Leave Management</a> <?php endif; ?>
                            <?php if (($isAdmin || $isManager || $isHR) && showLink('leaveapproval.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/leaveapproval.php" class="<?= $currentPage == 'leaveapproval.php' ? 'active' : '' ?>">Leave Approvals</a> <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Payroll <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('salary.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>payroll/salary.php" class="<?= $currentPage == 'salary.php' ? 'active' : '' ?>">Monthly Salary</a> <?php endif; ?>
                            <?php if (showLink('salaryslip.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>payroll/salaryslip.php" class="<?= $currentPage == 'salaryslip.php' ? 'active' : '' ?>">Salary Slip</a> <?php endif; ?>
                            <?php if (showLink('form16.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>payroll/form16.php" class="<?= $currentPage == 'form16.php' ? 'active' : '' ?>">Form 16</a> <?php endif; ?>
                            <?php if (showLink('fullfinal.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>payroll/fullfinal.php" class="<?= $currentPage == 'fullfinal.php' ? 'active' : '' ?>">Full & Final Settlement</a> <?php endif; ?>
                            <?php if (showLink('advance.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>payroll/advance.php" class="<?= $currentPage == 'advance.php' ? 'active' : '' ?>">Advance / Loan</a> <?php endif; ?>
                            <?php if (showLink('reimbursement.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>payroll/reimbursement.php" class="<?= $currentPage == 'reimbursement.php' ? 'active' : '' ?>">Reimbursement</a> <?php endif; ?>
                            <?php if (($isAdmin || $isHR) && showLink('tada.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>payroll/tada.php" class="<?= $currentPage == 'tada.php' ? 'active' : '' ?>">TA / DA Rules</a> <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Core HR <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('appraisal.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/appraisal.php" class="<?= $currentPage == 'appraisal.php' ? 'active' : '' ?>">Appraisal</a> <?php endif; ?>
                            <?php if (showLink('hrletters.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/hrletters.php" class="<?= $currentPage == 'hrletters.php' ? 'active' : '' ?>">HR Letters</a> <?php endif; ?>
                            <?php if (showLink('assets.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/assets.php" class="<?= $currentPage == 'assets.php' ? 'active' : '' ?>">Asset Management</a> <?php endif; ?>
                            <?php if (showLink('training.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/training.php" class="<?= $currentPage == 'training.php' ? 'active' : '' ?>">Training</a> <?php endif; ?>
                            <?php if (($isAdmin || $isHR) && showLink('resignedemployee.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/resignedemployee.php" class="<?= $currentPage == 'resignedemployee.php' ? 'active' : '' ?>">Resigned Employees</a> <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <!-- MASTER DATA -->
        <div class="nav-group" data-group="Master Data">
            <button class="nav-master" type="button">
                <span>Master Data</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="nav-submenu">
                <div class="nav-submenu-inner">
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">HR Masters <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('employees.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/employees.php" class="<?= $currentPage == 'employees.php' ? 'active' : '' ?>">Employee</a> <?php endif; ?>
                            <?php if (showLink('master.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/master.php" class="<?= $currentPage == 'master.php' ? 'active' : '' ?>">Departments & Roles</a> <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Geography & Logistics <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('geography.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/geography.php" class="<?= $currentPage == 'geography.php' ? 'active' : '' ?>">Geographic</a> <?php endif; ?>
                            <?php if (showLink('parties.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/parties.php" class="<?= $currentPage == 'parties.php' ? 'active' : '' ?>">Parties Master</a> <?php endif; ?>
                            <?php if (showLink('assignparty.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/assignparty.php" class="<?= $currentPage == 'assignparty.php' ? 'active' : '' ?>">Party Assignments</a> <?php endif; ?>
                            <?php if (showLink('transporters.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>dispatch/transporters.php" class="<?= $currentPage == 'transporters.php' ? 'active' : '' ?>">Transporters</a> <?php endif; ?>
                            <?php if (showLink('senders.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>dispatch/senders.php" class="<?= $currentPage == 'senders.php' ? 'active' : '' ?>">Dispatcher</a> <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">Products <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('products.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/products.php" class="<?= $currentPage == 'products.php' ? 'active' : '' ?>">Products</a> <?php endif; ?>
                            <?php if (showLink('brand.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/brand.php" class="<?= $currentPage == 'brand.php' ? 'active' : '' ?>">Brands</a> <?php endif; ?>
                            <?php if (showLink('src.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/src.php" class="<?= $currentPage == 'src.php' ? 'active' : '' ?>">SRC Master</a> <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- DATA MANAGEMENT -->
        <div class="nav-group" data-group="Data Management">
            <button class="nav-master" type="button">
                <span>Data Management</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="nav-submenu">
                <div class="nav-submenu-inner">
                    <?php if (showLink('masterupload.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>masters/masterupload.php" class="<?= $currentPage == 'masterupload.php' ? 'active' : '' ?>">Bulk Master Upload</a> <?php endif; ?>
                    <?php if (showLink('cop.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>admin/cop.php" class="<?= $currentPage == 'cop.php' ? 'active' : '' ?>">COP Settings</a> <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ADMINISTRATION -->
        <div class="nav-group" data-group="Administration">
            <button class="nav-master" type="button" style="color: #fca5a5;">
                <span>Administration</span>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </button>
            <div class="nav-submenu">
                <div class="nav-submenu-inner">
                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">System Maintenance <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('systemlogs.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>admin/systemlogs.php" class="<?= $currentPage == 'systemlogs.php' ? 'active' : '' ?>">System Logs</a> <?php endif; ?>
                            <?php if (showLink('permissions.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>admin/permissions.php" class="<?= $currentPage == 'permissions.php' ? 'active' : '' ?>">Permissions</a> <?php endif; ?>
                            <?php if (showLink('backup.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>admin/backup.php" class="<?= $currentPage == 'backup.php' ? 'active' : '' ?>">Backup</a> <?php endif; ?>
                            <?php if (showLink('users.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>admin/users.php" class="<?= $currentPage == 'users.php' ? 'active' : '' ?>">System Security</a> <?php endif; ?>
                        </div>
                    </div>

                    <div class="nav-sub-group">
                        <button class="nav-sub-master" type="button">HR Configuration <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
                        <div class="nav-sub-menu">
                            <?php if (showLink('shift.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>hr/shift.php" class="<?= $currentPage == 'shift.php' ? 'active' : '' ?>">Shift Management</a> <?php endif; ?>
                            <?php if (showLink('salarystructure.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>payroll/salarystructure.php" class="<?= $currentPage == 'salarystructure.php' ? 'active' : '' ?>">Salary Structure</a> <?php endif; ?>
                        </div>
                    </div>

                    <?php if (showLink('settings.php', $isAdmin, $sidebarPerms)): ?> <a href="<?= BASE_URL ?>admin/settings.php" class="<?= $currentPage == 'settings.php' ? 'active' : '' ?>">App Settings</a> <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </nav>
</aside>

<script>
(function() {
    // Top Level Menu Accordion
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

    // 2nd Level Menu Accordion (Tree Nodes)
    document.querySelectorAll('.nav-sub-master').forEach(subMaster => {
        subMaster.addEventListener('click', (e) => {
            e.stopPropagation();
            const subMenu = subMaster.nextElementSibling;
            const isOpen = subMaster.classList.contains('open');
            
            // Close other sub-menus in the same master section
            const parentInner = subMaster.closest('.nav-submenu-inner');
            if (parentInner) {
                parentInner.querySelectorAll('.nav-sub-master').forEach(btn => {
                    btn.classList.remove('open');
                    if(btn.nextElementSibling) btn.nextElementSibling.style.maxHeight = null;
                });
            }

            if (!isOpen && subMenu) {
                subMaster.classList.add('open');
                subMenu.style.maxHeight = subMenu.scrollHeight + "px";
            }
        });
    });

    // Automatically open active nested menus on page load
    document.querySelectorAll('.nav-sub-menu a.active').forEach(activeLink => {
        const subMenu = activeLink.closest('.nav-sub-menu');
        if (subMenu) {
            subMenu.style.maxHeight = "2000px";
            const subMaster = subMenu.previousElementSibling;
            if (subMaster) subMaster.classList.add('open');
            
            const masterMenu = subMenu.closest('.nav-submenu');
            if (masterMenu) {
                masterMenu.style.maxHeight = "2000px";
                const masterBtn = masterMenu.previousElementSibling;
                if (masterBtn) masterBtn.classList.add('open');
            }
        }
    });
    
    // Automatically open active 1st level menus
    document.querySelectorAll('.nav-submenu-inner > a.active').forEach(activeLink => {
        const masterMenu = activeLink.closest('.nav-submenu');
        if (masterMenu) {
            masterMenu.style.maxHeight = "2000px";
            const masterBtn = masterMenu.previousElementSibling;
            if (masterBtn) masterBtn.classList.add('open');
        }
    });

    // SEARCH LOGIC
    const input = document.getElementById('menuSearch');
    const clearBtn = document.getElementById('menuSearchClear');
    const resultsBox = document.getElementById('menuSearchResults');
    const nav = document.getElementById('sidebarNav');
    if (!input || !nav) return;

    function buildIndex() {
        const items = [];
        nav.querySelectorAll('.nav-group').forEach(group => {
            const groupName = group.getAttribute('data-group') ? group.getAttribute('data-group') : '';
            group.querySelectorAll('a[href]').forEach(a => {
                const label = (a.textContent ? a.textContent : '').trim();
                const href = a.getAttribute('href') ? a.getAttribute('href') : '';
                const keywords = (a.getAttribute('data-keywords') ? a.getAttribute('data-keywords') : '').toLowerCase();
                items.push({
                    label, href, group: groupName, keywords,
                    searchText: (label + ' ' + keywords + ' ' + groupName + ' ' + href.replace('.php','')).toLowerCase(),
                    el: a, groupEl: group
                });
            });
        });
        return items;
    }

    let index = buildIndex();
    let activeIdx = -1;

    function highlight(text, q) {
        if (!q) return text;
        const re = new RegExp('(' + q.replace(/[.*+?^${}()|[\\]\\\\]/g, '\\$&') + ')', 'ig');
        return text.replace(re, '<mark>$1</mark>');
    }

    function scoreMatch(item, q) {
        const label = item.label.toLowerCase();
        if (label === q) return 100;
        if (label.startsWith(q)) return 80;
        if (label.includes(q)) return 60;
        if (item.keywords.split(/\s+/).some(k => k.startsWith(q))) return 50;
        if (item.searchText.includes(q)) return 30;
        const words = q.split(/\s+/).filter(Boolean);
        if (words.length > 1 && words.every(w => item.searchText.includes(w))) return 40;
        return 0;
    }

    function runSearch(q) {
        q = (q ? q : '').trim().toLowerCase();
        activeIdx = -1;

        if (!q) {
            resultsBox.style.display = 'none';
            resultsBox.innerHTML = '';
            clearBtn.style.display = 'none';
            return;
        }

        clearBtn.style.display = 'block';
        index = buildIndex(); 

        const matches = index
            .map(item => ({ item, score: scoreMatch(item, q) }))
            .filter(x => x.score > 0)
            .sort((a, b) => {
                let diff = b.score - a.score;
                if (diff !== 0) return diff;
                return a.item.label.localeCompare(b.item.label);
            });

        if (!matches.length) {
            resultsBox.innerHTML = '<div class="msr-empty">No menu found for &ldquo;' + q.replace(/</g,'&lt;') + '&rdquo;</div>';
            resultsBox.style.display = 'block';
            return;
        }

        let html = '';
        let lastGroup = '';
        matches.slice(0, 12).forEach(({ item }, i) => {
            if (item.group !== lastGroup) {
                html += '<div class="msr-group">' + item.group + '</div>';
                lastGroup = item.group;
            }
            html += '<a class="msr-item" href="' + item.href + '" data-idx="' + i + '">' + highlight(item.label, q) + '</a>';
        });
        resultsBox.innerHTML = html;
        resultsBox.style.display = 'block';
    }

    input.addEventListener('input', () => runSearch(input.value));
    
    input.addEventListener('keydown', (e) => {
        const items = resultsBox.querySelectorAll('.msr-item');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIdx = Math.min(activeIdx + 1, items.length - 1);
            items.forEach((el, i) => el.classList.toggle('msr-active', i === activeIdx));
            if (items[activeIdx]) items[activeIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIdx = Math.max(activeIdx - 1, 0);
            items.forEach((el, i) => el.classList.toggle('msr-active', i === activeIdx));
            if (items[activeIdx]) items[activeIdx].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIdx >= 0 && items[activeIdx]) window.location.href = items[activeIdx].getAttribute('href');
            else if (items[0]) window.location.href = items[0].getAttribute('href');
        } else if (e.key === 'Escape') {
            input.value = ''; runSearch(''); input.blur();
        }
    });

    clearBtn.addEventListener('click', () => { input.value = ''; runSearch(''); input.focus(); });
    document.addEventListener('click', (e) => { if (!e.target.closest('.sidebar-search-wrap')) resultsBox.style.display = 'none'; });
})();
</script>