#!/usr/bin/env bash
set -euo pipefail
# Run from the approved release directory; environment and keys stay outside releases.
exec php artisan recruit:backup
