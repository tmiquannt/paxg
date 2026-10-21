# Đánh giá Mini ERP theo vận hành thực tế — 20/10/2026

## Kết luận

Hệ thống đã có nền tảng nghiệp vụ đáng kể, nhưng chưa đủ để nghiệm thu vận hành. Có bốn lỗi P1 cần xử lý trước: ghi lùi ngày làm sai kỳ đối soát đã đóng, không lưu được phạm vi kho/quỹ, màn Phiếu chi lỗi fatal, và form kiểm kê từ chối số đếm hợp lệ. Bộ test đang xanh chưa bao phủ những ranh giới này.

WordPress + custom plugin + MySQL/InnoDB là lựa chọn hợp lý cho phạm vi một tổ chức, MVP nhỏ đã định nghĩa. Chưa có bằng chứng cần đổi framework hoặc tách microservices. Vấn đề chính là tính nhất quán giữa HTTP/form, policy, service, transaction và test.

Đây là báo cáo đánh giá, chưa sửa mã nghiệp vụ. Không thay đổi tài khoản có sẵn. Tài khoản kiểm tra tạm được xóa sau kiểm tra; bảng kiểm thử giao dịch dùng prefix ngẫu nhiên và đã xác minh cleanup.

**Lưu ý mã thay đổi song song lúc kết thúc review (khoảng 15:03, Asia/Ho_Chi_Minh):** `CashController.php`, `Bootstrap.php`, `AppRouter.php` và phần chứng từ tiền có thay đổi từ bên ngoài đợt audit. Hash cuối đo được là `08e8c6b94419377f5d78ad65b50b1edfe933bcc1797c77270a6281832881d116`, khác hash khi ghi nhận các phát hiện. Không gắn kết quả test trước đó cho bản mã mới. Số dòng trong các finding chỉ nơi đã kiểm tra ban đầu; có thể dịch sau thay đổi song song.

F03: mapping mới đã có `erp_expenses`, nhưng lần tái kiểm tra controller và `php -l` cuối cùng đều thất bại do **Parse error: unexpected token "/" tại CashController.php:86**. Vì mã còn được sửa, chưa kết luận Phiếu chi đã sửa xong và chưa coi lỗi cú pháp này là trạng thái ổn định của candidate. Tài khoản tái kiểm tra cũng đã được xóa trong finally. Cần lint và browser retest khi bên sửa hoàn tất.

## Phạm vi và bằng chứng

- Mã hiện tại trong `wp-content/plugins/mini-erp`, SPEC và các contract liên quan; không lấy diff của Git cha trên Desktop làm phạm vi.
- Hash gộp 48 file plugin, loại vendor: `025d5088cabbf0049d4f68a1953e648297536c3ad6c50b951eb7c82c9b93536a`. Cách tính: sort đường dẫn, cập nhật SHA-256 bằng đường dẫn tương đối từ cwd rồi nội dung từng file.
- Docker WordPress/MySQL đang healthy. PHPUnit: **65 tests / 4.168 assertions pass**.
- `sales-smoke`, `inventory-smoke`, `reports-smoke`: pass; fixture tables được cleanup. Đây là smoke của các đường đã viết, chưa phải toàn bộ nghiệp vụ được nghiệm thu.
- Browser thật: đăng nhập tài khoản ERP Manager tạm; mở dashboard và sales; chọn bộ lọc; đổi vai trò của chính tài khoản tạm sang Accountant để kiểm tra các route được phép. Phiếu chi bị fatal nhưng HTTP vẫn trả **200**.
- View desktop 1440×900 và view hẹp thực đo `innerWidth=500`. Tool giả lập mobile bị lỗi; resize yêu cầu 390 nhưng browser giữ tối thiểu 500. **Chưa xác nhận 390px, điện thoại thật hoặc trang có nhiều dữ liệu.**
- Dữ liệu nghiệp vụ hiện có trên các màn đã kiểm tra là rỗng. Không suy diễn đã kiểm tra visual của đơn nhiều dòng, chứng từ dài hoặc tất cả role/scope.

## Lỗi cần xử lý

### F01 — P1: Kỳ đối soát CLOSED vẫn bị thay đổi bởi giao dịch lùi ngày

