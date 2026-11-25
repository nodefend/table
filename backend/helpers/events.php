<?php
declare(strict_types=1);
require_once __DIR__ . '/validation.php';

// Gọi sau khi khóa và cập nhật dữ liệu nghiệp vụ, Trong cùng transaction.
function append_event(PDO $pdo, ?int $branchId, string $type, int $entityId, array $payload): int
{
    if (!$pdo->inTransaction()) throw new LogicException('Event requires business transaction');
    validate_id($entityId, 'entity_id');
    $types = ['reservation.created','reservation.updated','table.status_changed','order.created','order.updated','menu.updated'];
    validate_enum($type, 'event_type', $types);
    if (($type === 'menu.updated') !== ($branchId === null)) throw new LogicException('Invalid event scope');
    if ($branchId !== null) validate_id($branchId, 'branch_id');
    // Chỉ các trường ID và trạng thái cho phép; không chuyển nguyên reservation/user vào event.
    $allowed = match ($type) {
        'reservation.created','reservation.updated' => ['table_id','status'],
        'table.status_changed' => ['table_id','physical_status'],
        'order.created','order.updated' => ['table_id','reservation_id','status'],
        'menu.updated' => ['menu_item_id'],
    };
    validate_fields($payload, $allowed);
    foreach ($payload as $key => $value) {
        if (str_ends_with($key, '_id')) validate_id($value, $key);
        elseif ($key === 'physical_status') validate_enum($value, $key, ['available','occupied','cleaning','out_of_service']);
        else validate_enum($value, $key, str_starts_with($type, 'order.') ? ['open','completed','cancelled'] : ['pending','confirmed','checked_in','completed','cancelled']);
    }
    $row = $pdo->query('SELECT payload FROM change_events WHERE id = 0 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Missing event sentinel');
    $last = event_sequence($row['payload']);
    if ($last >= 9007199254740991) throw new OverflowException('Event ID exhausted');
    $id = $last + 1;
    $stmt = $pdo->prepare('INSERT INTO change_events (id, branch_id, event_type, entity_id, payload, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(6))');
    $stmt->execute([$id,$branchId,$type,$entityId,json_encode((object)$payload, JSON_THROW_ON_ERROR)]);
    $stmt = $pdo->prepare('UPDATE change_events SET payload = ?, created_at = UTC_TIMESTAMP(6) WHERE id = 0');
    $stmt->execute([json_encode(['last_id'=>$id], JSON_THROW_ON_ERROR)]);
    return $id;
}
function event_sequence(string $json): int
{
    $value = json_decode($json, true, 64, JSON_THROW_ON_ERROR)['last_id'] ?? null;
    if (!is_int($value) || $value < 0 || $value > 9007199254740991) throw new RuntimeException('Invalid event sentinel');
    return $value;
}
function event_high_water(PDO $pdo): int
{
    $value = $pdo->query('SELECT payload FROM change_events WHERE id = 0')->fetchColumn();
    if ($value === false) throw new RuntimeException('Missing event sentinel');
    return event_sequence($value);
}
function event_bootstrap(PDO $pdo): array
{
    if ($pdo->inTransaction()) throw new LogicException('Bootstrap requires autocommit');
    return ['events'=>[], 'next_since_id'=>event_high_water($pdo), 'has_more'=>false];
}
function event_batch(PDO $pdo, int $branchId, int $sinceId): array
{
    if ($pdo->inTransaction()) throw new LogicException('Event read requires autocommit');
    validate_id($branchId, 'branch_id');
    validate_int($sinceId, 'since_id', 0);
    if ($sinceId > event_high_water($pdo)) invalid_field('since_id', 'Cursor vượt quá ID hiện tại; cần bootstrap lại.');
    $stmt = $pdo->prepare("SELECT id,branch_id,event_type,entity_id,payload,created_at FROM change_events WHERE id > ? AND (branch_id = ? OR (branch_id IS NULL AND event_type = 'menu.updated')) ORDER BY id ASC LIMIT 101");
    $stmt->execute([$sinceId,$branchId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $more = count($rows) > 100;
    $events = [];
    foreach (array_slice($rows, 0, 100) as $row) {
        $events[] = [
            'id'=>(int)$row['id'], 'branch_id'=>$row['branch_id'] === null ? null : (int)$row['branch_id'],
            'event_type'=>$row['event_type'], 'entity_id'=>(int)$row['entity_id'],
            'payload'=>json_decode($row['payload'], false, 64, JSON_THROW_ON_ERROR),
            'created_at'=>(new DateTimeImmutable($row['created_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
    return ['events'=>$events,'next_since_id'=>$events ? $events[count($events)-1]['id'] : $sinceId,'has_more'=>$more];
}
// Endpoint staff phải xác thực và xác định branch từ DB/session trước khi gọi.
// Callback phải kiểm tra lại user/branch active và quyền scope từ DB, throw 401/403 nếu sai.
function wait_for_events(PDO $pdo, int $branchId, int $sinceId, callable $assertScope, int $timeoutSeconds = 20): array
{
    if ($pdo->inTransaction() || session_status() === PHP_SESSION_ACTIVE) throw new LogicException('Close transaction/session before polling');
    if ($timeoutSeconds < 1 || $timeoutSeconds > 20) throw new InvalidArgumentException('Invalid polling timeout');
    $deadline = hrtime(true) + $timeoutSeconds * 1000000000;
    do {
        $assertScope($branchId);
        $batch = event_batch($pdo, $branchId, $sinceId);
        if ($batch['events'] || hrtime(true) >= $deadline || connection_aborted()) {
            $assertScope($branchId);
            return $batch;
        }
        usleep((int)min(250000, max(0, ($deadline - hrtime(true)) / 1000)));
    } while (true);
}
