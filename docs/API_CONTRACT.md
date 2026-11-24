# API Contract — TableFlow v2

Baseline thiết kế, chưa có PHP thực thi. TV1 sở hữu `public/api/customer/`, TV2 `public/api/staff/`. Path tương đối document root `public/`; dùng relative/configured base URL qua api.js, không hardcode hostname/port/tên folder triển khai.

## 1. Quy ước chung

- JSON UTF-8; POST/PATCH body JSON, GET query string. Không GET để ghi; Content-Type application/json khi có body; response Cache-Control: no-store cho dữ liệu động/riêng tư.
- snake_case; ID integer an toàn JS (<= Number.MAX_SAFE_INTEGER); bool JSON; null nếu tùy chọn; ISO 8601 offset bắt buộc khi nhập ngày giờ, response UTC Z, UI Asia/Ho_Chi_Minh; tiền VND integer; vip_rate số phần trăm (5, không phải 0.05).
- Success: `{"success":true,"message":"OK","data":{}}`. Error: `{"success":false,"message":"Request failed","error":{"code":"ERROR_CODE","details":{}}}`. List trả array trực tiếp trong data, rỗng `[]` (trừ composite response có mô tả).
- HTTP 200/201; 400 INVALID_JSON/INVALID_REQUEST; 401 UNAUTHENTICATED/INVALID_CREDENTIALS; 403 FORBIDDEN/CSRF_INVALID; 404 NOT_FOUND; 405 METHOD_NOT_ALLOWED (Allow header); 409 conflict; 422 VALIDATION_ERROR (details theo field); 429 RATE_LIMITED (Retry-After); 500 INTERNAL_ERROR; 503 SERVICE_UNAVAILABLE. Không trả stack trace.
- Staff session same-origin HttpOnly, SameSite=Lax, Secure khi HTTPS; login regenerate ID, kiểm tra Origin và rate limit; ghi sau login có X-CSRF-Token; logout hủy session. Không giữ session lock khi poll. Lookup và login rate limit, lỗi chung không lộ tài khoản/đơn tồn tại.
- Staff chỉ branch_id được gán từ DB/session; GET không chỉ định branch thì dùng branch của staff, nếu chỉ định khác trả 403. Admin bắt buộc branch_id cho list/snapshot/event và mọi thao tác trên chi nhánh. Với PATCH theo id, truyền branch_id query (staff có thể bỏ); server đối chiếu thực thể với scope, không tin input. Menu chung không cần branch_id, chỉ admin ghi. Kiểm tra active và scope ở backend cho từng request; không lọc chỉ ở UI.

## 2. Kiểu dữ liệu

| Kiểu | Trường |
|---|---|
| Branch | id, branch_code, name, city, address (nullable), phone (nullable), opening_time, closing_time, is_active |
| Area | id, branch_id, name, area_type, description (nullable), image_url (nullable), is_active, table_count, max_capacity, vip_rate |
| Table | id, branch_id, area_id, area_name, area_type, table_number, capacity, physical_status, position_x, position_y, display_status, vip_rate |
| ReservationPublic | reservation_code, branch_id, branch_name, city, table_id, table_number, area_type, area_name, party_size, start_at, end_at, status, customer_name, note (nullable) |
| ReservationStaff | id + ReservationPublic + phone, created_at, updated_at |
| MenuCategory | id, name, is_active |
| Cuisine | id, name, is_active |
| MenuItem | id, category_id, category_name, cuisine_id, cuisine_name, name, description (nullable), price, image_url (nullable), is_available |
| Order | id, branch_id, table_id, reservation_id, status, subtotal_amount, vip_rate, vip_fee, total_amount, items [{menu_item_id,quantity,unit_price}], created_at, updated_at |
| Event | id, branch_id (nullable chỉ menu.updated), event_type, entity_id, payload (object), created_at |

`Area.max_capacity` = sức chứa lớn nhất một bàn, không phải tổng ghế. `display_status`: available/reserved/occupied/cleaning/out_of_service theo BUSINESS_RULES; không lộ thông tin đặt chỗ người khác qua Table. ReservationPublic chỉ trả khi vừa tạo/lookup hợp lệ; không trả phone. vip_rate suy ra area_type, không nhận từ client.

## 3. Customer — TV1

