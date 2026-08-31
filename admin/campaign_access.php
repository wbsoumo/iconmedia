<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);
require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('admin');

$adminId = $_SESSION['user_name'] ?? 'Admin';
$adminName = $_SESSION['user_name'] ?? 'Admin';
$success = $error = null;

/* ===============================
   ASSIGN OFFER TO AFFILIATE
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['assign_offer'])) {
        $affiliateId = (int)($_POST['affiliate_id'] ?? 0);
        $offerId = (int)($_POST['offer_id'] ?? 0);
        $payoutType = $_POST['payout_type'] ?? 'default';
        $customPayout = isset($_POST['custom_payout']) && $_POST['custom_payout'] !== '' ? (float)$_POST['custom_payout'] : null;
        $notes = trim($_POST['notes'] ?? '');

        if (!$affiliateId || !$offerId) {
            $error = 'Publisher and Offer are required';
        } else {
            try {
                $checkStmt = $pdo->prepare("SELECT id FROM affiliate_offer_approval WHERE affiliate_id = ? AND offer_id = ?");
                $checkStmt->execute([$affiliateId, $offerId]);
                
                if ($checkStmt->fetch()) {
                    $error = 'This offer is already assigned to this publisher';
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO affiliate_offer_approval
                            (affiliate_id, offer_id, status, payout_type, custom_payout, notes, created_by, approved_at)
                        VALUES
                            (:affiliate_id, :offer_id, 'approved', :payout_type, :custom_payout, :notes, :created_by, NOW())
                    ");

                    $stmt->execute([
                        'affiliate_id' => $affiliateId,
                        'offer_id' => $offerId,
                        'payout_type' => $payoutType,
                        'custom_payout' => $customPayout,
                        'notes' => $notes,
                        'created_by' => $adminId
                    ]);

                    $success = 'Campaign access granted successfully!';
                }
            } catch (PDOException $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
    
    // Bulk status update
    if (isset($_POST['bulk_status'])) {
        $selectedAssignments = $_POST['selected_assignments'] ?? [];
        $bulkStatus = $_POST['bulk_status'];
        
        if (!empty($selectedAssignments)) {
            $placeholders = implode(',', array_fill(0, count($selectedAssignments), '?'));
            $stmt = $pdo->prepare("UPDATE affiliate_offer_approval SET status = ?, approved_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([$bulkStatus], $selectedAssignments));
            $success = count($selectedAssignments) . ' access permission(s) updated to ' . ucfirst($bulkStatus);
        }
    }

    // Individual action
    if (isset($_POST['update_status'])) {
        $approvalId = (int)($_POST['approval_id'] ?? 0);
        $newStatus = $_POST['status'] ?? '';
        if ($approvalId && in_array($newStatus, ['approved', 'rejected'], true)) {
            $stmt = $pdo->prepare("UPDATE affiliate_offer_approval SET status = ?, approved_at = NOW() WHERE id = ?");
            $stmt->execute([$newStatus, $approvalId]);
            $success = 'Access status updated to ' . ucfirst($newStatus);
        }
    }
}

/* ===============================
   FETCH AFFILIATES & OFFERS
================================ */
$affiliates = $pdo->query("SELECT user_id, name, email FROM users WHERE role_id = 3 AND status = 'active' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$offers = $pdo->query("SELECT offer_id, offer_name, payout, status FROM offers ORDER BY offer_name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* ===============================
   FETCH ALL ACCESS ASSIGNMENTS
================================ */
$sql = "
    SELECT 
        aoa.id,
        aoa.status,
        aoa.payout_type,
        aoa.custom_payout,
        aoa.notes,
        aoa.created_at,
        u.user_id as affiliate_id,
        u.name AS affiliate_name,
        u.email AS affiliate_email,
        o.offer_id,
        o.offer_name,
        o.payout as original_payout
    FROM affiliate_offer_approval aoa
    INNER JOIN users u ON u.user_id = aoa.affiliate_id
    INNER JOIN offers o ON o.offer_id = aoa.offer_id
    ORDER BY aoa.id DESC
";
$assignments = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

// Totals
$stats = [
    'total' => count($assignments),
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0
];
foreach ($assignments as $as) {
    if ($as['status'] === 'approved') $stats['approved']++;
    elseif ($as['status'] === 'pending') $stats['pending']++;
    elseif ($as['status'] === 'rejected') $stats['rejected']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Campaign Access Permissions | Admin Panel</title>
    
    <!-- Google Font -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,600,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    <!-- Select2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    
    <style>
        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 4px 18px rgba(0,0,0,0.06);
            margin-bottom: 25px;
            background: #ffffff;
        }

        .stat-card-custom {
            border-radius: 12px;
            background: #ffffff;
            padding: 20px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.03);
            text-align: center;
        }

        .stat-card-custom .stat-number {
            font-size: 26px;
            font-weight: 800;
            color: #1e293b;
        }

        .stat-card-custom .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            text-transform: uppercase;
        }

        @media (max-width: 767.98px) {
            .stat-boxes-row > [class*="col-"] {
                flex: 0 0 50% !important;
                max-width: 50% !important;
                padding-left: 6px !important;
                padding-right: 6px !important;
            }
        }
    </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
            <li class="nav-item d-none d-sm-inline-block"><a href="dashboard.php" class="nav-link">Dashboard</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="campaigns.php" class="nav-link">Campaigns</a></li>
            <li class="nav-item d-none d-sm-inline-block"><a href="campaign_access.php" class="nav-link active">Campaign Access</a></li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-crown mr-2"></i><strong>Admin</strong>
            </span>
        </a>

        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-line"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>

                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i>
                            <p>Manage Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_campaign.php" class="nav-link">
                            <i class="nav-icon fas fa-plus"></i>
                            <p>Create Campaign</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="campaign_access.php" class="nav-link active">
                            <i class="nav-icon fas fa-key"></i>
                            <p>Campaign Access</p>
                        </a>
                    </li>

                    <li class="nav-header">REPORTS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i>
                            <p>Campaign Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i>
                            <p>Affiliate Report</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-building"></i>
                            <p>Advertiser Report</p>
                        </a>
                    </li>

                    <li class="nav-header">PUBLISHERS</li>
                    <li class="nav-item">
                        <a href="publishers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-friends"></i>
                            <p>Manage Publishers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="publisher_postbacks.php" class="nav-link">
                            <i class="nav-icon fas fa-link"></i>
                            <p>Publisher Postbacks</p>
                        </a>
                    </li>

                    <li class="nav-header">ADVERTISERS</li>
                    <li class="nav-item">
                        <a href="advertisers.php" class="nav-link">
                            <i class="nav-icon fas fa-briefcase"></i>
                            <p>Manage Advertisers</p>
                        </a>
                    </li>

                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="account_managers.php" class="nav-link">
                            <i class="nav-icon fas fa-user-tie"></i>
                            <p>Account Managers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-cog"></i>
                            <p>Settings</p>
                        </a>
                    </li>
                </ul>
            </nav>
        </div>
    </aside>

    <!-- Content Wrapper -->
    <div class="content-wrapper">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0 font-weight-bold">Campaign Access Permissions & Approvals</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Campaign Access</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">
            <div class="container-fluid">

                <!-- Alert Messages -->
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle mr-2"></i><?php echo htmlspecialchars($error); ?>
                    <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
                </div>
                <?php endif; ?>

                <!-- Summary Stat Cards (2x2 Mobile Responsive Grid) -->
                <div class="row mb-4 stat-boxes-row">
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-primary"><?php echo number_format($stats['total']); ?></div>
                            <div class="stat-label">Total Permissions</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-success"><?php echo number_format($stats['approved']); ?></div>
                            <div class="stat-label">Approved Access</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-warning"><?php echo number_format($stats['pending']); ?></div>
                            <div class="stat-label">Pending Review</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="stat-card-custom">
                            <div class="stat-number text-danger"><?php echo number_format($stats['rejected']); ?></div>
                            <div class="stat-label">Blocked / Rejected</div>
                        </div>
                    </div>
                </div>

                <!-- Grant New Access Card -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-key mr-2"></i>Grant Private Offer Access</h4>
                    <form method="post">
                        <input type="hidden" name="assign_offer" value="1">
                        <div class="row">
                            <div class="col-md-5">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Select Publisher <span class="text-danger">*</span></label>
                                    <select name="affiliate_id" class="form-control select2" required>
                                        <option value="">Choose Publisher...</option>
                                        <?php foreach ($affiliates as $aff): ?>
                                        <option value="<?php echo $aff['user_id']; ?>">
                                            <?php echo htmlspecialchars($aff['name']); ?> (<?php echo htmlspecialchars($aff['email']); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="form-group mb-3">
                                    <label class="font-weight-bold">Select Campaign Offer <span class="text-danger">*</span></label>
                                    <select name="offer_id" class="form-control select2" required>
                                        <option value="">Choose Campaign...</option>
                                        <?php foreach ($offers as $of): ?>
                                        <option value="<?php echo $of['offer_id']; ?>">
                                            #<?php echo $of['offer_id']; ?> - <?php echo htmlspecialchars($of['offer_name']); ?> ($<?php echo number_format($of['payout'], 2); ?>)
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-2 d-flex align-items-end mb-3">
                                <button type="submit" class="btn btn-success btn-block font-weight-bold shadow-sm">
                                    <i class="fas fa-check mr-1"></i> Grant Access
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Access Approvals Directory -->
                <div class="card card-custom p-4">
                    <h4 class="font-weight-bold text-primary mb-3"><i class="fas fa-list-check mr-2"></i>Publisher Campaign Access Registry</h4>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle" id="accessDataTable">
                            <thead class="thead-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Publisher</th>
                                    <th>Campaign Offer</th>
                                    <th>Payout Rate</th>
                                    <th>Status</th>
                                    <th>Date Granted</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assignments as $as): ?>
                                <tr>
                                    <td><strong>#<?php echo $as['id']; ?></strong></td>
                                    <td>
                                        <strong class="text-dark font-weight-bold"><?php echo htmlspecialchars($as['affiliate_name']); ?></strong>
                                        <small class="d-block text-muted"><?php echo htmlspecialchars($as['affiliate_email']); ?></small>
                                    </td>
                                    <td>
                                        <strong class="text-primary">#<?php echo $as['offer_id']; ?> - <?php echo htmlspecialchars($as['offer_name']); ?></strong>
                                    </td>
                                    <td>
                                        <strong class="text-success">$<?php echo number_format($as['custom_payout'] ?? $as['original_payout'], 2); ?></strong>
                                    </td>
                                    <td>
                                        <?php 
                                        $stClass = 'secondary';
                                        if ($as['status'] === 'approved') $stClass = 'success';
                                        elseif ($as['status'] === 'pending') $stClass = 'warning';
                                        elseif ($as['status'] === 'rejected') $stClass = 'danger';
                                        ?>
                                        <span class="badge badge-<?php echo $stClass; ?> p-2"><?php echo ucfirst($as['status']); ?></span>
                                    </td>
                                    <td><small class="text-muted"><?php echo date('Y-m-d H:i', strtotime($as['created_at'])); ?></small></td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="approval_id" value="<?php echo $as['id']; ?>">
                                            <input type="hidden" name="update_status" value="1">
                                            <?php if ($as['status'] !== 'approved'): ?>
                                            <button type="submit" name="status" value="approved" class="btn btn-sm btn-outline-success mr-1" title="Approve Access"><i class="fas fa-check"></i></button>
                                            <?php endif; ?>
                                            <?php if ($as['status'] !== 'rejected'): ?>
                                            <button type="submit" name="status" value="rejected" class="btn btn-sm btn-outline-danger" title="Block Access"><i class="fas fa-ban"></i></button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline"><strong>Admin Panel v3.0</strong></div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- SCRIPTS -->
<script src="https://code.jquery.com/jquery-3.6.4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap4.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    $('.select2').select2({ theme: 'bootstrap4', width: '100%' });
    $('#accessDataTable').DataTable({
        pageLength: 10,
        responsive: true,
        order: [[0, 'desc']]
    });
});
</script>
</body>
</html>