# Thiết kế prototype ERP: Đơn bán

## Mục tiêu và luồng người dùng

Người dùng chính là Sales và Manager cần tìm đơn, nhận biết đơn đang ở bước nào, mở chứng từ để kiểm tra hàng và số tiền, rồi thực hiện đúng hành động được phép. Luồng chính: tạo nháp → gửi duyệt nếu vượt ngưỡng chiết khấu → Manager duyệt hoặc trả lại → Warehouse giao hàng. Màn này tập trung vào danh sách và chi tiết đơn; không đưa KPI, biểu đồ hay hoạt động giả lên trang.

## Phân cấp thông tin

1. **Đơn bán** và hành động chính **Tạo đơn bán**.
2. Tìm nhanh theo mã/khách hàng, lọc theo trạng thái và kho.
3. Danh sách có mã, khách hàng, kho, ngày tạo, tổng tiền và trạng thái; số tiền căn phải.
4. Chi tiết đơn đang chọn: trạng thái và hành động hợp lệ, người mua/kho/người tạo, số lượng đặt/đã giao/còn lại theo dòng, tổng tiền và lịch sử chứng từ.

## Wireframe desktop

```text
┌ WordPress admin navigation ─┬─────────────────────────────────────────────────────────────┐
│ Đơn bán (active)            │ Đơn bán                  [Tạo đơn bán]                      │
│ Đơn mua                     │ [Tìm kiếm] [Trạng thái] [Kho]                              │
│ Sản phẩm                    ├──────────────────────────────┬──────────────────────────────┤
│ Khách hàng                  │ Bảng đơn bán                 │ SO-2026-0142  Chờ duyệt      │
│ Kho                         │ mã / khách / kho / ngày      │ khách, kho, người tạo        │
│ Hóa đơn                     │ tổng / trạng thái            │ dòng hàng và thành tiền      │
│ Thanh toán                  │ chọn một hàng                │ lịch sử / hành động          │
└─────────────────────────────┴──────────────────────────────┴──────────────────────────────┘
```

Trên màn nhỏ, điều hướng WordPress thu gọn; danh sách và chi tiết xếp dọc để giữ bảng dễ đọc. Không tạo sidebar thứ hai cho sản phẩm.

## Thành phần prototype

- Điều hướng WordPress được mô phỏng để đặt màn hình đúng ngữ cảnh.
- Bộ lọc trạng thái, tìm kiếm và lọc kho chạy trên dữ liệu mẫu phía trình duyệt.
- Bảng có chọn hàng, sắp xếp cột tổng tiền, tìm theo mã/khách hàng và lọc kho/trạng thái.
- Pane chi tiết cập nhật theo đơn; hành động phụ thuộc trạng thái: gửi duyệt, duyệt, trả về nháp hoặc ghi nhận giao hàng từng phần.
- Form giao hàng nhận số lượng theo từng dòng, giới hạn ở số còn lại; nhiều lần post tạo shipment riêng, giảm số còn lại và cập nhật trạng thái giao một phần/đã giao.
- Form nháp inline có khách hàng, kho, sản phẩm, số lượng, chiết khấu và tổng tiền dự kiến; hỗ trợ sửa nháp, xác thực bắt buộc và chặn chiết khấu vượt tiền hàng.
- Có điều hướng bàn phím cho các tab trạng thái, focus nhìn thấy được và trạng thái tải có thông báo `aria-busy`.
- Bộ chọn trạng thái prototype để xem loading, empty, error, forbidden và success.

## Phê bình thiết kế

Đã bỏ KPI cards, biểu đồ, feed hoạt động chung, icon trang trí, gradient, bóng đổ lớn và modal tạo đơn. Những yếu tố đó chiếm chỗ nhưng không giúp nhân viên tìm hoặc xử lý đơn nhanh hơn. Pane chi tiết dùng cùng một màn hình để đối chiếu danh sách và chứng từ; chỉ các hành động hợp lệ theo trạng thái mới xuất hiện.

## Giới hạn

Prototype HTML/CSS/JavaScript này vẫn chỉ dùng dữ liệu mẫu; các thao tác của nó không ghi vào hệ thống thật. Kể từ sau khi prototype được viết, plugin Mini ERP đã có route WordPress riêng cho các phân hệ, kiểm tra capability, phạm vi kho/quỹ, trạng thái nghiệp vụ, lưu trữ và audit. Màn thực tế và prototype là hai bề mặt khác nhau; prototype không phải bằng chứng nghiệm thu của route, bảo mật, giao dịch cơ sở dữ liệu hay giao diện đã render. Ứng dụng thật đang dùng lớp giao diện chung trong `assets/frontend.css` để thống nhất bảng, biểu mẫu, điều hướng, thông báo và trạng thái tương tác.
