<?php
session_start();
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "hardware_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// PHPMailer for admin-triggered notifications
require 'vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/* ---------------- UPDATE TRANSACTION STATUS (AJAX) ---------------- */
if (isset($_POST['ajax_update'])) {
    $transaction_id = intval($_POST['transaction_id']);
    $new_status = $conn->real_escape_string($_POST['status']);
    $conn->query("UPDATE transactions SET status='$new_status' WHERE transaction_id=$transaction_id");

        // If status became Success, notify the customer by email
    if (strtolower($new_status) === 'success') {
        // fetch the transaction's user_id, transaction_date and order_type
        $txn_res = $conn->query("SELECT user_id, transaction_date, order_type, delivery_address FROM transactions WHERE transaction_id=$transaction_id LIMIT 1");
        if ($txn_res && $txn_res->num_rows > 0) {
            $txn = $txn_res->fetch_assoc();
            $user_id = intval($txn['user_id']);
            $transaction_date = $txn['transaction_date'];
            $order_type = strtolower(trim($txn['order_type'] ?? ''));
            $delivery_address = $txn['delivery_address'] ?? '';

            // fetch user email and name
            $user_res = $conn->query("SELECT email, fname, lname FROM users WHERE id=$user_id LIMIT 1");
            if ($user_res && $user_res->num_rows > 0) {
                $user = $user_res->fetch_assoc();
                $user_email = $user['email'];
                $user_name = trim(($user['fname'] ?? '') . ' ' . ($user['lname'] ?? '')) ?: 'Customer';

                if (!empty($user_email)) {
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host = 'smtp.gmail.com';
                        $mail->SMTPAuth = true;
                        $mail->Username = 'rogeliomonfielsr@gmail.com';
                        $mail->Password = 'kioa rdpq tews rcdx';
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port = 587;
                        $mail->setFrom('rogeliomonfielsr@gmail.com', 'Abeth Hardware');
                        $mail->addAddress($user_email, $user_name);
                        $mail->isHTML(true);

                        $readable_date = date('M d, Y g:i A', strtotime($transaction_date));

                        if ($order_type === 'pickup') {
                            // Send a pickup-ready notification
                            $mail->Subject = "Your order #$transaction_id is ready for pickup";
                            $pickup_instructions = "<p>Your order <strong>#" . $transaction_id . "</strong> placed on " . $readable_date . " is now <strong>ready for pickup</strong> at our store.</p>" .
                                                "<p>Please bring a copy of this email or your Order # when you come to pick up your items.\nPickup Location: <strong>Abeth Hardware, B3/L11 Tiongquaio St. Manuyo Dos, Las Pinas City</strong>.</p>" .
                                                "<p>Pickup Hours: <strong>Mon–Sat 9:00 AM – 6:00 PM</strong>. For any questions, call 📞 +63 966-866-9728.</p>";
                            $mail->Body = "<p>Hello " . htmlspecialchars($user_name) . ",</p>" . $pickup_instructions;
                        } else {
                            // Default delivered/completed notification for non-pickup orders
                            $mail->Subject = "Your order #$transaction_id is complete";
                            $mail_body = "<p>Hello " . htmlspecialchars($user_name) . ",</p>" .
                                         "<p>Your order <strong>#" . $transaction_id . "</strong> placed on " . $readable_date . " has been marked as <strong>Delivered / Completed</strong>. Thank you for shopping with Abeth Hardware.</p>" .
                                         "<p>If you have questions about your order, reply to this email or contact us at 📞 +63 966-866-9728.</p>";
                            $mail->Body = $mail_body;
                        }

                        $mail->send();
                    } catch (Exception $e) {
                        error_log('Delivery/pickup notification mailer error: ' . $mail->ErrorInfo);
                    }
                }
            }
        }
    }

    echo json_encode(["success" => true]);
    exit;
}

/* ---------------- FETCH TRANSACTIONS WITH PAGINATION ---------------- */
$limit = 10;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$offset = ($current_page - 1) * $limit;

$where_clause = "WHERE (source = 'online')"; // show only non-POS (online) transactions
$total_result = $conn->query("SELECT COUNT(*) AS total FROM transactions " . $where_clause);
$total_records = (int) $total_result->fetch_assoc()['total'];
$total_pages = ($total_records > 0) ? ceil($total_records / $limit) : 1;

