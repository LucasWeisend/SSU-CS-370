<?php
session_start();

// database connection stuff
$host = "localhost";
$database_name = "dreamteam_db";
$database_user = "dreamteam_user";
$database_password = "777777777";

// connect to database
$connection = new mysqli($host, $database_user, $database_password, $database_name);

// stop if database fails
if ($connection->connect_error) {
    die("Could not connect to the database.");
}

// 5 minutes
$session_limit = 300;
$message = "";

// small helper for html output
function esc($text){
    // this will convert special characters to HTML entities to prevent XSS attacks
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}


// check inactivity timeout
if (isset($_SESSION['last_seen'])) {
    // if last seen is more than 5 minutes ag
    if (time() - $_SESSION['last_seen'] > $session_limit) {
        session_unset(); // clear session data
    session_destroy(); // destroy session
        session_start(); // start a new session for the message
        $message = "You were logged out after being inactive for 5 minutes.";
    }
}

// update time after activity
$_SESSION['last_seen'] = time();


// logout request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout']))
{
session_unset(); // clear session data
    session_destroy(); // destroy session
    header("Location: portal.php"); // redirect to self to show message
    exit();
}


// handle login/register only if not logged in
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_SESSION['user_email'])) {

// get and trim inputs
$user_email = trim($_POST['email'] ?? '');
$user_password = $_POST['password'] ?? '';
        // basic validation
    if (!filter_var($user_email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
    }
    // catches if the password is empty or just spaces
    else if ($user_password == '') {
        $message = "Please enter a password.";
    }
    else {

        // register section
        if (isset($_POST['register'])) {
    // check if email is already used
            $check_user = $connection->prepare("SELECT id FROM users WHERE email = ?");
            $check_user->bind_param("s", $user_email);
            $check_user->execute();
            $check_user->store_result();
                // if we found a user with that email, show error
            if ($check_user->num_rows > 0){
                $message = "That email is already being used.";
            }else {
                    // hash the password before storing
                $hashed = password_hash($user_password, PASSWORD_DEFAULT);
                    // use prepared statement to prevent SQL injection
                $add_user = $connection->prepare("INSERT INTO users (email, password) VALUES (?, ?)");
                $add_user->bind_param("ss", $user_email, $hashed);

                if ($add_user->execute()) {
                    $message = "Account created. You can log in now.";  // asks user to log back in after registration
                }
                else{
                    $message = "Something went wrong while creating the account.";  // just in case
                }

                $add_user->close();
            }

        $check_user->close();
        }

        // login section
        if (isset($_POST['login'])) {
        // find user by email
            $find_user = $connection->prepare("SELECT password FROM users WHERE email = ?");
            $find_user->bind_param("s", $user_email);
            $find_user->execute(); // execute the query
            $find_user->store_result();     // store result to check num_rows and bind_result later

            // if we found a user with that email, verify password
            if ($find_user->num_rows === 1) {
                $find_user->bind_result($stored_hash);
                $find_user->fetch();    // fetch the result into $stored_hash

            if (password_verify($user_password, $stored_hash)) {
                    $_SESSION['user_email'] = $user_email; // store email in session to indicate logged in
                    $_SESSION['last_seen'] = time();
                    $message = "Login successful."; // confimration message
                } else {
                    $message = "Wrong password.";   // didnt match the hash, wrong password
                }

            } else {
                $message = "No account found with that email."; // no user with that email
            }

            $find_user->close();    // close the statement
    }
    }
}

$connection->close();   // close the database connection
// the rest of the file is just HTML with some embedded PHP for dynamic content
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Dream Team Portal</title>

    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            display:flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

    .container {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            text-align: center;
            width: 300px;
        }


    input {
            width: 90%;
            padding: 8px;
            margin-top: 5px;
        }

    button{
            padding: 8px 12px;
            margin: 5px;
            cursor: pointer;
        }
    </style>
</head>

<body>
<div class="container">
    <h1>Dream Team Auction Website</h1>

    <?php if ($message !== ""): ?>
        <p><?php echo esc($message); ?></p>
    <?php endif; ?>

    <?php if (isset($_SESSION['user_email'])): ?>
        <p>Welcome, <?php echo esc($_SESSION['user_email']); ?>.</p>

        <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 16px;">
            <a href="transactions.php" style="text-decoration: none;">
                <button type="button" style="width: 100%; padding: 10px; background-color: #1a3a5c; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">My Transactions</button>
            </a>
            <a href="bid.php" style="text-decoration: none;">
                <button type="button" style="width: 100%; padding: 10px; background-color: #1a3a5c; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">Make a Bid</button>
            </a>
            <a href="sell.php" style="text-decoration: none;">
                <button type="button" style="width: 100%; padding: 10px; background-color: #1a3a5c; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">Sell an Item</button>
            </a>

            <hr style="border: none; border-top: 1px solid #ddd; margin: 4px 0;">

            <form method="post" action="portal.php">
                <button type="submit" name="logout" style="width: 100%; padding: 10px; background-color: #c62828; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 0.95rem;">Log Out</button>
            </form>
        </div>

	

    <?php else: ?>
        <h2>Register or Log In</h2>

        <form method="post" action="portal.php">
            <p>
                <label for="email">Email address:</label><br>
                <input type="email" id="email" name="email" required>

            </p>

            <p>
                <label for="password">Password:</label><br>
                <input type="password" id="password" name="password" required>
            </p>


           <p>
                <button type="submit" name="register">Register</button>
                <button type="submit" name="login">Log In</button>
            </p>

        </form>
        
        <hr style="border: none; border-top: 1px solid #ddd; margin: 16px 0;">
 
        <a href="index.php" style="text-decoration: none;">
            <button type="button" style="width: 100%; padding: 8px 12px; background-color: #f4f4f4; color: #555; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; font-size: 0.9rem;">Guest Mode</button>
        </a>

    <?php endif; ?>
</div>

</body>

</html>
