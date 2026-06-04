#!/bin/sh
set -e

# Ensure var directory exists and is writable
mkdir -p /app/var
chmod -R 777 /app/var

# Run database migrations
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Setup cron jobs:
#   - Every minute : run due monitor checks
#   - 23:59 daily  : send daily summary
#   - 03:00 Sunday : wipe rotated log files older than 7 days to keep disk clean
cat > /etc/crontabs/root <<'EOF'
* * * * * cd /app && php bin/console app:monitor:run >> /app/var/cron.log 2>&1
59 23 * * * cd /app && php bin/console app:monitor:daily-summary >> /app/var/cron.log 2>&1
0 3 * * 0 find /app/var/log -type f -name "*.log" -mtime +7 -delete >> /app/var/cron.log 2>&1
EOF

# Start cron daemon in the background
crond -b -d 8

# If custom arguments are passed (e.g. running phpunit or cli commands), execute them
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

# Default: start the PHP built-in server for the Dashboard REST API
echo "Starting Dashboard API on http://0.0.0.0:8000..."
exec php -S 0.0.0.0:8000 -t public
