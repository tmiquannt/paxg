# SOFTWARE REQUIREMENTS & TECHNICAL DESIGN SPECIFICATION
## Mini ERP nội bộ – WordPress Native (PHP + MySQL)

**Mã tài liệu:** WP-ERP-SPEC-001 / REV-1.1  
**Phiên bản:** 1.1 (Accounting & Payments Release)  
**Ngày:** 19/10/2026  
**Trạng thái:** Baseline thiết kế đã cập nhật; chưa phải phần mềm đã triển khai/kiểm thử  
**Đối tượng:** Đồ án tốt nghiệp / MVP vận hành nội bộ quy mô nhỏ  
**Ngôn ngữ giao diện:** Tiếng Việt; **tiền tệ:** VND; **múi giờ nghiệp vụ:** Asia/Ho_Chi_Minh

> **Tuyên bố:** Đây là đặc tả đủ để tổ chức phát triển, triển khai và nghiệm thu một hệ thống có thể vận hành. Không có nghĩa hệ thống đã được lập trình, kiểm thử bảo mật hay đạt chứng nhận. Tất cả chỉ tiêu vận hành là mục tiêu nghiệm thu dự kiến.

## Nhật ký thay đổi (Change Log)

| Phiên bản | Thay đổi chính | Tính tương thích |
|---|---|---|
| 1.0 | Kiến trúc nền tảng, sales/purchasing/stock, invoice và payment mức cơ bản | Baseline ban đầu |
| **1.1** | Hoàn thiện dòng hàng hóa đơn, giao/nhận nhiều đợt, bản in/PDF, lịch sử thanh toán, hạn nợ, tài khoản quỹ, dòng tiền, đối soát thủ công, đảo bút toán có duyệt, RBAC, UI, giao dịch, test | Migration tăng version, bổ sung bảng/cột/index; giữ nguyên chứng từ cũ |

**Nguyên tắc nâng cấp:** tất cả yêu cầu P0 được đặc tả nhất quán đến lớp UI → permission → service → schema → transaction → report → test; mô tả thiết kế **không** xác nhận mã nguồn đã được viết.

## 0. Tóm tắt và quyết định kiến trúc

**Phương án được chọn:** Một website WordPress duy nhất, một **custom plugin `mini-erp`** chứa ERP app PHP, controller, service, repository và security layer; dữ liệu nghiệp vụ giao dịch trong **custom tables MySQL/InnoDB**. ERP dùng giao diện riêng ở site root và các route module bên ngoài `/wp-admin`; WordPress Core vẫn cung cấp đăng nhập, session, danh tính người dùng, roles/capabilities, nonce, hooks và cấu hình chung. Super Admin ERP tạo tài khoản nhân viên, gán role ERP và phạm vi truy cập ngay trong màn Người dùng ERP. `/wp-admin` chỉ dành cho quản trị WordPress kỹ thuật và khởi tạo/khôi phục quyền ERP ban đầu. Không dùng WooCommerce, page builder, React, microservices hoặc backend ngoài.

| Quyết định | Phương án chính thức | Lý do |
|---|---|---|
| UI | ERP frontend shell + PHP template + CSS/JS | Một codebase; phân biệt màn nghiệp vụ với quản trị WordPress |
| Form thay đổi dữ liệu | `admin-post.php`, phương thức POST; AJAX chỉ cho tương tác cần thiết | Sẵn có trong WordPress, ít bề mặt API |
| Authorization | Capabilities + object ownership/scope + workflow checks | Chống truy cập trái phép và bypass trạng thái |
| Dữ liệu ERP | Bảng riêng tiền tố `$wpdb->prefix . 'erp_'` | Có chỉ mục, transaction, tính toàn vẹn rõ hơn post meta |
| Kho | Stock ledger + balance, cập nhật nguyên tử | Không âm tồn, truy vết toàn bộ nhập/xuất |
| Kế toán | Hóa đơn nội bộ bất biến + PDF/in + thanh toán nhiều lần + sổ quỹ/đối soát + công nợ | Phù hợp thời gian đồ án; không thay thế phần mềm kế toán tuân thủ pháp luật |
| Triển khai | Một site, một tổ chức, single-site WordPress | Không cần multi-tenant hoặc multisite |

**Nguồn nền tảng:** WordPress Roles & Capabilities [S1], custom tables [S2], administration menu [S3], admin-post [S4], Nonces [S5], MySQL InnoDB [S9–S10], OWASP API Security [S11].

## 1. Phạm vi, giả định, ngoài phạm vi

### 1.1 Phạm vi nghiệp vụ phiên bản 1.1 (MUST)

1. Quản trị tài khoản nhân viên và phân vai trò ERP.
2. Sản phẩm, danh mục, SKU, giá bán, trạng thái kinh doanh.
3. Khách hàng và nhà cung cấp.
4. Mua hàng: đơn mua, duyệt, nhận đủ hoặc nhận nhiều phần có theo dõi phần còn lại.
5. Bán hàng: tạo đơn, xác nhận/duyệt, giao đủ hoặc giao nhiều phần có theo dõi phần còn lại.
6. Kho: đa kho, tồn theo kho, sổ biến động, kiểm kê và điều chỉnh có duyệt.
7. Hóa đơn bán/mua **nội bộ** có dòng hàng snapshot, in/xuất PDF được kiểm soát quyền.
8. Lịch sử thanh toán nhiều lần theo hóa đơn/khách hàng, phiếu thu/chi, số còn phải thu/phải trả và cảnh báo quá hạn.
9. Quản lý tài khoản quỹ tiền mặt/ngân hàng **nội bộ**, sổ quỹ bất biến, đối soát thủ công theo kỳ và đảo bút toán khi ghi sai theo phê duyệt.
10. Dashboard báo cáo doanh thu, dòng tiền, công nợ theo tuổi nợ, tồn kho và đơn hàng.
11. Audit log cho thay đổi quan trọng, sao lưu/khôi phục, kiểm thử bảo mật.

### 1.2 Giả định thiết kế

- Một công ty; tối đa **5 vai trò nghiệp vụ**; một người dùng giữ **một vai trò ERP chính**. Tài khoản WordPress Administrator là tài khoản hạ tầng/break-glass riêng.
- Nhiều kho, nhưng ở MVP một đơn bán/mua gắn một kho; hỗ trợ nhiều lần giao/nhận một phần và theo dõi phần còn lại (backorder) trên cùng chứng từ nguồn. Không giữ chỗ tồn kho khi xác nhận đơn bán: chỉ hiển thị `on_hand` tham khảo, không gọi là `available`; shipment phải khóa và kiểm tra số dư thực trước khi post.
- Hệ thống quản lý **hàng hóa hữu hình**; không có lô, hạn dùng, serial, chuyển kho hay BOM/sản xuất.
- Giá bán, chiết khấu và tiền thu được tính bằng **số nguyên VND (`BIGINT`)**, không dùng PHP float để tính tiền. Số lượng dùng `DECIMAL(18,3)` nếu sản phẩm có đơn vị lẻ; với hàng đếm cái, kiểm tra bội số 1.
- Thuế là cấu hình tỷ lệ đơn giản và **tách khỏi giá bán**; PDF được ghi rõ **CHỨNG TỪ NỘI BỘ — KHÔNG PHẢI HÓA ĐƠN ĐIỆN TỬ**. Thời điểm/phân loại/thuế cho hóa đơn pháp lý Việt Nam cần thẩm định trước khi sử dụng thật.
- Báo cáo tài chính chỉ gồm tổng hợp thu, chi, doanh thu, công nợ; **không** triển khai sổ cái, định khoản kép, báo cáo thuế, kết chuyển kế toán.

### 1.3 Ngoài phạm vi v1.1 (WON'T)

SaaS/multi-tenant, WooCommerce storefront, POS offline, thanh toán online/payment gateway, phát hành hóa đơn điện tử hợp pháp, accounting ledger đầy đủ, đa ngoại tệ, giá vốn FIFO, đổi/trả hàng và hoàn tiền thật (P1 sau v1.1), chiết khấu nhiều tầng, đồng bộ phần mềm khác, ứng dụng di động, workflow engine tổng quát.

### 1.4 Những thay đổi phải thông qua change request

Thay đổi bổ sung hoàn/đổi hàng, đa chi nhánh hay kết nối thanh toán làm đổi dữ liệu, nghiệp vụ và test suite; không thêm trực tiếp vào MVP khi chưa cập nhật spec.

### 1.5 Change request CR-01 — luồng ERP thực tế (2026-10-20)

Theo dõi nguồn chính thức từ Odoo, Microsoft Dynamics 365 Business Central và SAP Business One cho thấy chứng từ được liên kết theo chuỗi; giao/nhận có thể hoàn tất từng phần, phần còn lại thành backorder; hóa đơn có thể căn cứ lượng đã giao; phân quyền gồm cả permission set và record scope; duyệt có thể bật theo điều kiện chứng từ. Áp dụng các nguyên tắc này trong phạm vi một kho trên mỗi order: nhiều shipment/receipt, cập nhật lượng còn lại, invoice theo shipment/receipt, phê duyệt theo capability/ngưỡng và đối tượng. Không giữ chỗ stock lúc xác nhận sales order; on-hand chỉ để tham khảo, còn shipment phải khóa và kiểm tra tồn trước khi post. Không thêm multi-warehouse allocation, returns/refunds, payment gateway, hoặc workflow engine tổng quát. Nguồn cụ thể và mapping được lưu tại `docs/research/practical-erp-workflows.md`.

### 1.6 Change request CR-02 — ERP app ở site root (2026-10-20)

Theo yêu cầu UI: truy cập trực tiếp `/` mở ERP app sau đăng nhập; các module ERP dùng route frontend như `/?erp_page=mini-erp-sales`; `/wp-admin/` vẫn dành cho quản trị WordPress và cấp quyền ERP ban đầu. Session/capability WordPress tiếp tục là lớp xác thực/phân quyền. Các thao tác thay đổi dữ liệu vẫn POST qua `admin-post.php` với nonce, capability, object/scope và workflow guard như trước. Tài khoản WordPress Administrator không tự trở thành Actor ERP.

## 2. Kiến trúc logic và luồng request

```text
Browser (HTTPS; WordPress admin authenticated cookie)
  |
  +--> GET / or /?erp_page=mini-erp-... --> ERP App Router --> Page Controller --> PHP Template
  |
  +--> /wp-admin/ -----------------------> WordPress technical administration / initial ERP access setup
  |
  +--> POST wp-admin/admin-post.php?action=erp_...
  |       -> CSRF/nonce -> Capability -> Object/Scope -> Validation
  |       -> Application Service -> Repository ($wpdb) -> InnoDB
  |       -> Redirect + one-time notice
  |
  +--> POST wp-admin/admin-ajax.php?action=erp_... (chỉ lookup/filter)
          -> check_ajax_referer -> Capability -> scoped query -> JSON

WordPress Core: user, session, password, capabilities, routing hooks
Mini ERP plugin: rules, UI, operations, audit, DB schema, reporting
MySQL: core WP tables + custom ERP transaction tables (InnoDB)
```

### 2.1 Các lớp trong plugin

| Layer | Trách nhiệm | Không được làm |
|---|---|---|
| UI / Templates | Render HTML, `esc_*`, biểu mẫu, danh sách, menu | SQL và tính tiền trực tiếp |
| Admin Controllers | Nhận request, kiểm tra method/nonce/capability, chuyển DTO vào service, PRG redirect | Tin tổng tiền/trạng thái do client gửi |
| Authorization Policy | Kiểm tra role capability + owner, assigned warehouse + trạng thái | Chỉ dựa vào ẩn menu/nút |
| Application Services | Workflow, validation nghiệp vụ, tính toán, transaction, idempotency | Truy cập `$_POST`/render HTML |
| Repositories | SQL prepared queries, row locking, persistence | Tự ý quyết định quyền nghiệp vụ |
| Infrastructure | Database migration, logging, cron, export, backup hooks | Để cron quyết định thời điểm giao dịch tiền/kho |

### 2.2 Cấu trúc thư mục chuẩn

```text
wp-content/plugins/mini-erp/
  mini-erp.php                     # bootstrap, plugin metadata, constant
  uninstall.php                    # KHÔNG xóa ERP data tự động, trừ khi có opt-in rất rõ
  composer.json                    # PSR-4 autoload dev optional
  src/
    Bootstrap.php
    Admin/MenuRegistrar.php
    Admin/Assets.php
    Admin/Controllers/{Product,Sales,Purchase,Inventory,Accounting,Invoice,Payment,Cash,Reconciliation}Controller.php
    Security/{RolesInstaller,CapabilityPolicy,OrderPolicy,NonceGuard}.php
    Domain/{Sales,Purchase,Inventory,Accounting}/
    Application/Services/{SalesOrderService,PurchaseOrderService,StockService,InvoiceService,PaymentService,CashLedgerService,ReconciliationService,PdfDocumentService,ReportService}.php
    Infrastructure/Database/{Installer,Migrator,TransactionManager}.php
    Infrastructure/Repositories/*.php
    Infrastructure/Audit/AuditLogger.php
    Infrastructure/Jobs/*.php
  templates/{dashboard,products,customers,sales,purchases,inventory,accounting,invoices,payments,cash,reconciliations,reports,users}/*.php
  assets/{css,js}/
  languages/
  tests/{Unit,Integration,Security}/
  README.md
```

### 2.3 Nguyên tắc WordPress integration

