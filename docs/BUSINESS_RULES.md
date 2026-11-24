# Business Rules — TableFlow v2

Phạm vi nhóm chốt: một thương hiệu, nhiều chi nhánh, 4 khu vực, 29 bàn/chi nhánh, thực đơn chung và VIP 5%. Quy tắc triển khai dưới đây là thiết kế dự án, không phải tất cả là yêu cầu giảng viên. Chưa triển khai backend.

## 1. Phạm vi và phân quyền

- Customer không đăng nhập: chọn một trong 5 địa phương, chi nhánh active, xem không gian/bàn/menu, tìm bàn, đặt bàn, nhận mã và tra cứu mã + phone. Không đọc đơn người khác, không quản lý trạng thái.
- Staff đăng nhập và chỉ xem/ghi bàn, reservation, order, dashboard, event của branch_id trong session (đối chiếu DB). Không được thay đổi branch bằng query/body. Admin xem toàn hệ thống, chọn chi nhánh cụ thể cho thao tác nghiệp vụ; riêng menu chung chỉ admin ghi, staff đọc. Không xây dựng trang CRUD tài khoản/chi nhánh trong MVP; seed cấu hình sẵn.
- Mọi API staff (trừ login) cần session hợp lệ, thao tác ghi cần CSRF, kiểm tra scope trên thực thể trong DB; ngoài scope trả 403. Không gửi PII qua event. Session phải kiểm tra user/branch active. Không dùng mật khẩu thật.

## 2. Đặt bàn và khu vực

- Ngoài trời: 8 bàn (2/3/2/1); bên trong: 9 (3/3/2/1); VIP: 6 (1/2/2/1); yên tĩnh: 6 (3/3/0/0), theo thứ tự bàn 2/4/6/8. Quiet tối đa 4 khách. VIP ai cũng có thể chọn, không cần tài khoản đặc biệt, phụ phí 5% hóa đơn món/nước. Hiển thị trước khi đặt. Không ghép bàn, một lượt 1–8 khách.
- Luồng: địa phương -> chi nhánh -> ngày/giờ/số khách -> khu vực hoặc mọi khu vực -> bàn -> thông tin liên hệ -> xác nhận pending -> tra cứu. Khi đổi branch/date/party/area, hủy request cũ hoặc bỏ response stale, bỏ bàn đã chọn và fetch lại.
- Mặc định 90 phút, cấu hình server; client gửi start_at có offset, server tính end_at. Không nhận end_at tùy ý. start_at không ở quá khứ, toàn khung nằm trong giờ mở cửa địa phương cùng ngày (giờ demo 10:00–22:00). Branch/area active; table thuộc branch yêu cầu, capacity >= party_size; quiet chỉ 1–4. out_of_service không nhận đặt.
- Khoảng nửa mở [start_at,end_at); hai đơn liền kề được phép. Chặn overlap với pending, confirmed, checked_in bằng transaction khóa bàn (DATABASE_SCHEMA.md). occupied/cleaning chặn đặt bắt đầu trong 15 phút tới (cấu hình); tương lai xa hơn dựa lịch và out_of_service. Không hứa bàn vật lý sẽ sẵn sàng ngoài thời điểm hiện tại.
- Tạo pending với mã ngẫu nhiên khó đoán, unique toàn hệ thống; normalize phone (chuỗi, bỏ khoảng trắng/dấu phân cách, giữ số 0 đầu) nhất quán. Không log phone đầy đủ. Kết quả tìm bàn là snapshot, submit kiểm tra lại. Xung đột 409 TABLE_ALREADY_BOOKED; disable submit khi request đang chạy. Không có bàn: 200 [] (hoặc alternatives []); không tự hết hạn pending trong MVP, staff xử lý.

## 3. Trạng thái và sơ đồ bàn

| Từ | Đến | Điều kiện |
|---|---|---|
| pending | confirmed | Staff đúng scope, kiểm tra lại bàn/lịch |
| pending | cancelled | Staff đúng scope |
| confirmed | checked_in | Bàn available, start_at <= now < end_at, không có khách khác; đổi bàn occupied cùng transaction |
| confirmed | cancelled | Staff đúng scope |
| checked_in | completed | Không còn order open; đổi bàn cleaning cùng transaction |

