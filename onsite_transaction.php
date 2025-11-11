<?php
session_start();
include 'db.php';

if (!isset($_SESSION['username'])) {
  header("Location: index.php");
  exit;
}

// If POSTed from pos.php, process & insert transaction + items
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['transaction_data'])) {
    $transaction_json = $_POST['transaction_data'];
    $data = json_decode($transaction_json, true);

    if ($data && isset($data['total']) && isset($data['items']) && is_array($data['items'])) {
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : $_SESSION['username'];
        $total_amount = floatval($data['total']);
        $source = 'pos';

        $stmt = $conn->prepare("INSERT INTO transactions (user_id, total_amount, transaction_date, source) VALUES (?, ?, NOW(), ?)");
        $stmt->bind_param("sds", $user_id, $total_amount, $source);
        if ($stmt->execute()) {
            $transaction_id = $stmt->insert_id;
            $stmt->close();

            $item_stmt = $conn->prepare("INSERT INTO transaction_items (transaction_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            foreach ($data['items'] as $it) {
                $product_id = $it['id'];
                $qty = intval($it['qty']);
                $price = floatval($it['price']);
                $item_stmt->bind_param("iiid", $transaction_id, $product_id, $qty, $price);
                $item_stmt->execute();
            }
            $item_stmt->close();
            header("Location: onsite_transaction.php?inserted=1");
            exit();
        } else {
            $stmt->close();
            die("Failed to save transaction: " . htmlspecialchars($conn->error));
        }
    } else {
        die("Invalid transaction data.");
    }
}

// Display transactions (onsite/pos)
$records_per_page = 10;
$current_page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($current_page - 1) * $records_per_page;

$total_records = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM transactions WHERE source = 'pos'"))['total'];
$total_pages = max(1, ceil($total_records / $records_per_page));

$transactions_result = mysqli_query($conn, "SELECT * FROM transactions WHERE source = 'pos' ORDER BY transaction_date DESC LIMIT $records_per_page OFFSET $offset");

// monthly sales summary
$monthly_sales_query = "
  SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month, SUM(total_amount) AS total_sales
  FROM transactions
  WHERE source = 'pos'
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Onsite Transactions</title>
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <link rel="stylesheet" href="ot.css">
</head>
<body>

<div class="container" style="display:flex; gap:25px; align-items:flex-start;">

    <!-- LEFT SIDE: TRANSACTION TABLE -->
    <div style="flex:2;">
        <a href="customer_orders.php" class="back-btn">⬅ Back to All Transactions</a>
        <h2>Onsite POS Transactions</h2>

        <?php if (isset($_GET['inserted'])): ?>
          <div style="padding:10px;background:#e6ffea;border:1px solid #b7f0c6;margin:10px 0;border-radius:6px;">
            Transaction saved successfully.
          </div>
        <?php endif; ?>

        <table>
          <thead>
            <tr>
              <th>Transaction ID</th>
              <th>User</th>
              <th>Total Amount (₱)</th>
              <th>Date</th>
              <th>Items</th>
            </tr>
          </thead>
          <tbody>
            <?php while ($t = mysqli_fetch_assoc($transactions_result)) { ?>
              <?php
                $items = [];
                $res = mysqli_query($conn, "SELECT ti.quantity, ti.price, p.name 
                  FROM transaction_items ti 
                  LEFT JOIN products_ko p ON ti.product_id = p.id 
                  WHERE ti.transaction_id = {$t['transaction_id']}");
                while($i = mysqli_fetch_assoc($res)){
                  $items[] = [
                    "name" => $i['name'],
                    "quantity" => $i['quantity'],
                    "price" => $i['price']
                  ];
                }
              ?>
              <tr>
                <td><?= $t['transaction_id'] ?></td>
                <td><?= $t['user_id'] ?></td>
                <td>₱<?= number_format($t['total_amount'],2) ?></td>
                <td><?= $t['transaction_date'] ?></td>
                <td><button class="view-items" data-items='<?= json_encode($items) ?>'>View Items</button></td>
              </tr>
            <?php } ?>
          </tbody>
        </table>

        <div style="margin-top:12px;">
          <?php if ($current_page > 1): ?><a href="?page=<?= $current_page - 1 ?>" class="back-btn" style="background:#004080;margin-right:8px;">← Previous</a><?php endif; ?>
          Page <?= $current_page ?> of <?= $total_pages ?> (Total: <?= $total_records ?> records)
          <?php if ($current_page < $total_pages): ?><a href="?page=<?= $current_page + 1 ?>" class="back-btn" style="background:#004080;margin-left:8px;">Next →</a><?php endif; ?>
        </div>
    </div>

    <!-- RIGHT SIDE: SALES CHART -->
    <div style="flex:1; background:#fff; padding:20px; border-radius:12px; box-shadow:0 3px 8px rgba(0,0,0,0.08);">
        <h3>Sales Summary (Onsite)</h3>
        <p>Total Revenue: <strong>₱<?= number_format($total_revenue,2) ?></strong></p>
        <canvas id="salesChart" style="width:100%; height:260px;"></canvas>
    </div>

</div>

<!-- MODAL -->
<div id="itemsModal" class="modal-overlay">
  <div class="modal-box">
    <h3>Purchased Items</h3>
    <table style="width:100%; border-collapse:collapse;">
      <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Subtotal</th></tr></thead>
      <tbody id="modalItemsBody"></tbody>
    </table>
    <button class="close-btn" onclick="closeModal()">Close</button>
  </div>
</div>

<script>
document.querySelectorAll(".view-items").forEach(btn => {
  btn.addEventListener("click", function() {
    let items = JSON.parse(this.dataset.items);
    let body = document.getElementById("modalItemsBody");
    body.innerHTML = "";
    items.forEach(it => {
      body.innerHTML += `
        <tr>
          <td>${it.name}</td>
          <td>${it.quantity}</td>
          <td>₱${parseFloat(it.price).toFixed(2)}</td>
          <td>₱${(it.quantity * it.price).toFixed(2)}</td>
        </tr>`;
    });
    document.getElementById("itemsModal").style.display = "flex";
  });
});
function closeModal(){ document.getElementById("itemsModal").style.display = "none"; }

new Chart(document.getElementById('salesChart'), {
  type: 'bar',
  data: { labels: <?= json_encode($months) ?>, datasets: [{
    label: 'Monthly Sales (₱)',
    data: <?= json_encode($sales) ?>,
    backgroundColor: 'rgba(0, 64, 128, 0.7)',
    borderColor: '#003060', borderWidth: 1, borderRadius: 8
  }]},
  options: { scales: { y: { beginAtZero: true } } }
});
</script>

</body>
</html>
