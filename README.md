# ITPMS — IT Project Management System

A self-contained PHP + MySQL + AJAX project management dashboard: sidebar navigation,
status overview cards, a sortable project table, and full Create/Read/Update/Delete for
projects, including a detail view with a progress-trend line chart.

## Stack

- **Backend:** PHP (PDO/MySQL) — `api/projects.php` is a small REST-style JSON endpoint
- **Database:** MySQL / MariaDB
- **Frontend:** Plain HTML/CSS/JS, AJAX via `fetch()`, [Chart.js](https://www.chartjs.org/) for the progress chart, [Lucide](https://lucide.dev/) for icons (both loaded from CDN)
- No frameworks, no build step — upload and go.

## File structure

```
itpms/
├── index.php              Main page (sidebar + dashboard + projects views + modals)
├── config.php             Database credentials — EDIT THIS
├── database.sql           Table schema + sample seed data — IMPORT THIS
├── api/
│   └── projects.php       CRUD endpoint consumed by the frontend via AJAX
├── assets/
│   ├── css/style.css      All styling
│   └── js/app.js          All frontend logic (rendering, AJAX calls, modals, chart)
└── README.md
```

## Local / hosting setup

1. **Create a database** (via phpMyAdmin, Adminer, or your host's control panel), e.g. `itpms`.
2. **Import the schema:**
   ```bash
   mysql -u YOUR_DB_USER -p itpms < database.sql
   ```
   or use phpMyAdmin's "Import" tab and select `database.sql`. This creates the `projects`
   and `progress_history` tables and adds 8 sample projects so the dashboard isn't empty.
3. **Edit `config.php`** with your database host, name, username, and password:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'itpms');
   define('DB_USER', 'your_db_user');
   define('DB_PASS', 'your_db_password');
   ```
4. **Upload the whole `itpms/` folder** to your web host (e.g. via FTP or cPanel File Manager),
   typically inside `public_html/` or a subfolder of it.
5. Visit `https://yourdomain.com/itpms/` (or wherever you uploaded it) in your browser.

### Requirements

- PHP 7.4+ with the **PDO MySQL** extension enabled (enabled by default on almost all hosts)
- MySQL 5.7+ / MariaDB 10.2+
- No Composer packages, no CLI build tools needed

## How it works

- `index.php` renders the page shell once; everything else happens through AJAX.
- `assets/js/app.js` calls `api/projects.php` to load, create, update, and delete projects,
  then re-renders the stat cards and tables in-place — no full page reloads.
- Clicking the **eye icon** (or a dashboard row) fetches that single project plus its full
  progress history and shows it in a two-column modal (details + line chart) sized to fit
  without scrolling.
- Every time a project is created or its progress is edited, a history point is recorded for
  today's date, so the trend chart naturally builds up over time.
- Status colors (Completed / Ongoing / On Hold / Cancelled / Not Started) are defined once in
  `STATUS_CONFIG` in `app.js` — reuse that object if you add new statuses or want to restyle.

## Customizing

- **Statuses / priorities:** update the `ENUM` values in `database.sql`, the `$VALID_STATUSES`
  / `$VALID_PRIORITIES` arrays in `api/projects.php`, the `<select>` options in `index.php`,
  and the `STATUS_CONFIG` object in `app.js` — all four need to stay in sync.
- **Currency:** the `money()` helper in `app.js` and the `₱` symbol in `index.php` assume PHP
  (Philippine peso) formatting — change the symbol/locale there if needed.
- **Auth:** this build has no login system. If you're hosting it somewhere non-private, put it
  behind HTTP basic auth (an `.htaccess` + `.htpasswd`) or add your own login layer in front of
  `index.php` and `api/projects.php`.
