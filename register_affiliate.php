<?php
/**
 * Affiliate Registration
 * PHP 7.1+
 */

define('APP_INIT', true);

require_once __DIR__ . '/app/core/register.php';

$error   = null;
$success = false;

// If already logged in, block access
if (isset($_SESSION['auth'])) {
    header('Location: /');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Trim all inputs
    $data = [
        'name'     => isset($_POST['name']) ? trim($_POST['name']) : '',
        'email'    => isset($_POST['email']) ? trim($_POST['email']) : '',
        'password' => isset($_POST['password']) ? $_POST['password'] : ''
    ];

    $result = register_user('affiliate', $data);

    if ($result['success']) {
        $success = true;
    } else {
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Affiliate Registration</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body>

<h2>Affiliate Sign Up</h2>

<?php if ($error): ?>
    <p style="color:red;">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    </p>
<?php endif; ?>

<?php if ($success): ?>
    <p style="color:green;">
        Registration successful. Your account is pending approval.
    </p>
<?php else: ?>
<form method="post" autocomplete="off">

    <label>
        Full Name<br>
        <input type="text" name="name" required>
    </label>
    <br><br>

    <label>
        Email Address<br>
        <input type="email" name="email" required>
    </label>
    <br><br>

    <label>
        Password (min 8 chars)<br>
        <input type="password" name="password" required>
    </label>
    <br><br>

    <button type="submit">Create Affiliate Account</button>

</form>
<?php endif; ?>

</body>
</html>