- Đăng ký màn nghiệp vụ qua ERP frontend router; kiểm tra capability trước khi render và tiếp tục kiểm tra trong controller. Chỉ màn cấp quyền ERP ban đầu đăng ký dưới WordPress `admin_menu` [S3].
- Form mutate dùng `admin_post_erp_*`; tuyệt đối không khai báo `admin_post_nopriv_erp_*` cho các nghiệp vụ [S4]. Sử dụng POST→Redirect→GET để tránh submit lại khi refresh.
- UI có thể dùng admin CSS mặc định + file CSS namespaced `.erp-*`; không tùy biến WordPress core và không nhét logic vào theme.
- `admin-ajax.php` chỉ dành cho tìm kiếm khách hàng, sản phẩm, autocomplete/preview; mỗi handler kiểm tra nonce + cap + dữ liệu theo phạm vi.
- Không dùng `wp_posts/wp_postmeta` cho đơn hàng, số dư kho, thanh toán và biến động tồn vì các bản ghi này cần transaction và ràng buộc riêng. Dùng WP Options chỉ cho cài đặt nhỏ, không phải trạng thái giao dịch.
- Kích hoạt plugin: chạy kiểm tra môi trường, migration có phiên bản, đăng ký roles/caps. **Không DROP bảng khi deactivate** [S2, S17].

## 3. Vai trò và mô hình quyền

### 3.1 Danh sách vai trò

| Role slug | Tên | Nhiệm vụ |
|---|---|---|
| `erp_system_admin` | System Admin | User ERP, role/cap ERP, audit, cấu hình; không sửa tài chính/kho mặc định |
| `erp_manager` | Manager | Danh mục, giá, duyệt mua hàng/đơn bán/điều chỉnh; xem báo cáo |
| `erp_sales` | Sales | Khách hàng, đơn bán của mình, tìm sản phẩm và tồn khả dụng |
| `erp_warehouse` | Warehouse | Xem nghiệp vụ kho, nhập, xuất, kiểm kê, đề nghị điều chỉnh |
| `erp_accountant` | Accountant | Hóa đơn nội bộ, thu/chi, công nợ, báo cáo tài chính |

**Điểm quan trọng:** `administrator` WordPress là **technical superuser**, có khả năng thay đổi plugin và quyền trong hệ thống. Không thể kỳ vọng capability ở custom plugin cách ly được tài khoản này hoàn toàn. Tạo 01 tài khoản Administrator dành cho quản trị máy chủ (MFA, hạn chế dùng thường nhật), còn `erp_system_admin` để quản trị ERP hằng ngày.

**Quy tắc duy nhất:** chỉ một tài khoản WordPress thường được giữ role `erp_system_admin` trong tổ chức. WordPress technical administrator là đường bootstrap/khôi phục riêng, không được tính là ERP System Admin. Khi đã có một ERP System Admin, màn Người dùng ERP vẫn cho phép người này gán các role nghiệp vụ, bật/tắt người dùng và gán kho/quỹ cho tài khoản khác; việc cấp thêm role `erp_system_admin` bị chặn. Khi dữ liệu cũ có nhiều System Admin, một trong số họ có thể hạ role của tài khoản còn lại để khôi phục trạng thái duy nhất.

### 3.2 Permission catalog v1.1

| Nhóm | Các capability ERP |
|---|---|
| Users & System | `erp_dashboard_view`, `erp_users_view`, `erp_users_manage`, `erp_roles_manage`, `erp_settings_manage`, `erp_audit_view` |
| Master data | `erp_products_view`, `erp_products_manage`, `erp_customers_manage`, `erp_suppliers_view`, `erp_suppliers_manage`, `erp_warehouses_view`, `erp_warehouses_manage` |
| Sales | `erp_sales_create`, `erp_sales_view_own`, `erp_sales_view_all`, `erp_sales_confirm`, `erp_sales_approve`, `erp_sales_cancel` |
| Purchase | `erp_purchases_create`, `erp_purchases_view`, `erp_purchases_approve`, `erp_purchases_cancel` |
| Inventory | `erp_stock_view`, `erp_stock_receive`, `erp_stock_ship`, `erp_stock_count`, `erp_stock_adjust_request`, `erp_stock_adjust_approve`, `erp_stock_adjust_apply` |
| Invoice | `erp_invoices_view`, `erp_invoice_sources_view_all`, `erp_invoice_sources_view_own`, `erp_invoices_issue`, `erp_invoices_print`, `erp_invoices_void` |
| Payments | `erp_payments_view`, `erp_payments_record`, `erp_payments_void_request`, `erp_payments_void_approve`, `erp_payments_void_apply` |
| Cash book | `erp_cash_accounts_view`, `erp_cash_accounts_manage`, `erp_cash_accounts_activate`, `erp_cash_book_view`, `erp_expenses_manage` |
| Reconciliation | `erp_reconciliations_create`, `erp_reconciliations_close`, `erp_reconciliations_view` |
| Reports | `erp_reports_sales`, `erp_reports_stock`, `erp_reports_finance`, `erp_reports_receivables` |

**Nguyên tắc:** `erp_invoices_print` chỉ cho bản ghi mà actor được phép đọc; mọi download PDF yêu cầu xác thực/ủy quyền. `erp_payments_void_approve` thuộc Manager, **không** được cấp cho Accountant trong cấu hình 5-role mặc định; Accountant yêu cầu và áp dụng sau duyệt. Quyền truy cập dữ liệu tài chính qua menu khác nhau nhưng cùng một policy backend.

`erp_invoices_view` là cổng chức năng chung; `erp_invoice_sources_view_all` và `erp_invoice_sources_view_own` tách riêng phạm vi nguồn chứng từ. Manager/Accountant nhận quyền all-source; Sales chỉ own-sales. Các capability `erp_suppliers_view`, `erp_warehouses_view/manage` tách đọc khỏi ghi master data. `erp_dashboard_view` chỉ cho phép vào màn tổng quan; KPI và từng trường vẫn phải qua projection/policy theo role.

### 3.3 Role–permission matrix (v1.1)

Ký hiệu: `✓` toàn phạm vi theo policy; `own` bản ghi do mình tạo; `queue` hàng chờ theo kho; `read` chỉ đọc; `—` không cấp. Manager **không được ghi nhận thanh toán**, SysAdmin ERP **không tự động có quyền tài chính**.

| Chức năng | SysAdmin | Manager | Sales | Warehouse | Accountant |
|---|:---:|:---:|:---:|:---:|:---:|
| Người dùng, ERP role, audit | ✓ | — | — | — | — |
| Product, khách hàng, nhà cung cấp | — | ✓ | khách hàng | read | read |
| Đơn bán / xem trạng thái | — | ✓ | own | queue | read |
| Phê duyệt giảm giá / đơn mua | — | ✓ | — | — | — |
| Nhập, xuất, kiểm kê, áp dụng điều chỉnh | — | duyệt | — | ✓ | — |
| Xem hóa đơn | — | read | own (đơn thuộc mình, giới hạn) | — | ✓ |
| Phát hành / in PDF hóa đơn | — | xem/in | xem/in theo own | — | ✓ |
| Lịch sử thanh toán và công nợ | — | read | own (số dư tổng) | — | ✓ |
| Ghi nhận thanh toán, phiếu thu/chi | — | — | — | — | ✓ |
| Yêu cầu void thanh toán | — | — | — | — | ✓ |
| Duyệt void thanh toán | — | ✓ | — | — | — |
| Áp dụng void đã duyệt | — | — | — | — | ✓ (khác người yêu cầu nếu có 2 accountant) |
| Quản trị tài khoản quỹ | — | kích hoạt/mở kỳ | — | — | tạo yêu cầu và quản lý |
| Sổ quỹ và đối soát | — | read / close | — | — | create/match |
| Báo cáo tài chính và tuổi nợ | — | read | own (hạn chế) | — | ✓ |

**Object/field-level policy:** Sales chỉ xem hóa đơn của đơn do mình tạo khi cần hỗ trợ khách, không được xem ghi chú kế toán, tài khoản thu tiền, giao dịch tài khoản khác hoặc thông tin ngân hàng. Warehouse chỉ thấy hàng đợi còn thao tác tại kho được gán: đơn bán `CONFIRMED`/`PARTIALLY_SHIPPED` và đơn mua `APPROVED`/`PARTIALLY_RECEIVED`; đơn nháp, đã hoàn tất hoặc thuộc kho khác không vào hàng đợi và không mở được bằng ID. Projection của Warehouse không chứa giá bán, giá nhập, tổng tiền, thông tin khách hàng hay nhà cung cấp. Manager xem thông tin tài chính theo business policy nhưng không được sửa bút toán. Đối soát chỉ tài khoản quỹ actor được phép truy cập; quyền download PDF phải kiểm tra cả invoice/source ownership.

## 4. Chi tiết yêu cầu chức năng

| ID | Module | Yêu cầu MUST (P0) | Tiêu chí chấp nhận chính |
|---|---|---|---|
| FR-ADM-01 | Admin | Tạo/vô hiệu hóa user, gán một role ERP | User bị khóa không thao tác được; log thay đổi role |
| FR-PRO-01 | Product | CRUD danh mục/sản phẩm | SKU duy nhất, không hard-delete hàng đã dùng |
| FR-CRM-01 | CRM | Quản lý khách hàng, thông tin xuất chứng từ | Thông tin khách hàng có kiểm tra quyền |
| FR-SUP-01 | Supplier | CRUD nhà cung cấp | Không xóa NCC đã phát sinh PO |
| FR-SAL-01 | Sales | Tạo, xác nhận và duyệt đơn | Server tính tiền và chốt snapshot |
| FR-PUR-01 | Purchasing | Đơn mua, duyệt, nhận một hay nhiều phần | Chỉ PO đã duyệt; tổng received không vượt ordered; phần còn lại rõ ràng |
| FR-INV-01 | Inventory | Nhập/xuất, sổ kho, kiểm kê | Không âm tồn; stock ledger bất biến |
| FR-ACC-01 | Invoice | Phát hành chứng từ nội bộ từ từng shipment/receipt đã posted | Tối đa một invoice cho từng nguồn; không invoice vượt số lượng đã giao/nhận; snapshot bất biến |
| FR-ACC-02 | Invoice | Màn hình chi tiết, print và download PDF | Quyền download đúng; watermark nội bộ; render dữ liệu snapshot |
| FR-ACC-03 | Invoice | Ngày phát hành, hạn thanh toán, status và tuổi nợ | `due_at` là mốc 23:59:59.999999 Asia/Ho_Chi_Minh của ngày hạn, chuyển UTC để lưu; phải không sớm hơn thời điểm phát hành; overdue tính theo ngày địa phương |
| FR-ACC-04 | Payment | Thu/chi nhiều lần trên cùng invoice; tiền mặt/chuyển khoản | Không thu/chi vượt dư nợ; mỗi payment duy nhất |
| FR-ACC-05 | Payment History | Danh sách timeline và tìm kiếm theo invoice/khách hàng | Có mã, amount, method, ref, actor, ngày, status |
| FR-ACC-06 | Receipt/Voucher | In/PDF phiếu thu/chi liên kết payment hoặc expense | Không tồn tại phiếu không có bút toán posted tương ứng |
| FR-ACC-07 | Cash Book | Quỹ tiền mặt/tài khoản ngân hàng nội bộ, số dư và sổ dòng tiền | Mọi posted payment/expense có cash movement, số dư đối chiếu |
| FR-ACC-08 | Correction | Yêu cầu → phê duyệt → áp dụng void payment ghi sai | Không xóa log; có bút toán đảo; maker-checker; không void nếu đã đối soát |
| FR-ACC-09 | Reconciliation | Đối soát thủ công theo kỳ với sao kê bên ngoài | Match không trùng; batch đóng bất biến; báo chênh lệch |
| FR-ACC-10 | Expenses | Ghi phiếu chi nội bộ kèm tài khoản tiền và lý do | Tạo expense + cash movement nguyên tử; không trừ tồn kho |
| FR-REP-01 | Reports | Doanh thu, tiền thực thu/chi, công nợ, tuổi nợ, sổ quỹ | Công thức và date filters đối chiếu từ chứng từ nguồn |
| FR-AUD-01 | Audit | Ghi nhận tất cả thao tác tài chính/kho/quyền | Có actor, object, before/after cho field cho phép |

**MVP hỗ trợ thu/chi sau khi có invoice, không thu đặt cọc/ứng trước; mỗi payment liên kết đúng một invoice, một tài khoản quỹ.** Nhiều phương thức thanh toán cho một invoice được thực hiện bằng nhiều payment. Chỉ phát hành một invoice/source; sửa/điều chỉnh hóa đơn đã phát hành là P1 thay vì sửa trực tiếp bản ghi.

### 4.1 Quy tắc tính tiền

**Quyết định v1.1 ngày 19/10/2026 (ưu tiên hơn mô tả cũ):** Giữ chính xác phần lẻ VND của từng dòng khi cộng; chỉ làm tròn **subtotal toàn chứng từ một lần** đến VND nguyên (half-up). Không làm tròn riêng subtotal từng dòng. Ví dụ hai dòng 0.500 VND có subtotal chứng từ 1 VND, không phải 2 VND. Chiết khấu và thuế được tính ở cấp chứng từ sau subtotal đã làm tròn; chiết khấu half-up, đề xuất thuế half-up cần được mô tả nhất quán trong hợp đồng triển khai.

**Mismatch và hướng biểu diễn đã làm rõ trước migration:** integer-only `line_total_vnd` không giữ được giá trị gốc 0.500 VND. Hợp đồng calculator `docs/pricing-contract.md` PRICE-1a giữ `line_subtotal_exact_vnd DECIMAL(22,3)`, rồi phân bổ subtotal chứng từ đã làm tròn bằng largest remainder (tie theo source-line ID bất biến), có `rounding_allocation_vnd DECIMAL(4,3)` hiển thị riêng. `line_subtotal_vnd`, discount/tax/net dòng vẫn là số nguyên **được phân bổ**, không phải làm tròn dòng độc lập. `SUM(exact dòng) + SUM(phân bổ làm tròn) = subtotal_vnd`; `SUM(line_total_vnd) = total_vnd`. **Không cộng rounding_adjustment lần nữa vào tổng net dòng.** Đây thay thế đề xuất net-dòng decimal trước đó. Document giữ exact subtotal và signed rounding adjustment để truy vết. Mọi tổng integer trong BIGINT signed; quantity tối đa 999999999999999.999; phép nhân/cộng trung gian dùng BCMath/decimal-string, không float. Invoice snapshot có implementation candidate và isolated MySQL/PDF evidence; authenticated browser review vẫn mở.

