# Call Home Feature - Complete Documentation

## Version 1.0 | January 2026

---

## Table of Contents

1. [Overview](#overview)
2. [Features](#features)
3. [Installation](#installation)
4. [Configuration](#configuration)
5. [Usage](#usage)
6. [Privacy & Security](#privacy--security)
7. [Troubleshooting](#troubleshooting)
8. [API Reference](#api-reference)

---

## Overview

### What is Call Home?

The Call Home feature allows your Vehicle Repair Billing System to communicate with a central server to:
- ✅ Check for system updates
- ✅ Validate license status
- ✅ Send anonymous usage statistics (telemetry)
- ✅ Receive security alerts
- ✅ Get product announcements

### Why Use Call Home?

**Benefits:**
- 🔄 Automatic update notifications
- 🛡️ Proactive security alerts
- 📊 Help improve the product (via anonymous data)
- ✅ License validation
- 📢 Important announcements

**Privacy First:**
- All telemetry is anonymous
- No customer data is ever sent
- No personal information collected
- Can be completely disabled
- Full transparency on what's sent

---

## Features

### 1. Update Checking

**Automatic:**
- Checks for updates daily
- Notifies directors of new versions
- Displays update information
- Provides download links

**Manual:**
- Click "Check for Updates" button
- Instant update check
- View release notes
- See what's new

### 2. License Validation

**Validates:**
- License key authenticity
- Expiration dates
- Feature entitlements
- Domain restrictions (if any)

**Notifications:**
- Invalid license alerts
- Expiring license warnings
- Feature availability updates

### 3. Telemetry (Anonymous Usage Data)

**What's Collected:**
- Number of vehicles (count only)
- Number of jobs (count only)
- Number of invoices (count only)
- Number of active users (count only)
- Activity in last 30 days
- Feature usage (yes/no flags)
- System version
- PHP version
- MySQL version

**What's NOT Collected:**
- ❌ Customer names
- ❌ Vehicle details
- ❌ Invoice amounts
- ❌ User information
- ❌ IP addresses (except connection IP)
- ❌ Any personal data

### 4. Security Alerts

**Receives:**
- Critical security vulnerabilities
- Recommended actions
- Severity levels
- Mitigation steps

**Displays:**
- High-priority alerts prominently
- Action items
- Affected versions
- Remediation guides

### 5. Announcements

**Types:**
- New feature releases
- Best practice tips
- Webinar invitations
- Special offers
- Deprecation notices

---

## Installation

### Step 1: Add Database Tables

Run the migration script:

```bash
mysql -u root -p vehicle_repair_billing < database_call_home_migration.sql
```

Or manually execute:

```sql
-- In MySQL console
USE vehicle_repair_billing;
SOURCE database_call_home_migration.sql;
```

This creates 4 new tables:
- `system_settings` - Configuration storage
- `system_notifications` - Alerts and updates
- `call_home_log` - Activity tracking
- `error_log` - Error logging (optional)

### Step 2: Add Call Home Files

Copy these files to your installation:

```bash
# Copy call home service
cp includes/call_home.php /path/to/vehicle_repair_billing/includes/

# Copy admin page
cp modules/settings/call_home.php /path/to/vehicle_repair_billing/modules/settings/

# Copy cron script
cp cron/call_home_cron.php /path/to/vehicle_repair_billing/cron/
chmod +x /path/to/vehicle_repair_billing/cron/call_home_cron.php
```

### Step 3: Set Up Cron Job (Optional but Recommended)

Add to crontab:

```bash
crontab -e
```

Add this line:

```cron
# Call home daily at 2 AM
0 2 * * * /usr/bin/php /var/www/html/vehicle_repair_billing/cron/call_home_cron.php >> /var/log/call_home.log 2>&1
```

### Step 4: Configure Call Home Server URL

Edit `includes/call_home.php`:

```php
private $callHomeUrl = 'https://updates.yourdomain.com/api/callback.php';
```

**Note:** If you don't have a call home server, you can:
- Leave as is (it will fail silently)
- Set to your own server
- Disable telemetry completely

---

## Configuration

### Access Settings

1. Login as Director
2. Go to **Settings** → **Call Home**
3. Configure options

### Available Settings

#### 1. Telemetry (Anonymous Data)

**Enable/Disable:**
- ☐ Enable Anonymous Telemetry

**Default:** Enabled

**To Disable:**
- Uncheck the box
- Click "Save Telemetry Settings"
- System will no longer send usage data

#### 2. License Key

**Enter License:**
- Enter your license key
- Click "Update License Key"
- Click "Validate License" to check

**Free Version:**
- Uses "FREE_LICENSE" by default
- All features available
- Can upgrade anytime

#### 3. Update Checking

**Automatic:**
- Enabled by default
- Checks daily via cron
- Notifications appear automatically

**Manual:**
- Click "Check for Updates" anytime
- Instant response
- See available updates

---

## Usage

### For Directors

#### Check for Updates

1. Go to Settings → Call Home
2. Click "Check for Updates"
3. View results:
   - Up to date ✓
   - Update available → Version shown

#### Validate License

1. Go to Settings → Call Home
2. Click "Validate License"
3. View status:
   - Valid ✓
   - Invalid/Expired → Contact support

#### View Notifications

1. Dashboard shows notification count
2. Go to Settings → Call Home
3. Review pending notifications
4. Click "Dismiss" to mark as read

#### Manage Telemetry

1. Go to Settings → Call Home
2. Toggle "Enable Anonymous Telemetry"
3. Save settings
4. Takes effect immediately

### For System Administrators

#### Monitor Call Home Activity

**View Logs:**
```bash
tail -f /var/log/call_home.log
```

**Check Database:**
```sql
SELECT * FROM call_home_log ORDER BY created_at DESC LIMIT 10;
```

**View Statistics:**
- Go to Settings → Call Home
- Scroll to "Call Home Statistics"
- See success rates

#### Troubleshoot Issues

**Test Manually:**
```bash
php cron/call_home_cron.php
```

**Check Network:**
```bash
curl -v https://updates.yourdomain.com/api/callback.php
```

**View Errors:**
```sql
SELECT * FROM call_home_log WHERE status = 'failed';
```

---

## Privacy & Security

### What We Collect (When Telemetry is Enabled)

**System Information:**
- System version (e.g., "1.0")
- PHP version (e.g., "8.1.0")
- MySQL version (e.g., "8.0.32")
- Server software (e.g., "Apache/2.4")
- Installation date

**Usage Statistics (Counts Only):**
- Total vehicles: 150 (just the number)
- Total jobs: 450
- Total invoices: 380
- Active users: 5
- Jobs last 30 days: 45
- Invoices last 30 days: 40

**Feature Usage (Yes/No Flags):**
- Quotations used: Yes
- Subcontracts used: Yes
- Analytics used: Yes

**System Identification:**
- Unique system ID (random hash)
- License key
- Domain name (for license validation)

### What We NEVER Collect

❌ Customer names
❌ Vehicle registration numbers
❌ Invoice amounts
❌ Revenue data
❌ User names
❌ Email addresses
❌ Phone numbers
❌ Any personal data
❌ Any customer data
❌ Business-specific information

### Data Transmission Security

**Encrypted:**
- All data sent via HTTPS
- TLS 1.2 or higher
- Certificate validation

**Headers:**
```
Content-Type: application/json
X-System-ID: [unique-hash]
X-License-Key: [license-key]
```

**Data Format:**
```json
{
  "system_id": "abc123...",
  "license_key": "LICENSE-KEY",
  "type": "heartbeat",
  "version": "1.0",
  "statistics": {
    "total_vehicles": 150,
    "total_jobs": 450,
    "total_invoices": 380
  }
}
```

### Privacy Controls

**Full Control:**
- ✅ Can disable telemetry completely
- ✅ Can see what's being sent
- ✅ Can opt out anytime
- ✅ No data sent if disabled

**Transparency:**
- All code is open
- All data documented
- No hidden tracking
- Clear privacy policy

---

## Troubleshooting

### Issue: Call Home Not Working

**Symptoms:**
- No updates showing
- "Last Check-In: Never"
- Failed status in logs

**Solutions:**

1. **Check Internet Connection:**
   ```bash
   ping updates.yourdomain.com
   ```

2. **Check Firewall:**
   ```bash
   # Allow outbound HTTPS
   sudo ufw allow out 443/tcp
   ```

3. **Test Manually:**
   ```bash
   php cron/call_home_cron.php
   ```

4. **Check Logs:**
   ```bash
   tail -f /var/log/call_home.log
   ```

5. **Verify cURL Extension:**
   ```php
   <?php
   echo extension_loaded('curl') ? 'Enabled' : 'Disabled';
   ?>
   ```

### Issue: License Validation Fails

**Symptoms:**
- "License invalid" message
- Features restricted

**Solutions:**

1. **Check License Key:**
   - Verify no extra spaces
   - Check for typos
   - Confirm key is correct

2. **Validate Manually:**
   - Go to Settings → Call Home
   - Click "Validate License"
   - Review error message

3. **Contact Support:**
   - Email: support@yourdomain.com
   - Provide system ID
   - Include error details

### Issue: Notifications Not Showing

**Symptoms:**
- No update notifications
- Alerts not appearing

**Solutions:**

1. **Check Telemetry:**
   - Ensure telemetry is enabled
   - System can't receive updates if disabled

2. **Check Database:**
   ```sql
   SELECT * FROM system_notifications WHERE is_read = 0;
   ```

3. **Manually Check:**
   - Click "Check for Updates"
   - Forces immediate check

### Issue: Cron Job Not Running

**Symptoms:**
- Last call home never updates
- Logs not growing

**Solutions:**

1. **Check Crontab:**
   ```bash
   crontab -l
   ```

2. **Check Cron Service:**
   ```bash
   sudo systemctl status cron
   ```

3. **Check Permissions:**
   ```bash
   chmod +x cron/call_home_cron.php
   ```

4. **Test Script:**
   ```bash
   php cron/call_home_cron.php
   ```

---

## API Reference

### CallHomeService Class

#### Constructor

```php
$callHome = new CallHomeService($pdo);
```

#### Methods

**callHome($type)**
```php
$response = $callHome->callHome('heartbeat');
// Types: 'heartbeat', 'update_check', 'license_validate', 'error_report'
```

**checkForUpdates()**
```php
$response = $callHome->checkForUpdates();
// Returns: ['updates_available' => true, 'latest_version' => '1.1']
```

**validateLicense()**
```php
$response = $callHome->validateLicense();
// Returns: ['license_status' => 'valid']
```

**isTelemetryEnabled()**
```php
$enabled = $callHome->isTelemetryEnabled();
// Returns: boolean
```

**optOutOfTelemetry()**
```php
$callHome->optOutOfTelemetry();
// Disables telemetry
```

**getPendingNotifications()**
```php
$notifications = $callHome->getPendingNotifications();
// Returns: array of notification objects
```

**markNotificationRead($id)**
```php
$callHome->markNotificationRead(123);
// Marks notification as read
```

### Helper Functions

**getCallHomeService()**
```php
$callHome = getCallHomeService();
// Returns: CallHomeService instance
```

**scheduledCallHome()**
```php
scheduledCallHome();
// Run scheduled call home (for cron)
```

**manualUpdateCheck()**
```php
$response = manualUpdateCheck();
// Manually check for updates
```

---

## Server-Side Implementation (Optional)

If you want to set up your own call home server:

### Endpoint: callback.php

```php
<?php
header('Content-Type: application/json');

// Get request data
$json = file_get_contents('php://input');
$data = json_decode($json, true);

// Validate request
$systemId = $_SERVER['HTTP_X_SYSTEM_ID'] ?? '';
$licenseKey = $_SERVER['HTTP_X_LICENSE_KEY'] ?? '';

if (!$systemId || !$licenseKey) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing credentials']);
    exit;
}

// Process request based on type
$response = [
    'status' => 'success',
    'timestamp' => time()
];

switch ($data['type']) {
    case 'heartbeat':
        // Log statistics
        logTelemetry($systemId, $data['statistics']);
        $response['message'] = 'Heartbeat received';
        break;
        
    case 'update_check':
        $response['updates_available'] = false;
        $response['latest_version'] = '1.0';
        // Check if update available
        if ($data['version'] < '1.0') {
            $response['updates_available'] = true;
            $response['latest_version'] = '1.1';
            $response['update_url'] = 'https://updates.yourdomain.com/v1.1';
        }
        break;
        
    case 'license_validate':
        $response['license_status'] = validateLicense($licenseKey, $data['domain']);
        break;
}

echo json_encode($response);
```

---

## Best Practices

### For System Administrators

✅ **Do:**
- Enable telemetry to help improve the product
- Check for updates monthly
- Review security alerts promptly
- Keep license information current
- Monitor call home logs

❌ **Don't:**
- Disable update checks (unless isolated network)
- Ignore security alerts
- Share system ID publicly
- Block outbound HTTPS unnecessarily

### For Privacy-Conscious Users

✅ **Can:**
- Disable telemetry completely
- Review all code (it's open)
- Run on isolated network
- See exactly what's sent
- Opt out anytime

✅ **Guaranteed:**
- No personal data collected
- No customer data sent
- Can disable entirely
- Full transparency

---

## FAQ

**Q: Is telemetry required?**
A: No, it can be completely disabled.

**Q: What happens if I disable telemetry?**
A: You won't receive automatic update notifications or security alerts, but the system works normally.

**Q: Can I use without internet?**
A: Yes, internet is only needed for call home features.

**Q: Is my data secure?**
A: Yes, all communication is encrypted via HTTPS.

**Q: What if call home server is down?**
A: System continues to work normally. Call home fails silently.

**Q: Can I self-host the call home server?**
A: Yes, see "Server-Side Implementation" section.

---

## Support

**For help with Call Home:**
- Review troubleshooting section
- Check system logs
- Test manually
- Contact support

**Contact:**
- Email: support@yourdomain.com
- Include system ID
- Describe issue
- Attach logs if possible

---

**Document Version:** 1.0  
**Last Updated:** January 2026  
**Call Home Version:** 1.0  

---

© 2026 Vehicle Repair Billing System
