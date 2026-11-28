# TableFlow — Ngữ cảnh dự án và định hướng sản phẩm

TableFlow, với slogan **Taste Across Asia**, là hệ thống đặt bàn và quản lý nhà hàng cho môn Web Programming & Applications (503073), Topic 2 — AJAX. Nhóm có hai thành viên và chia việc theo luồng nghiệp vụ. Khách chọn chi nhánh, không gian, bàn và món phù hợp với ngân sách. Nhân viên điều hành bàn và lượt đặt trong cùng ứng dụng với cập nhật gần thời gian thực.

Tài liệu này mô tả thiết kế mục tiêu. Khi làm việc, kiểm tra nhánh hiện tại và các tệp thực tế trước khi xác nhận mức độ hoàn thành.

## Thứ tự ưu tiên

| Mức | Phạm vi | Kết quả cần đạt |
|---|---|---|
| **P0 — Nghiệp vụ và yêu cầu bắt buộc** | MySQL, tìm và đặt bàn, transaction chống trùng, xác thực và phân quyền, Fetch, Long Polling, ba chế độ benchmark. | Customer đặt bàn từ dữ liệu thật. Nhân viên đúng chi nhánh nhận và xử lý lượt đặt. Cả ba chế độ đo được trên một kịch bản chung. |
| **P1 — Hoàn thiện sản phẩm** | Mười chức năng sáng tạo, thực đơn, hình ảnh, chọn chi nhánh, hóa đơn VIP, dashboard và trải nghiệm đặt bàn. | Luồng khách và nhân viên đầy đủ theo đặc tả, với dữ liệu từ MySQL và phản hồi giao diện rõ ràng. |
| **P2 — Tinh chỉnh và tối ưu** | Animation, responsive nâng cao, hiệu ứng tương tác, tối ưu ảnh, hiệu năng và trải nghiệm. | Giao diện mượt và dễ dùng, với tác động hiệu năng được kiểm tra khi có liên quan. |

Luồng cốt lõi cần có nhãn, điều khiển dùng được bằng bàn phím và các trạng thái tải, rỗng, thành công, lỗi. Hoàn thiện giao diện đa kích thước theo tiêu chí UI/UX của đề trong P2.

## Phạm vi nhà hàng và dữ liệu

- Một thương hiệu giả lập tại 5 địa phương, 10 chi nhánh, 40 khu vực, 290 bàn và 1.220 chỗ. Mỗi chi nhánh có 29 bàn, 122 chỗ và nhân viên riêng. Giờ mẫu là 10:00–22:00 theo cấu hình từng chi nhánh.
- Mã chi nhánh gồm HCM01 Quận 1, HCM02 Quận 7, HN01 Hoàn Kiếm, HN02 Cầu Giấy, DN01 Hải Châu, DN02 Sơn Trà, DL01 Hồ Xuân Hương, DL02 Tuyền Lâm, PQ01 Dương Đông và PQ02 An Thới. Dữ liệu địa điểm dùng cho demo.
- Mỗi chi nhánh có 8 bàn outdoor, 9 bàn indoor, 6 bàn VIP và 6 bàn quiet. Sức chứa mỗi bàn là 2, 4, 6 hoặc 8, riêng quiet có bàn 2 và 4. Mã bàn dùng tiền tố OUT, IN, VIP và QT, duy nhất trong từng chi nhánh.
- Thực đơn và giá dùng chung cho toàn hệ thống. Có 36 mục gồm 9 khai vị, 13 món chính, 6 tráng miệng và 8 đồ uống, với hai bộ lọc độc lập theo loại món và nền ẩm thực Việt Nam, Ấn Độ, Thái Lan, Quốc tế. Tên, giá, tình trạng và ảnh lấy từ MySQL. Danh sách mẫu chi tiết nằm trong [seed.sql](database/seed.sql).
- Schema v2 gồm 11 bảng. Mỗi chi nhánh có một Staff mẫu và toàn hệ thống có một Admin mẫu. Chi tiết bảng, quan hệ và chỉ mục nằm trong [tài liệu schema](docs/DATABASE_SCHEMA.md) và [schema.sql](database/schema.sql).

## Luồng sản phẩm và phân công

**TV1 — Customer Flow.** Khách không cần tài khoản. Luồng chính đi từ chọn địa phương và chi nhánh, chọn ngày giờ và số khách, khám phá khu vực, tìm và chọn bàn, nhập thông tin, nhận mã đặt bàn, rồi tra cứu bằng mã và số điện thoại. TV1 phụ trách `public/customer/`, `public/api/customer/` và hai chế độ benchmark Reload và Fetch.