completed/cancelled là cuối. Không sửa lịch/table_id; hủy và đặt lại. Đến ngoài khung giờ: xử lý bằng đơn mới theo quy tắc hiện hành. Check-in vẫn là thao tác trong trang quản lý reservation, không có trang check-in độc lập hoặc QR. Lỗi chuyển sai 409 INVALID_STATE_TRANSITION.

`physical_status`: available, occupied, cleaning, out_of_service. `reserved` chỉ là display_status theo khung giờ xem, không lưu physical_status. Ưu tiên out_of_service; với thời điểm hiện tại hoặc trong 15 phút tới, occupied/cleaning nếu có; tương lai xa hơn reserved khi overlap, nếu không là available dự kiến. Staff PATCH bàn chỉ cleaning -> available, available -> out_of_service, out_of_service -> available. Không ngừng phục vụ nếu còn reservation active chưa kết thúc. occupied chỉ qua check-in/complete; không walk-in riêng. Mỗi cập nhật phát event cùng transaction.

## 4. Thực đơn và hóa đơn

- 36 món chung cho 10 chi nhánh. Hai trục phân loại: loại món (khai vị, món chính, tráng miệng, nước uống) và cuisine (Việt Nam, Ấn Độ, Thái Lan, Quốc tế). Khách chỉ xem món available, category/cuisine active. Admin thêm/sửa tên, mô tả, giá, ảnh, trạng thái; staff xem; không xóa cứng. Quản lý category/cuisine ngoài MVP. Danh sách và giá seed là giá giả lập đã đề xuất, không phải giá thật.
- Chỉ tạo order khi reservation checked_in, table occupied, cùng bàn/chi nhánh. reservation_id bắt buộc; POST tạo order mới, không tự gộp. Số lượng nguyên dương, lấy giá DB, snapshot unit_price, tính tổng phía server. Không cho khách đặt món trước. Order open -> completed hoặc cancelled, không sửa item sau tạo; terminal không đổi. Hoàn thành reservation khi không còn order open.
- subtotal = SUM(quantity*unit_price). vip_rate = 5.00 nếu bàn VIP, nếu khác 0.00. vip_fee = ROUND(subtotal * vip_rate / 100, 0) theo VND, total = subtotal + vip_fee. Lưu cả 4 giá trị trong order; không cộng phí lần hai. Mỗi order là một hóa đơn; tổng chi tiêu cả lượt là tổng các hóa đơn completed, không phải một hóa đơn gộp. Order cancelled không tính; order open chỉ là dự kiến. MVP tính/hoàn thành hóa đơn nội bộ, không thanh toán online, không thuế/khuyến mãi. Nếu nhiều order, làm tròn từng order (quyết định kỹ thuật); tổng phí có thể lệch tối đa vài đồng so với tính một lần trên cả lượt. Nếu cần hóa đơn gộp một lần phải đổi contract trước khi code. Ví dụ VIP 457.000 -> 22.850 -> 479.850; thường 242.000 -> 0 -> 242.000.

## 5. Mười chức năng đã giữ lại

