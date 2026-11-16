# Deployment Guide: Neon PostgreSQL & Render Cloud Hosting
## School Library Management System (4BIT&1BYTE - LIBRARY_MS)

This guide provides step-by-step instructions for deploying the Library Management System to **Render** using a serverless **Neon PostgreSQL** database, while explaining the reorganized **Client (Frontend)** and **Server (Backend)** folder structure.

---

## 1. Project Folder Structure (Client vs Server Separation)

The codebase is organized into distinct **Client** (presentation/assets) and **Server** (APIs/database/logic) tiers:

```
LIBRARY_MS/
├── client/                             # ==========================================
│   ├── assets/                         # FRONTEND (CLIENT TIER)
│   │   ├── css/                        # Custom stylesheets (auth.css, main.css)
│   │   └── vendor/                     # 100% Offline vendor bundles
│   │       ├── fontawesome/            # Font Awesome 6.4.0 CSS & webfonts
│   │       ├── fonts/                  # Inter font family (@font-face & TTFs)
│   │       └── tailwindcss.js          # Standalone Tailwind CSS runtime engine
│   └── js/                             # Client-side SPA controller & event engine
│       └── app.js                      # DOM router, AJAX client, scanner listener
│
├── server/                             # ==========================================
│   ├── api/                            # BACKEND (SERVER TIER)
│   │   ├── books.php                   # Bibliographic catalog management endpoint
│   │   ├── book_copies.php             # Physical copy & barcode tracking endpoint
│   │   ├── borrowing.php               # Circulation desk: issue, returns & quotas
│   │   ├── categories.php              # Category taxonomy management endpoint
│   │   ├── courses.php                 # Academic degree program listing endpoint
│   │   ├── dashboard.php               # Aggregate statistics & queues by role
│   │   ├── profile.php                 # User profile & credential management
│   │   ├── reservations.php            # Student hold placement & processing
│   │   ├── students.php                # Student directory management endpoint
│   │   └── users.php                   # Staff provisioning & administrative audit
│   ├── config/                         # Core server configuration
│   │   ├── auth.php                    # Session handling, RBAC & CSRF middleware
│   │   └── db.php                      # Dual-Driver PDO (MySQL & Neon PostgreSQL)
│   ├── migrations/                     # Database schema definitions
│   │   ├── schema_neon_postgres.sql    # Complete Neon PostgreSQL schema + seed data
│   │   ├── 20261102_account_roles.sql  # MySQL migration archive
│   │   └── 20261105_librarian_mgmt.sql # MySQL staff management archive
│   └── scripts/                        # Administrative CLI maintenance utilities
│       ├── reset_admin_password.php    # Emergency password reset script
│       └── reset_admin_password.ps1    # PowerShell automation script
│
├── public pages (Web Entry Points)     # ==========================================
│   ├── index.php                       # Authenticated Single Page Application shell
│   ├── login.php                       # User authentication gateway
│   ├── register.php                    # Public student onboarding interface
│   ├── forgot_password.php             # Offline self-service password reset
│   └── logout.php                      # Session destruction & cookie clearance
│
├── docs/                               # TECHNICAL DOCUMENTATION
│   ├── SYSTEM_DOCUMENTATION.md         # Comprehensive system documentation
│   ├── NEON_RENDER_DEPLOYMENT.md       # This cloud deployment guide
│   └── future_improvements.md          # Architectural development roadmap
│
├── Dockerfile                          # Production container specification for Render
├── .dockerignore                       # Docker build exclusion rules
└── render.yaml                         # Render Blueprint specification
```

---

## 2. Setting Up Neon Serverless PostgreSQL

