# AGENTS.md — TableFlow

## Mục tiêu và nguồn yêu cầu

TableFlow: Restaurant Reservation and Table Management System Using AJAX with Comparative Performance Evaluation. Môn Web Programming & Applications (503073), HK1 2026–2027, Topic 2 AJAX, 2 thành viên. Nguồn yêu cầu chính thức: Essay & Practical Project Guidelines v2.0, 19/11/2026, mục I–VI. Tài liệu này là quy ước nhóm, không phải toàn bộ nội dung đề bài.

Bắt buộc theo đề: ứng dụng demo hoạt động; dynamic feature; so sánh Full-Page Reload, AJAX Fetch (nhóm chọn Fetch) và Long Polling (nhóm chọn thay WebSockets); đo Network payload size, TTFB, DOM update behavior bằng Browser DevTools; số liệu tự thu thập; báo cáo tiếng Anh theo mẫu Faculty; Git incremental và đóng góp cân bằng; README, DB, dependencies nếu có, test/admin credentials; video screen-and-camera tối đa 10–15 phút, 1080p+, mỗi thành viên xuất hiện và trình bày; live defense cá nhân 5–10 phút, gồm sửa/debug 3–5 phút không AI. AI Usage Log trong Appendix có ít nhất 2–3 ví dụ prompt và cách kiểm chứng. Nhóm sẽ tổng hợp AI_USAGE_LOG.md cuối dự án, lưu bằng chứng từ bây giờ.

Rubric: Part A 2.5 (literature .5, design/experiment 1.5, formatting/citations/AI .5); Part B 3.5 (functionality 2, UI/UX .5, Git/video 1); Part C 4 (Q&A 2, live coding 2). Nếu C <1.5, tổng = .5*(A+B)+C; vắng không được chấp thuận = 0. Không tối ưu điểm bằng cách giả mạo bằng chứng.

## Kiến trúc

Một repo, một PHP/MySQL application, hai giao diện, một thương hiệu và 10 chi nhánh giả lập (5 địa phương). HTML5/CSS3/JS Fetch; PHP 8.x PDO; MySQL 8.x InnoDB; Long Polling. Không tự đổi stack. Document root `public/`. Luồng: browser -> PHP endpoint -> controller/service/repository (khi cần) -> DB -> response -> DOM. Không bắt buộc lớp rỗng hoặc framework chỉ để đủ cấu trúc.

```text
TableFlow/
  public/
    customer/             # TV1
    staff/                # TV2
    benchmark/            # chia theo phương pháp
    api/
      customer/           # TV1
      staff/              # TV2
    assets/
      css/                # common.css dùng chung; customer/staff riêng
      js/                 # api.js dùng chung; customer/staff riêng
      images/customer/
      images/staff/
  backend/
    config/
    controllers/
    services/
    repositories/
    middleware/
    helpers/
  database/migrations/
  docs/
  tests/customer/
  tests/staff/
  tests/integration/
  experiments/
  AGENTS.md
  README.md
  .env.example
  .gitignore
```

Các thư mục rỗng dùng .gitkeep. Main ban đầu chỉ có folder và file nền tảng. Tạo file nghiệp vụ khi làm trên branch cá nhân, không cần tạo tất cả file rỗng trước. Nếu repo đã có file rỗng, không tự xóa; nếu API customer đang nằm ở root api, báo nhóm và thống nhất chuyển sang api/customer trước khi viết code; cập nhật call sites nếu đã có. Không tự ghi đè code.

## Phân công

| Phần | TV1 — feature/customer-flow | TV2 — feature/staff-flow |
|---|---|---|
| Frontend | public/customer; chọn chi nhánh, xem/tìm bàn, đặt, xác nhận, tra cứu, menu; Explore Space, Smart Table, Smart Menu, Smart Branch, Reservation Journey | public/staff; login, Live Dashboard, Live Notification, Live Table Management, hóa đơn VIP, cập nhật reservation, menu (admin ghi), orders |
| API | public/api/customer | public/api/staff |
| Backend | customer controller/service/repository; kiểm tra overlap, ghi event | staff controller/service/repository; auth/session, kiểm tra branch scope, hóa đơn, quản lý trạng thái, đọc event |
| Assets | customer.css, customer.js, images/customer | staff.css, staff.js, long-polling.js, images/staff |
| Tests | tests/customer | tests/staff |
| Benchmark | reload.php, fetch.php | long-polling.php |

Cả hai: database, API contract, business rules, config, response/validation/events helpers, api.js, common.css, tests/integration, benchmark index/results và methodology, báo cáo/video. Thống nhất chủ sở hữu từng thay đổi file chung trước khi code để tránh conflict. Phân công theo chức năng end-to-end, không chỉ frontend/backend.

## Phạm vi v2 đã chốt