- Đơn vị tiền VND lưu nguyên số nguyên không dấu phân cách; giá/amount sử dụng `BIGINT` (MVP giá không âm, mọi giá trị giới hạn trong BIGINT signed).
- `line_subtotal = unit_price_snapshot × quantity`; hỗ trợ số lượng lẻ bằng toán decimal-string/BCMath hoặc tính theo đơn vị nhỏ nhất được chuẩn hóa, **không dùng float**.
- `discount_amount` do backend tính theo `discount_basis_points` (10000 = 100%) với phương pháp làm tròn xác định **half-up** đến đồng VND.
- `tax_amount` tính từ số tiền sau chiết khấu và tỷ lệ cấu hình; thuế đơn giản chỉ là mô phỏng nghiệp vụ, không phải chứng từ thuế điện tử hợp lệ.
- `grand_total = subtotal − discount + tax`. Lưu snapshot các số này trên đơn đã xác nhận; không tính lại theo giá catalog khi đọc đơn lịch sử.

### 4.2 Quy tắc xóa dữ liệu

Không hard-delete sản phẩm/khách hàng/NCC đã phát sinh tham chiếu, đơn hàng, hóa đơn và ledger. Dùng `is_active`, `CANCELLED`, `VOID`, các bản ghi bù/trả lại có truy vết (nếu được bổ sung sau); duy trì lịch sử nguyên trạng.

## 5. Workflow / State machine bắt buộc

### 5.1 Đơn bán (Sales Order)

```text
DRAFT --submit(normal)--> CONFIRMED --ship(partial)--> PARTIALLY_SHIPPED --ship(remaining)--> SHIPPED
  |                       ^                  |
  |--submit(over limit)--> PENDING_APPROVAL --approve--> CONFIRMED
  |                                         \--reject--> DRAFT
  \--cancel--> CANCELLED
PENDING_APPROVAL --cancel (authorized)--> CANCELLED
CONFIRMED --cancel (before shipment; authorized)--> CANCELLED
PARTIALLY_SHIPPED --cancel remaining (authorized)--> CLOSED
```

| Transition | Actor | Guard | Side effect |
|---|---|---|---|
| DRAFT→CONFIRMED | Sales owner/Manager | Items tồn tại, giá snapshot, chính sách discount đạt | Chốt totals; version++ |
| DRAFT→PENDING_APPROVAL | Sales owner | Chiết khấu vượt ngưỡng | Tạo request chờ duyệt |
| PENDING_APPROVAL→CONFIRMED | Manager khác creator | Chưa hủy, policy hợp lệ | Lưu approver/timestamp |
| CONFIRMED/PARTIALLY_SHIPPED→PARTIALLY_SHIPPED/SHIPPED | Warehouse | Đúng kho, số lượng giao > 0 và không vượt số còn lại, đủ stock; đúng kho được gán | Tạo shipment và stock movement; giảm stock trong 1 transaction; phần còn lại tiếp tục hiển thị trong hàng chờ |
| DRAFT/CONFIRMED→CANCELLED | Owner/Manager theo policy | Chưa có shipment | Audit + khóa sửa |
| PARTIALLY_SHIPPED→CLOSED | Manager | Có ít nhất một shipment; lý do bắt buộc | Đóng phần còn lại; shipment và invoice lịch sử giữ nguyên; audit |

**Không được** chuyển SHIPPED/CLOSED về DRAFT/CONFIRMED hay sửa hàng/đã giao sau khi xác nhận. Hủy/hoàn hàng sau xuất nằm ngoài v1.1. Chỉ được xuất invoice từ quantities đã shipped và chưa invoiced. Để tránh TOCTOU, guard trạng thái và remaining quantity phải được kiểm tra lại dưới khóa DB khi commit.

### 5.2 Đơn mua (Purchase Order)

```text
DRAFT -> PENDING_APPROVAL -> APPROVED -> PARTIALLY_RECEIVED -> RECEIVED
   \-> CANCELLED       \-> REJECTED -> DRAFT
APPROVED (chưa nhận) -> CANCELLED (Manager; có lý do)
```

Manager tạo PO không được tự duyệt. `APPROVED` có thể nhận nhiều đợt: mỗi goods receipt ghi lượng nhận thực tế, cập nhật stock ledger và lượng còn lại trong một transaction; chỉ chuyển `RECEIVED` khi đủ số lượng đặt. Chỉ được xuất invoice NCC từ lượng đã nhận và chưa được lập invoice.

### 5.3 Kiểm kê/điều chỉnh (Stock Adjustment)

`DRAFT → REQUESTED → APPROVED/REJECTED → APPLIED`.

- Warehouse tạo chênh lệch so với số đếm thực tế, lưu `expected_qty`, `counted_qty`, `delta_qty`.
- Manager khác `requested_by` duyệt; Warehouse khác approver có quyền `erp_stock_adjust_apply` thực thi.
- Trước khi APPLY, hệ thống khóa dòng balance và xác minh số tồn hiện tại. Nếu tồn đã đổi từ lúc kiểm kê, đánh dấu `STALE`/yêu cầu kiểm kê lại, **không áp dụng delta cũ âm thầm**.
- `APPLIED` tạo chính xác một stock movement và thay đổi quantity cùng transaction.

### 5.4 Hóa đơn nội bộ và PDF (P0)

```text
posted shipment / goods receipt
  └─ Accountant: ISSUE INTERNAL INVOICE from un-invoiced delivered/received quantities
         ├─ Snapshot buyer, seller, items, amount, due date
         ├─ ISSUED ─> detail / HTML print / protected PDF
         └─ VOID: only when zero posted payments and no reconciliation;
                  privileged action, reason + audit
```

- Invoice phát hành với trạng thái `ISSUED`, ghi thời điểm và phiên bản template; `invoice_no` và số tiền **bất biến**. Nguồn là từng `SHIPMENT` hoặc `GOODS_RECEIPT`; chỉ lấy số lượng source chưa được invoice. Không chấp nhận POST sửa buyer, item, price, tax hay total từ UI khi issue: snapshot lấy từ source đã posted, chỉ cho phép ngày hạn thanh toán theo policy.
- `erp_invoice_items` lưu snapshot mã hàng, tên, đơn vị, số lượng, đơn giá, exact line subtotal, signed rounding allocation, allocated subtotal, discount, tax và tổng dòng. `erp_invoices` lưu algorithm version, discount/tax basis points cho chứng từ mới, và snapshot tên/địa chỉ/mã số thuế bên bán/mua; sửa master data sau này **không đổi chứng từ lịch sử**. Legacy rates không được suy đoán khi nâng cấp.
- HTML Print và PDF sinh từ **cùng một InvoiceViewModel**, không render lại số liệu bằng catalog hiện thời. Header hiển thị `CHỨNG TỪ NỘI BỘ — KHÔNG PHẢI HÓA ĐƠN ĐIỆN TỬ`; số chứng từ, bên bán/mua, giá trị chính xác, phân bổ làm tròn, subtotal phân bổ, discount, tax, grand total, ngày lập/hạn thanh toán.
- PDF xuất qua **endpoint authenticated** kiểm tra capability + object policy trước mỗi download; tên file whitelist, `Content-Disposition: attachment`; cấm truy cập public PDF trong `uploads`. HTML/PDF template không tải URL/file ngoài không tin cậy. Cần font tiếng Việt và kiểm thử PDF đa trang.
- Chứng từ nhà cung cấp nhập vào ERP chỉ là **chứng từ theo dõi nội bộ**, không giả định thay thế hóa đơn nhà cung cấp hợp pháp.

### 5.5 Payment lifecycle, lịch sử và chứng từ thu/chi (P0)

```text
INVOICE ISSUED
  └─ POST PAYMENT (amount > 0 <= outstanding)
       ├─ POSTED + CASH_MOVEMENT + AUDIT (cùng transaction)
       └─ PAYMENT ERROR:
             POSTED -> VOID_PENDING (Accountant request)
                    -> VOID_APPROVED (Manager approval)
                    -> VOID + REVERSAL CASH_MOVEMENT (Accountant apply)
                    -> POSTED (Manager reject, lưu void_rejection_reason/audit)
```

- Thanh toán theo hướng `IN` với hóa đơn bán, `OUT` với hóa đơn mua; không dùng số âm cho payment. Mỗi payment có `payment_no`, `invoice_id`, `amount_vnd`, `cash_account_id`, `method`, `external_reference` (nếu có), `paid_at` ngày giao dịch, `posted_at` ngày hạch toán, `actor_id`, `status`, `request_key` duy nhất.
- `payment_status` trên giao diện **derived**: `UNPAID` (0), `PARTIAL` (0 < effective_sum < total), `PAID` (effective_sum = total), `OVERDUE` là nhãn bổ sung khi ngày local của `due_at` trước ngày hiện tại và outstanding > 0; không được dùng `OVERDUE` thay thế trạng thái thanh toán. **Effective payment** gồm `POSTED`, `VOID_PENDING`, `VOID_APPROVED`; chỉ `VOID` sau apply + reversal nguyên tử mới hết hiệu lực. Mọi tổng thanh toán/outstanding/report/guard dùng định nghĩa này, không chỉ lọc POSTED.
- Ví dụ invoice 5.000.000đ: 2.000.000 (bank), 1.000.000 (cash), 2.000.000 (bank) ⇒ 3 payment POSTED, outstanding=0, trạng thái `PAID`; từng phiếu thu phản ánh đúng từng payment.
- Không sửa trực tiếp amount/account/method của payment POSTED; **chỉ được void khi giao dịch ghi nhận nhầm chưa được đối soát**, có yêu cầu lý do + người duyệt khác người yêu cầu. Giao dịch thực sự đã chuyển tiền **không được dùng VOID như một cách hoàn tiền**; refund/return thuộc P1.
- Nếu payment đã nằm trong batch reconciliation CLOSED hoặc đã matched: **khóa VOID trong P0**, cần quy trình sửa sai qua chứng từ điều chỉnh riêng (P1) hoặc mở quy trình hủy đối soát có kiểm soát sau thay đổi spec. Không xóa dòng tiền gốc.
- Phiếu thu/chi là view/PDF đọc từ `payment + invoice + cash movement`, không phải bảng “phiếu” editable độc lập. Phiếu chi expense đọc từ `expense + cash movement`; mã chứng từ không trùng. Nếu payment đã bị VOID, in lại phiếu phải thể hiện **ĐÃ HỦY** và dấu thời gian/người phê duyệt.

### 5.6 Cash accounts, cash ledger và manual reconciliation (P0)

- `erp_cash_accounts` gồm tài khoản tiền mặt/quỹ và ngân hàng nội bộ: Accountant tạo `DRAFT`, Manager khác người tạo xác nhận số dư mở kỳ và chuyển `ACTIVE`; `opening_balance_vnd` sau khi ACTIVE **bất biến**. Chỉ tài khoản ACTIVE mới phát sinh giao dịch. Số dư hiện tại tính `opening + SUM(IN) − SUM(OUT)` từ `erp_cash_movements`, không để frontend chỉnh trực tiếp.
- Mọi payment `POSTED`, payment `VOID` (reversal), expense `POSTED` ghi đúng một movement theo `event_key` duy nhất, cùng transaction. `reference` ngoài như mã chuyển khoản **không** coi là idempotency key tuyệt đối.
- Đối với dòng tiền ra, service khóa `cash_account` trước khi xác minh số dư; với quỹ tiền mặt không cho âm. Tài khoản ngân hàng cũng mặc định không âm trong đồ án (không mô phỏng hạn mức thấu chi).
- Đối soát thủ công: Accountant mở batch theo tài khoản/ngày, nhập số dư theo sao kê, chọn cash movements và gắn mã giao dịch ngoài; kiểm tra số tiền/chiều giao dịch; Manager có quyền `erp_reconciliations_close` chỉ chốt batch khi chênh lệch **chính xác bằng 0** và tập giao dịch trong kỳ đã được đối chiếu đầy đủ. Giải trình chênh lệch không cho phép CLOSE. Bản ghi đã match không được match lại ở batch khác.
- Không kết nối internet banking, không tự truy xuất sao kê, không đồng nghĩa với xác thực tiền đã về ngân hàng thật; `paid_at`/reference là thông tin kế toán nhập có trách nhiệm.

## 6. Đặc tả dữ liệu (Data Dictionary cấp triển khai)

### 6.1 Bảng Core WordPress được tái sử dụng

- `{prefix}users`: `ID`, login, email, password hash, user status và các cột Core.
- `{prefix}usermeta`: metadata quyền người dùng; Role/capability do API WordPress quản lý, không tự sửa serialized meta.
- `{prefix}options`: lưu `erp_schema_version`, ngưỡng discount, timezone cấu hình, metadata plugin. Không lưu tồn kho/số tiền giao dịch ở đây.

### 6.2 Bảng custom ERP (26 bảng v1.1)

Quy ước: dùng `$wpdb->prefix . 'erp_'`; `id BIGINT UNSIGNED AUTO_INCREMENT`, `DATETIME(6)` UTC, tiền `BIGINT` số nguyên VND, lượng `DECIMAL(18,3)`, bảng InnoDB + utf8mb4. Danh sách cột là **minimum contract**; lưu `created_at`, `updated_at`/`actor` nơi thích hợp. Các FK nội bộ ERP và CHECK/UNIQUE/index phải được xây bằng migration đã kiểm thử.

