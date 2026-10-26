<?php
session_set_cookie_params([
    'httponly' => true,
    'secure' => true,
    'samesite' => 'Lax',
]);
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectWithFlash(string $message, string $type): never
{
    $_SESSION['flash_message'] = $message;
    $_SESSION['flash_type'] = $type;
    header('Location: /', true, 303);
    exit;
}

$message = (string)($_SESSION['flash_message'] ?? '');
$messageType = (string)($_SESSION['flash_type'] ?? 'success');
unset($_SESSION['flash_message'], $_SESSION['flash_type']);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$host = getenv('DB_HOST') ?: 'mysql';
$db = getenv('MYSQL_DATABASE') ?: 'inventory_db';
$user = getenv('MYSQL_USER') ?: 'inv_user';
$pass = getenv('MYSQL_PASSWORD') ?: '';

try {
    $conn = new mysqli($host, $user, $pass, $db);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $e) {
    error_log('Inventory database connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Dịch vụ dữ liệu hiện chưa sẵn sàng. Vui lòng thử lại sau.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($csrfToken, $submittedToken)) {
        redirectWithFlash('Yêu cầu không hợp lệ. Vui lòng tải lại trang và thử lại.', 'danger');
    }

    $formAction = $_POST['form_action'] ?? 'transaction';
    if ($formAction === 'add_product') {
        $rawSku = $_POST['sku'] ?? '';
        $rawProductName = $_POST['name'] ?? '';
        $rawInitialQuantity = $_POST['initial_quantity'] ?? '0';
        $rawPrice = $_POST['price'] ?? '';
        $supplierRaw = $_POST['supplier_id'] ?? '';
        if (!is_string($rawSku) || !is_string($rawProductName) || !is_string($rawInitialQuantity) || !is_string($rawPrice) || !is_string($supplierRaw)) {
            redirectWithFlash('Thông tin sản phẩm không hợp lệ.', 'danger');
        }

        $sku = strtoupper(trim($rawSku));
        $productName = trim($rawProductName);
        $initialQuantity = filter_var($rawInitialQuantity, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 2147483647]]);
        $priceRaw = trim($rawPrice);
        $skuLength = preg_match_all('/./us', $sku);
        $nameLength = preg_match_all('/./us', $productName);
        $price = filter_var($priceRaw, FILTER_VALIDATE_FLOAT);

        if ($sku === '' || $skuLength === false || $skuLength > 50 || $productName === '' || $nameLength === false || $nameLength > 100) {
            redirectWithFlash('Mã SKU (tối đa 50 ký tự) và tên sản phẩm (tối đa 100 ký tự) là bắt buộc.', 'danger');
        }
        if ($initialQuantity === false) {
            redirectWithFlash('Tồn kho ban đầu phải là số nguyên từ 0 đến 2.147.483.647.', 'danger');
        }
        if (!preg_match('/\A\d{1,10}(?:\.\d{1,2})?\z/D', $priceRaw) || $price === false || $price > 9999999999.99) {
            redirectWithFlash('Đơn giá phải từ 0 đến 9.999.999.999,99 và có tối đa 2 chữ số thập phân.', 'danger');
        }

        $supplierId = null;
        if ($supplierRaw !== '') {
            $supplierId = filter_var($supplierRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($supplierId === false) {
                redirectWithFlash('Nhà cung cấp đã chọn không hợp lệ.', 'danger');
            }
        }

        $transactionOpen = false;
        try {
            $conn->begin_transaction();
            $transactionOpen = true;

            if ($supplierId !== null) {
                $supplierCheck = $conn->prepare('SELECT id FROM suppliers WHERE id = ?');
                $supplierCheck->bind_param('i', $supplierId);
                $supplierCheck->execute();
                $supplierExists = $supplierCheck->get_result()->fetch_assoc();
                $supplierCheck->close();
                if (!$supplierExists) {
                    throw new DomainException('Nhà cung cấp đã chọn không tồn tại.');
                }
            }

            $insertProduct = $conn->prepare('INSERT INTO products (sku, name, quantity, price, supplier_id) VALUES (?, ?, ?, ?, ?)');
            $insertProduct->bind_param('ssisi', $sku, $productName, $initialQuantity, $priceRaw, $supplierId);
            $insertProduct->execute();
            $productId = $conn->insert_id;
            $insertProduct->close();

            if ($initialQuantity > 0) {
                $initialType = 'IN';
                $initialNote = 'Tồn kho ban đầu khi tạo sản phẩm';
                $insertTransaction = $conn->prepare('INSERT INTO transactions (product_id, type, amount, note) VALUES (?, ?, ?, ?)');
                $insertTransaction->bind_param('isis', $productId, $initialType, $initialQuantity, $initialNote);
                $insertTransaction->execute();
                $insertTransaction->close();
            }

            $conn->commit();
            $transactionOpen = false;
            redirectWithFlash("Đã thêm sản phẩm {$sku} thành công.", 'success');
        } catch (DomainException $e) {
            if ($transactionOpen) {
                $conn->rollback();
            }
            redirectWithFlash($e->getMessage(), 'warning');
        } catch (mysqli_sql_exception $e) {
            if ($transactionOpen) {
                $conn->rollback();
            }
            if ($e->getCode() === 1062) {
                redirectWithFlash('Mã SKU này đã tồn tại. Vui lòng nhập mã SKU khác.', 'warning');
            }
            error_log('Inventory product creation failed: ' . $e->getMessage());
            redirectWithFlash('Không thể thêm sản phẩm lúc này. Vui lòng thử lại.', 'danger');
        }
    }

    if ($formAction !== 'transaction') {
        redirectWithFlash('Thao tác không hợp lệ.', 'danger');
    }

    $rawProductId = $_POST['product_id'] ?? '';
    $rawAmount = $_POST['amount'] ?? '';
    if (!is_string($rawProductId) || !is_string($rawAmount)) {
        redirectWithFlash('Vui lòng chọn sản phẩm và số lượng hợp lệ.', 'danger');
    }
    $productId = filter_var($rawProductId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $amount = filter_var($rawAmount, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    $type = $_POST['action_type'] ?? '';
    $rawNote = $_POST['note'] ?? '';

    if ($productId === false || $amount === false || !in_array($type, ['IN', 'OUT'], true) || !is_string($rawNote)) {
        redirectWithFlash('Vui lòng chọn sản phẩm, loại giao dịch và số lượng hợp lệ.', 'danger');
    }

    $note = trim($rawNote);
    $noteLength = preg_match_all('/./us', $note);
    if ($noteLength === false || $noteLength > 255) {
        redirectWithFlash('Ghi chú không được vượt quá 255 ký tự.', 'danger');
    }

    $transactionOpen = false;
    try {
        $conn->begin_transaction();
        $transactionOpen = true;

        $select = $conn->prepare('SELECT quantity FROM products WHERE id = ? FOR UPDATE');
        $select->bind_param('i', $productId);
        $select->execute();
        $product = $select->get_result()->fetch_assoc();
        $select->close();

        if (!$product) {
            throw new DomainException('Không tìm thấy sản phẩm đã chọn.');
        }

        $currentQuantity = (int)$product['quantity'];
        if ($type === 'OUT' && $amount > $currentQuantity) {
            throw new DomainException('Số lượng xuất vượt quá tồn kho hiện tại.');
        }
        if ($type === 'IN' && $currentQuantity > 2147483647 - $amount) {
            throw new DomainException('Số lượng nhập vượt giới hạn lưu trữ.');
        }

        $delta = $type === 'IN' ? $amount : -$amount;
        $update = $conn->prepare('UPDATE products SET quantity = quantity + ? WHERE id = ?');
        $update->bind_param('ii', $delta, $productId);
        $update->execute();
        $update->close();

        $insert = $conn->prepare('INSERT INTO transactions (product_id, type, amount, note) VALUES (?, ?, ?, ?)');
        $insert->bind_param('isis', $productId, $type, $amount, $note);
        $insert->execute();
        $insert->close();

        $conn->commit();
        $transactionOpen = false;
        $label = $type === 'IN' ? 'NHẬP' : 'XUẤT';
        redirectWithFlash("Đã ghi nhận phiếu {$label} kho thành công.", 'success');
    } catch (DomainException $e) {
        if ($transactionOpen) {
            $conn->rollback();
        }
        redirectWithFlash($e->getMessage(), 'warning');
    } catch (mysqli_sql_exception $e) {
        if ($transactionOpen) {
            $conn->rollback();
        }
        error_log('Inventory transaction failed: ' . $e->getMessage());
        redirectWithFlash('Không thể ghi nhận giao dịch lúc này. Vui lòng thử lại.', 'danger');
    }
}

try {
    $productsResult = $conn->query(
        'SELECT p.*, s.name AS supplier_name FROM products p LEFT JOIN suppliers s ON p.supplier_id = s.id ORDER BY p.sku'
    );
    $products = $productsResult->fetch_all(MYSQLI_ASSOC);
    $suppliersResult = $conn->query('SELECT id, name FROM suppliers ORDER BY name');
    $suppliers = $suppliersResult->fetch_all(MYSQLI_ASSOC);
    $transactionsResult = $conn->query(
        'SELECT t.*, p.sku, p.name AS product_name FROM transactions t JOIN products p ON t.product_id = p.id ORDER BY t.created_at DESC, t.id DESC LIMIT 10'
    );
    $transactions = $transactionsResult->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    error_log('Inventory page query failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Không thể tải dữ liệu kho. Vui lòng kiểm tra cấu hình cơ sở dữ liệu.');
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Hệ thống Quản lý Kho hàng</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h2 class="mb-4 text-primary fw-bold">📦 Hệ thống Quản lý Kho hàng (Đề 5)</h2>
    <?php if ($message !== ''): ?>
        <div class="alert alert-<?= h($messageType) ?>" role="alert"><?= h($message) ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-dark text-white fw-bold">Danh sách Tồn kho &amp; Nhà cung cấp</div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover">
                            <thead class="table-secondary">
                                <tr><th>Mã SKU</th><th>Tên hàng hóa</th><th>Tồn kho</th><th>Đơn giá (VNĐ)</th><th>Nhà cung cấp</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($products as $row): ?>
                                <tr>
                                    <td><code><?= h($row['sku']) ?></code></td>
                                    <td><?= h($row['name']) ?></td>
                                    <td class="fw-bold <?= (int)$row['quantity'] < 10 ? 'text-danger' : 'text-success' ?>"><?= h($row['quantity']) ?></td>
                                    <td><?= h(number_format((float)$row['price'])) ?></td>
                                    <td><?= h($row['supplier_name']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$products): ?>
                                <tr><td colspan="5" class="text-center text-muted">Chưa có sản phẩm trong kho.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-primary text-white fw-bold">Tạo Phiếu Nhập / Xuất Kho</div>
                <div class="card-body">
                    <form method="POST" action="/">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="form_action" value="transaction">
                        <div class="mb-3">
                            <label class="form-label" for="product_id">Sản phẩm</label>
                            <select id="product_id" name="product_id" class="form-select" required <?= !$products ? 'disabled' : '' ?>>
                                <?php foreach ($products as $product): ?>
                                    <option value="<?= h($product['id']) ?>" data-quantity="<?= h($product['quantity']) ?>"><?= h($product['sku'] . ' - ' . $product['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="action_type">Loại giao dịch</label>
                            <select id="action_type" name="action_type" class="form-select" required>
                                <option value="IN">Nhập kho (+)</option>
                                <option value="OUT">Xuất kho (-)</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="amount">Số lượng</label>
                            <input id="amount" type="number" name="amount" class="form-control" min="1" max="2147483647" step="1" required>
                            <div id="amount-help" class="form-text">Số lượng xuất không được vượt quá tồn kho.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="note">Ghi chú</label>
                            <input id="note" type="text" name="note" class="form-control" maxlength="255" placeholder="Lý do nhập/xuất...">
                        </div>
                        <button type="submit" class="btn btn-success w-100" <?= !$products ? 'disabled' : '' ?>>Xác nhận giao dịch</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm mb-4">
                <div class="card-header bg-success text-white fw-bold">Thêm sản phẩm</div>
                <div class="card-body">
                    <form method="POST" action="/">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="form_action" value="add_product">
                        <div class="mb-3">
                            <label class="form-label" for="sku">Mã SKU</label>
                            <input id="sku" type="text" name="sku" class="form-control" maxlength="50" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="name">Tên sản phẩm</label>
                            <input id="name" type="text" name="name" class="form-control" maxlength="100" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="initial_quantity">Tồn kho ban đầu</label>
                            <input id="initial_quantity" type="number" name="initial_quantity" class="form-control" min="0" max="2147483647" step="1" value="0" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="price">Đơn giá (VNĐ)</label>
                            <input id="price" type="number" name="price" class="form-control" min="0" max="9999999999.99" step="0.01" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="supplier_id">Nhà cung cấp</label>
                            <select id="supplier_id" name="supplier_id" class="form-select">
                                <option value="">Không chọn</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= h($supplier['id']) ?>"><?= h($supplier['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-success w-100">Thêm sản phẩm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-secondary text-white fw-bold">Lịch sử Nhập / Xuất gần đây</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead><tr><th>Thời gian</th><th>Mã SKU</th><th>Sản phẩm</th><th>Loại</th><th>Số lượng</th><th>Ghi chú</th></tr></thead>
                    <tbody>
                    <?php foreach ($transactions as $transaction): ?>
                        <tr>
                            <td><?= h($transaction['created_at']) ?></td>
                            <td><?= h($transaction['sku']) ?></td>
                            <td><?= h($transaction['product_name']) ?></td>
                            <td><span class="badge <?= $transaction['type'] === 'IN' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= $transaction['type'] === 'IN' ? 'NHẬP' : 'XUẤT' ?></span></td>
                            <td class="fw-bold"><?= h($transaction['amount']) ?></td>
                            <td><?= h($transaction['note']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$transactions): ?>
                        <tr><td colspan="6" class="text-center text-muted">Chưa có giao dịch.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<script>
const actionType = document.getElementById('action_type');
const productSelect = document.getElementById('product_id');
const amountInput = document.getElementById('amount');
const amountHelp = document.getElementById('amount-help');

function updateAmountLimit() {
    const selectedProduct = productSelect.options[productSelect.selectedIndex];
    const stock = selectedProduct ? Number(selectedProduct.dataset.quantity) : 0;
    if (actionType.value === 'OUT') {
        amountInput.max = String(stock);
        amountHelp.textContent = `Tồn kho hiện tại: ${stock}. Số lượng xuất không được vượt quá mức này.`;
    } else {
        amountInput.max = '2147483647';
        amountHelp.textContent = 'Số lượng nhập phải là số nguyên dương.';
    }
}

actionType.addEventListener('change', updateAmountLimit);
productSelect.addEventListener('change', updateAmountLimit);
updateAmountLimit();
</script>
</body>
</html>
