<?php
session_start();
include 'db.php';

if (!isset($_SESSION['username'])) {
  header("Location: index.php");
  exit;
}

// Handle void transaction request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['void_transaction'])) {
    $transaction_id = intval($_POST['transaction_id']);
    $void_reason = $conn->real_escape_string($_POST['void_reason']);
    
    $conn->begin_transaction();
    
    try {
        // Get transaction items to restore stock
        $items_query = $conn->prepare("SELECT ti.product_id, ti.quantity FROM transaction_items ti WHERE ti.transaction_id = ?");
        $items_query->bind_param("i", $transaction_id);
        $items_query->execute();
        $items_result = $items_query->get_result();
        
        $stock_stmt_main = $conn->prepare("UPDATE products_ko SET stock = stock + ? WHERE id = ?");
        $stock_stmt_variant = $conn->prepare("UPDATE product_variants SET stock = stock + ? WHERE product_id = ?");
        
        while ($item = $items_result->fetch_assoc()) {
            $product_id = $item['product_id'];
            $qty = $item['quantity'];
            
            // Restore main product stock
            $stock_stmt_main->bind_param("ii", $qty, $product_id);
            $stock_stmt_main->execute();
            
            // Also restore variant stock if exists
            $stock_stmt_variant->bind_param("ii", $qty, $product_id);
            $stock_stmt_variant->execute();
        }
        
        $items_query->close();
        $stock_stmt_main->close();
        $stock_stmt_variant->close();
        
        // Mark transaction as voided
        $void_stmt = $conn->prepare("UPDATE transactions SET source = 'voided', transaction_date = NOW() WHERE transaction_id = ?");
        $void_stmt->bind_param("i", $transaction_id);
        $void_stmt->execute();
        $void_stmt->close();
        
        $conn->commit();
        header("Location: onsite_transaction.php?voided=1");
        exit();
        
    } catch (Exception $e) {
        $conn->rollback();
        die("Void transaction failed: " . htmlspecialchars($e->getMessage()));
    }
}

// If POSTed from pos.php, process & insert transaction + items
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transaction_data'])) {
    $transaction_json = $_POST['transaction_data'];
    $data = json_decode($transaction_json, true);

    if ($data && isset($data['total']) && isset($data['items']) && is_array($data['items'])) {
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : $_SESSION['username'];
        $total_amount = floatval($data['total']);
        $source = 'pos';

        // Start database transaction
        $conn->begin_transaction();

        try {
            // Insert into transactions table
            $stmt = $conn->prepare("INSERT INTO transactions (user_id, total_amount, transaction_date, source) VALUES (?, ?, NOW(), ?)");
            $stmt->bind_param("sds", $user_id, $total_amount, $source);
            $stmt->execute();
            $transaction_id = $stmt->insert_id;
            $stmt->close();

            $item_stmt = $conn->prepare("INSERT INTO transaction_items (transaction_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stock_stmt_main = $conn->prepare("UPDATE products_ko SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $stock_stmt_variant = $conn->prepare("UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?");

            foreach ($data['items'] as $it) {
                $id_string = $it['id'];
                $qty = intval($it['qty']);
                $price = floatval($it['price']);

                // Check if this is a variant item (format: "productId_variantId")
                if (strpos($id_string, '_') !== false) {
                    // Variant product
                    list($product_id, $variant_id) = explode('_', $id_string);
                    $product_id = intval($product_id);
                    $variant_id = intval($variant_id);

                    // Insert transaction item with product_id
                    $item_stmt->bind_param("iiid", $transaction_id, $product_id, $qty, $price);
                    $item_stmt->execute();

                    // Update variant stock
                    $stock_stmt_variant->bind_param("iii", $qty, $variant_id, $qty);
                    $stock_stmt_variant->execute();

                    if ($stock_stmt_variant->affected_rows === 0) {
                        throw new Exception("Insufficient stock for variant ID: $variant_id");
                    }
                } else {
                    // Non-variant product
                    $product_id = intval($id_string);

                    // Insert transaction item
                    $item_stmt->bind_param("iiid", $transaction_id, $product_id, $qty, $price);
                    $item_stmt->execute();

                    // Update main product stock
                    $stock_stmt_main->bind_param("iii", $qty, $product_id, $qty);
                    $stock_stmt_main->execute();

                    if ($stock_stmt_main->affected_rows === 0) {
                        throw new Exception("Insufficient stock for product ID: $product_id");
                    }
                }
            }

            $item_stmt->close();
            $stock_stmt_main->close();
            $stock_stmt_variant->close();

            $conn->commit();

            // Return success without redirect (for AJAX)
            http_response_code(200);
            echo json_encode(['success' => true, 'transaction_id' => $transaction_id]);
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            exit();
        }
    } else {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid transaction data']);
        exit();
    }
}

// Display transactions (onsite/pos)
// Filter params (period: day|month|year|all)
$records_per_page = 10;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $records_per_page;

