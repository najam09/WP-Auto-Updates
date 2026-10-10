# WP Auto Updates Manager (Enterprise Edition) v3.0.0

An enterprise-grade, zero-miss, production-ready automatic background updater for WordPress plugins and themes. Engineered for high-availability sites, agencies, and enterprise networks where missing an update is not an option.

---

## 🚀 Key Enterprise Features

- ⚡ **Dual-Engine Auto-Update Architecture**:
  - **Engine 1 (Native WP)**: Standard core hook filtering with non-blocking async dispatching.
  - **Engine 2 (Direct Resilient Fallback Upgrader)**: If WordPress Core skips an item (e.g. due to VCS `.git` folders, translation checks, or third-party quirks), the Direct Engine executes the upgrade directly with silent background skins.
- 🔄 **Intelligent Retry Queue (Exponential Backoff)**:
  - Automatically captures temporary network drops, WordPress.org CDN 503 outages, or timeouts.
  - Retries failed updates with exponential backoff: **Attempt 1 (5 min)**, **Attempt 2 (15 min)**, **Attempt 3 (60 min)**.
- 🌐 **External Server Webhook & Crontab Trigger**:
  - Secure, token-authenticated endpoint (`/?wp_auto_updates_webhook=1&token=SECRET`).
  - Allows hosting providers, Linux system crontabs, Cloudflare Workers, or cPanel Cron Jobs to trigger updates reliably, completely eliminating reliance on website visitor traffic.
- 🛡️ **Auto-Enrollment Policy for New Extensions**:
  - Automatically enables auto-updates for newly installed plugins and themes. You never have to worry about forgetting to check a box.
- 🩺 **Pre-Update Compatibility Guard**:
  - Validates `Requires PHP` and `Requires at least` WordPress version from the update package metadata before upgrading. Protects your site from breaking if a plugin requires a newer PHP version than your server supports.
- 🧹 **Self-Healing Lock & Maintenance Buster**:
  - Automatically purges stale `auto_updater.lock` records and old `.maintenance` files (> 10 min) before every run.
- 📋 **Comprehensive Audit Log Engine**:
  - Detailed FIFO ring buffer log with deduplication, status filtering, live search, and **CSV / JSON exports**.
- 🎛️ **Modern Tabbed Interface**:
  - Tabs for **Plugins**, **Themes**, **Audit Logs**, **Retry Queue**, **Settings & Webhook**, and **System Diagnostics**.
- 🚀 **"Force Run Auto-Updates Now"**:
  - Immediate manual trigger for instant update execution and cache clearing with real-time feedback.

---

## 📂 Installation

### Option 1: Standard Plugin Upload (ZIP)
1. In your WordPress Admin dashboard, go to **Plugins > Add New > Upload Plugin**.
2. Upload `wp-auto-updates.zip` and click **Install Now**, then **Activate**.

### Option 2: Manual Directory Placement
1. Unzip and place the `wp-auto-updates` folder into:
   ```
   wp-content/plugins/wp-auto-updates/
   ```
2. Navigate to **Plugins > Installed Plugins** in WP-Admin and click **Activate**.

### Option 3: Must-Use (MU) Plugin
1. Copy `wp-auto-updates.php` directly into:
   ```
   wp-content/mu-plugins/wp-auto-updates.php
   ```
2. The system will activate automatically across the site/network.

---

## 🔒 Enterprise Security Architecture & Hardening

- **Zero Backdoors / No Remote Code Execution**: Contains zero `eval()`, `exec()`, `shell_exec()`, `passthru()`, `assert()`, or base64 decoding routines. All packages are retrieved and verified exclusively through WordPress Core’s official upgrader APIs with SSL verification.
- **Strict Capability Enforcement**: All administrative actions require `manage_options` (or `manage_network_plugins` on Multisite), customizable via `wp_auto_updates_capability`.
- **Complete CSRF Protection**: Every single form submission and state modification requires valid WordPress nonces (`check_admin_referer`).
- **Timing-Safe Webhook Authentication**: Webhook tokens are validated using constant-time `hash_equals()` to eliminate timing attack vectors.
- **Anti-DoS Throttling**: The external webhook endpoint enforces a 60-second rate-limit throttle to protect server memory and CPU against flood attempts.
- **CSV Formula Injection (DDE) Defense**: Audit log CSV exports neutralize spreadsheet formula execution (`=`, `+`, `-`, `@`) to protect administrator workstations.
- **Strict Input Sanitization & Output Escaping**: All database logs and UI outputs are sanitized (`sanitize_text_field`, `wp_strip_all_tags`) and escaped (`esc_html`, `esc_attr`, `wp_json_encode`).
- **Hardened HTTP Headers**: Exports and API responses declare `X-Content-Type-Options: nosniff` and `nocache_headers()`.

