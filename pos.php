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
           data-price="<?= $p['price'] ?>"
           data-stock="<?= $p['stock'] ?>">
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
      <div class="breakdown-item total-line">
        <span><strong>Total:</strong></span>
        <span id="finalTotal"><strong>₱0.00</strong></span>
      </div>
    </div>
    
    <button type="button" class="confirm-btn" onclick="confirmTransaction()" id="confirmBtn">Confirm Transaction</button>
    <button type="button" class="cancel-btn" onclick="cancelCheckout()">Cancel</button>
    <div class="transaction-status" id="transactionStatus" style="display: none;"></div>
  </div>
  
<button type="button" class="checkout-btn" onclick="initiateCheckout()">Checkout</button>
</div>

<!-- Receipt Modal -->
<div class="receipt-container" id="receiptContainer">
  <div class="receipt-modal">
    <div class="receipt" id="receiptContent">
      <!-- Receipt content will be generated here -->
    </div>
    <div class="receipt-buttons">
      <button class="receipt-btn print-btn" onclick="printReceipt()">🖨️ Print Receipt</button>
      <button class="receipt-btn close-receipt-btn" onclick="closeReceipt()">✕ Close</button>
    </div>
  </div>
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
<button onclick="changeQty('${id}', 1)" ${item.qty >= item.stock ? 'disabled style="opacity: 0.5; cursor: not-allowed;"' : ''}>+</button>
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
    const stock = parseInt(card.dataset.stock);

    if (!cart[id]) {
      cart[id] = { name, category, price, stock, qty: 1 };
    } else {
      if (cart[id].qty < stock) {
        cart[id].qty++;
      } else {
        alert(`Cannot add more items. Only ${stock} in stock.`);
        return;
      }
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

  // Calculate totals (no tax)
  let subtotal = 0;
  for (const id in cart) {
    subtotal += cart[id].price * cart[id].qty;
  }
  
  currentTotal = subtotal; // No tax added
  
  // Update breakdown display
  document.getElementById('subtotalAmount').textContent = `₱${subtotal.toFixed(2)}`;
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
    tax: 0,
    total: currentTotal,
    items: items,
    payment_mode: paymentMode,
    cash_amount: paymentMode === 'cash' ? parseFloat(document.getElementById('cashAmount').value) : null,
    gcash_ref: paymentMode === 'gcash' ? document.getElementById('gcashRef').value : null,
    change: paymentMode === 'cash' ? Math.max(0, parseFloat(document.getElementById('cashAmount').value) - currentTotal) : 0
  };

  // Show receipt and automatically save to database
  showReceipt(transactionData);
  
  // Automatically submit to database
  submitTransactionToServer(transactionData);
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
  
  // Show/hide floating button based on cart contents and screen size
  const floatingBtn = document.getElementById('floatingCartBtn');
  const isMobile = window.innerWidth <= 768;
  
  if (itemCount > 0 && isMobile) {
    floatingBtn.style.display = 'flex';
  } else {
    floatingBtn.style.display = 'none';
  }
}

// Add window resize listener to handle screen size changes
window.addEventListener('resize', updateCartCount);

// Override updateCartDisplay to include mobile updates
const originalUpdateCartDisplay = updateCartDisplay;
updateCartDisplay = function() {
  originalUpdateCartDisplay();
  updateCartCount();
};

// Initialize cart count
updateCartCount();



// Receipt functions
function showReceipt(transactionData) {
  const receiptContent = document.getElementById('receiptContent');
  const now = new Date();
  const receiptNumber = 'R' + now.getFullYear() + (now.getMonth()+1).toString().padStart(2,'0') + now.getDate().toString().padStart(2,'0') + now.getTime().toString().slice(-6);
  
  let receiptHTML = `
    <div class="receipt-header">
      <h2>ABETH HARDWARE</h2>
      <p>Point of Sale System</p>
      <p>Date: ${now.toLocaleDateString()} ${now.toLocaleTimeString()}</p>
      <p>Receipt #: ${receiptNumber}</p>
    </div>
    
    <div class="receipt-separator"></div>
    
    <div class="receipt-info">
      <div class="info-row">
        <span>Cashier:</span>
        <span><?= $_SESSION['username'] ?></span>
      </div>
      <div class="info-separator"></div>
      <div class="info-row">
        <span>Payment:</span>
        <span>${transactionData.payment_mode.toUpperCase()}</span>
      </div>
    </div>
    
    <div class="receipt-items-separator"></div>
    
    <div class="receipt-items">`;
  
  transactionData.items.forEach(item => {
    const itemTotal = item.qty * item.price;
    receiptHTML += `
      <div class="receipt-item-row">
        <div class="item-name">${item.name}</div>
        <div class="item-price">₱${itemTotal.toFixed(2)}</div>
      </div>
      <div class="item-qty-line">${item.qty} x ₱${item.price.toFixed(2)}</div>`;
  });
  
  receiptHTML += `
    </div>
    
    <div class="receipt-items-separator"></div>
    
    <div class="receipt-totals">
      <div class="total-row">
        <span>Subtotal:</span>
        <span>₱${transactionData.subtotal.toFixed(2)}</span>
      </div>`;
  
  if (transactionData.payment_mode === 'cash') {
    receiptHTML += `
      <div class="total-row">
        <span>Cash:</span>
        <span>₱${transactionData.cash_amount.toFixed(2)}</span>
      </div>
      <div class="total-row">
        <span>Change:</span>
        <span>₱${transactionData.change.toFixed(2)}</span>
      </div>`;
  } else if (transactionData.payment_mode === 'gcash') {
    receiptHTML += `
      <div class="total-row">
        <span>GCash Ref:</span>
        <span>${transactionData.gcash_ref}</span>
      </div>`;
  }
  
  receiptHTML += `
      <div class="final-total">
        <span>TOTAL:</span>
        <span>₱${transactionData.total.toFixed(2)}</span>
      </div>
    </div>
    
    <div class="receipt-separator"></div>
    
    <div class="receipt-footer">
      <p>Thank you for your purchase!</p>
      <p>Please come again</p>
      <br>
      <p>This serves as your official receipt</p>
    </div>`;
  
  receiptContent.innerHTML = receiptHTML;
  document.getElementById('receiptContainer').style.display = 'flex';
}

