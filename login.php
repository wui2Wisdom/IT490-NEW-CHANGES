<?php
session_start();
require_once __DIR__ . '/vendor/autoload.php';
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;


function sendToQueue($message) {
    $connection = new AMQPStreamConnection('localhost', 5672, 'guest', 'guest');
    $channel = $connection->channel();
    
    $channel->queue_declare('login', false, true, false, false);

    $msg = new AMQPMessage(json_encode($message), ['delivery_mode' => 2]);
    $channel->basic_publish($msg, '', 'login');

    $channel->close();
    $connection->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = "Username and password are required.";
    } else {
        $message = [
            'username' => $username,
            'password' => $password
        ];
    
        sendToQueue($message);

        $pdo = new PDO("mysql:host=localhost;dbname=IT490", 'IT490', 'password');
        $stmt = $pdo->prepare("SELECT session_key FROM sessions WHERE user_id = (SELECT id FROM users WHERE username = :username) ORDER BY created_at DESC LIMIT 1");
        $stmt->execute(['username' => $username]);
        $session = $stmt->fetch();

        if ($session) {
            $_SESSION['username'] = $username;
            $_SESSION['session_key'] = $session['session_key'];
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "Invalid login credentials.";
        }
    }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<link rel="stylesheet" href="Frontend/CSS/styles.css">
</head>

<body>
	<div class="login_form">
		<form method="post">
			<h2 class="login">Login</h2>
			<?php if (isset($error)) echo "<p style='color: red;'>$error</p>"; ?>
    	    		<?php if (isset($success)) echo "<p style='color: green;'>$success</p>"; ?>
			<div>
				<div class="input_box">
					<label for="email">Username</label>
					<input type="text" id="username" name="username" placeholder="Enter username"/>
				</div>
				<div class="input_box">
					<div>
						<label for="password">Password</label>
					</div>
					<input type="password" id="password" name="password" placeholder="Enter password"/>
				</div>
				<button type="submit">Log In</button>
				<p class="sign_up">Don't have an account? <a href="register.php">Sign up</a></p>
		</form>
	</div>
</body>

</html>
