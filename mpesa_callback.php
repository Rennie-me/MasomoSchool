<?php
declare(strict_types=1);
require __DIR__ . '/payment_helpers.php';

header('Content-Type: application/json; charset=utf-8');
$raw = file_get_contents('php://input');
$payload = is_string($raw) ? json_decode($raw, true) : null;
$callback = $payload['Body']['stkCallback'] ?? null;
$checkoutId = is_array($callback) ? ($callback['CheckoutRequestID'] ?? null) : null;
$callbackResult = is_array($callback) ? ($callback['ResultCode'] ?? null) : null;

if (!is_string($checkoutId) || $checkoutId === '' || !is_numeric($callbackResult)) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Invalid callback payload']);
    exit;
}

$lookup = db()->prepare('SELECT id,status,merchant_request_id FROM payments WHERE checkout_request_id=? AND method="mpesa"');
$lookup->execute([$checkoutId]);
$payment = $lookup->fetch();
if ($payment && isset($callback['MerchantRequestID']) && $payment['merchant_request_id'] !== null
    && !hash_equals((string) $payment['merchant_request_id'], (string) $callback['MerchantRequestID'])) {
    http_response_code(400);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Callback request does not match']);
    exit;
}
if (!$payment || $payment['status'] !== 'pending') {
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Callback already handled or payment not found']);
    exit;
}

try {
    $verification = verifyMpesaCheckout($checkoutId);
    $queryResult = $verification['ResultCode'] ?? null;
    if ((string) ($verification['ResponseCode'] ?? '') !== '0' || !is_numeric($queryResult) || (int) $queryResult !== (int) $callbackResult) {
        throw new RuntimeException('M-Pesa query response did not match the callback result.');
    }

    $successful = (int) $queryResult === 0;
    $receipt = null;
    $items = $callback['CallbackMetadata']['Item'] ?? [];
    if (is_array($items)) {
        foreach ($items as $item) {
            if (($item['Name'] ?? '') === 'MpesaReceiptNumber' && isset($item['Value'])) {
                $receipt = (string) $item['Value'];
                break;
            }
        }
    }
    updatePaymentStatus((int) $payment['id'], $successful, $receipt, [
        'result_code' => (int) $queryResult,
        'result_description' => $verification['ResultDesc'] ?? null,
        'checkout_request_id' => $checkoutId,
    ]);
    echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
} catch (Throwable $exception) {
    error_log('M-Pesa callback verification failed: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['ResultCode' => 1, 'ResultDesc' => 'Payment verification temporarily unavailable']);
}