function printReceipt() {
  // For web browsers - standard print
  window.print();
  
  // For thermal printers, you can add specific printer commands here
  // This would require additional libraries like escpos or printer-specific APIs
  
  // Example for ESC/POS thermal printers (requires additional setup):
  // printToThermalPrinter();
}

function printToThermalPrinter() {
  // This function would handle thermal printer communication
  // You would need to implement printer-specific protocols
  // Common thermal printer protocols: ESC/POS, CPCL, ZPL
  
  try {
    // Example implementation would go here
    // This typically requires:
    // 1. Printer driver installation
    // 2. USB/Serial/Network connection setup
    // 3. Printer-specific command formatting
    
    console.log('Thermal printer functionality would be implemented here');
    alert('Receipt sent to thermal printer! (Implementation depends on your specific printer model)');
  } catch (error) {
    console.error('Printer error:', error);
    alert('Printer not available. Using standard print instead.');
    window.print();
  }
}

function closeReceipt() {
  document.getElementById('receiptContainer').style.display = 'none';
  
  // Clear cart and reset form after successful transaction
  cart = {};
  updateCartDisplay();
  
  // Hide checkout section and show checkout button again
  document.getElementById('checkoutSection').style.display = 'none';
  document.querySelector('.checkout-btn').style.display = 'block';
  
  // Reset form
  document.getElementById('cashAmount').value = '';
  document.getElementById('gcashRef').value = '';
  document.getElementById('changeDisplay').innerHTML = '<strong>Change: ₱0.00</strong>';
  document.querySelector('input[value="cash"]').checked = true;
  togglePaymentMethod();
  
  // Transaction is already saved automatically when confirmed
  console.log('Transaction completed and saved to database');
}

function submitTransactionToServer(transactionData) {
  // Show loading status
  const statusDiv = document.getElementById('transactionStatus');
  statusDiv.innerHTML = '💾 Saving transaction to database...';
  statusDiv.style.display = 'block';
  statusDiv.style.background = 'linear-gradient(45deg, #3498db, #2980b9)';
  statusDiv.style.color = 'white';
  statusDiv.style.padding = '10px';
  statusDiv.style.borderRadius = '8px';
  statusDiv.style.textAlign = 'center';
  statusDiv.style.marginTop = '10px';
  
  // Save transaction data to database via AJAX to avoid page redirect
  const formData = new FormData();
  formData.append('transaction_data', JSON.stringify(transactionData));
  
  fetch('onsite_transaction.php', {
    method: 'POST',
    body: formData
  })
  .then(response => {
    if (response.ok) {
      console.log('Transaction saved successfully to database');
      statusDiv.innerHTML = '✅ Transaction saved successfully!';
      statusDiv.style.background = 'linear-gradient(45deg, #27ae60, #2ecc71)';
      
      // Hide status after 3 seconds
      setTimeout(() => {
        statusDiv.style.display = 'none';
      }, 3000);
    } else {
      console.error('Error saving transaction to database');
      statusDiv.innerHTML = '⚠️ Warning: Issue saving to database';
      statusDiv.style.background = 'linear-gradient(45deg, #e74c3c, #c0392b)';
    }
  })
  .catch(error => {
    console.error('Network error saving transaction:', error);
    statusDiv.innerHTML = '⚠️ Network error saving transaction';
    statusDiv.style.background = 'linear-gradient(45deg, #e74c3c, #c0392b)';
  });
}
</script>

</body>
</html>
