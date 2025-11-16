<?php
session_start();

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php'; 

// Redirect if not logged in
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'customer') {
    header("Location: index.php");
    exit();
}

$customer_id = $_SESSION['id'];
$conn = new mysqli("localhost", "root", "", "hardware_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* ---------------- FETCH USER EMAIL ---------------- */
$user_email = "";
$user_query = $conn->query("SELECT email, fname, lname FROM users WHERE id='$customer_id'");
if ($user_query && $user_query->num_rows > 0) {
    $user = $user_query->fetch_assoc();
    $user_email = $user['email'];
    $user_name = $user['fname'] . ' ' . $user['lname'];
}

/* ---------------- ADD TO CART ---------------- */
if (isset($_POST['add_to_cart'])) {
    $product_id = $_POST['product_id'];
    $check = $conn->query("SELECT * FROM cart WHERE customer_id='$customer_id' AND product_id='$product_id'");
    if ($check->num_rows > 0) {
        $conn->query("UPDATE cart SET quantity = quantity + 1 WHERE customer_id='$customer_id' AND product_id='$product_id'");
    } else {
        $conn->query("INSERT INTO cart (customer_id, product_id, quantity) VALUES ('$customer_id','$product_id','1')");
    }
}

/* ---------------- UPDATE CART ---------------- */
if (isset($_POST['increase_qty']) || isset($_POST['decrease_qty'])) {
    $cart_id = intval($_POST['cart_id']);
    $result = $conn->query("SELECT c.quantity, c.product_id, p.stock FROM cart c JOIN products_ko p ON c.product_id = p.id WHERE c.cart_id='$cart_id' AND c.customer_id='$customer_id'");
    if ($result && $result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $qty = $row['quantity'];
        $stock = $row['stock'];
        if (isset($_POST['increase_qty'])) {
            if ($qty < $stock) {
                $qty++;
            }
        }
        if (isset($_POST['decrease_qty'])) $qty--;
        if ($qty > 0) {
            $conn->query("UPDATE cart SET quantity='$qty' WHERE cart_id='$cart_id'");
        } else {
            $conn->query("DELETE FROM cart WHERE cart_id='$cart_id'");
        }
    }
}

/* ---------------- REMOVE FROM CART ---------------- */
if (isset($_POST['remove_from_cart'])) {
    $cart_id = $_POST['cart_id'];
    $conn->query("DELETE FROM cart WHERE cart_id='$cart_id' AND customer_id='$customer_id'");
}

/* ---------------- CONFIRM PAYMENT & CHECKOUT ---------------- */
if (isset($_POST['confirm_payment'])) {

    // Set timezone to Philippines
    date_default_timezone_set('Asia/Manila');

    $order_type = $_POST['order_type'] ?? '';
    $delivery_address = $_POST['delivery_address'] ?? '';
    $contact_number = $_POST['contact_number'] ?? '';
    $payment_method = $_POST['payment_method'] ?? '';
    $gcash_reference = $_POST['gcash_reference'] ?? '';

    $cart_items = $conn->query("
        SELECT c.product_id, c.quantity, p.price, p.stock, p.name
        FROM cart c
        JOIN products_ko p ON c.product_id = p.id
        WHERE c.customer_id='$customer_id'
    ");

    if ($cart_items->num_rows > 0) {
        $total = 0;

        while ($i = $cart_items->fetch_assoc()) {
            $total += $i['quantity'] * $i['price'];
        }

        $current_datetime = date('Y-m-d H:i:s');
        $conn->query("INSERT INTO transactions (user_id, total_amount, transaction_date, order_type, delivery_address, contact_number, payment_method, gcash_reference) 
                      VALUES ('$customer_id','$total','$current_datetime','$order_type','$delivery_address','$contact_number','$payment_method','$gcash_reference')");
        $transaction_id = $conn->insert_id;

        $cart_items = $conn->query("
            SELECT c.product_id, c.quantity, p.price, p.name
            FROM cart c
            JOIN products_ko p ON c.product_id = p.id
            WHERE c.customer_id='$customer_id'
        ");

        while ($i = $cart_items->fetch_assoc()) {
            $pid = $i['product_id'];
            $conn->query("INSERT INTO transaction_items (transaction_id, product_id, product_name, quantity, price)
                          VALUES ('$transaction_id', '$pid', '{$i['name']}', '{$i['quantity']}', '{$i['price']}')");
            $conn->query("UPDATE products_ko SET stock = stock - {$i['quantity']} WHERE id='$pid'");
        }

        $conn->query("DELETE FROM cart WHERE customer_id='$customer_id'");

        /* ---------------- EMAIL RECEIPT ---------------- */
        // Use the exact transaction datetime
        $order_datetime = date('M d, Y g:i A', strtotime($current_datetime));
        
        // Calculate estimated delivery time based on current time + travel duration
        $estimated_delivery = '';
        if ($order_type === 'delivery') {
            // Estimate delivery time: 30-45 minutes from now for local delivery
            $delivery_start = date('g:i A', strtotime($current_datetime . ' +30 minutes'));
            $delivery_end = date('g:i A', strtotime($current_datetime . ' +45 minutes'));
            $estimated_delivery = "30-45 minutes • $delivery_start - $delivery_end";
        } else {
            // Pickup ready in 15-20 minutes
            $pickup_time = date('g:i A', strtotime($current_datetime . ' +15 minutes'));
            $estimated_delivery = "Ready in 15-20 minutes • by $pickup_time";
        }

        // Build items list first
        $items_result = $conn->query("SELECT product_name, quantity, price FROM transaction_items WHERE transaction_id='$transaction_id'");
        $items_html = "";
        $total = 0;
        while ($row = $items_result->fetch_assoc()) {
            $subtotal = $row['quantity'] * $row['price'];
            $total += $subtotal;
            $items_html .= "
                  <tr style='border-bottom: 1px solid #f3f4f6;'>
                    <td style='padding: 7px 0; font-size: 12px; color: #111827;'>" . htmlspecialchars($row['product_name']) . "</td>
                    <td style='padding: 7px 0; text-align: center; font-size: 12px; color: #6b7280;'>{$row['quantity']}</td>
                    <td style='padding: 7px 0; text-align: right; font-size: 12px; color: #6b7280;'>₱" . number_format($row['price'], 2) . "</td>
                    <td style='padding: 7px 0; text-align: right; font-size: 12px; color: #111827; font-weight: 500;'>₱" . number_format($subtotal, 2) . "</td>
                  </tr>";
        }

        $receipt_body = "
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset='UTF-8'>
          <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        </head>
        <body style='margin: 0; padding: 0; background-color: #f5f5f5; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, \"Helvetica Neue\", Arial, sans-serif;'>
          
          <div style='max-width: 600px; margin: 40px auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);'>
            
            <div style='background: #004080; padding: 14px 20px; text-align: center;'>
              <div style='font-size: 20px; margin-bottom: 3px;'>🛠️</div>
              <h1 style='margin: 0; font-size: 17px; font-weight: 600; color: #ffffff; letter-spacing: -0.5px;'>Abeth Hardware</h1>
              <p style='margin: 3px 0 0; font-size: 10px; color: rgba(255,255,255,0.85);'>B3/L11 Tiongquaio St. Manuyo Dos, Las Pinas City</p>
            </div>

            <div style='padding: 16px; border-bottom: 1px solid #e5e7eb;'>
              <div style='text-align: center; margin-bottom: 12px;'>
                <div style='display: inline-block; background: #10b981; color: white; padding: 5px 14px; border-radius: 20px; font-size: 11px; font-weight: 600;'>
                  ✓ Order Confirmed
                </div>
              </div>
              
              <p style='margin: 0 0 3px; font-size: 13px; color: #111827; font-weight: 500;'>Hello $user_name,</p>
              <p style='margin: 0; font-size: 12px; color: #6b7280; line-height: 1.4;'>Thank you for your order. Here's your receipt.</p>
            </div>

            <div style='padding: 16px; border-bottom: 1px solid #e5e7eb;'>
              <table style='width: 100%; border-collapse: collapse;'>
                <tr>
                  <td style='padding: 0 0 10px 0; font-size: 11px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;'>Order Number</td>
                  <td style='padding: 0 0 10px 0; font-size: 13px; color: #111827; text-align: right; font-weight: 600;'>#$transaction_id</td>
                </tr>
                <tr>
                  <td style='padding: 0 0 10px 0; font-size: 11px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;'>Order Date</td>
                  <td style='padding: 0 0 10px 0; font-size: 13px; color: #111827; text-align: right;'>$order_datetime</td>
                </tr>
                <tr>
                  <td style='padding: 0 0 10px 0; font-size: 11px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px;'>Order Type</td>
                  <td style='padding: 0 0 10px 0; font-size: 13px; color: #111827; text-align: right;'>" . ($order_type === 'delivery' ? '🚚 Delivery' : '🏪 Pickup') . "</td>
                </tr>
                " . ($order_type === 'delivery' ? "
                <tr>
                  <td style='padding: 0; font-size: 12px; color: #9ca3af; text-transform: uppercase; letter-spacing: 0.5px; vertical-align: top;'>Delivery Address</td>
                  <td style='padding: 0; font-size: 14px; color: #111827; text-align: right; line-height: 1.5;'>$delivery_address</td>
                </tr>
                " : "") . "
              </table>
              
              <div style='margin-top: 10px; padding: 8px; background: #fef3c7; border-radius: 6px; border-left: 3px solid #f59e0b;'>
                <p style='margin: 0; font-size: 10px; color: #92400e; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;'>" . ($order_type === 'delivery' ? 'Estimated Delivery' : 'Ready for Pickup') . "</p>
                <p style='margin: 3px 0 0; font-size: 12px; color: #78350f; font-weight: 600;'>$estimated_delivery</p>
                " . ($order_type === 'delivery' ? "<p style='margin: 4px 0 0; font-size: 11px; color: #92400e;'>Contact: $contact_number</p>" : "") . "
              </div>
            </div>

            <div style='padding: 16px;'>
              <h2 style='margin: 0 0 8px; font-size: 14px; color: #111827; font-weight: 600;'>Order Items</h2>
              
              <table style='width: 100%; border-collapse: collapse;'>
                <thead>
                  <tr style='border-bottom: 2px solid #e5e7eb;'>
                    <th style='padding: 7px 0; text-align: left; font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;'>Item</th>
                    <th style='padding: 7px 0; text-align: center; font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;'>Qty</th>
                    <th style='padding: 7px 0; text-align: right; font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;'>Price</th>
                    <th style='padding: 7px 0; text-align: right; font-size: 10px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;'>Total</th>
                  </tr>
                </thead>
                <tbody>$items_html</tbody>
              </table>
              
              <div style='margin-top: 10px; padding-top: 8px; border-top: 2px solid #e5e7eb;'>
                <table style='width: 100%;'>
                  <tr>
                    <td style='padding: 0; font-size: 15px; color: #111827; font-weight: 700;'>Total</td>
                    <td style='padding: 0; text-align: right; font-size: 19px; color: #004080; font-weight: 700;'>₱" . number_format($total, 2) . "</td>
                  </tr>
                </table>
              </div>
            </div>

            <div style='background: #f9fafb; padding: 14px; text-align: center; border-top: 1px solid #e5e7eb;'>
              <p style='margin: 0 0 5px; font-size: 11px; color: #6b7280;'>Questions about your order?</p>
              <p style='margin: 0; font-size: 11px; color: #111827;'>
                📞 +63 966-866-9728 / +63 977-386-8066<br>
                📧 abethhardware@gmail.com
              </p>
              
              <div style='margin-top: 10px; padding-top: 10px; border-top: 1px solid #e5e7eb;'>
                <p style='margin: 0; font-size: 9px; color: #9ca3af;'>
                  © " . date('Y') . " Abeth Hardware. All rights reserved.
                </p>
              </div>
            </div>

          </div>
        </body>
        </html>";

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
                $mail->Subject = 'Your Receipt from Abeth Hardware';
                $mail->Body = $receipt_body;
                $mail->send();
            } catch (Exception $e) {
                error_log("Mailer Error: " . $mail->ErrorInfo);
            }
        }
    }
}

/* ---------------- FETCH DATA ---------------- */

$selected_category = isset($_GET['category']) ? $conn->real_escape_string($_GET['category']) : '';
if (!empty($selected_category)) {
    $products = $conn->query("SELECT * FROM products_ko WHERE archive = 0 AND category = '$selected_category' ORDER BY id DESC");
} else {
    $products = $conn->query("SELECT * FROM products_ko WHERE archive = 0 ORDER BY id DESC");
}
$categories = $conn->query("SELECT DISTINCT category FROM products_ko WHERE category IS NOT NULL AND category != ''");

$cart = $conn->query("SELECT c.cart_id, c.product_id, p.name, p.price, p.category, p.stock, c.quantity FROM cart c JOIN products_ko p ON c.product_id = p.id WHERE c.customer_id='$customer_id'");
$transactions_result = $conn->query("
    SELECT t.transaction_id, t.transaction_date, t.total_amount, ti.product_name, ti.quantity, ti.price
    FROM transactions t
    JOIN transaction_items ti ON t.transaction_id = ti.transaction_id
    WHERE t.user_id='$customer_id'
    ORDER BY t.transaction_date DESC
");
$transactions = [];
if ($transactions_result) {
    while ($row = $transactions_result->fetch_assoc()) {
        $id = $row['transaction_id'];
        $transactions[$id]['date'] = $row['transaction_date'];
        $transactions[$id]['total'] = $row['total_amount'];
        $transactions[$id]['items'][] = [
            'name' => $row['product_name'],
            'quantity' => $row['quantity'],
            'price' => $row['price']
        ];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Abeth Hardware - Customer</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<link rel="stylesheet" href="products.css">
<style>

@media (max-width: 768px) {
  .right-panel {
    width: 90%;
    right: -100%;
  }
  .right-panel.active {
    right: 0;
  }
  .cart-float-btn {
    display: flex !important;
  }
}
</style>
</head>
<body>

<div class="header">
  <h3>WELCOME TO ABETH HARDWARE!</h3>
  <div class="top-right">
    <button class="home-btn" onclick="window.location.href='index.php'">Home</button>
    <button class="history-btn" onclick="toggleHistory()">History</button>
    <form action="logout.php" method="POST">
      <button type="submit" class="logout-btn">Logout</button>
    </form>
  </div>
</div>

<div class="layout">
  <div class="left-panel">
    <h2>Available Products</h2>
    <form method="GET" style="margin-bottom: 20px;">
      <label for="category"><strong>Filter by Category:</strong></label>
      <select name="category" id="category" onchange="this.form.submit()">
        <option value="">All</option>
        <?php if ($categories && $categories->num_rows > 0): ?>
          <?php while ($cat = $categories->fetch_assoc()): ?>
            <option value="<?= htmlspecialchars($cat['category']) ?>" 
              <?= ($selected_category === $cat['category']) ? 'selected' : '' ?>>
              <?= htmlspecialchars($cat['category']) ?>
            </option>
          <?php endwhile; ?>
        <?php endif; ?>
      </select>
    </form>

    <div class="product-grid">
      <?php if ($products && $products->num_rows > 0): ?>
        <?php while ($p = $products->fetch_assoc()): ?>
          <div class="product-card">
            <?php if (!empty($p['image'])): ?>
              <img src="<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
            <?php else: ?>
              <div class="no-image">No Image</div>
            <?php endif; ?>
            <div class="product-footer">
              <h4><?= htmlspecialchars($p['name']) ?></h4>
              <?= htmlspecialchars($p['category']) ?>
              <p><strong>₱<?= number_format($p['price'], 2) ?></strong></p>
              <form method="POST">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <?php if ($p['stock'] > 0): ?>
                  <button type="submit" class="add-cart-btn" name="add_to_cart" style="font-size: 0.8rem; padding: 8px 14px;">Add</button>
                <?php else: ?>
                  <button type="button" class="add-cart-btn" disabled style="background-color: #999; cursor: not-allowed; font-size: 0.75rem; padding: 8px 12px;">Out of Stock</button>
                <?php endif; ?>
              </form>
            </div>
          </div>
        <?php endwhile; ?>
      <?php else: ?>
        <p>No products available.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="right-panel">
    <div class="cart-header">Your Cart</div>
    <div class="cart-content">
      <?php if ($cart && $cart->num_rows > 0): ?>
        <div class="cart-items-container">
          <?php 
          $total = 0;
          while ($item = $cart->fetch_assoc()): 
              $subtotal = $item['price'] * $item['quantity'];
              $total += $subtotal;
          ?>
            <div class="cart-row">
              <div class="cart-item-info">
                <div class="cart-item-name"><?= htmlspecialchars($item['name']) ?></div>
                <?php if (!empty($item['category'])): ?>
                  <div class="cart-item-category"><?= htmlspecialchars($item['category']) ?></div>
                <?php endif; ?>
                <div class="cart-item-price">₱<?= number_format($item['price'], 2) ?></div>
              </div>
              <div class="cart-controls">
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="cart_id" value="<?= $item['cart_id'] ?>">
                  <button class="qty-btn" name="decrease_qty">-</button>
                </form>
                <span class="qty-display"><?= $item['quantity'] ?></span>
                <form method="POST" style="display:inline;">
                  <input type="hidden" name="cart_id" value="<?= $item['cart_id'] ?>">
                  <button class="qty-btn" name="increase_qty" <?= ($item['quantity'] >= $item['stock']) ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : '' ?>>+</button>
                </form>
              </div>
            </div>
          <?php endwhile; ?>
        </div>

        <div class="cart-footer">
          <div class="total-section">
            <p><strong>Subtotal:</strong> ₱<?= number_format($total, 2) ?></p>
          </div>

          <form method="POST" id="checkoutForm">
            <div class="order-type-section">
              <label><strong>Order Type:</strong></label>
              <select name="order_type" id="orderType" required>
                <option value="" disabled selected>-- Choose Order Type --</option>
                <option value="pickup">🏪 Pickup</option>
                <option value="delivery">🚚 Delivery</option>
              </select>
              <div id="deliveryFields" class="delivery-fields">
                <input type="text" name="delivery_address" placeholder="📍 Enter Delivery Address" required>
                <input type="text" name="contact_number" placeholder="📞 Enter Contact Number" required>
              </div>
            </div>
            <button type="button" class="checkout-btn" onclick="openPaymentModal()">🛒 Checkout Now</button>
          </form>
        </div>
      <?php else: ?>
        <div class="cart-items-container">
          <div class="empty-cart">
            <div class="empty-cart-icon">🛒</div>
            <div class="empty-cart-text">Your cart is empty</div>
            <div class="empty-cart-subtext">Add some products to get started!</div>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<button id="cartToggleBtn" class="cart-float-btn">🛒</button>

<!-- Payment Method Modal -->
<div id="paymentModal" class="payment-modal" style="display: none;">
  <div class="payment-modal-content">
    <button class="payment-close-btn" onclick="closePaymentModal()">×</button>
    <h2>Select Payment Method</h2>
    <p style="text-align: center; color: #666; margin-bottom: 30px;">
      Total Amount: <strong style="font-size: 24px; color: #004080;">₱<?= number_format($total ?? 0, 2) ?></strong>
    </p>
    
    <div class="payment-options">
      <div class="payment-option" onclick="selectPayment('gcash')">
        <div class="payment-icon">💳</div>
        <h3>GCash</h3>
        <p>Pay via GCash QR Code</p>
      </div>
    </div>
  </div>
</div>

<!-- GCash QR Modal -->
<div id="gcashModal" class="payment-modal" style="display: none;">
  <div class="payment-modal-content">
    <button class="payment-close-btn" onclick="closeGcashModal()">×</button>
    <h2>GCash Payment</h2>
    
    <div class="gcash-container">
      <p style="text-align: center; color: #666; margin-bottom: 20px;">
        Scan the QR code below to pay<br>
        <strong style="font-size: 24px; color: #004080;">₱<?= number_format($total ?? 0, 2) ?></strong>
      </p>
      
      <div class="qr-code-container" style="text-align: center; margin: 30px 0;">
        <img src="uploads/gcash-qr.png" alt="GCash QR Code" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';" style="max-width: 300px; width: 100%; height: auto; border: 2px solid #ddd; border-radius: 8px; padding: 10px; background: white;">
        <div style="display: none; padding: 40px; background: #f0f0f0; border-radius: 8px; color: #666;">
          <p style="margin: 0; font-size: 14px;">📱 GCash QR Code</p>
          <p style="margin: 10px 0 0; font-size: 12px;">Please upload gcash-qr.png to uploads folder</p>
        </div>
      </div>
      
      <p style="text-align: center; color: #666; margin-top: 20px; font-size: 14px;">
        After payment, enter your GCash reference number below
      </p>
      
      <div style="margin: 20px 0;">
        <input type="text" id="gcashReference" placeholder="Enter GCash Reference Number (e.g., 1234567890)" style="width: 100%; padding: 12px 15px; border: 2px solid rgba(0, 64, 128, 0.2); border-radius: 25px; font-size: 1rem; text-align: center; transition: all 0.3s ease;" required>
        <p style="text-align: center; font-size: 12px; color: #999; margin-top: 8px;">13-digit reference number from your GCash receipt</p>
      </div>
      
      <button type="button" class="confirm-payment-btn" onclick="confirmGcashPayment()">✓ Confirm Payment</button>
      <button type="button" class="back-payment-btn" onclick="backToPaymentOptions()">← Back</button>
    </div>
  </div>
</div>

<!-- Hidden form for payment confirmation -->
<form method="POST" id="paymentConfirmForm" style="display: none;">
  <input type="hidden" name="confirm_payment" value="1">
  <input type="hidden" name="order_type" id="hidden_order_type">
  <input type="hidden" name="delivery_address" id="hidden_delivery_address">
  <input type="hidden" name="contact_number" id="hidden_contact_number">
  <input type="hidden" name="payment_method" id="hidden_payment_method">
  <input type="hidden" name="gcash_reference" id="hidden_gcash_reference">
</form>

<div id="historyPanel" class="history-container">
  <button class="close-btn" onclick="toggleHistory()">×</button>
  <h3>Purchase History</h3>
  <?php if (!empty($transactions)): ?>
    <?php foreach ($transactions as $id => $t): ?>
      <div class="history-item">
        <p><strong>Date:</strong> <?= htmlspecialchars($t['date']) ?></p>
        <ul>
          <?php foreach ($t['items'] as $item): ?>
            <li><?= htmlspecialchars($item['name']) ?> (x<?= $item['quantity'] ?>) - ₱<?= number_format($item['price'], 2) ?></li>
          <?php endforeach; ?>
        </ul>
        <p><strong>Total:</strong> ₱<?= number_format($t['total'], 2) ?></p>
        <hr>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p>No transaction history yet.</p>
  <?php endif; ?>
</div>

<script>
function toggleHistory() {
  document.getElementById('historyPanel').classList.toggle('active');
}

// Delivery fields toggle
const orderType = document.getElementById('orderType');
const deliveryFields = document.getElementById('deliveryFields');

orderType?.addEventListener('change', () => {
  if (orderType.value === 'delivery') {
    deliveryFields.style.display = 'block';
    deliveryFields.querySelectorAll('input').forEach(input => {
      input.setAttribute('required', 'required');
    });
  } else {
    deliveryFields.style.display = 'none';
    deliveryFields.querySelectorAll('input').forEach(input => {
      input.removeAttribute('required');
      input.value = '';
    });
  }
});

// Cart toggle
const rightPanel = document.querySelector('.right-panel');
const cartToggleBtn = document.getElementById('cartToggleBtn');

cartToggleBtn.addEventListener('click', () => {
  rightPanel.classList.toggle('active');
});

// Close cart when clicking outside
document.addEventListener('click', (e) => {
  if (!rightPanel.contains(e.target) && !cartToggleBtn.contains(e.target)) {
    rightPanel.classList.remove('active');
  }
});

// Payment Modal Functions
function openPaymentModal() {
  const orderType = document.getElementById('orderType');
  const deliveryAddress = document.querySelector('input[name="delivery_address"]');
  const contactNumber = document.querySelector('input[name="contact_number"]');
  
  // Validate order type
  if (!orderType.value) {
    alert('Please select an order type (Pickup or Delivery)');
    return;
  }
  
  // Validate delivery fields if delivery is selected
  if (orderType.value === 'delivery') {
    if (!deliveryAddress.value || !contactNumber.value) {
      alert('Please enter delivery address and contact number');
      return;
    }
  }
  
  // Store values in hidden form
  document.getElementById('hidden_order_type').value = orderType.value;
  document.getElementById('hidden_delivery_address').value = deliveryAddress.value;
  document.getElementById('hidden_contact_number').value = contactNumber.value;
  
  // Show payment modal
  document.getElementById('paymentModal').style.display = 'flex';
}

function closePaymentModal() {
  document.getElementById('paymentModal').style.display = 'none';
}

function selectPayment(method) {
  document.getElementById('hidden_payment_method').value = method;
  
  if (method === 'gcash') {
    // Show GCash QR modal
    document.getElementById('paymentModal').style.display = 'none';
    document.getElementById('gcashModal').style.display = 'flex';
  }
}

function closeGcashModal() {
  document.getElementById('gcashModal').style.display = 'none';
  document.getElementById('paymentModal').style.display = 'none';
}

function confirmGcashPayment() {
  const referenceNumber = document.getElementById('gcashReference').value.trim();
  
  // Validate reference number
  if (!referenceNumber) {
    alert('Please enter your GCash reference number');
    return;
  }
  
  if (referenceNumber.length < 10) {
    alert('Please enter a valid GCash reference number (at least 10 digits)');
    return;
  }
  
  // Store reference number in hidden form
  document.getElementById('hidden_gcash_reference').value = referenceNumber;
  
  // Submit the hidden form to process the order
  document.getElementById('paymentConfirmForm').submit();
}

function backToPaymentOptions() {
  document.getElementById('gcashModal').style.display = 'none';
  document.getElementById('paymentModal').style.display = 'flex';
}

// Close modal when clicking outside
window.onclick = function(event) {
  const paymentModal = document.getElementById('paymentModal');
  const gcashModal = document.getElementById('gcashModal');
  if (event.target === paymentModal) {
    closePaymentModal();
  }
  if (event.target === gcashModal) {
    closeGcashModal();
  }
}
</script>

</body>
</html>
