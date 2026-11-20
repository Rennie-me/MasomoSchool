<?php
declare(strict_types=1);
require_once __DIR__ . '/app.php';

function providerRequest(string $url, string $method, array $headers, ?array $payload = null): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize the payment request.');
    }
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($body === false) {
        throw new RuntimeException('Payment provider request failed: ' . $error);
    }
    $data = json_decode($body, true);
    if (!is_array($data) || $status < 200 || $status >= 300) {
        $providerMessage = is_array($data)
            ? ($data['errorMessage'] ?? $data['error_description'] ?? $data['ResponseDescription'] ?? $data['message'] ?? '')
            : trim((string) $body);
        $detail = $providerMessage !== ''
            ? ' ' . substr((string) $providerMessage, 0, 200)
            : '';
        throw new RuntimeException('Payment provider returned HTTP ' . $status . '.' . $detail);
    }
    return $data;
}

function paymentByReference(string $reference, ?string $method = null): ?array
{
    $sql = 'SELECT p.*, e.student_id, e.course_id FROM payments p JOIN enrollments e ON e.id=p.enrollment_id WHERE p.reference=?';
    $params = [$reference];
    if ($method !== null) {
        $sql .= ' AND p.method=?';
        $params[] = $method;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $payment = $stmt->fetch();
    return $payment ?: null;
}

function updatePaymentStatus(int $paymentId, bool $successful, ?string $providerReference, ?array $details = null): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT enrollment_id FROM payments WHERE id=?');
        $stmt->execute([$paymentId]);
        $enrollmentId = $stmt->fetchColumn();
        if ($enrollmentId === false) {
            throw new RuntimeException('Payment record no longer exists.');
        }
        $enrollmentLock = $pdo->prepare('SELECT id FROM enrollments WHERE id=? FOR UPDATE');
        $enrollmentLock->execute([$enrollmentId]);
        if (!$enrollmentLock->fetch()) {
            throw new RuntimeException('Enrollment record no longer exists.');
        }
        $stmt = $pdo->prepare('SELECT id,enrollment_id,status FROM payments WHERE id=? FOR UPDATE');
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        if (!$payment) {
            throw new RuntimeException('Payment record no longer exists.');
        }
        if ($payment['status'] === 'pending') {
            $status = $successful ? 'paid' : 'failed';
            $update = $pdo->prepare('UPDATE payments SET status=?,provider_reference=COALESCE(?,provider_reference),provider_response=? WHERE id=? AND status="pending"');
            $update->execute([$status, $providerReference, $details ? json_encode($details, JSON_THROW_ON_ERROR) : null, $paymentId]);
            if ($successful) {
                $activate = $pdo->prepare('UPDATE enrollments SET status="active" WHERE id=?');
                $activate->execute([$payment['enrollment_id']]);
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function mpesaBaseUrl(): string
{
    return envValue('MPESA_ENV', 'sandbox') === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

function mpesaAccessToken(): string
{
    $key = envValue('MPESA_CONSUMER_KEY', '');
    $secret = envValue('MPESA_CONSUMER_SECRET', '');
    if ($key === '' || $secret === '') {
        throw new RuntimeException('M-Pesa credentials are not configured.');
    }
    $curl = curl_init(mpesaBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials');
    if ($curl === false) {
        throw new RuntimeException('Unable to initialize M-Pesa authentication.');
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD => $key . ':' . $secret,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $data = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($data) || $status < 200 || $status >= 300 || empty($data['access_token'])) {
        throw new RuntimeException('M-Pesa authentication failed: ' . $error);
    }
    return (string) $data['access_token'];
}

function mpesaTimestamp(): string
{
    return date('YmdHis');
}

function mpesaPassword(string $timestamp): string
{
    return base64_encode((string) envValue('MPESA_SHORTCODE', '') . (string) envValue('MPESA_PASSKEY', '') . $timestamp);
}

function verifyMpesaCheckout(string $checkoutRequestId): array
{
    $timestamp = mpesaTimestamp();
    $shortcode = (string) envValue('MPESA_SHORTCODE', '');
    return providerRequest(mpesaBaseUrl() . '/mpesa/stkpushquery/v1/query', 'POST', [
        'Authorization: Bearer ' . mpesaAccessToken(),
        'Content-Type: application/json',
    ], [
        'BusinessShortCode' => $shortcode,
        'Password' => mpesaPassword($timestamp),
        'Timestamp' => $timestamp,
        'CheckoutRequestID' => $checkoutRequestId,
    ]);
}