$period = isset($_GET['period']) ? $_GET['period'] : 'all';
$filter_date = isset($_GET['date']) ? $conn->real_escape_string($_GET['date']) : '';
$filter_month = isset($_GET['month']) ? $conn->real_escape_string($_GET['month']) : '';
$filter_year = isset($_GET['year']) ? $conn->real_escape_string($_GET['year']) : '';

// Build WHERE clause for filtered queries (limit to onsite POS source)
$where_base = "WHERE source='pos'";
if ($period === 'day' && $filter_date) {
  $where_filter = " AND DATE(transaction_date) = '" . $filter_date . "'";
} elseif ($period === 'month' && $filter_month) {
  $parts = explode('-', $filter_month);
  if (count($parts) === 2) {
    $y = intval($parts[0]);
    $m = intval($parts[1]);
    $where_filter = " AND YEAR(transaction_date) = $y AND MONTH(transaction_date) = $m";
  } else {
    $where_filter = '';
  }
} elseif ($period === 'year' && $filter_year) {
  $y = intval($filter_year);
  $where_filter = " AND YEAR(transaction_date) = $y";
} else {
  $where_filter = '';
}

$where = $where_base . $where_filter;

// Count total transactions (with filters)
$total_result = $conn->query("SELECT COUNT(*) AS total FROM transactions " . $where);
$total_records = ($row = $total_result->fetch_assoc()) ? $row['total'] : 0;
$total_pages = max(1, ceil($total_records / $records_per_page));

// Get paginated transactions (with filters)
$sql = "SELECT * FROM transactions " . $where . " ORDER BY transaction_date DESC LIMIT ? OFFSET ?";
$transactions_query = $conn->prepare($sql);
$transactions_query->bind_param("ii", $records_per_page, $offset);
$transactions_query->execute();
$transactions_result = $transactions_query->get_result();

// Monthly sales summary
$monthly_sales_query = "
  SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month, SUM(total_amount) AS total_sales
  FROM transactions
  " . $where . "
  GROUP BY month
  ORDER BY month ASC
";
$monthly_sales_result = $conn->query($monthly_sales_query);

$months = [];
$sales = [];
$total_revenue = 0;
while ($row = $monthly_sales_result->fetch_assoc()) {
  $months[] = $row['month'];
  $sales[] = $row['total_sales'];
  $total_revenue += $row['total_sales'];
}

// --- Daily sales data for chart (last 30 days or filtered period) ---
$daily_labels = [];
$daily_values = [];
if ($period === 'month' && $filter_month) {
  $parts = explode('-', $filter_month);
  $y = intval($parts[0]);
  $m = intval($parts[1]);
  $daily_q = "SELECT DATE(transaction_date) AS day, COALESCE(SUM(total_amount),0) AS total FROM transactions " . $where . " AND YEAR(transaction_date)=$y AND MONTH(transaction_date)=$m GROUP BY day ORDER BY day ASC";
  $daily_res = $conn->query($daily_q);
  $daily_map = [];
  if ($daily_res) while ($r = $daily_res->fetch_assoc()) $daily_map[$r['day']] = (float)$r['total'];
  $days_in_month = cal_days_in_month(CAL_GREGORIAN, $m, $y);
  for ($d = 1; $d <= $days_in_month; $d++) {
    $date_str = sprintf('%04d-%02d-%02d', $y, $m, $d);
    $daily_labels[] = date('M j', strtotime($date_str));
    $daily_values[] = isset($daily_map[$date_str]) ? $daily_map[$date_str] : 0;
  }
} elseif ($period === 'year' && $filter_year) {
  $y = intval($filter_year);
  $daily_q = "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS day, COALESCE(SUM(total_amount),0) AS total FROM transactions " . $where . " AND YEAR(transaction_date)=$y GROUP BY day ORDER BY day ASC";
  $daily_res = $conn->query($daily_q);
  $daily_map = [];
  if ($daily_res) while ($r = $daily_res->fetch_assoc()) $daily_map[$r['day']] = (float)$r['total'];
  for ($m = 1; $m <= 12; $m++) {
    $label = date('M', strtotime(sprintf('%04d-%02d-01', $y, $m)));
    $key = sprintf('%04d-%02d', $y, $m);
    $daily_labels[] = $label;
    $daily_values[] = isset($daily_map[$key]) ? $daily_map[$key] : 0;
  }
} else {
  $daily_q = "SELECT DATE(transaction_date) AS day, COALESCE(SUM(total_amount),0) AS total FROM transactions " . $where . " AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY day ORDER BY day ASC";
  $daily_res = $conn->query($daily_q);
  $daily_map = [];
  if ($daily_res) while ($r = $daily_res->fetch_assoc()) $daily_map[$r['day']] = (float)$r['total'];
  for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $daily_labels[] = date('M j', strtotime($d));
    $daily_values[] = isset($daily_map[$d]) ? $daily_map[$d] : 0;
  }
}

// Most purchased product (onsite) — use products_ko.name as fallback when ti.product_name is empty
$most_product = ['product_name' => '—', 'total_qty' => 0];
$mp_q = "SELECT COALESCE(ti.product_name, p.name) AS product_name, SUM(ti.quantity) AS total_qty
  FROM transaction_items ti
  JOIN transactions t ON ti.transaction_id = t.transaction_id
  LEFT JOIN products_ko p ON ti.product_id = p.id
  " . $where . "
  GROUP BY ti.product_id, product_name
  ORDER BY total_qty DESC
  LIMIT 1";
