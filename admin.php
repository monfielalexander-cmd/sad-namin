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



// Provide a simple JSON endpoint to fetch stock logs for a product or variant
if (isset($_GET['get_stock_logs'])) {
  $product_id = isset($_GET['product_id']) && $_GET['product_id'] !== '' ? intval($_GET['product_id']) : null;
  $variant_id = isset($_GET['variant_id']) && $_GET['variant_id'] !== '' ? intval($_GET['variant_id']) : null;

  header('Content-Type: application/json');
  $logs = [];

  if ($variant_id) {
    $stmt = $conn->prepare("SELECT sl.*, p.name as product_name, pv.size as variant_size FROM stock_log sl LEFT JOIN products_ko p ON sl.product_id = p.id LEFT JOIN product_variants pv ON sl.variant_id = pv.id WHERE sl.variant_id = ? ORDER BY sl.created_at DESC");
    if ($stmt) {
      $stmt->bind_param('i', $variant_id);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) $logs[] = $r;
      $stmt->close();
    }
  } elseif ($product_id) {
    $stmt = $conn->prepare("SELECT sl.*, p.name as product_name, pv.size as variant_size FROM stock_log sl LEFT JOIN products_ko p ON sl.product_id = p.id LEFT JOIN product_variants pv ON sl.variant_id = pv.id WHERE sl.product_id = ? ORDER BY sl.created_at DESC");
    if ($stmt) {
      $stmt->bind_param('i', $product_id);
      $stmt->execute();
      $res = $stmt->get_result();
      while ($r = $res->fetch_assoc()) $logs[] = $r;
      $stmt->close();
    }
  } else {
    $res = $conn->query("SELECT sl.*, p.name as product_name, pv.size as variant_size FROM stock_log sl LEFT JOIN products_ko p ON sl.product_id = p.id LEFT JOIN product_variants pv ON sl.variant_id = pv.id ORDER BY sl.created_at DESC LIMIT 50");
    if ($res) {
      while ($r = $res->fetch_assoc()) $logs[] = $r;
    }
  }

  echo json_encode($logs);
  exit();
}

// Endpoint: return count of online orders (pending/new)
if (isset($_GET['online_order_count'])) {
  // return total pending and new-since-last-seen counts
  $total_res = $conn->query("SELECT COUNT(*) AS cnt FROM transactions WHERE source = 'online' AND (status IS NULL OR status <> 'success')");
  $total_cnt = 0;
  if ($total_res) { $r = $total_res->fetch_assoc(); $total_cnt = intval($r['cnt'] ?? 0); }

  $new_cnt = 0;
  if (isset($_SESSION['online_orders_last_seen']) && $_SESSION['online_orders_last_seen']) {
    $last = $conn->real_escape_string($_SESSION['online_orders_last_seen']);
    $new_res = $conn->query("SELECT COUNT(*) AS cnt FROM transactions WHERE source = 'online' AND (status IS NULL OR status <> 'success') AND transaction_date > '$last'");
    if ($new_res) { $rr = $new_res->fetch_assoc(); $new_cnt = intval($rr['cnt'] ?? 0); }
  } else {
    // if never seen, all pending are 'new'
    $new_cnt = $total_cnt;
  }

  header('Content-Type: application/json');
  echo json_encode(['total' => $total_cnt, 'new' => $new_cnt]);
  exit();
}

// Endpoint: acknowledge (mark) online orders as seen for this admin session
if (isset($_GET['ack_online_orders'])) {
  $_SESSION['online_orders_last_seen'] = date('Y-m-d H:i:s');
  header('Content-Type: application/json');
  echo json_encode(['ok' => true, 'seen_at' => $_SESSION['online_orders_last_seen']]);
  exit();
}
/* ---------------- ADD PRODUCT ---------------- */
if (isset($_POST['add_product'])) {
    $name = $conn->real_escape_string($_POST['name']);
    $category = $conn->real_escape_string($_POST['category']); 
    $price = floatval($_POST['price']);
    $stock = intval($_POST['stock']);

    // Check if product name already exists
    $check_name = $conn->query("SELECT id FROM products_ko WHERE name = '$name' AND archive = 0");
    if ($check_name && $check_name->num_rows > 0) {
        $_SESSION['error_message'] = "Product name already exists. Please use a different name.";
        header("Location: admin.php");
        exit();
    }

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

                // Handle variant image upload
                $variant_image = "";
                if (isset($_FILES['size_images']) && isset($_FILES['size_images']['name'][$i]) && $_FILES['size_images']['error'][$i] == 0) {
                    $img_name = basename($_FILES['size_images']['name'][$i]);
                    $target_dir = "uploads/";
                    if (!is_dir($target_dir)) mkdir($target_dir);
                    $target_file = $target_dir . time() . "_" . $i . "_" . $img_name;
                    
                    if (move_uploaded_file($_FILES['size_images']['tmp_name'][$i], $target_file)) {
                        $variant_image = $target_file;
                    }
                }

                $conn->query("INSERT INTO product_variants (product_id, size, stock, price_modifier, image)
                              VALUES ($product_id, '$size', $size_stock, $price_modifier, '$variant_image')");
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
    // Log archive action in stock_log with change_amount = 0 so it appears in logs
    $stmt = $conn->prepare("INSERT INTO stock_log (product_id, variant_id, change_amount) VALUES (?, NULL, 0)");
    if ($stmt) {
      $stmt->bind_param('i', $pid);
      $stmt->execute();
      $stmt->close();
    }

    header("Location: admin.php");
    exit();
}

/* ---------------- EDIT PRODUCT ---------------- */
if (isset($_POST['edit_product'])) {
    $pid = intval($_POST['edit_product_id']);
    $name = $conn->real_escape_string($_POST['edit_name']);
    $category = $conn->real_escape_string($_POST['edit_category']);
    $price = floatval($_POST['edit_price']);

    // Check if product name already exists (excluding current product)
    $check_name = $conn->query("SELECT id FROM products_ko WHERE name = '$name' AND archive = 0 AND id != $pid");
    if ($check_name && $check_name->num_rows > 0) {
        $_SESSION['error_message'] = "Product name already exists. Please use a different name.";
        header("Location: admin.php");
        exit();
    }

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

    // Handle removed variants (delete from DB)
    if (!empty($_POST['remove_variant_ids'])) {
      $remove_ids = $_POST['remove_variant_ids'];
      foreach ($remove_ids as $rid) {
        $rid = intval($rid);
        if ($rid > 0) {
          $conn->query("DELETE FROM product_variants WHERE id = $rid AND product_id = $pid");
        }
      }
    }

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

            // Handle variant image update
            $image_update = "";
            $file_key = "edit_variant_images_" . $vid;
            if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] == 0) {
                $img_name = basename($_FILES[$file_key]['name']);
                $target_dir = "uploads/";
                if (!is_dir($target_dir)) mkdir($target_dir);
                $target_file = $target_dir . time() . "_variant_" . $vid . "_" . $img_name;
                
                if (move_uploaded_file($_FILES[$file_key]['tmp_name'], $target_file)) {
                    $image_update = ", image = '" . $conn->real_escape_string($target_file) . "'";
                }
            }

            $conn->query("UPDATE product_variants SET size = '$size', price_modifier = $price_modifier $image_update WHERE id = $vid");
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

                // Handle new variant image upload
                $variant_image = "";
                if (isset($_FILES['new_variant_images']) && isset($_FILES['new_variant_images']['name'][$i]) && $_FILES['new_variant_images']['error'][$i] == 0) {
                    $img_name = basename($_FILES['new_variant_images']['name'][$i]);
                    $target_dir = "uploads/";
                    if (!is_dir($target_dir)) mkdir($target_dir);
                    $target_file = $target_dir . time() . "_" . $i . "_" . $img_name;
                    
                    if (move_uploaded_file($_FILES['new_variant_images']['tmp_name'][$i], $target_file)) {
                        $variant_image = $target_file;
                    }
                }

                $conn->query("INSERT INTO product_variants (product_id, size, stock, price_modifier, image) VALUES ($pid, '$size', $stock, $price_modifier, '$variant_image')");
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
  // Log this stock change with timestamp
  $stmt = $conn->prepare("INSERT INTO stock_log (product_id, variant_id, change_amount) VALUES (?, NULL, ?)");
  if ($stmt) {
    $stmt->bind_param('ii', $pid, $new_stock);
    $stmt->execute();
    $stmt->close();
  }
    
    header("Location: admin.php");
    exit();
}

