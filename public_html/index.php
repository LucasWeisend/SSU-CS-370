<?php
// ── Database connection ──────────────────────────────────────────────────────
$host    = "localhost";
$db_name = "dreamteam_db";
$db_user = "dreamteam_user";
$db_pass = "777777777";

$conn = new mysqli($host, $db_user, $db_pass, $db_name);
if ($conn->connect_error) {
    die("Could not connect to the database.");
}

// ── Helper ───────────────────────────────────────────────────────────────────
function esc($v) {
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

// ── Fetch ALL active auctions, ordered soonest-ending first ─────────────────
$auctions = $conn->prepare(
    "SELECT p.product_id,
            p.description,
            p.minimum_starting_price,
            p.buyout_price,
            p.end_time,
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

        .login-btn {
            display: inline-block;
            padding: 6px 16px;
            background: #a8d0f5;
            color: #1a3a5c !important;
            border-radius: 4px;
            font-weight: bold;
            font-size: .85rem;
            text-decoration: none !important;
            margin-left: 16px;
            transition: background .15s;
        }
        .login-btn:hover { background: #fff; }

        main {
            max-width: 1100px;
            margin: 36px auto;
            padding: 0 16px 60px;
        }

        h2.page-title {
            font-size: 1.5rem;
            margin-bottom: 8px;
            color: #1a3a5c;
        }
        .page-subtitle {
            font-size: .9rem;
            color: #666;
            margin-bottom: 28px;
        }
        .page-subtitle a { color: #1565c0; }

        /* ── Card / table ── */
        .card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,.1);
            overflow: hidden;
        }
        .card-header {
            background: #1a3a5c;
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

        /* ── Login-to-bid call-to-action cell ── */
        .cta-login {
            display: inline-block;
            padding: 4px 12px;
            background: #e3f2fd;
            color: #1565c0;
            border-radius: 4px;
            font-size: .78rem;
            font-weight: bold;
            text-decoration: none;
            white-space: nowrap;
            transition: background .15s;
        }
        .cta-login:hover { background: #bbdefb; }

        .empty {
            padding: 28px 20px;
            color: #888;
            font-style: italic;
            font-size: .9rem;
        }
    </style>
</head>
<body>

<header>
    <h1>🏆 Dream Team Auction</h1>
    <div>
        <a href="portal.php" class="login-btn">Log In / Register</a>
    </div>
</header>

<main>
    <h2 class="page-title">Active Auctions</h2>
    <p class="page-subtitle">
        Browse all live listings below — ending soonest first. 
    </p>
    <p class="page-subtitle">    
        You are currently in guest mode. Please <a href="portal.php">log in or register</a> to place a bid.
    </p>

    <div class="card">
        <div class="card-header">📋 Items Currently Up for Auction</div>

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
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($auction_rows as $row): ?>
                    <tr>
                        <td><?php echo (int)$row['product_id']; ?></td>
                        <td><?php echo esc($row['description'] ?: '—'); ?></td>
                        <td><?php echo esc($row['seller_email']); ?></td>
                        <td class="money">$<?php echo number_format($row['minimum_starting_price'], 2); ?></td>
                        <td class="money"><strong>$<?php echo number_format($row['current_floor'], 2); ?></strong></td>
                        <td class="money"><?php echo $row['buyout_price'] ? '$' . number_format($row['buyout_price'], 2) : '—'; ?></td>
                        <td><?php echo (int)$row['bid_count']; ?></td>
                        <td><?php echo esc($row['end_time']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</main>
</body>
</html>