[Neon](https://neon.tech) provides a fully managed, serverless PostgreSQL database with an automatic free tier.

### Step 2.1: Create a Neon Account and Project
1. Go to [https://neon.tech](https://neon.tech) and sign up (or sign in with GitHub/Google).
2. Click **Create Project**.
3. Set your project details:
   - **Project Name:** `school-library-ms` (or any preferred name).
   - **Postgres Version:** `16` (recommended) or `15`.
   - **Region:** Select the region closest to your users (e.g., `US East (Ohio)` or `Asia Pacific (Singapore)`).
4. Click **Create Project**.

### Step 2.2: Execute the Database Schema
1. In your Neon Project Dashboard, click the **SQL Editor** tab on the left sidebar.
2. Open the file [server/migrations/schema_neon_postgres.sql](file:///c:/Users/Eliza%20Lie%20Corpuz/Documents/4BIT&1BYTE/LIBRARY_MS/server/migrations/schema_neon_postgres.sql).
3. Copy the entire contents of `schema_neon_postgres.sql`.
4. Paste the SQL code into the Neon SQL Editor.
5. Click **Run** (or press `Ctrl + Enter`).
6. Verify the output displays success. The script automatically creates:
   - All 9 relational tables: `courses`, `students`, `users`, `categories`, `books`, `book_copies`, `borrowing_records`, `reservations`, `activity_logs`.
   - 17 enrolled academic programs.
   - 20 Academic & Non-Academic categories.
   - Default Administrator (`admin`) and Librarian (`librarian`) accounts.
   - Initial book titles and physical inventory copies.

### Step 2.3: Copy the Neon Connection String
1. Go to your Neon **Dashboard** overview.
2. Under **Connection Details**, ensure the role selected is your owner role and database is `neondb`.
3. Choose **Connection string** and toggle **Pooled connection** (or Direct).
4. Copy the connection string. It will look like this:
   ```
   postgresql://neondb_owner:npg_AbCdEf123456@ep-cool-forest-123456.us-east-2.aws.neon.tech/neondb?sslmode=require
   ```
   *(Keep this string secure; you will paste it into Render in Section 4).*

---

## 3. How the Dual-Driver Database Configuration Works

The system features an automated **Dual-Driver Database Connector** in [server/config/db.php](file:///c:/Users/Eliza%20Lie%20Corpuz/Documents/4BIT&1BYTE/LIBRARY_MS/server/config/db.php):

```
                       +----------------------------------+
                       |       Database Request Initiated |
                       +----------------------------------+
                                        |
                         Is DATABASE_URL environment set?
                                        |
                     +------------------+------------------+
                     | YES                                 | NO
                     v                                     v
+------------------------------------------+ +------------------------------------------+
|  CLOUD MODE (Neon / Render)              | |  LOCAL MODE (XAMPP / On-Premise)         |
|  - Driver: PDO pdo_pgsql                 | |  - Driver: PDO pdo_mysql                 |
|  - DSN: pgsql:host=...;sslmode=require   | |  - DSN: mysql:host=127.0.0.1;dbname=...  |
|  - Auto-parses Cloud Connection String   | |  - Connects to localhost:3306            |
+------------------------------------------+ +------------------------------------------+
```

### Key Compatibility Implementations
- **ANSI SQL Standards:** All queries in `server/api/` use standard SQL functions (`CURRENT_DATE` and `COUNT(CASE WHEN ... THEN 1 END)`) that execute identically on both MySQL and PostgreSQL.
- **PHP Date Diff Engine:** Days-remaining calculations for borrowing records are computed using PHP's native `DateTime::diff()` to eliminate dialect differences between MySQL's `DATEDIFF()` and PostgreSQL's date subtraction.

---

## 4. Deploying the Application to Render

[Render](https://render.com) is a modern cloud hosting platform with native support for Docker Web Services.

### Step 4.1: Push Your Code to GitHub
1. Initialize git in your project root (if not already initialized):
   ```bash
   git init
   git add .
   git commit -m "feat: restructure client/server, add Neon postgres schema and Render Dockerfile"
   ```
2. Create a new repository on [GitHub](https://github.com).
3. Link and push your repository:
   ```bash
   git remote add origin https://github.com/<your-username>/<your-repo-name>.git
   git branch -M main
   git push -u origin main
   ```

### Step 4.2: Create a Web Service on Render
1. Log in to [https://render.com](https://render.com).
2. Click **New +** at the top right and select **Web Service**.
3. Choose **Build and deploy from a Git repository** and connect your GitHub repository.
4. Configure your Web Service:
   - **Name:** `school-library-ms` (or any unique name).
   - **Region:** Choose the region closest to your Neon database (e.g., `Oregon (US West)` or `Ohio (US East)`).
   - **Branch:** `main`.
   - **Runtime:** **Docker** (Render will automatically detect the [Dockerfile](file:///c:/Users/Eliza%20Lie%20Corpuz/Documents/4BIT&1BYTE/LIBRARY_MS/Dockerfile)).
   - **Plan Type:** **Free**.

### Step 4.3: Configure Environment Variables
Under the **Environment Variables** section on Render, add the following key-value pairs:

| Key | Value | Description |
|---|---|---|
| `DATABASE_URL` | `postgresql://neondb_owner:npg_xxx@ep-xxx.us-east-2.aws.neon.tech/neondb?sslmode=require` | Your exact connection string copied from Neon in Step 2.3. |
| `APP_ENV` | `production` | Enables production mode error sanitization. |

### Step 4.4: Deploy
1. Click **Create Web Service**.
2. Render will trigger the Docker build:
   - It downloads PHP 8.2 with Apache.
   - It compiles `pdo_pgsql` and `pdo_mysql` extensions.
   - It copies the project and sets up Apache virtual hosts.
3. Wait 2–3 minutes for the build to complete. When the status changes to **Live**, click on your service's URL (e.g., `https://school-library-ms.onrender.com`).

---

## 5. Post-Deployment Verification

1. **Visit the Login Page:** Navigate to `https://<your-app>.onrender.com/login.php`.
2. **Login as Administrator:**
   - **Username / Email:** `admin` or `admin@school.edu`
   - **Password:** `admin123` (or your reset password)
3. **Verify Neon Database Connection:**
   - On the Admin Dashboard, confirm that the aggregate metric cards show `10` Books, `11` Copies, `17` Courses, and `20` Categories loaded directly from your Neon PostgreSQL database.
4. **Test Barcode & Book Copies Desk:**
   - Navigate to **Book Copies** in the sidebar. Verify the physical barcode list loads with zero errors.
5. **Test Student Registration:**
   - Open an incognito tab and navigate to `/register.php`.
   - Register a new student account and confirm that the record appears in both the database and the admin student directory.

---

## 6. Troubleshooting Cloud Deployment

| Issue / Symptom | Potential Cause | Resolution |
|---|---|---|
| **500 Database Connection Failed** | Missing or incorrect `DATABASE_URL` on Render. | Open Render Service Settings $\rightarrow$ Environment Variables $\rightarrow$ verify `DATABASE_URL` contains `?sslmode=require`. |
| **Render Build Error: extension pdo_pgsql not found** | Dockerfile missing PostgreSQL dev libraries. | Ensure the [Dockerfile](file:///c:/Users/Eliza%20Lie%20Corpuz/Documents/4BIT&1BYTE/LIBRARY_MS/Dockerfile) includes `apt-get install -y libpq-dev && docker-php-ext-install pdo_pgsql`. |
| **White Screen / Assets Not Loading** | Incorrect relative asset paths. | Assets are hosted self-contained under `client/assets/` and `assets/`. Verify that files were committed to Git. |
| **Free Tier Spin-Down Delay** | Render free tier Web Services spin down after 15 minutes of inactivity. | The first request after idle may take 30–50 seconds to wake up the container. Subsequent requests run at full speed. |

