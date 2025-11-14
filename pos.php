<?php
session_start();

if (!isset($_SESSION['username']) || !in_array($_SESSION['role'], ['staff', 'admin'])) {
    header("Location: index.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "hardware_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$selected_category = isset($_GET['category']) ? $conn->real_escape_string($_GET['category']) : '';
if (!empty($selected_category)) {
    $products = $conn->query("SELECT * FROM products_ko WHERE archive = 0 AND category = '$selected_category' ORDER BY id DESC");
} else {
    $products = $conn->query("SELECT * FROM products_ko WHERE archive = 0 ORDER BY id DESC");
}
$categories = $conn->query("SELECT DISTINCT category FROM products_ko WHERE category IS NOT NULL AND category != ''");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Point of Sale (POS) - Abeth Hardware</title>
<link rel="stylesheet" href="pos.css">
</head>
<body>

<nav>
  <div class="logo">Abeth Hardware POS</div>
  <div class="nav-buttons">
    <a href="admin.php"><button class="back-btn">⬅ Back to Dashboard</button></a>
    <a href="onsite_transaction.php"><button class="onsite-btn">Onsite Transactions</button></a>
    <button id="toggleCartBtn" class="floating-cart-btn">🛒</button>
  </div>
</nav>

<!-- ✅ Enhanced Category Filter -->
<div class="filter-container">
  <form method="GET">
    <label for="category">Filter by Category:</label>
    <select name="category" id="category" onchange="this.form.submit()">
      <option value="">All Categories</option>
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
</div>

<div class="product-grid">
  <?php if ($products && $products->num_rows > 0): ?>
    <?php while ($p = $products->fetch_assoc()): ?>
      <!-- ✅ Added data attributes -->
      <div class="product-card"
           data-id="<?= $p['id'] ?>"
           data-name="<?= htmlspecialchars($p['name']) ?>"
           data-category="<?= htmlspecialchars($p['category']) ?>"
           data-price="<?= $p['price'] ?>">
        <?php if (!empty($p['image'])): ?>
          <img src="<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name']) ?>">
        <?php else: ?>
          <div class="no-image">No Image</div>
        <?php endif; ?>

        <div class="product-footer">
          <h4><?= htmlspecialchars($p['name']) ?></h4>
          <?= htmlspecialchars($p['category']) ?>
          <p><strong>₱<?= number_format($p['price'], 2) ?></strong></p>

          <?php if ($p['stock'] > 0): ?>
            <button type="button" class="add-cart-btn">Add</button>
          <?php else: ?>
            <button type="button" class="add-cart-btn" disabled style="background-color: #999; cursor: not-allowed;">Out of Stock</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endwhile; ?>
  <?php else: ?>
    <p>No products available.</p>
  <?php endif; ?>
</div>

<!-- 🛒 Enhanced Cart Sidebar -->
<div class="cart-container">
  <div class="cart-header">
    <h3>🛒 Transaction</h3>
    <button class="close-cart-btn" onclick="toggleMobileCart()" id="closeCartBtn">✕</button>
  </div>
  <table class="cart-table" id="cartTable">
    <thead>
      <tr>
        <th>Item</th>
        <th>Qty</th>
        <th>Price</th>
      </tr>
    </thead>
    <tbody id="cartBody"></tbody>
  </table>

  <div class="total" id="cartTotal">Total: ₱0.00</div>
  
  <!-- Enhanced Checkout Section -->
  <div class="checkout-section" id="checkoutSection" style="display: none;">
    <div class="payment-mode">
      <h4>Payment Mode</h4>
      <div class="payment-options">
        <label class="payment-option">
          <input type="radio" name="paymentMode" value="cash" checked onchange="togglePaymentMethod()">
          <span>💵 Cash</span>
        </label>
        <label class="payment-option">
          <input type="radio" name="paymentMode" value="gcash" onchange="togglePaymentMethod()">
          <span>📱 GCash</span>
        </label>
      </div>
    </div>
    
    <div class="cash-payment" id="cashPayment">
      <div class="input-group">
        <label for="cashAmount">Cash Amount:</label>
        <input type="number" id="cashAmount" placeholder="Enter amount" step="0.01" oninput="calculateChange()">
      </div>
      <div class="change-display" id="changeDisplay">
        <strong>Change: ₱0.00</strong>
      </div>
    </div>
    
    <div class="gcash-payment" id="gcashPayment" style="display: none;">
      <div class="input-group">
        <label for="gcashRef">GCash Reference Number:</label>
        <input type="text" id="gcashRef" placeholder="Enter reference number">
      </div>
    </div>
    
    <div class="sales-breakdown">
      <h4>Sales Breakdown</h4>
      <div class="breakdown-item">
        <span>Subtotal:</span>
        <span id="subtotalAmount">₱0.00</span>
      </div>
      <div class="breakdown-item">
        <span>Tax (12%):</span>
        <span id="taxAmount">₱0.00</span>
      </div>
      <div class="breakdown-item total-line">
        <span><strong>Total:</strong></span>
        <span id="finalTotal"><strong>₱0.00</strong></span>
      </div>
    </div>
    
    <button type="button" class="confirm-btn" onclick="confirmTransaction()">Confirm Transaction</button>
    <button type="button" class="cancel-btn" onclick="cancelCheckout()">Cancel</button>
  </div>
  
<button type="button" class="checkout-btn" onclick="initiateCheckout()">Checkout</button>
</div>

<script>
let cart = {};

function updateCartDisplay() {
  const tbody = document.getElementById('cartBody');
  tbody.innerHTML = '';
  let total = 0;

  for (const id in cart) {
    const item = cart[id];
    total += item.price * item.qty;
    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>
        ${item.name}<br>
        <small style="color:gray;">${item.category}</small>
      </td>
      <td>
        <div class="qty-controls">
<button onclick="changeQty('${id}', -1)">-</button>
<span>${item.qty}</span>
<button onclick="changeQty('${id}', 1)">+</button>
        </div>
      </td>
      <td>₱${(item.price * item.qty).toFixed(2)}</td>
    `;
    tbody.appendChild(tr);
  }

  document.getElementById('cartTotal').textContent = `Total: ₱${total.toFixed(2)}`;
}

function changeQty(id, delta) {
  if (!cart[id]) return;
  cart[id].qty += delta;
  if (cart[id].qty <= 0) delete cart[id];
  updateCartDisplay();
}

document.querySelectorAll('.add-cart-btn').forEach(btn => {
  btn.addEventListener('click', e => {
    const card = e.target.closest('.product-card');
    const id = card.dataset.id;
    const name = card.dataset.name;
    const category = card.dataset.category;
    const price = parseFloat(card.dataset.price);

    if (!cart[id]) {
      cart[id] = { name, category, price, qty: 1 };
    } else {
      cart[id].qty++;
    }

    updateCartDisplay();
  });
});

let currentTotal = 0;

function initiateCheckout() {
  if (Object.keys(cart).length === 0) {
    alert('No items in cart!');
    return;
  }

  // Calculate totals
  let subtotal = 0;
  for (const id in cart) {
    subtotal += cart[id].price * cart[id].qty;
  }
  
  const tax = subtotal * 0.12; // 12% tax
  currentTotal = subtotal + tax;
  
  // Update breakdown display
  document.getElementById('subtotalAmount').textContent = `₱${subtotal.toFixed(2)}`;
  document.getElementById('taxAmount').textContent = `₱${tax.toFixed(2)}`;
  document.getElementById('finalTotal').textContent = `₱${currentTotal.toFixed(2)}`;
  
  // Show checkout section
  document.getElementById('checkoutSection').style.display = 'block';
  document.querySelector('.checkout-btn').style.display = 'none';
}

function togglePaymentMethod() {
  const paymentMode = document.querySelector('input[name="paymentMode"]:checked').value;
  const cashPayment = document.getElementById('cashPayment');
  const gcashPayment = document.getElementById('gcashPayment');
  
  if (paymentMode === 'cash') {
    cashPayment.style.display = 'block';
    gcashPayment.style.display = 'none';
  } else {
    cashPayment.style.display = 'none';
    gcashPayment.style.display = 'block';
  }
}

function calculateChange() {
  const cashAmount = parseFloat(document.getElementById('cashAmount').value) || 0;
  const change = Math.max(0, cashAmount - currentTotal);
  
  const changeDisplay = document.getElementById('changeDisplay');
  changeDisplay.innerHTML = `<strong>Change: ₱${change.toFixed(2)}</strong>`;
  
  if (cashAmount < currentTotal && cashAmount > 0) {
    changeDisplay.style.background = 'linear-gradient(45deg, #e74c3c, #c0392b)';
    changeDisplay.innerHTML = `<strong>Insufficient Amount: ₱${(currentTotal - cashAmount).toFixed(2)} more needed</strong>`;
  } else {
    changeDisplay.style.background = 'linear-gradient(45deg, var(--success-green), #2ecc71)';
  }
}

function confirmTransaction() {
  const paymentMode = document.querySelector('input[name="paymentMode"]:checked').value;
  
  // Validate payment
  if (paymentMode === 'cash') {
    const cashAmount = parseFloat(document.getElementById('cashAmount').value);
    if (!cashAmount || cashAmount < currentTotal) {
      alert('Please enter a valid cash amount that covers the total!');
      return;
    }
  } else if (paymentMode === 'gcash') {
    const gcashRef = document.getElementById('gcashRef').value.trim();
    if (!gcashRef) {
      alert('Please enter the GCash reference number!');
      return;
    }
  }

  // Prepare transaction data
  const items = [];
  let subtotal = 0;

  for (const id in cart) {
    subtotal += cart[id].price * cart[id].qty;
    items.push({
      id: id,
      name: cart[id].name,
      qty: cart[id].qty,
      price: cart[id].price
    });
  }

  const transactionData = {
    subtotal: subtotal,
    tax: subtotal * 0.12,
    total: currentTotal,
    items: items,
    payment_mode: paymentMode,
    cash_amount: paymentMode === 'cash' ? parseFloat(document.getElementById('cashAmount').value) : null,
    gcash_ref: paymentMode === 'gcash' ? document.getElementById('gcashRef').value : null,
    change: paymentMode === 'cash' ? Math.max(0, parseFloat(document.getElementById('cashAmount').value) - currentTotal) : 0
  };

  document.getElementById('transactionData').value = JSON.stringify(transactionData);
  document.getElementById('checkoutForm').submit();
}

function cancelCheckout() {
  document.getElementById('checkoutSection').style.display = 'none';
  document.querySelector('.checkout-btn').style.display = 'block';
  
  // Reset form
  document.getElementById('cashAmount').value = '';
  document.getElementById('gcashRef').value = '';
  document.getElementById('changeDisplay').innerHTML = '<strong>Change: ₱0.00</strong>';
  document.querySelector('input[value="cash"]').checked = true;
  togglePaymentMethod();
}
</script>


<!-- Mobile Floating Cart Button -->
<button class="floating-cart-btn" onclick="toggleMobileCart()" id="floatingCartBtn">
  🛒 <span id="cartCount">0</span>
</button>

<form id="checkoutForm" action="onsite_transaction.php" method="POST" style="display:none;">
  <input type="hidden" name="transaction_data" id="transactionData">
</form>

<script>
// Mobile cart toggle functionality
function toggleMobileCart() {
  const cartContainer = document.querySelector('.cart-container');
  cartContainer.classList.toggle('active');
}

// Update cart count for mobile button
function updateCartCount() {
  const itemCount = Object.keys(cart).reduce((sum, id) => sum + cart[id].qty, 0);
  document.getElementById('cartCount').textContent = itemCount;
  
  // Show/hide floating button based on cart contents
  const floatingBtn = document.getElementById('floatingCartBtn');
  if (itemCount > 0) {
    floatingBtn.style.display = 'flex';
  } else {
    floatingBtn.style.display = 'none';
  }
}

// Override updateCartDisplay to include mobile updates
const originalUpdateCartDisplay = updateCartDisplay;
updateCartDisplay = function() {
  originalUpdateCartDisplay();
  updateCartCount();
};

// Initialize cart count
updateCartCount();
</script>

</body>
</html>
