# Postal Error Reporting System

A PHP CLI tool for monitoring Postal mail server delivery failures and sending email notifications.

## Features

- Monitors MariaDB database for failed deliveries
- Filters for outgoing messages only
- Tracks already processed failures to avoid duplicate notifications
- Sends detailed email notifications about delivery failures
- Supports both continuous monitoring and single-check modes
- Configurable via INI configuration files
- Systemd service integration with journal logging
- Professional Debian package for easy deployment

## Quick Start

### Option 1: Debian Package (Recommended)

1. **Download the latest .deb package from GitHub Releases**
2. **Install the package:**
   ```bash
   sudo dpkg -i postal-monitor_*.deb
   ```
3. **Configure:**
   ```bash
   sudo vi /etc/postal-monitor/config.ini
   sudo systemctl start postal-monitor
   ```

## Configuration

The system uses INI configuration files with the following sections:

### Database Configuration
```ini
[database]
host = localhost
port = 3306
user = postal
password = your_db_password
name = postal-server-1
```

### SMTP Configuration
```ini
[smtp]
host = localhost
port = 587
user = your_smtp_user
password = your_smtp_password
use_tls = true
```

### Notification Settings
```ini
[notifications]
email = admin@yourdomain.com
from_email = postal-monitor@yourdomain.com
```

### Monitoring Settings
```ini
[monitoring]
check_interval_minutes = 5
state_file_path = /var/lib/postal-monitor/last_checked_delivery_id
```

## Usage

### Manual Execution

```bash
# Run a single check
php postal_monitor.php --config=/etc/postal-monitor/config.ini --once

# Start continuous monitoring
php postal_monitor.php --config=/etc/postal-monitor/config.ini

# Show help
php postal_monitor.php --help
```

### Systemd Service (Recommended)

```bash
# Start the service
sudo systemctl start postal-monitor

# Enable automatic startup
sudo systemctl enable postal-monitor

# Check status
sudo systemctl status postal-monitor

# View logs
sudo journalctl -u postal-monitor -f
```

### Development/Testing

```bash
# Single check with local config
php postal_monitor.php --config=config.local.ini --once

# Continuous monitoring with local config
php postal_monitor.php --config=config.local.ini
```

## How It Works

1. **Database Monitoring:** The script queries the `deliveries` table for entries with status not equal to 'Sent' or 'SoftFail'
2. **Message Filtering:** It joins with the `messages` table to ensure only outgoing messages are considered
3. **State Tracking:** Uses a state file to track the last checked delivery ID to avoid duplicate notifications
4. **Email Notification:** Sends detailed HTML email notifications with failure information

## SQL Query Used

```sql
SELECT 
    d.id,
    d.message_id,
    d.status,
    d.code,
    d.output,
    d.details,
    d.timestamp,
    m.rcpt_to,
    m.mail_from,
    m.subject,
    m.scope
FROM deliveries d
JOIN messages m ON d.message_id = m.id
WHERE d.id > :last_checked_id
AND d.status NOT IN ('Sent', 'SoftFail')
AND m.scope = 'outgoing'
ORDER BY d.id ASC
```

## Notification Email Content

The notification emails include:
- Delivery failure details (ID, status, timestamp)
- Original message information (from, to, subject)
- Error details (code, output, error message)
- Formatted HTML with clear sections

## Troubleshooting

1. **Database Connection Issues:**
   - Verify database credentials in config.ini
   - Ensure MariaDB is running and accessible
   - Check firewall settings

2. **Email Notification Issues:**
   - Verify SMTP settings in config.ini
   - Test SMTP connectivity manually
   - Check mail server logs

3. **Service Issues:**
   - Check service status: `sudo systemctl status postal-monitor`
   - View logs: `sudo journalctl -u postal-monitor --since "1 hour ago"`
   - Test configuration: `sudo -u postal-monitor postal-monitor --once`

4. **Permission Issues:**
   - Ensure postal-monitor user has access to state directory
   - Check file permissions: `ls -la /var/lib/postal-monitor/`

## Security Considerations

- Configuration files have restricted permissions (640, root:postal-monitor)
- Service runs with dedicated user account and minimal privileges
- No new privileges, private tmp, protected system directories
- Use encrypted SMTP connections (TLS/SSL)
- Consider using application-specific database user with read-only access

## Building from Source

```bash
# Install build dependencies
sudo apt update
sudo apt install dpkg-dev php-cli php-mysql composer

# Build the package
./build_deb.sh

# Install the built package
sudo dpkg -i postal-monitor_*.deb
```

## Dependencies

- PHP 7.4 or higher
- PDO MySQL extension
- Composer for dependency management
- PHPMailer for email sending