$mp_res = $conn->query($mp_q);
if ($mp_res && $mp_res->num_rows > 0) $most_product = $mp_res->fetch_assoc();

// Aggregated purchased products for modal — same approach, ensure name comes from products table when needed
$purchased_products = [];
$pp_q = "SELECT COALESCE(ti.product_name, p.name) AS product_name, SUM(ti.quantity) AS total_qty
  FROM transaction_items ti
  JOIN transactions t ON ti.transaction_id = t.transaction_id
  LEFT JOIN products_ko p ON ti.product_id = p.id
  " . $where . "
  GROUP BY ti.product_id, product_name
  ORDER BY total_qty DESC";
$pp_res = $conn->query($pp_q);
if ($pp_res) while ($r = $pp_res->fetch_assoc()) $purchased_products[] = $r;

// Filtered total (reflects the selected period)
$filtered_total_q = "SELECT COALESCE(SUM(total_amount),0) AS total FROM transactions " . $where;
$filtered_total_res = $conn->query($filtered_total_q);
$filtered_total = ($filtered_total_res && ($row = $filtered_total_res->fetch_assoc())) ? (float)$row['total'] : 0.0;

// Sales this month (onsite)
$sales_month_q = "SELECT COALESCE(SUM(total_amount),0) AS total FROM transactions WHERE source='pos' AND YEAR(transaction_date)=YEAR(CURDATE()) AND MONTH(transaction_date)=MONTH(CURDATE())";
$sales_month_res = $conn->query($sales_month_q);
$sales_month = ($sales_month_res && ($r = $sales_month_res->fetch_assoc())) ? (float)$r['total'] : 0.0;

// Sales this year (onsite)
$sales_year_q = "SELECT COALESCE(SUM(total_amount),0) AS total FROM transactions WHERE source='pos' AND YEAR(transaction_date)=YEAR(CURDATE())";
$sales_year_res = $conn->query($sales_year_q);
$sales_year = ($sales_year_res && ($r2 = $sales_year_res->fetch_assoc())) ? (float)$r2['total'] : 0.0;

// All filtered transactions (for PDF export)
$all_transactions = [];
$all_q = $conn->query("SELECT * FROM transactions " . $where . " ORDER BY transaction_date DESC");
if ($all_q) while ($ar = $all_q->fetch_assoc()) $all_transactions[] = $ar;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Onsite Transactions</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="ot.css">
  <style>
/* ==============================
   CSS VARIABLES & BASE STYLES
============================== */
:root {
  --primary-blue: #004080;
  --secondary-blue: #0066cc;
  --accent-gold: #ffcc00;
  --dark-gold: #e6b800;
  --text-dark: #333;
  --text-light: #555;
  --bg-light: #f9f9f9;
  --shadow-light: 0 4px 15px rgba(0, 64, 128, 0.1);
  --shadow-medium: 0 8px 25px rgba(0, 64, 128, 0.15);
  --shadow-heavy: 0 12px 35px rgba(0, 64, 128, 0.2);
  --transition: all 0.3s ease;
  --border-radius: 12px;
  --success-green: #27ae60;
  --danger-red: #e74c3c;
}

* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
  font-family: 'Poppins', sans-serif;
}

body {
  line-height: 1.6;
  color: var(--text-dark);
  background: linear-gradient(135deg, #f8faff 0%, #e8f2ff 100%);
  min-height: 100vh;
  overflow-x: hidden;
}

  .container {
  max-width: 97%;
  width: calc(100% - 120px);
  margin: 30px auto;
  background: linear-gradient(145deg, #ffffff 0%, #f8faff 100%);
  padding: 30px;
  border-radius: var(--border-radius);
  box-shadow: var(--shadow-heavy);
  min-height: 80vh;
  display: block;
  border: 1px solid rgba(255, 255, 255, 0.8);
  position: relative;
}


.container::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: linear-gradient(145deg, transparent 0%, rgba(255, 204, 0, 0.02) 100%);
  pointer-events: none;
  border-radius: var(--border-radius);
}

/* ==============================
   ENHANCED TYPOGRAPHY
============================== */
h2, h3 {
  margin-bottom: 20px;
  color: var(--primary-blue);
  font-weight: 700;
  text-shadow: 1px 1px 2px rgba(0, 64, 128, 0.1);
  background: linear-gradient(45deg, var(--primary-blue), var(--secondary-blue));
  -webkit-background-clip: text;
  background-clip: text;
  -webkit-text-fill-color: transparent;
  position: relative;
}

/* Center the main page heading inside transactions section */
#transactionsPage h1 {
  text-align: center;
  margin: 0;
  color: var(--primary-blue);
  font-weight: 700;
  font-size: 2rem;
  letter-spacing: 0.2px;
}

