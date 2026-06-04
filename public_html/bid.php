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

// ── Handle bid submission ────────────────────────────────────────────────────
$message  = "";
$msg_type = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['place_bid'])) {
    $pid     = (int)($_POST['product_id'] ?? 0);
    $bid_amt = (float)($_POST['bid_amount'] ?? 0);

    $chk = $conn->prepare(
        "SELECT p.seller_id,
                COALESCE(MAX(b.bid_amount), p.minimum_starting_price) AS floor_price
         FROM products p
         LEFT JOIN bid b ON b.product_id = p.product_id
         WHERE p.product_id = ? AND p.end_time > NOW()
         GROUP BY p.product_id"
    );
    $chk->bind_param("i", $pid);
    $chk->execute();
    $chk->bind_result($seller_id, $floor_price);
    $fetched = $chk->fetch();
    $chk->close();

    if (!$fetched) {
        $message  = "That auction is no longer active or does not exist.";
        $msg_type = "err";
    } elseif ((int)$seller_id === (int)$user_id) {
        $message  = "You cannot bid on your own items.";
        $msg_type = "err";
    } elseif ($bid_amt <= $floor_price) {
        $message  = "Your bid must be higher than the current floor of $" . number_format($floor_price, 2) . ".";
        $msg_type = "err";
    } else {
        $now = date('Y-m-d H:i:s');
        $ins = $conn->prepare(
            "INSERT INTO bid (bidder_id, product_id, bid_amount, bid_timestamp) VALUES (?, ?, ?, ?)"
        );
        $ins->bind_param("iids", $user_id, $pid, $bid_amt, $now);
        if ($ins->execute()) {
            $message  = "Your bid of $" . number_format($bid_amt, 2) . " was placed successfully!";
            $msg_type = "ok";
        } else {
            $message  = "Something went wrong placing your bid. Please try again.";
            $msg_type = "err";
        }
        $ins->close();
    }
}

// ── Fetch ALL active auctions, ordered soonest-ending first ─────────────────
$auctions = $conn->prepare(
    "SELECT p.product_id,
            p.description,
            p.minimum_starting_price,
            p.buyout_price,
            p.end_time,
            p.seller_id,
            COALESCE(MAX(b.bid_amount), p.minimum_starting_price) AS current_floor,
            COUNT(b.bid_id) AS bid_count,
            u.email         AS seller_email
     FROM products p
     JOIN users u ON u.id = p.seller_id
     LEFT JOIN bid b ON b.product_id = p.product_id
     WHERE p.end_time > NOW()
     GROUP BY p.product_id
     ORDER BY p.end_time ASC"
);
$auctions->execute();
$auctions_result = $auctions->get_result();

$auction_rows = [];
while ($row = $auctions_result->fetch_assoc()) {
    $auction_rows[] = $row;
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Active Auctions – Dream Team Auction</title>
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
            max-width: 1100px;
            margin: 36px auto;
            padding: 0 16px 60px;
        }

        h2.page-title {
            font-size: 1.5rem;
            margin-bottom: 28px;
            color: #1a3a5c;
        }

        /* ── Flash messages ── */
        .flash {
            padding: 12px 18px;
            border-radius: 6px;
            margin-bottom: 24px;
            font-size: .9rem;
        }
        .flash-ok  { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .flash-err { background: #fce4ec; color: #c62828; border: 1px solid #f48fb1; }

        /* ── Card / table ── */
        .card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,.1);
            overflow: hidden;
        }
        .card-header {
            background: #e65100;
            color: #fff;
            padding: 14px 20px;
            font-size: 1.05rem;
            font-weight: bold;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .88rem;
        }
        thead th {
            background: #f9f9f9;
            padding: 10px 14px;
            text-align: left;
            border-bottom: 2px solid #e0e0e0;
            color: #444;
            font-size: .77rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        tbody td {
            padding: 11px 14px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fafafa; }

        .money { font-family: 'Courier New', monospace; }

        .badge-own {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 10px;
            font-size: .75rem;
            font-weight: bold;
            background: #e3f2fd;
            color: #1565c0;
        }

        /* ── Inline bid form ── */
        .bid-inline {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .bid-inline input[type="number"] {
            width: 95px;
            padding: 5px 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: .85rem;
        }
        .bid-inline input[type="number"]:focus {
            outline: none;
            border-color: #e65100;
        }
        .bid-inline button {
            padding: 5px 14px;
            background: #e65100;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: .82rem;
            font-weight: bold;
            white-space: nowrap;
            transition: background .15s;
        }
        .bid-inline button:hover { background: #bf360c; }

        .empty {
            padding: 28px 20px;
            color: #888;
            font-style: italic;
            font-size: .9rem;
        }

        @media (max-width: 700px) {
            .bid-inline { flex-direction: column; align-items: flex-start; }
            .bid-inline input[type="number"] { width: 100%; }
            .bid-inline button { width: 100%; }
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
    <h2 class="page-title">Active Auctions</h2>

    <?php if ($message !== ""): ?>
        <div class="flash flash-<?php echo $msg_type === 'ok' ? 'ok' : 'err'; ?>">
            <?php echo esc($message); ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header">⚡ Items Up for Auction — Ending Soonest First</div>

        <?php if (empty($auction_rows)): ?>
            <p class="empty">There are no active auctions at this time. Check back soon!</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th>Seller</th>
                        <th>Starting Price</th>
                        <th>Current Floor</th>
                        <th>Buyout</th>
                        <th>Bids</th>
                        <th>Ends</th>
                        <th>Bid</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($auction_rows as $row):
                    $is_own = ((int)$row['seller_id'] === (int)$user_id);
                ?>
                    <tr>
                        <td><?php echo (int)$row['product_id']; ?></td>
                        <td><?php echo esc($row['description'] ?: '—'); ?></td>
                        <td><?php echo esc($row['seller_email']); ?></td>
                        <td class="money">$<?php echo number_format($row['minimum_starting_price'], 2); ?></td>
                        <td class="money"><strong>$<?php echo number_format($row['current_floor'], 2); ?></strong></td>
                        <td class="money"><?php echo $row['buyout_price'] ? '$' . number_format($row['buyout_price'], 2) : '—'; ?></td>
                        <td><?php echo (int)$row['bid_count']; ?></td>
                        <td><?php echo esc($row['end_time']); ?></td>
                        <td>
                            <?php if ($is_own): ?>
                                <span class="badge-own">Your listing</span>
                            <?php else: ?>
                                <form method="post" action="bid.php" class="bid-inline">
                                    <input type="hidden" name="product_id"
                                           value="<?php echo (int)$row['product_id']; ?>">
                                    <input type="number"
                                           name="bid_amount"
                                           min="<?php echo number_format($row['current_floor'] + 0.01, 2, '.', ''); ?>"
                                           step="0.01"
                                           placeholder="$<?php echo number_format($row['current_floor'] + 0.01, 2); ?>"
                                           required>
                                    <button type="submit" name="place_bid">Bid ↑</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
