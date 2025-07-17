#!/bin/bash

# Postal Monitor Debian Package Builder
# Creates a .deb package for Debian 12 with systemd service

set -e

# Package information
PACKAGE_NAME="postal-monitor"
VERSION="1.0.0"
ARCHITECTURE="all"
MAINTAINER="Your Name <your.email@domain.com>"
DESCRIPTION="Postal mail server delivery failure monitoring system"

# Directories
BUILD_DIR="./build"
PACKAGE_DIR="$BUILD_DIR/${PACKAGE_NAME}_${VERSION}_${ARCHITECTURE}"
DEBIAN_DIR="$PACKAGE_DIR/DEBIAN"

echo "Building Debian package for $PACKAGE_NAME v$VERSION..."

# Clean previous build
rm -rf "$BUILD_DIR"
mkdir -p "$DEBIAN_DIR"

# Create directory structure
mkdir -p "$PACKAGE_DIR/usr/bin"
mkdir -p "$PACKAGE_DIR/usr/share/postal-monitor"
mkdir -p "$PACKAGE_DIR/etc/postal-monitor"
mkdir -p "$PACKAGE_DIR/etc/systemd/system"
mkdir -p "$PACKAGE_DIR/var/lib/postal-monitor"

# Install Composer dependencies
echo "Installing PHP dependencies..."
composer install --no-dev --optimize-autoloader

# Copy application files
cp postal_monitor.php "$PACKAGE_DIR/usr/share/postal-monitor/"
cp -r vendor "$PACKAGE_DIR/usr/share/postal-monitor/"
cp config.ini "$PACKAGE_DIR/etc/postal-monitor/config.ini"

# Create executable wrapper
cat > "$PACKAGE_DIR/usr/bin/postal-monitor" << 'EOF'
#!/bin/bash
exec /usr/bin/php /usr/share/postal-monitor/postal_monitor.php "$@"
EOF
chmod +x "$PACKAGE_DIR/usr/bin/postal-monitor"

# Create systemd service file
cat > "$PACKAGE_DIR/etc/systemd/system/postal-monitor.service" << 'EOF'
[Unit]
Description=Postal Mail Server Error Monitor
After=network.target mysql.service mariadb.service
Wants=network.target

[Service]
Type=simple
User=postal-monitor
Group=postal-monitor
WorkingDirectory=/usr/share/postal-monitor
ExecStart=/usr/bin/postal-monitor --config=/etc/postal-monitor/config.ini
Restart=always
RestartSec=30
StandardOutput=journal
StandardError=journal
SyslogIdentifier=postal-monitor

# Security settings
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/lib/postal-monitor

[Install]
WantedBy=multi-user.target
EOF

# Create control file
cat > "$DEBIAN_DIR/control" << EOF
Package: $PACKAGE_NAME
Version: $VERSION
Architecture: $ARCHITECTURE
Maintainer: $MAINTAINER
Depends: php-cli (>= 7.4), php-mysql, php-curl, adduser
Description: $DESCRIPTION
 A PHP CLI tool for monitoring Postal mail server delivery failures
 and sending email notifications. Monitors MariaDB database for failed
 deliveries and sends detailed email notifications about delivery failures.
EOF

# Create postinst script (post-installation)
cat > "$DEBIAN_DIR/postinst" << 'EOF'
#!/bin/bash
set -e

# Create user and group
if ! getent group postal-monitor >/dev/null; then
    addgroup --system postal-monitor
fi

if ! getent passwd postal-monitor >/dev/null; then
    adduser --system --ingroup postal-monitor --home /var/lib/postal-monitor \
            --no-create-home --gecos "Postal Monitor" postal-monitor
fi

# Set ownership and permissions
chown postal-monitor:postal-monitor /var/lib/postal-monitor
chmod 755 /var/lib/postal-monitor

# Set config file permissions
chown root:postal-monitor /etc/postal-monitor/config.ini
chmod 640 /etc/postal-monitor/config.ini

# Reload systemd and enable service
systemctl daemon-reload
systemctl enable postal-monitor

echo "Postal Monitor installed successfully!"
echo "Please edit /etc/postal-monitor/config.ini before starting the service."
echo "Start with: systemctl start postal-monitor"
echo "Check status: systemctl status postal-monitor"
echo "View logs: journalctl -u postal-monitor -f"

#DEBHELPER#
EOF

# Create prerm script (pre-removal)
cat > "$DEBIAN_DIR/prerm" << 'EOF'
#!/bin/bash
set -e

if [ "$1" = "remove" ]; then
    # Stop and disable service
    systemctl stop postal-monitor || true
    systemctl disable postal-monitor || true
fi

#DEBHELPER#
EOF

# Create postrm script (post-removal)
cat > "$DEBIAN_DIR/postrm" << 'EOF'
#!/bin/bash
set -e

if [ "$1" = "purge" ]; then
    # Remove user and group
    if getent passwd postal-monitor >/dev/null; then
        deluser postal-monitor || true
    fi
    if getent group postal-monitor >/dev/null; then
        delgroup postal-monitor || true
    fi
    
    # Remove data directory
    rm -rf /var/lib/postal-monitor
    
    # Reload systemd
    systemctl daemon-reload || true
fi

#DEBHELPER#
EOF

# Make scripts executable
chmod 755 "$DEBIAN_DIR/postinst" "$DEBIAN_DIR/prerm" "$DEBIAN_DIR/postrm"

# Create conffiles (configuration files that should be preserved)
cat > "$DEBIAN_DIR/conffiles" << 'EOF'
/etc/postal-monitor/config.ini
EOF

# Calculate installed size
INSTALLED_SIZE=$(du -sk "$PACKAGE_DIR" | cut -f1)
echo "Installed-Size: $INSTALLED_SIZE" >> "$DEBIAN_DIR/control"

# Build the package
echo "Building package..."
dpkg-deb --build "$PACKAGE_DIR"

# Move to final location
mv "${PACKAGE_DIR}.deb" "./"

echo "Package built successfully: ${PACKAGE_NAME}_${VERSION}_${ARCHITECTURE}.deb"
echo ""
echo "To install:"
echo "  sudo dpkg -i ${PACKAGE_NAME}_${VERSION}_${ARCHITECTURE}.deb"
echo "  sudo apt-get install -f  # if there are dependency issues"
echo ""
echo "To configure:"
echo "  sudo nano /etc/postal-monitor/config.ini"
echo "  sudo systemctl start postal-monitor"
echo ""
echo "To uninstall:"
echo "  sudo apt-get remove $PACKAGE_NAME        # Remove package, keep config"
echo "  sudo apt-get purge $PACKAGE_NAME         # Remove everything"