| # | Bảng | Cột nghiệp vụ tối thiểu | Ràng buộc/index chính |
|---:|---|---|---|
| 1 | `erp_categories` | `id,name,code,is_active` | `UNIQUE(code)` |
| 2 | `erp_products` | `id,sku,name,category_id,unit,unit_step,sale_price_vnd,is_active` | `UNIQUE(sku)` |
| 3 | `erp_customers` | `id,code,name,tax_id,phone,email,address,is_active` | `UNIQUE(code)` |
| 4 | `erp_suppliers` | `id,code,name,tax_id,phone,email,address,is_active` | `UNIQUE(code)` |
| 5 | `erp_warehouses` | `id,code,name,is_active` | `UNIQUE(code)` |
| 6 | `erp_stock_balances` | `warehouse_id,product_id,quantity,updated_at` | `UNIQUE(warehouse_id,product_id)` |
| 7 | `erp_stock_movements` | `id,warehouse_id,product_id,movement_key,direction,qty,before_qty,after_qty,source_type,source_id,actor_id,occurred_at` | `UNIQUE(movement_key)` |
| 8 | `erp_sales_orders` | `id,order_no,customer_id,warehouse_id,created_by,approved_by,status,discount_bps,subtotal_vnd,discount_vnd,tax_vnd,total_vnd,version,created_at` | `UNIQUE(order_no)` |
| 9 | `erp_sales_order_items` | `id,order_id,product_id,sku_snapshot,name_snapshot,unit_price_vnd,quantity,line_total_vnd` | `INDEX(order_id)` |
| 10 | `erp_purchase_orders` | `id,po_no,supplier_id,warehouse_id,created_by,approved_by,status,total_vnd,version,created_at` | `UNIQUE(po_no)` |
| 11 | `erp_purchase_order_items` | `id,po_id,product_id,unit_cost_vnd,quantity,line_total_vnd` | `INDEX(po_id)` |
| 12 | `erp_goods_receipts` | `id,receipt_no,purchase_order_id,warehouse_id,received_by,received_at,request_key` | `UNIQUE(receipt_no)`, `UNIQUE(request_key)`, `INDEX(purchase_order_id,received_at)` |
| 13 | `erp_goods_receipt_items` | `id,goods_receipt_id,purchase_item_id,product_id,quantity` | `UNIQUE(goods_receipt_id,purchase_item_id)` |
| 14 | `erp_shipments` | `id,shipment_no,sales_order_id,warehouse_id,shipped_by,shipped_at,request_key` | `UNIQUE(shipment_no)`, `UNIQUE(request_key)`, `INDEX(sales_order_id,shipped_at)` |
| 15 | `erp_shipment_items` | `id,shipment_id,sales_item_id,product_id,quantity` | `UNIQUE(shipment_id,sales_item_id)` |
| 16 | `erp_stock_adjustments` | `id,adjustment_no,warehouse_id,status,requested_by,approved_by,applied_by,reason,requested_at,approved_at,applied_at,apply_request_key` | `UNIQUE(adjustment_no)`, `UNIQUE(apply_request_key)` |
| 17 | `erp_stock_adjustment_items` | `id,adjustment_id,product_id,expected_qty,counted_qty,delta_qty` | `UNIQUE(adjustment_id,product_id)` |
| 18 | `erp_invoices` | `id,invoice_no,source_type,source_id,direction,party_id,seller_name,seller_address,seller_tax_id,buyer_name,buyer_address,buyer_tax_id,subtotal_exact_vnd,rounding_adjustment_vnd,subtotal_vnd,discount_vnd,tax_vnd,total_vnd,pricing_algorithm_version,discount_bps,tax_bps,status,issued_at,due_at,issued_by,void_reason,voided_by,voided_at,template_version` | `source_type` is `SHIPMENT` or `GOODS_RECEIPT`; rates are nullable only for legacy snapshots; `UNIQUE(invoice_no)`, `UNIQUE(source_type,source_id)`, `INDEX(status,due_at)` |
| 19 | `erp_payments` | `id,payment_no,invoice_id,direction,amount_vnd,cash_account_id,method,external_reference,paid_at,posted_at,status,created_by,void_reason,void_requested_by,void_approved_by,void_approved_at,void_rejected_by,void_rejected_at,void_rejection_reason,void_applied_by,request_key` | `UNIQUE(payment_no)`, `UNIQUE(request_key)`, `INDEX(invoice_id,status)` |
| 20 | `erp_expenses` | `id,expense_no,category,description,amount_vnd,occurred_at,recorded_by,cash_account_id,status,request_key` | `UNIQUE(expense_no)`, `UNIQUE(request_key)` |
| 21 | `erp_audit_logs` | `id,event_id,request_id,actor_user_id,action,entity_type,entity_id,result,before_json,after_json,ip_hash,created_at` | `UNIQUE(event_id)` |
| **22** | **`erp_invoice_items`** | `id,invoice_id,source_item_id,sku_snapshot,name_snapshot,unit_snapshot,quantity,unit_price_vnd,line_subtotal_exact_vnd,rounding_allocation_vnd,line_subtotal_vnd,discount_vnd,tax_vnd,line_total_vnd` | `UNIQUE(invoice_id,source_item_id)` |
| **23** | **`erp_cash_accounts`** | `id,account_code,name,type,currency,opening_balance_vnd,status,created_by,activated_by,activated_at,created_at` | `UNIQUE(account_code)`, currency=`VND`, status DRAFT/ACTIVE/INACTIVE |
| **24** | **`erp_cash_movements`** | `id,cash_account_id,event_key,source_type,source_id,direction,amount_vnd,occurred_at,actor_id,external_reference,created_at` | `UNIQUE(event_key)`, `INDEX(cash_account_id,occurred_at)` |
| **25** | **`erp_reconciliations`** | `id,rec_no,cash_account_id,period_from,period_to,statement_opening_vnd,statement_closing_vnd,status,created_by,closed_by,closed_at,note` | `UNIQUE(rec_no)`, account/period index, no overlapping CLOSED periods |
| **26** | **`erp_reconciliation_items`** | `id,reconciliation_id,cash_movement_id,external_reference,external_amount_vnd,matched_by,matched_at` | `UNIQUE(cash_movement_id)`, `UNIQUE(reconciliation_id,external_reference)` when ref present |

**Yêu cầu giao dịch/ràng buộc bắt buộc:**

- V1.1 **không** thêm bảng refund; tính năng refund/credit note sẽ bổ sung migration P1 sau khi có thiết kế return inventory và invoice adjustment.
- `invoice_items` là immutable snapshot; invoice chỉ `ISSUED` hoặc `VOID`; `due_at` là mốc 23:59:59.999999 Asia/Ho_Chi_Minh của ngày hạn, chuyển UTC để lưu; phải không sớm hơn thời điểm phát hành và `total_vnd > 0` theo policy P0. Với `void` chỉ cho khi chưa có payment lịch sử posted/matched, phải lưu lý do và audit; không hard delete.
- `cash_movements` là append-only, dòng reversal được tạo event key mới như `PAYMENT_VOID:{payment_id}`; không sửa/xóa dòng original. Một payment POSTED → một event `PAYMENT_POST:{id}`; expense → `EXPENSE_POST:{id}`.
- `reconciliation_items` không sửa sau khi batch CLOSED, một movement chỉ thuộc một reconciliation; kiểm tra số dư theo kỳ và khoản match đúng chiều/số tiền.
- `cash_accounts.opening_balance_vnd` bất biến sau khi ACTIVE; mọi số dư báo cáo là tổng mở kỳ + ledger movements. Không viết UI sửa `cash_accounts.balance` vì **không có cột balance mutable**.
- Ngoài index nêu trong bảng, có index `erp_payments(invoice_id, status, posted_at)`, `erp_invoices(direction,status,due_at)`, `erp_cash_movements(source_type,source_id)`; lượng dữ liệu lớn thì benchmark EXPLAIN và thêm composite index theo query.
- Trong WordPress, vai trò và user giữ ở `users/usermeta` core; plugin không thay serialized role meta trực tiếp. Mọi bảng custom dùng prefix thực tế từ `$wpdb->prefix`.

### 6.3 Quan hệ dữ liệu (logic)

```text
Customers 1--N SalesOrders 1--N SalesOrderItems N--1 Products
Suppliers 1--N PurchaseOrders 1--N PurchaseOrderItems N--1 Products
Warehouses 1--N StockBalances N--1 Products
Warehouses 1--N StockMovements N--1 Products
PurchaseOrders 1--N GoodsReceipts 1--N GoodsReceiptItems
SalesOrders 1--N Shipments 1--N ShipmentItems
GoodsReceipts 1--0..1 Invoices 1--N InvoiceItems
Shipments 1--0..1 Invoices 1--N InvoiceItems
Invoices 1--N Payments N--1 CashAccounts
CashAccounts 1--N CashMovements 0..1--1 ReconciliationItems
Reconciliations 1--N ReconciliationItems
Expenses 1--1 CashMovements (when POSTED)
StockAdjustments 1--N StockAdjustmentItems
WP Users 1--N (orders / actor fields / audit logs)
```

### 6.4 Index và tính toàn vẹn

- Tất cả truy vấn danh sách 100% có phân trang server-side (`LIMIT`/`OFFSET` tối đa 100 rows/request) và filter có index hợp lý.
- Các `order_no`, `po_no`, `invoice_no` phải là mã duy nhất; có thể dùng `PREFIX-YYYY-ID` sinh từ ID sau khi insert, bên trong transaction. Tránh `SELECT MAX(number) + 1` do race condition.
- Những field chỉ client có thể truyền vào đơn nháp: `customer_id, warehouse_id, items[{product_id,qty}], requested_discount_bps, note`. Không nhận `status`, `created_by`, `approved_by`, `total_vnd` theo POST.
- Trường `version` trên order hỗ trợ optimistic conflict detection ở chỉnh sửa draft; khi mutate quan trọng dùng cả row lock và state guards.
- Không làm báo cáo doanh thu bằng số tiền phiếu thanh toán nếu đang cần chỉ tiêu bán hàng; phân biệt doanh thu lập hóa đơn và dòng tiền thực thu.
- `invoice_no`, payment_no, external_reference không được người dùng lợi dụng sửa lịch sử đã posted; `request_key` được phát từ server/form, scope theo loại action và actor; unique trong database.
- Khi in PDF/CSV không sử dụng dữ liệu catalog hiện tại cho chứng từ đã phát hành; giới hạn truy vấn/export theo role, tránh CSV formula injection.

## 7. Các nghiệp vụ transaction phải có

### 7.1 Xuất hàng nguyên tử

**Precondition:** order `CONFIRMED` hoặc `PARTIALLY_SHIPPED`, đúng kho, có lượng còn lại và đủ stock cho số lượng đang giao, user `erp_stock_ship` thuộc kho.

```text
BEGIN;
  SELECT sales_order WHERE id = ? FOR UPDATE;
  validate status in (CONFIRMED, PARTIALLY_SHIPPED), remaining quantity and warehouse assignment;
  INSERT shipment(order_id, request_key UNIQUE, warehouse_id, shipped_by);
  foreach requested shipment line in ORDER BY product_id ASC:
      validate 0 < ship_qty <= ordered_qty - sum(previous_shipments);
      ensure balance row exists;
      UPDATE balances
         SET quantity = quantity - :qty
       WHERE warehouse_id=:wh AND product_id=:product AND quantity >= :qty;
      assert affected_rows == 1;
      INSERT shipment_item;
      INSERT stock_movement (unique movement_key, before/after quantities);
  compute status from remaining quantities (PARTIALLY_SHIPPED or SHIPPED);
  UPDATE sales_order SET status=:status,version=version+1
      WHERE id=? AND status IN ('CONFIRMED','PARTIALLY_SHIPPED');
  INSERT audit_log(success);
COMMIT;
On any failed SQL, insufficient stock, duplicate key, or invalid state: ROLLBACK.
```
```

**Implementation notes:** số dư trước/sau lấy ở row đã lock hoặc qua lock tương thích; thao tác đủ kiểm tra atomic, không dựa trên truy vấn `SELECT quantity` bên ngoài transaction. Để xác định before/after chính xác, lock từng balance row theo thứ tự product_id, rồi cập nhật có điều kiện và viết ledger. Một dòng balance duy nhất cho `(warehouse_id, product_id)`; không dùng `LOCK TABLES`. Bắt deadlock/lock timeout và retry an toàn với `request_key`/unique constraints, giới hạn số lần retry [S9–S10].

### 7.2 Nhập hàng nguyên tử

`BEGIN → lock PO APPROVED/PARTIALLY_RECEIVED → compare each receipt quantity with ordered minus prior received → insert receipt + item lines → increase balances + stock movements → PO=PARTIALLY_RECEIVED/RECEIVED → audit → COMMIT`. Duplicate request key is rejected idempotently; one PO can have many distinct receipts, but one sales/purchase line can only be received up to its remaining quantity.

### 7.3 Ghi payment + cash movement nguyên tử (P0)

```text
BEGIN;
 SELECT invoice WHERE id=? FOR UPDATE;             # lock đầu tiên
 validate invoice=ISSUED, direction, paid_at, account ACTIVE;
 verify amount>0 and amount<=outstanding;            # query effective payments dưới invoice lock
 SELECT cash_account WHERE id=? FOR UPDATE;         # lock sau invoice
 if direction=OUT: assert computed cash_balance>=amount;
 INSERT payment(status=POSTED, request_key UNIQUE);
 INSERT cash_movement(event_key='PAYMENT_POST:{payment_id}',
     source_type='PAYMENT',source_id=payment_id,direction,amount);
 INSERT audit_log;
