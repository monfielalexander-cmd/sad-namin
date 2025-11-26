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

// AJAX endpoint: return transactions for a specific month (YYYY-MM)
if (isset($_GET['ajax_month']) && $_GET['ajax_month'] == '1' && isset($_GET['month'])) {
  $month_raw = mysqli_real_escape_string($conn, $_GET['month']);
  $parts = explode('-', $month_raw);
  if (count($parts) === 2) {
    $y = intval($parts[0]);
    $m = intval($parts[1]);
    $q = "SELECT * FROM transactions WHERE source='online' AND YEAR(transaction_date)=$y AND MONTH(transaction_date)=$m ORDER BY transaction_date DESC";
    $res = mysqli_query($conn, $q);
    $rows = [];
    if ($res) {
      while ($r = mysqli_fetch_assoc($res)) {
        $rows[] = $r;
      }
    }
    header('Content-Type: application/json');
    echo json_encode($rows);
    exit;
  } else {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
  }
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
$mp_q = "SELECT COALESCE(ti.product_name, p.name) AS product_name, SUM(ti.quantity) AS total_qty
          FROM transaction_items ti
          JOIN transactions t ON ti.transaction_id = t.transaction_id
          LEFT JOIN products_ko p ON ti.product_id = p.id
          " . $where . "
          GROUP BY ti.product_id, product_name
          ORDER BY total_qty DESC LIMIT 1";
$mp_res = mysqli_query($conn, $mp_q);
if ($mp_res && mysqli_num_rows($mp_res) > 0) {
  $most_product = mysqli_fetch_assoc($mp_res);
}

// Aggregated purchased products for modal (uses same filter $where)
$purchased_products = [];
$pp_q = "SELECT COALESCE(ti.product_name, p.name) AS product_name, SUM(ti.quantity) AS total_qty
          FROM transaction_items ti
          JOIN transactions t ON ti.transaction_id = t.transaction_id
          LEFT JOIN products_ko p ON ti.product_id = p.id
          " . $where . "
          GROUP BY ti.product_id, product_name
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

// --- Daily sales data for chart (last 30 days or filtered period) ---
$daily_labels = [];
$daily_values = [];
// If a specific day is selected, show the 30-day window ending on that date
if ($period === 'day' && $filter_date) {
  $end_ts = strtotime($filter_date);
  $date_list = [];
  for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days", $end_ts));
    $date_list[] = $d;
  }

  // query totals for the whole 30-day range (use a fresh WHERE that only limits to online source)
  $start_date = $date_list[0];
  $end_date = $date_list[count($date_list)-1];
  $range_where = "WHERE source='online'";
  $range_q = "SELECT DATE(transaction_date) AS day, COALESCE(SUM(total_amount),0) AS total FROM transactions " . $range_where . " AND DATE(transaction_date) >= '" . $start_date . "' AND DATE(transaction_date) <= '" . $end_date . "' GROUP BY day ORDER BY day ASC";
  $range_res = mysqli_query($conn, $range_q);
  $daily_map = [];
  if ($range_res) {
    while ($r = mysqli_fetch_assoc($range_res)) {
      $daily_map[$r['day']] = (float)$r['total'];
    }
  }

  foreach ($date_list as $d) {
    $daily_labels[] = date('M j', strtotime($d));
    $daily_values[] = isset($daily_map[$d]) ? $daily_map[$d] : 0;
  }

} elseif ($period === 'month' && $filter_month) {
  $parts = explode('-', $filter_month);
  $y = intval($parts[0]);
  $m = intval($parts[1]);
  $daily_q = "SELECT DATE(transaction_date) AS day, COALESCE(SUM(total_amount),0) AS total FROM transactions " . $where . " AND YEAR(transaction_date)=$y AND MONTH(transaction_date)=$m GROUP BY day ORDER BY day ASC";
  $daily_res = mysqli_query($conn, $daily_q);
  $daily_map = [];
  if ($daily_res) while ($r = mysqli_fetch_assoc($daily_res)) $daily_map[$r['day']] = (float)$r['total'];
  $days_in_month = cal_days_in_month(CAL_GREGORIAN, $m, $y);
  for ($d = 1; $d <= $days_in_month; $d++) {
    $date_str = sprintf('%04d-%02d-%02d', $y, $m, $d);
    $daily_labels[] = date('M j', strtotime($date_str));
    $daily_values[] = isset($daily_map[$date_str]) ? $daily_map[$date_str] : 0;
  }
} elseif ($period === 'year' && $filter_year) {
  $y = intval($filter_year);
  $daily_q = "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS day, COALESCE(SUM(total_amount),0) AS total FROM transactions " . $where . " AND YEAR(transaction_date)=$y GROUP BY day ORDER BY day ASC";
  $daily_res = mysqli_query($conn, $daily_q);
  $daily_map = [];
  if ($daily_res) while ($r = mysqli_fetch_assoc($daily_res)) $daily_map[$r['day']] = (float)$r['total'];
  for ($m = 1; $m <= 12; $m++) {
    $label = date('M', strtotime(sprintf('%04d-%02d-01', $y, $m)));
    $key = sprintf('%04d-%02d', $y, $m);
    $daily_labels[] = $label;
    $daily_values[] = isset($daily_map[$key]) ? $daily_map[$key] : 0;
  }
} else {
  $daily_q = "SELECT DATE(transaction_date) AS day, COALESCE(SUM(total_amount),0) AS total FROM transactions " . $where . " AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY day ORDER BY day ASC";
  $daily_res = mysqli_query($conn, $daily_q);
  $daily_map = [];
  if ($daily_res) while ($r = mysqli_fetch_assoc($daily_res)) $daily_map[$r['day']] = (float)$r['total'];
  // Use the database's CURDATE() to avoid PHP timezone drift so labels align with DB results
  $db_today_res = mysqli_query($conn, "SELECT CURDATE() AS today");
  $db_today_row = $db_today_res ? mysqli_fetch_assoc($db_today_res) : null;
  $db_today = $db_today_row && isset($db_today_row['today']) ? $db_today_row['today'] : date('Y-m-d');
  for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days", strtotime($db_today)));
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
      padding: 8px 14px;
      border-radius: 16px;
      text-decoration: none;
      font-size: 0.85rem;
      font-weight: 600;
      transition: var(--transition);
      box-shadow: 0 3px 10px rgba(0, 102, 204, 0.25);
      text-transform: uppercase;
      letter-spacing: 0.4px;
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
    .view-btn {
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding:6px 10px;
      font-size:0.85rem;
      border-radius:8px;
      background:#ffffff;
      color:var(--primary-blue);
      border:1px solid rgba(0,64,128,0.12);
      box-shadow: 0 4px 12px rgba(0,64,128,0.04);
      text-decoration: none;
      cursor: pointer;
    }
    .view-btn:hover {
      background: var(--secondary-blue);
      color: #fff;
      border-color: rgba(0,64,128,0.18);
      box-shadow: 0 6px 18px rgba(0,102,204,0.12);
    }
    /* KPI cards - shared styles copied from onsite_transaction.php */
    .kpi-cards {
      display: flex;
      gap: 18px;
      margin: 18px 0 16px;
      flex-wrap: wrap;
      align-items: stretch;
    }
    .kpi-card {
      flex: 1 1 220px;
      background: #fff;
      padding: 14px 18px;
      border-radius: 12px;
      box-shadow: 0 10px 30px rgba(2,48,90,0.06);
      text-align: left;
      position: relative;
      display: flex;
      align-items: center;
      justify-content: space-between;
      min-height: 84px;
    }
    .kpi-card.wide { flex: 1 1 260px; }
    .kpi-left { display: flex; flex-direction: column; gap: 6px; align-items:flex-start; }
    .kpi-title { font-size: 13px; color: #6b7280; margin: 0; }
    .kpi-value { font-weight: 900; font-size: 15px; margin: 0; color: var(--text-dark); }
    .kpi-value.large { font-size: 20px; }
    .kpi-value.success { color: var(--success-green); }
    .kpi-value.primary { color: var(--primary-blue); }
    .kpi-right { display: flex; flex-direction: column; align-items:flex-end; gap: 6px; min-width:120px; }
    .kpi-value { text-align: right; }
    /* Make the wide KPI (Most Purchased) show value and action inline on the right */
    .kpi-card.wide .kpi-right { flex-direction: row; align-items: center; justify-content: flex-end; gap: 10px; }
    .kpi-card.wide .kpi-value { margin: 0; white-space: nowrap; }
    .kpi-card.wide .view-btn { margin-left: 6px; }
    .kpi-card .kpi-action {
      display: inline-flex;
      align-items: center;
      padding: 6px 10px;
      font-size: 0.85rem;
      border-radius: 8px;
      background: #fff;
      color: var(--primary-blue);
      border: 1px solid rgba(0,64,128,0.12);
      box-shadow: 0 4px 12px rgba(0,64,128,0.04);
      cursor: pointer;
    }
    .kpi-card .kpi-action:hover { background: var(--secondary-blue); color: #fff; border-color: rgba(0,64,128,0.18); box-shadow: 0 6px 18px rgba(0,102,204,0.12); }
    @media (max-width: 720px) {
      .kpi-card { flex-direction: column; align-items: flex-start; min-height: auto; }
      .kpi-right { align-items: flex-start; width: 100%; }
      /* ensure wide card stacks nicely on small screens */
      .kpi-card.wide { flex-direction: column; align-items: flex-start; }
      .kpi-card.wide .kpi-right { flex-direction: column; align-items: flex-start; width: 100%; }
      .kpi-card .kpi-action { align-self: flex-start; }
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

    /* Daily chart container styling (matches onsite_transaction) */
    .daily-chart { margin:10px 0 20px; background:linear-gradient(145deg,#fff,#f8fbff); padding:14px; border-radius:12px; box-shadow:var(--shadow-light); }
    .daily-chart h2 { margin:0 0 8px; font-size:1rem; color:var(--primary-blue); text-align:center; position:relative; }
    .daily-chart h2::after { content: ''; position: absolute; bottom: -8px; left: 50%; transform: translateX(-50%); width: 60px; height: 6px; background: var(--accent-gold); border-radius: 4px; }
    .daily-chart canvas { width:100%; height:220px; display:block; margin:0 auto; border-radius:8px; box-shadow: var(--shadow-light); }

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
      color: var(--primary-blu  e);
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
        padding: 10px 12px;
        font-size: 0.85rem;
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
      <div style="display:inline-flex; gap:8px; align-items:center;">
        <input id="downloadMonth" type="month" title="Choose month to download (optional)" style="padding:8px 10px; border-radius:8px; border:1px solid rgba(0,64,128,0.12); background:#fff;">
        <button class="download-btn" id="downloadPDF">⬇ Download Report</button>
      </div>
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
        <div class="kpi-cards">
          <div class="kpi-card wide">
            <div class="kpi-left">
              <p class="kpi-title">Most Purchased</p>
            </div>
            <div class="kpi-right">
              <p class="kpi-value"><?= htmlspecialchars($most_product['product_name'] ?? '—') ?></p>
              <button id="viewProductsBtn" class="kpi-action view-btn">View All</button>
            </div>
          </div>

          <div class="kpi-card">
            <div class="kpi-left">
              <p class="kpi-title">Total (Filtered)</p>
            </div>
            <div class="kpi-right">
              <p class="kpi-value success">₱<?= number_format($filtered_total,2) ?></p>
            </div>
          </div>

          <div class="kpi-card">
            <div class="kpi-left">
              <p class="kpi-title">Sales This Month</p>
            </div>
            <div class="kpi-right">
              <p class="kpi-value primary">₱<?= number_format($sales_month,2) ?></p>
            </div>
          </div>

          <div class="kpi-card">
            <div class="kpi-left">
              <p class="kpi-title">Sales This Year</p>
            </div>
            <div class="kpi-right">
              <p class="kpi-value primary">₱<?= number_format($sales_year,2) ?></p>
            </div>
          </div>
        </div>

      <!-- DAILY SALES CHART -->
      <div class="daily-chart">
        <h2><?php if ($period === 'day' && $filter_date) { echo htmlspecialchars(date('M j, Y', strtotime($filter_date))) . ' — Daily Sales'; } else { echo 'Last 30 Days — Daily Sales'; } ?></h2>
        <canvas id="dailySalesChart"></canvas>
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
    <style>
      /* Products modal (enlarged for clearer display) */
      .modal {
        position: fixed;
        inset: 0;
        display: none;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, rgba(15,23,42,0.55) 0%, rgba(15,23,42,0.72) 100%);
        backdrop-filter: blur(10px);
        -webkit-backdrop-filter: blur(10px);
        z-index: 9999;
        padding: 40px 80px; /* larger side padding to create roomy margins like the image */
        box-sizing: border-box;
        animation: modalBackdropFadeIn 0.36s ease;
      }
      .modal.show { display: flex; }

      @keyframes modalBackdropFadeIn {
        from { opacity: 0; backdrop-filter: blur(0px); -webkit-backdrop-filter: blur(0px); }
        to { opacity: 1; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
      }

      .modal-box {
        width: 980px; /* tuned to match screenshot proportion */
        max-width: calc(100% - 160px);
        background: #fff;
        border-radius: 14px;
        box-shadow: 0 18px 48px rgba(2,6,23,0.36);
        overflow: hidden;
        border: 6px solid rgba(255,255,255,0.95); /* subtle white frame like the image */
        transform: translateY(10px) scale(.98);
        opacity: 0;
        transition: transform 360ms cubic-bezier(0.2,0.9,0.2,1), opacity 360ms ease;
      }

      .modal-header {
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 40px 26px 32px; /* taller header like the image */
        background: linear-gradient(90deg, #1e3a8a, #2b5fb0);
        color: #fff;
        position: relative;
        border-radius: 12px 12px 0 0;
        text-align: center;
      }
      .modal-header h3 { margin: 0; font-size: 1.5rem; font-weight: bold; line-height:1.02; }
      .modal-header h3::after {
        content: '';
        position: absolute;
        left: 50%;
        transform: translateX(-50%);
        bottom: -16px;
        width: 100px; /* wider underline to match image */
        height: 10px;
        background: linear-gradient(45deg, #ffd700 0%, #ffeb3b 100%);
        border-radius: 6px;
        box-shadow: 0 6px 14px rgba(255, 215, 0, 0.30);
      }
      .modal-subtitle { color: rgba(255,255,255,0.95); font-size: 1rem; margin-top: 8px; }

      .close-btn {
        background: linear-gradient(180deg, #ff6b6b, #e74c3c);
        color: #fff;
        border: none;
        height: 44px;
        min-width: 120px;
        padding: 0 20px;
        border-radius: 999px;
        font-weight: 800;
        font-size: 0.95rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 10px 30px rgba(231,76,60,0.18), 0 0 0 6px rgba(231,76,60,0.06);
        cursor: pointer;
        transition: transform .12s ease, box-shadow .12s ease, opacity .12s ease;
      }
      .close-btn:hover {
        transform: translateY(-2px);
        box-shadow: 0 18px 46px rgba(231,76,60,0.22), 0 0 0 8px rgba(231,76,60,0.06);
        transition: transform .12s ease, box-shadow .12s ease, opacity .12s ease;
      }
      .close-btn:focus {
        outline: 3px solid rgba(231,76,60,0.14);
        outline-offset: 3px;
      }

      .table-wrapper {
        padding: 18px 20px;
        max-height: 520px;
        overflow: auto;
        background: #fff;
      }

      /* Custom scrollbar to match products.css */
      .table-wrapper::-webkit-scrollbar {
        width: 10px;
        height: 10px;
      }
      .table-wrapper::-webkit-scrollbar-track {
        background: rgba(0, 64, 128, 0.05);
        border-radius: 10px;
      }
      .table-wrapper::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, var(--primary-blue), #0056b3);
        border-radius: 10px;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,0.35);
      }
      .table-wrapper::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, #0056b3, var(--primary-blue));
      }

      /* Also apply to modal-box if it ever scrolls */
      .modal-box::-webkit-scrollbar {
        width: 10px;
      }
      .modal-box::-webkit-scrollbar-track {
        background: rgba(0, 64, 128, 0.05);
        border-radius: 10px;
      }
      .modal-box::-webkit-scrollbar-thumb {
        background: linear-gradient(135deg, var(--primary-blue), #0056b3);
        border-radius: 10px;
      }
      .modal-box::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(135deg, #0056b3, var(--primary-blue));
      }
      .products-table {
        width: 100%;
        border-collapse: collapse;
      }
      .products-table thead th {
        text-align: left;
        padding: 10px 12px;
        font-size: 0.98rem;
        color: #374151;
        border-bottom: 1px solid #e6edf6;
        background: #fbfdff;
      }
      .products-table tbody td {
        padding: 12px; color: #111827; vertical-align: middle;
        border-bottom: 1px solid #f3f4f6;
      }

      .modal-footer {
        display: flex; justify-content: center; gap: 10px; padding: 24px 20px 32px; background: #fbfbfd; /* centered close like image */
      }
 
      .modal.show .modal-box {
        transform: translateY(0) scale(1);
        opacity: 1;
      }
      .modal.closing .modal-box {
        transform: translateY(12px) scale(.98);
        opacity: 0;
      }
    </style>

    <div id="productsModal" class="modal" aria-hidden="true">
      <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="productsModalTitle">
        <div class="modal-header">
          <div>
            <h3 id="productsModalTitle">Most Purchased Products</h3>
          </div>
          <button id="productsModalClose" class="modal-close" aria-label="Close purchased products">×</button>
        </div>

        <div class="table-wrapper">
          <table id="productsTable" class="products-table">
            <thead>
              <tr>
                <th>Product Name</th>
                <th style="width:120px; text-align:right;">Quantity</th>
              </tr>
            </thead>
            <tbody>
              <!-- populated by JS -->
            </tbody>
          </table>
        </div>

        <div class="modal-footer">
          <button class="close-btn footer-close">Close</button>
        </div>
      </div>
    </div>
  </div>


<script>
    // NOTE: download handler moved into DOMContentLoaded so it can access embedded PHP data reliably.

    // Initialize chart on page load (no AJAX partials)
    document.addEventListener('DOMContentLoaded', function() {
      const dailyEl = document.getElementById('dailySalesChart');
      const labels = <?= json_encode($daily_labels) ?>;
      const values = <?= json_encode($daily_values) ?>;

      // Embedded all transactions (reflects current page filter 'where' at PHP level)
      const allTransactions = <?= json_encode($all_transactions) ?>;

      // PDF generation helper: accepts an array of transactions (objects) and optional titleSuffix
      function generatePDFFromTransactions(transactions, titleSuffix) {
        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('p', 'mm', 'a4');

        // Header
        pdf.setFontSize(18);
        pdf.setFont(undefined, 'bold');
        pdf.text('ABETH HARDWARE', 105, 20, { align: 'center' });

        let subtitle = 'Customer Orders Report (Online)';
        if (titleSuffix) subtitle += ' — ' + titleSuffix;
        pdf.setFontSize(14);
        pdf.text(subtitle, 105, 30, { align: 'center' });

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

        const headers = ['Transaction ID', 'User ID', 'Total Amount (P)', 'Transaction Date', 'Source'];
        // Keep total width <= 165 (printable area): 30+25+40+50+20 = 165
        const colWidths = [30, 25, 40, 50, 20];
        let xPosition = 20;

        // Draw header background
        pdf.setFillColor(0, 0, 0);
        pdf.rect(20, yPosition - 5, 165, 8, 'F');

        pdf.setTextColor(255, 255, 255);
        // use a safe built-in font to avoid glyph/kerning issues
        try { pdf.setFont('helvetica', 'bold'); } catch (e) { /* ignore if not supported */ }
        headers.forEach((header, index) => {
          pdf.text(header, xPosition + 2, yPosition, { maxWidth: colWidths[index] - 4 });
          xPosition += colWidths[index];
        });

        yPosition += 10;
        pdf.setTextColor(0, 0, 0);
        pdf.setFont(undefined, 'normal');

        // Group transactions by month-year for the PDF layout
        const transactionsByMonth = {};
        transactions.forEach(transaction => {
          const date = new Date(transaction.transaction_date);
          const monthYear = date.toLocaleDateString('en-US', { year: 'numeric', month: 'long' });
          if (!transactionsByMonth[monthYear]) transactionsByMonth[monthYear] = [];
          transactionsByMonth[monthYear].push(transaction);
        });

        const sortedMonths = Object.keys(transactionsByMonth).sort((a, b) => new Date(b) - new Date(a));
        let transactionRowIndex = 0;

        sortedMonths.forEach((monthYear) => {
          const monthTransactions = transactionsByMonth[monthYear];
          let monthlyTotal = 0;

          if (yPosition > 250) { pdf.addPage(); yPosition = 20; }

          // Month header
          pdf.setFontSize(14);
          pdf.setFont(undefined, 'bold');
          pdf.setFillColor(0, 0, 0);
          pdf.setTextColor(255, 255, 255);
          pdf.rect(20, yPosition - 3, 165, 12, 'F');
          pdf.text(monthYear, 105, yPosition + 5, { align: 'center' });
          yPosition += 20;

          pdf.setTextColor(0, 0, 0);
          pdf.setFont(undefined, 'normal');

          // Month table headers
          pdf.setFontSize(9);
          pdf.setFont(undefined, 'bold');
          pdf.setFillColor(240, 240, 240);
          pdf.rect(20, yPosition - 5, 165, 8, 'F');
          pdf.setTextColor(0, 0, 0);
          let headerX = 20;
          headers.forEach((header, headerIndex) => { pdf.text(header, headerX + 2, yPosition, { maxWidth: colWidths[headerIndex] - 4 }); headerX += colWidths[headerIndex]; });
          yPosition += 10;
          pdf.setFont(undefined, 'normal');

          monthTransactions.forEach((transaction) => {
            if (yPosition > 270) {
              pdf.addPage();
              yPosition = 20;
              pdf.setFontSize(14); pdf.setFont(undefined, 'bold'); pdf.setFillColor(0, 0, 0); pdf.setTextColor(255, 255, 255);
              pdf.rect(20, yPosition - 3, 165, 12, 'F'); pdf.text(`${monthYear} (continued)`, 105, yPosition + 5, { align: 'center' }); yPosition += 20;
              pdf.setFontSize(9); pdf.setFont(undefined, 'bold'); pdf.setFillColor(240, 240, 240); pdf.rect(20, yPosition - 5, 165, 8, 'F'); pdf.setTextColor(0,0,0);
              let headerX2 = 20; headers.forEach((header, headerIndex) => { pdf.text(header, headerX2 + 2, yPosition, { maxWidth: colWidths[headerIndex] - 4 }); headerX2 += colWidths[headerIndex]; }); yPosition += 10; pdf.setFont(undefined, 'normal');
            }

            xPosition = 20;
            if (transactionRowIndex % 2 === 0) { pdf.setFillColor(250, 250, 250); pdf.rect(20, yPosition - 3, 165, 7, 'F'); }

            const amount = Number(transaction.total_amount || 0);
            // format as ASCII 'P' with no space to avoid special-glyph rendering issues: e.g. P1,234.56
            const formattedAmount = 'P' + amount.toLocaleString('en-US', { minimumFractionDigits: 2 });
            const transactionData = [
              String(transaction.transaction_id || ''),
              String(transaction.user_id || ''),
              formattedAmount,
              String(transaction.transaction_date || ''),
              String(transaction.source || '')
            ];

            transactionData.forEach((data, cellIndex) => { pdf.text(data.toString(), xPosition + 2, yPosition, { maxWidth: colWidths[cellIndex] - 4 }); xPosition += colWidths[cellIndex]; });

            monthlyTotal += parseFloat(transaction.total_amount || 0);
            yPosition += 7;
            transactionRowIndex++;
          });

          yPosition += 3;
          pdf.setFont(undefined, 'bold');
          pdf.setFillColor(220, 220, 220);
          pdf.rect(120, yPosition - 3, 65, 8, 'F');
          pdf.text(`${monthYear} Total: P${monthlyTotal.toLocaleString('en-US', {minimumFractionDigits: 2})}`, 125, yPosition + 2);
          yPosition += 15;
          pdf.setFont(undefined, 'normal');
        });

        // Summary
        yPosition += 10; if (yPosition > 250) { pdf.addPage(); yPosition = 20; }
        pdf.setDrawColor(0, 0, 0); pdf.line(20, yPosition, 190, yPosition); yPosition += 10;
        pdf.setFont(undefined, 'bold'); pdf.setFontSize(12); pdf.text('Summary', 20, yPosition); yPosition += 10;
        pdf.setFont(undefined, 'normal'); pdf.setFontSize(10);

        const totalRecords = transactions.length;
        const totalRevenue = transactions.reduce((s, t) => s + (parseFloat(t.total_amount) || 0), 0);
        pdf.text(`Total Transactions: ${totalRecords}`, 20, yPosition); yPosition += 7;
        pdf.text(`Total Revenue: P${totalRevenue.toLocaleString('en-US', {minimumFractionDigits: 2})}`, 20, yPosition);

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
      }

      // Download button: optionally fetch selected month (if any) then generate PDF
      const downloadBtn = document.getElementById('downloadPDF');
      const downloadMonthInput = document.getElementById('downloadMonth');
      if (downloadBtn) {
        downloadBtn.addEventListener('click', async () => {
          // if month selected, fetch server-side limited dataset; otherwise use embedded allTransactions
          const monthVal = downloadMonthInput && downloadMonthInput.value ? downloadMonthInput.value : '';
          if (monthVal) {
            // fetch transactions for chosen month via AJAX endpoint added in PHP
            try {
              const res = await fetch('customer_orders.php?ajax_month=1&month=' + encodeURIComponent(monthVal));
              if (!res.ok) throw new Error('Network response not ok');
              const data = await res.json();
              // compute pretty month label
              const parts = monthVal.split('-');
              let pretty = monthVal;
              if (parts.length === 2) {
                pretty = new Date(parts[0], parts[1]-1, 1).toLocaleString('en-US', { month: 'long', year: 'numeric' });
              }
              generatePDFFromTransactions(data, pretty);
            } catch (err) {
              alert('Failed to fetch transactions for selected month. Please try again.');
              console.error(err);
            }
          } else {
            // use embedded allTransactions from PHP (reflects current page filter)
            generatePDFFromTransactions(allTransactions, 'All Available');
          }
        });
      }

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

          // clear any lingering closing state then show
          modal.classList.remove('closing');
          modal.classList.add('show');
          modal.setAttribute('aria-hidden', 'false');
          // focus the close button for keyboard users
          const focusTarget = modal.querySelector('.footer-close') || modal.querySelector('#productsModalClose');
          if (focusTarget && typeof focusTarget.focus === 'function') focusTarget.focus();
        }

        function closeProductsModal() {
          if (!modal.classList.contains('show') || modal.classList.contains('closing')) return;
          // start closing animation
          modal.classList.add('closing');
          modal.setAttribute('aria-hidden', 'true');
          // wait for animation to finish before removing show
          setTimeout(() => {
            modal.classList.remove('show');
            modal.classList.remove('closing');
          }, 360);
        }

        if (viewBtn) viewBtn.addEventListener('click', openProductsModal);
        if (modalClose) modalClose.addEventListener('click', closeProductsModal);
        const footerCloseBtn = document.querySelector('.footer-close');
        if (footerCloseBtn) footerCloseBtn.addEventListener('click', closeProductsModal);
        // close when clicking outside content
        if (modal) modal.addEventListener('click', (e) => { if (e.target === modal) closeProductsModal(); });
        // close with Esc
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeProductsModal(); });
    });
  </script>
</body>
</html> 