| Chủ sở hữu | Chức năng | Luồng và tiêu chí hoàn thành |
|---|---|---|
| TV1 | Explore Space | Fetch areas theo chi nhánh, ảnh/mô tả/sức chứa, fallback ảnh, báo phí VIP; chọn khu vực cập nhật map |
| TV1 | Smart Table | Tìm bàn khả dụng, ưu tiên khu vực theo dịp nếu khách chọn (hẹn hò/đối tác/gia đình/sinh nhật), sau đó sức chứa dư nhỏ nhất, rồi table_number; không đề xuất bàn vi phạm điều kiện. Khách quyết định cuối cùng |
| TV1 | Smart Menu | Lọc món theo cuisine/category, nhận budget VND cho cả nhóm và party_size; gợi ý ít nhất một món chính/người nếu đủ tiền, rồi thêm khai vị/nước/tráng miệng nếu còn ngân sách; không khẳng định khẩu phần tối ưu. Tính phí VIP theo quy tắc hóa đơn dự kiến; không vượt budget; nếu không đủ hiển thị lý do. Chỉ là ước tính, không tạo order |
| TV1 | Smart Branch | Khi chi nhánh hết bàn, tìm cùng giờ ở chi nhánh active khác cùng địa phương hoặc giờ khác ở chi nhánh hiện tại trong ngày; chỉ đề xuất kết quả kiểm tra thật; không tự đặt/đổi chi nhánh |
| TV1 | Reservation Journey | Tra cứu bằng mã + phone qua Fetch, timeline pending/confirmed/checked_in/completed hoặc cancelled; nút cập nhật không reload. Tự cập nhật công khai qua long polling riêng ngoài MVP, không dùng API staff |
| TV2 | Live Dashboard | Tổng bàn theo physical_status, reservation theo trạng thái trong ngày địa phương, số order open, cập nhật khi event đến; admin chọn branch, staff cố định branch; không Revenue Analytics |
| TV2 | Live Notification | Thông báo sự kiện đúng branch, badge số chưa đọc trong phiên UI, không PII, không duplicate event ID |
| TV2 | Live Table Management | Sơ đồ 4 khu vực theo branch, cập nhật trạng thái hợp lệ, nhận event rồi fetch snapshot; phân biệt trạng thái vật lý và theo lịch |
| TV2 | Quản lý hóa đơn và VIP | Tạo order, hiển thị subtotal/rate/fee/total, kiểm tra giá/branch phía server; hoàn thành/hủy order |
| TV2 | Cập nhật reservation | Danh sách theo branch/date/status, xác nhận/hủy/check-in/complete theo bảng state transitions, đồng bộ event |

Occasion Mode tích hợp tùy chọn vào Smart Table, không trang riêng; Digital Ticket/QR, Revenue Analytics, Check-in bằng mã độc lập đã loại. Live Waiting List, Pre-order, thanh toán online và AI thực không thuộc phạm vi. Smart Table là thuật toán quy tắc, không quảng cáo là AI.

## 6. Long Polling và UX

- Staff stream: reservation.created/updated, table.status_changed, order.created/updated theo branch; menu.updated global. Chỉ ID và trạng thái tối thiểu, không phone/note/password. Event và ghi nghiệp vụ cùng transaction. Admin chọn branch cụ thể khi subscribe; đổi branch phải abort và bootstrap lại.
- Timeout 20s; 200 events rỗng khi không đổi. Một request in-flight mỗi client; backoff khi lỗi; dừng logout/401. Đọc cursor trước snapshot rồi poll từ cursor; idempotent, không ghi đè response cũ. Không giữ PHP session lock/DB transaction khi chờ. Xem API Contract.
- Loading/empty/error/success rõ; màu trạng thái kèm nhãn, responsive và keyboard-accessible. Không gửi dữ liệu demo như availability thật. Dữ liệu hiển thị là dữ liệu MySQL khi ứng dụng chạy.

## 7. Acceptance tests (kỳ vọng, chưa chạy)

| Case | Kỳ vọng |
|---|---|
| Hai request đồng thời một bàn/giờ | 1 thành công, 1 trả 409 |
| Cùng mã bàn ở hai chi nhánh | Hai reservation độc lập, không xung đột |
| Hai khoảng liền kề | Cho phép nếu hợp lệ |
| Quiet với 5 khách | Tìm bàn 200 []; tạo đặt chỗ không hợp lệ 422 |
| Bàn thiếu chỗ, quá 8, quá khứ, ngoài giờ khi tạo reservation | 422 |
| Không có bàn | 200 [] và Smart Branch chỉ gợi ý kết quả thật |
| Tra cứu sai mã hoặc phone | 404 chung, không lộ thông tin |
| Staff chưa login / thiếu CSRF khi ghi | 401 / 403 |
| Staff branch A xem/ghi đơn branch B | 403, không lộ dữ liệu |
| Staff đổi menu chung | 403; admin được phép |
| Chuyển trạng thái sai | 409 |
| VIP 457000 / thường 242000 | 479850 / 242000; server bỏ qua giá client bằng cách từ chối field không hợp lệ |
| Smart Menu budget không đủ | Không vượt ngân sách, báo thiếu |
| Đặt bàn thành công branch A | Staff A nhận event, B không nhận; menu global cả hai nhận |
| Timeout / mất mạng / đổi branch | Polling an toàn, không nhân đôi request, bỏ response cũ |
| Rollback hoặc commit lệch thứ tự | Không phát event rollback, không mất event trong scope |

Xem [Database Schema](DATABASE_SCHEMA.md), [API Contract](API_CONTRACT.md).
