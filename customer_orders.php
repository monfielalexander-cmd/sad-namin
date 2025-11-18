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

// Get total records for online transactions only
$total_records_query = "SELECT COUNT(*) as total FROM transactions WHERE source = 'online'";
$total_records_result = mysqli_query($conn, $total_records_query);
$total_records = mysqli_fetch_assoc($total_records_result)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Get paginated online transactions (for web display)
$transactions_result = mysqli_query($conn, "SELECT * FROM transactions WHERE source = 'online' ORDER BY transaction_date DESC LIMIT $records_per_page OFFSET $offset");

// Get ALL online transactions (for PDF download)
$all_transactions_result = mysqli_query($conn, "SELECT * FROM transactions WHERE source = 'online' ORDER BY transaction_date DESC");
$all_transactions = [];
while ($row = mysqli_fetch_assoc($all_transactions_result)) {
  $all_transactions[] = $row;
}

$monthly_sales_query = "
  SELECT 
    DATE_FORMAT(transaction_date, '%Y-%m') AS month,
    SUM(total_amount) AS total_sales
  FROM transactions
  WHERE source = 'online'
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
  <title>Transactions - Admin Panel</title>
  <link rel="stylesheet" href="admin.css">
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
      <div class="pagination">
        <?php if ($current_page > 1): ?>
          <a href="?page=<?= $current_page - 1 ?>" class="page-btn">← Previous</a>
        <?php endif; ?>

        <span class="page-info">
          Page <?= $current_page ?> of <?= $total_pages ?> (Total: <?= $total_records ?> records)
        </span>

        <?php if ($current_page < $total_pages): ?>
          <a href="?page=<?= $current_page + 1 ?>" class="page-btn">Next →</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- PAGE 2: SALES SUMMARY -->
    <div id="salesPage" class="sales-summary">
      <h2>📊 Online Sales Summary</h2>
      <div class="total-revenue">
        Total Revenue: <strong>₱<?= number_format($total_revenue, 2) ?></strong>
      </div>
      <canvas id="salesChart"></canvas>
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

    // Chart rendering - Wait for DOM to be ready
    document.addEventListener('DOMContentLoaded', function() {
      const chartCanvas = document.getElementById('salesChart');
      if (chartCanvas) {
        const ctx = chartCanvas.getContext('2d');
        const salesChart = new Chart(ctx, {
          type: 'pie',
          data: {
            labels: <?= json_encode($months) ?>,
            datasets: [{
              label: 'Monthly Sales Distribution',
              data: <?= json_encode($sales) ?>,
              backgroundColor: [
                '#2E86C1',
                '#3498DB', 
                '#5DADE2',
                '#85C1E9',
                '#AED6F1',
                '#D6EAF8',
                '#E8F6F3',
                '#F7DC6F',
                '#F4D03F',
                '#F1C40F',
                '#E67E22',
                '#D35400'
              ],
              borderColor: '#ffffff',
              borderWidth: 3,
              hoverBorderWidth: 5,
              hoverBorderColor: '#ffcc00'
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
              legend: {
                display: true,
                position: 'right',
                labels: {
                  font: {
                    family: 'Poppins',
                    size: 13,
                    weight: '700'
                  },
                  color: '#2c3e50',
                  padding: 18,
                  usePointStyle: true,
                  pointStyle: 'circle',
                  boxWidth: 15,
                  boxHeight: 15
                }
              },
              tooltip: {
                backgroundColor: 'rgba(255, 255, 255, 0.95)',
                titleColor: '#2c3e50',
                bodyColor: '#2c3e50',
                borderColor: '#3498DB',
                borderWidth: 2,
                cornerRadius: 12,
                displayColors: true,
                titleFont: {
                  size: 14,
                  weight: 'bold'
                },
                bodyFont: {
                  size: 13,
                  weight: '600'
                },
                padding: 12,
                callbacks: {
                  label: function(context) {
                    const value = context.parsed;
                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                    const percentage = ((value / total) * 100).toFixed(1);
                    return `${context.label}: ₱${value.toLocaleString()} (${percentage}%)`;
                  }
                }
              }
            },
            animation: {
              duration: 2000,
              easing: 'easeOutBounce'
            },
            elements: {
              arc: {
                borderWidth: 3,
                hoverBorderWidth: 5
              }
            }
          }
        });
      }
    });
  </script>
</body>
</html>
