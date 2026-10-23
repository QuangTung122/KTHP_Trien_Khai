# ĐỀ TÀI 5: HỆ THỐNG QUẢN LÝ KHO HÀNG (INVENTORY MANAGEMENT SYSTEM)

**Môn học:** Triển khai và Quản trị Hệ thống Phần mềm[cite: 1]  
**Sinh viên thực hiện:** Ngo Tung (Mã số sinh viên: *[Điền MSSV của bạn]*[cite: 1])  
**Môi trường triển khai:** Ubuntu 18.04 LTS trên VMware, Docker & Docker Compose v2.  
**Repository GitHub:** [https://github.com/QuangTung122/KTHP_Trien_Khai.git](https://github.com/QuangTung122/KTHP_Trien_Khai.git)

---

## 1. TỔNG QUAN KIẾN TRÚC HỆ THỐNG
Hệ thống được thiết kế theo mô hình Multi-container chạy hoàn toàn bằng **Docker Compose**, phân chia thành các lớp mạng độc lập (`frontend_net`, `backend_net`, `monitoring_net`) nhằm đảm bảo tính bảo mật và hiệu năng:

1. **Lớp Giao diện & Điều phối (Frontend & Reverse Proxy):**
   - **Nginx (v1.24):** Đóng vai trò Reverse Proxy, hỗ trợ HTTPS với chứng chỉ SSL tự ký, tích hợp đầy đủ các *Security Headers* bảo mật và chặn ẩn phiên bản (`server_tokens off`).
   - **PHP Web Application:** Chạy trên nền Apache (PHP 8.1), kết nối trực tiếp cơ sở dữ liệu để thực hiện nghiệp vụ Nhập/Xuất kho hàng hóa.

2. **Lớp Cơ sở dữ liệu (Database Layer):**
   - **MySQL (v8.0):** Lưu trữ thông tin nhà cung cấp, danh mục sản phẩm tồn kho và lịch sử giao dịch (hỗ trợ chuẩn tiếng Việt `utf8mb4`). *(Được đặt trong mạng nội bộ backend_net, tuyệt đối không mở port ra bên ngoài máy host).*
   - **phpMyAdmin:** Công cụ quản trị CSDL trực quan phục vụ công tác kiểm tra dữ liệu.

3. **Lớp Giám sát & Đo lường (Monitoring Layer):**
   - **Prometheus:** Thu thập các thông số (metrics) định kỳ từ hệ thống.
   - **Grafana:** Giao diện trực quan hóa dữ liệu giám sát (Dashboard) cho Container, Web Server và Database.
   - **Exporters:** `cAdvisor` (giám sát container), `Nginx Prometheus Exporter`, `MySQL Exporter`.

4. **Lớp Quản lý Log tập trung (Centralized Logging):**
   - **Loki & Promtail:** Promtail tự động thu thập log từ Docker socket của toàn bộ container và đẩy về Loki server để phục vụ truy vấn LogQL.

---

## 2. CÁC BIỆN PHÁP BẢO MẬT (SYSTEM HARDENING) ĐÃ ÁP DỤNG
Đồ án tích hợp các tiêu chuẩn hardening khắt khe:
- **Non-root Container:** Ứng dụng Web PHP chạy dưới quyền user giới hạn `www-data` (UID 33) thay vì quyền `root`.
- **Network Isolation:** Cô lập hoàn toàn MySQL trong mạng nội bộ (`backend_net`), chặn các truy cập trái phép từ bên ngoài.
- **Security Headers trên Nginx:** Kích hoạt các header bảo mật (`X-Frame-Options`, `X-Content-Type-Options`, `X-XSS-Protection`, `Strict-Transport-Security`).
- **Privilege Escalation Prevention:** Cấu hình `security_opt: [no-new-privileges:true]` cho các container quan trọng để ngăn chặn leo thang đặc quyền.
- **Bảo mật thông tin nhạy cảm:** Tách biệt hoàn toàn mật khẩu (`.env`) và khóa SSL ra khỏi mã nguồn bằng cơ chế `.gitignore`.

---

## 3. HƯỚNG DẪN CÀI ĐẶT VÀ TRIỂN KHAI

### Bước 1: Clone Repository về máy ảo Ubuntu
```bash
git clone [https://github.com/QuangTung122/KTHP_Trien_Khai.git](https://github.com/QuangTung122/KTHP_Trien_Khai.git)
cd KTHP_Trien_Khai
Bước 2: Cấu hình biến môi trường
Sao chép file mẫu .env.example thành .env và chỉnh sửa mật khẩu nếu cần:

Bash
cp .env.example .env
Bước 3: Tạo chứng chỉ SSL tự ký cho Nginx
Tạo thư mục chứa chứng chỉ và sinh cặp khóa RSA 2048-bit:

Bash
mkdir -p nginx/certs
openssl req -x509 -nodes -days 365 -newkey rsa:2048 \
  -keyout nginx/certs/selfsigned.key \
  -out nginx/certs/selfsigned.crt \
  -subj "/C=VN/ST=Hanoi/L=Hanoi/O=University/OU=IT/CN=localhost"
Bước 4: Khởi chạy toàn bộ hệ thống bằng Docker Compose
Bash
docker-compose up -d --build
4. DANH SÁCH CÁC CỔNG TRUY CẬP DỊCH VỤ
Website Quản lý Kho (HTTPS): https://localhost (Bấm Advanced -> Proceed khi trình duyệt cảnh báo chứng chỉ tự ký)

phpMyAdmin (Quản trị MySQL): http://localhost:8081

Prometheus (Giám sát metrics): http://localhost:9090

Grafana (Dashboards & Loki Logs): http://localhost:3000 (Đăng nhập: admin_sv / Mật khẩu trong file .env)

5. CÁC CÂU TRUY VẤN LOGQL (DÙNG CHO LOKI)
Truy cập Grafana -> Explore -> Chọn nguồn Loki và sử dụng các câu truy vấn mẫu sau:

Xem toàn bộ log của Nginx Reverse Proxy:

Đoạn mã
{container="inventory-nginx"}
Lọc log từ cả Nginx và Ứng dụng Web:

Đoạn mã
{container=~"inventory-nginx|inventory-app"}
Thống kê tần suất log phát sinh theo container trong mỗi 1 phút:

Đoạn mã
sum by (container) (count_over_time({container=~".+"}[1m]))