Vị trí: [PaymentService.php:61](../../wp-content/plugins/mini-erp/src/Application/Services/PaymentService.php#L61), nhánh thanh toán tương tự tại dòng 38–54; [ReconciliationService.php:32](../../wp-content/plugins/mini-erp/src/Application/Services/ReconciliationService.php#L32).

Đã tái hiện bằng service thật và MySQL, với tài khoản/quỹ/batch trong bảng fixture riêng:

1. Tạo và kích hoạt quỹ opening 1.000 VND.
2. Accountant tạo đối soát ngày hôm qua, opening/closing 1.000; Manager khác người tạo đóng kỳ.
3. Accountant ghi chi 100 VND vào 12:00 ngày hôm qua.
4. Ghi thành công: batch vẫn CLOSED, statement closing 1.000, ledger closing 900, movement count 1, matched count 0.

```text
ISOLATION=REPEATABLE-READ
BEFORE={"status":"CLOSED","statement_closing_vnd":"1000"}
AFTER_EXPENSE_ID=1 {"status":"CLOSED","statement_closing_vnd":"1000","actual_closing":"900","movement_count":"1","matched_count":"0"}
CLEANUP_VERIFIED=paxg_test_audit_84f1834142_
```

Hướng sửa: kiểm soát mọi cash movement dưới cùng account lock; từ chối ngày làm thay đổi flow hoặc opening của kỳ đã đóng. Chỉ kiểm tra ngày nằm giữa period_from/period_to là chưa đủ: giao dịch trước kỳ cũng làm đổi opening. Áp dụng nhất quán cho payment, expense và reversal theo quy tắc ngày hiệu lực.

Script tái hiện: [evidence/reproduce-closed-period.php](evidence/reproduce-closed-period.php), chỉ chạy CLI trong Docker local; tạo/xóa đúng các bảng fixture, không phải script sửa dữ liệu.

### F02 — P1: Không lưu được phân quyền kho/quỹ khác rỗng

Vị trí: [UserAccessController.php:172](../../wp-content/plugins/mini-erp/src/Security/UserAccessController.php#L172), dòng 179–180, 213–222.

`update_user_meta()` ghi assignment dạng array; `storedIds()` đọc lại qua `parseIds()`, nhưng hàm đó chỉ chấp nhận string/int. Mảng bị đọc thành rỗng, kiểm tra sau ghi thất bại và rollback. Phạm vi có sẵn cũng hiện rỗng trong form; lưu form đó có thể vô tình xóa assignment.

Runtime trên user tạm: metadata `[987654]`, `storedIds()` trả `[]`; ID này chỉ dùng kiểm tra kiểu dữ liệu, không phải kho thật. Đã xóa metadata kiểm tra. Không có nghĩa Actor cũng đọc sai: `CapabilityPolicy` có parser mảng riêng. Chính sự khác nhau giữa hai parser tạo lỗi này.

Ảnh hưởng: không thiết lập được đường dùng kho/quỹ qua màn ERP users; kích hoạt quỹ, thu chi, giao nhận có thể mắc kẹt vì thiếu assignment. Cần thống nhất định dạng lưu và parser, đồng thời kiểm tra create/edit/readback qua HTTP.

### F03 — P1: Màn Phiếu chi của Accountant bị fatal

Vị trí: [CashController.php:77](../../wp-content/plugins/mini-erp/src/Admin/Controllers/CashController.php#L77), allowlist dòng 91.

`renderExpenses()` gọi `table(..., 'erp_expenses')`, trong khi allowlist của helper không có bảng này. Browser đăng nhập Accountant mở route và chỉ thấy shell, main rỗng; Apache ghi `Uncaught DomainException` từ dòng 91. Response vẫn 200 nên health check theo HTTP status sẽ bỏ sót lỗi.

Hướng sửa: đồng bộ table mapping và kiểm tra nội dung bắt buộc của route theo role. Thêm lớp xử lý lỗi render để báo lỗi an toàn với status phù hợp; cần quyết định trước khi phát body/header.

Trạng thái cuối: nguyên nhân allowlist đã được thay đổi song song; xem ghi chú đầu báo cáo về lỗi cú pháp trong lần tái kiểm tra. Ảnh và Apache stack trace ở đây là bằng chứng của bản trước thay đổi đó.

### F04 — P1: Form kiểm kê từ chối mọi key sản phẩm bình thường

Vị trí: [InventoryController.php:29](../../wp-content/plugins/mini-erp/src/Admin/Controllers/InventoryController.php#L29), input `counts[product_id]` tại dòng 75.

PHP tự biến key `counts[123]` thành integer 123. Controller chỉ chấp nhận key là string, đổi integer thành 0 rồi redirect invalid. Vì vậy nhập số đếm và gửi duyệt từ form đều bị chặn trước service.

Đã xác nhận bằng PHP parsing và gọi controller thật với Actor Warehouse tạm, nonce hợp lệ, payload `counts[123]=2.000`: redirect `notice=invalid`. Kiểm tra dùng adjustment ID giả và bị từ chối trước khi đọc/ghi adjustment; không tạo kiểm kê nghiệp vụ. Cảnh báo header trong CLI là do hook bằng chứng in URL trước redirect, không phải lỗi UI được báo ở đây.

Hướng sửa: chấp nhận integer key dương và canonical string hợp lệ; test bằng payload mà PHP thực sự giải mã từ form.

### F05 — P2: Bộ lọc trạng thái sales/purchase không hoạt động

Vị trí: [SalesController.php:128](../../wp-content/plugins/mini-erp/src/Admin/Controllers/SalesController.php#L128), [PurchaseController.php:52](../../wp-content/plugins/mini-erp/src/Admin/Controllers/PurchaseController.php#L52).

`sanitize_key()` đổi CONFIRMED thành confirmed, trong khi allowlist và option dùng uppercase. SQL không thêm điều kiện trạng thái. Browser chọn “Đã xác nhận”, URL có `status=CONFIRMED` nhưng màn trở về “Tất cả”. Logic SQL xác nhận nguyên nhân; dữ liệu thật đang rỗng nên chưa so sánh số dòng giữa các trạng thái.

Hướng sửa: chuẩn hóa enum theo cùng một convention và test kết quả với ít nhất hai trạng thái khác nhau.

### F06 — P2: Sau 100 chứng từ, mất đường truy cập thao tác qua UI

Vị trí: [CashController.php:65](../../wp-content/plugins/mini-erp/src/Admin/Controllers/CashController.php#L65), [InvoiceController.php:56](../../wp-content/plugins/mini-erp/src/Admin/Controllers/InvoiceController.php#L56).

Danh sách payment chỉ lấy 100 mới nhất, không search/pagination/detail lookup. Nút request/approve/apply void chỉ có trên danh sách này: payment cũ hơn mất đường thao tác bình thường. Redirect có `record` nhưng render không dùng nó để tải bản ghi. Invoice list và nguồn đủ điều kiện phát hành cũng có hard limit 100. Invoice có thể mở bằng URL ID nếu biết, nhưng UI không cung cấp đường tìm chứng từ cũ.

Đây là kết luận từ code, chưa seed 101 chứng từ. Hướng sửa: pagination/search trên server và detail route độc lập, luôn giữ scope trong query/count/detail. Không chỉ tăng LIMIT.

### F07 — P2: Exception migration không được catch đúng namespace

Vị trí: [Bootstrap.php:103](../../wp-content/plugins/mini-erp/src/Bootstrap.php#L103).

Class thuộc namespace MiniERP nhưng `catch (Throwable)` không import `Throwable`; nó tham chiếu `MiniERP\Throwable`, không bắt global exception từ migration. Khi migration lỗi, request có thể fatal thay vì hiện thông báo đã thiết kế. Kết luận theo mã/ngữ nghĩa PHP; chưa cố tình làm hỏng schema thật.

Ngoài ra upgrade chỉ đăng ký `admin_init` priority 1, còn router redirect ERP Actor ở priority 0. Frontend không có schema gate trước thao tác nghiệp vụ. Cần kiểm soát version/readiness rõ ràng trên mọi entry point, chạy upgrade có khóa, và dừng ghi khi schema chưa sẵn sàng. Chưa kiểm thử nâng cấp đồng thời hay fault injection.

### F08 — P2: Snapshot hóa đơn thiếu phân bổ làm tròn từng dòng

Vị trí: [InvoiceService.php:87](../../wp-content/plugins/mini-erp/src/Application/Services/InvoiceService.php#L87), [InvoiceSchema.php:82](../../wp-content/plugins/mini-erp/src/Infrastructure/Database/InvoiceSchema.php#L82), [InvoiceDocumentService.php:45](../../wp-content/plugins/mini-erp/src/Application/Services/InvoiceDocumentService.php#L45).

Calculator trả `rounding_allocation_vnd` và `line_subtotal_vnd` đã phân bổ, nhưng service/schema invoice items không lưu hai trường đó. HTML/PDF không cung cấp dấu vết phân bổ từng dòng như contract PRICE-1 yêu cầu. Đây là thiếu snapshot/khả năng đối chiếu, chưa kết luận tổng tiền chứng từ bị tính sai.

Hướng sửa: giữ đủ snapshot cần tái lập số tiền, hiển thị diễn giải phù hợp và test hóa đơn có nhiều dòng tiền lẻ. Cần migration cho chứng từ cũ theo chính sách rõ ràng.

## Kiến trúc: điểm phù hợp và điểm cần củng cố

### Giữ nền tảng monolith, củng cố ranh giới

- Service đã tách khỏi controller; có state machine, số học tiền chính xác, transaction, ledger và idempotency. Các smoke vừa chạy chứng minh một số đường thực sự hoạt động.
- Tuy vậy controller vừa SQL, scope, parse form, render HTML và quyết định workflow. Nhiều hàm bị nén thành một dòng rất dài, làm review và phân biệt lỗi quyền/dữ liệu/DB khó hơn. Tách query/read model và template theo module; giữ application service làm nơi quyết định invariant khi ghi.
- Không cần xây abstraction tổng quát trước. Bắt đầu từ parser assignment, cash-period guard, và query có scope đang bị lặp.

### ReadPolicy chưa được dùng làm nguồn chính sách chung

Tìm toàn bộ `src` chỉ thấy định nghĩa class ReadPolicy, không thấy consumer. Controllers và services dựng điều kiện riêng. Có độ lệch cụ thể: ReadPolicy cho Warehouse đọc sales CONFIRMED/SHIPPED, controller dùng CONFIRMED/PARTIALLY_SHIPPED; purchase policy cho all khi có `erp_purchases_view`, trong khi controller Warehouse vẫn scope theo kho.

Chưa chứng minh được một đường IDOR runtime. Đây là rủi ro policy drift có bằng chứng. Cần thống nhất scope descriptor/projection cho list, count, detail, document và action; chạy matrix role × owner × warehouse/account × state.

### Transaction có khóa chưa đủ để chứng minh an toàn race

DB hiện dùng REPEATABLE-READ. Một số đường như `applyVoid()` và `close()` đọc thường trước khi lấy account lock, rồi kiểm tra aggregate bằng SELECT thường sau khi chờ lock. Có nguy cơ kiểm tra trên snapshot cũ. **Chưa có deterministic race reproduction**, không gộp vào lỗi đã xác nhận F01.

Cần test hai connection điều khiển lịch khóa/commit cho payment, expense, void, match, close; sau đó chọn locking read/transaction boundary/isolation phù hợp. Không đổi isolation toàn hệ thống chỉ để làm test xanh.

## UI và nghiệp vụ thực tế

- Desktop: shell và điều hướng theo role rõ; dashboard phân biệt doanh thu hóa đơn với tiền thu, thể hiện thiếu assignment. Đây là điểm tốt đã nhìn trên browser.
- View hẹp 500px: menu thành dải cuộn ngang và bảng sales cũng cuộn ngang. Với nhiều module, thao tác bị khuất và phải dò ngang; nên có menu thu gọn và cách ưu tiên các cột/hành động. Đây là nhận xét usability, không phải kết luận vỡ layout ở 390px.
- Form sales cố định 5 dòng, trong khi backend nhận tới 20; form mua cũng cố định ít dòng. Nếu đơn >5 dòng được tạo bằng service/POST rồi sửa bằng UI, có nguy cơ bỏ mất phần không được render. Chưa kiểm tra có đơn thực tế dạng này. Cần đồng bộ giới hạn và có nút thêm/xóa dòng.
- Sales dùng “basis points”, trạng thái/raw UTC và role tiếng Anh xuất hiện ở nhiều nơi. Nên cho nhập phần trăm quen thuộc, preview tổng tiền/chiết khấu/thuế và chuyển ngày giờ sang múi giờ nghiệp vụ; ghi rõ khi một giá trị là UTC.
- Chi tiết sales/purchase đang tổng hợp đã giao/nhận nhưng chưa có timeline từng phiếu đủ để truy từ đơn tới các đợt giao/nhận, hóa đơn và thanh toán. Cần đường liên kết chứng từ và trạng thái còn lại để nhân viên trả lời “đợt nào, ngày nào, đã xuất hóa đơn/thu tiền chưa”. Đây là phần hoàn thiện workflow, không yêu cầu thêm returns hay payment gateway.
- Lỗi form thường redirect về thông báo chung và không giữ lại input. Cần lỗi cụ thể theo trường và giữ nháp để tránh nhân viên nhập lại đơn.

Đối chiếu nguồn chính thức: Odoo phân biệt chính sách hóa đơn theo lượng đặt/giao và theo dõi delivered/invoiced quantities ([Odoo invoicing policies](https://www.odoo.com/documentation/18.0/applications/sales/sales/invoicing/invoicing_policy.html)); ERPNext phân biệt đối soát ngân hàng với đối chiếu phân bổ thanh toán ([Bank Reconciliation](https://docs.frappe.io/erpnext/bank-reconciliation), [Payment Reconciliation](https://docs.frappe.io/erpnext/payment-reconciliation)). Đây là mốc tham khảo luồng thực tế, không phải yêu cầu triển khai toàn bộ tính năng của các sản phẩm đó.

## Thứ tự xử lý đề xuất

1. Chặn sai lệch kỳ đối soát; sửa assignment, Phiếu chi và form kiểm kê. Thêm regression test đúng boundary của từng lỗi.
2. Sửa filter và pagination/detail của chứng từ; kiểm tra một luồng mua → nhận → hóa đơn → chi và bán → giao nhiều đợt → hóa đơn → thu qua HTTP với các role riêng.
3. Củng cố read policy/query, migration gate và kiểm thử race; hoàn thiện snapshot hóa đơn.
4. Sau khi luồng đúng mới tinh chỉnh UI: chứng từ liên kết, form nhiều dòng, lỗi giữ input, ngày giờ và view mobile thật.
5. Trước nghiệm thu vận hành: role/object/field matrix đầy đủ, backup/restore có diễn tập, dữ liệu đủ lớn và fault/race tests. Những phần này chưa được xác nhận trong đợt review này.

## Ảnh chụp

- [Dashboard desktop — Accountant](evidence/dashboard-desktop.png)
- [Sales ở view hẹp thực tế 500px](evidence/sales-narrow-500.png)
- [Phiếu chi chỉ còn shell sau fatal](evidence/expenses-fatal.png)

## Re-review — payment posting versus reconciliation close race (2026-10-20)

The race concern above was explicitly unconfirmed at audit time. A later two-worker runtime exercise ran the real payment-post and reconciliation-close services against isolated InnoDB tables under `REPEATABLE-READ`. Both operations waited on the same cash-account row lock before proceeding.

- When close won the lock, it committed CLOSED; payment then observed that closed period and wrote no payment or cash movement.
- When payment won the lock, it committed its payment and cash movement; close then observed the updated ledger and rejected, leaving the batch DRAFT.

The fixture tables were dropped and cleanup verified after both runs. No race was reproduced in this pair, so no isolation-level or service-code change is justified by this evidence. This does not close the broader race review for expenses, void application, reconciliation matching, or deadlock/retry behavior. The historical observation that some lookups occur before a transaction remains true, but those lookups are outside the transaction snapshot; both service paths take the account serialization lock before the ledger's first ordinary aggregate read. MySQL documents that `REPEATABLE READ` establishes its consistent-read snapshot on the first consistent read ([consistent nonlocking reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-consistent-read.html)) and that locking reads wait for concurrent changes and use the latest values ([locking reads](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html)).

## Follow-up — schema readiness gate for ERP AJAX lookups (2026-10-21)

F07 was partially addressed after this audit: `Bootstrap` now imports global `Throwable`, attempts idempotent upgrade on `init`, shows an upgrade error notice, and blocks ERP frontend screens and `mini_erp_*` admin-post actions while the schema version is not ready. This continuation found that the three authenticated AJAX lookup actions (`erp_search_products`, `erp_search_customers`, `erp_search_suppliers`) use another namespace and bypassed that action-prefix gate. `Bootstrap::isSchemaGatedAction()` now enumerates those exact actions and keeps unrelated WordPress actions outside the gate.

Evidence: the current PHPUnit suite passes 83 tests / 4,246 assertions. `ajax-smoke` exercises a pending schema-version marker through the WordPress `wp_die_handler` and confirms a 503 for `erp_search_products`, while `profile_update` is not intercepted; it restores the original option and request state. The normal authenticated AJAX smoke then passes role and warehouse projections with exact fixture cleanup. This tests the registered gate method in the local WordPress process, not a separate HTTP request served while the schema is actually unavailable. Concurrent full-upgrade interleavings, hard missing-table faults and the complete route matrix remain open; F07 is not fully accepted for production readiness.
