# FOSSBilling ISPmanager Server Manager Module

A hosting server manager adapter for [FOSSBilling](https://fossbilling.org) that integrates with [ISPmanager 6](https://www.ispmanager.com), enabling automatic provisioning of hosting accounts when customers purchase hosting plans.

> **Built for hosting businesses running ISPmanager 6 on their servers.**

---

## Features

- Automatic hosting account creation on purchase
- Account suspension and unsuspension
- Account cancellation and deletion
- Password changes
- Package/plan upgrades and downgrades
- Account synchronisation
- Primary domain creation with PHP and SSL
- Comprehensive logging of all API calls
- Test connection verification

---

## Requirements

- [FOSSBilling](https://fossbilling.org) 0.8.2 or later
- PHP 8.3 or later
- ISPmanager 6 (Lite, Pro or Host)
- A dedicated API user in ISPmanager (no 2FA)

---

## Installation

### Step 1 — Download the module

Download `ispmanager.php` from the [GitHub releases page](https://github.com/grant436/fossbilling-ispmanager/releases).

### Step 2 — Copy to your FOSSBilling installation

Copy it to:
/library/Server/Manager/ISPmanager.php

Note: the filename on the server must be `ISPmanager.php` (capital letters) to match the class name.

For Docker installations:

```bash
docker exec fossbilling curl -L \
  "https://raw.githubusercontent.com/grant436/fossbilling-ispmanager/main/ispmanager.php" \
  -o /var/www/html/library/Server/Manager/ISPmanager.php
```

### Step 3 — Create a dedicated API user in ISPmanager

1. Log into ISPmanager as root
2. Go to **Navigation Board → Administrator Users**
3. Create a new administrator user e.g. `fossbilling`
4. Set a strong password without special characters
5. Leave **Superuser** unchecked
6. Leave **2FA** disabled

### Step 4 — Open firewall access

FOSSBilling needs to reach ISPmanager on port 1500. If running in Docker on the same server, allow access from the Docker network:

```bash
sudo ufw allow from 127.0.0.1 to any port 1500
sudo ufw allow from 172.16.0.0/12 to any port 1500
sudo ufw reload
```

### Step 5 — Configure in FOSSBilling

1. Go to **Products & Services → Hosting Plans → Servers**
2. Click **New** to add a server
3. Fill in:
   - **Name:** your server name
   - **Hostname:** your server IP or hostname
   - **IP:** your server IP
   - **Server Manager:** `ISPmanager`
   - **Username:** your ISPmanager API username
   - **Password:** your ISPmanager API password
   - **Port:** `1500`
   - **Use Secure Connection:** Yes
   - **Verify TLS Certificate:** No (ISPmanager uses self-signed cert on port 1500)
4. Click **Test Connection** to verify
5. Save

### Step 6 — Create a hosting plan

1. Go to **Products & Services → Hosting Plans → Hosting Plans**
2. Click **New** and fill in your plan limits
3. Assign it to your ISPmanager server

### Step 7 — Create a hosting product

1. Go to **Products & Services → Products**
2. Create or edit a hosting product
3. Under **Configuration** assign your server and hosting plan

---

## How It Works

When a customer purchases a hosting plan:

1. FOSSBilling calls `createAccount()` in this module
2. The module creates a user account in ISPmanager via the API
3. The module creates the primary domain/website under that user
4. ISPmanager provisions the web directory, Nginx vhost and optionally Let's Encrypt SSL
5. The order is marked as active in FOSSBilling

### Username Generation

ISPmanager usernames must be lowercase alphanumeric and max 16 characters. The module automatically generates a username from the customer's domain name — e.g. `servmewebs.nz` becomes `servmew0`.

### SSL Certificates

The module requests Let's Encrypt SSL for each new website. This will only succeed if the domain's DNS is already pointing to your server. If DNS is not yet configured, the website will be created without SSL and it can be enabled later from within ISPmanager.

---

## Log Files

All API calls are logged to the FOSSBilling event log:
/data/log/event/event-YYYY-MM-DD.log

For Docker installations:

```bash
docker exec fossbilling tail -f /var/www/html/data/log/event/event-2026-06-10.log
```

---

## Known Limitations

| Limitation | Detail |
|---|---|
| **Username changes** | ISPmanager does not support renaming users via API. The module logs the request but takes no action. |
| **IP changes** | IP assignment is managed by ISPmanager automatically. |
| **SSL on creation** | Let's Encrypt will only issue if DNS points to your server. |

---

## Contributing

Contributions welcome. Please open an issue or pull request at [github.com/grant436/fossbilling-ispmanager](https://github.com/grant436/fossbilling-ispmanager).

---

## License

Apache 2.0 — see [LICENSE](LICENSE)

---

## Author

Built by Grant Charsley, [ServMe IT Limited](https://www.servmeit.co.nz) — NZ-based managed service provider.

ISPmanager is a trademark of ISPsystem. This module is not officially affiliated with ISPsystem.
