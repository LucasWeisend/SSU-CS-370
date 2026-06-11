# SSU-CS-370
Designated repo for Software Design &amp; Development - CS 370 at Sonoma State University

*This project was overseen and graded by [Robert James Bruce](https://www.robertjamesbruce.com/)*

---

## Term Project: Dream Team Auction — Full-Stack Web Application

A fully functional **online auction platform** built from scratch as the semester-long term project for CS 370 (Software Design & Development) at Sonoma State University. The application lets users register, list items for auction, place competing bids, and track all of their activity in a personal transaction dashboard.

### Live Features

- **User Authentication** — Secure registration and login with `password_hash` / `password_verify`, session management, and automatic logout after 5 minutes of inactivity.
- **Item Listings** — Sellers can create auction listings with a description, starting bid price, an optional buyout price, and a custom start time. Every auction runs for exactly 7 days (168 hours) and the end time is computed automatically.
- **Live Bidding** — Authenticated users can browse all active auctions and place bids directly from the listing table. The platform enforces that bids must beat the current floor price and prevents sellers from bidding on their own items.
- **Buyout Price** — Sellers can optionally set a buyout price that allows a buyer to win the auction instantly.
- **Transactions Dashboard** — A personal hub showing everything in one place: active listings you're selling, closed listings with sale results, items you've purchased, auctions you're currently winning or being outbid on, and auctions you participated in but didn't win.
- **Increase Bid** — Users can raise their bid on any active auction they're already participating in, directly from the transactions dashboard.
- **Public Auction Index** — A read-only landing page (`index.php`) lets anyone browse active listings without logging in, encouraging sign-ups.
- **XSS Protection** — All user-generated output is escaped via `htmlspecialchars` throughout the application.
- **SQL Injection Prevention** — Every database interaction uses prepared statements with bound parameters via MySQLi.

### Tech Stack

| Layer | Technology |
|---|---|
| Backend | PHP 8 |
| Database | MySQL (MySQLi extension) |
| Frontend | Vanilla HTML5 & CSS3 |
| Auth | PHP Sessions + bcrypt password hashing |
| Server | Apache / LAMP stack |

### Database Schema

The application is backed by four relational tables:

- **`users`** — Stores account credentials (hashed passwords, no plaintext).
- **`products`** — Auction listings with seller reference, pricing, description, and computed start/end timestamps.
- **`bid`** — Individual bid records tied to a bidder and a product, with a timestamp for ordering.
- **`sale`** — Records completed sales with the winning buyer and final price.

Foreign key constraints and a `CHECK` constraint (`end_time > start_time`) are enforced at the database level.

### Project Structure

```
├── index.php           # Public auction browsing page (no login required)
├── portal.php          # Login / registration portal + session management
├── bid.php             # Active auctions view with inline bid submission
├── sell.php            # Create a new auction listing
├── transactions.php    # Personal dashboard — selling, buying, bidding history
├── config.php          # Database connection constants
└── schema.sql          # Full database schema
```

### What I Learned

This project gave me hands-on experience with the full web development lifecycle — from designing a normalized relational schema, to writing secure server-side PHP, to crafting a clean and responsive CSS layout without any frameworks. Key takeaways include:

- Structuring multi-table SQL queries with `JOIN`, `COALESCE`, `GROUP BY`, and subqueries for real-world data needs.
- Implementing stateful user sessions securely, including idle-timeout logic.
- Handling nullable database fields (`buyout_price`) correctly across both the PHP layer and SQL prepared statements.
- Building a UI that adapts its controls based on context (e.g. hiding the bid form on a user's own listings, showing "Winning" vs "Outbid" badges dynamically).
