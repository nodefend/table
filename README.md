# TableFlow

Restaurant Reservation and Table Management System Using AJAX with Comparative Performance Evaluation.

Môn Web Programming & Applications (503073), HK1 2026–2027, Topic 2 AJAX. Nhóm 2 thành viên.

**Trạng thái:** tài liệu thiết kế v2; chưa xác minh repository thực tế hoặc chạy ứng dụng. Các bước cài đặt dưới đây là quy trình dự kiến sau khi nhóm hoàn thiện code nền tảng; không phải bằng chứng hệ thống đã chạy.

## Chức năng dự kiến

- Customer: chọn địa phương/chi nhánh, xem sơ đồ, tìm/đặt bàn, tra cứu, xem thực đơn; Explore Space, Smart Table, Smart Menu, Smart Branch, Reservation Journey.
- Staff: đăng nhập, quản lý bàn/đặt chỗ/orders theo chi nhánh, admin quản lý thực đơn chung; Live Dashboard, Live Notification, Live Table Management, hóa đơn VIP 5%, cập nhật trạng thái đặt bàn qua Long Polling.
- Benchmark: so sánh Full-Page Reload, AJAX Fetch, Long Polling bằng Browser DevTools (Network payload size, TTFB, DOM update behavior).

## Phạm vi v2

Một thương hiệu, 5 địa phương (TP. Hồ Chí Minh, Hà Nội, Đà Nẵng, Đà Lạt, Phú Quốc), 10 chi nhánh giả lập; 4 khu vực/chi nhánh, 29 bàn/chi nhánh, 122 chỗ/chi nhánh; toàn hệ thống 40 khu vực, 290 bàn, 1.220 chỗ. 36 món/đồ uống dùng chung; 11 bảng. Không ghép bàn, không thanh toán online, không QR, không phân tích doanh thu, không đặt món trước. Phụ phí VIP 5% tiền món và nước, không tính thuế/khuyến mãi trong MVP. Xem quy tắc chi tiết và các giả định trong docs.

## Stack và tài liệu

PHP 8.x (PDO MySQL), MySQL 8.x, HTML/CSS/JavaScript, Git. Không cần Node.js cho thiết kế hiện tại. Apache/XAMPP hoặc PHP local server thích hợp; MySQL phải đúng phiên bản hỗ trợ schema (kiểm tra XAMPP có thể đi kèm MariaDB thay vì MySQL).

- [Database schema](docs/DATABASE_SCHEMA.md)
- [Business rules](docs/BUSINESS_RULES.md)
- [API contract](docs/API_CONTRACT.md)
- [Development instructions](AGENTS.md)

## Cài đặt dự kiến

1. Clone repository từ URL thực tế của nhóm: `git clone <REPOSITORY_URL>`; `cd TableFlow` (đổi tên thư mục nếu clone khác tên). Chưa cung cấp URL repo trong tài liệu này.
2. Kiểm tra `php -v`, `php -m` (pdo_mysql), `mysql --version`. Bật MySQL server; tạo DB local và user chỉ có quyền phù hợp. Chọn tên DB, host, port theo máy.
3. Copy `.env.example` thành `.env` (Windows PowerShell: `Copy-Item .env.example .env`; Linux/macOS: `cp .env.example .env`). Điền DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD; dự kiến APP_TIMEZONE=Asia/Ho_Chi_Minh, RESERVATION_DURATION_MINUTES=90, IMMEDIATE_BOOKING_WINDOW_MINUTES=15, LONG_POLL_TIMEOUT_SECONDS=20. Nhóm phải triển khai loader và xác nhận tên biến đúng như .env.example; PHP không tự đọc .env. Không commit .env.
4. Import vào DB đã chọn bằng MySQL client. Ví dụ trong mysql shell: `USE tableflow;` (thay bằng tên DB đã tạo), `SOURCE database/schema.sql;`, `SOURCE database/seed.sql;` (chạy từ root repo, hoặc dùng đường dẫn tuyệt đối). Kiểm tra các bảng và dữ liệu mẫu. Không chạy seed trên DB thật. Bản v2 không phải migration; tạo DB rỗng mới, không chạy đè bản v1.
5. Cấu hình Apache document root tới `public/`, hoặc local PHP server nếu chỉ smoke test. Long Polling cần server xử lý đồng thời nhiều request; PHP built-in server mặc định trên Windows không phù hợp benchmark nhiều client. Khuyến nghị Apache cho demo/benchmark. Đảm bảo timeout PHP/web server lớn hơn timeout polling. Không expose backend, database, .env hoặc tests qua web.
6. Khi các trang đã được tạo, mở `<BASE_URL>/customer/index.php`, `<BASE_URL>/staff/login.php`, `<BASE_URL>/benchmark/index.php`. BASE_URL là địa chỉ triển khai thực tế, không hardcode trong source. Các trang chưa được xác nhận tồn tại.
7. Tài khoản test/admin: `staff_demo` / `StaffDemo@123` (HCM01); các chi nhánh khác `staff_<branch_code viết thường>` (ví dụ `staff_dl01`) cùng mật khẩu; `admin_demo` / `AdminDemo@123` (toàn hệ thống). Hash đã được tạo và kiểm tra bằng PHP; chưa import MySQL. Không dùng thông tin đăng nhập thật.

## Phát triển

- `main`: nền tảng chung, tích hợp đã review.
- `feature/customer-flow`: TV1 phát triển Customer và Reload/Fetch.
- `feature/staff-flow`: TV2 phát triển Staff và Long Polling.

Khi nền tảng đã được merge, mỗi thành viên dùng branch của mình. Nếu chưa có branch: `git switch main`, `git pull origin main`, `git switch -c feature/customer-flow` (TV2 thay bằng feature/staff-flow), `git push -u origin HEAD`. Nếu branch đã tồn tại, checkout branch đó, không tạo trùng. Đồng bộ bằng `git fetch origin` và `git merge origin/main` khi working tree sạch. Commit từng chức năng bằng tiếng Việt, mở PR và review chéo.

## Kiểm thử và thực nghiệm

`tests/customer/`, `tests/staff/`, `tests/integration/` chứa test scripts tạo dần khi phát triển. Chưa có lệnh test runner được xác nhận; cập nhật README khi chọn cách chạy test. Dùng DB test riêng. Kiểm tra đặt trùng, validation, quyền theo chi nhánh, phụ phí VIP, CSRF, state transitions, polling reconnect và đồng bộ hai browser.

`experiments/` lưu raw data, screenshots, charts, methodology. Không có kết quả đo ở giai đoạn này. Benchmark đủ ba phương pháp trên cùng dynamic feature; lưu bằng chứng DevTools và phân tích nguyên nhân, phân biệt thời gian long-poll chờ event với thời gian phản hồi/xử lý.

## Deliverables và hoàn thiện

Theo Essay & Practical Project Guidelines v2.0 (19/11/2026): báo cáo PDF tiếng Anh theo Faculty template, Git repo có lịch sử incremental, source/database/dependencies/README và test credentials, video tối đa 10–15 phút, độ phân giải 1080p trở lên, Live Defense, AI Usage Log trong Appendix. Nhóm sẽ tổng hợp AI_USAGE_LOG.md cuối dự án, nhưng ghi bằng chứng sử dụng AI ngay khi làm. Thành viên và MSSV thực tế, URL repo, phiên bản môi trường, lệnh test, tài khoản mẫu và kết quả benchmark cần cập nhật khi xác minh.
