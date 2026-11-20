<?php
declare(strict_types=1);
require __DIR__ . '/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}
verifyCsrf();

$action = postString('action');
if ($action === 'update_phone') {
    $student = requireRole('student');
    $phone = postString('phone');
    if ($phone !== '' && !preg_match('/^\+?[\d\s()-]{7,20}$/', $phone)) {
        flash('Enter a valid phone number, for example 0712345678.', 'error');
        redirect('index.php?page=dashboard');
    }
    $stmt = db()->prepare("UPDATE users SET phone=? WHERE id=? AND role='student'");
    $stmt->execute([$phone !== '' ? $phone : null, $student['id']]);
    flash('M-Pesa phone number updated.');
    redirect('index.php?page=dashboard');
}

$email = strtolower(postString('email'));
$password = $_POST['password'] ?? '';
if (!is_string($password)) {
    $password = '';
}

if ($action === 'register') {
    $name = postString('name');
    $phone = postString('phone');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        flash('Enter your name, a valid email address, and a password of at least 8 characters.', 'error');
        redirect('index.php?page=register');
    }

    try {
        $stmt = db()->prepare("INSERT INTO users (name,email,phone,password_hash,role) VALUES (?,?,?,?, 'student')");
        $stmt->execute([$name, $email, $phone !== '' ? $phone : null, password_hash($password, PASSWORD_DEFAULT)]);
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int) db()->lastInsertId(), 'name' => $name, 'email' => $email, 'role' => 'student'];
        flash('Your account is ready. Browse the course catalog to enroll.');
        redirect('index.php?page=dashboard');
    } catch (PDOException $exception) {
        if ($exception->getCode() === '23000') {
            flash('That email address is already registered. Please log in instead.', 'error');
            redirect('index.php?page=register');
        }
        error_log('Student registration failed: ' . $exception->getMessage());
        flash('Registration could not be completed. Please try again later.', 'error');
        redirect('index.php?page=register');
    }
}

if ($action === 'login') {
    $stmt = db()->prepare('SELECT id,name,email,password_hash,role,is_active FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $account = $stmt->fetch();
    if (!$account || !password_verify($password, $account['password_hash'])) {
        flash('The email or password is incorrect.', 'error');
        redirect('index.php?page=login');
    }
    if (!(int) $account['is_active']) {
        flash('This account is currently disabled. Please contact the school administrator.', 'error');
        redirect('index.php?page=login');
    }
    session_regenerate_id(true);
    $_SESSION['user'] = ['id' => (int) $account['id'], 'name' => $account['name'], 'email' => $account['email'], 'role' => $account['role']];
    redirect($account['role'] === 'admin' ? 'index.php?page=admin' : 'index.php?page=dashboard');
}

flash('Invalid request.', 'error');
redirect('index.php');