| Method & path dưới /api/customer/ | Input | Success data |
|---|---|---|
| GET branches.php | city? | Branch[] chỉ active; city không truyền thì tất cả |
| GET areas.php | branch_id | Area[] chỉ active, branch active |
| GET tables.php | branch_id, area_id?, start_at? | Table[] sơ đồ mọi bàn active-area, display_status theo thời điểm (mặc định now), không lọc capacity |
| GET availability.php | branch_id, start_at, party_size, area_id? | Table[] chỉ bàn hợp lệ, server tính end_at |
| GET alternatives.php | branch_id, start_at, party_size, area_type? | Alternative[]; chỉ gọi khi cần gợi ý thay thế |
| POST reservations.php | branch_id, table_id, customer_name, phone, party_size, start_at, note? | 201 ReservationPublic |
| POST reservation-lookup.php | reservation_code, phone | ReservationPublic; dùng cho Journey, nút refresh gọi lại cùng endpoint |
| GET menu-filters.php | không | {categories:MenuCategory[],cuisines:Cuisine[]} chỉ active |
| GET menu.php | category_id?, cuisine_id?, q? | MenuItem[] chỉ available và category/cuisine active |

`city` là nhãn địa phương trong DB, không phải mã tỉnh. branch_id không tồn tại: 404; branch inactive: 409 BRANCH_UNAVAILABLE; area/table không thuộc branch: 422 VALIDATION_ERROR. Khi filter quiet với party_size >4, availability trả [] (không có bàn phù hợp); cố POST đặt vào quiet >4 trả 422. Tìm kiếm quá khứ/party không 1–8/khung vượt giờ hoạt động trả 422. `tables.php` chỉ đọc trạng thái, cho phép xem snapshot now ngoài giờ; không phải cam kết khả dụng.

Alternative = {branch_id,branch_name,city,start_at,end_at,available_count}. Tìm đúng khung giờ ở các branch active khác cùng city, và khung giờ khác cùng ngày ở branch hiện tại (bước 30 phút, tối đa 3 slot gần nhất), kiểm tra sức chứa, area_type nếu có, giờ mở cửa, overlap và physical_status theo cùng service availability. Sort chi nhánh khác theo branch_code, giờ khác theo khoảng cách đến start_at rồi giờ tăng dần. Không trả kết quả count=0. Không tự chọn/giữ bàn; [] nếu không tìm được. Bước và giới hạn là mặc định thiết kế, có thể thống nhất đổi trước code.

Smart Table xếp hạng phía client trên danh sách availability (rule-based), Smart Menu tính gợi ý phía client từ dữ liệu menu đã fetch, không tạo thêm API ghi/order. Nếu mục đích dùng bữa có chọn, không ghi trường occasion riêng; khách muốn gửi yêu cầu cho staff thì đưa vào note. Không dùng API staff từ customer.

Ví dụ POST reservations:
```json
{"branch_id":1,"table_id":3,"customer_name":"Khách Demo","phone":"0000000001","party_size":4,"start_at":"2027-01-15T18:00:00+07:00","note":null}
```
Ví dụ response 201 (ngày chỉ minh họa, test bằng ngày tương lai thực):
```json
{"success":true,"message":"Created","data":{"reservation_code":"TF-DEMO-EXAMPLE","branch_id":1,"branch_name":"TableFlow Quận 1","city":"TP. Hồ Chí Minh","table_id":3,"table_number":"OUT-03","area_type":"outdoor","area_name":"Ngoài trời","party_size":4,"start_at":"2027-01-15T11:00:00Z","end_at":"2027-01-15T12:30:00Z","status":"pending","customer_name":"Khách Demo","note":null}}
```
Xung đột 409 `TABLE_ALREADY_BOOKED`; table không phục vụ 409 `TABLE_UNAVAILABLE`; lỗi field/quá sức chứa 422. Không có bàn là 200 [], không phải lỗi. Lookup sai mã/phone trả 404 NOT_FOUND chung. Không đặt phone hoặc reservation_code vào URL query/log.

## 4. Staff — TV2

Tất cả dưới `/api/staff/`. branch_id trong query cho endpoint có scope (GET/POST/PATCH); quy tắc staff/admin ở mục 1. Endpoint ghi yêu cầu CSRF trừ login. Endpoint menu không có scope chi nhánh.

| Method & file | Input ngoài branch_id | Success data |
|---|---|---|
| POST login.php | username,password | {user:{id,username,display_name,role,branch_id},csrf_token} |
| POST logout.php | {} | {} |
| GET branches.php | không | Branch[] (admin tất cả, staff chỉ branch mình) |
| GET dashboard.php | date? | DashboardSnapshot |
| GET tables.php | area_id?,start_at? | Table[] (cả khu vực inactive nếu có) |
| PATCH tables.php?id=ID | physical_status | Table |
| GET reservations.php | status?,date?,table_id? | ReservationStaff[] |
| PATCH reservations.php?id=ID | status | ReservationStaff |
| GET menu.php | category_id?,cuisine_id?,q? | MenuItem[] (cả món ẩn) |
| POST menu.php (admin) | category_id,cuisine_id,name,description?,price,image_url?,is_available? | 201 MenuItem |
| PATCH menu.php?id=ID (admin) | subset của field POST | MenuItem |
| GET orders.php | table_id?,reservation_id?,status? | Order[] |
| POST orders.php | table_id,reservation_id,items:[{menu_item_id,quantity}] | 201 Order |
| PATCH orders.php?id=ID | status | Order |
| GET table-events.php | since_id hoặc bootstrap=1 | EventResponse (mục 5) |

