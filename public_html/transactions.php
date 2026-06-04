<?php
session_start();

// ── Database connection ──────────────────────────────────────────────────────
$host     = "localhost";
$db_name  = "dreamteam_db";
$db_user  = "dreamteam_user";
$db_pass  = "777777777";

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

// ── Handle "increase bid" POST ────────────────────────────────────────────────
$bid_message = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['increase_bid'])) {
    $pid        = (int)($_POST['product_id'] ?? 0);
    $new_amount = (float)($_POST['new_bid']   ?? 0);

    // Fetch current highest bid and minimum starting price for validation
    $val = $conn->prepare(
        "SELECT COALESCE(MAX(b.bid_amount), p.minimum_starting_price)
         FROM products p
         LEFT JOIN bid b ON b.product_id = p.product_id
         WHERE p.product_id = ? AND p.end_time > NOW()"
    );
    $val->bind_param("i", $pid);
    $val->execute();
    $val->bind_result($current_high);
    $val->fetch();
    $val->close();

    if ($current_high === null) {
        $bid_message = "That auction is no longer active.";
    } elseif ($new_amount <= $current_high) {
        $bid_message = "Your bid must be higher than the current highest bid of $" . number_format($current_high, 2) . ".";
    } else {
        $now  = date('Y-m-d H:i:s');
        $ins  = $conn->prepare(
            "INSERT INTO bid (bidder_id, product_id, bid_amount, bid_timestamp) VALUES (?, ?, ?, ?)"
        );
        $ins->bind_param("iids", $user_id, $pid, $new_amount, $now);
        if ($ins->execute()) {
            $bid_message = "Bid of $" . number_format($new_amount, 2) . " placed successfully!";
        } else {
            $bid_message = "Something went wrong placing your bid.";
        }
        $ins->close();
    }
}

// ════════════════════════════════════════════════════════════════════════════
//  QUERIES
// ════════════════════════════════════════════════════════════════════════════

// ── 1. SELLING – active listings ─────────────────────────────────────────────
$selling_active = $conn->prepare(
    "SELECT p.product_id,
            p.description,
            p.minimum_starting_price,
            p.buyout_price,
            p.end_time,
            COALESCE(MAX(b.bid_amount), 0) AS current_bid,
            COUNT(b.bid_id)               AS bid_count
     FROM products p
     LEFT JOIN bid b ON b.product_id = p.product_id
     WHERE p.seller_id = ? AND p.end_time > NOW()
     GROUP BY p.product_id"
);
$selling_active->bind_param("i", $user_id);
$selling_active->execute();
$selling_active_result = $selling_active->get_result();

// ── 2. SELLING – closed / sold ────────────────────────────────────────────────
$selling_closed = $conn->prepare(
    "SELECT p.product_id,
            p.description,
            p.end_time,
            s.final_price,
            u.email AS buyer_email
     FROM products p
     LEFT JOIN sale s ON s.product_id = p.product_id
     LEFT JOIN users u ON u.id = s.buyer_id
     WHERE p.seller_id = ? AND p.end_time <= NOW()
     ORDER BY p.end_time DESC"
);
$selling_closed->bind_param("i", $user_id);
$selling_closed->execute();
$selling_closed_result = $selling_closed->get_result();

// ── 3. PURCHASES ──────────────────────────────────────────────────────────────
$purchases = $conn->prepare(
    "SELECT p.product_id,
            p.description,
            p.end_time,
            s.final_price,
            u.email AS seller_email
     FROM sale s
     JOIN products p ON p.product_id = s.product_id
     JOIN users    u ON u.id = p.seller_id
     WHERE s.buyer_id = ?
     ORDER BY p.end_time DESC"
);
$purchases->bind_param("i", $user_id);
$purchases->execute();
$purchases_result = $purchases->get_result();