/* Page header with icon */
.page-header { display:flex; align-items:center; justify-content:center; gap:12px; margin-bottom:18px; }
.page-icon { width:40px; height:40px; object-fit:cover; border-radius:8px; box-shadow: 0 6px 18px rgba(0,64,128,0.12); }

h2 {
  font-size: 2rem;
}

h2::after {
  content: '';
  position: absolute;
  bottom: -8px;
  left: 0;
  width: 60px;
  height: 4px;
  background: linear-gradient(45deg, var(--accent-gold), #ffd700);
  border-radius: 2px;
}

h3 {
  font-size: 1.5rem;
}

.back-btn {
  background: linear-gradient(45deg, var(--secondary-blue), #0080ff);
  color: white;
  padding: 12px 20px;
  border-radius: 25px;
  display: inline-block;
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 600;
  margin-bottom: 25px;
  transition: var(--transition);
  box-shadow: 0 4px 15px rgba(0, 102, 204, 0.3);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  position: relative;
  overflow: hidden;
}

.back-btn::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 204, 0, 0.3), transparent);
  transition: left 0.5s;
}

.back-btn:hover {
  background: linear-gradient(45deg, #0080ff, var(--secondary-blue));
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(0, 102, 204, 0.4);
  text-decoration: none;
  color: white;
}

.back-btn:hover::before {
  left: 100%;
}

.filter-btn {
  background: linear-gradient(45deg, var(--secondary-blue), #0080ff);
  color: white;
  padding: 12px 20px;
  border-radius: 25px;
  display: inline-block;
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 600;
  margin-bottom: 25px;
  transition: var(--transition);
  box-shadow: 0 4px 15px rgba(0, 102, 204, 0.3);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  position: relative;
  overflow: hidden;
}

.filter-btn::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 204, 0, 0.3), transparent);
  transition: left 0.5s;
}

