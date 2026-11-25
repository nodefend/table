<?php
declare(strict_types=1);

class ApiException extends RuntimeException
{
    public function __construct(public int $status, public string $errorCode,
        string $message, public array $details = []) { parent::__construct($message); }
}

function json_response(array $body, int $status = 200): void
{
    // Encode trước khi gửi header để lỗi encode vẫn xử lý được.
    $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo $json;
    exit;
}
function api_success(mixed $data = null, string $message = 'OK', int $status = 200): void
{
    json_response(['success'=>true, 'message'=>$message, 'data'=>$data ?? (object)[]], $status);
}
function api_error(int $status, string $code, string $message, array $details = []): void
{
    json_response(['success'=>false, 'message'=>$message,
        'error'=>['code'=>$code,'details'=>(object)$details]], $status);
}
function api_require_method(string ...$allowed): string
{
    $method = $_SERVER['REQUEST_METHOD'] ?? '';
    if (!in_array($method, $allowed, true)) {
        header('Allow: ' . implode(', ', $allowed));
        throw new ApiException(405, 'METHOD_NOT_ALLOWED', 'Phương thức không được hỗ trợ.');
    }
    return $method;
}
// Bao endpoint trong hàm này. Service tự rollback bằng db_transaction().
function api_run(callable $handler): void
{
    ini_set('display_errors', '0');
    try {
        $handler();
        throw new LogicException('Endpoint did not return a response');
    } catch (ApiException $e) {
        api_error($e->status, $e->errorCode, $e->getMessage(), $e->details);
    } catch (Throwable $e) {
        // Không ghi body, SQL, password, phone hoặc stack trace vào log chung.
        error_log('TableFlow API failure: ' . get_class($e));
        api_error(500, 'INTERNAL_ERROR', 'Máy chủ gặp lỗi.');
    }
}
