# Cloud Deployment Guide: Vercel, Render & Neon PostgreSQL
## School Library Management System (LIBRARY_MS)

This guide provides tested, step-by-step instructions to deploy the system to **Vercel** (Serverless PHP) or **Render** (Docker Web Service) connected to **Neon Serverless PostgreSQL**.

---

## 1. Architecture Overview

```
                      ┌───────────────────────────────────────────────┐
                      │              CLIENT (Frontend)                │
                      │  - Single Page Application (client/index.php) │
                      │  - Multi-Role Login (client/login.php)        │
                      │  - Student Registration (client/register.php) │
                      │  - Password Recovery (client/forgot_pwd.php)  │
                      │  - Assets: Tailwind, FontAwesome, Inter Font  │
                      │  - Controller: client/js/app.js               │
                      └──────────────────────┬────────────────────────┘
                                             │ JSON REST APIs
                                             ▼
                      ┌───────────────────────────────────────────────┐
                      │              SERVER (Backend)                 │
                      │  - 10 Endpoints (server/api/*.php)            │
                      │  - Dual-Driver DB Engine (server/config/db.php│
                      │  - Auth & RBAC Middleware (config/auth.php)   │
                      │  - Cloud Migrations (schema_neon_postgres.sql)│
                      │  - Database CLI Seeder (scripts/seed_neon.php)│
                      └──────────────────────┬────────────────────────┘
                                             │
                     ┌───────────────────────┴───────────────────────┐
                     ▼                                               ▼
         ┌────────────────────────┐                     ┌────────────────────────┐
         │ LOCAL ENVIRONMENT      │                     │ CLOUD ENVIRONMENT      │
         │ - Driver: PDO MySQL    │                     │ - Driver: PDO PgSQL    │
         │ - DB: school_db (3306) │                     │ - Host: Neon Serverless│
         │ - Runtime: Local Apache│                     │ - Platform: Vercel or  │
         │                        │                     │             Render     │
         └────────────────────────┘                     └────────────────────────┘
```

---

## 2. Database: Neon Serverless PostgreSQL

Your cloud database is hosted on **Neon PostgreSQL** and has already been initialized and populated with all 29 catalog books, 165 barcoded physical inventory copies, 17 degree programs, 20 taxonomy categories, and administrator/librarian accounts.

### Connection String:
```text
postgresql://neondb_owner:npg_QFmBRUa5sJ3E@ep-young-fire-ao3f6pj2-pooler.c-2.ap-southeast-1.aws.neon.tech/neondb?sslmode=require&channel_binding=require
```

### Re-seeding anytime from CLI:
```bash
php server/scripts/seed_neon.php
```

---

## 3. Deployment Option A: Vercel (Recommended — Fastest & Zero Docker)

Vercel deploys the application as serverless PHP functions using the community runtime `vercel-php@0.9.0`. It requires **no Docker**, starts in under 20 seconds, and includes global CDN caching.

### Step 1: Push latest changes to GitHub
Ensure your latest code is pushed to your GitHub repository:
```bash
git add .
git commit -m "feat: configure Vercel and Render deployment"
git push origin main
```

### Step 2: Import into Vercel
1. Go to **[vercel.com/new](https://vercel.com/new)** and sign in.
2. Select your repository: **`school-library-management-system`**.
3. In the project configuration page:
   - **Framework Preset:** Leave as **Other**.
   - **Root Directory:** Leave as **`./`** *(do not change)*.
4. Expand **Environment Variables** and add:
   - **Key:** `DATABASE_URL`
   - **Value:** `postgresql://neondb_owner:npg_QFmBRUa5sJ3E@ep-young-fire-ao3f6pj2-pooler.c-2.ap-southeast-1.aws.neon.tech/neondb?sslmode=require&channel_binding=require`
5. Click **Deploy**.

Vercel will read [`vercel.json`](file:///c:/Users/Eliza%20Lie%20Corpuz/Documents/4BIT&1BYTE/LIBRARY_MS/vercel.json), build the PHP functions, and give you a live production URL (e.g. `https://school-library-management-system.vercel.app`).

---

## 4. Deployment Option B: Render

Render runs the application as a full containerized Apache + PHP 8.2 service.

### Method 1: 1-Click Blueprint Deploy (Zero manual errors)
1. Go to **[dashboard.render.com](https://dashboard.render.com/)**.
2. Click **New +** at the top right $\rightarrow$ select **Blueprint**.
3. Select your repository: **`school-library-management-system`**.
4. Render reads [`render.yaml`](file:///c:/Users/Eliza%20Lie%20Corpuz/Documents/4BIT&1BYTE/LIBRARY_MS/render.yaml) automatically.
5. In the environment variable prompt for `DATABASE_URL`, paste your Neon connection string.
6. Click **Apply**.

### Method 2: Standard Manual Web Service
If you create the Web Service manually in Render:
1. Click **New +** $\rightarrow$ **Web Service**.
2. Connect your GitHub repository.
3. In the settings page:
   - **Name:** `school-library-management-system`
   - **Region:** `Singapore` (or closest to your database)
   - **Branch:** `main`
   - **Runtime:** `Docker`
   - **Root Directory:** ⚠️ **LEAVE COMPLETELY BLANK (EMPTY)** *(Do NOT enter `LIBRARY_MS`)*
   - **Dockerfile Path:** `./Dockerfile`
   - **Docker Build Context Directory:** `.` *(or empty)*
4. Under **Environment Variables**, click **Add Environment Variable**:
   - **Key:** `DATABASE_URL`
   - **Value:** `postgresql://neondb_owner:npg_QFmBRUa5sJ3E@ep-young-fire-ao3f6pj2-pooler.c-2.ap-southeast-1.aws.neon.tech/neondb?sslmode=require&channel_binding=require`
5. Click **Create Web Service**.

---

## 5. Default Credentials for Live Testing

Once deployed on either platform, log in at `/login.php`:

| Role | Username | Password | Notes |
| :--- | :--- | :--- | :--- |
| **Administrator** | `admin` | `admin123` | Full system access, librarian provisioning, audit trail |
| **Librarian** | `librarian` | *(seeded bcrypt)* | Circulation desk, barcode scanner, catalog management |
| **Librarian** | `ikang` | *(seeded bcrypt)* | Circulation operations |
| **Student** | *(self-register)* | *(your choice)* | Click "Register as Student" on `/register.php` |

