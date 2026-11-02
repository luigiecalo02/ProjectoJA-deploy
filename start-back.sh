#!/usr/bin/env bash
# Arranque backend ProjectJA (Git Bash / MSYS)
set -e
export PATH="/c/php/php84:$PATH"
cd "$(dirname "$0")/ProjectJABack"
echo "PHP: $(php -v | head -n 1)"

# Cola: las notificaciones (push + campanita) se procesan aquí.
php artisan queue:listen --tries=3 &
QUEUE_PID=$!
trap 'kill "$QUEUE_PID" 2>/dev/null || true' EXIT
echo "Queue worker PID: $QUEUE_PID"

php -d memory_limit=512M -d upload_max_filesize=100M -d post_max_size=110M artisan serve --host=127.0.0.1 --port=8000
