# API Contract — TableFlow v1

Trạng thái: hợp đồng thiết kế cần review, chưa phải API đã chạy. TV1 sở hữu `public/api/customer/`, TV2 sở hữu `public/api/staff/`. Endpoint bên dưới tương đối với document root `public/`; không hardcode hostname, port hay tên thư mục triển khai. Frontend dùng base URL cấu hình/relative path qua api.js.

## 1. Quy ước

- Request/response JSON UTF-8; body JSON cho POST/PATCH; GET dùng query string; `Content-Type: application/json` khi có body. Không dùng GET để ghi dữ liệu.
- snake_case; ID là integer an toàn trong JavaScript (giới hạn vận hành <= Number.MAX_SAFE_INTEGER); bool JSON; null cho giá trị tùy chọn; thời gian ISO 8601 có offset, response chuẩn UTC (Z), UI hiển thị Asia/Ho_Chi_Minh; tiền VND integer.
- Thành công: `{"success":true,"message":"OK","data":{}}`. Thất bại: `{"success":false,"message":"Request failed","error":{"code":"ERROR_CODE","details":{}}}`. Mảng trả trong data trực tiếp nếu endpoint list; không có kết quả là []. Không phụ thuộc chữ message.
- 200 OK; 201 Created; 400 INVALID_JSON/INVALID_REQUEST; 401 UNAUTHENTICATED/INVALID_CREDENTIALS; 403 FORBIDDEN/CSRF_INVALID; 404 NOT_FOUND; 405 METHOD_NOT_ALLOWED (Allow header); 409 conflict; 422 VALIDATION_ERROR (details theo tên field); 500 INTERNAL_ERROR; 503 SERVICE_UNAVAILABLE. Không trả stack trace.
- Staff dùng same-origin session cookie HttpOnly, SameSite=Lax, Secure khi HTTPS. Login regenerate session ID; login có kiểm tra Origin cùng origin và giới hạn thử mật khẩu; các request ghi sau login có header `X-CSRF-Token`. Logout hủy session. Không để session lock chặn polling.
- Customer lookup và login có rate limit phù hợp; nếu vượt trả 429 RATE_LIMITED với Retry-After. Không lộ thông tin tồn tại tài khoản/đơn.

## 2. Kiểu dữ liệu trả về

| Kiểu | Các trường |
|---|---|
| Table | id, area_id, area_name, table_number, capacity, physical_status, position_x, position_y, display_status |
| ReservationPublic | reservation_code, table_id, table_number, party_size, start_at, end_at, status, customer_name, note (nullable) |
| ReservationStaff | id + ReservationPublic + phone, created_at, updated_at |
| MenuItem | id, category_id, category_name, name, description (nullable), price, is_available |
| Order | id, table_id, reservation_id, status, total_amount, items [{menu_item_id,quantity,unit_price}], created_at, updated_at |
| Event | id, event_type, entity_id, payload (object), created_at |

`display_status`: available/reserved/occupied/cleaning/out_of_service theo BUSINESS_RULES.md. Không lộ dữ liệu đặt chỗ của người khác qua Table. `ReservationPublic` chỉ trả cho người vừa đặt hoặc tra cứu hợp lệ; không trả phone.

## 3. Customer endpoints — TV1

| Method & path | Input | Success data |
|---|---|---|
| GET /api/customer/tables.php | area_id? | Table[] (thời điểm hiện tại; chỉ khu vực active) |
| GET /api/customer/availability.php | start_at, party_size, area_id? | Table[] (chỉ bàn phù hợp; server tính end_at) |
| POST /api/customer/reservations.php | table_id, customer_name, phone, party_size, start_at, note? | 201 ReservationPublic |
| POST /api/customer/reservation-lookup.php | reservation_code, phone | ReservationPublic |
| GET /api/customer/menu.php | category_id?, q? | MenuItem[] (chỉ món available/category active) |

Ví dụ POST reservations body:
```json
{"table_id":3,"customer_name":"Demo Customer","phone":"0900000000","party_size":4,"start_at":"2027-01-15T18:00:00+07:00","note":null}
```
Ngày chỉ minh họa; khi test dùng thời điểm tương lai thực tế. Server tự tính end_at. Ví dụ thành công 201:
```json
{"success":true,"message":"Created","data":{"reservation_code":"DEMO-EXAMPLE","table_id":3,"table_number":"T03","party_size":4,"start_at":"2027-01-15T11:00:00Z","end_at":"2027-01-15T12:30:00Z","status":"pending","customer_name":"Demo Customer","note":null}}
```
Xung đột 409: `{"success":false,"message":"Table is already booked","error":{"code":"TABLE_ALREADY_BOOKED","details":{}}}`. Table không tồn tại 404; không đủ sức chứa/quá khứ 422; bàn không khả dụng 409 TABLE_UNAVAILABLE.