COMMIT;
On any failure: ROLLBACK;
```

Mọi luồng liên quan payment phải lock invoice trước, rồi lock cash account theo thứ tự đồng nhất (id tăng dần nếu nhiều account). Tất cả dòng cash movement do payment/expense sinh trong transaction; tuyệt đối không ghi payment trước rồi dùng WP-Cron để cập nhật sổ quỹ.

### 7.3.1 VOID payment sai sót + reversal (P0)

`Accountant REQUEST (reason) → Manager APPROVE (not same requester) → Accountant APPLY`; khi APPLY `BEGIN → lock invoice → lock payment → lock cash_account → verify approved, not reconciled, amount/status → INSERT cash_movement(reverse direction, event_key PAYMENT_VOID:{payment_id}) → mark payment VOID → audit → COMMIT`. Đảo bút toán phản ánh **sửa dữ liệu sổ quỹ nhập nhầm**, không sử dụng để giả hoàn tiền thật. Nếu cần đảo OUT→IN hay IN→OUT phải xác nhận dòng tiền reversal không làm số dư tiền mặt âm; nếu không thỏa, dừng và xử lý ngoài P0 theo chính sách kế toán.

### 7.3.2 Expense + cash movement (P0)

`BEGIN → lock cash_account → check enough funds (OUT) → insert expense(POSTED) → insert cash movement(OUT, EXPENSE_POST:id UNIQUE) → audit → COMMIT`. Không cho expense POSTED đứng riêng không có cash movement.

### 7.3.3 Manual reconciliation và close (P0)

`Accountant create DRAFT batch → match từng cash movement chưa reconciled với dòng sao kê nhập tay → kiểm tra chiều/amount/reference → tính closing từ opening + actual flow + unmatched differences → Manager close if exact match (0 discrepancy) → freeze items/batch`. Unique `cash_movement_id` bảo vệ double match. Thêm policy không cho batch CLOSED trùng kỳ/tài khoản; locking account và batch khi close; không khóa tài khoản tiền toàn kỳ một cách tùy tiện. Trường hợp sao kê có giao dịch không có trong ERP phải tạo chứng từ hợp lệ trước khi match, không tạo silent money row.

### 7.4 Điều chỉnh tồn kho

Điều chỉnh kho phải tách request/approval/apply. Tại APPLY, lock adjustment và balance, kiểm tra `quantity == expected_qty` trước khi tính delta mới. Thêm stock movement, đánh dấu APPLIED trong 1 transaction. Nếu số dư đã đổi, từ chối và yêu cầu kiểm kê lại.

### 7.5 Các bất biến (invariants) không được phá vỡ

| INV-ID | Bất biến |
|---|---|
| INV-01 | Không `erp_stock_balances.quantity < 0` trong giao dịch hợp lệ |
| INV-02 | Tổng shipped quantity của mỗi Sales Order line không vượt ordered quantity; mọi shipment có unique request key và đủ ledger |
| INV-03 | Tổng received quantity của mỗi Purchase Order line không vượt ordered quantity; mọi goods receipt có unique request key và đủ ledger |
| INV-04 | Mỗi shipment/receipt source có tối đa một invoice; invoice chỉ chốt lượng đã giao/nhận và totals/item snapshots sau `ISSUED` là bất biến |
| INV-05 | `SUM(effective payments: POSTED/VOID_PENDING/VOID_APPROVED) <= invoice.total_vnd`; không payment âm; request/approve void không giải phóng dư nợ |
| INV-06 | `PARTIALLY_SHIPPED`/`SHIPPED` ⇒ tổng lượng shipment khớp stock ledger; `SHIPPED` chỉ khi remaining quantity bằng 0 |
| INV-07 | `PARTIALLY_RECEIVED`/`RECEIVED` ⇒ tổng lượng receipt khớp stock ledger; `RECEIVED` chỉ khi remaining quantity bằng 0 |
| INV-08 | P0 bắt đầu tồn bằng 0; Stock balance = SUM(movements). Tồn ban đầu MVP/demo chỉ từ PO được duyệt và goods receipt; không có nhập tồn mở kỳ hay sửa balance trực tiếp |
| INV-09 | Stock adjustment maker khác approver; apply không stale |
| INV-10 | Chỉ domain service được đổi workflow status/stock/payment |
| **INV-11** | Mỗi payment POSTED có chính xác một `PAYMENT_POST` cash movement; mỗi payment VOID có thêm đúng một reversal |
| **INV-12** | Mỗi expense POSTED có đúng một `EXPENSE_POST` cash movement |
| **INV-13** | Cash account balance = opening + SUM(IN) − SUM(OUT) trong cash ledger |
| **INV-14** | Không VOID posted payment đã matched/reconciled; reversal chỉ sau approved maker-checker |
| **INV-15** | Một `cash_movement` chỉ matched tối đa một lần; CLOSED reconciliation không sửa items |
| **INV-16** | PDF/print chỉ đọc invoice snapshot, và luôn chạy object-level authorization |
| **INV-17** | Invoice outstanding >= 0; nếu >0 và due date qua hạn thì hiển thị overdue |
| **INV-18** | Batch reconciliation chỉ CLOSED nếu statement closing = ERP closing theo tập giao dịch/kỳ đã đối chiếu; không có unmatched quan trọng |

## 8. Hợp đồng giao diện và actions

### 8.1 Màn hình ERP app

| Route `/?erp_page=...` | Đối tượng | UI và dữ liệu chính |
|---|---|---|
| `mini-erp` | Theo capability | Dashboard và các phân hệ được cấp |
| `mini-erp-products` | Manager và view roles | Product, category, SKU, price |
| `mini-erp-customers` / `mini-erp-suppliers` | Sales/Manager và role scoped | Khách hàng, nhà cung cấp, liên hệ và dữ liệu scoped |
| `mini-erp-sales` / `mini-erp-purchases` | Sales/Manager/Warehouse/Accountant scoped | Đơn bán/mua, timeline, fulfillment, link invoice |
| `mini-erp-inventory` / `mini-erp-warehouses` | Manager/Warehouse scoped | Stock, movements, adjustment và danh sách kho |
| `mini-erp-invoices` | Accountant/Manager/Sales scoped | Hóa đơn, trạng thái, due date, tìm/lọc, chi tiết, HTML Print/PDF theo capability |
| `mini-erp-payments` / `mini-erp-expenses` | Theo capability và cash-account scope | Lịch sử payment, phiếu thu/chi, request/approve void |
| `mini-erp-cash-accounts` | Accountant/Manager read scoped | Danh sách quỹ/ngân hàng nội bộ và số dư được phân quyền |
| `mini-erp-reconciliation` | Accountant/Manager scoped | Batch DRAFT/CLOSED, đối soát thủ công, chênh lệch |
| `mini-erp-reports` | Theo capability | Doanh thu, thực thu/chi, công nợ, aging, cash, stock |
| `mini-erp-users` / `mini-erp-audit` | ERP System Admin | User, capabilities, log |

**Invoice Detail UI tối thiểu:** header #invoice/date/due/customer/seller/status; item grid qty/unit/price/discount/tax/total; payment history table (time/method/amount/status/ref/actor); amounts invoice/paid/outstanding; nút Print/PDF có capability; audit timeline (chỉ role đủ quyền). **Payment History UI:** lọc ngày, invoice_no, customer, method, account, actor, status, direction; phân trang server-side và field masking.

### 8.2 Form actions chính (admin_post_...)

| Action | POST fields allowlisted | Permission/policy / lưu ý |
|---|---|---|
| `erp_sales_save_draft`, `erp_sales_submit`, `erp_sales_approve`, `erp_sales_ship` | order_id, customer, warehouse, items, discount/version; order_id/nonce/request_key khi submit/ship | Sales/Manager/Warehouse theo scope, state guard |
| `erp_purchase_save`, `erp_purchase_approve`, `erp_purchase_receive` | supplier/warehouse/items; po_id/nonce/request_key khi approve/receive | Manager/Warehouse theo scope/state |
| `erp_stock_adjust_request`, `erp_stock_adjust_approve`, `erp_stock_adjust_apply` | warehouse, counted quantities, reason; adjustment_id/decision/nonce/request_key khi approve/apply | Warehouse/Manager maker-checker |
| **`erp_invoice_issue`** | `source_type,source_id,due_date_local,nonce,request_key` | Accountant, source fulfilled, snapshot all fields server-side |
| **`erp_invoice_print` / `erp_invoice_pdf`** | `invoice_id,nonce` (GET endpoint authenticated, action-specific nonce) | Read/print + object policy; safe output, no public path |
| **`erp_invoice_void`** | `invoice_id,reason,nonce` | Accountant; ISSUED, zero posted payments, audit, không cho sửa snapshot |
| **`erp_cash_account_request`** | `account_code,name,type,opening_balance_vnd,nonce` | Accountant tạo DRAFT, creator từ server, không sửa sau kích hoạt |
| **`erp_cash_account_activate`** | `cash_account_id,nonce` | Manager khác creator, kiểm tra opening và không có ledger |
| **`erp_payment_record`** | `invoice_id,amount_vnd,cash_account_id,method,paid_at,external_reference,nonce,request_key` | Accountant; eligible invoice, amount bound, double lock, unique event |
| **`erp_payment_void_request`** | `payment_id,reason,nonce` | Accountant; POSTED, not reconciled |
| **`erp_payment_void_approve`** | `payment_id,decision,reason,nonce` | Manager khác requester; trạng thái VOID_PENDING |
| **`erp_payment_void_apply`** | `payment_id,nonce,request_key` | Accountant; VOID_APPROVED, not reconciled; reverse cash |
| **`erp_expense_post`** | `category,description,amount_vnd,cash_account_id,occurred_at,nonce,request_key` | Accountant; cash-out ledger atomic |
| **`erp_reconciliation_create`** | `cash_account_id,period_from,period_to,statement_opening_vnd,statement_closing_vnd,nonce` | Accountant; no overlapping closed period |
| **`erp_reconciliation_match`** | `reconciliation_id,cash_movement_id,external_reference,external_amount_vnd,nonce` | Accountant; DRAFT, unique match and direction |
| **`erp_reconciliation_close`** | `reconciliation_id,nonce` | Manager; delta 0; freeze batch |
| `erp_user_update_role` | `user_id,role_slug,nonce` | SysAdmin ERP, allowlist, audit |

**HTTP:** POST form success dùng 303 redirect; thiếu capability của action trả 403 trước lookup; đối tượng không tồn tại **hoặc ngoài phạm vi actor** trả cùng thông báo 404 để không tiết lộ sự tồn tại (theo T-S03/T-S13). Validation lỗi 422, xung đột version/duplicate 409 chỉ sau khi authorize đối tượng. Download tài liệu nhạy cảm qua action được bảo vệ, phải gửi `Cache-Control: private, no-store` và `X-Content-Type-Options: nosniff`. URL GET download dùng nonce hành động riêng **nhưng nonce không thay thế quyền**. Tuyệt đối không gửi `status`, `total_vnd`, `posted_at` tùy ý cho phép ghi vào DB.

### 8.3 AJAX endpoints (read-only v1.1)

- `wp_ajax_erp_search_products`: query tối đa 100 ký tự, phân trang, cap `erp_products_view`, trả id/sku/name/price/available_qty theo scope.
- `wp_ajax_erp_search_customers`: cap Sales/Manager, trả dữ liệu tối thiểu theo mục đích.
- `wp_ajax_erp_stock_preview`: cap Stock/Sales view, trả số tồn hiện tại không coi là cam kết khi xuất.
- Các actions AJAX dù chỉ đọc cũng phải kiểm tra capability và phạm vi dữ liệu, hạn chế rate, không đăng ký `wp_ajax_nopriv_*`.
- `wp_ajax_erp_invoice_lookup`, `wp_ajax_erp_payment_history`, `wp_ajax_erp_cash_balance_preview` chỉ trả field được authorize; số dư preview không coi là cam kết sau khi post.

## 9. Security requirements và threat model

### 9.1 Trust boundaries

`Browser (không tin) → WordPress request/nonce → Controller (kiểm tra danh tính) → Policy (quyền) → Service (invariant) → DB (commit)`.

Nguồn không tin: `$_GET`, `$_POST`, `$_FILES`, headers, AJAX params, user metadata có thể bị sửa bởi plugin khác, giá client gửi, bản ghi ID, cả dữ liệu đọc từ DB khi render. Ngoại lệ: server-side verified identity (`get_current_user_id()`), capability resolution qua WordPress, request-scoped policy checks.

### 9.2 Threat register Critical/High

| ID | Mối đe dọa | Ưu tiên tham chiếu | Biện pháp và bằng chứng |
|---|---|---|---|
| SEC-01 | Privilege escalation / role tampering | Critical nếu khai thác cho toàn quyền | SysAdmin-only controller, allowlist, audit, test Sales self-elevation 403 |
| SEC-02 | IDOR/BOLA đơn hàng/hóa đơn | Critical nếu lộ/sửa nhiều dữ liệu | Cap + object ownership + warehouse scope trên mọi thao tác; thiếu cap 403, khác owner cùng generic 404 như object không tồn tại |
| SEC-03 | Race xuất kho / duplicate shipment | Critical với tính toàn vẹn kho | InnoDB transaction + row lock + unique shipment + request_key; load concurrency test |
| SEC-04 | SQL Injection | Critical nếu khai thác dữ liệu | `$wpdb->prepare()`, allowlist identifiers/sort, code review/security test |
| SEC-05 | CSRF khi thay đổi trạng thái | High | POST + nonce + current_user_can + object policy; missing nonce 403 |
| SEC-06 | Stored/reflected XSS | High | Contextual output escaping, validate/sanitize fields, test payload không thực thi |
| SEC-07 | Tamper giá/amount/status | High/Critical tùy tác động | Tính lại server, allowlist DTO, transaction, test sửa total client |
| SEC-08 | Replay payment/ledger | High | request_key/event_key unique + invoice/account lock + capped amount |
| SEC-09 | PDF lộ hóa đơn, path traversal/SSRF khi render | High | Download authz/object policy, PDF từ snapshot, disable remote resources, không tạo file public |
| SEC-10 | Plugin supply chain / WP compromise | High/Critical tùy plugin | Ít plugin, cập nhật thường xuyên, kiểm tra provenance, staging, least privilege |
| SEC-11 | Log tampering và PII leakage | High | Cap audit, mask PII, offsite log backup, log truy cập logs |
| SEC-12 | Backup mất/không phục hồi | High | Automated backup + monthly restore drill + có chỉ số RPO/RTO
| SEC-13 | Sửa payment POSTED / che giấu tiền | Critical nếu ảnh hưởng tài chính | Append-only cash movement, reversal có maker-checker, test invariant 11–15 |
| SEC-14 | Đối soát giả/match trùng | High | Unique movement match, batch close delta zero, scoped policy, audit | |

Mức Critical/High là phân loại **ưu tiên thiết kế của dự án**, không phải CVSS chấm trên sản phẩm đã kiểm thử. Tham chiếu OWASP API1/API3 và OWASP Authorization [S11–S12].

### 9.3 Security controls có thể nghiệm thu

1. **Authentication:** WordPress cookie/session trên HTTPS; khóa/vô hiệu user; MFA cho WP Administrator và tài khoản role nhạy cảm nếu plugin MFA đủ tin cậy; giới hạn login attempts ở web server/security layer.
2. **Authorization:** server-side `current_user_can('erp_*')` + `Policy::can($user, $action, $object)`; kiểm tra lại state tại commit; default deny; không dùng `role == 'manager'` hardcode trong service [S1, S12].
3. **CSRF:** Mọi POST thay đổi dữ liệu có `wp_nonce_field()` + `check_admin_referer()`; AJAX có `check_ajax_referer()`. **Nonce không phải authentication/authorization và không chống replay** [S5].
4. **SQLi:** `$wpdb->prepare()` cho biến SQL và whitelist cho ORDER BY/column; không nối trực tiếp user input vào query [S6].
5. **Validation:** định nghĩa schema whitelist, không `$_POST` trực tiếp vào insert; ID `absint`, enum strict, max length, quantity dương/step hợp lệ, VND integer bounds, HTML content được sanitize [S7].
6. **XSS:** `esc_html`/`esc_attr`/`esc_url` đúng ngữ cảnh ở thời điểm render. Không chèn chuỗi unsanitized vào inline JS [S8].
7. **Sensitive data:** hạn chế field PII theo role; không tạo link export/public upload ai cũng truy cập; không lưu secrets trong audit [S13].
8. **Session/admin:** disable file editor, mật khẩu mạnh, 2FA, ít WordPress Administrator, cấm chia sẻ tài khoản, timeout/reauth cho tác vụ nhạy cảm nếu cần.
9. **Audit:** ghi create/approve/ship/receive/payment/role change/failed authz attempts; log nghiệp vụ thành công nằm cùng transaction; log thất bại ghi riêng sau rollback và best-effort không làm lộ PII [S13].
10. **Dependency:** WordPress, PHP, DB, plugin/theme bản được hỗ trợ; vá lỗi ở staging, kiểm thử trước production; không dùng các plugin không rõ nguồn.
11. **Database access:** user DB tối thiểu cần thiết cho WordPress và migration; backup/DB admin credentials tách khỏi web app khi có thể; tường lửa không expose MySQL public.
12. **CSP/Cookies:** dựa vào WordPress cookie flags / HTTPS; thêm các security headers phù hợp, kiểm thử tương thích WP-admin trước khi khóa CSP; không đưa CSP thiếu kiểm chứng vào môi trường thật.

### 9.4 Mã PHP tham chiếu (pattern, không phải toàn bộ implementation)

```php
add_action('admin_post_erp_sales_ship', function () {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        wp_die('Method not allowed', '', ['response' => 405]);
    }
    if (!current_user_can('erp_stock_ship')) {
        wp_die('Forbidden', '', ['response' => 403]);
    }
    check_admin_referer('erp_sales_ship');
    $order_id = absint($_POST['order_id'] ?? 0);
    $request_key = sanitize_text_field(wp_unslash($_POST['request_key'] ?? ''));
    if (!$order_id || !preg_match('/^[a-zA-Z0-9-]{16,96}$/', $request_key)) {
        wp_die('Invalid request', '', ['response' => 422]);
    }
    // StockService::ship($order_id, get_current_user_id(), $request_key)
    // tự lock order, check warehouse scope, trạng thái, tồn và commit.
    // Catch DomainException: show safe message, never leak SQL internals.
    wp_safe_redirect(home_url('/?erp_page=mini-erp-sales'), 303);
    exit;
});
```

Controller mẫu **chưa thể dùng vận hành ngay** nếu chưa triển khai StockService, error handling và idempotency. Phải test riêng luồng success/forbidden/insufficient stock/duplicate/DB rollback trước go-live.

## 10. Audit, báo cáo đối soát và bảo vệ dữ liệu

### 10.1 Audit schema logic

- `event_id` và `request_id` giúp nhóm các sự kiện cùng giao dịch. `action` dạng `order.ship`, `purchase.receive`, `payment.post`, `role.change`, `authz.denied`.
- `before_json`, `after_json` chỉ chứa trường nghiệp vụ được allowlist: status, qty, amount, role slug; không ghi token/nonce/password, địa chỉ/điện thoại đầy đủ trừ khi có lý do vận hành.
- Không cho role nghiệp vụ chỉnh sửa/xóa audit log qua UI/API. DB Administrator/hạ tầng vẫn có thể can thiệp, vì vậy cần backup bất biến/offsite hoặc xuất log phục vụ phát hiện sửa trái phép khi sản xuất thực tế.
- Retention audit/PII cấu hình theo chính sách và nghĩa vụ pháp lý; MVP dùng dữ liệu demo và giữ log trong vòng đời đồ án. Xử lý yêu cầu truy cập/xóa dữ liệu cá nhân cần cơ chế tách riêng, không phá lịch sử chứng từ pháp lý [S13–S14].

### 10.2 Báo cáo v1.1 và nguồn dữ liệu chuẩn

| Báo cáo | Nguồn dữ liệu chính | Cách tính/đối soát |
|---|---|---|
| Doanh thu hóa đơn bán | `erp_invoices` SALES/ISSUED | SUM(total_vnd) theo issued_at, **không phải cash** |
| Tổng thanh toán nhận/trả | `erp_payments` effective (POSTED/VOID_PENDING/VOID_APPROVED) theo direction | Xem lịch sử theo invoice/party/method/account/date |
| Dòng tiền thực thu/chi | `erp_cash_movements` | SUM(IN) – SUM(OUT), đã bao gồm reversals/expenses |
| Chi phí nội bộ | `erp_expenses` POSTED | Phân loại chi phí; đối chiếu event `EXPENSE_POST` |
| Công nợ hiện tại | `erp_invoices` − SUM(effective payments) | Theo party, invoice, due date, outstanding >= 0 |
| Tuổi nợ (aging) | invoice outstanding và due_at | Chưa đến hạn, 1–30, 31–60, 61–90, >90 ngày; theo ngày hệ thống Asia/HCM |
| Nhật ký payment | `erp_payments`, `erp_cash_accounts`, users | Lọc, timeline, void history, account masked |
| Sổ quỹ tiền mặt/ngân hàng nội bộ | `erp_cash_accounts` + `erp_cash_movements` | Opening + IN − OUT, không làm tròn float |
| Chênh lệch đối soát | `erp_reconciliations` và matches | Statement closing – ERP calculated closing, chỉ CLOSE khi 0 |
| Tồn kho | `erp_stock_balances`/`erp_stock_movements` | Nhất quán lượng hiện tại và lịch sử |

**Lưu ý cách hiểu:** Trạng thái invoice `VOID` chỉ xuất hiện khi chưa có posted payment; doanh thu có thể được lọc trừ VOID. Khoản ghi sai bị VOID thì payment chuyển trạng thái VOID, và cash movement reversal giữ lại trong sổ quỹ. Báo cáo tài chính cơ bản **không** được gọi là báo cáo kế toán tài chính theo chuẩn mực khi chưa có sổ cái/định khoản kép/giá vốn.

### 10.3 Đặc tả xuất PDF và CSV

- In hóa đơn nội bộ và phiếu thu/chi dùng một layout dữ liệu giữa HTML/PDF, tiếng Việt, số tiền `VND` phân tách hàng nghìn, làm tròn theo quy tắc mục 4.1.
- PDF có dấu hiệu **CHỨNG TỪ NỘI BỘ** và số chứng từ; nguồn dữ liệu `invoice_items` snapshot hoặc payment/expense+movement; timestamp render không thay đổi ngày phát hành.
- Có thể chọn thư viện PHP PDF open-source (Dompdf hoặc mPDF), khóa phiên bản phụ thuộc đã kiểm thử, đặt security option chặn tải tài nguyên không tin cậy, giới hạn kích thước và sanitize HTML; cần kiểm thử font Unicode trước release. Đây là đề xuất thư viện, chưa khẳng định các thư viện đã được cài đặt.
- CSV export cần phân quyền giống màn hình, bao gồm BOM nếu phục vụ Excel tiếng Việt; escaped field chứa dấu `=`, `+`, `-`, `@` hoặc ký tự control để phòng spreadsheet formula injection. Thiết lập giới hạn exports và ghi audit các lần xuất dữ liệu nhạy cảm.

## 11. Phi chức năng (NFR) và target nghiệm thu

| ID | Chỉ tiêu mục tiêu | Điều kiện đo |
|---|---|---|
| NFR-01 | Giao diện danh sách p95 < 2 giây | Staging, 10.000 sản phẩm, 50.000 đơn, 15 user concurrent, query có index |
| NFR-02 | Transaction đơn bán/xuất kho p95 < 3 giây | 15 concurrent users, không tính thời gian mạng bất thường |
| NFR-03 | Không âm tồn hoặc xuất trùng trong 100 vòng test đồng thời | Hai hoặc nhiều request xuất cùng item, đối chiếu balances/ledger |
| NFR-04 | Bản ghi nhạy cảm không truy cập trái phép | 100% test RBAC/IDOR bắt buộc pass |
| NFR-05 | 100% nghiệp vụ thay đổi trạng thái chính có audit | Log có actor/action/entity/time/result |
| NFR-06 | RPO mục tiêu <= 24 giờ | Database backup tối thiểu hàng ngày, backup offsite |
| NFR-07 | RTO mục tiêu <= 4 giờ | Restore drill trên staging thành công |
| NFR-08 | Chứng từ có định danh duy nhất | Không trùng shipment/receipt/invoice/payment/reconciliation |
| NFR-09 | Unicode tiếng Việt, xuất CSV UTF-8 có BOM tùy Excel | Test trên môi trường người dùng |
| NFR-10 | Trình duyệt Chrome/Edge/Firefox phiên bản được hỗ trợ | Desktop 1366px trở lên; responsive cơ bản |
| **NFR-11** | PDF in được, Unicode tiếng Việt đầy đủ | 100 invoice (1–30 dòng) xuất PDF không lỗi font/cắt trang |
| **NFR-12** | Cash/payments/reconciliation luôn khớp ledger | 100% invariants INV-11..INV-18 sau test concurrent/rollback |

Các số liệu NFR là **mục tiêu đề xuất** cần benchmark và tinh chỉnh, không phải kết quả đo sẵn có hoặc cam kết SLA cho doanh nghiệp thực.

## 12. Môi trường vận hành và triển khai

### 12.1 Baseline tối thiểu kiến nghị

**Yêu cầu local v1.1:** dự án phải khởi chạy được bằng Docker Compose cho phát triển, kiểm thử và demo. Compose/build/startup scripts, cấu hình lưu lại và dữ liệu local nằm trong thư mục dự án; tài liệu phải có start/stop/access/health/test có thể lặp lại. Chỉ bind dịch vụ ra loopback, dùng dữ liệu giả, không dùng DB dự án khác. Đây không phải ủy quyền triển khai production hay external hosting. Việc core WordPress chạy được không chứng minh nghiệp vụ ERP đã hoàn tất.

- **PHP 8.3+**, **MySQL 8.0+** (ưu tiên MySQL 8.4 LTS) **hoặc MariaDB 10.11+**, **HTTPS**. Đây phù hợp baseline WordPress khuyến nghị tại thời điểm khảo sát; phải kiểm thử compat với bản WordPress đang triển khai [S15].
- Web server Nginx hoặc Apache; PHP-FPM; database **không exposed ra Internet**; máy chủ Linux với bảo trì/cập nhật bảo mật.
- Staging tách production: bản WordPress/plugin/DB tương ứng; không copy dữ liệu khách thật sang staging nếu chưa masking.
- MVP triển khai một server là đủ cho đồ án; khi dùng thật phải đánh giá dung lượng, giám sát, backup, UPS/HA theo rủi ro.
- Với hosting shared không có quyền cron, tránh đặt bất kỳ tác vụ ảnh hưởng tồn kho/thanh toán vào WP-Cron. WP-Cron phụ thuộc lượt truy cập và không đảm bảo chạy chính xác thời điểm [S16].

### 12.2 Cấu hình sản xuất tối thiểu

- `WP_DEBUG=false`, `WP_DEBUG_DISPLAY=false`, `DISALLOW_FILE_EDIT=true` trong `wp-config.php`; log errors ở server không public.
- Bật HTTPS redirect và `FORCE_SSL_ADMIN` nếu phù hợp hạ tầng, secure cookies; cấu hình backup offsite.
- Quyền file hợp lý, không cho webserver ghi tùy ý vào PHP code; plugin chỉ update từ quy trình release.
- Bảo vệ `/wp-admin`, `/wp-login.php` bằng 2FA/rate limiting, đảm bảo break-glass account an toàn.
- Admin ERP và `admin-post.php` **không cache public**. Không cache HTML chứa nonce hay dữ liệu theo từng user.
- Tránh plugin không cần thiết. Quản lý dependency inventory (`composer.lock` nếu dùng Composer) và kiểm tra plugin/theme updates.

### 12.3 Release/migration/rollback

1. Backup DB + files + `wp-config.php` (bảo vệ secret, không commit vào Git).
2. Triển khai staging và chạy migration idempotent có version `erp_schema_version`.
3. Chạy smoke tests login/permission/CRUD/draft/ship/receive/payment, so khớp schema/engine.
4. Deploy code phiên bản có tag Git, chạy migration, kiểm tra health/log sau release.
5. Rollback code nếu không có schema-breaking migration; nếu schema đã thay đổi không tương thích thì dùng restore hoặc migration down **đã kiểm thử**, không giả định `dbDelta()` tự rollback.
6. Kiểm tra lại report/stock reconciliations sau phục hồi dữ liệu.

### 12.3.1 Kế hoạch migration dữ liệu 1.0 → 1.1 (nếu đã có dữ liệu)

1. **Preflight:** khóa tạm thao tác thanh toán và phát hành invoice, backup toàn bộ, snapshot số invoice/payment/outstanding/total cash, xác minh InnoDB/charset.
2. **Schema migration:** thêm 5 bảng mới, bổ sung invoice/payment/expense fields, index và trạng thái. Không sửa các bảng Core WordPress.
3. **Invoice backfill:** tạo `erp_invoice_items` từ sales/purchase item snapshots còn nguyên; ảnh chụp thông tin buyer/seller trước v1.1 **không thể khôi phục chính xác nếu nguồn đã thay đổi**, đánh dấu `legacy_snapshot_review_required` hoặc lập biên bản xác minh thủ công, tuyệt đối không nhận dữ liệu current master là snapshot gốc mà không cảnh báo.
4. **Payment backfill:** đối chiếu từng payment cũ với account quỹ thực tế (do Accountant xác nhận) trước khi insert `PAYMENT_POST:{id}` cash movement. Với payment cũ không rõ account, **chặn go-live accounting** cho đến khi xử lý, không tự gán mặc định ngẫu nhiên.
5. **Expense backfill:** tương tự payment, phải có account và `EXPENSE_POST:{id}`. Dữ liệu void cũ phải xem lại dòng tiền thực tế; không tự động dựng reversal khi không chứng minh được.
6. **Verification:** đối chiếu count/total theo payment/invoice, outstanding, mọi INV-11..INV-18 áp dụng cho dữ liệu migrated; khóa việc đóng reconciliation cho đến khi đã xử lý legacy records.
7. **Cutover:** bật lại kế toán sau sign-off, lưu checksum/report migration và audit; rollback bằng restore đã kiểm thử nếu không đạt.

Nếu chưa có dữ liệu thật (đồ án làm mới), chạy schema v1.1 từ đầu, các bước backfill không áp dụng.

### 12.4 Backup và disaster recovery

- Daily database backup; weekly full WP files (trừ cache) kèm media/private docs nếu có; lưu ít nhất một bản sao tách khỏi server (lịch giữ cụ thể quyết định theo dữ liệu thực tế).
- Tài liệu restore: dựng DB → WP files → cập nhật secrets/config/URL nếu cần → chạy kiểm tra migration schema → verify user login & stock ledger.
- Hàng tháng thử restore sang staging, đo RPO/RTO và lưu kết quả. Một backup chưa restore-test không coi là kiểm chứng đầy đủ.
- Khi mất tính toàn vẹn kho: tạm dừng các action nhập/xuất, sao lưu snapshot, chạy report đối chiếu và điều tra audit trước khi chỉnh sửa.

### 12.5 Monitoring & lịch nền

- Giám sát uptime site, mã lỗi PHP, thất bại login, authz.denied tăng đột biến, stock reconciliation mismatch, DB backup failures, dung lượng disk.
- WP-Cron (hoặc system cron gọi WP-CLI) chỉ dùng **nhắc nợ, cleanup an toàn, báo cáo định kỳ và kiểm tra đối soát**, không xử lý giao dịch bắt buộc phải hoàn thành ngay theo click.
- Nếu cần chạy chính xác, cấu hình cron OS gọi `wp cron event run --due-now` trên hosting có WP-CLI, không giả định traffic WP-Cron đủ ổn định [S16].

## 13. Test plan / Acceptance criteria

### 13.1 Các ca kiểm thử chức năng tối thiểu

| Test ID | Preconditions/action | Expected |
|---|---|---|
| T-F01 | Manager tạo SKU mới/trùng | Unique SKU; reject duplicate |
| T-F02 | Sales confirm draft nhiều item, gồm hai dòng 0.500 VND | Giữ exact từng dòng, subtotal chứng từ = 1 VND sau một lần làm tròn; không phải 2 VND. Print/PDF hiển thị exact và điều chỉnh làm tròn theo contract đã chốt |
| T-F03 | Sales discount vượt ngưỡng | PENDING_APPROVAL; Manager khác creator approve |
| T-F04 | Warehouse nhận một phần rồi phần còn lại của PO | Hai receipt, backorder đúng, balance/ledger đúng, không vượt ordered quantity |
| T-F05 | Warehouse giao một phần rồi giao phần còn lại của SO | Hai shipment, backorder đúng, không âm tồn, audit từng lần |
| T-F06 | Ship thiếu tồn | Rollback 100%, không ledger mồ côi |
| **T-F07** | Accountant issue sales invoice | Chỉ từ lượng SHIPPED chưa invoiced; snapshot items/buyer/seller/totals, tối đa một invoice/shipment source |
| **T-F08** | Sửa product/customer sau issue | HTML/PDF invoice không đổi |
| **T-F09** | Invoice 5m, thu 2m+1m+2m qua 2 accounts | 3 payments, history 3 rows, status PAID, outstanding 0 |
| **T-F10** | Invoice due yesterday, còn thiếu 500k | PARTIAL và OVERDUE indicator, aging đúng ngày |
| **T-F11** | POST payment vượt outstanding hoặc trùng request_key | Reject hoặc idempotent same result, không double ledger |
| **T-F12** | Post payment 1m + issue phiếu thu PDF | Cash movement 1m, voucher có đúng payment/method/account |
| **T-F13** | Expense 200k từ cash quỹ | Expense+cash movement OUT cùng txn; balance giảm 200k |
| **T-F14** | Request → manager approve → apply VOID payment nhập nhầm | Payment VOID, reversal movement, outstanding tái tính, original ledger vẫn còn |
| **T-F15** | Attempt VOID matched payment | Reject, reason/audit, không thay đổi cash |
| **T-F16** | Manual reconciliation account/period, thử note giải trình khi delta khác 0 | Unique match, chỉ delta chính xác 0 và match đủ mới CLOSE; note không override; no edit after CLOSE |
| **T-F17** | Invoice detail/print/PDF Vietnamese | Unicode đúng, số/totals/states consistent, tải PDF có auth |
| **T-F18** | CashBook/aging/revenue dashboards | Totals có thể tái tính độc lập từ source tables |
| T-F19 | Stock adjustment request by creator | Same actor approve blocked; stale apply blocked; không cung cấp opening-count workflow. Initial stock qua approved PO receipt |

### 13.2 Security/critical negative tests

| Test ID | Input / tình huống | Kết quả bắt buộc |
|---|---|---|
| T-S01 | Unauthenticated POST ERP mutation | 403/login; no write |
| T-S02 | Sales đổi user role bằng sửa request | 403, no escalation |
| T-S03 | Sales A mở đơn của Sales B qua ID | 403/404, no information exposure |
| T-S04 | Warehouse post payment/void | 403 |
| T-S05 | POST thiếu nonce/sai nonce | Reject, no mutation |
| T-S06 | HTML/JS payload trong customer/invoice note | Không XSS |
| T-S07 | SQL injection qua filter/search/sort | Prepared SQL/allowlist; no injection |
| T-S08 | Fake amount/totals/approved_by trong request | Ignored/rejected, server owns calculations |
| T-S09 | Replay same shipment request | Exactly one shipment and ledger set |
| T-S10 | 2 shipping requests, stock=5, each qty=4 | Tối đa một success, balance=1 |
| T-S11 | 2 concurrent payment 700k vào invoice outstanding 1m | Tối đa 1 success, no overpayment |
| T-S12 | Simulated exception between balance update and movement | DB rollback all changes |
| T-S13 | URL PDF invoice ID khác/unauthenticated | 403/404; file not accessible via /uploads |
| T-S14 | Sales đọc audit/finance report other scope | 403 |
| T-S15 | DB deadlock/lock timeout, retry | Finite retry, idempotency preserved |
| T-S16 | Role thay URL admin menu | No unauthorized content |
| **T-S17** | Truy cập PDF của khách khác và đoán path | Enforced object-level policy, no SSRF/path traversal |
| **T-S18** | Payment VOID không có Manager approval | Reject and original/cash movement intact |
| **T-S19** | Payment đã matched/đóng kỳ nhưng request VOID | Reject |
| **T-S20** | Replay payment create/void + retry concurrent | Unique events, no duplicate cash movement |
| **T-S21** | Cố sửa cash account opening balance sau posted | Reject + log |
| **T-S22** | Cố match same cash movement vào 2 batches | Unique constraint prevents |
| **T-S23** | Close reconciliation có chênh lệch hoặc overlap closed period | Reject |
| **T-S24** | CSV cell starts with =,+,-,@ | Không bị thực thi công thức trong Excel-compatible export |

### 13.3 Automation tools và bằng chứng

- **PHPUnit + WordPress test environment** cho policies/services; integration tests trên MySQL thật (không thay thế transaction tests bằng SQLite nếu behavior khác).
- **WP-CLI** để quản lý plugins/seed DB; **PHP_CodeSniffer WordPress Coding Standards**, PHPStan và dependency vulnerability scan; trình duyệt Playwright/Selenium nếu có đủ thời gian.
- **OWASP ZAP** quét staging có ủy quyền, tập trung form/IDOR/CSRF/XSS; báo cáo có scope rõ ràng. Không chạy quét tấn công lên môi trường hoặc website không thuộc quyền quản lý.
- Kiểm thử concurrency bằng PHP/cURL/script song song có barrier, kiểm tra database sau mỗi vòng; quan sát transaction isolation, unique keys, deadlocks.
- Nghiệm thu security: **100% T-S01...T-S24 pass**, không còn lỗ hổng Critical/High biết được trong phạm vi plugin, các invariant INV-01..INV-18 không vi phạm.

## 14. Kế hoạch triển khai và bàn giao theo phiên bản 1.1

| Sprint | Ưu tiên | Đầu ra kỹ thuật | Exit criteria |
|---|---|---|---|
| S1 | P0 | Bootstrap, migration, roles, UI, policy guard | Unauthorized route blocked |
| S2 | P0 | Product/category, customer/supplier, warehouse | CRUD, validation, unique keys |
| S3 | P0 | Sales/PO pricing & approval | State machine và maker-checker pass |
| S4 | P0 | Goods receipts, shipments, stock ledger | No negative stock/duplicate shipment |
| **S5** | **P0** | Invoice snapshots, invoice_items, detail/print/PDF | T-F07/08/17, scoped downloads pass |
| **S6** | **P0** | Payment multiple parts, cash accounts/movements, payment history, voucher | T-F09..T-F13, payment concurrency pass |
| **S7** | **P0** | Due date/aging, void approval/reversal, manual reconciliation | T-F14..T-F16, invariants 11–18 pass |
| S8 | P0 | Dashboard/reports, audit, security tests, backup/restore | T-S01..T-S24 pass; restore drill |
| S9 | P1 | UX polish, CSV safe export, docs, demo/seed data | End-to-end demo; role UI review |

**Ước lượng:** 9 sprint chia theo nhóm chức năng, không phải 9 tuần cố định; một người làm part-time có thể cần 14–24 tuần hoặc hơn. Nếu thời gian hạn chế, giữ nguyên bảo mật và tính toàn vẹn P0; ưu tiên giảm UX/các báo cáo phụ thay vì bỏ transaction hoặc audit.

**Deliverables:** Git repository của custom plugin, migration/changelog SQL, ERD, role-permission matrix, workflow diagrams, UI contracts, test matrix, seed/demo dataset, hướng dẫn cài/khôi phục, threat model + scan staging, mẫu PDF nội bộ in được.

### 14.1 Demo 15 bước end-to-end

1. WP technical Administrator kích hoạt plugin; tạo 5 ERP role và tài khoản demo.
2. ERP SysAdmin phân quyền; Sales không thấy kế toán chi tiết.
3. Manager tạo product, customer, supplier; Accountant tạo yêu cầu quỹ/bank account opening 10.000.000 VND; Manager khác xác nhận/kích hoạt.
4. Manager tạo PO; Manager khác duyệt; Warehouse nhận hàng → stock tăng.
5. Sales tạo đơn 5.000.000 VND; Manager duyệt discount nếu vượt ngưỡng.
6. Warehouse ship → stock giảm, không double shipment.
7. Accountant issue invoice; in PDF tiếng Việt, có các item và snapshot dữ liệu.
8. Thay đổi tên khách hàng/sản phẩm ở master; bản PDF invoice không đổi.
9. Accountant thu lần 1: 2.000.000 bank; lần 2: 1.000.000 cash; xem PARTIAL và history.
10. Accountant thu lần 3: 2.000.000 bank; thấy PAID, outstanding 0.
11. Accountant tạo expense 200.000 cash; CashBook ghi OUT đúng.
12. Tạo invoice đến hạn còn chưa trả → công nợ quá hạn và nhóm aging hiển thị đúng.
13. Tạo một payment nhập nhầm khác (chưa reconciled), Accountant request void → Manager approve → Accountant apply, có reversal.
14. Accountant đối soát bank manual, Manager đóng batch nếu delta=0; đã CLOSE không sửa được.
15. Chạy negative test PDF IDOR, SQLi/nonce, race payment/stock và xem audit/report phù hợp.

## 15. Go-live checklist (không được bỏ qua)

- [ ] WordPress/PHP/DB versions được hỗ trợ; HTTPS hoạt động và redirect chuẩn.
- [ ] Không có plugin/theme dư thừa hoặc nguồn không tin cậy; staging và production tách nhau.
- [ ] Mỗi ERP role dùng tài khoản riêng; technical Admin được bảo vệ MFA.
- [ ] Plugin migration idempotent; InnoDB và indexes/unique constraints đã verify.
- [ ] Không có `admin_post_nopriv` hoặc `wp_ajax_nopriv` cho nghiệp vụ ERP.
- [ ] All permission + object scope + state transition tests pass.
- [ ] Concurrency stock/payment/idempotency tests pass trên MySQL thực.
- [ ] Debug output tắt trong production; lỗi SQL không lộ cho người dùng.
- [ ] Có lịch backup offsite và bản restore drill thực tế.
- [ ] Các báo cáo stock reconciliation và manual cash reconciliation không có chênh lệch test data.
- [ ] Invoice print/PDF đã kiểm thử tiếng Việt, access control/owner scope và không có URL tải công khai.
- [ ] Payment multiple parts, void+reversal và cash ledger invariants INV-11..INV-18 đều pass.
- [ ] Công nợ quá hạn, chứng từ thu/chi và lịch sử giao dịch đã đối chiếu từ dữ liệu nguồn.
- [ ] Đã thực hiện đánh giá giới hạn pháp lý của hóa đơn nội bộ/kế toán.
- [ ] Có rollback plan, người chịu trách nhiệm, liên hệ vận hành.

## 16. ADR – lựa chọn khác bị loại và đánh đổi

| ADR | Phương án chọn | Phương án khác | Đánh đổi |
|---|---|---|---|
| ADR-01 | WordPress native admin UI | React/headless | Ít công nghệ, nhanh hơn nhưng UX admin khó linh hoạt bằng SPA |
| ADR-02 | Custom plugin + domain services | Code trực tiếp theme/functions.php | Cần cấu trúc PHP, bù lại bảo trì/test tốt |
| ADR-03 | ERP custom DB tables | CPT/postmeta cho mọi giao dịch | Cần migrations, nhưng có transaction/index/constraints phù hợp |
| ADR-04 | admin-post + AJAX read | Public REST API khắp nơi | Giảm công bề mặt API; đổi lại không dễ tích hợp bên ngoài |
| ADR-05 | Maker-checker cho việc nhạy cảm | Manager kiểm soát một mình | Có thể chậm hơn, tránh gian lận và thao tác sai |
| ADR-06 | Stock ledger + balances | Chỉ lưu quantity hiện tại | Nhiều bảng hơn, đối soát và audit tốt hơn |
| ADR-07 | Không dùng WooCommerce | WooCommerce làm nền ERP | Bỏ tính năng ecommerce dư thừa, tự chịu trách nhiệm triển khai nghiệp vụ |
| ADR-08 | Thanh toán, quỹ theo append-only ledger | Chỉ lưu payment + số dư cập nhật tay | Dữ liệu kiểm toán mạnh hơn, cần đối soát và transaction |
| ADR-09 | Print/PDF nội bộ có auth | Public media URL/hóa đơn điện tử tự làm | Dễ triển khai demo an toàn; không khẳng định hóa đơn pháp lý |
| ADR-10 | Đối soát thủ công P0 | Kết nối banking real-time | Không cần ngân hàng/API, có giới hạn xác thực tiền thực nhận |

## 17. Danh sách rủi ro còn lại và điều kiện mở rộng

- **WP technical Administrator** là trust root: nếu bị chiếm tài khoản hoặc server, custom ERP permission không thể chống lại toàn bộ quyền của kẻ tấn công. Cần MFA, host hardening, patching và backup.
- **Khả năng đáp ứng tải:** mục tiêu NFR chỉ được công nhận sau benchmark; nếu dữ liệu lớn có thể cần phân vùng báo cáo, caching đọc, indexes mới.
- **Hóa đơn/pháp luật Việt Nam:** `erp_invoices` trong spec là chứng từ/hóa đơn nội bộ minh họa, **không** có nghĩa đáp ứng chuẩn hóa đơn điện tử hoặc quy định kế toán/thuế Việt Nam. Muốn đưa vào doanh nghiệp thật phải rà soát nghiệp vụ, quy định và tích hợp đơn vị cung cấp hóa đơn điện tử được phép.
- **Double-entry accounting / COGS / returns/refunds/credit notes:** cần thiết kế bổ sung trước khi gọi đây là ERP tài chính hoàn chỉnh. V1.1 không xử lý hoàn tiền thực tế sau khi hàng đã bán; phải thực hiện quy trình được phê duyệt ngoài hệ thống hoặc không sử dụng với nghiệp vụ có trả hàng cho đến khi triển khai P1.
- **Đối soát ngân hàng:** chỉ là đối soát sao kê do người dùng nhập tay, không xác minh giao dịch ngân hàng thật; không sử dụng từ ngữ “đã xác thực ngân hàng”.
- **Hóa đơn pháp lý:** tham khảo Nghị định 123/2020/NĐ-CP và Nghị định 70/2025/NĐ-CP [S22]; bản PDF từ plugin không phải e-invoice hợp pháp. Kiểm tra quy định cập nhật tại thời điểm đưa vào kinh doanh thật.
- **Đa kho nhưng chưa chuyển kho:** có tồn theo kho; chuyển kho/điều chuyển là module v2 với hai ledger lines có transaction chung.
- **Lưu trữ chứng từ:** v1.1 không cho upload file hóa đơn public; nếu cần, thiết kế private document storage và download authorization trước.
- **Giới hạn tồn mở kỳ (quyết định v1.1):** nhập tồn mở kỳ được hoãn ngoài P0. Không thêm opening-count workflow, không dùng kiểm kê để giả nhập tồn mở kỳ, **không** sửa balance trực tiếp. Tồn ban đầu MVP/demo chỉ đến từ đơn mua được duyệt và nhận hàng. Không hỗ trợ nhập số dư tồn của doanh nghiệp đang vận hành trong P0.

## 18. Định nghĩa hoàn thành (Definition of Done)

Một yêu cầu nghiệp vụ chỉ được coi DONE khi có: (a) permission + object policy; (b) validate/input allowlist; (c) transaction/idempotency nếu thay đổi trạng thái/tồn/tiền; (d) migration/index phù hợp; (e) audit; (f) unit/integration/negative tests; (g) UI message và hướng dẫn sử dụng; (h) staging verification. Một bản demo đẹp mà bỏ thiếu 3/8 mục này chưa được coi là vận hành an toàn.

---

## 19. Nguồn tham khảo đã tra cứu (ưu tiên primary sources)

**Ngày đối chiếu:** 19/10/2026 (bổ sung nguồn chính thức ở bản 1.1). Các đường dẫn dưới đây là trang tài liệu nguồn trực tiếp; đặc tả này có thể thay đổi nếu tài liệu/phiên bản nền tảng được cập nhật.

- **[S1] WordPress Developer – Roles and Capabilities**: https://developer.wordpress.org/plugins/users/roles-and-capabilities/
- **[S2] WordPress Developer – Creating Tables with Plugins**: https://developer.wordpress.org/plugins/creating-tables-with-plugins/
- **[S3] WordPress Developer – add_menu_page()**: https://developer.wordpress.org/reference/functions/add_menu_page/
- **[S4] WordPress Developer – admin_post_{$action}**: https://developer.wordpress.org/reference/hooks/admin_post_action/
- **[S5] WordPress Developer – Nonces**: https://developer.wordpress.org/apis/security/nonces/
- **[S6] WordPress Developer – wpdb::prepare()**: https://developer.wordpress.org/reference/classes/wpdb/prepare/
- **[S7] WordPress Developer – Validating Data**: https://developer.wordpress.org/apis/security/data-validation/
- **[S8] WordPress Developer – Escaping Data**: https://developer.wordpress.org/apis/security/escaping/
- **[S9] MySQL 8.4 Reference – InnoDB Best Practices**: https://dev.mysql.com/doc/refman/8.4/en/innodb-best-practices.html
- **[S10] MySQL 8.4 Reference – InnoDB Deadlocks**: https://dev.mysql.com/doc/refman/8.4/en/innodb-deadlocks.html
- **[S11] OWASP API Security Top 10 (2023)**: https://api-security.owasp.org/editions/2023/en/0x11-t10/
- **[S12] OWASP Authorization Cheat Sheet**: https://cheatsheetseries.owasp.org/cheatsheets/Authorization_Cheat_Sheet.html
- **[S13] OWASP Logging Cheat Sheet**: https://cheatsheetseries.owasp.org/cheatsheets/Logging_Cheat_Sheet.html
- **[S14] WordPress Developer – Privacy Handbook**: https://developer.wordpress.org/plugins/privacy/
- **[S15] WordPress.org – Hosting Requirements**: https://wordpress.org/about/requirements/
- **[S16] WordPress Developer – WP-Cron**: https://developer.wordpress.org/plugins/cron/
- **[S17] WordPress Developer – Activation/Deactivation Hooks**: https://developer.wordpress.org/plugins/plugin-basics/activation-deactivation-hooks/
- **[S18] WordPress Developer – check_ajax_referer()**: https://developer.wordpress.org/reference/functions/check_ajax_referer/
- **[S19] WordPress Developer – REST cookie authentication (nếu mở REST v2)**: https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/
- **[S20] OWASP ASVS v5.0 (chuẩn kiểm chứng bảo mật tham chiếu)**: https://github.com/OWASP/ASVS/tree/master/5.0

**Lưu ý nguồn:** WordPress khuyến nghị cân nhắc post meta khi phù hợp; lựa chọn custom tables trong đặc tả là **quyết định thiết kế có lý do riêng** vì tồn kho, thanh toán và đơn hàng yêu cầu unique indexes và transaction [S2, S9]. Không coi một phương án là quy định bắt buộc cho mọi WordPress plugin.

- **[S21] MySQL 8.4 – Locking Reads (`SELECT ... FOR UPDATE`)**: https://dev.mysql.com/doc/refman/8.4/en/innodb-locking-reads.html
- **[S22] Chính phủ – Nghị định 70/2025/NĐ-CP sửa đổi quy định hóa đơn, chứng từ**: https://chinhphu.vn/?classid=1&docid=213179&orggroupid=2&pageid=27160
- **[S23] WordPress Developer – `check_admin_referer()`**: https://developer.wordpress.org/reference/functions/check_admin_referer/
- **[S24] WordPress Developer – `wp_nonce_field()`**: https://developer.wordpress.org/reference/functions/wp_nonce_field/

## 20. Phụ lục triển khai nhanh & thuật ngữ

### 20.1 Thứ tự khởi chạy trên môi trường đồ án

1. Khởi tạo một bản WordPress single-site trên HTTPS hoặc localhost tách staging; cài đúng PHP/MySQL/WordPress đã kiểm thử.
2. Cài custom plugin, chạy installer/migration phiên bản 1.1; kiểm tra **26 bảng ERP là InnoDB** và các unique/index hoạt động.
3. Đăng ký 5 role ERP và map capability theo mục 3, tạo 5–7 tài khoản demo riêng; WP Administrator giữ ngoài workflow kinh doanh thường nhật.
4. Seed category, products, customer, supplier, warehouse; tạo Account DRAFT (cash/bank), Manager duyệt opening, active account.
5. Thử end-to-end PO → receipt → SO → shipment → invoice → PDF → payment nhiều lần → cash ledger → aging → reconciliation.
6. Chạy test T-F01..T-F19, T-S01..T-S24 trên MySQL thật; fix bất cứ failure nào trước khi ghi nhãn 'ready for demo'.
7. Backup và restore drill, kiểm tra quyền tải PDF, mốc giờ Asia/Ho_Chi_Minh, máy in/PDF có tiếng Việt, rồi khóa môi trường demo và tạo báo cáo nghiệm thu.

### 20.2 Thuật ngữ

| Thuật ngữ | Định nghĩa sử dụng trong bản spec |
|---|---|
| **Internal Invoice** | Chứng từ hóa đơn nội bộ dùng demo/vận hành quản trị; không mặc nhiên là hóa đơn điện tử hợp pháp |
| **Snapshot** | Giá/trường/đối tượng được chụp tại thời điểm phát hành; không đổi khi dữ liệu master thay đổi |
| **POSTED** | Giao dịch được hệ thống ghi sổ hợp lệ, có cash movement và audit |
| **VOID** | Hủy hiệu lực ghi sổ sai có phê duyệt; dữ liệu lịch sử và bút toán đảo vẫn còn |
| **Reversal** | Dòng tiền đảo chiều của giao dịch sai, không phải hoàn tiền thực tế |
| **Outstanding** | Số phải thu/trả còn lại = tổng hóa đơn − tổng thanh toán POSTED |
| **PARTIAL / PAID** | Thanh toán một phần / đủ tiền hóa đơn; không đồng nghĩa trạng thái giao hàng |
| **OVERDUE** | Nhãn công nợ quá hạn, chỉ áp dụng khi outstanding > 0 và đã qua due date |
| **Ledger** | Sổ biến động append-only, là nguồn kiểm tra tồn kho hoặc dòng tiền |
| **Idempotency** | Gửi lại một yêu cầu không được tạo thêm nghiệp vụ/phiếu trùng |
| **Maker-checker** | Người thực hiện yêu cầu và người duyệt là hai người dùng khác nhau |
| **Reconciliation** | Đối chiếu sổ quỹ ERP với số liệu sao kê được nhập/kiểm tra thủ công |
| **RPO / RTO** | Mức dữ liệu chấp nhận mất khi sự cố / thời gian phục hồi mục tiêu |

**Mốc hoàn thành:** tài liệu này là **specification**. Hệ thống được coi là *có thể vận hành nội bộ trong phạm vi đã định nghĩa* chỉ sau khi triển khai plugin đầy đủ, kiểm tra pháp lý áp dụng, tất cả test P0 đạt, và đã xác minh backup/restore cùng chính sách phân quyền trên môi trường đích.
