#!/bin/sh
set -eu

storage=/var/lib/fct/storage
for directory in "$storage" "$storage/uploads" "$storage/uploads/convenios"; do
    mkdir -p "$directory"
    owner="$(stat -c '%u' "$directory")"
    if [ "$owner" != "33" ]; then
        chown -R www-data:www-data "$directory"
    fi
    chown www-data:www-data "$directory"
    chmod 700 "$directory"
done

exec /usr/local/bin/docker-php-entrypoint "$@"
