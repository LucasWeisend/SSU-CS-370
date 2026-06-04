<?php
session_start();

// ── Database connection ──────────────────────────────────────────────────────
$host    = "localhost";
$db_name = "dreamteam_db";
$db_user = "dreamteam_user";
$db_pass = "777777777";

$conn = new mysqli($host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Could not connect to the database.");
}

// ── Auth guard ───────────────────────────────────────────────────────────────
if (!isset($_SESSION['user_email'])) {
    header("Location: portal.php");
    exit();
}

// ── Helper ───────────────────────────────────────────────────────────────────
function esc($v) {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// ── Resolve logged-in user id ────────────────────────────────────────────────
$email = $_SESSION['user_email'];
$stmt  = $conn->prepare("SELECT id FROM users WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->bind_result($user_id);
$stmt->fetch();
$stmt->close();

// ── Handle listing submission ────────────────────────────────────────────────
$message   = "";
$msg_type  = "";
$form_vals = [];   // repopulate on error

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['list_item'])) {

    $description   = trim($_POST['description']    ?? '');
    $start_price   = (float)($_POST['start_price'] ?? 0);
    $buyout_raw    = trim($_POST['buyout_price']    ?? '');
    $buyout_price  = ($buyout_raw !== '') ? (float)$buyout_raw : null;
    $start_time_in = trim($_POST['start_time']      ?? '');

    // Keep values for repopulation
    $form_vals = [
        'description'  => $description,
        'start_price'  => $start_price,
        'buyout_price' => $buyout_raw,
        'start_time'   => $start_time_in,
    ];

    // ── Validation ──────────────────────────────────────────────────────────
    if ($description === '') {
        $message  = "Please enter a description for your item.";
        $msg_type = "err";
    } elseif ($start_price <= 0) {
        $message  = "Starting bid price must be greater than $0.00.";
        $msg_type = "err";
    } elseif ($buyout_price !== null && $buyout_price <= $start_price) {
        $message  = "Buyout price must be greater than the starting bid price.";
        $msg_type = "err";
    } elseif ($start_time_in === '') {
        $message  = "Please enter a start date and time for the auction.";
        $msg_type = "err";
    } else {
        // Parse start time and compute end time (168 hours = 7 days later)
        $start_ts = strtotime($start_time_in);
        if ($start_ts === false) {
            $message  = "Invalid start date/time format.";
            $msg_type = "err";
        } else {
            $start_dt = date('Y-m-d H:i:s', $start_ts);
            $end_dt   = date('Y-m-d H:i:s', $start_ts + 168 * 3600);

            $ins = $conn->prepare(
                "INSERT INTO products
                    (seller_id, minimum_starting_price, buyout_price, description, start_time, end_time)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            // buyout_price is nullable – pass as string "NULL" or actual value
            if ($buyout_price !== null) {
                $ins->bind_param("iddsss", $user_id, $start_price, $buyout_price, $description, $start_dt, $end_dt);
            } else {
                // Use a separate path for NULL buyout
                $ins = $conn->prepare(
                    "INSERT INTO products
                        (seller_id, minimum_starting_price, buyout_price, description, start_time, end_time)
                     VALUES (?, ?, NULL, ?, ?, ?)"
                );
                $ins->bind_param("idsss", $user_id, $start_price, $description, $start_dt, $end_dt);
            }

            if ($ins->execute()) {
                $new_id   = $conn->insert_id;
                $message  = "Your item has been listed! (Auction #$new_id) It runs from $start_dt to $end_dt.";
                $msg_type = "ok";
                $form_vals = [];   // clear form on success
            } else {
                $message  = "Something went wrong creating the listing. Please try again.";
                $msg_type = "err";
            }
            $ins->close();
        }
    }
}

$conn->close();

// ── Default start time: now, rounded to next minute ─────────────────────────
$default_start = date('Y-m-d\TH:i', ceil(time() / 60) * 60);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sell an Item – Dream Team Auction</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, sans-serif;
            background: #f0f2f5;
            color: #222;
            min-height: 100vh;
        }

        /* ── Top bar ── */
        header {
            background: #1a3a5c;
            color: #fff;
            padding: 14px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header h1 { font-size: 1.2rem; letter-spacing: .03em; }
        header a {
            color: #a8d0f5;
            text-decoration: none;
            margin-left: 16px;
            font-size: .85rem;
        }
        header a:hover { text-decoration: underline; }
        header .user-info { font-size: .85rem; opacity: .85; }

        main {
            max-width: 620px;
            margin: 36px auto;
            padding: 0 16px 60px;
        }

        h2.page-title {
            font-size: 1.5rem;
            margin-bottom: 28px;
            color: #1a3a5c;
        }

        /* ── Card ── */
        .card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,.1);
            overflow: hidden;
        }
        .card-header {
            background: #2e7d32;
            color: #fff;
            padding: 14px 20px;
            font-size: 1.05rem;
            font-weight: bold;
        }
        .card-body { padding: 28px 32px; }

        /* ── Form ── */
        .form-group { margin-bottom: 22px; }
        label {
            display: block;
            font-size: .88rem;
            font-weight: bold;
            color: #444;
            margin-bottom: 6px;
        }
        .optional {
            font-weight: normal;
            color: #888;
            font-size: .78rem;
            margin-left: 4px;
        }
        textarea, input[type="number"], input[type="datetime-local"] {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccc;
            border-radius: 5px;
            font-size: .93rem;
            font-family: inherit;
            background: #fafafa;
            transition: border-color .2s;
        }
        textarea { resize: vertical; min-height: 90px; }
        textarea:focus,
        input[type="number"]:focus,
        input[type="datetime-local"]:focus {
            outline: none;
            border-color: #2e7d32;
            background: #fff;
        }

        .hint {
            font-size: .78rem;
            color: #777;
            margin-top: 5px;
            line-height: 1.4;
        }

        /* ── Duration callout box ── */
        .info-box {
            background: #e8f5e9;
            border: 1px solid #a5d6a7;
            border-radius: 6px;
            padding: 12px 16px;
            font-size: .85rem;
            color: #1b5e20;
            margin-bottom: 24px;
            line-height: 1.5;
        }
        .info-box strong { display: block; margin-bottom: 2px; }

        /* ── Price row ── */
        .price-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        /* ── Submit ── */
        .btn-submit {
            width: 100%;
            padding: 13px;
            background: #2e7d32;
            color: #fff;
            border: none;
            border-radius: 5px;
            font-size: 1rem;
            font-weight: bold;
            cursor: pointer;
            letter-spacing: .03em;
            transition: background .2s;
        }
        .btn-submit:hover { background: #1b5e20; }

        /* ── Flash messages ── */
        .flash {
            padding: 12px 18px;
            border-radius: 6px;
            margin-bottom: 24px;
            font-size: .9rem;
            line-height: 1.5;
        }
        .flash-ok  { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .flash-err { background: #fce4ec; color: #c62828; border: 1px solid #f48fb1; }

        /* ── Divider ── */
        hr { border: none; border-top: 1px solid #eee; margin: 22px 0; }

        @media (max-width: 480px) {
            .price-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<header>
    <h1>🏆 Dream Team Auction</h1>
    <div>
        <span class="user-info">Logged in as <?php echo esc($email); ?></span>
        <a href="transactions.php">My Transactions</a>
        <a href="bid.php">Make a Bid</a>
        <a href="sell.php">Sell an Item</a>
        <a href="portal.php">← Back to Portal</a>
    </div>
</header>

<main>
    <h2 class="page-title">Sell an Item</h2>

    <?php if ($message !== ""): ?>
        <div class="flash flash-<?php echo $msg_type === 'ok' ? 'ok' : 'err'; ?>">
            <?php echo esc($message); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">🛒 Create a New Listing</div>
        <div class="card-body">

            <div class="info-box">
                <strong>📅 Auction Duration: 7 days (168 hours)</strong>
                All auctions automatically close exactly 168 hours after the start time you choose below.
            </div>

            <form method="post" action="sell.php">

                <!-- Description -->
                <div class="form-group">
                    <label for="description">Item Description</label>
                    <textarea id="description"
                              name="description"
                              placeholder="Describe what you're selling — condition, size, model, etc."
                              required><?php echo esc($form_vals['description'] ?? ''); ?></textarea>
                </div>

                <hr>

                <!-- Prices -->
                <div class="price-row">
                    <div class="form-group">
                        <label for="start_price">Starting Bid Price ($)</label>
                        <input type="number"
                               id="start_price"
                               name="start_price"
                               min="0.01"
                               step="0.01"
                               placeholder="e.g. 1.00"
                               value="<?php echo esc($form_vals['start_price'] ?? ''); ?>"
                               required>
                        <p class="hint">The minimum amount bidders must open with.</p>
                    </div>

                    <div class="form-group">
                        <label for="buyout_price">Buyout Price ($) <span class="optional">(optional)</span></label>
                        <input type="number"
                               id="buyout_price"
                               name="buyout_price"
                               min="0.01"
                               step="0.01"
                               placeholder="e.g. 99.99"
                               value="<?php echo esc($form_vals['buyout_price'] ?? ''); ?>">
                        <p class="hint">Instant-win price. Must be above the starting price. Leave blank for none.</p>
                    </div>
                </div>

                <hr>

                <!-- Start time -->
                <div class="form-group">
                    <label for="start_time">Auction Start Date &amp; Time</label>
                    <input type="datetime-local"
                           id="start_time"
                           name="start_time"
                           value="<?php echo esc($form_vals['start_time'] ?? $default_start); ?>"
                           required>
                    <p class="hint">
                        The auction will run from this moment until exactly 168 hours (7 days) later.<br>
                        You can schedule it to start in the future.
                    </p>
                </div>

                <button type="submit" name="list_item" class="btn-submit">List Item for Auction 🚀</button>
            </form>

        </div>
    </div>
</main>
</body>
</html>
