# WP Auto Updates Manager (Enterprise Edition)

An enterprise-grade, zero-miss automatic background updater for WordPress plugins and themes. Engineered for high-availability production environments, agencies, and enterprise networks where missing an update or experiencing maintenance downtime is not an option.

---

## Table of Contents

- [Overview](#overview)
- [Key Features](#key-features)
- [System Requirements](#system-requirements)
- [Installation](#installation)
  - [Method 1: Git Clone (Recommended for Developers)](#method-1-git-clone-recommended-for-developers)
  - [Method 2: GitHub Release Archive (ZIP)](#method-2-github-release-archive-zip)
  - [Method 3: Must-Use (MU) Plugin](#method-3-must-use-mu-plugin)
- [How It Works](#how-it-works)
  - [Dual-Engine Execution](#dual-engine-execution)
  - [Intelligent Retry Queue](#intelligent-retry-queue)
  - [Pre-Update Compatibility Guard](#pre-update-compatibility-guard)
  - [Self-Healing Maintenance Buster](#self-healing-maintenance-buster)
- [External Webhook and Server Cron Setup](#external-webhook-and-server-cron-setup)
  - [Webhook Endpoint Details](#webhook-endpoint-details)
  - [Crontab Configuration Example](#crontab-configuration-example)
  - [cURL Execution Example](#curl-execution-example)
- [Admin Interface](#admin-interface)
- [Enterprise Security Architecture](#enterprise-security-architecture)
- [Multisite Support](#multisite-support)
- [Hooks and Extensibility](#hooks-and-extensibility)
- [Contributing](#contributing)
- [License](#license)

---

## Overview

WordPress native automatic updates rely heavily on WP-Cron and user page visits, which can lead to delayed updates on low-traffic sites or missed updates due to timeout constraints and temporary CDN outages.

WP Auto Updates Manager provides a resilient, dual-engine background updater with automatic retry mechanisms, server-level webhook execution, and safety guards to ensure your plugins and themes remain up to date without risking site stability.

---

## Key Features

- **Dual-Engine Auto-Update Architecture**:
  - **Native WordPress Engine**: Standard core hook filtering with non-blocking asynchronous dispatching.
  - **Direct Resilient Fallback Upgrader**: If WordPress Core skips an item (due to VCS `.git` directories, translation checks, or third-party conflicts), the Direct Engine executes the upgrade directly using silent background skins.
- **Intelligent Retry Queue (Exponential Backoff)**:
  - Automatically captures temporary network drops, WordPress.org CDN 503 errors, and HTTP timeouts.
  - Retries failed updates using exponential backoff: Attempt 1 (5 minutes), Attempt 2 (15 minutes), and Attempt 3 (60 minutes).
- **External Webhook and System Cron Integration**:
  - Secure, token-authenticated HTTP endpoint for external triggers.
  - Allows Linux system crontabs, hosting schedulers, Cloudflare Workers, or cPanel Cron Jobs to execute updates independently of front-end web traffic.
- **Auto-Enrollment for New Extensions**:
  - Automatically enables auto-updates for newly installed plugins and themes, eliminating manual configuration steps.
- **Pre-Update Compatibility Guard**:
  - Checks `Requires PHP` and `Requires at least` WordPress version constraints against your current environment prior to applying updates, preventing breaking changes.
- **Self-Healing Lock and Maintenance Recovery**:
  - Automatically removes stale `auto_updater.lock` entries and expired `.maintenance` files older than 10 minutes prior to running updates.
- **Comprehensive Audit Trail**:
  - In-memory FIFO ring buffer retaining up to 500 log events with live search, status filtering, and one-click CSV and JSON export options.
- **Dedicated Admin Control Panel**:
  - Clean tabbed interface featuring Plugins, Themes, Audit Logs, Retry Queue, Settings & Webhook, and System Diagnostics.
- **Instant Manual Execution**:
  - "Force Run Auto-Updates Now" button for immediate on-demand cache clearing and update processing.

---

## System Requirements

- **WordPress**: 5.6 or higher
- **PHP**: 7.4 or higher (8.0, 8.1, 8.2, and 8.3 supported)
- **Web Server**: Apache, Nginx, LiteSpeed, or IIS
- **Multisite**: Fully compatible (single site and WordPress Multisite network installations)

---

## Installation

### Method 1: Git Clone (Recommended for Developers)

Navigate to your WordPress installation's plugin directory and clone the repository:

```bash
cd wp-content/plugins/
git clone https://github.com/your-username/wp-auto-updates.git
```

Activate the plugin via WP-CLI:

```bash
wp plugin activate wp-auto-updates
```

Or activate it through the WordPress Admin dashboard under **Plugins > Installed Plugins**.

### Method 2: GitHub Release Archive (ZIP)

1. Download the latest `.zip` archive from the GitHub repository **Releases** page.
2. Log in to your WordPress Admin dashboard.
3. Navigate to **Plugins > Add New > Upload Plugin**.
4. Choose the downloaded ZIP file, click **Install Now**, and click **Activate Plugin**.

### Method 3: Must-Use (MU) Plugin

For mission-critical environments where the updater must always remain active across single sites or networks:

1. Copy `wp-auto-updates.php` into your `wp-content/mu-plugins/` directory:
   ```bash
   cp wp-auto-updates.php wp-content/mu-plugins/wp-auto-updates.php
   ```
2. The updater will automatically run as a Must-Use plugin without requiring manual activation.

---

## How It Works

### Dual-Engine Execution

1. **Native Engine**: Intercepts `auto_update_plugin` and `auto_update_theme` filters to enforce auto-updates based on your settings.
2. **Direct Fallback Engine**: If WordPress Core skips an eligible update due to edge cases (such as git-tracked directories, custom package headers, or background skin issues), the direct engine triggers WordPress's `Plugin_Upgrader` and `Theme_Upgrader` with a custom silent skin to guarantee execution.

### Intelligent Retry Queue

When an update fails due to temporary network issues, connection timeouts, or repository CDN rate limits:
- The item is automatically registered into the retry queue.
- Retry attempts are scheduled with exponential backoff:
  - Attempt 1: 5 minutes
  - Attempt 2: 15 minutes
  - Attempt 3: 60 minutes
- If all three attempts fail, the incident is permanently recorded in the audit log for administrator review.

### Pre-Update Compatibility Guard

Before downloading or applying an update package, the plugin parses package metadata:
- Compares the plugin's minimum PHP version with the server's `PHP_VERSION`.
- Compares the plugin's minimum WordPress version with the active `$wp_version`.
- Updates that exceed your environment's specifications are withheld, and an alert is recorded in the audit trail.

### Self-Healing Maintenance Buster

WordPress updates write a `.maintenance` file to the root directory during extraction. In the event of an abrupt PHP timeout or server restart:
- The updater automatically detects and clears `.maintenance` files older than 10 minutes.
- Stale transient lock records (`auto_updater.lock`) are cleared to prevent infinite blocking.

---

## External Webhook and Server Cron Setup

To achieve reliable execution independent of website visitors, trigger updates using a server-level cron job or an external monitoring service.

### Webhook Endpoint Details

- **URL Pattern**: `https://example.com/?wp_auto_updates_webhook=1&token=YOUR_SECRET_TOKEN`
- **Method**: `GET` or `POST`
- **Authentication**: Secret token generated in the plugin settings, validated using timing-safe `hash_equals()`.
- **Rate Limiting**: Enforces a 60-second cooldown period between requests to prevent resource exhaustion.

### Crontab Configuration Example

Add the following entry to your Linux server crontab (`crontab -e`) to trigger updates every 2 hours:

```bash
0 */2 * * * curl -s -f "https://example.com/?wp_auto_updates_webhook=1&token=YOUR_SECRET_TOKEN" > /dev/null 2>&1
```

Or using `wget`:

```bash
0 */2 * * * wget -q -O - "https://example.com/?wp_auto_updates_webhook=1&token=YOUR_SECRET_TOKEN" > /dev/null 2>&1
```

### cURL Execution Example

To test the webhook manually from the terminal:

```bash
curl -i "https://example.com/?wp_auto_updates_webhook=1&token=YOUR_SECRET_TOKEN"
```

Expected HTTP response: `200 OK` with JSON payload confirming the execution status.

---

## Admin Interface

Access the dashboard in WordPress Admin via **Settings > Auto Updates** (or **Network Admin > Auto Updates** on Multisite):

- **Plugins**: Enable or disable auto-updates on a per-plugin basis, or apply bulk actions.
- **Themes**: Manage auto-update policies across all installed themes.
- **Audit Logs**: View chronological event logs with filtering by status (`success`, `failed`, `skipped`), search by extension name, and export data to CSV or JSON.
- **Retry Queue**: Inspect active items awaiting retry, review backoff attempt counts, manually trigger retries, or flush the queue.
- **Settings & Webhook**: Configure global auto-enrollment, toggle core email notifications, generate or rotate your webhook secret token, and view ready-to-copy crontab commands.
- **System Diagnostics**: Real-time overview of PHP version, WordPress version, cron status, directory write permissions, and lock file states.

---

## Enterprise Security Architecture

- **No Remote Code Execution**: Contains zero usage of `eval()`, `exec()`, `shell_exec()`, `passthru()`, `assert()`, or base64 decoding routines. All packages are retrieved and verified via official WordPress Upgrader APIs with SSL verification.
- **Strict Capability Checks**: Administrative operations require `manage_options` (or `manage_network_plugins` on Multisite networks), customizable via the `wp_auto_updates_capability` filter.
- **CSRF Protection**: All form submissions, manual triggers, and settings updates are protected by WordPress security nonces (`check_admin_referer`).
- **Timing-Safe Token Authentication**: External webhook tokens are validated with constant-time `hash_equals()` to prevent timing attack vulnerabilities.
- **Denial-of-Service Defense**: Built-in 60-second rate-limiting throttle prevents server flooding via the webhook endpoint.
- **CSV Formula Injection Prevention**: Audit log CSV exports neutralize spreadsheet formula execution characters (`=`, `+`, `-`, `@`) to protect admin workstations against CSV/DDE injection.
- **Input Sanitization and Output Escaping**: Comprehensive sanitization (`sanitize_text_field`, `wp_strip_all_tags`) and escaping (`esc_html`, `esc_attr`, `wp_json_encode`) applied across all data flows.
- **Hardened HTTP Headers**: API and file export responses enforce `X-Content-Type-Options: nosniff` and `nocache_headers()`.

---

## Multisite Support

WP Auto Updates Manager is fully WordPress Multisite ready:
- Automatically detects Multisite networks and binds management to the Network Admin console.
- Configuration and audit logs are stored at the network level using `get_site_option()` and `update_site_option()`.
- Network administrators can manage updates across all sites from a single unified screen.

---

## Hooks and Extensibility

### Filters

- **`wp_auto_updates_capability`**: Override the minimum required user capability.
  ```php
  add_filter('wp_auto_updates_capability', function($capability) {
      return 'manage_options';
  });
  ```

---

## Contributing

Contributions, bug reports, and feature requests are welcome.

1. Fork the repository on GitHub.
2. Create a feature branch (`git checkout -b feature/improvement`).
3. Commit your changes (`git commit -m 'Add new feature'`).
4. Push to the branch (`git push origin feature/improvement`).
5. Open a Pull Request.

---

## License

This project is licensed under the GNU General Public License v2.0 or later (GPL-2.0-or-later). See the [LICENSE](LICENSE) file for details.