/* ---------------- UPDATE VARIANT STOCK ---------------- */
if (isset($_POST['update_variant_stock'])) {
    $variant_id = intval($_POST['variant_id']);
    $new_stock = intval($_POST['new_stock']);
    $conn->query("UPDATE product_variants SET stock = stock + $new_stock WHERE id = $variant_id");
  // Log this variant stock change
  // find product_id for this variant
  $res = $conn->query("SELECT product_id FROM product_variants WHERE id = $variant_id");
  $pid_for_log = null;
  if ($res && $res->num_rows > 0) {
    $row = $res->fetch_assoc();
    $pid_for_log = intval($row['product_id']);
  }
  $stmt = $conn->prepare("INSERT INTO stock_log (product_id, variant_id, change_amount) VALUES (?, ?, ?)");
  if ($stmt) {
    $stmt->bind_param('iii', $pid_for_log, $variant_id, $new_stock);
    $stmt->execute();
    $stmt->close();
  }
    
    header("Location: admin.php");
    exit();
}

/* ---------------- EXPORT STOCK TO CSV ---------------- */
if (isset($_GET['export_stock_csv'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="stock_export_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Type', 'Product ID', 'Variant ID', 'Product Name', 'Size', 'Current Stock', 'Add Stock']);
    
    // Export multi-size products with variants
    $multi = $conn->query("SELECT p.id as product_id, p.name, v.id as variant_id, v.size, v.stock 
                           FROM products_ko p 
                           JOIN product_variants v ON p.id = v.product_id 
                           WHERE p.archive = 0 
                           ORDER BY p.name, v.size");
    if ($multi) {
        while ($row = $multi->fetch_assoc()) {
            fputcsv($output, [
                'variant',
                $row['product_id'],
                $row['variant_id'],
                $row['name'],
                $row['size'],
                $row['stock'],
                '' // Empty column for user to fill
            ]);
        }
    }
    
    // Export single products
    $single = $conn->query("SELECT p.id, p.name, p.stock 
                            FROM products_ko p 
                            WHERE p.archive = 0 
                            AND p.id NOT IN (SELECT DISTINCT product_id FROM product_variants) 
                            ORDER BY p.name");
    if ($single) {
        while ($row = $single->fetch_assoc()) {
            fputcsv($output, [
                'product',
                $row['id'],
                '',
                $row['name'],
                '',
                $row['stock'],
                '' // Empty column for user to fill
            ]);
        }
    }
    
    fclose($output);
    exit();
}

/* ---------------- IMPORT STOCK FROM CSV ---------------- */
if (isset($_POST['import_stock_csv']) && isset($_FILES['stock_csv'])) {
    $errors = [];
    $success_count = 0;
    
    if ($_FILES['stock_csv']['error'] == 0) {
        $file = fopen($_FILES['stock_csv']['tmp_name'], 'r');
        
        // Skip header row
        fgetcsv($file);
        
        while (($data = fgetcsv($file)) !== FALSE) {
            if (count($data) < 7) continue;
            
            $type = trim($data[0]);
            $product_id = intval($data[1]);
            $variant_id = $data[2] ? intval($data[2]) : null;
            $add_stock = intval($data[6]);
            
            if ($add_stock <= 0) continue; // Skip if no stock to add
            
            if ($type === 'variant' && $variant_id) {
                // Update variant stock
                $conn->query("UPDATE product_variants SET stock = stock + $add_stock WHERE id = $variant_id");
                
                // Log the change
                $stmt = $conn->prepare("INSERT INTO stock_log (product_id, variant_id, change_amount) VALUES (?, ?, ?)");
                if ($stmt) {
                    $stmt->bind_param('iii', $product_id, $variant_id, $add_stock);
                    $stmt->execute();
                    $stmt->close();
                }
                $success_count++;
            } elseif ($type === 'product' && $product_id) {
                // Update product stock
                $conn->query("UPDATE products_ko SET stock = stock + $add_stock WHERE id = $product_id");
                
                // Log the change
                $stmt = $conn->prepare("INSERT INTO stock_log (product_id, variant_id, change_amount) VALUES (?, NULL, ?)");
                if ($stmt) {
                    $stmt->bind_param('ii', $product_id, $add_stock);
                    $stmt->execute();
                    $stmt->close();
                }
                $success_count++;
            }
        }
        
        fclose($file);
        $_SESSION['success_message'] = "Successfully updated stock for $success_count items.";
    } else {
        $_SESSION['error_message'] = "Error uploading CSV file.";
    }
    
    header("Location: admin.php");
    exit();
}

/* ---------------- FETCH PRODUCTS ---------------- */
$multi_products = $conn->query("SELECT p.*, 
  (SELECT COUNT(*) FROM product_variants WHERE product_id = p.id) as variant_count,
  (SELECT SUM(stock) FROM product_variants WHERE product_id = p.id) as total_variant_stock,
  (SELECT MAX(created_at) FROM stock_log sl WHERE sl.product_id = p.id AND sl.variant_id IS NULL) AS last_product_stock_update
  FROM products_ko p 
  WHERE p.archive = 0
  AND p.id IN (SELECT DISTINCT product_id FROM product_variants)
  ORDER BY p.id DESC");

$single_products = $conn->query("SELECT p.* FROM products_ko p WHERE p.archive = 0 AND p.id NOT IN (SELECT DISTINCT product_id FROM product_variants) ORDER BY p.id DESC");

/* ---------------- LOW STOCK ALERT ---------------- */
// Check for products with variants only
$low_stock_query = "
  SELECT p.name, p.category, v.stock, v.size
  FROM product_variants v
  JOIN products_ko p ON v.product_id = p.id
  WHERE v.stock <= 5 AND p.archive = 0
  UNION
  SELECT p.name, p.category, p.stock AS stock, NULL AS size
  FROM products_ko p
  WHERE p.stock < 4 AND p.archive = 0 AND p.id NOT IN (SELECT DISTINCT product_id FROM product_variants)
  ORDER BY stock ASC
";
$low_stock = $conn->query($low_stock_query);

// initial online orders new-count (since last seen) for rendering nav badge
$online_orders_count = 0;
$online_orders_total = 0;
$res_count = $conn->query("SELECT COUNT(*) AS cnt FROM transactions WHERE source = 'online' AND (status IS NULL OR status <> 'success')");
if ($res_count) {
  $r = $res_count->fetch_assoc();
  $online_orders_total = intval($r['cnt'] ?? 0);
}
if (isset($_SESSION['online_orders_last_seen']) && $_SESSION['online_orders_last_seen']) {
  $ls = $conn->real_escape_string($_SESSION['online_orders_last_seen']);
  $res_new = $conn->query("SELECT COUNT(*) AS cnt FROM transactions WHERE source = 'online' AND (status IS NULL OR status <> 'success') AND transaction_date > '$ls'");
  if ($res_new) {
    $rr = $res_new->fetch_assoc();
    $online_orders_count = intval($rr['cnt'] ?? 0);
  }
} else {
  // first visit: treat all pending as new
  $online_orders_count = $online_orders_total;
}
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
    <a href="customer_orders.php">Sales</a>
    <div class="orders-top-container">
      <a href="orders.php" id="ordersLink" onclick="ackOnlineOrders()">Orders</a>
      <span id="ordersBadge" class="orders-badge <?= $online_orders_count > 0 ? '' : 'hidden' ?>"><?= $online_orders_count > 0 ? $online_orders_count : '' ?></span>
    </div>
    <a href="logout.php" class="logout-btn">Logout</a>
  </div>
</nav>

<script>
function toggleMenu() {
  document.getElementById("navMenu").classList.toggle("active");
}

// Modal Functions
function openAddProductModal(type) {
  document.getElementById("addProductModal").style.display = "block";
  const t = type && (type === 'single' || type === 'multi') ? type : 'multi';
  const radio = document.querySelector('input[name="product_type"][value="' + t + '"]');
  if (radio) radio.checked = true;
  if (typeof toggleProductType === 'function') toggleProductType(t);
}

function closeAddProductModal() {
  document.getElementById("addProductModal").style.display = "none";
}
</script>

<!-- PRODUCT MANAGEMENT -->
<div class="admin-panel" id="products">

  <?php if (isset($_SESSION['error_message'])): ?>
    <div style="background:#e74c3c; color:white; padding:12px 20px; border-radius:8px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
      <span><?= $_SESSION['error_message'] ?></span>
      <button onclick="this.parentElement.remove()" style="background:transparent; border:none; color:white; font-size:20px; cursor:pointer; padding:0 8px;">&times;</button>
    </div>
    <?php unset($_SESSION['error_message']); ?>
  <?php endif; ?>

  <?php if (isset($_SESSION['success_message'])): ?>
    <div style="background:#27ae60; color:white; padding:12px 20px; border-radius:8px; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center;">
      <span><?= $_SESSION['success_message'] ?></span>
      <button onclick="this.parentElement.remove()" style="background:transparent; border:none; color:white; font-size:20px; cursor:pointer; padding:0 8px;">&times;</button>
    </div>
    <?php unset($_SESSION['success_message']); ?>
  <?php endif; ?>

  <h3 style="margin-top:0; display:flex; align-items:center; gap:8px;">
    Multi-Size Products
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
    <div style="display:flex;gap:8px;align-items:center;">
      <button onclick="openAddProductModal()" class="add-product-btn">+ Add Product</button>
      <button type="button" onclick="openBatchStockModal()" class="add-product-btn">Batch Stock</button>
      <button type="button" onclick="showStockLogs(0,0)" class="add-product-btn">Show Logs</button>
    </div>
  </div>

  <table class="product-table" id="productTable">
    <tr>
      <th>ID</th>
      <th>Name</th>
      <th>Category</th>
      <th>Sizes</th>
      <th>Stock</th>
      <th>Price</th>
      <th>Image</th>
      <th>Actions</th>
    </tr>
    <?php if ($multi_products && $multi_products->num_rows > 0): ?>
      <?php while ($p = $multi_products->fetch_assoc()): ?>
        <?php 
        // Get variants for this product
        // include last stock change timestamp for each variant
        $variants = $conn->query("SELECT pv.*, (SELECT MAX(created_at) FROM stock_log sl WHERE sl.variant_id = pv.id) AS last_stock_update FROM product_variants pv WHERE product_id = {$p['id']}");
        $has_variants = $variants && $variants->num_rows > 0;
        ?>
        <tr>
          <td data-label="ID"><?= $p['id'] ?></td>
          <td data-label="Name"><?= htmlspecialchars($p['name']) ?></td>
          <td data-label="Category"><?= htmlspecialchars($p['category'] ?? 'N/A') ?></td>
          
          <!-- Sizes Column -->
          <td data-label="Sizes">
            <?php if ($has_variants): ?>
              <div style="font-size:0.85rem; color:#555;">
                <?php 
                $variants_reset = $conn->query("SELECT pv.*, (SELECT MAX(created_at) FROM stock_log sl WHERE sl.variant_id = pv.id) AS last_stock_update FROM product_variants pv WHERE product_id = {$p['id']}");
                while ($v = $variants_reset->fetch_assoc()): 
                  $final_price = $p['price'] + $v['price_modifier'];
                ?>
                  <div style="margin:4px 0;">
                    <strong><?= htmlspecialchars($v['size']) ?></strong>
                  </div>
                <?php endwhile; ?>
              </div>
            <?php else: ?>
              <span style="color:#999; font-style:italic;">—</span>
            <?php endif; ?>
          </td>
          
          <!-- Stock Column -->
          <td data-label="Stock">
            <?php if ($has_variants): ?>
              <div style="font-size:0.85rem; color:#555;">
                <?php 
                $variants_reset2 = $conn->query("SELECT pv.*, (SELECT MAX(created_at) FROM stock_log sl WHERE sl.variant_id = pv.id) AS last_stock_update FROM product_variants pv WHERE product_id = {$p['id']}");
                while ($v = $variants_reset2->fetch_assoc()): 
                ?>
                  <div style="display:flex; align-items:center; gap:4px; margin:4px 0;">
                    <span><?= $v['stock'] ?> pcs</span>
                    <form method="POST" style="display:inline-flex; gap:4px;">
                      <input type="hidden" name="variant_id" value="<?= $v['id'] ?>">
                      <input type="number" name="new_stock" min="1" placeholder="Qty" 
                             style="width:50px; padding:4px 6px; border:1px solid #ddd; border-radius:6px; font-size:0.8rem;" required>
                      <button type="submit" name="update_variant_stock" 
                              style="padding:4px 8px; background:#0066cc; color:white; border:none; border-radius:6px; cursor:pointer; font-size:0.8rem; font-weight:600;">
                        +
                      </button>
                    </form>
                  </div>
                <?php endwhile; ?>
              </div>
            <?php else: ?>
              <div style="display:flex; align-items:center; gap:6px; justify-content:center;">
                <span><?= $p['stock'] ?> pcs</span>
                <form method="POST" style="display:inline-flex; gap:4px;">
                  <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                  <input type="number" name="new_stock" min="1" placeholder="Qty" style="width:50px; padding:4px 6px; border:1px solid #ddd; border-radius:6px; font-size:0.8rem;" required>
                  <button type="submit" name="update_stock" class="plus-stock-btn" title="Add stock">+</button>
                </form>
              </div>
            <?php endif; ?>
          </td>
          
          <!-- Price Column -->
          <td data-label="Price">
            <?php if ($has_variants): ?>
              <div style="font-size:0.85rem; color:#555;">
                <?php 
                $variants_reset3 = $conn->query("SELECT pv.*, (SELECT MAX(created_at) FROM stock_log sl WHERE sl.variant_id = pv.id) AS last_stock_update FROM product_variants pv WHERE product_id = {$p['id']}");
                while ($v = $variants_reset3->fetch_assoc()): 
                  $final_price = $p['price'] + $v['price_modifier'];
                ?>
                  <div style="margin:4px 0; color:#27ae60; font-weight:600;">
                    ₱<?= number_format($final_price, 2) ?>
                  </div>
                <?php endwhile; ?>
              </div>
            <?php else: ?>
              <span style="color:#27ae60; font-weight:600;">₱<?= number_format($p['price'], 2) ?></span>
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
              <?php if ($has_variants): ?>
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

<!-- Single Products Section -->
<div class="admin-panel" id="single-products" style="margin-top:32px;">
  <h3 style="margin-top:0;">Single Products</h3>
  <table class="product-table" id="singleProductTable">
    <tr>
      <th>ID</th>
      <th>Name</th>
      <th>Stock</th>
      <th>Price</th>
      <th>Image</th>
      <th>Actions</th>
    </tr>
    <?php if ($single_products && $single_products->num_rows > 0): ?>
      <?php while ($sp = $single_products->fetch_assoc()): ?>
        <tr>
          <td data-label="ID"><?= $sp['id'] ?></td>
          <td data-label="Name"><?= htmlspecialchars($sp['name']) ?></td>
            <td data-label="Stock">
            <div style="display:flex; align-items:center; gap:10px; justify-content:center;">
              <div><strong><?= intval($sp['stock']) ?></strong> pcs</div>
              <form method="POST" style="display:inline-flex; align-items:center; gap:6px;">
                <input type="hidden" name="product_id" value="<?= $sp['id'] ?>">
                <input type="number" name="new_stock" min="1" placeholder="Qty" style="width:64px; padding:6px 8px; border:1px solid #ddd; border-radius:8px; font-size:0.85rem;" required>
                <button type="submit" name="update_stock" class="plus-stock-btn" title="Add stock">+</button>
              </form>
            </div>
          </td>
          <td data-label="Price">₱<?= number_format($sp['price'], 2) ?></td>
          <td data-label="Image">
            <?php if ($sp['image']): ?>
              <img src="<?= htmlspecialchars($sp['image']) ?>" width="60" alt="Product Image">
            <?php else: ?>
              No image
            <?php endif; ?>
          </td>
          <td data-label="Actions">
            <div class="stock-actions">
              <button type="button" onclick="openEditProductModal(<?= $sp['id'] ?>)" class="update-btn" style="margin-right:8px;">Edit</button>

              <form method="POST" style="display: inline;">
                <input type="hidden" name="product_id" value="<?= $sp['id'] ?>">
                <button type="submit" name="archive_product" class="archive-btn" onclick="return confirm('Archive this product?')">Archive</button>
              </form>
            </div>
          </td>
        </tr>
      <?php endwhile; ?>
    <?php else: ?>
      <tr><td colspan="6">No single products found.</td></tr>
    <?php endif; ?>
  </table>
</div>
<!-- Batch Stock Update Modal -->
<div id="batchStockModal" class="modal" style="display:none;">
  <div class="modal-content" style="max-width:600px;">
    <div class="modal-header">
      <h2>📊 Batch Stock Update</h2>
      <span class="close-modal" onclick="closeBatchStockModal()">&times;</span>
    </div>
    <div style="padding:20px;">
      <p style="margin-bottom:20px; color:#666;">Update stock for multiple products at once using a CSV file.</p>
      
      <div style="background:#f8f9fa; padding:16px; border-radius:8px; margin-bottom:20px;">
        <h4 style="margin-top:0; color:#004080;">Step 1: Export Current Stock</h4>
        <p style="font-size:0.9rem; color:#666; margin-bottom:12px;">Download a CSV file with your current product stock levels.</p>
        <a href="admin.php?export_stock_csv=1" class="add-product-btn" style="display:inline-block; text-decoration:none; background:#004080;">
          ⬇️ Download Stock CSV
        </a>
      </div>
      
      <div style="background:#f8f9fa; padding:16px; border-radius:8px;">
        <h4 style="margin-top:0; color:#27ae60;">Step 2: Update & Import</h4>
        <p style="font-size:0.9rem; color:#666; margin-bottom:12px;">
          Fill in the "Add Stock" column with quantities to add, then upload the file.
        </p>
        <form method="POST" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:12px;">
          <input type="file" name="stock_csv" accept=".csv" required style="padding:10px; border:2px dashed #ddd; border-radius:8px; background:white;">
          <button type="submit" name="import_stock_csv" class="submit-btn" style="background:linear-gradient(135deg, #16a085 0%, #27ae60 100%);">
            ⬆️ Upload & Update Stock
          </button>
        </form>
      </div>
      
      <div style="margin-top:16px; padding:12px; background:#fff3cd; border-left:4px solid #ffc107; border-radius:4px;">
        <small style="color:#856404;">
          <strong>Note:</strong> Only rows with values in the "Add Stock" column will be updated. Stock will be added to current levels.
        </small>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" onclick="closeBatchStockModal()" class="cancel-btn">Close</button>
    </div>
  </div>
</div>

<!-- Stock Log Modal -->
<div id="stockLogModal" class="modal" style="display:none;">
  <div class="modal-content" style="max-width:760px;">
    <div class="modal-header">
      <h2>Admin Logs</h2>
      <span class="close-modal" onclick="closeStockLogModal()">&times;</span>
    </div>
    <div id="stockLogContent" style="padding:16px;">Loading...</div>
    <div class="modal-footer">
      <button type="button" onclick="closeStockLogModal()" class="cancellogs-btn">Close</button>
    </div>
  </div>
</div>v>
</div>



      <!-- Edit Product Modal -->
      <div id="editProductModal" class="modal" style="display:none;">
        <div class="modal-content">
          <div class="modal-header">
            <h2>Edit Product</h2>
            <span class="close-modal" onclick="closeEditProductModal()">&times;</span>
          </div>
          <form id="editProductForm" method="POST" enctype="multipart/form-data" class="modal-form">
            <input type="hidden" id="edit_product_id" name="edit_product_id" />

            <div class="form-group">
              <label for="edit-name">Product Name</label>
              <input type="text" id="edit-name" name="edit_name" placeholder="Product Name" required>
            </div>

            <div class="form-group" id="editPriceGroup">
              <label for="edit-price">Price</label>
              <input type="number" step="0.01" id="edit-price" name="edit_price" placeholder="Product Price">
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
              <label>Existing Variants</label>
              <div id="editVariantsContainer"><small style="color:#999;">No existing variants</small></div>
            </div>

            <div class="form-group">
              <label>Add New Variants</label>
              <div id="newVariantsContainer">
                <div class="size-variant-row" style="display:flex; gap:8px; align-items:center; margin-bottom:10px; padding:10px; border:1px solid #e0e0e0; border-radius:8px;">
                  <input type="text" name="new_variant_sizes[]" placeholder="Size" style="flex:2; min-width:120px;">
                  <input type="number" name="new_variant_stocks[]" placeholder="Stock" min="0" style="flex:1; min-width:100px;">
                  <input type="number" step="0.01" name="new_variant_prices[]" placeholder="Final Price" style="flex:1; min-width:100px;">
                  <div style="flex:1.5; min-width:140px;">
                    <input type="file" name="new_variant_images[]" accept="image/*" style="font-size:0.85rem; width:100%; padding:8px;">
                  </div>
                  <button type="button" onclick="removeNewVariantRow(this)" class="remove-size-btn">×</button>
                </div>
              </div>
              <button type="button" onclick="addNewVariantRow()" class="add-size-btn">+ Add Variant</button>
            </div>

            <div class="form-group">
              <label for="edit-image">Product Image</label>
              <input type="file" id="edit-image" name="edit_image" accept="image/*">
              <small style="color:#666; display:block;">Leave empty to keep current image</small>
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
        <label>Product Type</label>
        <div style="display:flex;gap:12px;align-items:center;">
          <label style="font-weight:600;"><input type="radio" name="product_type" value="multi" checked onchange="toggleProductType('multi')"> Multi-size (variants)</label>
          <label style="font-weight:600;"><input type="radio" name="product_type" value="single" onchange="toggleProductType('single')"> Single product</label>
        </div>
      </div>

      <div class="form-group">
        <label for="modal-name">Product Name</label>
        <input type="text" id="modal-name" name="name" placeholder="Product Name" required>
      </div>

      <div class="form-group" id="categoryField">
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

      <!-- Single product fields -->
      <div id="singleProductFields" style="display:none;">
        <div class="form-group" style="display:flex; gap:12px; align-items:flex-start; flex-wrap:wrap;">
          <div style="flex:1; min-width:180px;">
            <label for="single-price">Price</label>
            <input type="number" step="0.01" id="single-price" name="price" placeholder="Price" style="width:100%;" />
          </div>
          <div style="flex:1; min-width:140px;">
            <label for="single-stock">Stock</label>
            <input type="number" id="single-stock" name="stock" placeholder="Stock" min="0" style="width:100%;" />
          </div>
        </div>
      </div>

      <!-- Multi-size (variants) fields -->
      <div id="multiProductFields">
        <div class="form-group">
          <label>Product Sizes/Variants</label>
          <div id="sizeVariantsContainer">
            <div class="size-variant-row" style="display:flex; gap:8px; align-items:center; margin-bottom:10px; padding:10px; border:1px solid #e0e0e0; border-radius:8px;">
              <input type="text" name="sizes[]" placeholder="Size (e.g., 8ft, 10ft)" style="flex:2; min-width:120px;">
              <input type="number" name="size_stocks[]" placeholder="Stock" min="0" style="flex:1; min-width:100px;">
              <input type="number" step="0.01" name="size_prices[]" placeholder="Final Price" style="flex:1; min-width:100px;">
              <div style="flex:1.5; min-width:140px;">
                <input type="file" name="size_images[]" accept="image/*" style="font-size:0.85rem; width:100%; padding:8px;">
              </div>
              <button type="button" onclick="removeSizeRow(this)" class="remove-size-btn">×</button>
            </div>
          </div>
          <button type="button" onclick="addSizeRow()" class="add-size-btn">+ Add Size</button>
          <small style="color:#666;">Enter the complete price for each size and optionally upload an image for each variant</small>
        </div>
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
// Improved search: when user types one letter, show products whose Name starts with it;
// when typing more than one character, fall back to a full-text (includes) match across the row.
document.getElementById("searchInput").addEventListener("input", function() {
  const filter = this.value.trim().toLowerCase();
  const rows = document.querySelectorAll("#productTable tr:not(:first-child), #singleProductTable tr:not(:first-child)");
  rows.forEach(row => {
    if (!filter) { row.style.display = ""; return; }

    // Prefer checking specific cells so single-product rows reliably match
    const nameCell = row.querySelector('td[data-label="Name"]') || row.querySelector('td:nth-child(2)');
    const categoryCell = row.querySelector('td[data-label="Category"]') || row.querySelector('td:nth-child(3)');
    const priceCell = row.querySelector('td[data-label="Price"]') || row.querySelector('td:nth-child(4)');

    const nameText = nameCell ? nameCell.textContent.trim().toLowerCase() : '';
    const categoryText = categoryCell ? categoryCell.textContent.trim().toLowerCase() : '';
    const priceText = priceCell ? priceCell.textContent.trim().toLowerCase() : '';

    let match = false;
    if (filter.length === 1) {
      // single-character: only match names that start with the character
      match = nameText.startsWith(filter);
    } else {
      // multi-character: match name, category, price, or any text in the row
      match = nameText.includes(filter) || categoryText.includes(filter) || priceText.includes(filter) || row.textContent.toLowerCase().includes(filter);
    }

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
  newRow.style.cssText = "display:flex; gap:8px; align-items:center; margin-bottom:10px; padding:10px; border:1px solid #e0e0e0; border-radius:8px;";
  newRow.innerHTML = `
    <input type="text" name="sizes[]" placeholder="Size (e.g., 8ft, 10ft)" style="flex:2; min-width:120px;">
    <input type="number" name="size_stocks[]" placeholder="Stock" min="0" style="flex:1; min-width:100px;">
    <input type="number" step="0.01" name="size_prices[]" placeholder="Final Price" style="flex:1; min-width:100px;">
    <div style="flex:1.5; min-width:140px;">
      <input type="file" name="size_images[]" accept="image/*" style="font-size:0.85rem; width:100%; padding:8px;">
    </div>
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
    // populate base fields
    document.getElementById('edit-category').value = data.category;
    document.getElementById('edit-price').value = data.price;
      
      // Show current image
      const imagePreview = document.getElementById('current-image-preview');
      if (data.image) {
        imagePreview.innerHTML = `<img src="${data.image}" width="100" alt="Current Image">`;
      } else {
        imagePreview.innerHTML = '<small>No current image</small>';
      }
      
      // Populate variants (if any)
      const variantsContainer = document.getElementById('editVariantsContainer');
      variantsContainer.innerHTML = '';
      if (data.variants && data.variants.length > 0) {
        data.variants.forEach((variant, index) => {
          const variantRow = document.createElement('div');
          variantRow.className = 'size-variant-row';
          variantRow.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:10px; padding:10px; border:1px solid #e0e0e0; border-radius:8px; flex-wrap:wrap;';
          
          let imagePreview = '';
          if (variant.image && variant.image.trim() !== '') {
            imagePreview = `
              <div style="width:100%; display:flex; align-items:center; gap:10px; margin-top:8px;">
                <img src="${variant.image}" alt="${variant.size}" style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:2px solid #ddd;">
                <small style="color:#666;">Current image</small>
              </div>
            `;
          }
          
          variantRow.innerHTML = `
            <input type="hidden" name="edit_variant_ids[]" value="${variant.id}">
            <input type="text" name="edit_variant_sizes[]" value="${variant.size}" placeholder="Size" style="flex:2; min-width:120px;">
            <span style="flex:1; padding:8px; color:#666; min-width:100px;">Stock: ${variant.stock}</span>
            <input type="number" step="0.01" name="edit_variant_prices[]" value="${variant.final_price}" placeholder="Final Price" style="flex:1; min-width:100px;">
            <div style="flex:1.5; min-width:140px;">
              <input type="file" name="edit_variant_images_${variant.id}" accept="image/*" style="font-size:0.85rem; width:100%; padding:8px;" onchange="previewEditVariantImage(this, ${variant.id})">
            </div>
            <button type="button" class="remove-size-btn" style="margin-left:8px;" onclick="markRemoveExistingVariant(this, ${variant.id})">×</button>
            ${imagePreview}
          `;
          variantsContainer.appendChild(variantRow);
        });
      }

      // Toggle modal fields depending on whether product has variants
      try {
        const categoryGroup = document.getElementById('edit-category') ? document.getElementById('edit-category').closest('.form-group') : null;
        const editVariantsGroup = document.getElementById('editVariantsContainer') ? document.getElementById('editVariantsContainer').parentNode : null;
        const newVariantsGroup = document.getElementById('newVariantsContainer') ? document.getElementById('newVariantsContainer').parentNode : null;
        const imageGroup = document.getElementById('edit-image') ? document.getElementById('edit-image').closest('.form-group') : null;
        const priceGroup = document.getElementById('edit-price') ? document.getElementById('edit-price').closest('.form-group') : null;

        if (data.variants && data.variants.length > 0) {
          // Multi-size product: show category and variant sections, hide simple price editor
          if (categoryGroup) categoryGroup.style.display = '';
          if (editVariantsGroup) editVariantsGroup.style.display = '';
          if (newVariantsGroup) newVariantsGroup.style.display = '';
          if (imageGroup) imageGroup.style.display = '';
          if (priceGroup) {
            priceGroup.style.display = 'none';
            const priceInput = document.getElementById('edit-price'); if (priceInput) priceInput.required = false;
          }
        } else {
          // Single product: show only name + price + image, hide category/variants
          if (categoryGroup) categoryGroup.style.display = 'none';
          if (editVariantsGroup) editVariantsGroup.style.display = 'none';
          if (newVariantsGroup) newVariantsGroup.style.display = 'none';
          // keep image visible so single-product photos can be changed
          if (imageGroup) imageGroup.style.display = '';
          if (priceGroup) {
            priceGroup.style.display = '';
            const priceInput = document.getElementById('edit-price'); if (priceInput) priceInput.required = true;
          }
        }
      } catch (e) { /* ignore if DOM methods fail in older browsers */ }
      
      document.getElementById('editProductModal').style.display = 'block';
    })
    .catch(error => console.error('Error:', error));
}

function closeEditProductModal() {
  document.getElementById('editProductModal').style.display = 'none';
}

// Preview variant image when selected in edit modal
function previewEditVariantImage(input, variantId) {
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = function(e) {
      // Find the variant row and update or add preview
      const fileInput = input;
      const row = fileInput.closest('.size-variant-row');
      let existingPreview = row.querySelector('.variant-image-preview');
      
      if (!existingPreview) {
        const previewDiv = document.createElement('div');
        previewDiv.className = 'variant-image-preview';
        previewDiv.style.cssText = 'width:100%; display:flex; align-items:center; gap:10px; margin-top:8px;';
        row.appendChild(previewDiv);
        existingPreview = previewDiv;
      }
      
      existingPreview.innerHTML = `
        <img src="${e.target.result}" alt="Preview" style="width:60px; height:60px; object-fit:cover; border-radius:6px; border:2px solid #27ae60;">
        <small style="color:#27ae60; font-weight:600;">New image selected</small>
      `;
    };
    reader.readAsDataURL(input.files[0]);
  }
}

// Mark an existing variant for removal: add hidden input to the form and remove the row from DOM
function markRemoveExistingVariant(btn, variantId) {
  if (!confirm('Remove this variant? This will delete it when you save.')) return;
  const form = document.getElementById('editProductForm');
  const hidden = document.createElement('input');
  hidden.type = 'hidden';
  hidden.name = 'remove_variant_ids[]';
  hidden.value = variantId;
  form.appendChild(hidden);
  // remove the row from the UI
  btn.parentElement.remove();
}

function addNewVariantRow() {
  const container = document.getElementById('newVariantsContainer');
  const newRow = document.createElement('div');
  newRow.className = 'size-variant-row';
  newRow.style.cssText = 'display:flex; gap:8px; align-items:center; margin-bottom:10px; padding:10px; border:1px solid #e0e0e0; border-radius:8px;';
  newRow.innerHTML = `
    <input type="text" name="new_variant_sizes[]" placeholder="Size" style="flex:2; min-width:120px;">
    <input type="number" name="new_variant_stocks[]" placeholder="Stock" min="0" style="flex:1; min-width:100px;">
    <input type="number" step="0.01" name="new_variant_prices[]" placeholder="Final Price" style="flex:1; min-width:100px;">
    <div style="flex:1.5; min-width:140px;">
      <input type="file" name="new_variant_images[]" accept="image/*" style="font-size:0.85rem; width:100%; padding:8px;">
    </div>
    <button type="button" onclick="removeNewVariantRow(this)" class="remove-size-btn">×</button>
  `;
  container.appendChild(newRow);
}

function removeNewVariantRow(btn) {
  btn.parentElement.remove();
}

// Batch Stock Modal Functions
function openBatchStockModal() {
  document.getElementById('batchStockModal').style.display = 'block';
}

function closeBatchStockModal() {
  document.getElementById('batchStockModal').style.display = 'none';
}

// Toggle between single product and multi-size (variants) in Add Product modal
function toggleProductType(type) {
  const single = document.getElementById('singleProductFields');
  const multi = document.getElementById('multiProductFields');
  const singlePrice = document.getElementById('single-price');
  const singleStock = document.getElementById('single-stock');
  const basePrice = document.getElementById('base-price');
  const categoryField = document.getElementById('categoryField');
  if (type === 'single') {
    single.style.display = 'block';
    multi.style.display = 'none';
    if (categoryField) categoryField.style.display = 'none';
    if (singlePrice) singlePrice.required = true;
    if (singleStock) singleStock.required = true;
    if (basePrice) basePrice.required = false;
  } else {
    single.style.display = 'none';
    multi.style.display = 'block';
    if (categoryField) categoryField.style.display = 'block';
    if (singlePrice) singlePrice.required = false;
    if (singleStock) singleStock.required = false;
    if (basePrice) basePrice.required = true;
  }
}
</script>

<!-- Stock logs JS -->
<script>
function showStockLogs(productId, variantId) {
  let url = 'admin.php?get_stock_logs=1';
  if (variantId && variantId > 0) url += '&variant_id=' + variantId;
  else if (productId && productId > 0) url += '&product_id=' + productId;

  const content = document.getElementById('stockLogContent');
  content.innerHTML = '<p style="color:#666;">Loading...</p>';
  fetch(url)
    .then(res => res.json())
    .then(data => {
      if (!data || data.length === 0) {
        content.innerHTML = '<p>No logs found.</p>';
        document.getElementById('stockLogModal').style.display = 'block';
        return;
      }
      const list = document.createElement('div');
      list.style.display = 'flex';
      list.style.flexDirection = 'column';
      list.style.gap = '8px';

      data.forEach(log => {
        const item = document.createElement('div');
        item.style.padding = '10px';
        item.style.border = '1px solid rgba(0,0,0,0.06)';
        item.style.borderRadius = '6px';

        // Format the timestamp using the browser locale
        let createdStr = '';
        if (log.created_at) {
          try {
            const iso = log.created_at.replace(' ', 'T');
            const d = new Date(iso);
            if (!isNaN(d.getTime())) createdStr = d.toLocaleString();
            else createdStr = log.created_at;
          } catch (e) {
            createdStr = log.created_at;
          }
        }

        // Determine if this is an archive event (change_amount == 0)
        const changeNum = Number(log.change_amount);
        const changeHtml = (changeNum === 0)
          ? '<em style="color:#c0392b; font-weight:700;">Archived</em>'
          : 'Change: <strong>+' + changeNum + '</strong>';

        item.innerHTML = `
          <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
            <div>
              <strong>${log.product_name ? log.product_name : 'Product'}</strong>
              ${log.variant_size ? ' &middot; <em>' + log.variant_size + '</em>' : ''}
              <div style="font-size:0.95rem;color:#333;">${changeHtml}</div>
            </div>
            <div style="font-size:0.85rem;color:#666;white-space:nowrap;">${createdStr}</div>
          </div>
        `;
        list.appendChild(item);
      });

      content.innerHTML = '';
      content.appendChild(list);
      document.getElementById('stockLogModal').style.display = 'block';
    })
    .catch(err => {
      console.error(err);
      content.innerHTML = '<p>Error loading logs.</p>';
      document.getElementById('stockLogModal').style.display = 'block';
    });
}

function closeStockLogModal() {
  document.getElementById('stockLogModal').style.display = 'none';
}

// Close stock log modal when clicking outside
window.addEventListener('click', function(e) {
  const modal = document.getElementById('stockLogModal');
  if (!modal) return;
  if (e.target == modal) modal.style.display = 'none';
});
</script>

<!-- Floating POS Button -->
<a href="pos.php" class="pos-float-btn" title="Open POS">
  🛒 POS
</a>
</body>
<script>
// Poll server for online orders count and show toast when new orders arrive
(function(){
  let lastCount = Number(<?= $online_orders_count ?> || 0);
  const badge = document.getElementById('ordersBadge');

  // ack function used by Orders link
  window.ackOnlineOrders = function() {
    try {
      // prefer sendBeacon for navigation-safe ack
      if (navigator.sendBeacon) {
        navigator.sendBeacon('admin.php?ack_online_orders=1');
      } else {
        fetch('admin.php?ack_online_orders=1', { method: 'GET', keepalive: true }).catch(()=>{});
      }
      // hide badge immediately when clicking Orders
      if (badge) { badge.classList.add('hidden'); badge.textContent = ''; }
      lastCount = 0;
    } catch (e) { console.error(e); }
  };

  function showToast(msg) {
    const t = document.createElement('div');
    t.className = 'orders-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 500); }, 5000);
  }

  function updateBadge(count) {
    if (!badge) return;
    if (count > 0) {
      badge.textContent = count;
      badge.classList.remove('hidden');
    } else {
      badge.textContent = '';
      badge.classList.add('hidden');
    }
  }

  async function check() {
    try {
      const res = await fetch('admin.php?online_order_count=1', {cache: 'no-store'});
      if (!res.ok) return;
      const data = await res.json();
      const newCount = Number(data.new || 0);
      const totalCount = Number(data.total || 0);
      if (newCount > lastCount) {
        const diff = newCount - lastCount;
        showToast(`New online order${diff>1?'s':''}: ${diff}`);
        const original = document.title;
        document.title = `(${newCount}) ` + original;
        setTimeout(() => { document.title = original; }, 5000);
      }
      lastCount = newCount;
      updateBadge(newCount);
    } catch (e) {
      // ignore network errors silently
      console.error('Order poll failed', e);
    }
  }

  // initial badge state
  updateBadge(lastCount);
  // poll every 8 seconds
  setInterval(check, 8000);
  // check on page load shortly after DOM ready
  setTimeout(check, 1200);
})();
</script>
</html>
</body>
</html>
