#!/bin/sh
set -e

# ── Environment ──────────────────────────────────────────────────────────────
export APP_ENV=prod

# Generate APP_SECRET once if the placeholder value is still set or it is empty.
# The secret is written to /app/.env.local so it survives restarts; the guard
# prevents a new line from being appended on every single boot.
if [ -z "${APP_SECRET}" ] || [ "${APP_SECRET}" = "change_me" ]; then
    if grep -qs '^APP_SECRET=' /app/.env.local; then
        APP_SECRET=$(sed -n 's/^APP_SECRET=//p' /app/.env.local | tail -n 1)
    else
        APP_SECRET=$(cat /proc/sys/kernel/random/uuid | tr -d '-')
        echo "APP_SECRET=${APP_SECRET}" >> /app/.env.local
    fi
    export APP_SECRET
fi

# ── Storage ───────────────────────────────────────────────────────────────────
mkdir -p /app/var/log
chmod -R 777 /app/var

# ── Database migrations ───────────────────────────────────────────────────────
php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# ── Cron jobs ─────────────────────────────────────────────────────────────────
# The crontab is rewritten in full on every boot so restarting the container
# never appends duplicate entries.
#
# SERVER_REPORT_SCHEDULE controls the periodic "how is the server doing?"
# digest built from the `script` checks. Set it to "off" to disable.
SERVER_REPORT_SCHEDULE="${SERVER_REPORT_SCHEDULE:-0 * * * *}"

# DAILY_SUMMARY_SCHEDULE controls the "last 24 hours" recap. Set it to "off"
# to disable.
DAILY_SUMMARY_SCHEDULE="${DAILY_SUMMARY_SCHEDULE:-0 22 * * *}"

cat > /etc/crontabs/root <<EOF
# Run due monitor checks every minute
* * * * * cd /app && APP_ENV=prod php bin/console app:monitor:run >> /app/var/cron.log 2>&1
# Weekly hard purge: rotated logs older than 7 days, plus the cron log itself
0 3 * * 0 find /app/var/log -type f -name "*.log" -mtime +7 -delete >> /app/var/cron.log 2>&1
5 3 * * 0 : > /app/var/cron.log
EOF

if [ "${SERVER_REPORT_SCHEDULE}" != "off" ]; then
    cat >> /etc/crontabs/root <<EOF
# Periodic server health digest sent to Telegram
${SERVER_REPORT_SCHEDULE} cd /app && APP_ENV=prod php bin/console app:monitor:report >> /app/var/cron.log 2>&1
EOF
fi

if [ "${DAILY_SUMMARY_SCHEDULE}" != "off" ]; then
    cat >> /etc/crontabs/root <<EOF
# Daily monitoring summary
${DAILY_SUMMARY_SCHEDULE} cd /app && APP_ENV=prod php bin/console app:monitor:daily-summary >> /app/var/cron.log 2>&1
EOF
fi

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