| Tính năng | Hành vi mục tiêu |
|---|---|
| Explore Space | Tải ảnh, mô tả, sức chứa và đặc trưng của khu vực theo chi nhánh. |
| Smart Table | Xếp hạng bàn khả dụng theo nhu cầu, khu vực và sức chứa phù hợp. Khách xác nhận lựa chọn. |
| Smart Menu | Gợi ý món theo số người, ngân sách, nền ẩm thực và phí VIP. Chi phí hiển thị là ước tính. |
| Smart Branch | Kiểm tra dữ liệu thật và gợi ý chi nhánh khác cùng địa phương hoặc khung giờ khác khi hết bàn. |
| Reservation Journey | Xác minh mã và số điện thoại, tải lại timeline qua Fetch bằng API Customer. |

**TV2 — Staff Flow.** Nhân viên đăng nhập, tiếp nhận, xác nhận, check-in, phục vụ, tạo đơn món, tính tiền, hoàn thành và dọn bàn. Staff thao tác trong chi nhánh được gán. Admin chọn chi nhánh khi quản lý hoạt động và có quyền ghi thực đơn chung. TV2 phụ trách `public/staff/`, `public/api/staff/` và chế độ benchmark Long Polling.

| Tính năng | Hành vi mục tiêu |
|---|---|
| Live Dashboard | Cập nhật số bàn, lượt đặt và đơn gọi món đang mở từ dữ liệu thật. |
| Live Notification | Nhận sự kiện đúng chi nhánh và khử trùng lặp theo event ID. |
| Live Table Management | Thao tác sơ đồ bàn và lấy lại snapshot sau sự kiện. |
| Billing & VIP Fee | Tính tổng và phụ phí VIP 5% ở máy chủ. |
| Reservation Status Management | Xác nhận, hủy, check-in và hoàn thành theo chuyển trạng thái hợp lệ. |

Cả hai cùng phụ trách schema, seed, hợp đồng API, helper, CSS dùng chung, kiểm thử tích hợp và phương pháp benchmark. Review chéo các thay đổi tác động cả hai luồng. Quy trình nhánh và đóng góp Git nằm trong [quy ước của nhóm](docs/AGENTS.md).

## Quy tắc cần giữ khi triển khai

- Mỗi lượt đặt nhận 1–8 khách và tương ứng một bàn đủ chỗ. Mặc định đặt 90 phút, toàn bộ khung giờ nằm trong giờ mở cửa. Lưu thời gian theo UTC và hiển thị theo `Asia/Ho_Chi_Minh`.
- Kiểm tra lại bàn ngay trong transaction lúc ghi. Khóa hàng bàn trước khi kiểm tra chồng lấn theo khoảng nửa mở với các trạng thái đang giữ chỗ. Hai lượt liền kề được phép. Đặt trùng trả 409, dữ liệu sai trả 422 và tìm bàn hết chỗ trả 200 với mảng rỗng.
- Lượt đặt chuyển từ `pending` sang `confirmed` hoặc `cancelled`, từ `confirmed` sang `checked_in` hoặc `cancelled`, từ `checked_in` sang `completed`. Trạng thái vật lý của bàn gồm `available`, `occupied`, `cleaning`, `out_of_service`. `reserved` được tính theo lịch khi hiển thị.
- Máy chủ xác định giá và khu vực từ DB. Mỗi đơn lưu giá tại thời điểm tạo, tổng tiền món và nước, mức phí, số tiền phí và tổng thanh toán. Phí VIP làm tròn đến VND theo từng đơn. Khu vực khác có phí 0%.
- MVP dùng gợi ý theo quy tắc và check-in trong quản lý lượt đặt. Vé điện tử/QR, phân tích doanh thu, đặt món trước, danh sách chờ, thanh toán trực tuyến, ghép bàn, nhiều thương hiệu và tích hợp mô hình AI nằm ngoài phạm vi.

[Quy tắc nghiệp vụ chi tiết](docs/BUSINESS_RULES.md)

## Kiến trúc, API và Long Polling

Dùng HTML, CSS, JavaScript thuần và Fetch với PHP 8.x, PDO, MySQL 8.x InnoDB và PHP Session. Cả hai luồng chạy trong một ứng dụng với `public/` là document root. Endpoint nhận request, service xử lý nghiệp vụ và transaction, repository truy vấn DB khi cần, frontend cập nhật DOM từ JSON. Dùng chung `db()`, `db_transaction()`, `api_run()`, các hàm validation và event, `TableFlowAPI` và `common.css` hiện có.

Endpoint Customer đặt dưới `/api/customer/`, endpoint Staff đặt dưới `/api/staff/`. Chốt method, trường dữ liệu, mã HTTP và JSON theo [API Contract](docs/API_CONTRACT.md). Kiểm tra quyền và CSRF phía PHP khi phù hợp, dùng prepared statements, xác thực dữ liệu server, escape khi render HTML và giới hạn thông tin trả về theo quyền.

