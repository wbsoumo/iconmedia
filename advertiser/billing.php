<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

define('APP_INIT', true);

require_once __DIR__ . '/../app/core/auth.php';
require_once __DIR__ . '/../app/config/database.php';

require_role('advertiser');

$advertiserId = auth_user_id();
$advertiserName = $_SESSION['user_name'] ?? 'Advertiser';
$success = $error = null;

/* -------------------------------------------------
   FETCH ADVERTISER DETAILS & BALANCE
-------------------------------------------------- */
$userStmt = $pdo->prepare("
    SELECT 
        u.user_id,
        u.name,
        u.email,
        u.balance,
        u.company
    FROM users u
    WHERE u.user_id = :uid AND u.role_id = 4
");
$userStmt->execute(['uid' => $advertiserId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    die('Invalid advertiser account');
}

/* -------------------------------------------------
   HANDLE DEPOSIT / ADD FUNDS REQUEST
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_funds') {
    $amount = floatval($_POST['amount']);
    $paymentMethodId = intval($_POST['payment_method_id'] ?? 0);
    $notes = trim($_POST['notes'] ?? '');

    if ($amount < 10) {
        $error = 'Minimum deposit amount is $10.00';
    } elseif ($amount > 50000) {
        $error = 'Maximum single deposit amount is $50,000.00';
    } else {
        try {
            // Check if advertiser_transactions table exists, else insert or fallback gracefully
            $txStmt = $pdo->prepare("
                INSERT INTO advertiser_transactions 
                (advertiser_id, type, amount, status, payment_method_id, description, created_at)
                VALUES (:uid, 'deposit', :amount, 'pending', :method_id, :notes, NOW())
            ");
            $txStmt->execute([
                'uid'       => $advertiserId,
                'amount'    => $amount,
                'method_id' => $paymentMethodId ?: null,
                'notes'     => $notes ?: 'Deposit request via Billing Portal'
            ]);
            $success = 'Deposit request of $' . number_format($amount, 2) . ' submitted successfully. Funds will reflect once confirmed.';
        } catch (Exception $e) {
            // Fallback balance credit / transaction logging
            $error = 'Transaction system busy. Please contact support or retry.';
        }
    }
}

/* -------------------------------------------------
   HANDLE ADD PAYMENT METHOD
-------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_payment') {
    $paymentMethod = trim($_POST['payment_method'] ?? '');
    $accountDetails = trim($_POST['account_details'] ?? '');
    $isDefault = isset($_POST['is_default']) ? 1 : 0;

    if ($paymentMethod === '' || $accountDetails === '') {
        $error = 'Please provide both payment method name and account details.';
    } else {
        try {
            if ($isDefault) {
                $pdo->prepare("UPDATE advertiser_payment_methods SET is_default = 0 WHERE advertiser_id = :uid")
                    ->execute(['uid' => $advertiserId]);
            }
            $pmStmt = $pdo->prepare("
                INSERT INTO advertiser_payment_methods 
                (advertiser_id, payment_method, account_details, is_default, is_verified, created_at)
                VALUES (:uid, :method, :details, :default, 0, NOW())
            ");
            $pmStmt->execute([
                'uid'     => $advertiserId,
                'method'  => $paymentMethod,
                'details' => $accountDetails,
                'default' => $isDefault
            ]);
            $success = 'Payment method added successfully!';
        } catch (Exception $e) {
            $error = 'Could not add payment method. Please check inputs.';
        }
    }
}

/* -------------------------------------------------
   FETCH PAYMENT METHODS
-------------------------------------------------- */
$paymentMethods = [];
try {
    $pmFetch = $pdo->prepare("
        SELECT * FROM advertiser_payment_methods 
        WHERE advertiser_id = :uid 
        ORDER BY is_default DESC, created_at DESC
    ");
    $pmFetch->execute(['uid' => $advertiserId]);
    $paymentMethods = $pmFetch->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $paymentMethods = [];
}

/* -------------------------------------------------
   FETCH INVOICES & TRANSACTIONS
-------------------------------------------------- */
$invoices = [];
try {
    $invFetch = $pdo->prepare("
        SELECT * FROM advertiser_invoices 
        WHERE advertiser_id = :uid 
        ORDER BY created_at DESC
    ");
    $invFetch->execute(['uid' => $advertiserId]);
    $invoices = $invFetch->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $invoices = [];
}

$transactions = [];
try {
    $txFetch = $pdo->prepare("
        SELECT * FROM advertiser_transactions 
        WHERE advertiser_id = :uid 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $txFetch->execute(['uid' => $advertiserId]);
    $transactions = $txFetch->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $transactions = [];
}

// Summary Metrics
$totalSpent = 0;
$totalDeposits = 0;
foreach ($transactions as $tx) {
    if ($tx['type'] === 'deposit' && $tx['status'] === 'approved') {
        $totalDeposits += $tx['amount'];
    } elseif ($tx['type'] === 'spend' || $tx['type'] === 'charge') {
        $totalSpent += $tx['amount'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing & Payments | Advertiser Panel | GVS Icon Media</title>
    
    <!-- Google Font: Source Sans Pro -->
    <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- AdminLTE 3 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --info-gradient: linear-gradient(135deg, #1e3c72 0%, #2a5298 100%);
            --warning-gradient: linear-gradient(135deg, #f7971e 0%, #ffd200 100%);
            --danger-gradient: linear-gradient(135deg, #ff416c 0%, #ff4b2b 100%);
        }
        
        .balance-card {
            background: var(--primary-gradient);
            color: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
            margin-bottom: 25px;
            position: relative;
            overflow: hidden;
        }

        .balance-card h2 {
            font-size: 42px;
            font-weight: 800;
            margin: 10px 0;
        }

        .card-custom {
            border-radius: 12px;
            border: none;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }

        .card-custom .card-header {
            background: white;
            border-bottom: 1px solid #f0f0f0;
            border-radius: 12px 12px 0 0;
            padding: 18px 25px;
        }

        .badge-status {
            padding: 6px 14px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 12px;
        }

        .badge-approved { background: rgba(40,167,69,0.15); color: #28a745; }
        .badge-pending { background: rgba(255,193,7,0.15); color: #ffc107; }
        .badge-rejected { background: rgba(220,53,69,0.15); color: #dc3545; }

        /* 2x2 Grid for Mobile Stat Boxes */
        @media (max-width: 767.98px) {
            .stat-boxes-row > [class*="col-"] {
                flex: 0 0 50% !important;
                max-width: 50% !important;
                padding-left: 6px !important;
                padding-right: 6px !important;
            }

            .small-box {
                margin-bottom: 12px !important;
            }

            .small-box .inner {
                padding: 12px 10px !important;
            }

            .small-box h3 {
                font-size: 18px !important;
                margin-bottom: 2px !important;
            }

            .small-box p {
                font-size: 11px !important;
            }

            .small-box .icon {
                display: none !important;
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
            <li class="nav-item d-none d-sm-inline-block">
                <a href="dashboard.php" class="nav-link">Dashboard</a>
            </li>
            <li class="nav-item d-none d-sm-inline-block">
                <a href="billing.php" class="nav-link active">Billing</a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item">
                <a class="nav-link" href="#" id="darkModeToggle"><i class="fas fa-moon"></i></a>
            </li>
        </ul>
    </nav>

    <!-- Sidebar -->
    <aside class="main-sidebar sidebar-dark-primary elevation-4">
        <a href="dashboard.php" class="brand-link text-center">
            <span class="brand-text font-weight-light" style="font-size: 1.5rem;">
                <i class="fas fa-chart-line mr-2"></i><strong>Advertiser</strong>
            </span>
        </a>
        <div class="sidebar">
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu">
                    <li class="nav-item">
                        <a href="dashboard.php" class="nav-link">
                            <i class="nav-icon fas fa-tachometer-alt"></i><p>Dashboard</p>
                        </a>
                    </li>
                    <li class="nav-header">CAMPAIGNS</li>
                    <li class="nav-item">
                        <a href="campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-bullhorn"></i><p>Manage Campaigns</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="offers.php" class="nav-link">
                            <i class="nav-icon fas fa-gift"></i><p>All Offers</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="create_offer.php" class="nav-link">
                            <i class="nav-icon fas fa-plus-circle"></i><p>Create New Offer</p>
                        </a>
                    </li>
                    <li class="nav-header">REPORTS & ANALYTICS</li>
                    <li class="nav-item">
                        <a href="reports_campaigns.php" class="nav-link">
                            <i class="nav-icon fas fa-chart-bar"></i><p>Campaign Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_conversions.php" class="nav-link">
                            <i class="fas fa-exchange-alt nav-icon"></i><p>Conversion Reports</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="reports_affiliates.php" class="nav-link">
                            <i class="nav-icon fas fa-users"></i><p>Affiliate Reports</p>
                        </a>
                    </li>
                    <li class="nav-header">TOOLS</li>
                    <li class="nav-item">
                        <a href="ip_whitelist.php" class="nav-link">
                            <i class="nav-icon fas fa-tower-broadcast"></i><p>IP Whitelist</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="postback.php" class="nav-link">
                            <i class="nav-icon fas fa-code"></i><p>Postback Manager</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="api.php" class="nav-link">
                            <i class="nav-icon fas fa-plug"></i><p>API Integration</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="optimization.php" class="nav-link">
                            <i class="nav-icon fas fa-rocket"></i><p>Optimization Tools</p>
                        </a>
                    </li>
                    <li class="nav-header">ACCOUNT</li>
                    <li class="nav-item">
                        <a href="profile.php" class="nav-link">
                            <i class="nav-icon fas fa-user"></i><p>Profile</p>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="billing.php" class="nav-link active">
                            <i class="nav-icon fas fa-wallet"></i><p>Billing & Payments</p>
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
                        <h1 class="m-0">Billing & Payment Management</h1>
                    </div>
                    <div class="col-sm-6">
                        <ol class="breadcrumb float-sm-right">
                            <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                            <li class="breadcrumb-item active">Billing</li>
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
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle mr-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle mr-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                <?php endif; ?>

                <!-- Balance & Top Actions Card -->
                <div class="balance-card">
                    <div class="row align-items-center">
                        <div class="col-md-7">
                            <span class="text-uppercase" style="letter-spacing: 1.5px; opacity: 0.85;">Current Account Balance</span>
                            <h2>$<?php echo number_format($user['balance'] ?? 0, 2); ?></h2>
                            <p class="mb-0"><i class="fas fa-shield-alt mr-1"></i> Pre-paid campaign budget reserved for active conversions</p>
                        </div>
                        <div class="col-md-5 text-md-right mt-3 mt-md-0">
                            <button class="btn btn-light btn-lg font-weight-bold px-4 shadow-sm" data-toggle="modal" data-target="#addFundsModal">
                                <i class="fas fa-plus-circle text-primary mr-2"></i>Add Funds
                            </button>
                            <button class="btn btn-outline-light btn-lg font-weight-bold px-4 ml-2" data-toggle="modal" data-target="#addPaymentModal">
                                <i class="fas fa-credit-card mr-2"></i>Payment Method
                            </button>
                        </div>
                    </div>
                </div>

                <!-- 2x2 Grid Summary Stat Boxes -->
                <div class="row stat-boxes-row mb-4">
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-info">
                            <div class="inner">
                                <h3>$<?php echo number_format($user['balance'] ?? 0, 2); ?></h3>
                                <p>Available Funds</p>
                            </div>
                            <div class="icon"><i class="fas fa-wallet"></i></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-success">
                            <div class="inner">
                                <h3>$<?php echo number_format($totalDeposits, 2); ?></h3>
                                <p>Total Deposits</p>
                            </div>
                            <div class="icon"><i class="fas fa-arrow-down"></i></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-warning">
                            <div class="inner">
                                <h3>$<?php echo number_format($totalSpent, 2); ?></h3>
                                <p>Total Campaign Spend</p>
                            </div>
                            <div class="icon"><i class="fas fa-chart-line"></i></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="small-box bg-gradient-danger">
                            <div class="inner">
                                <h3><?php echo count($paymentMethods); ?></h3>
                                <p>Payment Methods</p>
                            </div>
                            <div class="icon"><i class="fas fa-credit-card"></i></div>
                        </div>
                    </div>
                </div>

                <!-- Content Grid: Invoices & Transactions -->
                <div class="row">

                    <!-- Left Column: Transactions & Invoices -->
                    <div class="col-lg-8">
                        
                        <!-- Transaction History -->
                        <div class="card card-custom">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h3 class="card-title font-weight-bold"><i class="fas fa-history text-primary mr-2"></i>Recent Transactions</h3>
                                <button class="btn btn-sm btn-outline-secondary" onclick="location.reload()"><i class="fas fa-sync-alt mr-1"></i>Refresh</button>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($transactions)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-receipt fa-3x mb-3 text-light"></i>
                                        <p>No transaction history found yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Type</th>
                                                    <th>Amount</th>
                                                    <th>Status</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($transactions as $tx): ?>
                                                <tr>
                                                    <td>#TX-<?php echo $tx['id']; ?></td>
                                                    <td>
                                                        <span class="font-weight-bold text-capitalize">
                                                            <?php echo htmlspecialchars($tx['type']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="font-weight-bold <?php echo ($tx['type'] === 'deposit') ? 'text-success' : 'text-dark'; ?>">
                                                        <?php echo ($tx['type'] === 'deposit' ? '+' : '-') . '$' . number_format($tx['amount'], 2); ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge-status badge-<?php echo ($tx['status'] === 'approved') ? 'approved' : (($tx['status'] === 'pending') ? 'pending' : 'rejected'); ?>">
                                                            <?php echo ucfirst($tx['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-muted"><?php echo date('M d, Y H:i', strtotime($tx['created_at'])); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Invoices Table -->
                        <div class="card card-custom">
                            <div class="card-header">
                                <h3 class="card-title font-weight-bold"><i class="fas fa-file-invoice-dollar text-success mr-2"></i>Invoices & Receipts</h3>
                            </div>
                            <div class="card-body p-0">
                                <?php if (empty($invoices)): ?>
                                    <div class="text-center py-5 text-muted">
                                        <i class="fas fa-file-invoice fa-3x mb-3 text-light"></i>
                                        <p>No invoices generated yet.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover align-middle mb-0">
                                            <thead class="bg-light">
                                                <tr>
                                                    <th>Invoice ID</th>
                                                    <th>Amount</th>
                                                    <th>Method</th>
                                                    <th>Status</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($invoices as $inv): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($inv['invoice_id']); ?></strong></td>
                                                    <td class="font-weight-bold">$<?php echo number_format($inv['amount'], 2); ?></td>
                                                    <td><?php echo htmlspecialchars($inv['payment_method']); ?></td>
                                                    <td>
                                                        <span class="badge-status badge-<?php echo ($inv['status'] === 'paid') ? 'approved' : 'pending'; ?>">
                                                            <?php echo ucfirst($inv['status']); ?>
                                                        </span>
                                                    </td>
                                                    <td class="text-muted"><?php echo date('M d, Y', strtotime($inv['created_at'])); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>

                    <!-- Right Column: Payment Methods & Quick Add -->
                    <div class="col-lg-4">
                        
                        <!-- Saved Payment Methods -->
                        <div class="card card-custom">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h3 class="card-title font-weight-bold"><i class="fas fa-credit-card text-info mr-2"></i>Saved Payment Methods</h3>
                                <button class="btn btn-xs btn-primary" data-toggle="modal" data-target="#addPaymentModal"><i class="fas fa-plus"></i></button>
                            </div>
                            <div class="card-body">
                                <?php if (empty($paymentMethods)): ?>
                                    <p class="text-muted text-center py-3">No payment methods added yet.</p>
                                <?php else: ?>
                                    <?php foreach ($paymentMethods as $pm): ?>
                                        <div class="p-3 border rounded mb-3 d-flex align-items-center justify-content-between">
                                            <div>
                                                <h5 class="mb-1 font-weight-bold"><?php echo htmlspecialchars($pm['payment_method']); ?></h5>
                                                <small class="text-muted"><?php echo htmlspecialchars($pm['account_details']); ?></small>
                                            </div>
                                            <div>
                                                <?php if ($pm['is_default']): ?>
                                                    <span class="badge badge-primary">Default</span>
                                                <?php endif; ?>
                                                <span class="badge badge-<?php echo $pm['is_verified'] ? 'success' : 'warning'; ?>">
                                                    <?php echo $pm['is_verified'] ? 'Verified' : 'Pending'; ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <button class="btn btn-outline-primary btn-block mt-3" data-toggle="modal" data-target="#addPaymentModal">
                                    <i class="fas fa-plus mr-1"></i>Add New Method
                                </button>
                            </div>
                        </div>

                        <!-- Billing Support & Info -->
                        <div class="card card-custom bg-light">
                            <div class="card-body">
                                <h5><i class="fas fa-info-circle text-primary mr-2"></i>Need Billing Assistance?</h5>
                                <p class="text-muted small">For custom invoices, wire transfer instructions, or billing inquiries, please reach out to your dedicated account manager or finance support.</p>
                                <a href="profile.php" class="btn btn-sm btn-secondary btn-block">View Account Manager Details</a>
                            </div>
                        </div>

                    </div>

                </div>

            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="main-footer">
        <div class="float-right d-none d-sm-inline">
            <strong>Advertiser Panel v3.0</strong>
        </div>
        <strong>Copyright &copy; <?php echo date('Y'); ?> <a href="#">GVS Icon Media</a>.</strong> All rights reserved.
    </footer>
</div>

<!-- Modal: Add Funds -->
<div class="modal fade" id="addFundsModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-wallet text-primary mr-2"></i>Add Campaign Funds</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_funds">
                    <div class="form-group">
                        <label>Deposit Amount (USD) *</label>
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">$</span>
                            </div>
                            <input type="number" name="amount" class="form-control" placeholder="100.00" min="10" max="50000" step="0.01" required>
                        </div>
                        <small class="form-text text-muted">Minimum deposit amount is $10.00</small>
                    </div>

                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method_id" class="form-control">
                            <option value="0">Default Payment Gateway / Wire</option>
                            <?php foreach ($paymentMethods as $pm): ?>
                                <option value="<?php echo $pm['id']; ?>">
                                    <?php echo htmlspecialchars($pm['payment_method']); ?> (<?php echo htmlspecialchars($pm['account_details']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Notes / Reference (Optional)</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Transaction reference or notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary font-weight-bold">Submit Deposit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Add Payment Method -->
<div class="modal fade" id="addPaymentModal" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-credit-card text-info mr-2"></i>Add Payment Method</h5>
                <button type="button" class="close" data-dismiss="modal"><span>&times;</span></button>
            </div>
            <form method="post" action="">
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_payment">
                    <div class="form-group">
                        <label>Payment Type *</label>
                        <select name="payment_method" class="form-control" required>
                            <option value="">Select Payment Method</option>
                            <option value="PayPal">PayPal</option>
                            <option value="Stripe / Credit Card">Stripe / Credit Card</option>
                            <option value="Bank Wire Transfer">Bank Wire Transfer</option>
                            <option value="Cryptocurrency (USDT/BTC)">Cryptocurrency (USDT/BTC)</option>
                            <option value="Payoneer">Payoneer</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Account Details *</label>
                        <input type="text" name="account_details" class="form-control" placeholder="Email, Wallet address or Account details" required>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" name="is_default" value="1" class="form-check-input" id="is_default_check">
                        <label class="form-check-label" for="is_default_check">Set as default payment method</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success font-weight-bold">Save Payment Method</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.6.0/jquery.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>

</body>
</html>