$query = "
    SELECT 
        t.transaction_id,
        t.user_id,
        u.fname AS first_name,
        u.lname AS last_name,
        t.total_amount,
        t.transaction_date,
        t.order_type,
        t.delivery_address,
        t.contact_number,
        t.payment_method,
        t.gcash_reference,
        t.status
    FROM transactions t
    LEFT JOIN users u ON t.user_id = u.id
    " . $where_clause . "
    ORDER BY t.transaction_date DESC
    LIMIT $limit OFFSET $offset
";
$transactions = $conn->query($query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Orders Management - Abeth Hardware</title>
    <link rel="stylesheet" href="orders.css">
</head>
<body>

<div class="orders-wrapper">
    <div class="top-bar">
        <a href="admin.php" class="back-btn">← Back to Dashboard</a>
        <h2>Orders Management</h2>
    </div>

    <table class="orders-table">
        <tr>
            <th>ID</th>
            <th>Customer</th>
            <th>Method</th>
            <th>Payment</th>
            <th>GCash Ref</th>
            <th>Address</th>
            <th>Contact</th>
            <th>Total</th>
            <th>Date</th>
            <th>Status</th>
            <th>Action</th>
        </tr>

        <?php if ($transactions && $transactions->num_rows > 0): ?>
            <?php while ($row = $transactions->fetch_assoc()): ?>
                <?php
                    $customer_name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
                    if ($customer_name === '') $customer_name = 'Guest';
                    $isSuccess = strtolower($row['status']) === 'success';
                ?>
                <tr>
                    <td><?= $row['transaction_id'] ?></td>
                    <td><?= htmlspecialchars($customer_name) ?></td>
                    <td><?= htmlspecialchars(ucfirst($row['order_type'])) ?></td>
                    <td>
                        <span class="payment-badge <?= strtolower($row['payment_method'] ?? 'n/a') ?>">
                            <?= htmlspecialchars(ucfirst($row['payment_method'] ?? 'N/A')) ?>
                        </span>
                    </td>
                    <td>
                        <?php if (!empty($row['gcash_reference'])): ?>
                            <span class="gcash-ref" title="Click to copy" onclick="copyToClipboard('<?= htmlspecialchars($row['gcash_reference']) ?>')">
                                <?= htmlspecialchars($row['gcash_reference']) ?>
                                <span class="copy-icon">📋</span>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($row['delivery_address'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['contact_number'] ?? 'N/A') ?></td>
                    <td>₱<?= number_format($row['total_amount'], 2) ?></td>
                    <td><?= htmlspecialchars($row['transaction_date']) ?></td>
                    <td>
                        <span class="status-badge <?= strtolower($row['status']) ?>">
                            <?= htmlspecialchars($row['status']) ?>
                        </span>
                    </td>
                    <td>
                        <label class="switch">
                            <input type="checkbox" class="status-toggle" 
                                   data-id="<?= $row['transaction_id'] ?>"
                                   <?= $isSuccess ? 'checked' : '' ?>>
                            <span class="slider"></span>
                        </label>
                    </td>
                </tr>
            <?php endwhile; ?>
        <?php else: ?>
            <tr><td colspan="11">No transactions found.</td></tr>
        <?php endif; ?>
    </table>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination">
      <?php if ($current_page > 1): ?>
        <a href="?page=<?= $current_page - 1 ?>" class="page-btn">← Previous</a>
      <?php endif; ?>

      <span class="page-info">
        Page <?= $current_page ?> of <?= $total_pages ?> (Total: <?= $total_records ?> records)
      </span>

      <?php if ($current_page < $total_pages): ?>
        <a href="?page=<?= $current_page + 1 ?>" class="page-btn">Next →</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<script>
// Copy GCash reference to clipboard
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        // Show temporary success message
        const tooltip = document.createElement('div');
        tooltip.textContent = 'Copied!';
        tooltip.style.cssText = 'position: fixed; top: 20px; right: 20px; background: #27ae60; color: white; padding: 10px 20px; border-radius: 5px; z-index: 9999; font-weight: 600;';
        document.body.appendChild(tooltip);
        setTimeout(() => tooltip.remove(), 2000);
    });
}

document.querySelectorAll('.status-toggle').forEach(toggle => {
    toggle.addEventListener('change', function() {
        const transactionId = this.dataset.id;
        const newStatus = this.checked ? 'Success' : 'Pending';
        
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({
                ajax_update: true,
                transaction_id: transactionId,
                status: newStatus
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const badge = this.closest('tr').querySelector('.status-badge');
                badge.textContent = newStatus;
                badge.className = 'status-badge ' + newStatus.toLowerCase();
            }
        });
    });
});
</script>

</body>
</html>
