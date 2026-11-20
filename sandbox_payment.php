<?php
declare(strict_types=1);
require __DIR__ . '/payment_helpers.php';

if (!sandboxPaymentSimulationEnabled()) {
    http_response_code(404);
    exit('Not found');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php?page=dashboard');
}
verifyCsrf();
$student = requireRole('student');
$paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
if (!$paymentId) {
    flash('Choose a valid pending sandbox payment.', 'error');
    redirect('index.php?page=dashboard');
}

$stmt = db()->prepare(
    'SELECT p.id,p.reference FROM payments p
     JOIN enrollments e ON e.id=p.enrollment_id
     WHERE p.id=? AND e.student_id=? AND p.method="mpesa" AND p.status="pending" AND p.checkout_request_id IS NOT NULL'
);
$stmt->execute([$paymentId, $student['id']]);
$payment = $stmt->fetch();
if (!$payment) {
    flash('No pending sandbox M-Pesa payment was found.', 'error');
    redirect('index.php?page=dashboard');
}

$simulationReference = 'SANDBOX-TEST-' . strtoupper(bin2hex(random_bytes(6)));
updatePaymentStatus((int) $payment['id'], true, $simulationReference, [
    'environment' => 'sandbox',
    'simulated' => true,
    'charged' => false,
]);

$fresh = paymentByReference((string) $payment['reference'], 'mpesa');
if ($fresh && $fresh['provider_reference'] === $simulationReference) {
    flash('Sandbox payment simulated successfully. No money was charged.');
} elseif ($fresh && $fresh['status'] === 'paid') {
    flash('Payment was already confirmed.');
} else {
    flash('The sandbox payment could not be completed. Please refresh and try again.', 'error');
}
redirect('index.php?page=dashboard');