4 khu vực outdoor/indoor/vip/quiet; 29 bàn/chi nhánh, sức chứa 2/4/6/8; quiet chỉ 2/4. 36 món dùng chung, VIP +5% hóa đơn món/nước. Staff chỉ một chi nhánh; admin toàn hệ thống, chọn chi nhánh khi thao tác. Occasion Mode không làm riêng; Digital Ticket/QR, Revenue Analytics và Check-in bằng mã độc lập đã loại; thao tác check-in trong cập nhật trạng thái vẫn giữ. Smart Menu chỉ ước tính, không tạo order. Không mở rộng phạm vi tự ý.

## Trước khi tách branch

1. Review 5 tài liệu: DATABASE_SCHEMA.md, BUSINESS_RULES.md, API_CONTRACT.md, AGENTS.md, README.md.
2. Hoàn thiện schema.sql, seed.sql, database.php, response.php, validation.php, events.php, api.js, common.css, .env.example, .gitignore. Nếu cần thêm loader cấu hình, thống nhất cùng nhóm.
3. Hai máy chạy DB, seed, PDO, response và event smoke test thành công; lưu bằng chứng thật. Commit nền tảng lên main bằng PR hoặc quy trình nhóm đã thống nhất.
4. Tạo/sync hai branch từ main. Không mặc định tài liệu đã có nghĩa là code chạy được.

## Git workflow

- main là nhánh tích hợp; chức năng riêng làm trên feature/customer-flow hoặc feature/staff-flow. Nếu đã có branch, fetch và merge origin/main khi working tree sạch. Không tạo lại repo.
- Commit incremental, rõ bằng tiếng Việt, ví dụ `feat: Thêm chức năng tìm bàn trống`. Không fake commit, không dùng danh tính người khác, không force push hoặc xóa branch. PR có mô tả, file đổi, cách test và kết quả thực tế; người còn lại review trước merge. Cập nhật main thường xuyên.
- AI không tự commit/push/merge, đổi branch hoặc sửa phần người khác nếu chưa được yêu cầu. Kiểm tra git status và branch trước thao tác.

## Contract, bảo mật và chất lượng

Đọc docs/DATABASE_SCHEMA.md, docs/BUSINESS_RULES.md, docs/API_CONTRACT.md trước khi code. Ba tài liệu cùng là baseline; nếu mâu thuẫn, báo nhóm, không tự chọn một bên. Đường dẫn API customer/staff tách riêng. Không hardcode hostname/port/secret; prepared statements; password_hash/password_verify; session/CSRF; validation server; escape HTML; lỗi JSON không lộ stack trace. Kiểm tra concurrency, transaction rollback, event ordering, branch scope, VIP fee và giới hạn khu vực quiet. Không dùng production data khi test. Giữ code đơn giản để sinh viên hiểu và sửa được.

## Benchmark

Thiết kế ba chế độ cùng một dynamic feature: màn hình danh sách/sơ đồ bàn có cùng branch_id và cùng bộ lọc và cùng tập dữ liệu, thao tác refresh để thấy thay đổi trạng thái/đặt chỗ. Reload tải lại document; Fetch tải snapshot JSON và cập nhật DOM; Long Polling đợi event rồi cập nhật cùng snapshot. Có thể bổ sung thí nghiệm tìm bàn chủ động (Reload vs Fetch) nhưng không thay thế so sánh đủ ba phương pháp. Cùng browser, máy, DB, bộ lọc, cache policy, network conditions và thao tác; ghi rõ khác biệt trigger và waiting time. Đo payload theo cùng định nghĩa (ví dụ transferred bytes), TTFB từng request, DOM update behavior, tổng bytes/request count mỗi thao tác; tách long-poll wait khỏi server processing và đo event-to-UI nếu có. Đề xuất 30 lần/điều kiện nếu khả thi, ghi rõ warm-up, median và IQR; không bịa kết quả. Lưu raw data, screenshots, charts, methodology trong experiments/. Chỉ công bố số đo đã thu thật.

## Cách AI thực hiện nhiệm vụ

1. Đọc repo, branch và 3 contract trước; xác định scope TV1/TV2/chung.
2. Đề xuất logic ngắn, sửa tối thiểu các file liên quan; không tự đổi schema, API, stack hoặc kiến trúc. Thay đổi dùng chung phải nêu tác động và xin nhóm thống nhất.
3. Viết code có thể giải thích, test edge cases; không giả output. Báo rõ lệnh đã chạy, pass/fail/chưa chạy, file tạo/sửa, lý do, cách chạy lại và hạn chế.
4. Ghi lại tool, task, prompt, kiểm chứng và chỉnh sửa thật để tổng hợp AI Usage Log. Không tạo nhật ký giả. Không thay sinh viên thực hiện Live Defense.