.filter-btn:hover {
  background: linear-gradient(45deg, #0080ff, var(--secondary-blue));
  transform: translateY(-2px);
  box-shadow: 0 8px 25px rgba(0, 102, 204, 0.4);
  text-decoration: none;
  color: white;
}

.filter-btn:hover::before {
  left: 100%;
}


/* Filter form and inputs */
.filter-form { display:flex; gap:12px; align-items:center; margin-bottom:12px; flex-wrap:wrap; }
.filter-label { font-weight:600; color:var(--primary-blue); }
.filter-input { padding:8px 10px; border-radius:8px; border:1px solid #e0e6f0; }
.filter-input[type="number"] { width:100px; }
.filter-btn { padding:8px 12px; border-radius:12px; min-width:120px; display:inline-block; text-align:center; font-weight:600; background:linear-gradient(45deg,#0077dd,#0090ff); color:#fff; border:none; cursor:pointer; margin-bottom:0; }

/* KPI cards - enhanced layout to match system style */
.kpi-cards {
  display: flex;
  gap: 18px;
  margin: 18px 0 16px;
  flex-wrap: wrap;
  align-items: stretch;
}
.kpi-card {
  flex: 1 1 200px;
  background: #fff;
  padding: 18px 20px;
  border-radius: 12px;
  box-shadow: var(--shadow-light);
  text-align: left;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 100px;
}
.kpi-card.wide { flex: 1 1 260px; }
.kpi-left { display: flex; flex-direction: column; gap: 6px; }
.kpi-title { font-size: 13px; color: #6b7280; margin: 0; }
.kpi-value { font-weight: 800; font-size: 15pxpx; margin: 0; color: var(--text-dark); }
.kpi-value.large { font-size: 20px; }
.kpi-value.success { color: var(--success-green); }
.kpi-value.primary { color: var(--primary-blue); }

/* Right area for small helper text / action */
.kpi-right { display: flex; flex-direction: column; align-items: flex-end; gap: 8px; }
.kpi-card .kpi-action {
  display: inline-block;
  padding: 6px 10px;
  font-size: 0.85rem;
  border-radius: 8px;
  background: transparent;
  color: var(--primary-blue);
  border: 1px solid rgba(0,64,128,0.06);
  cursor: pointer;
}
.kpi-card .kpi-action:hover { background: var(--secondary-blue); color: #fff; border-color: rgba(0,64,128,0.18); box-shadow: 0 6px 18px rgba(0,102,204,0.12); }

/* Make KPI cards stack nicely on narrow screens */
@media (max-width: 720px) {
  .kpi-card { flex-direction: column; align-items: flex-start; min-height: auto; }
  .kpi-right { align-items: flex-start; width: 100%; }
  .kpi-card .kpi-action { align-self: flex-start; }
}
/* Daily chart container - make chart a bit smaller and centered like reference */
  .daily-chart { margin:18px auto 24px; background:linear-gradient(145deg,#fff,#f8fbff); padding:18px 22px; border-radius:12px; box-shadow:var(--shadow-light); max-width:1100px; }
  .daily-chart h2 { margin:0 0 8px; font-size:1rem; color:var(--primary-blue); text-align:center; position:relative; }
  .daily-chart h2::after { content: ''; position: absolute; bottom: -8px; left: 50%; transform: translateX(-50%); width: 60px; height: 6px; background: var(--accent-gold); border-radius: 4px; }
  .daily-chart .chart-inner { display:flex; justify-content:center; }
  .daily-chart canvas { width:100%; max-width:900px; margin:12px auto 0; display:block; border-radius:8px; box-shadow: 0 8px 20px rgba(0,0,0,0.06); }

/* Daily sales canvas sizing adjusted to be smaller than before and centered */
#dailySalesChart { width:100% !important; height:260px !important; max-width:900px; display:block; margin: 0 auto; }

/* Actions wrapper for filter buttons */
.filter-actions { display:inline-flex; gap:8px; align-items:center; }

/* Normalize filter controls: ensure anchors and buttons match size */
.filter-actions .filter-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 12px 20px !important;
  min-width: 120px;
  height: 30px;
  box-sizing: border-box;
  text-decoration: none;
}

/* Make filter inputs match button height for alignment */
.filter-form .filter-input,
.filter-form select.filter-input {
  height: 40px;
  display: inline-flex;
  align-items: center;
}

/* Page info */
.page-info { color:var(--text-dark); margin:0 12px; }
/* Modal styles (overlay and box for items/void) */
  .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); align-items: center; justify-content: center; z-index: 9999; -webkit-backdrop-filter: blur(6px); backdrop-filter: blur(6px); transition: backdrop-filter 0.18s ease, background 0.18s ease; }
.modal-overlay .modal-box { background: #fff; border-radius: 10px; padding: 18px; max-width: 92%; width: 640px; box-shadow: 0 12px 30px rgba(0,0,0,0.15); }
.modal-box .modal-footer { margin-top: 12px; text-align: right; }
.modal-box .close-btn { background: #e9eef7; border: none; color: #004080; padding: 8px 12px; border-radius: 8px; cursor: pointer; }

#productsModal > div { position: relative; }
#productsModal #productsModalClose { position: absolute; top: 12px; right: 12px; z-index: 11000; background: transparent; border: none; font-size: 22px; color: #0b4777; cursor: pointer; }

#productsModal { padding: 18px; }
#productsModal .modal-box {
  width: 90%;
  max-width: 1100px;
  max-height: 84vh;
  overflow: hidden;
  padding: 22px 22px 16px 22px;
  border-radius: 12px;
  box-shadow: 0 20px 40px rgba(6,30,60,0.25);
  position: relative;
}
#productsModal .modal-box h3 {
  margin: 0 0 6px 0;
  color: #0b4777;
  font-size: 20px;
  font-weight: 700;
}
#productsModal .modal-box .modal-subtitle {
  color: #6b7280;
  margin: 0 0 14px 0;
  font-size: 14px;
}
#productsModal .modal-content { padding: 0; }
#productsModal .table-wrapper { max-height: 64vh; overflow: auto; border-radius: 8px; background: linear-gradient(180deg,#fff,#fbfdff); border: 1px solid #eef6fb; }
#productsModal table { width: 100%; border-collapse: collapse; }
#productsModal thead th { background: linear-gradient(180deg,#eff7ff,#f6fbff); color: #0b4777; text-align: left; padding: 12px 16px; font-size: 13px; letter-spacing: 0.4px; border-bottom: 1px solid #e6f0f8; }
#productsModal tbody td { padding: 14px 16px; border-bottom: 1px solid #f1f7fb; vertical-align: middle; }
#productsModal tbody tr:nth-child(odd) td { background: linear-gradient(180deg, rgba(246,250,255,0.6), rgba(255,255,255,0.6)); }
#productsModal tbody td:nth-child(2) { text-align: right; color: #0b4777; font-weight: 600; }
#productsModal .modal-footer { padding: 12px 0 0 0; text-align: right; background: transparent; }
#productsModal::-webkit-scrollbar, #productsModal .table-wrapper::-webkit-scrollbar { width: 12px; }
#productsModal::-webkit-scrollbar-thumb, #productsModal .table-wrapper::-webkit-scrollbar-thumb { background: #cddbe9; border-radius: 8px; border: 3px solid rgba(255,255,255,0.6); }
#productsModal #productsModalClose { font-size: 20px; color: #1e3c72; }

#productsModal #productsModalClose,
#productsModal .modal-footer .close-btn {
  background: linear-gradient(180deg, #ff6b6b, #e74c3c);
  color: #fff;
  border: none;
  padding: 8px 14px;
  border-radius: 28px;
  font-weight: 700;
  box-shadow: 0 8px 28px rgba(231,76,60,0.18), 0 0 0 6px rgba(231,76,60,0.06);
  cursor: pointer;
  transition: transform .12s ease, box-shadow .12s ease, opacity .12s ease;
}

#productsModal #productsModalClose:hover,
#productsModal .modal-footer .close-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 14px 40px rgba(231,76,60,0.22), 0 0 0 8px rgba(231,76,60,0.06);
}

#productsModal #productsModalClose:focus,
#productsModal .modal-footer .close-btn:focus {
  outline: 3px solid rgba(231,76,60,0.14);
  outline-offset: 3px;
}
</style>
</head>
<body>

<div class="container">
  <div class="top-buttons">
    <a href="pos.php" class="back-btn">← Back to POS</a>
  </div>

  <div id="transactionsPage">
    <h1>🛒 Onsite POS Transactions</h1>

    <!-- FILTER FORM -->
    <form method="GET" class="filter-form">
      <label class="filter-label">Filter:</label>
      <select id="periodSelect" name="period" class="filter-input">
        <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>All</option>
        <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>Day</option>
        <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Month</option>
        <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Year</option>
      </select>
      <input type="date" id="dateInput" name="date" value="<?= htmlspecialchars($filter_date) ?>" class="filter-input" style="display: <?= $period === 'day' ? 'inline-block' : 'none' ?>;">
      <input type="month" id="monthInput" name="month" value="<?= htmlspecialchars($filter_month) ?>" class="filter-input" style="display: <?= $period === 'month' ? 'inline-block' : 'none' ?>;">
      <input type="number" id="yearInput" name="year" min="2000" max="2100" placeholder="YYYY" value="<?= htmlspecialchars($filter_year) ?>" class="filter-input" style="display: <?= $period === 'year' ? 'inline-block' : 'none' ?>;">

      <div class="filter-actions">
        <button type="submit" class="filter-btn">Apply</button>
        <a href="onsite_transaction.php" class="back-btn filter-btn">Reset</a>
      </div>
    </form>

    <script>
      document.getElementById('periodSelect').addEventListener('change', function(){
        const v = this.value;
        document.getElementById('dateInput').style.display = v === 'day' ? 'inline-block' : 'none';
        document.getElementById('monthInput').style.display = v === 'month' ? 'inline-block' : 'none';
        document.getElementById('yearInput').style.display = v === 'year' ? 'inline-block' : 'none';
      });
    </script>

    <!-- KPI CARDS -->
    <div class="kpi-cards">
      <div class="kpi-card wide">
        <div class="kpi-title">Most Purchased</div>
        <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:6px;">
          <div class="kpi-value large"><?= htmlspecialchars($most_product['product_name']) ?></div>
          <button id="viewProductsBtn" class="kpi-action">View All</button>
        </div>
      </div>

      <div class="kpi-card">
        <div class="kpi-title">Total (Filtered)</div>
        <div class="kpi-value success">₱<?= number_format($filtered_total,2) ?></div>
      </div>

      <div class="kpi-card">
        <div class="kpi-title">Sales This Month</div>
        <div class="kpi-value primary">₱<?= number_format($sales_month,2) ?></div>
      </div>

      <div class="kpi-card">
        <div class="kpi-title">Sales This Year</div>
        <div class="kpi-value" style="color:#004080;">₱<?= number_format($sales_year,2) ?></div>
      </div>
    </div>

    <!-- DAILY SALES CHART -->
    <div class="daily-chart">
      <h2>Last 30 Days — Daily Sales</h2>
      <div class="chart-inner" style="display:flex; justify-content:center;">
        <canvas id="dailySalesChart"></canvas>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Transaction ID</th>
          <th>User</th>
          <th>Total Amount (₱)</th>
          <th>Date</th>
          <th>Items</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($t = $transactions_result->fetch_assoc()) { ?>
          <?php
            $items = [];
            $item_query = $conn->prepare("SELECT ti.quantity, ti.price, p.name, p.category FROM transaction_items ti LEFT JOIN products_ko p ON ti.product_id = p.id WHERE ti.transaction_id = ?");
            $item_query->bind_param("i", $t['transaction_id']);
            $item_query->execute();
            $res = $item_query->get_result();
            while($i = $res->fetch_assoc()){
              $items[] = [
                "name" => $i['name'],
                "category" => $i['category'],
                "quantity" => $i['quantity'],
                "price" => $i['price']
              ];
            }
            $item_query->close();
          ?>
          <tr>
            <td><?= htmlspecialchars($t['transaction_id']) ?></td>
            <td><?= htmlspecialchars($t['user_id']) ?></td>
            <td>₱<?= number_format($t['total_amount'],2) ?></td>
            <td><?= htmlspecialchars($t['transaction_date']) ?></td>
            <td><button class="view-items" data-items='<?= json_encode($items) ?>'>View Items</button></td>
            <td>
              <button class="void-btn" onclick="openVoidModal(<?= $t['transaction_id'] ?>)">Void</button>
            </td>
          </tr>
        <?php } ?>
      </tbody>
    </table>

    <?php
      // Build pagination links that preserve filters and scroll to transactions section
      $params = $_GET;
      if (!isset($params['page'])) $params['page'] = $current_page;
      $prev_href = '';
      $next_href = '';
      if ($current_page > 1) { $params['page'] = $current_page - 1; $prev_href = '?' . http_build_query($params) . '#transactionsPage'; }
      if ($current_page < $total_pages) { $params['page'] = $current_page + 1; $next_href = '?' . http_build_query($params) . '#transactionsPage'; }
    ?>

    <div class="pagination">
      <?php if ($prev_href): ?><a href="<?= htmlspecialchars($prev_href) ?>" class="page-btn">← Previous</a><?php endif; ?>
      <span class="page-info">Page <?= $current_page ?> of <?= $total_pages ?> (Total: <?= $total_records ?> records)</span>
      <?php if ($next_href): ?><a href="<?= htmlspecialchars($next_href) ?>" class="page-btn">Next →</a><?php endif; ?>
    </div>

  </div>

</div>

<div id="itemsModal" class="modal-overlay">
  <div class="modal-box">
      <h3 class="modal-header">Purchased Items</h3>
    <div class="modal-content">
      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Product</th>
              <th>Category</th>
              <th>Qty</th>
              <th>Price</th>
              <th>Subtotal</th>
            </tr>
          </thead>
          <tbody id="modalItemsBody"></tbody>
        </table>
      </div>
    </div>
    <div class="modal-footer">
      <button class="close-btn footer-close" onclick="closeModal()">Close</button>
    </div>
  </div>
</div>

<!-- VOID MODAL -->
<div id="voidModal" class="modal-overlay" aria-hidden="true">
  <div class="modal-box" style="max-width: 550px;">
    <h3>Void Transaction</h3>
    <div class="modal-content void-modal-content">
      <p class="void-warning-text">
        Are you sure you want to void this transaction?
      </p>
      <p class="void-description">
        This will restore the stock and mark the transaction as voided.
      </p>
      <form id="voidForm" method="POST" action="onsite_transaction.php">
        <input type="hidden" name="void_transaction" value="1">
        <input type="hidden" name="transaction_id" id="voidTransactionId">
        <div class="void-reason-container">
          <label class="void-label">Reason for voiding:</label>
          <textarea name="void_reason" required class="void-textarea" placeholder="Enter reason for voiding this transaction..." onfocus="this.style.borderColor='#1e3c72'" onblur="this.style.borderColor='#e0e0e0'"></textarea>
        </div>
        <div class="void-button-container">
          <button type="submit" class="void-confirm-btn">Confirm Void</button>
          <button type="button" class="close-btn" onclick="closeVoidModal()">Cancel</button>
        </div>
      </form>
    </div>
  </div>
</div>

    <!-- PRODUCTS MODAL -->
    <div id="productsModal" class="modal-overlay">
      <div class="modal-box">
        <h3>Most Purchased Products</h3>
        <div class="modal-content">
          <div class="table-wrapper">
            <table>
              <thead>
                <tr>
                  <th>PRODUCT NAME</th>
                  <th style="width:120px;">QUANTITY</th>
                </tr>
              </thead>
              <tbody id="productsModalBody"></tbody>
            </table>
          </div>
        </div>
        <div class="modal-footer">
          <button class="close-btn" onclick="document.getElementById('productsModal').style.display='none'">Close</button>
        </div>
      </div>
    </div>

    <script>
// view items modal
document.querySelectorAll(".view-items").forEach(btn => {
  btn.addEventListener("click", function() {
    let items = JSON.parse(this.dataset.items);
    let body = document.getElementById("modalItemsBody");
    body.innerHTML = "";
    items.forEach(it => {
      body.innerHTML += `
        <tr>
          <td>${it.name}</td>
          <td>${it.category || '-'}</td>
          <td>${it.quantity}</td>
          <td>₱${parseFloat(it.price).toFixed(2)}</td>
          <td>₱${(it.quantity * it.price).toFixed(2)}</td>
        </tr>`;
    });
    document.getElementById("itemsModal").style.display = "flex";
  });
});
function closeModal(){ document.getElementById("itemsModal").style.display = "none"; }

// Make items modal dismissible by background click, close button, and Escape key
(function(){
  const itemsModal = document.getElementById('itemsModal');
  if (!itemsModal) return;

  // Close when clicking outside the modal box
  itemsModal.addEventListener('click', function(e){
    if (e.target === itemsModal) closeModal();
  });

  // Wire close buttons inside the items modal
  const closeBtns = itemsModal.querySelectorAll('.close-btn');
  closeBtns.forEach(b => b.addEventListener('click', function(ev){ ev.preventDefault(); closeModal(); }));

  // Close on Escape when modal is open
  document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape' && itemsModal.style.display === 'flex') closeModal();
  });
})();

