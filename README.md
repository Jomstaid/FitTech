# FitTech 🏋️

A gym management system built for a local fitness center. Members sign up, book trainers, buy supplements, and track workouts on the web. Staff run check-ins and walk-ins from a JavaFX desktop app at the front desk. Both clients talk to the same PHP + MySQL backend.

## 📑 Table of contents

- [Features](#-features)
- [Tech stack](#-tech-stack)
- [Project structure](#-project-structure)
- [Database](#-database)
- [Getting started](#-getting-started)
- [Front desk app](#-front-desk-app-java)
- [API reference](#-api-reference)
- [How things work](#-how-things-work)
- [Known limitations](#-known-limitations)

## ✨ Features

**Members (web)**
- 🔐 Register and log in with username or email
- 📅 Book sessions with trainers and see availability (`schedule.php`)
- 🛒 Shop for supplements and gear, with order history (`shop.php`)
- 💪 Log workouts and view weekly stats (`workouts.php`)
- 🔄 Request a membership renewal and track its status (`renewal.php`)
- 🔑 Request a password reset, then get a token from the front desk
- 👤 Edit own profile and change password (`members.php`)

**Admins**
- 📊 Dashboard: membership breakdown, member status, revenue from shop, trainer sessions, walk-ins, and renewals
- ✅ Approve or reject shop orders, renewals, and password reset requests
- 💸 Refund renewals (revenue is netted out automatically)
- 👥 Manage members, trainers, products, and gym equipment
- 💰 Edit membership pricing
- 🖼️ Upload trainer and product images

**Front desk (Java desktop app)**
- 🔎 Search members by name or username
- 🚪 Check members in, and log paid walk-in guests
- Expired memberships are flagged on check-in

## 🧰 Tech stack

| Part | Tech |
| --- | --- |
| Frontend | HTML, CSS, JavaScript |
| Backend | PHP 7.4+ (PDO), JSON API |
| Database | MySQL / MariaDB (InnoDB, utf8mb4) |
| Desktop | Java, JavaFX (FXML), Gson, NetBeans/Ant |
| Server | Apache (XAMPP) with `.htaccess` clean URLs |

## 📁 Project structure

```
FitTech/
├── index.html, login.html, error.html     # pages
├── style.css, favicon.ico
├── .htaccess                              # serves /page as /page.html
├── config.php                             # DB connection + CORS headers
├── auth.php                               # login, register, search, check-in, password reset
├── admin.php                              # admin-only stats, attendance, walk-ins, pricing
├── members.php                            # member CRUD, profile, image uploads
├── shop.php                               # products, orders, cart/purchase analytics
├── schedule.php                           # trainers and bookings
├── workouts.php                           # workout logs and stats
├── equipment.php                          # gym equipment inventory
├── renewal.php                            # renewal requests, approvals, refunds
├── fittech_complete.sql                   # full database setup
├── fittech_TRUNCATE_code.sql              # clears data
├── uploads/
│   ├── products/
│   └── trainers/
└── Netbeans/
    ├── Java/                              # controllers, FXML, ApiService
    └── NetBeans/                          # NetBeans project files
```

## 🗄️ Database

Created by `fittech_complete.sql`:

| Table | Purpose |
| --- | --- |
| `members` | Members and admins (`is_admin = 1`) |
| `membership_pricing` | Source of truth for prices and duration |
| `trainers` | Coaches, specialty, rate |
| `bookings` | Trainer sessions and amount due |
| `products` | Shop catalog |
| `orders`, `order_items` | Purchases |
| `workouts` | Member workout logs |
| `attendance` | Check-ins and walk-ins |
| `equipment` | Gym equipment inventory |
| `renewal_requests` | Renewal workflow (pending, approved, rejected, cancelled, refunded) |
| `password_reset_tokens` | Admin-approved password resets |

Default pricing seeded in the SQL file:

| Plan | Price | Duration |
| --- | --- | --- |
| Walk-in | ₱300 | 1 day |
| Basic | ₱2,500 | 1 month |
| Premium | ₱12,000 | 6 months |
| VIP | ₱20,000 | 12 months |

Six sample trainers are seeded too.

## 🚀 Getting started

1. Install [XAMPP](https://www.apachefriends.org/) and start **Apache** and **MySQL**.
2. Copy this folder to `C:\xampp\htdocs\FitTech`.
3. Open phpMyAdmin, go to the **SQL** tab, paste all of `fittech_complete.sql`, and click **Go**.
   > ⚠️ The script drops and recreates every table. Only run it on a fresh install.
4. Check `config.php`. Defaults are `localhost`, database `fittech`, user `root`, empty password.
5. Open `http://localhost/FitTech/` and register an account from the site.
6. Make yourself an admin in phpMyAdmin:
   ```sql
   UPDATE members SET is_admin = 1 WHERE username = 'your_username';
   ```
7. Log in again to see the admin features.

To wipe data later, use `fittech_TRUNCATE_code.sql`.

## 🖥️ Front desk app (Java)

1. Open `Netbeans/NetBeans` in NetBeans. You need a JDK with JavaFX, plus the Gson library.
2. In `Netbeans/Java/ApiService.java`, change `BASE` from the production URL to your server:
   ```java
   public static final String BASE = "http://localhost/FitTech";
   ```
3. Run the project and log in with an admin account.

Main files: `LoginController` and `Login.fxml` (sign in), `DashboardController` and `Dashboard.fxml` (search, check-in, walk-in), `SessionManager` (keeps the logged-in admin), `ApiService` (HTTP calls to the PHP backend).

## 🔌 API reference

Every endpoint is `<file>.php?action=<name>` and returns JSON. Admin endpoints also need `admin_id=<id>` in the query string.

### `auth.php`
| Action | Description |
| --- | --- |
| `login` | Log in with username/email and password. Auto-expires past-due memberships |
| `register` | Create a member account |
| `search` | Find members by name or username (front desk) |
| `checkin` | Log a member's attendance |
| `forgot_password` | Member requests a password reset |
| `list_reset_requests` | Admin lists pending resets |
| `approve_reset` / `deny_reset` | Admin decision on a reset request |
| `generate_reset_token` | Admin issues a reset token |
| `reset_password` | Member sets a new password with the token |

### `admin.php` (admin only)
| Action | Description |
| --- | --- |
| `overview` | Dashboard stats and revenue |
| `walkin` | Log a walk-in guest and payment |
| `checkin` | Check a member in |
| `attendance`, `today`, `recent_checkins` | Attendance records |
| `revenue` | Revenue breakdown |
| `get_pricing`, `update_pricing` | Membership pricing |
| `analytics_overview`, `membership_breakdown` | Analytics data |

### `members.php`
`list`, `stats`, `update`, `self_update`, `change_password`, `auto_expire`, `auto_expire_self`, `upload_trainer`, `upload_product`

### `shop.php`
`products`, `all_products`, `create`, `update`, `toggle`, `delete`, `order`, `history`, `detail`, `all_orders`, `pending_orders`, `approve_order`, `reject_order`, `cart_analytics`, `purchase_analytics`

### `schedule.php`
`trainers`, `all_trainers`, `availability`, `create`, `update`, `delete`, `book`, `cancel`, `list`, `upcoming`, `count`, `all`

### `workouts.php`
`get`, `create`, `stats`, `stats_all`, `stats_week`

### `equipment.php`
`list`, `summary`, `create`, `update`, `delete`

### `renewal.php`
`request`, `cancel_request`, `my_requests`, `pending`, `approve`, `reject`, `refund`, `count_pending`, `renewal_revenue`

## ⚙️ How things work

- **Membership expiry:** logging in past the expiry date flips an active member to `Expired`.
- **Revenue:** only confirmed orders, confirmed bookings, approved renewals, and walk-in payments count. Refunded renewals drop out right away.
- **Prices:** PHP always reads prices from `membership_pricing`, never from the client.
- **Walk-ins:** guests are stored in `attendance`, not `members`.
- **Security basics:** passwords use `password_hash` / `password_verify`, and queries use prepared statements.
- **Clean URLs:** `.htaccess` lets `/login` load `login.html`.

## 🚧 Known limitations

- Admin endpoints trust the `admin_id` query parameter instead of a session or token
- CORS is open (`*`)
- Some front desk actions (`search`, `checkin` in `auth.php`) have no admin check
- Database credentials live in `config.php`; move them to an environment file before deploying
