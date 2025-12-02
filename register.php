<?php
session_start();

// ✅ Database Connection
$conn = new mysqli("localhost", "root", "", "hardware_db");

// Check for connection errors
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$registration_error = '';
$form_data = [];

// ✅ Check if form was submitted
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Escape user inputs to prevent SQL injection
    $fname     = $conn->real_escape_string($_POST['fname'] ?? '');
    $lname     = $conn->real_escape_string($_POST['lname'] ?? '');
    $address   = $conn->real_escape_string($_POST['address'] ?? '');
    $email     = $conn->real_escape_string($_POST['email'] ?? '');
    $username  = $conn->real_escape_string($_POST['username'] ?? '');
    $password  = $conn->real_escape_string($_POST['password'] ?? '');
    $confirm_pwd = $conn->real_escape_string($_POST['confirm_password'] ?? '');
    $role      = 'customer'; // Default role for new users

    // Store form data for repopulation
    $form_data = compact('fname', 'lname', 'address', 'email', 'username');

    // Validation
    if (empty($fname) || empty($lname) || empty($email) || empty($username) || empty($password)) {
        $registration_error = "Please fill in all required fields.";
    } elseif ($password !== $confirm_pwd) {
        $registration_error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $registration_error = "Password must be at least 6 characters long.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $registration_error = "Please enter a valid email address.";
    } else {
        // ✅ Check if username already exists
        $check = $conn->query("SELECT * FROM users WHERE username='$username'");
        if ($check && $check->num_rows > 0) {
            $registration_error = "Username already exists. Please choose a different one.";
        } else {
            // ✅ Insert new user into database
            $sql = "INSERT INTO users (fname, lname, address, email, username, password, role) 
                    VALUES ('$fname', '$lname', '$address', '$email', '$username', '$password', '$role')";

            if ($conn->query($sql) === TRUE) {
                echo "<script>
                    alert('Registration successful! Please log in.');
                    window.location='login.php';
                </script>";
                exit();
            } else {
                $registration_error = "Registration failed. Please try again.";
            }
        }
    }
}

// ✅ Close connection
$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Abeth Hardware</title>
    <link rel="stylesheet" href="auth.css?v=<?php echo filemtime(__DIR__ . '/auth.css'); ?>">
    <style>
        .auth-form {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }

        .auth-form .form-group:nth-child(n+5) {
            grid-column: 1 / -1;
        }

        @media (max-width: 480px) {
            .auth-form {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="auth-header">
            <h1>Create Account</h1>
            <p>Join Abeth Hardware and start shopping</p>
        </div>

        <?php if (!empty($registration_error)): ?>
            <div class="message error"><?php echo htmlspecialchars($registration_error); ?></div>
        <?php endif; ?>

        <form method="POST" class="auth-form">
            <div class="form-group">
                <label for="fname">First Name</label>
                <input type="text" id="fname" name="fname" placeholder="First name" value="<?php echo htmlspecialchars($form_data['fname'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="lname">Last Name</label>
                <input type="text" id="lname" name="lname" placeholder="Last name" value="<?php echo htmlspecialchars($form_data['lname'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" placeholder="your@email.com" value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="address">Address</label>
                <input type="text" id="address" name="address" placeholder="Street address" value="<?php echo htmlspecialchars($form_data['address'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" placeholder="Choose username" value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" placeholder="Min. 6 characters" required>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm password" required>
            </div>

            <button type="submit" class="auth-btn" style="grid-column: 1 / -1;">Create Account</button>
        </form>

        <div class="auth-footer">
            <p>Already have an account? <a href="login.php">Sign in here</a></p>
            <p style="margin-top: 10px;"><a href="index.php">← Back to Home</a></p>
        </div>
    </div>
</body>
</html>
