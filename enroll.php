<?php
declare(strict_types=1);
require __DIR__ . '/payment_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?page=courses');
}
verifyCsrf();
$student = requireRole('student');
$courseId = filter_var($_POST['course_id'] ?? null, FILTER_VALIDATE_INT);
$method = postString('method');
if (!$courseId || !in_array($method, ['', 'mpesa'], true)) {
    flash('Choose a valid course and payment method.', 'error');
    redirect('index.php?page=courses');
}

$courseStmt = db()->prepare('SELECT id FROM courses WHERE id=?');
$courseStmt->execute([$courseId]);
if (!$courseStmt->fetch()) {
    flash('That course is no longer available.', 'error');
    redirect('index.php?page=courses');
}

$pdo = db();
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare('INSERT INTO enrollments (student_id,course_id,status) VALUES (?,?,"pending") ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)');
    $insert->execute([$student['id'], $courseId]);
    $stmt = $pdo->prepare('SELECT id,status FROM enrollments WHERE student_id=? AND course_id=? FOR UPDATE');
    $stmt->execute([$student['id'], $courseId]);
    $enrollment = $stmt->fetch();
    $enrollmentId = (int) $enrollment['id'];
    $enrollmentStatus = $enrollment['status'];
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

if ($enrollmentStatus === 'active') {
    flash('You are already enrolled in this course.');
    redirect('index.php?page=dashboard');
}
if ($method === '') {
    flash('Course added. Complete your enrollment with M-Pesa.');
    redirect('index.php?page=dashboard');
}

$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT status FROM enrollments WHERE id=? AND student_id=? FOR UPDATE');
    $lock->execute([$enrollmentId, $student['id']]);
    $lockedEnrollment = $lock->fetch();
    if (!$lockedEnrollment || $lockedEnrollment['status'] === 'active') {
        $pdo->commit();
        flash('You are already enrolled in this course.');
        redirect('index.php?page=dashboard');
    }
    $expire = $pdo->prepare('UPDATE payments SET status="failed" WHERE enrollment_id=? AND status="pending" AND created_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
    $expire->execute([$enrollmentId]);
    $pending = $pdo->prepare('SELECT id FROM payments WHERE enrollment_id=? AND status="pending" LIMIT 1');
    $pending->execute([$enrollmentId]);
    if ($pending->fetch()) {
        $pdo->commit();
        flash('A payment is already pending for this enrollment. Check your M-Pesa prompt or wait for confirmation.', 'error');
        redirect('index.php?page=dashboard');
    }
    $reference = 'MASOMO-' . strtoupper(bin2hex(random_bytes(12)));
    $insertPayment = $pdo->prepare('INSERT INTO payments (enrollment_id,reference,method,amount,currency,status) VALUES (?,?,?,1,"KES","pending")');
    $insertPayment->execute([$enrollmentId, $reference, $method]);
    $paymentId = (int) $pdo->lastInsertId();
    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

try {
    $phoneStmt = $pdo->prepare("SELECT phone FROM users WHERE id=? AND role='student'");
    $phoneStmt->execute([$student['id']]);
    $phone = preg_replace('/\D+/', '', (string) $phoneStmt->fetchColumn());
    if (str_starts_with($phone, '0')) {
        $phone = '254' . substr($phone, 1);
    } elseif (str_starts_with($phone, '+')) {
        $phone = substr($phone, 1);
    }
    if (!preg_match('/^254[17]\d{8}$/', $phone)) {
        throw new RuntimeException('Add a valid Kenyan mobile number to your account before using M-Pesa.');
    }
    $callback = (string) envValue('MPESA_CALLBACK_URL', '');
    if (!filter_var($callback, FILTER_VALIDATE_URL) || strpos($callback, 'https://') !== 0) {
        throw new RuntimeException('Set MPESA_CALLBACK_URL to a public HTTPS callback URL (for local development, use ngrok).');
    }
    $timestamp = mpesaTimestamp();
    $response = providerRequest(mpesaBaseUrl() . '/mpesa/stkpush/v1/processrequest', 'POST', [
        'Authorization: Bearer ' . mpesaAccessToken(),
        'Content-Type: application/json',
    ], [
        'BusinessShortCode' => (string) envValue('MPESA_SHORTCODE', ''),
        'Password' => mpesaPassword($timestamp),
        'Timestamp' => $timestamp,
        'TransactionType' => 'CustomerPayBillOnline',
        'Amount' => 1,
        'PartyA' => $phone,
        'PartyB' => (string) envValue('MPESA_SHORTCODE', ''),
        'PhoneNumber' => $phone,
        'CallBackURL' => $callback,
        'AccountReference' => substr($reference, 0, 12),
        'TransactionDesc' => 'Masomo course enrollment',
    ]);
    if ((string) ($response['ResponseCode'] ?? '') !== '0' || empty($response['CheckoutRequestID'])) {
        throw new RuntimeException((string) ($response['ResponseDescription'] ?? 'M-Pesa could not start the payment.'));
    }
    $save = $pdo->prepare('UPDATE payments SET checkout_request_id=?,merchant_request_id=?,provider_response=? WHERE id=? AND status="pending"');
    $save->execute([
        (string) $response['CheckoutRequestID'],
        isset($response['MerchantRequestID']) ? (string) $response['MerchantRequestID'] : null,
        json_encode(['ResponseCode' => $response['ResponseCode'], 'ResponseDescription' => $response['ResponseDescription'] ?? 'STK push sent'], JSON_THROW_ON_ERROR),
        $paymentId,
    ]);
    if (envValue('MPESA_ENV', 'sandbox') === 'sandbox') {
        flash('Sandbox request accepted. Use the local simulation button on your dashboard to test enrollment; no money will be charged.');
    } else {
        flash('M-Pesa prompt sent. Approve the KSh 1 payment on your phone, then refresh this page after confirmation.');
    }
    redirect('index.php?page=dashboard');
} catch (Throwable $exception) {
    error_log('Payment initialization failed: ' . $exception->getMessage());
    $fail = $pdo->prepare('UPDATE payments SET status="failed" WHERE id=? AND status="pending"');
    $fail->execute([$paymentId]);
    flash($exception instanceof RuntimeException ? $exception->getMessage() : 'Payment could not be started. Please try again.', 'error');
    redirect('index.php?page=dashboard');
}