Login/logout/branches không cần branch_id. `DashboardSnapshot` = {branch_id,date,table_counts:{available,occupied,cleaning,out_of_service},reservation_counts:{pending,confirmed,checked_in,completed,cancelled},open_order_count}. Counts theo branch; table và order open là snapshot hiện tại, reservation lọc start_at theo date địa phương (mặc định hôm nay). Không số liệu doanh thu. `date` YYYY-MM-DD, lọc start_at trong ngày local; GET reservations nếu bỏ date thì không lọc. Sort reservation start_at,id; table table_number; menu category_id,id; order id giảm dần; branch branch_code. Search q theo tên món, prepared statements, không nhận tên cột tùy ý. MVP chưa bắt buộc pagination.

POST orders không nhận unit_price/subtotal/vip_rate/vip_fee/total từ client; từ chối trường không hợp lệ 422. Các menu item không lặp trong request; số lượng nguyên dương, kiểm tra available và category/cuisine active; giá lấy từ DB. Ví dụ (VIP, giá gỏi cuốn seed 45000):
```json
{"table_id":19,"reservation_id":123,"items":[{"menu_item_id":1,"quantity":2}]}
```
Sau tính phía server: subtotal_amount=90000, vip_rate=5, vip_fee=4500, total_amount=94500. ID reservation chỉ minh họa; cần checked_in thực tế. Không thực hiện thanh toán online. PATCH chỉ field whitelist; lỗi 409 INVALID_STATE_TRANSITION, TABLE_UNAVAILABLE, TABLE_HAS_ACTIVE_RESERVATIONS, ORDER_STILL_OPEN, TABLE_ALREADY_BOOKED tùy nghiệp vụ. Session token có thể đưa vào PHP đã xác thực để phục hồi reload; không lưu password/token log.

## 5. Long Polling và phân vùng

- `GET /api/staff/table-events.php?branch_id=1&bootstrap=1` trả ngay data `{events:[],next_since_id:<latest committed global id>,has_more:false}`. Cursor từ sentinel trước khi tải snapshot. Staff có thể bỏ branch_id; admin không được bỏ. Cursor không dùng làm quyền truy cập.
- `GET ...?branch_id=1&since_id=0`: giữ request tối đa 20s, query `id > :since_id AND (branch_id = :scope OR (branch_id IS NULL AND event_type = 'menu.updated')) ORDER BY id ASC LIMIT 101`. Trả tối đa 100; row 101 chỉ để xác định has_more. next_since_id = ID cuối trả; nếu rỗng giữ cursor đầu vào. ID có khoảng trống do lọc branch, không lỗi. Cursor lớn hơn global high-water mark: 422, bootstrap lại. Không nhảy cursor lên max khi chưa trả hết event.
- Ví dụ:
```json
{"success":true,"message":"OK","data":{"events":[{"id":12,"branch_id":1,"event_type":"reservation.created","entity_id":42,"payload":{"table_id":3,"status":"pending"},"created_at":"2027-01-15T10:00:00Z"}],"next_since_id":12,"has_more":false}}
```
- Event: reservation.created/updated, table.status_changed, order.created/updated gắn branch_id thực tế; menu.updated global NULL, payload menu_item_id, không PII. Khi check-in/complete thay đổi cả reservation và table, phát cả hai event trong một transaction; client gom refetch hợp lý. Notification dedupe id, Dashboard và map cập nhật từ snapshot hợp lệ.
- Không giữ transaction/session lock khi chờ; session_write_close sau auth; kiểm tra quyền trên từng request, kiểm tra lại active/scope trước trả response nếu có thay đổi. AbortController, backoff lỗi, dừng 401/logout; khi đổi branch abort, bỏ response cũ, bootstrap rồi snapshot mới. Không giả Long Polling bằng setInterval gọi request ngắn. No-store. Không public endpoint đọc stream staff.

## 6. Benchmark và thay đổi hợp đồng

Full-Page Reload, Fetch, Long Polling dùng cùng branch, cùng bộ lọc, dữ liệu và tính năng sơ đồ bàn. Reload tải document; Fetch tải JSON snapshot; Long Polling chờ event rồi fetch snapshot tương đương. Đo Network payload size, TTFB, DOM update behavior; tách thời gian chờ event khỏi xử lý, cộng bytes các request phụ. Dùng DevTools, cùng điều kiện test; raw data thật trong experiments/. Không có kết quả đo hiện tại. Thay đổi schema/API/state cần review hai thành viên, cập nhật contract trước hoặc cùng PR. Không đổi path customer/staff về API root.