// ── 4. CURRENT BIDS ───────────────────────────────────────────────────────────
//    Items where the auction is still open and the user has placed at least one bid
$current_bids = $conn->prepare(
    "SELECT p.product_id,
            p.description,
            p.end_time,
            MAX(b_all.bid_amount)  AS highest_bid,
            MAX(b_me.bid_amount)   AS my_highest_bid,
            (SELECT bidder_id
             FROM bid
             WHERE product_id = p.product_id
             ORDER BY bid_amount DESC, bid_timestamp ASC
             LIMIT 1)             AS top_bidder_id
     FROM products p
     JOIN bid b_me  ON b_me.product_id  = p.product_id AND b_me.bidder_id  = ?
     JOIN bid b_all ON b_all.product_id = p.product_id
     WHERE p.end_time > NOW()
     GROUP BY p.product_id"
);
$current_bids->bind_param("i", $user_id);
$current_bids->execute();
$current_bids_result = $current_bids->get_result();

// ── 5. DIDN'T WIN ─────────────────────────────────────────────────────────────
//    Closed auctions the user bid on but did NOT win
$didnt_win = $conn->prepare(
    "SELECT p.product_id,
            p.description,
            p.end_time,
            MAX(b_all.bid_amount) AS winning_bid
     FROM products p
     JOIN bid b_me  ON b_me.product_id  = p.product_id AND b_me.bidder_id = ?
     JOIN bid b_all ON b_all.product_id = p.product_id
     WHERE p.end_time <= NOW()
       AND p.product_id NOT IN (SELECT product_id FROM sale WHERE buyer_id = ?)
     GROUP BY p.product_id
     ORDER BY p.end_time DESC"
);
$didnt_win->bind_param("ii", $user_id, $user_id);
$didnt_win->execute();
$didnt_win_result = $didnt_win->get_result();

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Transactions – Dream Team Auction</title>
    <style>
        /* ── Reset / base ─────────────────────────────────────── */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: Arial, sans-serif;
            background: #f0f2f5;
            color: #222;
            min-height: 100vh;
        }

        /* ── Top bar ──────────────────────────────────────────── */
        header {
            background: #1a3a5c;
            color: #fff;
            padding: 14px 28px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        header h1 { font-size: 1.2rem; letter-spacing: .03em; }
        header .user-info { font-size: .85rem; opacity: .85; }
        header a {
            color: #a8d0f5;
            text-decoration: none;
            margin-left: 16px;
            font-size: .85rem;
        }
        header a:hover { text-decoration: underline; }

        /* ── Layout ───────────────────────────────────────────── */
        main {
            max-width: 1000px;
            margin: 32px auto;
            padding: 0 16px 60px;
        }

        h2.page-title {
            font-size: 1.5rem;
            margin-bottom: 28px;
            color: #1a3a5c;
        }

        /* ── Category sections ────────────────────────────────── */
        .section {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 4px rgba(0,0,0,.1);
            margin-bottom: 32px;
            overflow: hidden;
        }

        .section-header {
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .section-header h3 { font-size: 1.05rem; color: #fff; }
        .section-header .count {
            background: rgba(255,255,255,.3);
            border-radius: 12px;
            padding: 1px 8px;
            font-size: .78rem;
            color: #fff;
        }

        /* colour-coded headers */
        .hdr-selling  { background: #2e7d32; }
        .hdr-purchases{ background: #1565c0; }
        .hdr-bids     { background: #e65100; }
        .hdr-lost     { background: #6a1b9a; }

        /* subsection label inside selling */
        .subsection-label {
            padding: 8px 20px;
            background: #f5f5f5;
            border-bottom: 1px solid #e0e0e0;
            font-size: .8rem;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #555;
        }

        /* ── Table ────────────────────────────────────────────── */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: .88rem;
        }
        thead th {
            background: #f9f9f9;
            padding: 10px 16px;
            text-align: left;
            border-bottom: 2px solid #e0e0e0;
            color: #444;
            font-size: .78rem;
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        tbody td {
            padding: 11px 16px;
            border-bottom: 1px solid #f0f0f0;
            vertical-align: middle;
        }
        tbody tr:last-child td { border-bottom: none; }
        tbody tr:hover { background: #fafafa; }

        /* ── Badges ───────────────────────────────────────────── */
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: .75rem;
            font-weight: bold;
        }
        .badge-active  { background: #e8f5e9; color: #2e7d32; }
        .badge-sold    { background: #e3f2fd; color: #1565c0; }
        .badge-unsold  { background: #fce4ec; color: #c62828; }
        .badge-winning { background: #e8f5e9; color: #2e7d32; }
        .badge-outbid  { background: #fff3e0; color: #e65100; }

        /* ── Warning row ─────────────────────────────────────── */
        .outbid-warn {
            color: #c62828;
            font-size: .82rem;
            font-weight: bold;
        }

        /* ── Bid form ─────────────────────────────────────────── */
        .bid-form {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .bid-form input[type="number"] {
            width: 90px;
            padding: 5px 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-size: .85rem;
        }
        .bid-form button {
            padding: 5px 12px;
            background: #e65100;
            color: #fff;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: .82rem;
            white-space: nowrap;
        }
        .bid-form button:hover { background: #bf360c; }

        /* ── Empty state ─────────────────────────────────────── */
        .empty {
            padding: 22px 20px;
            color: #888;
            font-style: italic;
            font-size: .88rem;
        }

        /* ── Flash message ───────────────────────────────────── */
        .flash {
            padding: 12px 18px;
            border-radius: 6px;
            margin-bottom: 24px;
            font-size: .9rem;
        }
        .flash-ok  { background: #e8f5e9; color: #2e7d32; border: 1px solid #a5d6a7; }
        .flash-err { background: #fce4ec; color: #c62828; border: 1px solid #f48fb1; }

        /* ── Money ───────────────────────────────────────────── */
        .money { font-family: 'Courier New', monospace; }
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
    <h2 class="page-title">My Transactions</h2>

    <?php if ($bid_message !== ""): ?>
        <?php $flash_class = str_contains($bid_message, "successfully") ? "flash-ok" : "flash-err"; ?>
        <div class="flash <?php echo $flash_class; ?>"><?php echo esc($bid_message); ?></div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════════════════════════
         1. SELLING
    ══════════════════════════════════════════════════════════════ -->
    <?php
        $active_rows = $selling_active_result->num_rows;
        $closed_rows = $selling_closed_result->num_rows;
        $total_selling = $active_rows + $closed_rows;
    ?>
    <div class="section">
        <div class="section-header hdr-selling">
            <h3>🛒 Selling</h3>
            <span class="count"><?php echo $total_selling; ?> item<?php echo $total_selling !== 1 ? 's' : ''; ?></span>
        </div>

        <!-- Active listings -->
        <div class="subsection-label">Active Listings</div>
        <?php if ($active_rows === 0): ?>
            <p class="empty">No active listings.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th>Starting Price</th>
                        <th>Buyout Price</th>
                        <th>Current Bid</th>
                        <th>Bids</th>
                        <th>Ends</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $selling_active_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo esc($row['product_id']); ?></td>
                        <td><?php echo esc($row['description'] ?: '—'); ?></td>
                        <td class="money">$<?php echo number_format($row['minimum_starting_price'], 2); ?></td>
                        <td class="money"><?php echo $row['buyout_price'] ? '$' . number_format($row['buyout_price'], 2) : '—'; ?></td>
                        <td class="money"><?php echo $row['current_bid'] > 0 ? '$' . number_format($row['current_bid'], 2) : 'No bids yet'; ?></td>
                        <td><?php echo (int)$row['bid_count']; ?></td>
                        <td><?php echo esc($row['end_time']); ?></td>
                        <td><span class="badge badge-active">Active</span></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- Closed listings -->
        <div class="subsection-label">Closed Listings</div>
        <?php if ($closed_rows === 0): ?>
            <p class="empty">No closed listings.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th>Ended</th>
                        <th>Final Price</th>
                        <th>Buyer</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $selling_closed_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo esc($row['product_id']); ?></td>
                        <td><?php echo esc($row['description'] ?: '—'); ?></td>
                        <td><?php echo esc($row['end_time']); ?></td>
                        <td class="money">
                            <?php echo $row['final_price'] ? '$' . number_format($row['final_price'], 2) : '—'; ?>
                        </td>
                        <td><?php echo $row['buyer_email'] ? esc($row['buyer_email']) : '—'; ?></td>
                        <td>
                            <?php if ($row['final_price']): ?>
                                <span class="badge badge-sold">Sold</span>
                            <?php else: ?>
                                <span class="badge badge-unsold">No Sale</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div><!-- /selling -->


    <!-- ══════════════════════════════════════════════════════════
         2. PURCHASES
    ══════════════════════════════════════════════════════════════ -->
    <?php $purchase_count = $purchases_result->num_rows; ?>
    <div class="section">
        <div class="section-header hdr-purchases">
            <h3>📦 Purchases</h3>
            <span class="count"><?php echo $purchase_count; ?> item<?php echo $purchase_count !== 1 ? 's' : ''; ?></span>
        </div>
        <?php if ($purchase_count === 0): ?>
            <p class="empty">No purchases yet.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th>Auction Ended</th>
                        <th>Price Paid</th>
                        <th>Seller</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $purchases_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo esc($row['product_id']); ?></td>
                        <td><?php echo esc($row['description'] ?: '—'); ?></td>
                        <td><?php echo esc($row['end_time']); ?></td>
                        <td class="money">$<?php echo number_format($row['final_price'], 2); ?></td>
                        <td><?php echo esc($row['seller_email']); ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div><!-- /purchases -->


    <!-- ══════════════════════════════════════════════════════════
         3. CURRENT BIDS
    ══════════════════════════════════════════════════════════════ -->
    <?php $bids_count = $current_bids_result->num_rows; ?>
    <div class="section">
        <div class="section-header hdr-bids">
            <h3>⚡ Current Bids</h3>
            <span class="count"><?php echo $bids_count; ?> item<?php echo $bids_count !== 1 ? 's' : ''; ?></span>
        </div>
        <?php if ($bids_count === 0): ?>
            <p class="empty">You are not bidding on any active auctions.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th>Auction Ends</th>
                        <th>Highest Bid</th>
                        <th>My Highest Bid</th>
                        <th>Standing</th>
                        <th>Increase Bid</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $current_bids_result->fetch_assoc()):
                    $is_winning = ((int)$row['top_bidder_id'] === (int)$user_id);
                ?>
                    <tr>
                        <td><?php echo esc($row['product_id']); ?></td>
                        <td><?php echo esc($row['description'] ?: '—'); ?></td>
                        <td><?php echo esc($row['end_time']); ?></td>
                        <td class="money">$<?php echo number_format($row['highest_bid'], 2); ?></td>
                        <td class="money">$<?php echo number_format($row['my_highest_bid'], 2); ?></td>
                        <td>
                            <?php if ($is_winning): ?>
                                <span class="badge badge-winning">✓ Winning</span>
                            <?php else: ?>
                                <span class="badge badge-outbid">Outbid</span><br>
                                <span class="outbid-warn">⚠ You've been outbid!</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="post" action="transactions.php" class="bid-form">
                                <input type="hidden" name="product_id" value="<?php echo (int)$row['product_id']; ?>">
                                <input type="number"
                                       name="new_bid"
                                       min="<?php echo number_format($row['highest_bid'] + 0.01, 2, '.', ''); ?>"
                                       step="0.01"
                                       placeholder="$ amount"
                                       required>
                                <button type="submit" name="increase_bid">Bid ↑</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div><!-- /current bids -->


    <!-- ══════════════════════════════════════════════════════════
         4. DIDN'T WIN
    ══════════════════════════════════════════════════════════════ -->
    <?php $lost_count = $didnt_win_result->num_rows; ?>
    <div class="section">
        <div class="section-header hdr-lost">
            <h3>😔 Didn't Win</h3>
            <span class="count"><?php echo $lost_count; ?> item<?php echo $lost_count !== 1 ? 's' : ''; ?></span>
        </div>
        <?php if ($lost_count === 0): ?>
            <p class="empty">No lost auctions.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Description</th>
                        <th>Auction Ended</th>
                        <th>Winning Bid</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $didnt_win_result->fetch_assoc()): ?>
                    <tr>
                        <td><?php echo esc($row['product_id']); ?></td>
                        <td><?php echo esc($row['description'] ?: '—'); ?></td>
                        <td><?php echo esc($row['end_time']); ?></td>
                        <td class="money">$<?php echo number_format($row['winning_bid'], 2); ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div><!-- /didn't win -->

</main>
</body>
</html>