function openVoidModal(transactionId) {
  document.getElementById('voidTransactionId').value = transactionId;
  document.getElementById('voidModal').style.display = 'flex';
}

function closeVoidModal() {
  document.getElementById('voidModal').style.display = 'none';
  document.getElementById('voidForm').reset();
}

// Page init: chart, modals, download
document.addEventListener('DOMContentLoaded', function() {
  // Daily chart (customer_orders style)
  const dailyEl = document.getElementById('dailySalesChart');
  const labels = <?= json_encode($daily_labels) ?>;
  const values = <?= json_encode($daily_values) ?>;

  if (dailyEl) {
    const barColors = labels.map((_, i) => `hsl(${(i * 30) % 360} 72% 55%)`);
    const borderColors = labels.map((_, i) => `hsl(${(i * 30) % 360} 70% 40%)`);
    new Chart(dailyEl.getContext('2d'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'Daily Sales (₱)',
          data: values,
          backgroundColor: barColors,
          borderColor: borderColors,
          borderWidth: 1,
          maxBarThickness: 48,
          barPercentage: 0.7,
          categoryPercentage: 0.9
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'nearest', intersect: false },
        scales: { y: { beginAtZero: true } },
        plugins: {
          legend: { display: false },
          tooltip: {
            enabled: true,
            callbacks: {
              label: function(context) {
                var v = context.parsed && context.parsed.y !== undefined ? context.parsed.y : context.parsed;
                return '₱' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2 });
              }
            }
          }
        }
      }
    });
  }

  // Scroll to transactionsPage if anchor present
  if (location.hash === '#transactionsPage') {
    setTimeout(() => {
      const el = document.getElementById('transactionsPage');
      if (el) {
        const headerOffset = 30;
        const top = el.getBoundingClientRect().top + window.scrollY - headerOffset;
        window.scrollTo({ top: top, behavior: 'smooth' });
      }
    }, 60);
  }

  // Purchased products modal (populate)
  const purchasedProducts = <?= json_encode($purchased_products) ?>;
  const modal = document.getElementById('productsModal');
  const modalClose = document.getElementById('productsModalClose');
  const viewBtn = document.getElementById('viewProductsBtn');
  const productsTbody = document.querySelector('#productsModalBody');

  function openProductsModal() {
    productsTbody.innerHTML = '';
    if (!purchasedProducts || purchasedProducts.length === 0) {
      productsTbody.innerHTML = '<tr><td colspan="2" style="text-align:center; color:#666; padding:14px;">No products found for the current filter.</td></tr>';
    } else {
      purchasedProducts.forEach(p => {
        const tr = document.createElement('tr');
        const nameTd = document.createElement('td'); nameTd.textContent = p.product_name;
        const qtyTd = document.createElement('td'); qtyTd.style.textAlign = 'right'; qtyTd.textContent = Number(p.total_qty).toLocaleString();
        tr.appendChild(nameTd); tr.appendChild(qtyTd); productsTbody.appendChild(tr);
      });
    }
    if (modal) modal.style.display = 'flex';
  }
  function closeProductsModal() { if (modal) modal.style.display = 'none'; }
  if (viewBtn) viewBtn.addEventListener('click', openProductsModal);
  if (modalClose) modalClose.addEventListener('click', closeProductsModal);
  if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) closeProductsModal(); });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeProductsModal(); });

  // Extra safety: ensure products modal close works even if styles/stacking change
  (function(){
    const prodModal = document.getElementById('productsModal');
    const prodClose = document.getElementById('productsModalClose');
    if (!prodModal) return;

    // Close when clicking the backdrop
    prodModal.addEventListener('click', function(ev){ if (ev.target === prodModal) prodModal.style.display = 'none'; });

    // Close when clicking the X button
    if (prodClose) prodClose.addEventListener('click', function(ev){ ev.preventDefault(); prodModal.style.display = 'none'; });

    // Close on Escape
    document.addEventListener('keydown', function(ev){ if (ev.key === 'Escape' && prodModal.style.display === 'flex') prodModal.style.display = 'none'; });
  })();

  // Download PDF (simple export based on $all_transactions)
  const downloadBtn = document.getElementById('downloadPDF');
  if (downloadBtn) {
    downloadBtn.addEventListener('click', () => {
      const { jsPDF } = window.jspdf;
      const pdf = new jsPDF('p', 'mm', 'a4');
      pdf.setFontSize(18); pdf.setFont(undefined, 'bold'); pdf.text('Onsite Transactions Report', 105, 20, { align: 'center' });
      pdf.setFontSize(10); pdf.setFont(undefined, 'normal'); const currentDate = new Date().toLocaleDateString(); pdf.text(`Generated on: ${currentDate}`, 105, 28, { align: 'center' });
      pdf.setDrawColor(0); pdf.setLineWidth(0.5); pdf.line(20, 35, 190, 35);

      // Table header
      let y = 45; pdf.setFontSize(9); pdf.setFont(undefined,'bold');
      const headers = ['Transaction ID','User','Total','Date','Source']; const colWidths = [30,30,30,60,30];
      let x = 20; pdf.setFillColor(240,240,240); pdf.rect(20, y-5, 170, 7, 'F'); pdf.setTextColor(0);
      headers.forEach((h, idx) => { pdf.text(h, x+2, y); x += colWidths[idx]; }); y += 8; pdf.setFont(undefined,'normal');

      const rows = <?= json_encode($all_transactions) ?>;
      rows.forEach(r => {
        if (y > 270) { pdf.addPage(); y = 20; }
        x = 20; const cells = [r.transaction_id, r.user_id, '₱' + parseFloat(r.total_amount).toFixed(2), r.transaction_date, r.source];
        cells.forEach((c, idx) => { pdf.text(String(c), x+2, y); x += colWidths[idx]; }); y += 7;
      });

      pdf.save('Onsite_Transactions_Report.pdf');
    });
  }

});
</script>

</body>
</html>
