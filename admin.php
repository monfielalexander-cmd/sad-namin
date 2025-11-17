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

/* ---------------- ADD PRODUCT ---------------- */
if (isset($_POST['add_product'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $category = $conn->real_escape_string($_POST['category']); 
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

      // Insert base product
    $conn->query("INSERT INTO products_ko (name, category, price, stock, image)
                  VALUES ('$name','$category','$price','$stock','$image')");
    
    $product_id = $conn->insert_id;

    // Handle size variants if provided
    if (!empty($_POST['sizes'])) {
        $sizes = $_POST['sizes']; // Array of sizes
        $size_stocks = $_POST['size_stocks']; // Array of stocks per size
        $size_prices = $_POST['size_prices']; // Array of final prices

        for ($i = 0; $i < count($sizes); $i++) {
            if (!empty($sizes[$i])) {
                $size = $conn->real_escape_string($sizes[$i]);
                $size_stock = intval($size_stocks[$i] ?? 0);
                $final_price = floatval($size_prices[$i] ?? 0);
                
                // Calculate price modifier as difference from base price
                $price_modifier = $final_price - $price;

                $conn->query("INSERT INTO product_variants (product_id, size, stock, price_modifier)
                              VALUES ($product_id, '$size', $size_stock, $price_modifier)");
            }
        }
    }
    
    header("Location: admin.php");
    exit();
}

/* ---------------- ARCHIVE PRODUCT ---------------- */
if (isset($_POST['archive_product'])) {
    $pid = intval($_POST['product_id']);
    $conn->query("UPDATE products_ko SET archive = 1 WHERE id = $pid");
    
    header("Location: admin.php");
    exit();
}

/* ---------------- EDIT PRODUCT ---------------- */
if (isset($_POST['edit_product'])) {
    $pid = intval($_POST['edit_product_id']);
    $name = $conn->real_escape_string($_POST['edit_name']);
    $category = $conn->real_escape_string($_POST['edit_category']);
    $price = floatval($_POST['edit_price']);

    // Handle image update
    $image_update = "";
    if (isset($_FILES['edit_image']) && $_FILES['edit_image']['error'] == 0) {
        $img_name = basename($_FILES['edit_image']['name']);
        $target_dir = "uploads/";
        if (!is_dir($target_dir)) mkdir($target_dir);
        $target_file = $target_dir . time() . "_" . $img_name;

        if (move_uploaded_file($_FILES['edit_image']['tmp_name'], $target_file)) {
            $image_update = ", image = '$target_file'";
        }
    }

    $conn->query("UPDATE products_ko SET name = '$name', category = '$category', price = $price $image_update WHERE id = $pid");

    // Handle variant updates
    if (!empty($_POST['edit_variant_ids'])) {
        $variant_ids = $_POST['edit_variant_ids'];
        $variant_sizes = $_POST['edit_variant_sizes'];
        $variant_prices = $_POST['edit_variant_prices'];

        for ($i = 0; $i < count($variant_ids); $i++) {
            $vid = intval($variant_ids[$i]);
            $size = $conn->real_escape_string($variant_sizes[$i]);
            $final_price = floatval($variant_prices[$i]);
            $price_modifier = $final_price - $price;

            $conn->query("UPDATE product_variants SET size = '$size', price_modifier = $price_modifier WHERE id = $vid");
        }
    }

    // Handle new variants
    if (!empty($_POST['new_variant_sizes'])) {
        $new_sizes = $_POST['new_variant_sizes'];
        $new_stocks = $_POST['new_variant_stocks'];
        $new_prices = $_POST['new_variant_prices'];

        for ($i = 0; $i < count($new_sizes); $i++) {
            if (!empty($new_sizes[$i])) {
                $size = $conn->real_escape_string($new_sizes[$i]);
                $stock = intval($new_stocks[$i]);
                $final_price = floatval($new_prices[$i]);
                $price_modifier = $final_price - $price;

                $conn->query("INSERT INTO product_variants (product_id, size, stock, price_modifier) VALUES ($pid, '$size', $stock, $price_modifier)");
            }
        }
    }

    header("Location: admin.php");
    exit();
}

/* ---------------- UPDATE STOCK ---------------- */
if (isset($_POST['update_stock'])) {
    $pid = intval($_POST['product_id']);
    $new_stock = intval($_POST['new_stock']);
    $conn->query("UPDATE products_ko SET stock = stock + $new_stock WHERE id = $pid");
    
    header("Location: admin.php");
    exit();
}

/* ---------------- UPDATE VARIANT STOCK ---------------- */
if (isset($_POST['update_variant_stock'])) {
    $variant_id = intval($_POST['variant_id']);
    $new_stock = intval($_POST['new_stock']);
    $conn->query("UPDATE product_variants SET stock = stock + $new_stock WHERE id = $variant_id");
    
    header("Location: admin.php");
    exit();
}

/* ---------------- FETCH PRODUCTS ---------------- */
$products = $conn->query("SELECT p.*, 
    (SELECT COUNT(*) FROM product_variants WHERE product_id = p.id) as variant_count,
    (SELECT SUM(stock) FROM product_variants WHERE product_id = p.id) as total_variant_stock
    FROM products_ko p 
    WHERE p.archive = 0 
    AND p.id IN (SELECT DISTINCT product_id FROM product_variants)
    ORDER BY p.id DESC");

/* ---------------- LOW STOCK ALERT ---------------- */
// Check for products with variants only
$low_stock_query = "
    SELECT p.name, p.category, v.stock, v.size 
    FROM product_variants v
    JOIN products_ko p ON v.product_id = p.id
    WHERE v.stock <= 5 AND p.archive = 0
    ORDER BY v.stock ASC
";
$low_stock = $conn->query($low_stock_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard - Abeth Hardware</title>
<link rel="stylesheet" href="admin.css?v=<?= time() ?>">

</head>
<body>

<!-- NAVIGATION BAR -->
<nav>
  <div class="logo">Admin Dashboard</div>
  <div class="menu">
    <a href="single_products.php">Single Products</a>
    <a href="customer_orders.php">Sales</a>
    <a href="orders.php">Orders</a>
    <a href="logout.php" class="logout-btn">Logout</a>
  </div>
</nav>

<script>
function toggleMenu() {
  document.getElementById("navMenu").classList.toggle("active");
}

// Modal Functions
function openAddProductModal() {
  document.getElementById("addProductModal").style.display = "block";
}

function closeAddProductModal() {
  document.getElementById("addProductModal").style.display = "none";
}
</script>

<!-- PRODUCT MANAGEMENT -->
<div class="admin-panel" id="products">

  <h3 style="margin-top:0; display:flex; align-items:center; gap:8px;">
    Manage Multi-Size Products
    <small style="font-size:0.9rem; color:#666; font-weight:400;">(Roofing, Lumber, etc.)</small>

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
                <?php if ($item['size']): ?>
                  <span style="color:#e74c3c; font-weight:600;">(<?= htmlspecialchars($item['size']) ?>)</span>
                <?php endif; ?>
                <small style="color:#666;">(<?= htmlspecialchars($item['category'] ?? 'Uncategorized') ?>)</small>
                  — only <span><?= $item['stock'] ?></span> left!
              </li>
            <?php endwhile; ?>
          </ul>
        <?php else: ?>
          <ul><li>All products are well stocked.</li></ul>
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
      <th>Category</th>
      <th>Sizes</th>
      <th>Image</th>
      <th>Actions</th>
    </tr>
    <?php if ($products && $products->num_rows > 0): ?>
      <?php while ($p = $products->fetch_assoc()): ?>
        <?php 
        // Get variants for this product
        $variants = $conn->query("SELECT * FROM product_variants WHERE product_id = {$p['id']}");
        $has_variants = $variants && $variants->num_rows > 0;
        ?>
        <tr>
          <td data-label="ID"><?= $p['id'] ?></td>
          <td data-label="Name"><?= htmlspecialchars($p['name']) ?></td>
          <td data-label="Category"><?= htmlspecialchars($p['category'] ?? 'N/A') ?></td>
          <td data-label="Sizes">
            <?php if ($has_variants): ?>
              <div style="font-size:0.85rem; color:#555;">
                <?php 
                $variants_reset = $conn->query("SELECT * FROM product_variants WHERE product_id = {$p['id']}");
                while ($v = $variants_reset->fetch_assoc()): 
                  $final_price = $p['price'] + $v['price_modifier'];
                ?>
                  <div style="display:flex; align-items:center; gap:8px; margin:4px 0;">
                    <strong><?= htmlspecialchars($v['size']) ?>:</strong>
                    <span><?= $v['stock'] ?> pcs</span>
                    <span style="color:#27ae60; font-weight:600; margin-left:4px;">₱<?= number_format($final_price, 2) ?></span>
                    <form method="POST" style="display:inline-flex; gap:4px; margin-left:auto;">
                      <input type="hidden" name="variant_id" value="<?= $v['id'] ?>">
                      <input type="number" name="new_stock" min="1" placeholder="Qty" 
                             style="width:60px; padding:4px 8px; border:1px solid #ddd; border-radius:6px; font-size:0.85rem;" required>
                      <button type="submit" name="update_variant_stock" 
                              style="padding:4px 10px; background:#0066cc; color:white; border:none; border-radius:6px; cursor:pointer; font-size:0.85rem; font-weight:600;">
                        +
                      </button>
                    </form>
                  </div>
                <?php endwhile; ?>
              </div>
            <?php else: ?>
              <div style="font-size:0.85rem; color:#555;">
                <strong>Stock:</strong> <?= $p['stock'] ?> pcs<br>
                <strong style="color:#27ae60;">Price:</strong> <span style="color:#27ae60; font-weight:600;">₱<?= number_format($p['price'], 2) ?></span>
              </div>
            <?php endif; ?>
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
              <?php if (!$has_variants): ?>
                <form method="POST" style="display: inline;">
                  <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                  <input type="number" name="new_stock" min="1" placeholder="Qty" required>
                  <button type="submit" name="update_stock" class="update-btn">Add Stock</button>
                </form>
              <?php else: ?>
                <span style="color:#666; font-size:0.85rem; font-style:italic;">Update in Sizes →</span>
              <?php endif; ?>

              <button type="button" onclick="openEditProductModal(<?= $p['id'] ?>)" class="update-btn" style="margin-right:8px;">Edit</button>

              <form method="POST" style="display: inline;">
                <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                <button type="submit" name="archive_product" class="archive-btn" onclick="return confirm('Archive this product?')">Archive</button>
              </form>

            </div>
          </td>
        </tr>
      <?php endwhile; ?>
    <?php else: ?>
      <tr><td colspan="6">No products found.</td></tr>
    <?php endif; ?>
  </table>
</div>

<!-- Edit Product Modal -->
<div id="editProductModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Edit Product</h2>
      <span class="close-modal" onclick="closeEditProductModal()">&times;</span>
    </div>
    <form method="POST" enctype="multipart/form-data" class="modal-form" id="editProductForm">
      <input type="hidden" name="edit_product_id" id="edit_product_id">
      
      <div class="form-group">
        <label for="edit-name">Product Name</label>
        <input type="text" id="edit-name" name="edit_name" placeholder="Product Name" required>
      </div>
      
      <div class="form-group">
        <label for="edit-category">Category</label>
        <select id="edit-category" name="edit_category">
          <option value="">Select Category</option>
          <option value="Longspan">Longspan</option>
          <option value="Yero">Yero</option>
          <option value="Yero (B)">Yero (B)</option>
          <option value="Gutter">Gutter</option>
          <option value="Flashing">Flashing</option>
          <option value="Plain Sheet G1">Plain Sheet G.I</option>
          <option value="Shoa Board">Shoa Board</option>
          <option value="Norine Flywood">Norine Flywood</option>
          <option value="Fly Board">Flyboard</option>
          <option value="Pheno UC Board">Pheno UC Board</option>
          <option value="Coco Lumber">Coco Lumber</option>
          <option value="Flush Boor">Flush Boor</option>
          <option value="Savor Bar">Savor Bar</option>
          <option value="Flot Bar">Flot Bar</option>
          <option value="KD Good Lumber">KD Good Lumber</option>
          <option value="Plain Round Bar">Plain Round Bar</option>
          <option value="Insulation">Insulation</option>
        </select>
      </div>

      <div class="form-group">
        <label for="edit-price">Base Price</label>
        <input type="number" id="edit-price" name="edit_price" step="0.01" placeholder="Base Price" required>
      </div>

      <div class="form-group">
        <label>Existing Variants</label>
        <div id="editVariantsContainer"></div>
      </div>

      <div class="form-group">
        <label>Add New Variants</label>
        <div id="newVariantsContainer">
          <div class="size-variant-row">
            <input type="text" name="new_variant_sizes[]" placeholder="Size" style="flex:2;">
            <input type="number" name="new_variant_stocks[]" placeholder="Stock" min="0" style="flex:1;">
            <input type="number" step="0.01" name="new_variant_prices[]" placeholder="Final Price" style="flex:1;">
            <button type="button" onclick="removeNewVariantRow(this)" class="remove-size-btn">×</button>
          </div>
        </div>
        <button type="button" onclick="addNewVariantRow()" class="add-size-btn">+ Add Variant</button>
      </div>

      <div class="form-group">
        <label for="edit-image">Product Image</label>
        <input type="file" id="edit-image" name="edit_image" accept="image/*">
        <small style="color:#666;">Leave empty to keep current image</small>
        <div id="current-image-preview" style="margin-top:8px;"></div>
      </div>

      <div class="modal-footer">
        <button type="button" onclick="closeEditProductModal()" class="cancel-btn">Cancel</button>
        <button type="submit" name="edit_product" class="submit-btn">Update Product</button>
      </div>
    </form>
  </div>
</div>

<!-- Add Product Modal -->
<div id="addProductModal" class="modal">
  <div class="modal-content">
    <div class="modal-header">
      <h2>Add New Product</h2>
      <span class="close-modal" onclick="closeAddProductModal()">&times;</span>
    </div>
    <form method="POST" enctype="multipart/form-data" class="modal-form">
      <div class="form-group">
        <label for="modal-name">Product Name</label>
        <input type="text" id="modal-name" name="name" placeholder="Product Name" required>
      </div>
      
      <div class="form-group">
        <label for="modal-category">Category</label>
        <select id="modal-category" name="category">
          <option value="">Select Category</option>
          <option value="Longspan">Longspan</option>
          <option value="Yero">Yero</option>
          <option value="Yero (B)">Yero (B)</option>
          <option value="Gutter">Gutter</option>
          <option value="Flashing">Flashing</option>
          <option value="Plain Sheet G1">Plain Sheet G.I</option>
          <option value="Shoa Board">Shoa Board</option>
          <option value="Norine Flywood">Marine Plywood</option>
          <option value="Fly Board">Plyboard</option>
          <option value="Pheno UC Board">Phenolic Board</option>
          <option value="Coco Lumber">Coco Lumber</option>
          <option value="Flush Boor">Flush Boor</option>
          <option value="Savor Bar">Savor Bar</option>
          <option value="Flot Bar">Flot Bar</option>
          <option value="KD Good Lumber">KD Good Lumber</option>
          <option value="Plain Round Bar">Plain Round Bar</option>
          <option value="Insulation">Insulation</option>
        </select>
      </div>

      <div class="form-group">
        <label>Product Sizes/Variants</label>
        <div id="sizeVariantsContainer">
          <div class="size-variant-row">
            <input type="text" name="sizes[]" placeholder="Size (e.g., 8ft, 10ft)" style="flex:2;">
            <input type="number" name="size_stocks[]" placeholder="Stock" min="0" style="flex:1;">
            <input type="number" step="0.01" name="size_prices[]" placeholder="Final Price" style="flex:1;">
            <button type="button" onclick="removeSizeRow(this)" class="remove-size-btn">×</button>
          </div>
        </div>
        <button type="button" onclick="addSizeRow()" class="add-size-btn">+ Add Size</button>
        <small style="color:#666;">Enter the complete price for each size (e.g., 900 for 8ft, 1000 for 10ft)</small>
      </div>

      <div class="form-group">
        <label for="modal-image">Product Image</label>
        <input type="file" id="modal-image" name="image" accept="image/*">
      </div>

      <div class="modal-footer">
        <button type="button" onclick="closeAddProductModal()" class="cancel-btn">Cancel</button>
        <button type="submit" name="add_product" class="submit-btn">Add Product</button>
      </div>
    </form>
  </div>
</div>

<!-- 🔍 LIVE SEARCH -->
<script>
// Improved search: matches any cell (name, category, price, etc.)
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

// Close modal when clicking outside of it
window.onclick = function(event) {
  const modal = document.getElementById("addProductModal");
  if (event.target == modal) {
    closeAddProductModal();
  }
}

// 🔔 Notification dropdown smooth toggle
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

// Size variant management functions
function addSizeRow() {
  const container = document.getElementById("sizeVariantsContainer");
  const newRow = document.createElement("div");
  newRow.className = "size-variant-row";
  newRow.innerHTML = `
    <input type="text" name="sizes[]" placeholder="Size (e.g., 8ft, 10ft)" style="flex:2;">
    <input type="number" name="size_stocks[]" placeholder="Stock" min="0" style="flex:1;">
    <input type="number" step="0.01" name="size_prices[]" placeholder="Final Price" style="flex:1;">
    <button type="button" onclick="removeSizeRow(this)" class="remove-size-btn">×</button>
  `;
  container.appendChild(newRow);
}

function removeSizeRow(btn) {
  const container = document.getElementById("sizeVariantsContainer");
  if (container.children.length > 1) {
    btn.parentElement.remove();
  } else {
    alert("At least one size variant row is required if using variants");
  }
}

// Edit Product Modal Functions
function openEditProductModal(productId) {
  // Fetch product data via AJAX
  fetch(`get_product_data.php?id=${productId}`)
    .then(response => response.json())
    .then(data => {
      document.getElementById('edit_product_id').value = data.id;
      document.getElementById('edit-name').value = data.name;
      document.getElementById('edit-category').value = data.category;
      document.getElementById('edit-price').value = data.price;
      
      // Show current image
      const imagePreview = document.getElementById('current-image-preview');
      if (data.image) {
        imagePreview.innerHTML = `<img src="${data.image}" width="100" alt="Current Image">`;
      } else {
        imagePreview.innerHTML = '<small>No current image</small>';
      }
      
      // Populate variants
      const variantsContainer = document.getElementById('editVariantsContainer');
      variantsContainer.innerHTML = '';
      
      if (data.variants && data.variants.length > 0) {
        data.variants.forEach(variant => {
          const variantRow = document.createElement('div');
          variantRow.className = 'size-variant-row';
          variantRow.innerHTML = `
            <input type="hidden" name="edit_variant_ids[]" value="${variant.id}">
            <input type="text" name="edit_variant_sizes[]" value="${variant.size}" placeholder="Size" style="flex:2;">
            <span style="flex:1; padding:8px; color:#666;">Stock: ${variant.stock}</span>
            <input type="number" step="0.01" name="edit_variant_prices[]" value="${variant.final_price}" placeholder="Final Price" style="flex:1;">
          `;
          variantsContainer.appendChild(variantRow);
        });
      }
      
      document.getElementById('editProductModal').style.display = 'block';
    })
    .catch(error => console.error('Error:', error));
}

function closeEditProductModal() {
  document.getElementById('editProductModal').style.display = 'none';
}

function addNewVariantRow() {
  const container = document.getElementById('newVariantsContainer');
  const newRow = document.createElement('div');
  newRow.className = 'size-variant-row';
  newRow.innerHTML = `
    <input type="text" name="new_variant_sizes[]" placeholder="Size" style="flex:2;">
    <input type="number" name="new_variant_stocks[]" placeholder="Stock" min="0" style="flex:1;">
    <input type="number" step="0.01" name="new_variant_prices[]" placeholder="Final Price" style="flex:1;">
    <button type="button" onclick="removeNewVariantRow(this)" class="remove-size-btn">×</button>
  `;
  container.appendChild(newRow);
}

function removeNewVariantRow(btn) {
  btn.parentElement.remove();
}
</script>

<!-- Floating POS Button -->
<a href="pos.php" class="pos-float-btn" title="Open POS">
  🛒 POS
</a>

</body>
</html>
