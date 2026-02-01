# Vehicle Repair Billing System - Quick Start Guide

## 🚀 Get Started in Minutes!

This guide will help you get your Vehicle Repair Billing System up and running quickly.

---

## 📦 What You'll Find

This is a complete, enterprise-grade system for managing vehicle repairs, invoicing, and business operations. It includes:
*   Vehicle & Job Management
*   Labor, Parts & Subcontract Tracking
*   Comprehensive Invoicing & Profit Analysis
*   User & Admin Management (Roles, Audit Trail)
*   Visual Analytics & Advanced Reporting
*   Automated Database Backups
*   API & Table Monitoring
*   Security Features (Authentication, Authorization, Auditing)

For a full overview of all features, refer to `PROJECT_SUMMARY.md`.

---

## ⚙️ Installation & Setup

### Step 1: Prerequisites

Ensure your server meets these requirements:
*   **PHP:** 7.4 or higher
*   **MySQL:** 8.0 or higher
*   **Web Server:** Apache or Nginx
*   **`mysqldump`:** Command-line tool must be accessible in your system's PATH for database backups.
*   **Permissions:** Web server user (or cron user) needs write permissions for `uploads/`, `invoices/`, `logs/`, and `backups/` directories.

### Step 2: Database Setup

1.  **Create MySQL database:**
    Open your MySQL client (e.g., phpMyAdmin, MySQL Workbench, or command line) and create a new database.
    ```sql
    CREATE DATABASE vehicle_repair_billing;
    ```
2.  **Import the main schema:**
    Import the `database_schema.sql` file into the newly created database.
    ```bash
    mysql -u your_mysql_username -p vehicle_repair_billing < database_schema.sql
    ```
3.  **Import Call Home schema (if not done):**
    If you haven't already, also import `database_call_home_migration.sql`.
    ```bash
    mysql -u your_mysql_username -p vehicle_repair_billing < database_call_home_migration.sql
    ```
    *(You will be prompted for your MySQL password)*
4.  **Verify tables:** Ensure all 17 tables (including `system_settings`, `system_notifications`, `call_home_log`, `error_log`) have been created.

### Step 3: Configure the Application

Edit the `config/config.php` file:

1.  **Database Credentials:**
    ```php
    define('DB_HOST', 'localhost');     // Your database host
    define('DB_NAME', 'vehicle_repair_billing');
    define('DB_USER', 'root');          // Your MySQL username
    define('DB_PASS', '');              // Your MySQL password
    ```
    *Update `DB_USER` and `DB_PASS` with your actual MySQL credentials.*

2.  **Application URL:**
    The `APP_URL` is dynamically detected. If you encounter issues, ensure your web server is correctly configured for the `vehicle_repair_billing` subdirectory.

3.  **Timezone:**
    ```php
    date_default_timezone_set('Africa/Nairobi'); // Change to your desired timezone
    ```

### Step 4: Access the Application

1.  **Open your web browser:**
    Navigate to the URL where your application is hosted. Typically:
    `http://localhost/vehicle_repair_billing`
    (or `https://yourdomain.com/vehicle_repair_billing` if deployed)

2.  **Login with default credentials:**
    *   **Username:** `admin`
    *   **Password:** `admin123`

3.  **🚨 IMPORTANT: Change Admin Password Immediately!**
    Go to `My Profile` or `User Management` after logging in to change the default admin password.

---

## 🔒 Security Reminders

*   **Change default admin password** immediately after first login.
*   **Use strong, unique passwords** for all user accounts.
*   **Regular database backups** (now automated via Call Home feature if enabled).
*   **Monitor the Audit Trail** (`Admin & Settings` -> `Audit Trail`) regularly for suspicious activity.

---

## 🏃 First Steps After Installation

1.  **Change Admin Password:** (See above)
2.  **Update Company Information:** Go to `Admin & Settings` -> `General Settings` to configure your company details.
3.  **Configure Financial Defaults:** Set VAT rate, default markups in `General Settings`.
4.  **Manage Users:** Go to `Admin & Settings` -> `User Management` to add new users and assign roles.
5.  **Enable Call Home Features:** Go to `Admin & Settings` -> `Call Home` to enable/configure Automatic Database Backups and API/Table Monitoring.
6.  **Create your first vehicle:** Navigate to `Vehicles` -> `Add New Vehicle`.
7.  **Create your first job:** Navigate to `Jobs` -> `Create Job`.
8.  **Explore the Dashboard:** Get a quick overview of your system.

---

## 📞 Support & Troubleshooting

*   **"Database connection failed"**:
    *   Verify MySQL is running.
    *   Check `config/config.php` for correct `DB_HOST`, `DB_USER`, `DB_PASS`.
    *   Ensure the `vehicle_repair_billing` database exists.
*   **Permission Denied**:
    *   Ensure web server has write permissions for `uploads/`, `invoices/`, `logs/`, `backups/`. Use `chmod 755` (Linux/macOS) or `icacls` (Windows).
*   **"Page not found" (404)**:
    *   Verify `APP_URL` setting in `config/config.php` correctly points to your application's root.
    *   Ensure all files are in their correct locations.
    *   Check web server configuration for URL rewriting (e.g., `.htaccess` for Apache).
*   **Blank White Page**:
    *   Enable PHP error reporting in your `php.ini` (`display_errors = On`, `error_reporting = E_ALL`) and check your web server's error logs.
*   **Call Home `mysqldump` error**:
    *   Ensure `mysqldump` command is available in your server's PATH. You might need to provide the full path to `mysqldump` if not.

---

## 📚 Further Documentation

*   `README.md`: General project overview.
*   `PROJECT_SUMMARY.md`: Detailed summary of features and development history.
*   `CALL_HOME_DOCUMENTATION.md`: Specific documentation for the Call Home feature.