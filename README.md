# 🎬 CINEMAX EGYPT

**Next-Generation Cinema Management & Booking System**

![HTML5](https://img.shields.io/badge/HTML5-E34F26?style=for-the-badge&logo=html5&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-323330?style=for-the-badge&logo=javascript&logoColor=F7DF1E)
![TailwindCSS](https://img.shields.io/badge/Tailwind_CSS-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white)
![PHP](https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-00000F?style=for-the-badge&logo=mysql&logoColor=white)

CINEMAX EGYPT is an enterprise-grade, full-stack Single Page Application (SPA) designed to solve real-world cinema ticketing challenges. Built with a focus on concurrency safety, tiered administrative sandboxing, and dynamic algorithmic curation, this system handles everything from interactive seat selection to financial auditing.

---

## ✨ Key Features

* 🎟️ **Concurrency-Safe Booking Engine:** Utilizes database row-level locking (`FOR UPDATE`) and a 10-minute temporary `seat_locks` queue to mathematically prevent double-booking race conditions.
* 🔐 **Secure Architecture:** Features a PHP-based TMDB proxy (hiding API keys from the browser), automatic CSRF-token retry wrappers, and stateful isolated sessions.
* 👑 **Tiered RBAC Dashboards:** A dual-dashboard system separating High-Level Managers from Regionally-Sandboxed Staff (e.g., a Giza admin cannot access Alexandria bookings).
* 🎥 **Virtual Cinema:** A parallel interactive ecosystem allowing users to reserve virtual seats, chat with neighbors, and watch synchronized countdowns/trailers.
* 🌍 **Algorithmic Curation:** A specialized TMDB sync engine that ensures local Egyptian cinema thrives by enforcing a strict 4-Arabic / 8-Global movie grid layout.
* 📱 **Zero-Friction Presentation Tech:** Built-in Cloudflare quick-tunnel scripts and Wi-Fi IPv4 sniffers that generate live QR codes, allowing audiences to test the app instantly on their phones without paid hosting.
* 📊 **Audit-Proof Database:** SQL triggers propagate location data for rapid reporting, and cancelled reservations are safely moved to `cancelled_booking_seats` to preserve historical financial data.

---

## 🛠️ Tech Stack

* **Frontend:** Vanilla JavaScript (ES6+), HTML5, Tailwind CSS (zero heavy frontend frameworks for maximum speed).
* **Backend:** PHP 8+ structured around modular, single-entry action controllers (`api.php`, `vc_api.php`).
* **Database:** MySQL (InnoDB engine leveraging ACID properties, foreign key constraints, and dynamic triggers).
* **External APIs:** The Movie Database (TMDB) API for live poster, cast, and trailer data.
* **Libraries:** `QRCode.js` for generating per-seat ticket payloads.

---

## 📂 Project Structure

```text
├── .idea/                      # IDE Configuration
├── cache/                      # Server-side TMDB JSON caching
├── tools/                      # Assorted dev tools
├── index.html                  # Main SPA User Frontend
├── admin.html                  # Admin/Staff Dashboard Frontend
├── virtual_cinema.html         # Virtual Cinema Interactive Room
├── access_qr.html              # Public presentation QR generator
├── style.css & admin.css       # Tailwind utility overrides and custom styling
├── script.js & admin.js        # Core frontend logic, API wrappers, and SPA routing
├── api.php                     # Master Backend Router (Auth, Booking, Admin)
├── vc_api.php                  # Virtual Cinema Backend Router
├── tmdb_service.php            # Server-side TMDB fetch and caching logic
├── tmdb_sync.php               # Cron-job script to generate 7-day showtime schedules
├── network_info.php            # Auto-IP sniffer for local presentation URLs
├── db.php                      # PDO Database connection helper
├── schema.sql                  # MASTER Database Schema (Tables, Triggers, Views)
├── seed_data.php               # Generates 27 Governorates, 15+ Cinemas, 40+ Halls
└── config.example.php          # Example configuration file
```

---

# 🚀 Getting Started

## 1. Prerequisites

- XAMPP (or equivalent environment with Apache & MySQL)
- PHP 8.0+
- A free API key from The Movie Database (TMDB)
- **Cloudflared (For Public Demos):** If you intend to use the Public Presentation tunnel, download the `cloudflared.exe` binary from the official Cloudflare repository and place it in the root folder of this project.

## 2. Installation & Configuration

1. Clone this repository into your `htdocs` (or web root) folder.
2. **Crucial Security Step:** Locate the `config.example.php` file inside the project folder. Duplicate it and rename the copy to `config.php`.
3. **Move `config.php` one level ABOVE your web root folder.**
   - **Why?** This project is architected so that Git cannot see your real `config.php`. Your database passwords and TMDB API keys will never be accidentally committed to GitHub, and the file cannot be accessed via a web browser.
4. Open your new `config.php` and add your database credentials and TMDB Bearer token.

## 3. Database Setup

1. Open phpMyAdmin (or your preferred SQL client) and create a database named `cinema`.
2. Import the unified `schema.sql` file.
3. Run the seeder:

```bash
C:\xampp\php\php.exe seed_data.php
```

4. Run the TMDB synchronization script:

```bash
C:\xampp\php\php.exe tmdb_sync.php
```

---

## 🌐 Production Deployment Note

Because `config.php` is stored outside the Git repository for security, it will not be transferred when you pull this code onto a live server (such as AWS, DigitalOcean, or HostGator).

When deploying to production, you must manually create a `config.php` file one level above your live `public_html` directory and populate it with your production database credentials.

---

## 4. Running the App

We have built custom scripts to make viewing and presenting the application frictionless.

### Local Development

```bash
START_LOCAL_DEMO.bat
```

This opens the app in your browser and triggers the local Wi-Fi QR code so you can view it on your mobile device (must be on the same Wi-Fi network).

### Public Presentation (Cloudflare Tunnel)

Ensure `cloudflared.exe` is in your project folder.

```bash
START_CLOUDFLARE_DEMO.bat
```

This automatically launches a quick tunnel, exposes your local XAMPP server to the public internet, and generates a live QR code that anyone in the world can scan.

To safely terminate the connection:

```bash
STOP_CLOUDFLARE_TUNNEL.bat
```

## 🔑 Demo Accounts

To explore the tiered **Role-Based Access Control (RBAC)** system and booking workflows, you can log in using the following pre-configured demo accounts.

---

### Accessing the Dashboards

#### 🎟️ Customer Portal

Navigate to:

```text id="91eh4u"
index.html
```

Or click **"Sign In"** on the main home page.

#### 👑 Admin Portal

Navigate directly to:

```text id="10y8d6"
admin.html
```

---

## 👥 Available Demo Accounts

| Role                          | Email                                    | Password         | Access Level                                                                                  |
| ----------------------------- | ---------------------------------------- | ---------------- | --------------------------------------------------------------------------------------------- |
| **Manager Admin**             | `manager.cinemax2026@gmail.com`          | `Manager@2026!`  | Full global access to all regions, system tools, payments, and users.                         |
| **Cairo Regional Admin**      | `cairo.admin.cinemax2026@gmail.com`      | `Regional@2026!` | Sandboxed access limited to Cairo governorate data only.                                      |
| **Alexandria Regional Admin** | `alexandria.admin.cinemax2026@gmail.com` | `Regional@2026!` | Sandboxed access limited to Alexandria governorate data only.                                 |
| **Normal User**               | `user.cinemax2026@gmail.com`             | `User@2026!`     | Standard customer access including booking, history, community reviews, and support features. |

---

## 👥 The Team

This project was built with a **Vertical Feature Slice** methodology, where each developer owned features end-to-end (from SQL constraints to UI rendering).

* **Ahmed Khalid Mohamed** – UI Layout & Navigation, Movie Discovery (Home, Search, Details), TMDB API Integration, and Database Design & Seeding.
* **Adel Alaa** – Customer Booking Journey, Interactive Seat Reservations, Payment Processing, QR Ticketing, and User Account Management (Auth, Bookings, Requests).
* **Bassel Adel** – Admin Operations & Dashboards, Regional Management, Payment & Refund Control Workflows, Support Threads, and the Virtual Cinema Extension.

---

## 📜 License

This project was developed as an academic/portfolio project. All movie data, posters, and imagery belong to their respective copyright holders and are fetched via TMDB.