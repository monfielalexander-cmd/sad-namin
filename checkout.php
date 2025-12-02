<?php
session_start();
include 'db.php';

if (!isset($_SESSION['id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['id'];

$sql = "SELECT p.name, p.price, c.quantity, (p.price * c.quantity) AS subtotal, c.id as cart_id
        FROM cart c
        JOIN products_ko p ON c.product_id = p.id
        WHERE c.user_id = '$user_id'";
$result = $conn->query($sql);

$total = 0;
$item_count = 0;
$items = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $items[] = $row;
        $total += $row['subtotal'];
        $item_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - Abeth Hardware</title>
    <link rel="stylesheet" href="products.css?v=<?php echo filemtime(__DIR__ . '/products.css'); ?>">
    <style>
        .checkout-container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 40px;
            background: linear-gradient(145deg, #ffffff 0%, #f8faff 100%);
            border-radius: 16px;
            box-shadow: 0 8px 25px rgba(0, 64, 128, 0.15);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }

        .checkout-header {
            text-align: center;
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 2px solid rgba(0, 64, 128, 0.1);
        }

        .checkout-header h1 {
            font-size: 2.2rem;
            color: var(--primary-blue);
            margin-bottom: 10px;
        }

        .checkout-header p {
            color: var(--text-light);
            font-size: 1.05rem;
        }

        .checkout-content {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 40px;
        }

        .cart-items {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 18px 20px;
            background: linear-gradient(145deg, #ffffff 0%, #f8faff 100%);
            border: 2px solid rgba(0, 64, 128, 0.1);
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .cart-item:hover {
            border-color: var(--accent-gold);
            box-shadow: 0 4px 15px rgba(255, 204, 0, 0.15);
        }

        .item-info {
            flex: 1;
        }

        .item-info h3 {
            color: var(--primary-blue);
            margin-bottom: 5px;
            font-size: 1.1rem;
        }

        .item-info p {
            color: var(--text-light);
            font-size: 0.95rem;
            margin: 3px 0;
        }

        .item-price {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 8px;
        }

        .item-price .qty {
            color: var(--text-light);
            font-size: 0.95rem;
        }

        .item-price .price {
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--success-green);
        }

        .order-summary {
            background: linear-gradient(135deg, var(--primary-blue) 0%, #0056b3 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            height: fit-content;
            position: sticky;
            top: 100px;
        }

        .order-summary h2 {
            margin-bottom: 25px;
            font-size: 1.4rem;
            text-align: center;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .summary-row:last-child {
            border-bottom: none;
            padding-top: 15px;
            padding-bottom: 0;
            font-size: 1.2rem;
            font-weight: 700;
            margin-top: 10px;
            border-top: 2px solid rgba(255, 255, 255, 0.3);
        }

        .summary-label {
            color: rgba(255, 255, 255, 0.9);
        }

        .summary-value {
            font-weight: 600;
            color: var(--accent-gold);
        }

        .checkout-btn {
            width: 100%;
            padding: 16px 20px;
            background: linear-gradient(45deg, var(--accent-gold), #ffd700);
            color: var(--primary-blue);
            border: none;
            border-radius: 10px;
            font-size: 1.05rem;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 25px;
            box-shadow: 0 4px 15px rgba(255, 204, 0, 0.3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .checkout-btn:hover {
            background: linear-gradient(45deg, #ffd700, var(--accent-gold));
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 204, 0, 0.4);
        }

        .checkout-btn:active {
            transform: translateY(0);
        }

        .empty-cart {
            text-align: center;
            padding: 60px 40px;
            color: var(--text-light);
        }

        .empty-cart h2 {
            color: var(--primary-blue);
            margin-bottom: 15px;
            font-size: 1.8rem;
        }

        .empty-cart p {
            margin-bottom: 30px;
            font-size: 1.05rem;
        }

        .back-shopping-btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(45deg, var(--secondary-blue), #0080ff);
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(0, 102, 204, 0.2);
        }

        .back-shopping-btn:hover {
            background: linear-gradient(45deg, #0080ff, var(--secondary-blue));
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 102, 204, 0.3);
        }

        @media (max-width: 768px) {
            .checkout-content {
                grid-template-columns: 1fr;
            }

            .order-summary {
                position: static;
            }

            .checkout-container {
                padding: 25px;
                margin: 20px;
            }

            .checkout-header h1 {
                font-size: 1.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="header">
        <h3>Abeth Hardware - Checkout</h3>
        <nav>
            <a href="products.php">Continue Shopping</a>
        </nav>
    </div>

    <div class="checkout-container">
        <div class="checkout-header">
            <h1>Order Summary</h1>
            <p>Review your items before completing purchase</p>
        </div>

        <?php if (empty($items)): ?>
            <div class="empty-cart">
                <h2>Your Cart is Empty</h2>
                <p>No items in your cart yet. Start shopping to add items.</p>
                <a href="products.php" class="back-shopping-btn">← Continue Shopping</a>
            </div>
        <?php else: ?>
            <div class="checkout-content">
                <div class="cart-items">
                    <?php foreach ($items as $item): ?>
                        <div class="cart-item">
                            <div class="item-info">
                                <h3><?php echo htmlspecialchars($item['name']); ?></h3>
                                <p>Quantity: <strong><?php echo $item['quantity']; ?></strong></p>
                                <p>Unit Price: <strong>₱<?php echo number_format($item['price'], 2); ?></strong></p>
                            </div>
                            <div class="item-price">
                                <span class="qty">x<?php echo $item['quantity']; ?></span>
                                <span class="price">₱<?php echo number_format($item['subtotal'], 2); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="order-summary">
                    <h2>Summary</h2>
                    <div class="summary-row">
                        <span class="summary-label">Subtotal</span>
                        <span class="summary-value">₱<?php echo number_format($total, 2); ?></span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Shipping</span>
                        <span class="summary-value">Free</span>
                    </div>
                    <div class="summary-row">
                        <span class="summary-label">Tax</span>
                        <span class="summary-value">Included</span>
                    </div>
                    <div class="summary-row">
                        <span>Total Amount</span>
                        <span>₱<?php echo number_format($total, 2); ?></span>
                    </div>

                    <form method="POST" action="place_order.php" style="display: contents;">
                        <button type="submit" class="checkout-btn">Proceed to Payment</button>
                    </form>

                    <a href="products.php" class="back-shopping-btn" style="display: block; text-align: center; margin-top: 15px; margin-bottom: 0;">← Continue Shopping</a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
