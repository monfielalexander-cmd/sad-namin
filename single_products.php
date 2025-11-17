<?php
session_start();

// Redirect if not admin
if (!isset($_SESSION['username']) || $_SESSION['role'] != 'admin') {
    header("Location: index.php");
    exit();
}

$conn = new mysqli("localhost", "root", "", "hardware_db");
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* ---------------- ADD SINGLE PRODUCT ---------------- */
if (isset($_POST['add_single_product'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $price = floatval($_POST['price']);
    $stock = intval($_POST['stock']);

    $image = "";
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $img_name = basename($_FILES['image']['name']);
        $target_dir = "uploads/";
        if (!is_dir($target_dir)) mkdir($target_dir);
        $target_file = $target_dir . time() . "_" . $img_name;

        if (move_uploaded_file($_FILES['image']['tmp_name'], $target_file)) {
            $image = $target_file;
        }
    }

    $conn->query("INSERT INTO products_ko (name, price, stock, image)
                  VALUES ('$name','$price','$stock','$image')");
    
    header("Location: single_products.php");
    exit();
}

/* ---------------- ARCHIVE PRODUCT ---------------- */
if (isset($_POST['archive_product'])) {
    $pid = intval($_POST['product_id']);
    $conn->query("UPDATE products_ko SET archive = 1 WHERE id = $pid");
    
    header("Location: single_products.php");
    exit();
}

/* ---------------- UPDATE STOCK ---------------- */
if (isset($_POST['update_stock'])) {
    $pid = intval($_POST['product_id']);
    $new_stock = intval($_POST['new_stock']);
    $conn->query("UPDATE products_ko SET stock = stock + $new_stock WHERE id = $pid");
    
    header("Location: single_products.php");
    exit();
}

/* ---------------- FETCH SINGLE PRODUCTS (No Variants) ---------------- */
$products = $conn->query("SELECT p.* FROM products_ko p 
    WHERE p.archive = 0 
    AND p.id NOT IN (SELECT DISTINCT product_id FROM product_variants)
    ORDER BY p.id DESC");

/* ---------------- LOW STOCK ALERT ---------------- */
/* ---------------- LOW STOCK ALERT ---------------- */
$low_stock = $conn->query("SELECT name, stock FROM products_ko 
    WHERE stock <= 5 AND archive = 0 
    AND id NOT IN (SELECT DISTINCT product_id FROM product_variants)
    ORDER BY stock ASC");

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Single Products - Abeth Hardware</title>
<link rel="stylesheet" href="admin.css?v=<?= time() ?>">
</head>
<body>

<!-- NAVIGATION BAR -->
<nav>
  <div class="logo">Single Products</div>
  <div class="menu">
    <a href="admin.php">Multi-Size Products</a>
    <a href="customer_orders.php">Sales</a>
    <a href="orders.php">Orders</a>
    <a href="logout.php" class="logout-btn">Logout</a>
  </div>
</nav>

<!-- PRODUCT MANAGEMENT -->
<div class="admin-panel" id="products">

  <h3 style="margin-top:0; display:flex; align-items:center; gap:8px;">
    Manage Single Products
    <small style="font-size:0.9rem; color:#666; font-weight:400;">(Paint, Tools, Accessories)</small>

    <!-- 🔔 Notification Bell -->
    <div class="notification-wrapper">
      <span class="bell-icon" onclick="toggleNotifications()">
        <svg xmlns="http://www.w3.org/2000/svg" fill="#1e90ff" viewBox="0 0 24 24" width="24px" height="24px">
          <path d="M12 24c1.3 0 2.4-1 2.5-2.3h-5c.1 1.3 1.2 2.3 2.5 2.3zm6.3-6V11c0-3.1-1.6-5.6-4.3-6.3V4.5C14 3.1 13 2 11.5 2S9 3.1 9 4.5v.2C6.3 5.4 4.7 8 4.7 11v7L3 20v1h18v-1l-2.7-2z"/>
        </svg>
      </span>
      <?php if ($low_stock && $low_stock->num_rows > 0): ?>
        <span class="notif-badge"><?= $low_stock->num_rows ?></span>
      <?php endif; ?>

      <div id="notifDropdown" class="notif-dropdown">
        <h4>Low Stock Alerts</h4>
        <?php if ($low_stock && $low_stock->num_rows > 0): ?>
          <ul>
            <?php while ($item = $low_stock->fetch_assoc()): ?>
              <li>
                <strong><?= htmlspecialchars($item['name']) ?></strong>
                — only <span><?= $item['stock'] ?></span> left!
              </li>
            <?php endwhile; ?>
          </ul>
        <?php else: ?>
          <ul><li style="color:#27ae60;">✓ All products are well stocked.</li></ul>
        <?php endif; ?>
      </div>
    </div>
  </h3>

  <div class="manage-products-header">
    <input type="text" id="searchInput" placeholder="Search by name, category, price..." class="search-bar">
    <button onclick="openAddProductModal()" class="add-product-btn">+ Add Product</button>
  </div>

  <table class="product-table" id="productTable">
    <tr>
      <th>ID</th>
      <th>Name</th>
      <th>Price</th>
      <th>Stock</th>
      <th>Image</th>
      <th>Actions</th>
    </tr>
    <?php if ($products && $products->num_rows > 0): ?>
      <?php while ($p = $products->fetch_assoc()): ?>
        <tr>
          <td data-label="ID"><?= $p['id'] ?></td>
          <td data-label="Name"><?= htmlspecialchars($p['name']) ?></td>
          <td data-label="Price">₱<?= number_format($p['price'], 2) ?></td>
          <td data-label="Stock">
            <span style="color:<?= $p['stock'] <= 5 ? '#e74c3c' : '#27ae60' ?>; font-weight:600;">
              <?= $p['stock'] ?> pcs
            </span>
          </td>
          <td data-label="Image">
            <?php if ($p['image']): ?>
              <img src="<?= htmlspecialchars($p['image']) ?>" width="60" alt="Product Image">
            <?php else: ?>
              No image
            <?php endif; ?>
          </td>
          <td data-label="Actions">
            <div class="stock-actions">
              <form method="POST" style="display: inline-flex; gap:6px;">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <input type="number" name="new_stock" min="1" placeholder="Qty" style="width:70px;" required>
                <button type="submit" name="update_stock" class="update-btn">Add Stock</button>
              </form>

              <form method="POST" style="display: inline;">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <button type="submit" name="archive_product" class="archive-btn" onclick="return confirm('Archive this product?')">Archive</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endwhile; ?>
    <?php else: ?>
      <tr><td colspan="6" style="text-align:center; padding:40px; color:#999;">
        No single products found. Click "+ Add Product" to add one.
      </td></tr>
    <?php endif; ?>
  </table>
</div>

<!-- Add Product Modal -->
<div id="addProductModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Add Single Product</h2>
      <span class="close-modal" onclick="closeAddProductModal()">&times;</span>
    </div>
    <form method="POST" enctype="multipart/form-data" class="modal-form">
      <div class="form-group">
        <label for="modal-name">Product Name</label>
        <input type="text" id="modal-name" name="name" placeholder="e.g., Boysen Paint Red 1L" required>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="modal-price">Price</label>
          <input type="number" step="0.01" id="modal-price" name="price" placeholder="0.00" required>
        </div>

        <div class="form-group">
          <label for="modal-stock">Stock</label>
          <input type="number" id="modal-stock" name="stock" placeholder="0" min="0" required>
        </div>
      </div>

      <div class="form-group">
        <label for="modal-image">Product Image</label>
        <input type="file" id="modal-image" name="image" accept="image/*">
      </div>

      <div class="modal-footer">
        <button type="button" onclick="closeAddProductModal()" class="cancel-btn">Cancel</button>
        <button type="submit" name="add_single_product" class="submit-btn">Add Product</button>
      </div>
    </form>
  </div>
</div>

<script>
// Modal Functions
function openAddProductModal() {
  document.getElementById("addProductModal").style.display = "block";
}

function closeAddProductModal() {
  document.getElementById("addProductModal").style.display = "none";
}

// Live Search
document.getElementById("searchInput").addEventListener("keyup", function() {
  const filter = this.value.trim().toLowerCase();
  const rows = document.querySelectorAll("#productTable tr:not(:first-child)");
  rows.forEach(row => {
    let match = false;
    row.querySelectorAll('td').forEach(cell => {
      if (cell.textContent.toLowerCase().includes(filter)) match = true;
    });
    row.style.display = match ? "" : "none";
  });
});

// Close modal when clicking outside
window.onclick = function(event) {
  const modal = document.getElementById("addProductModal");
  if (event.target == modal) {
    closeAddProductModal();
  }
}

// Notification toggle
function toggleNotifications() {
  const dropdown = document.getElementById("notifDropdown");
  dropdown.classList.toggle("show");
}

// Close notification when clicking outside
window.addEventListener("click", function(e) {
  const dropdown = document.getElementById("notifDropdown");
  if (!e.target.closest(".notification-wrapper")) {
    dropdown.classList.remove("show");
  }
});
</script>

<!-- Floating POS Button -->
<a href="pos.php" class="pos-float-btn" title="Open POS">
  🛒 POS
</a>

</body>
</html>
