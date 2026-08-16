#!/usr/bin/env bash
# Arranque backend ProjectJA (Git Bash / MSYS)
set -e
export PATH="/c/php/php84:$PATH"
cd "$(dirname "$0")/ProjectJABack"
echo "PHP: $(php -v | head -n 1)"
php artisan serve --host=127.0.0.1 --port=8000
