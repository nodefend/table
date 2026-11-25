<?php
declare(strict_types=1);
require_once __DIR__ . '/response.php';

function invalid_field(string $field, string $message): void
{
    throw new ApiException(422, 'VALIDATION_ERROR', 'Dữ liệu không hợp lệ.', [$field=>$message]);
}
function json_body(int $maxBytes = 65536): array
{
    $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($type !== 'application/json') throw new ApiException(400, 'INVALID_REQUEST', 'Yêu cầu JSON.');
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) throw new ApiException(400, 'INVALID_REQUEST', 'Body không hợp lệ hoặc quá lớn.');
    try { $object = json_decode($raw, false, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException $e) { throw new ApiException(400, 'INVALID_JSON', 'JSON không hợp lệ.'); }
    if (!$object instanceof stdClass) throw new ApiException(400, 'INVALID_JSON', 'Body phải là object JSON.');
    return json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
}
function validate_fields(array $input, array $allowed, array $required = []): void
{
    foreach ($input as $field => $value) if (!in_array($field, $allowed, true)) invalid_field((string)$field, 'Trường không được hỗ trợ.');
    foreach ($required as $field) if (!array_key_exists($field, $input) || $input[$field] === null) invalid_field($field, 'Bắt buộc.');
}
function validate_int(mixed $value, string $field, int $min = 1, int $max = 9007199254740991): int
{
    if (!is_int($value) && !(is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value))) invalid_field($field, 'Phải là số nguyên.');
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min,'max_range'=>$max]]);
    if ($number === false) invalid_field($field, "Giá trị phải từ $min đến $max.");
    return $number;
}
function validate_id(mixed $value, string $field = 'id'): int { return validate_int($value, $field); }
function validate_party_size(mixed $value): int { return validate_int($value, 'party_size', 1, 8); }
function validate_capacity(mixed $value): int
{
    $n = validate_int($value, 'capacity', 2, 8);
    if (!in_array($n, [2,4,6,8], true)) invalid_field('capacity', 'Chỉ nhận 2, 4, 6 hoặc 8.');
    return $n;
}
function validate_text(mixed $value, string $field, int $max, bool $allowEmpty = false): string
{
    if (!is_string($value) || preg_match('//u', $value) !== 1) invalid_field($field, 'Phải là chuỗi UTF-8.');
    $value = trim($value);
    $length = preg_match_all('/./us', $value);
    if ((!$allowEmpty && $length === 0) || $length > $max) invalid_field($field, "Độ dài không hợp lệ (tối đa $max ký tự).");
    return $value;
}
function validate_phone(mixed $value): string
{
    $value = validate_text($value, 'phone', 40);
    $value = preg_replace('/[\s().-]+/u', '', $value);
    if (!preg_match('/^\+?[0-9]{8,15}$/D', $value)) invalid_field('phone', 'Số điện thoại không hợp lệ.');
    return $value;
}
function validate_enum(mixed $value, string $field, array $allowed): string
{
    if (!is_string($value) || !in_array($value, $allowed, true)) invalid_field($field, 'Giá trị không được hỗ trợ.');
    return $value;
}
function validate_bool(mixed $value, string $field): bool
{
    if (!is_bool($value)) invalid_field($field, 'Phải là boolean JSON.');
    return $value;
}
function validate_date(mixed $value, string $field = 'date'): string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) invalid_field($field, 'Dùng YYYY-MM-DD.');
    [$y,$m,$d] = array_map('intval', explode('-', $value));
    if (!checkdate($m,$d,$y) || $y < 1000) invalid_field($field, 'Ngày không hợp lệ.');
    return $value;
}
// ISO 8601 bắt buộc offset; trả DateTimeImmutable UTC để lưu MySQL.
function validate_datetime(mixed $value, string $field = 'start_at'): DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/^(\d{4}-\d{2}-\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value, $m)) invalid_field($field, 'Dùng ISO 8601 có múi giờ.');
    validate_date($m[1], $field);
    if (preg_match('/[+-]14:(?!00$)/', $value)) invalid_field($field, 'Offset không hợp lệ.');
    try { $date = new DateTimeImmutable($value); }
    catch (Exception $e) { invalid_field($field, 'Ngày giờ không hợp lệ.'); }
    return $date->setTimezone(new DateTimeZone('UTC'));
}
function validate_booking_window(DateTimeImmutable $start, string $opening, string $closing, ?DateTimeImmutable $now = null): DateTimeImmutable
{
    $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($start < $now) invalid_field('start_at', 'Không đặt bàn trong quá khứ.');
    $end = $start->modify('+90 minutes');
    $tz = new DateTimeZone('Asia/Ho_Chi_Minh');
    $local = $start->setTimezone($tz);
    $day = $local->format('Y-m-d');
    $open = new DateTimeImmutable("$day $opening", $tz);
    $close = new DateTimeImmutable("$day $closing", $tz);
    if ($start < $open || $end > $close) invalid_field('start_at', 'Khung đặt bàn vượt giờ hoạt động.');
    return $end->setTimezone(new DateTimeZone('UTC'));
}
function validate_table_fit(int $party, int $capacity, string $areaType): void
{
    validate_party_size($party); validate_capacity($capacity);
    if ($party > $capacity || ($areaType === 'quiet' && $party > 4)) invalid_field('party_size', 'Bàn không đủ chỗ.');
}
