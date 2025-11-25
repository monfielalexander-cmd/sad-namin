<?php
session_start();
include 'db.php';

if (!isset($_SESSION['username'])) {
  header("Location: index.php");
  exit;
}

// Pagination setup
$records_per_page = 10;
$current_page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($current_page - 1) * $records_per_page;

// Filter params (period: day|month|year|all)
$period = isset($_GET['period']) ? $_GET['period'] : 'all';
$filter_date = isset($_GET['date']) ? mysqli_real_escape_string($conn, $_GET['date']) : '';
$filter_month = isset($_GET['month']) ? mysqli_real_escape_string($conn, $_GET['month']) : '';
$filter_year = isset($_GET['year']) ? mysqli_real_escape_string($conn, $_GET['year']) : '';

// Build WHERE clause for filtered queries (always limit to online source for this page)
$where_base = "WHERE source='online'";
if ($period === 'day' && $filter_date) {
  $where_filter = " AND DATE(transaction_date) = '" . $filter_date . "'";
} elseif ($period === 'month' && $filter_month) {
  // expected format YYYY-MM
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

$total_records_query = "SELECT COUNT(*) as total FROM transactions " . $where;
$total_records_result = mysqli_query($conn, $total_records_query);
$total_records = mysqli_fetch_assoc($total_records_result)['total'];
$total_pages = ceil($total_records / $records_per_page);

$transactions_result = mysqli_query($conn, "SELECT * FROM transactions " . $where . " ORDER BY transaction_date DESC LIMIT $records_per_page OFFSET $offset");

// Get ALL filtered transactions (for PDF download)
$all_transactions_result = mysqli_query($conn, "SELECT * FROM transactions " . $where . " ORDER BY transaction_date DESC");
$all_transactions = [];
while ($row = mysqli_fetch_assoc($all_transactions_result)) {
  $all_transactions[] = $row;
}

$monthly_sales_query = "
  SELECT 
    DATE_FORMAT(transaction_date, '%Y-%m') AS month,
    SUM(total_amount) AS total_sales
  FROM transactions
  " . $where . "
  GROUP BY month
  ORDER BY month ASC
";
$monthly_sales_result = mysqli_query($conn, $monthly_sales_query);

$months = [];
$sales = [];
$total_revenue = 0;

while ($row = mysqli_fetch_assoc($monthly_sales_result)) {
  $months[] = $row['month'];
  $sales[] = $row['total_sales'];
  $total_revenue += $row['total_sales'];
}

// --- Additional KPIs and data for charts ---
// Most purchased product (online source)
$most_product = ['product_name' => '—', 'total_qty' => 0];
$mp_q = "SELECT ti.product_name, SUM(ti.quantity) AS total_qty
          FROM transaction_items ti
          JOIN transactions t ON ti.transaction_id = t.transaction_id
          " . $where . "
          GROUP BY ti.product_id, ti.product_name
          ORDER BY total_qty DESC LIMIT 1";
$mp_res = mysqli_query($conn, $mp_q);
if ($mp_res && mysqli_num_rows($mp_res) > 0) {
  $most_product = mysqli_fetch_assoc($mp_res);
}

// Aggregated purchased products for modal (uses same filter $where)
$purchased_products = [];
$pp_q = "SELECT ti.product_name, SUM(ti.quantity) AS total_qty
          FROM transaction_items ti
          JOIN transactions t ON ti.transaction_id = t.transaction_id
          " . $where . "
          GROUP BY ti.product_id, ti.product_name
          ORDER BY total_qty DESC";
$pp_res = mysqli_query($conn, $pp_q);
if ($pp_res) {
  while ($r = mysqli_fetch_assoc($pp_res)) {
    $purchased_products[] = $r;
  }
}

// Filtered total (reflects the selected period)
$filtered_total_q = "SELECT COALESCE(SUM(total_amount),0) AS total FROM transactions " . $where;
$filtered_total = (float) mysqli_fetch_assoc(mysqli_query($conn, $filtered_total_q))['total'];

// Sales this month (online)
$sales_month_q = "SELECT COALESCE(SUM(total_amount),0) AS total FROM transactions WHERE source='online' AND YEAR(transaction_date)=YEAR(CURDATE()) AND MONTH(transaction_date)=MONTH(CURDATE())";
$sales_month = (float) mysqli_fetch_assoc(mysqli_query($conn, $sales_month_q))['total'];

// Sales this year (online)
$sales_year_q = "SELECT COALESCE(SUM(total_amount),0) AS total FROM transactions WHERE source='online' AND YEAR(transaction_date)=YEAR(CURDATE())";
$sales_year = (float) mysqli_fetch_assoc(mysqli_query($conn, $sales_year_q))['total'];

// Daily sales data (last 30 days by default). If a filter is applied, adjust granularity:
$daily_labels = [];
$daily_values = [];
if ($period === 'month' && $filter_month) {
  // show daily totals for the selected month
  $parts = explode('-', $filter_month);
  $y = intval($parts[0]);
  $m = intval($parts[1]);
  $daily_q = "SELECT DATE(transaction_date) AS day, COALESCE(SUM(total_amount),0) AS total FROM transactions WHERE source='online' AND YEAR(transaction_date)=$y AND MONTH(transaction_date)=$m GROUP BY day ORDER BY day ASC";
  $daily_res = mysqli_query($conn, $daily_q);
  $daily_map = [];
  if ($daily_res) {
    while ($r = mysqli_fetch_assoc($daily_res)) {
      $daily_map[$r['day']] = (float)$r['total'];
    }
  }
  // build days of that month
  $days_in_month = cal_days_in_month(CAL_GREGORIAN, $m, $y);
  for ($d = 1; $d <= $days_in_month; $d++) {
    $date_str = sprintf('%04d-%02d-%02d', $y, $m, $d);
    $daily_labels[] = date('M j', strtotime($date_str));
    $daily_values[] = isset($daily_map[$date_str]) ? $daily_map[$date_str] : 0;
  }
} elseif ($period === 'year' && $filter_year) {
  // show monthly totals for the selected year
  $y = intval($filter_year);
  $daily_q = "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS day, COALESCE(SUM(total_amount),0) AS total FROM transactions WHERE source='online' AND YEAR(transaction_date)=$y GROUP BY day ORDER BY day ASC";
  $daily_res = mysqli_query($conn, $daily_q);
  $daily_map = [];
  if ($daily_res) {
    while ($r = mysqli_fetch_assoc($daily_res)) {
      $daily_map[$r['day']] = (float)$r['total'];
    }
  }
  for ($m = 1; $m <= 12; $m++) {
    $label = date('M', strtotime(sprintf('%04d-%02d-01', $y, $m)));
    $key = sprintf('%04d-%02d', $y, $m);
    $daily_labels[] = $label;
    $daily_values[] = isset($daily_map[$key]) ? $daily_map[$key] : 0;
  }
} else {
  // default: last 30 days
  $daily_q = "SELECT DATE(transaction_date) AS day, COALESCE(SUM(total_amount),0) AS total FROM transactions WHERE source='online' AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY day ORDER BY day ASC";
  $daily_res = mysqli_query($conn, $daily_q);
  // build an array indexed by day to fill missing days
  $daily_map = [];
  if ($daily_res) {
    while ($r = mysqli_fetch_assoc($daily_res)) {
      $daily_map[$r['day']] = (float)$r['total'];
    }
  }
  // populate last 30 days labels and values
  for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $daily_labels[] = date('M j', strtotime($d));
    $daily_values[] = isset($daily_map[$d]) ? $daily_map[$d] : 0;
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Transactions - Admin Panel</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
      max-width: 95%;
      width: 95%;
      margin: 20px auto;
      background: linear-gradient(145deg, #ffffff 0%, #f8faff 100%);
      padding: 30px;
      border-radius: var(--border-radius);
      box-shadow: var(--shadow-heavy);
      min-height: 90vh;
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
    h1, h2 {
      margin-bottom: 20px;
      color: var(--primary-blue);
      font-weight: 700;
      text-shadow: 1px 1px 2px rgba(0, 64, 128, 0.1);
      background: linear-gradient(45deg, var(--primary-blue), var(--secondary-blue));
      -webkit-background-clip: text;
      background-clip: text;
      -webkit-text-fill-color: transparent;
      position: relative;
      text-align: center;
    }

    h1 {
      font-size: 2.2rem;
    }

    h2 {
      font-size: 1.8rem;
    }

    h1::after, h2::after {
      content: '';
      position: absolute;
      bottom: -8px;
      left: 50%;
      transform: translateX(-50%);
      width: 80px;
      height: 4px;
      background: linear-gradient(45deg, var(--accent-gold), #ffd700);
      border-radius: 2px;
    }

    /* ==============================
       TOP BUTTONS SECTION
    ============================== */
    .top-buttons {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 25px;
      gap: 15px;
      flex-wrap: wrap;
    }

    .back-btn, .download-btn {
      background: linear-gradient(45deg, var(--secondary-blue), #0080ff);
      color: white;
      padding: 12px 20px;
      border-radius: 25px;
      text-decoration: none;
      font-size: 0.9rem;
      font-weight: 600;
      transition: var(--transition);
      box-shadow: 0 4px 15px rgba(0, 102, 204, 0.3);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      position: relative;
      overflow: hidden;
      border: none;
      cursor: pointer;
    }

    /* Buttons used inside filter form to keep uniform size */
    .filter-btn {
      padding: 8px 16px;
      border-radius: 12px;
      min-width: 120px;
      display: inline-block;
      text-align: center;
      box-sizing: border-box;
      font-weight: 600;
      font-size: 0.9rem;
      line-height: 1;
    }

    .back-btn::before, .download-btn::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 204, 0, 0.3), transparent);
      transition: left 0.5s;
    }

    .back-btn:hover, .download-btn:hover {
      background: linear-gradient(45deg, #0080ff, var(--secondary-blue));
      transform: translateY(-2px);
      box-shadow: 0 8px 25px rgba(0, 102, 204, 0.4);
      text-decoration: none;
      color: white;
    }

    .back-btn:hover::before, .download-btn:hover::before {
      left: 100%;
    }

    /* ==============================
       ENHANCED TABLE STYLING
    ============================== */
    table {
      width: 100%;
      border-collapse: collapse;
      background: linear-gradient(145deg, #ffffff 0%, #f8faff 100%);
      border-radius: var(--border-radius);
      overflow: hidden;
      box-shadow: var(--shadow-medium);
      margin-top: 20px;
      border: 1px solid rgba(255, 255, 255, 0.8);
    }

    th {
      background: linear-gradient(135deg, var(--primary-blue) 0%, #0056b3 100%);
      color: white;
      text-align: center;
      padding: 15px 12px;
      font-size: 0.9rem;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      position: sticky;
      top: 0;
      z-index: 10;
    }

    td {
      padding: 15px 12px;
      border-bottom: 1px solid rgba(0, 64, 128, 0.1);
      font-size: 0.95rem;
      color: var(--text-dark);
      text-align: center;
    }

    tbody tr {
      transition: var(--transition);
    }

    tbody tr:nth-child(even) {
      background: rgba(248, 250, 255, 0.5);
    }

    tbody tr:hover {
      background: linear-gradient(145deg, rgba(255, 204, 0, 0.1), rgba(0, 64, 128, 0.05));
      transform: translateY(-2px);
      box-shadow: 0 4px 15px rgba(0, 64, 128, 0.1);
    }

    /* ==============================
       ENHANCED SALES SUMMARY SECTION
    ============================== */
    .sales-summary {
      margin-top: 40px;
      padding: 30px;
      background: linear-gradient(145deg, #ffffff 0%, #f0f8ff 100%);
      border-radius: var(--border-radius);
      box-shadow: var(--shadow-medium);
      border: 1px solid rgba(0, 64, 128, 0.1);
      position: relative;
    }

    .sales-summary::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: linear-gradient(145deg, transparent 0%, rgba(255, 204, 0, 0.03) 100%);
      pointer-events: none;
      border-radius: var(--border-radius);
    }

    .sales-summary h2 {
      color: var(--primary-blue);
      margin-bottom: 20px;
      text-align: center;
      font-size: 1.8rem;
    }

    .total-revenue {
      font-size: 1.4rem;
      font-weight: 700;
      margin-bottom: 30px;
      text-align: center;
      color: var(--text-dark);
      background: linear-gradient(145deg, rgba(255, 255, 255, 0.9) 0%, rgba(240, 248, 255, 0.9) 100%);
      padding: 20px;
      border-radius: 10px;
      box-shadow: var(--shadow-light);
      border: 1px solid rgba(0, 64, 128, 0.1);
    }

    .total-revenue strong {
      color: var(--success-green);
      font-size: 1.6rem;
      text-shadow: 1px 1px 2px rgba(39, 174, 96, 0.1);
    }

    canvas {
      width: 100% !important;
      max-width: 800px;
      height: 300px !important;
      display: block;
      margin: 0 auto;
      border-radius: 8px;
      background: rgba(255, 255, 255, 0.8);
      padding: 10px;
      box-shadow: var(--shadow-light);
    }

    /* ==============================
       ENHANCED PAGINATION
    ============================== */
    .pagination {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 20px;
      margin-top: 30px;
      padding: 20px;
      background: linear-gradient(145deg, rgba(248, 250, 255, 0.9) 0%, rgba(240, 248, 255, 0.9) 100%);
      border-radius: var(--border-radius);
      box-shadow: var(--shadow-light);
      border: 1px solid rgba(0, 64, 128, 0.1);
    }

    .page-btn {
      background: linear-gradient(45deg, var(--secondary-blue), #0080ff);
      color: white;
      padding: 10px 18px;
      border-radius: 20px;
      text-decoration: none;
      margin: 0 8px;
      transition: var(--transition);
      font-weight: 600;
      box-shadow: 0 2px 8px rgba(0, 102, 204, 0.3);
      text-transform: uppercase;
      letter-spacing: 0.3px;
      font-size: 0.85rem;
    }

    .page-btn:hover {
      background: linear-gradient(45deg, #0080ff, var(--secondary-blue));
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(0, 102, 204, 0.4);
      text-decoration: none;
      color: white;
    }

    .page-info {
      font-size: 0.95rem;
      font-weight: 600;
      color: var(--primary-blue);
      text-align: center;
    }

    /* ==============================
       MODAL: Purchased Products
    ============================== */
    .modal {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.45);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 9999;
      padding: 20px;
    }

    .modal.show { display: flex; }

    .modal-content {
      background: #fff;
      width: 100%;
      max-width: 900px;
      border-radius: 12px;
      padding: 18px;
      box-shadow: var(--shadow-heavy);
      position: relative;
      max-height: 85vh;
      overflow: auto;
    }

    .modal-close {
      position: absolute;
      right: 14px;
      top: 10px;
      background: transparent;
      border: none;
      font-size: 20px;
      cursor: pointer;
      color: var(--primary-blue);
    }

    .modal table { width: 100%; border-collapse: collapse; margin-top:12px; }
    .modal th, .modal td { padding: 8px 10px; border-bottom: 1px solid rgba(0,0,0,0.06); text-align:left; }
    .modal th { background: #f6f9ff; color: var(--primary-blue); font-weight:700; }

    /* ==============================
       RESPONSIVE DESIGN
    ============================== */
    @media (max-width: 768px) {
      .container {
        padding: 20px;
        margin: 10px;
        width: calc(100% - 20px);
      }

      h1 {
        font-size: 1.8rem;
      }

      h2 {
        font-size: 1.5rem;
      }

      .top-buttons {
        flex-direction: column;
        gap: 15px;
      }

      .back-btn, .download-btn {
        width: 100%;
        text-align: center;
      }

      table {
        font-size: 0.9rem;
      }

      th, td {
        padding: 10px 8px;
        font-size: 0.8rem;
      }

      canvas {
        height: 250px !important;
      }

      .sales-summary {
        padding: 20px;
      }

      .total-revenue {
        font-size: 1.2rem;
      }

      .total-revenue strong {
        font-size: 1.4rem;
      }

      .pagination {
        flex-direction: column;
        gap: 15px;
        padding: 15px;
      }

      .page-btn {
        width: 100%;
        text-align: center;
        margin: 0;
      }
    }

    @media (max-width: 480px) {
      table {
        display: block;
        overflow-x: auto;
        white-space: nowrap;
      }

      h1::after, h2::after {
        width: 60px;
      }
    }
  </style>
</head>

<body>
  <div class="container">
    <div class="top-buttons">
      <a href="admin.php" class="back-btn">← Back to Dashboard</a>
      <button class="download-btn" id="downloadPDF">⬇ Download Report</button>
    </div>

    <!-- PAGE 1: TRANSACTIONS -->
    <div id="transactionsPage">
      <h1>🛒 Customer Orders (Online)</h1>
      <!-- FILTER FORM -->
      <form method="GET" style="display:flex; gap:12px; align-items:center; margin-bottom:12px; flex-wrap:wrap;">
        <label style="font-weight:600; color:#004080;">Filter:</label>
        <select id="periodSelect" name="period" style="padding:8px 10px; border-radius:8px;">
          <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>All</option>
          <option value="day" <?= $period === 'day' ? 'selected' : '' ?>>Day</option>
          <option value="month" <?= $period === 'month' ? 'selected' : '' ?>>Month</option>
          <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Year</option>
        </select>

        <input type="date" id="dateInput" name="date" value="<?= htmlspecialchars($filter_date) ?>" style="padding:8px 10px; border-radius:8px; display: <?= $period === 'day' ? 'inline-block' : 'none' ?>;">
        <input type="month" id="monthInput" name="month" value="<?= htmlspecialchars($filter_month) ?>" style="padding:8px 10px; border-radius:8px; display: <?= $period === 'month' ? 'inline-block' : 'none' ?>;">
        <input type="number" id="yearInput" name="year" min="2000" max="2100" placeholder="YYYY" value="<?= htmlspecialchars($filter_year) ?>" style="padding:8px 10px; border-radius:8px; width:100px; display: <?= $period === 'year' ? 'inline-block' : 'none' ?>;">

        <button type="submit" class="download-btn filter-btn">Apply</button>
        <a href="customer_orders.php" class="back-btn filter-btn">Reset</a>
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
      <div style="display:flex; gap:18px; margin: 18px 0 6px; flex-wrap:wrap;">
        <div style="flex:1 1 220px; background:#fff; padding:16px; border-radius:12px; box-shadow:var(--shadow-light); text-align:left;">
          <div style="font-size:12px; color:#6b7280;">Most Purchased</div>
          <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; margin-top:6px;">
            <div style="font-weight:700; font-size:16px;"><?= htmlspecialchars($most_product['product_name']) ?></div>
            <button id="viewProductsBtn" class="filter-btn" style="padding:6px 10px; font-size:0.85rem; white-space:nowrap;">View All</button>
          </div>
          <div style="color:#6b7280; margin-top:6px;">Quantity: <?= number_format($most_product['total_qty']) ?></div>
        </div>
        <div style="flex:1 1 160px; background:#fff; padding:16px; border-radius:12px; box-shadow:var(--shadow-light); text-align:left;">
          <div style="font-size:12px; color:#6b7280;">Total (Filtered)</div>
          <div style="font-weight:700; font-size:18px; margin-top:6px; color:var(--success-green);">₱<?= number_format($filtered_total,2) ?></div>
        </div>
        <div style="flex:1 1 160px; background:#fff; padding:16px; border-radius:12px; box-shadow:var(--shadow-light); text-align:left;">
          <div style="font-size:12px; color:#6b7280;">Sales This Month</div>
          <div style="font-weight:700; font-size:18px; margin-top:6px; color:var(--primary-blue);">₱<?= number_format($sales_month,2) ?></div>
        </div>
        <div style="flex:1 1 160px; background:#fff; padding:16px; border-radius:12px; box-shadow:var(--shadow-light); text-align:left;">
          <div style="font-size:12px; color:#6b7280;">Sales This Year</div>
          <div style="font-weight:700; font-size:18px; margin-top:6px; color:#004080;">₱<?= number_format($sales_year,2) ?></div>
        </div>
      </div>

      <!-- DAILY SALES CHART -->
      <div style="margin:10px 0 20px; background:linear-gradient(145deg,#fff,#f8fbff); padding:14px; border-radius:12px; box-shadow:var(--shadow-light);">
        <h2 style="margin:0 0 8px; font-size:1rem; color:var(--primary-blue);">Last 30 Days — Daily Sales</h2>
        <canvas id="dailySalesChart" style="width:100%; height:220px;"></canvas>
      </div>
      <table>
        <thead>
          <tr>
            <th>Transaction ID</th>
            <th>User ID</th>
            <th>Total Amount (₱)</th>
            <th>Transaction Date</th>
            <th>Source</th>
          </tr>
        </thead>
        <tbody>
          <?php while ($transaction = mysqli_fetch_assoc($transactions_result)) { ?>
            <tr>
              <td><?= $transaction['transaction_id'] ?></td>
              <td><?= $transaction['user_id'] ?></td>
              <td>₱<?= number_format($transaction['total_amount'], 2) ?></td>
              <td><?= $transaction['transaction_date'] ?></td>
              <td><?= htmlspecialchars($transaction['source']) ?></td>
            </tr>
          <?php } ?>
        </tbody>
      </table>

      <!-- Pagination -->
        <?php
          // Build pagination links that preserve filters and stay in the transactions section
          $params = $_GET;
          // Ensure page param exists (will be overwritten below)
          if (!isset($params['page'])) $params['page'] = $current_page;
          $prev_href = '';
          $next_href = '';
          if ($current_page > 1) {
            $params['page'] = $current_page - 1;
            $prev_href = '?' . http_build_query($params) . '#transactionsPage';
          }
          if ($current_page < $total_pages) {
            $params['page'] = $current_page + 1;
            $next_href = '?' . http_build_query($params) . '#transactionsPage';
          }
        ?>

        <div class="pagination">
          <?php if ($prev_href): ?>
            <a href="<?= htmlspecialchars($prev_href) ?>" class="page-btn">← Previous</a>
          <?php endif; ?>

          <span class="page-info">
            Page <?= $current_page ?> of <?= $total_pages ?> (Total: <?= $total_records ?> records)
          </span>

          <?php if ($next_href): ?>
            <a href="<?= htmlspecialchars($next_href) ?>" class="page-btn">Next →</a>
          <?php endif; ?>
        </div>
    </div>

    <!-- Sales Summary removed per user request -->
    <!-- Purchased Products Modal -->
    <div id="productsModal" class="modal" aria-hidden="true">
      <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="productsModalTitle">
        <button class="modal-close" id="productsModalClose" title="Close">✕</button>
        <h3 id="productsModalTitle" style="margin:0 0 8px; color:var(--primary-blue);">Purchased Products</h3>
        <div style="color:#6b7280; font-size:0.95rem;">Showing products for current filter selection.</div>

        <table id="productsTable">
          <thead>
            <tr><th>Product Name</th><th style="width:120px; text-align:right;">Quantity</th></tr>
          </thead>
          <tbody>
            <!-- populated by JS -->
          </tbody>
        </table>
      </div>
    </div>
  </div>


<script>
    // PDF Download - Clean Data Only
    document.getElementById('downloadPDF').addEventListener('click', () => {
      const { jsPDF } = window.jspdf;
      const pdf = new jsPDF('p', 'mm', 'a4');
      
      // Header
      pdf.setFontSize(18);
      pdf.setFont(undefined, 'bold');
      pdf.text('ABETH HARDWARE', 105, 20, { align: 'center' });
      
      pdf.setFontSize(14);
      pdf.text('Customer Orders Report (Online)', 105, 30, { align: 'center' });
      
      pdf.setFontSize(10);
      pdf.setFont(undefined, 'normal');
      const currentDate = new Date().toLocaleDateString();
      pdf.text(`Generated on: ${currentDate}`, 105, 40, { align: 'center' });
      
      // Draw line separator
      pdf.setDrawColor(0, 0, 0);
      pdf.setLineWidth(0.5);
      pdf.line(20, 45, 190, 45);
      
      // Table headers
      let yPosition = 55;
      pdf.setFontSize(9);
      pdf.setFont(undefined, 'bold');
      
      const headers = ['Transaction ID', 'User ID', 'Total Amount (₱)', 'Transaction Date', 'Source'];
      const colWidths = [30, 30, 35, 45, 25];
      let xPosition = 20;
      
      // Draw header background
      pdf.setFillColor(0, 0, 0);
      pdf.rect(20, yPosition - 5, 165, 8, 'F');
      
      pdf.setTextColor(255, 255, 255);
      headers.forEach((header, index) => {
        pdf.text(header, xPosition + 2, yPosition, { maxWidth: colWidths[index] - 4 });
        xPosition += colWidths[index];
      });
      
      yPosition += 10;
      pdf.setTextColor(0, 0, 0);
      pdf.setFont(undefined, 'normal');
      
      // Get ALL transaction data from PHP and organize by month
      const allTransactions = <?= json_encode($all_transactions) ?>;
      
      // Group transactions by month
      const transactionsByMonth = {};
      allTransactions.forEach(transaction => {
        const date = new Date(transaction.transaction_date);
        const monthYear = date.toLocaleDateString('en-US', { year: 'numeric', month: 'long' });
        
        if (!transactionsByMonth[monthYear]) {
          transactionsByMonth[monthYear] = [];
        }
        transactionsByMonth[monthYear].push(transaction);
      });
      
      // Sort months chronologically (newest first)
      const sortedMonths = Object.keys(transactionsByMonth).sort((a, b) => {
        return new Date(b) - new Date(a);
      });
      
      let transactionRowIndex = 0;
      
      sortedMonths.forEach((monthYear) => {
        const monthTransactions = transactionsByMonth[monthYear];
        let monthlyTotal = 0;
        
        // Check if we need a new page for month header
        if (yPosition > 250) {
          pdf.addPage();
          yPosition = 20;
        }
        
        // Month Header
        pdf.setFontSize(14);
        pdf.setFont(undefined, 'bold');
        pdf.setFillColor(0, 0, 0);
        pdf.setTextColor(255, 255, 255);
        pdf.rect(20, yPosition - 3, 165, 12, 'F');
        pdf.text(monthYear, 105, yPosition + 5, { align: 'center' });
        yPosition += 20;
        
        pdf.setTextColor(0, 0, 0);
        pdf.setFont(undefined, 'normal');
        
        // Draw table headers for this month
        pdf.setFontSize(9);
        pdf.setFont(undefined, 'bold');
        pdf.setFillColor(240, 240, 240);
        pdf.rect(20, yPosition - 5, 165, 8, 'F');
        pdf.setTextColor(0, 0, 0);
        
        let headerX = 20;
        headers.forEach((header, headerIndex) => {
          pdf.text(header, headerX + 2, yPosition, { maxWidth: colWidths[headerIndex] - 4 });
          headerX += colWidths[headerIndex];
        });
        
        yPosition += 10;
        pdf.setFont(undefined, 'normal');
        
        // Add transactions for this month
        monthTransactions.forEach((transaction) => {
          if (yPosition > 270) { // Check if need new page
            pdf.addPage();
            yPosition = 20;
            
            // Redraw month header on new page
            pdf.setFontSize(14);
            pdf.setFont(undefined, 'bold');
            pdf.setFillColor(0, 0, 0);
            pdf.setTextColor(255, 255, 255);
            pdf.rect(20, yPosition - 3, 165, 12, 'F');
            pdf.text(`${monthYear} (continued)`, 105, yPosition + 5, { align: 'center' });
            yPosition += 20;
            
            // Redraw headers
            pdf.setFontSize(9);
            pdf.setFont(undefined, 'bold');
            pdf.setFillColor(240, 240, 240);
            pdf.rect(20, yPosition - 5, 165, 8, 'F');
            pdf.setTextColor(0, 0, 0);
            
            let headerX = 20;
            headers.forEach((header, headerIndex) => {
              pdf.text(header, headerX + 2, yPosition, { maxWidth: colWidths[headerIndex] - 4 });
              headerX += colWidths[headerIndex];
            });
            
            yPosition += 10;
            pdf.setFont(undefined, 'normal');
          }
          
          xPosition = 20;
          
          // Alternate row colors
          if (transactionRowIndex % 2 === 0) {
            pdf.setFillColor(250, 250, 250);
            pdf.rect(20, yPosition - 3, 165, 7, 'F');
          }
          
          // Add transaction data
          const transactionData = [
            transaction.transaction_id,
            transaction.user_id,
            '₱' + parseFloat(transaction.total_amount).toLocaleString('en-US', {minimumFractionDigits: 2}),
            transaction.transaction_date,
            transaction.source
          ];
          
          transactionData.forEach((data, cellIndex) => {
            pdf.text(data.toString(), xPosition + 2, yPosition, { maxWidth: colWidths[cellIndex] - 4 });
            xPosition += colWidths[cellIndex];
          });
          
          monthlyTotal += parseFloat(transaction.total_amount);
          yPosition += 7;
          transactionRowIndex++;
        });
        
        // Monthly subtotal
        yPosition += 3;
        pdf.setFont(undefined, 'bold');
        pdf.setFillColor(220, 220, 220);
        pdf.rect(120, yPosition - 3, 65, 8, 'F');
        pdf.text(`${monthYear} Total: ₱${monthlyTotal.toLocaleString('en-US', {minimumFractionDigits: 2})}`, 125, yPosition + 2);
        yPosition += 15;
        pdf.setFont(undefined, 'normal');
      });
      
      // Summary section
      yPosition += 10;
      if (yPosition > 250) {
        pdf.addPage();
        yPosition = 20;
      }
      
      // Draw separator line
      pdf.setDrawColor(0, 0, 0);
      pdf.line(20, yPosition, 190, yPosition);
      yPosition += 10;
      
      pdf.setFont(undefined, 'bold');
      pdf.setFontSize(12);
      pdf.text('Summary', 20, yPosition);
      yPosition += 10;
      
      pdf.setFont(undefined, 'normal');
      pdf.setFontSize(10);
      
      const totalRecords = <?= $total_records ?>;
      const totalRevenue = '<?= number_format($total_revenue, 2) ?>';
      
      pdf.text(`Total Transactions: ${totalRecords}`, 20, yPosition);
      yPosition += 7;
      pdf.text(`Total Revenue: ₱${totalRevenue}`, 20, yPosition);
      
      // Footer
      const pageCount = pdf.internal.getNumberOfPages();
      for (let i = 1; i <= pageCount; i++) {
        pdf.setPage(i);
        pdf.setFontSize(8);
        pdf.setFont(undefined, 'normal');
        pdf.setTextColor(100, 100, 100);
        pdf.text(`Page ${i} of ${pageCount}`, 105, 290, { align: 'center' });
        pdf.text('Abeth Hardware - Customer Orders Report', 105, 285, { align: 'center' });
      }
      
      pdf.save('Customer_Orders_Report.pdf');
    });

    // Initialize chart on page load (no AJAX partials)
    document.addEventListener('DOMContentLoaded', function() {
      const dailyEl = document.getElementById('dailySalesChart');
      const labels = <?= json_encode($daily_labels) ?>;
      const values = <?= json_encode($daily_values) ?>;

      if (dailyEl) {
        // create a distinct color for each bar (repeats if more bars than palette)
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
              borderWidth: 1
            }]
          },
          options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } }, plugins: { legend: { display: false } } }
        });
      }

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

        // ----- Purchased Products Modal -----
        const purchasedProducts = <?= json_encode($purchased_products) ?>;
        const modal = document.getElementById('productsModal');
        const modalClose = document.getElementById('productsModalClose');
        const viewBtn = document.getElementById('viewProductsBtn');
        const productsTbody = document.querySelector('#productsTable tbody');

        function openProductsModal() {
          // populate table
          productsTbody.innerHTML = '';
          if (!purchasedProducts || purchasedProducts.length === 0) {
            productsTbody.innerHTML = '<tr><td colspan="2" style="text-align:center; color:#666; padding:14px;">No products found for the current filter.</td></tr>';
          } else {
            purchasedProducts.forEach(p => {
              const tr = document.createElement('tr');
              const nameTd = document.createElement('td');
              nameTd.textContent = p.product_name;
              const qtyTd = document.createElement('td');
              qtyTd.style.textAlign = 'right';
              qtyTd.textContent = Number(p.total_qty).toLocaleString();
              tr.appendChild(nameTd);
              tr.appendChild(qtyTd);
              productsTbody.appendChild(tr);
            });
          }

          modal.classList.add('show');
          modal.setAttribute('aria-hidden', 'false');
        }

        function closeProductsModal() {
          modal.classList.remove('show');
          modal.setAttribute('aria-hidden', 'true');
        }

        if (viewBtn) viewBtn.addEventListener('click', openProductsModal);
        if (modalClose) modalClose.addEventListener('click', closeProductsModal);
        // close when clicking outside content
        if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) closeProductsModal(); });
        // close with Esc
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeProductsModal(); });
    });
  </script>
</body>
</html> 
