#!/bin/sh
set -e

# ── Environment ──────────────────────────────────────────────────────────────
export APP_ENV=prod

# Generate APP_SECRET if the placeholder value is still set or it is empty.
# The secret is written to .env.local so it persists across container restarts
# as long as the var/ volume is mounted (we write it to /app/.env.local).
if [ -z "${APP_SECRET}" ] || [ "${APP_SECRET}" = "change_me" ]; then
    APP_SECRET=$(cat /proc/sys/kernel/random/uuid | tr -d '-')
    echo "APP_SECRET=${APP_SECRET}" >> /app/.env.local
    export APP_SECRET
fi

# ── Storage ───────────────────────────────────────────────────────────────────
mkdir -p /app/var/log
chmod -R 777 /app/var

# ── Database migrations ───────────────────────────────────────────────────────
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# ── Cron jobs ─────────────────────────────────────────────────────────────────
# Write the full crontab using a heredoc so re-running the entrypoint on
# container restart never appends duplicate entries.
cat > /etc/crontabs/root <<'EOF'
# Run due monitor checks every minute
* * * * * cd /app && APP_ENV=prod php bin/console app:monitor:run >> /app/var/cron.log 2>&1
# Daily monitoring summary at 23:59
59 23 * * * cd /app && APP_ENV=prod php bin/console app:monitor:daily-summary >> /app/var/cron.log 2>&1
# Weekly hard purge: delete rotated log files older than 7 days every Sunday at 03:00
0 3 * * 0 find /app/var/log -type f -name "*.log" -mtime +7 -delete >> /app/var/cron.log 2>&1
EOF

# Start cron daemon in the background
crond -b -d 8

# ── Custom command passthrough ────────────────────────────────────────────────
# If custom arguments are passed (e.g. running phpunit or cli commands),
# execute them instead of starting the HTTP server.
if [ "$#" -gt 0 ]; then
    exec "$@"
fi

# ── HTTP server ───────────────────────────────────────────────────────────────
echo "Starting Dashboard API on http://0.0.0.0:8000 (APP_ENV=prod)..."
exec php -S 0.0.0.0:8000 -t public