## 4. Staff endpoints — TV2

Tất cả dưới `/api/staff/`; login là ngoại lệ xác thực. Các endpoint ghi cần CSRF trừ login.

| Method & file | Input | Success data |
|---|---|---|
| POST login.php | username, password | {user:{id,username,display_name,role},csrf_token} |
| POST logout.php | {} | {} |
| GET tables.php | area_id?, start_at? | Table[] (mọi khu vực) |
| PATCH tables.php?id=ID | physical_status | Table |
| GET reservations.php | status?, date?, table_id? | ReservationStaff[] |
| PATCH reservations.php?id=ID | status | ReservationStaff |
| GET menu.php | category_id?, q? | MenuItem[] (cả món ẩn) |
| POST menu.php | category_id,name,description?,price,is_available? | 201 MenuItem |
| PATCH menu.php?id=ID | subset của trường POST | MenuItem |
| GET orders.php | table_id?, status? | Order[] |
| POST orders.php | table_id,reservation_id,items:[{menu_item_id,quantity}] | 201 Order |
| PATCH orders.php?id=ID | status | Order |
| GET table-events.php | since_id hoặc bootstrap=1 | Event response (mục 5) |

`date` là ngày YYYY-MM-DD tại Asia/Ho_Chi_Minh, lọc start_at trong ngày; danh sách mặc định không lọc, sort reservation theo start_at,id; table theo table_number; menu theo category_id,id; order theo id giảm dần. `q` tìm chuỗi tên món, prepared statements; không nhận tên cột tùy ý. MVP chưa bắt buộc pagination; nếu dữ liệu tăng, thống nhất contract mới trước khi thêm.

Lỗi 409 INVALID_STATE_TRANSITION, TABLE_UNAVAILABLE, TABLE_HAS_ACTIVE_RESERVATIONS, ORDER_STILL_OPEN, TABLE_ALREADY_BOOKED tùy nghiệp vụ. PATCH chỉ nhận field được phép; field không hợp lệ 422. Không nhận giá/tổng tiền từ client khi tạo order. Session token có thể được đưa vào trang PHP đã xác thực để phục hồi khi reload; không lưu password/token trong log.

## 5. Long Polling

- `GET /api/staff/table-events.php?bootstrap=1` trả ngay `{events:[],next_since_id:<latest committed id>,has_more:false}` trong data. Client lấy cursor trước khi tải snapshot.
- `GET /api/staff/table-events.php?since_id=0` trả event id > cursor, tối đa 100, tăng dần; server giữ request tới khi có event hoặc timeout 20s. Nếu không có: `{events:[],next_since_id:0,has_more:false}` (giữ cursor đầu vào). Nếu có, next_since_id là ID cuối thực trả; has_more báo còn event đã commit. Không nhảy cursor lên max nếu chưa trả hết. Cursor vượt high-water mark trả 422; client bootstrap lại.
- Ví dụ response:
```json
{"success":true,"message":"OK","data":{"events":[{"id":12,"event_type":"reservation.created","entity_id":42,"payload":{"table_id":3,"status":"pending"},"created_at":"2027-01-15T10:00:00Z"}],"next_since_id":12,"has_more":false}}
```
- Event types: reservation.created, reservation.updated, table.status_changed, order.created, order.updated, menu.updated. payload gồm ID liên quan và trạng thái nếu có. Client refetch snapshot/section tương ứng, không chứa PII.
- Không giữ DB transaction hoặc PHP session lock khi chờ; đóng session sau đọc auth, kiểm tra lại quyền trên request mới. Trả Cache-Control: no-store. Client dùng AbortController, backoff lỗi, dừng khi 401/logout. Không giả Long Polling bằng interval gọi request ngắn.

## 6. Benchmark và thay đổi hợp đồng

Full-Page Reload và Fetch dùng cùng nghiệp vụ lọc/tải snapshot; Long Polling dùng event để kích hoạt tải snapshot tương đương. Đo riêng event response và fetch bổ sung, cộng tổng payload của thao tác. URL benchmark chưa phải API nghiệp vụ, triển khai sau trên branch tương ứng. Thay đổi endpoint, field, status, schema phải review hai thành viên, cập nhật tài liệu trước hoặc cùng PR. Không đổi `/api/customer/` thành API root như cấu trúc cũ.