Khi Customer ghi lượt đặt, lưu `reservation.created` cùng transaction với bản ghi nghiệp vụ. Staff đăng nhập lấy cursor trước khi tải snapshot, rồi gửi `since_id` và đọc `next_since_id`. Máy chủ giữ request đến khi có sự kiện hoặc hết tối đa 20 giây. Giải phóng khóa PHP Session và transaction trước khi chờ. Sự kiện được lọc theo chi nhánh, dùng sentinel cấp ID tăng dần và chỉ chứa ID hoặc trạng thái cần thiết. `menu.updated` là sự kiện toàn hệ thống. Client khử trùng lặp, thử lại với backoff, hủy request cũ khi đổi chi nhánh và dừng khi mất phiên hoặc đăng xuất. Demo và đo Long Polling cần máy chủ hỗ trợ các request đồng thời.

## Benchmark Topic 2 và bài nộp

| Chế độ | Cách cập nhật sơ đồ hoặc danh sách bàn |
|---|---|
| Full-Page Reload | Tải lại HTML document. |
| AJAX Fetch | Tải JSON rồi cập nhật vùng DOM liên quan. |
| Long Polling | Chờ sự kiện rồi lấy snapshot tương đương và cập nhật DOM. |

Chạy cả ba chế độ với cùng chi nhánh, bộ lọc, dữ liệu và thao tác thay đổi trạng thái bàn. Giữ nhất quán trình duyệt, máy chủ, mạng và cache. Ghi số lần đo, tính các request phụ và mô tả khác biệt về cách kích hoạt. Dùng Browser DevTools thu **Network Payload Size**, **TTFB** và **DOM Update Behavior**. Tách thời gian chờ chủ ý của Long Polling khỏi độ trễ xử lý và cập nhật. Lưu dữ liệu gốc, ảnh DevTools, biểu đồ và phương pháp trong `experiments/` để phân tích nguyên nhân từ kết quả đo thật.

Nộp báo cáo PDF tiếng Anh theo mẫu Faculty, repo có lịch sử đóng góp của cả hai, mã nguồn, dependency manifest nếu có, SQL, hướng dẫn chạy và tài khoản demo. Video walkthrough dài tối đa 10–15 phút, độ phân giải từ 1080p và có cả hai thành viên trình bày. Phụ lục báo cáo có AI Usage Log với ít nhất 2–3 ví dụ prompt và cách nhóm kiểm chứng. Live Defense yêu cầu mỗi thành viên giải thích và tự sửa hoặc debug trong 3–5 phút mà không dùng AI. Rubric gồm báo cáo 2,5 điểm, sản phẩm và quy trình 3,5 điểm, bảo vệ cá nhân 4 điểm.

## Thiết kế giao diện và hình ảnh

Phong cách Modern Asian Dining với **Pastel Pink – Rose**. Dùng token trong [common.css](public/assets/css/common.css).

| Token | Màu |
|---|---|
| Background | `#FFF5F8` |
| Pastel Pink | `#F8D7E3` |
| Selected | `#FCE8EF` |
| Primary | `#A64D6B` |
| Heading | `#79354E` |
| Text | `#49343C` |
| Surface | `#FFFFFF` |
| Border | `#EBCBD7` |
| Muted | `#80616C` |

Dùng màu nghiệp vụ riêng và thêm nhãn hoặc biểu tượng cho từng trạng thái bàn. Customer ưu tiên khám phá không gian và hình ảnh, Staff ưu tiên thao tác nhanh, VIP giữ phong cách riêng trong cùng hệ thiết kế.

Mục tiêu 86 ảnh gồm 36 ảnh món và đồ uống, 40 ảnh khu vực, 10 ảnh chi nhánh. Dùng WebP, tên chữ thường không dấu ngăn cách bằng gạch ngang. Ảnh món dùng tỷ lệ 4:3, ảnh không gian dùng 16:9. Lưu ảnh dưới `public/assets/images/` theo nhóm `menu/`, `areas/`, `branches/`, `branding/` và `banners/`. Nhóm menu dùng `vietnamese/`, `indian/`, `thai/`, `desserts/`, `drinks/`. Ảnh khu vực dùng tên như `hcm01-outdoor.webp`, ảnh chi nhánh dùng tên như `hcm01.webp`. Kiểm tra tệp thật trước khi cập nhật đường dẫn tương đối vào MySQL và dùng ảnh fallback khi thiếu. Nếu cần lưu ảnh chi nhánh mà schema chưa có cột, review migration và tài liệu trước khi đổi.

## Nghiệm thu theo nhiệm vụ

- Đặt bàn đồng thời chỉ có một lượt thành công. Kiểm tra quá sức chứa, Quiet Zone, giờ hoạt động, hai khung liền kề và hết bàn.
- Kiểm tra đăng nhập, quyền theo chi nhánh, CSRF và chuyển trạng thái. Xác minh Staff không truy cập dữ liệu chi nhánh khác.
- Kiểm tra Long Polling khi timeout, mất mạng, đổi chi nhánh và đăng xuất. Kiểm tra hóa đơn VIP 5%, món hết hàng và ảnh fallback khi phát triển các tính năng tương ứng.
- Chạy từ bản clone sạch với [README](README.md), cấu hình và cùng schema/seed. Ghi kết quả thật, phần chưa chạy và các điểm thành viên cần giải thích khi bảo vệ.
