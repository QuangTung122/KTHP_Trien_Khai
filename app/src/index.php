<?php
$host = getenv('DB_HOST') ?: 'mysql';
$db   = getenv('MYSQL_DATABASE') ?: 'inventory_db';
$user = getenv('MYSQL_USER') ?: 'inv_user';
$pass = getenv('MYSQL_PASSWORD') ?: '';

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Kết nối CSDL thất bại: " . $conn->connect_error);
}
$conn->set_charset("utf8mb4");

// Xử lý Nhập / Xuất kho
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type'])) {
    $product_id = (int)$_POST['product_id'];
    $type = $_POST['action_type'] === 'IN' ? 'IN' : 'OUT';
    $amount = (int)$_POST['amount'];
    $note = $conn->real_escape_string($_POST['note']);

    if ($amount > 0) {
        $stmt = $conn->prepare("INSERT INTO transactions (product_id, type, amount, note) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("isis", $product_id, $type, $amount, $note);
        $stmt->execute();

        $op = ($type === 'IN') ? "+" : "-";
        $conn->query("UPDATE products SET quantity = quantity $op $amount WHERE id = $product_id");
        $msg = "Đã cập nhật phiếu " . ($type === 'IN' ? 'NHẬP' : 'XUẤT') . " kho thành công!";
    }
}

$products = $conn->query("SELECT p.*, s.name AS supplier_name FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id");
$transactions = $conn->query("SELECT t.*, p.sku, p.name AS product_name FROM transactions t JOIN products p ON t.product_id = p.id ORDER BY t.created_at DESC LIMIT 10");
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Hệ thống Quản lý Kho hàng (Inventory)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h2 class="mb-4 text-primary fw-bold">📦 Hệ thống Quản lý Kho hàng (Đề 5)</h2>
    <?php if ($msg): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="row">
        <!-- Bảng Tồn kho -->
        <div class="col-md-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white fw-bold">Danh sách Tồn kho & Nhà cung cấp</div>
                <div class="card-body">
                    <table class="table table-bordered table-hover">
                        <thead class="table-secondary">
                            <tr>
                                <th>Mã SKU</th>
                                <th>Tên hàng hóa</th>
                                <th>Tồn kho</th>
                                <th>Đơn giá (VNĐ)</th>
                                <th>Nhà cung cấp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $products->fetch_assoc()): ?>
                            <tr>
                                <td><code><?= htmlspecialchars($row['sku']) ?></code></td>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td class="fw-bold <?= $row['quantity'] < 10 ? 'text-danger' : 'text-success' ?>"><?= $row['quantity'] ?></td>
                                <td><?= number_format($row['price']) ?></td>
                                <td><?= htmlspecialchars($row['supplier_name']) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Form Nhập/Xuất Kho -->
        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white fw-bold">Tạo Phiếu Nhập / Xuất Kho</div>
                <div class="card-body">
                    <form method="POST">
                        <div class="mb-3">
                            <label class="form-label">Sản phẩm</label>
                            <select name="product_id" class="form-select" required>
                                <?php
                                $products->data_seek(0);
                                while($p = $products->fetch_assoc()): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['sku'] . ' - ' . $p['name']) ?></option>
                                <?php endwhile; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Loại giao dịch</label>
                            <select name="action_type" class="form-select" required>
                                <option value="IN">Nhập kho (+)</option>
                                <option value="OUT">Xuất kho (-)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Số lượng</label>
                            <input type="number" name="amount" class="form-control" min="1" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Ghi chú</label>
                            <input type="text" name="note" class="form-control" placeholder="Lý do nhập/xuất...">
                        </div>
                        <button type="submit" class="btn btn-success w-100">Xác nhận giao dịch</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Lịch sử giao dịch -->
    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white fw-bold">Lịch sử Nhập / Xuất gần đây</div>
        <div class="card-body">
            <table class="table table-sm table-striped">
                <thead>
                    <tr>
                        <th>Thời gian</th>
                        <th>Mã SKU</th>
                        <th>Sản phẩm</th>
                        <th>Loại</th>
                        <th>Số lượng</th>
                        <th>Ghi chú</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($t = $transactions->fetch_assoc()): ?>
                    <tr>
                        <td><?= $t['created_at'] ?></td>
                        <td><?= htmlspecialchars($t['sku']) ?></td>
                        <td><?= htmlspecialchars($t['product_name']) ?></td>
                        <td>
                            <span class="badge <?= $t['type'] === 'IN' ? 'bg-success' : 'bg-warning text-dark' ?>">
                                <?= $t['type'] === 'IN' ? 'NHẬP' : 'XUẤT' ?>
                            </span>
                        </td>
                        <td class="fw-bold"><?= $t['amount'] ?></td>
                        <td><?= htmlspecialchars($t['note']) ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
