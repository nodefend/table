# Business Rules — TableFlow

Trạng thái: thiết kế v1, chưa triển khai. Quy tắc dưới đây là phạm vi nhóm đề xuất, không phải tất cả đều là yêu cầu trực tiếp của giảng viên.

## 1. Phạm vi và vai trò

Customer (không cần đăng nhập): xem bàn, lọc bàn theo thời điểm/số khách/khu vực, tạo đặt chỗ, tra cứu bằng mã đặt chỗ + số điện thoại, xem thực đơn. Không được đọc danh sách đặt chỗ người khác hay thay đổi trạng thái bàn.

Staff: đăng nhập, quản lý đặt chỗ, bàn, menu, đơn gọi món và nhận sự kiện. Admin: cùng chức năng nghiệp vụ; quản trị tài khoản ngoài MVP. Cả staff/admin phải có session hợp lệ cho API staff, thao tác ghi phải có CSRF token. Không gửi dữ liệu khách hàng trong event payload nếu không cần thiết.

## 2. Đặt bàn

- Mặc định 90 phút, cấu hình từ server; client gửi start_at có offset, server tính end_at. Không nhận end_at tùy ý từ khách.
- start_at không trước thời điểm server hiện tại; party_size nguyên dương và không vượt capacity. Khu vực phải active. Bàn out_of_service không nhận đặt chỗ.
- Khoảng thời gian nửa mở [start_at,end_at): hai đơn liền kề được phép. Chặn overlap với pending, confirmed, checked_in bằng transaction/lock như DATABASE_SCHEMA.md.
- Bàn occupied/cleaning không phù hợp cho đặt chỗ bắt đầu ngay; không tự suy luận chúng sẽ bận mãi trong tương lai. Đề xuất ngưỡng bắt đầu ngay: trong 15 phút kể từ hiện tại, cấu hình server. Thời gian tương lai xa hơn chỉ kiểm tra lịch giữ chỗ và out_of_service; nhân viên chịu trách nhiệm tình trạng thực tế.
- Tạo đơn pending, mã đặt chỗ ngẫu nhiên khó đoán và unique. Chuẩn hóa số điện thoại (chuỗi, bỏ khoảng trắng và dấu phân cách; giữ số 0 đầu), dùng cùng quy tắc khi tạo và tra cứu. Không ghi log số điện thoại đầy đủ.
- Kết quả tìm bàn chỉ là snapshot, không đảm bảo giữ chỗ. Khi submit phải kiểm tra lại. Xung đột trả 409 TABLE_ALREADY_BOOKED. Chặn gửi lặp bằng UI disable khi chờ; DB vẫn là nguồn đảm bảo cuối cùng.
- Không tìm thấy bàn phù hợp là 200 với mảng rỗng. Không tự hủy pending theo thời gian trong MVP; staff xử lý thủ công.

## 3. Trạng thái reservation

| Từ | Đến | Điều kiện |
|---|---|---|
| pending | confirmed | Staff xác nhận, kiểm tra lại bàn/lịch |
| pending | cancelled | Staff hủy |
| confirmed | checked_in | Bàn available, tại thời điểm start_at <= now < end_at, không có khách khác |
| confirmed | cancelled | Staff hủy |
| checked_in | completed | Không còn order open của lượt này; chuyển bàn cleaning |

Các trạng thái khác không được chuyển; completed/cancelled là cuối. Không sửa lịch/table_id trong MVP; nếu đổi lịch thì hủy và tạo lại. Check-in đổi bàn sang occupied trong cùng transaction; complete đổi sang cleaning. Nếu khách đến trễ ngoài khung giờ, staff xử lý bằng đơn mới theo quy tắc hiện hành. Trạng thái không hợp lệ: 409 INVALID_STATE_TRANSITION.

## 4. Trạng thái bàn

- available: bàn sẵn sàng; occupied: có khách; cleaning: đang dọn; out_of_service: ngừng phục vụ.
- reserved là trạng thái hiển thị suy ra theo khoảng thời gian đang xem, không lưu vào physical_status. Ưu tiên out_of_service; nếu đang xem thời điểm hiện tại, hiển thị occupied/cleaning khi có; với lịch tương lai hiển thị reserved nếu overlap, nếu không thì available dự kiến.
- Staff PATCH bàn cho cleaning -> available, available -> out_of_service, out_of_service -> available. Không đưa bàn ngừng phục vụ khi còn reservation giữ chỗ chưa kết thúc. occupied chỉ thay đổi thông qua check-in/complete trong MVP; không hỗ trợ walk-in riêng. Staff không được bypass luồng reservation.
- Mỗi thay đổi thành công phát event tương ứng trong cùng transaction.

## 5. Menu và orders

- Customer chỉ xem món available thuộc category active. Staff có thể thêm món, đổi tên/giá/mô tả/trạng thái; không xóa cứng món đã dùng. Category được seed; quản lý category ngoài MVP.
- Chỉ tạo order khi reservation checked_in và bàn occupied; reservation_id bắt buộc trong MVP, dù schema cho phép NULL để mở rộng. Một order open có thể chứa nhiều món; mỗi lần POST tạo một order mới, không tự ghép với order cũ. Số lượng nguyên dương, giá lấy từ DB, tính tổng tại server.
- MVP: open -> completed hoặc cancelled; không chỉnh sửa item sau tạo. Các order terminal không đổi trạng thái. Hoàn thành reservation yêu cầu không còn order open.

## 6. Long Polling và UX

- Staff nhận reservation.created, reservation.updated, table.status_changed, order.created, order.updated, menu.updated. Payload tối thiểu, dùng ID và trạng thái; client tải lại dữ liệu được phép khi cần. Không gửi mật khẩu, phone, note qua event.
- Timeout mặc định 20 giây, có thể cấu hình; trả 200 với events rỗng nếu không đổi. Chỉ một request in-flight mỗi client, reconnect có backoff khi lỗi, dừng khi logout hoặc 401.
- Khi bắt đầu dashboard: lấy cursor hiện tại trước rồi tải snapshot; sau đó polling từ cursor, xử lý event idempotent và không áp dụng dữ liệu cũ ghi đè dữ liệu mới.
- Mọi thao tác có loading, success/error, empty state; màu trạng thái kèm nhãn chữ; responsive, keyboard-accessible.

## 7. Acceptance tests

| Case | Kỳ vọng |
|---|---|
| Hai request đồng thời cùng bàn và cùng giờ | Chỉ một thành công, còn lại 409 |
| Hai khoảng liền kề | Cho phép nếu các điều kiện khác hợp lệ |
| Quá sức chứa / quá khứ | 422 |
| Không có bàn | 200, [] |
| Tra cứu sai mã hoặc phone | 404 chung, không lộ thông tin |
| Staff chưa login | 401 |
| Staff thiếu CSRF khi ghi | 403 |
| Chuyển trạng thái sai | 409 |
| Đặt bàn thành công | Staff nhận event và cập nhật không reload |
| Timeout / mất mạng | Polling tiếp tục an toàn, không nhân đôi request |
| Rollback nghiệp vụ | Không phát event đã rollback |
| Hai transaction commit khác thời điểm | Không mất event khi cursor tiến |

Xem [Database Schema](DATABASE_SCHEMA.md), [API Contract](API_CONTRACT.md).
